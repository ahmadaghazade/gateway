<?php

namespace Larabookir\Gateway\Mellat;

use DateTime;
use Illuminate\Support\Facades\Request;
use Larabookir\Gateway\Enum;
use SoapClient;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;
use Larabookir\Gateway\Models\Gateway;


class Mellat extends PortAbstract implements PortInterface
{
	/**
	 * Address of main SOAP server
	 *
	 * @var string
	 */
	protected $serverUrl = 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl';

	/**
	 * {@inheritdoc}
	 */
	public function set($amount)
	{
		$this->amount = $amount;

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function ready()
	{
		$this->sendPayRequest();

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function redirect()
	{
		$refId = $this->refId;

        return \View::make('gateway::mellat-redirector')->with(compact('refId'));
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify($transaction)
	{
		parent::verify($transaction);

		$this->userPayment();
		$this->verifyPayment();
		$this->settleRequest();

		return $this;
	}

	/**
	 * Sets callback url
	 * @param $url
	 */
	function setCallback($url)
	{
		$this->callbackUrl = $url;
		return $this;
	}

	/**
	 * Gets callback url
	 * @return string
	 */
	function getCallback()
	{
		if (!$this->callbackUrl)
			$this->callbackUrl = Gateway::mellat()->callback_url;

		return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
	}

	/**
	 * Send pay request to server
	 *
	 * @return void
	 *
	 * @throws MellatException
	 */
	protected function sendPayRequest()
	{
		$dateTime = new DateTime();

		$this->newTransaction();

		$fields = array(
			'terminalId' => json_decode(Gateway::mellat()->connection_info)->terminalId,
			'userName' => json_decode(Gateway::mellat()->connection_info)->username,
			'userPassword' => json_decode(Gateway::mellat()->connection_info)->password,
			'orderId' => $this->transactionId(),
			'amount' => $this->amount,
			'localDate' => $dateTime->format('Ymd'),
			'localTime' => $dateTime->format('His'),
			'additionalData' => '',
			'callBackUrl' => $this->getCallback(),
			'payerId' => 0,
		);

		try {
			$soap = new \SoapClient($this->serverUrl);
            $response = $soap->bpPayRequest($fields);

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		$response = explode(',', $response->return);

		if ($response[0] != '0') {
			$this->transactionFailed();
			$this->newLog($response[0], MellatException::$errors[$response[0]]);
			throw new MellatException($response[0]);
		}
		$this->refId = $response[1];
		$this->transactionSetRefId();
	}

	/**
	 * Check user payment
	 *
	 * @return bool
	 *
	 * @throws MellatException
	 */
	protected function userPayment()
	{
		$this->refId = Request::input('RefId');
		$this->trackingCode = Request::input('SaleReferenceId');
		$this->cardNumber = Request::input('CardHolderPan');
		$payRequestResCode = Request::input('ResCode');

		if ($payRequestResCode == '0') {
			return true;
		}

		$this->transactionFailed();
		$this->newLog($payRequestResCode, @MellatException::$errors[$payRequestResCode]);
		throw new MellatException($payRequestResCode);
	}

	/**
	 * Verify user payment from bank server
	 *
	 * @return bool
	 *
	 * @throws MellatException
	 * @throws SoapFault
	 */
	protected function verifyPayment()
	{
		$fields = array(
			'terminalId' => json_decode(Gateway::mellat()->connection_info)->terminalId,
			'userName' => json_decode(Gateway::mellat()->connection_info)->username,
			'userPassword' => json_decode(Gateway::mellat()->connection_info)->password,
			'orderId' => $this->transactionId(),
			'saleOrderId' => $this->transactionId(),
			'saleReferenceId' => $this->trackingCode()
		);

		try {
			$soap = new SoapClient($this->serverUrl);
			$response = $soap->bpVerifyRequest($fields);

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		if ($response->return != '0') {
			$this->transactionFailed();
			$this->newLog($response->return, MellatException::$errors[$response->return]);
			throw new MellatException($response->return);
		}

		return true;
	}

	/**
	 * Send settle request
	 *
	 * @return bool
	 *
	 * @throws MellatException
	 * @throws SoapFault
	 */
	protected function settleRequest()
	{
		$fields = array(
			'terminalId' => json_decode(Gateway::mellat()->connection_info)->terminalId,
			'userName' => json_decode(Gateway::mellat()->connection_info)->username,
			'userPassword' => json_decode(Gateway::mellat()->connection_info)->password,
			'orderId' => $this->transactionId(),
			'saleOrderId' => $this->transactionId(),
			'saleReferenceId' => $this->trackingCode
		);

		try {
			$soap = new SoapClient($this->serverUrl);
			$response = $soap->bpSettleRequest($fields);

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		if ($response->return == '0' || $response->return == '45') {
			$this->transactionSucceed();
			$this->newLog($response->return, Enum::TRANSACTION_SUCCEED_TEXT);
			return true;
		}

		$this->transactionFailed();
		$this->newLog($response->return, MellatException::$errors[$response->return]);
		throw new MellatException($response->return);
	}
}

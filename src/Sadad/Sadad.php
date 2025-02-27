<?php

namespace Larabookir\Gateway\Sadad;

use SoapClient;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;
use Larabookir\Gateway\Models\Gateway;

class Sadad extends PortAbstract implements PortInterface
{
	/**
	 * Url of sadad gateway web service
	 *
	 * @var string
	 */
	protected $serverUrl = 'https://sadad.shaparak.ir/services/MerchantUtility.asmx?wsdl';

	/**
	 * Form generated by sadad gateway
	 *
	 * @var string
	 */
	private $form = '';


	/**
	 * {@inheritdoc}
	 */
	public function set($amount)
	{
		$this->amount = intval($amount);

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
		$form = $this->form;

		return \View::make('gateway::sadad-redirector')->with(compact('form'));
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify($transaction)
	{
		parent::verify($transaction);

		$this->verifyPayment();

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
			$this->callbackUrl = Gateway::sadad()->callback_url;

		return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
	}

	/**
	 * Send pay request to server
	 *
	 * @return void
	 *
	 * @throws SadadException
	 */
	protected function sendPayRequest()
	{
		$this->newTransaction();

		$this->form = '';

		try {
			$soap = new SoapClient($this->serverUrl);

			$response = $soap->PaymentUtility(
                json_decode(Gateway::sadad()->connection_info)->merchant,
				$this->amount,
				$this->transactionId(),
                json_decode(Gateway::sadad()->connection_info)->transactionKey,
                json_decode(Gateway::sadad()->connection_info)->terminalId,
				$this->getCallback()
			);

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		if (!isset($response['RequestKey']) || !isset($response['PaymentUtilityResult'])) {
			$this->newLog(SadadResult::INVALID_RESPONSE_CODE, SadadResult::INVALID_RESPONSE_MESSAGE);
			throw new SadadException(SadadResult::INVALID_RESPONSE_MESSAGE, SadadResult::INVALID_RESPONSE_CODE);
		}

		$this->form = $response['PaymentUtilityResult'];

		$this->refId = $response['RequestKey'];

		$this->transactionSetRefId();
	}

	/**
	 * Verify user payment from bank server
	 *
	 * @throws SadadException
	 */
	protected function verifyPayment()
	{
		try {
			$soap = new SoapClient($this->serverUrl);

			$result = $soap->CheckRequestStatusResult(
				$this->transactionId(),
                json_decode(Gateway::sadad()->connection_info)->merchant,
                json_decode(Gateway::sadad()->connection_info)->terminalId,
                json_decode(Gateway::sadad()->connection_info)->transactionKey,
				$this->refId(),
				$this->amount
			);

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		if (empty($result) || !isset($result->AppStatusCode))
			throw new SadadException('در دریافت اطلاعات از بانک خطایی رخ داده است.');

		$statusResult = strval($result->AppStatusCode);
		$appStatus = strtolower($result->AppStatusDescription);

		$message = $this->getMessage($statusResult, $appStatus);

		$this->newLog($statusResult, $message['fa']);

		if ($statusResult != 0 || $appStatus !== 'commit') {
			$this->transactionFailed();
			throw new SadadException($message['fa'], $statusResult);
		}
		$this->trackingCode = $result->TraceNo;
		$this->cardNumber = $result->CustomerCardNumber;
		$this->transactionSucceed();
	}

	/**
	 * Register error to error list
	 *
	 * @param int $code
	 * @param string $message
	 *
	 * @return array|null
	 *
	 * @throws SadadException
	 */
	private function getMessage($code, $message)
	{
		$result = SadadResult::codeResponse($code, $message);
		if ($result) {
			return $result;
		}
		$result = array(
			'code' => SadadResult::UNKNOWN_CODE,
			'message' => SadadResult::UNKNOWN_MESSAGE,
			'fa' => 'خطای ناشناخته',
			'en' => 'Unknown Error',
			'retry' => false
		);


		return $result;
	}
}

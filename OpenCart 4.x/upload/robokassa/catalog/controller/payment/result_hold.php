<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Payment;

class ResultHold extends \Opencart\System\Engine\Controller
{
	public function index(): void
	{
		$raw = file_get_contents('php://input');
		// $this->log->write('ROBOKASSA RESULT_HOLD RAW: ' . $raw);

		$payload = $this->getPayloadFromRequest($raw);
		if ($payload === null) {
			return;
		}

		// $this->log->write('ROBOKASSA RESULT_HOLD PAYLOAD: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));

		$this->processState($payload);
		http_response_code(200);
	}

	private function getPayloadFromRequest(string $raw): ?array
	{
		$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

		if (stripos($contentType, 'application/json') === false && stripos($contentType, 'application/jwt') === false) {
			http_response_code(415);
			return null;
		}

		$parts = explode('.', trim($raw));
		if (count($parts) === 3) {
			$json = json_decode($this->base64UrlDecode($parts[1]), true);
			return is_array($json) ? $json : [];
		}

		$json = json_decode($raw, true);
		if (is_array($json)) {
			return $json;
		}

		http_response_code(400);
		return null;
	}

	private function base64UrlDecode(string $data): string
	{
		$data = strtr($data, '-_', '+/');
		$pad = strlen($data) % 4;
		if ($pad) {
			$data .= str_repeat('=', 4 - $pad);
		}
		return (string) base64_decode($data);
	}

	private function processState(array $payload): void
	{
		$state = $payload['data']['state'] ?? null;

		if ($state === 'HOLD') {
			$this->updateOrderStatus($payload, 1, 'Robokassa: Платеж захолдирован.');
			return;
		}

		if ($state === 'OK') {
			$this->updateOrderStatus($payload, 2, 'Robokassa: Платеж успешно подтвержден.');
		}
	}

	private function updateOrderStatus(array $payload, int $status_id, string $message): void
	{
		$order_id = $payload['data']['invId'] ?? null;
		if (!$order_id) {
			// $this->log->write('ROBOKASSA RESULT_HOLD: missing invId');
			return;
		}

		$this->load->model('checkout/order');
		$order_info = $this->model_checkout_order->getOrder((int)$order_id);

		if (!$order_info) {
			// $this->log->write('ROBOKASSA RESULT_HOLD: order not found ' . $order_id);
			return;
		}

		if ((int)$order_info['order_status_id'] === 0) {
			$this->model_checkout_order->addHistory((int)$order_id, $status_id);
		}

		if ((int)$order_info['order_status_id'] !== $status_id) {
			$this->model_checkout_order->addHistory((int)$order_id, $status_id, $message);
		}
	}
}

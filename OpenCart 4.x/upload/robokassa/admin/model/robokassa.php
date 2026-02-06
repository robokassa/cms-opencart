<?php
namespace Opencart\Admin\Model\Extension\Robokassa\Payment;

class Robokassa extends \Opencart\System\Engine\Model
{
	public function holdCancel(int $order_id): bool
	{
		$merchant = (string)$this->config->get('payment_robokassa_login');

		$password1 = (int)$this->config->get('payment_robokassa_test')
			? (string)$this->config->get('payment_robokassa_test_password_1')
			: (string)$this->config->get('payment_robokassa_password_1');

		if ($merchant === '' || $password1 === '') {
			return false;
		}

		$invoice_id = $order_id;
		$out_sum = $this->getOutSum($order_id);

		$signature = md5($merchant . '::' . $invoice_id . ':' . $password1);

		$payload = [
			'MerchantLogin'  => $merchant,
			'OutSum'         => $out_sum,
			'InvoiceID'      => $invoice_id,
			'SignatureValue' => $signature,
		];

		$response = $this->post('https://auth.robokassa.ru/Merchant/Payment/Cancel', $payload);
		return stripos($response, 'OK') !== false;
	}

	public function holdConfirm(int $order_id): bool
	{
		$merchant = (string)$this->config->get('payment_robokassa_login');

		$password1 = (int)$this->config->get('payment_robokassa_test')
			? (string)$this->config->get('payment_robokassa_test_password_1')
			: (string)$this->config->get('payment_robokassa_password_1');

		if ($merchant === '' || $password1 === '') {
			return false;
		}

		$invoice_id = $order_id;

		[$out_sum, $receipt] = $this->buildConfirmReceipt($order_id);

		if ($out_sum === '0.00' || $receipt === '') {
			return false;
		}

		$signature = md5($merchant . ':' . $out_sum . ':' . $invoice_id . ':' . $receipt . ':' . $password1);

		$payload = [
			'MerchantLogin'  => $merchant,
			'OutSum'         => $out_sum,
			'InvoiceID'      => $invoice_id,
			'Receipt'        => $receipt,
			'SignatureValue' => $signature,
		];

		$url = 'https://auth.robokassa.ru/Merchant/Payment/Confirm';

		$response = $this->post($url, $payload);

		return stripos($response, 'OK') !== false;
	}

	private function buildConfirmReceipt(int $order_id): array
	{
		$pm  = (string)$this->config->get('payment_robokassa_payment_method');
		$po  = (string)$this->config->get('payment_robokassa_payment_object');
		$tax = (string)$this->config->get('payment_robokassa_tax');

		if ($pm === '')  $pm  = 'full_payment';
		if ($po === '')  $po  = 'commodity';
		if ($tax === '') $tax = 'none';

		$items = [];
		$sum_items = 0.0;

		$p = $this->db->query(
			"SELECT `name`, `quantity`, `total`
         FROM `" . DB_PREFIX . "order_product`
         WHERE order_id = '" . (int)$order_id . "'
         ORDER BY order_product_id ASC"
		);

		foreach ($p->rows as $row) {
			$name = (string)$row['name'];
			$qty  = (int)$row['quantity'];
			$line = (float)$row['total'];

			if ($qty <= 0) continue;
			if ($line <= 0) continue;

			$line = round($line, 2);

			$items[] = [
				'name'           => $name,
				'quantity'       => $qty,
				'sum'            => (float)number_format($line, 2, '.', ''),
				'payment_method' => $pm,
				'payment_object' => $po,
				'tax'            => $tax,
			];

			$sum_items += $line;
		}

		$sum_items = round($sum_items, 2);

		if (!$items || $sum_items <= 0) {
			return ['0.00', ''];
		}

		$receipt = json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return [number_format($sum_items, 2, '.', ''), (string)$receipt];
	}


	private function getOutSum(int $order_id): string
	{
		$sub_total = 0.0;
		$q1 = $this->db->query("SELECT `value` FROM `" . DB_PREFIX . "order_total` WHERE order_id = '" . (int)$order_id . "' AND code = 'sub_total' ORDER BY sort_order ASC LIMIT 1");
		if ($q1->num_rows && isset($q1->row['value'])) {
			$sub_total = (float)$q1->row['value'];
		}

		$total = 0.0;
		$q2 = $this->db->query("SELECT `total` FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$order_id . "' LIMIT 1");
		if ($q2->num_rows && isset($q2->row['total'])) {
			$total = (float)$q2->row['total'];
		}

		$sum = $sub_total > 0 ? $sub_total : $total;
		return number_format($sum, 2, '.', '');
	}

	private function post(string $url, array $data): string
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

		$result = curl_exec($ch);

		if ($result === false) {
			$error = curl_error($ch);
			curl_close($ch);
			throw new \RuntimeException($error);
		}

		curl_close($ch);
		return (string)$result;
	}
}


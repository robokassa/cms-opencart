<?php

class ControllerExtensionPaymentRobokassa extends Controller
{
	public function index()
	{

		$data['button_confirm'] = $this->language->get('button_confirm');

		$this->load->model('checkout/order');

		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

		if ($this->config->get('payment_robokassa_test')) {

			$password_1 = $this->config->get('payment_robokassa_test_password_1');
			$password_2 = $this->config->get('payment_robokassa_test_password_2');
			$data['payment_url'] = 'https://merchant.roboxchange.com/Index.aspx';

		} else {

			$password_1 = $this->config->get('payment_robokassa_password_1');
			$password_2 = $this->config->get('payment_robokassa_password_2');
			$data['payment_url'] = 'https://auth.robokassa.ru/Merchant/Index.aspx';

		}

		$data['robokassa_login'] = $this->config->get('payment_robokassa_login');

		$data['robokassa_fiscal'] = $this->config->get('payment_robokassa_fiscal');

		$data['inv_id'] = $this->session->data['order_id'];

		$data['order_desc'] = 'Покупка в ' . $this->config->get('config_name');

		$rur_code = 'RUB';

		$rur_order_total = $this->currency->convert($order_info['total'], $order_info['currency_code'], $rur_code);

		$data['out_summ'] = $this->currency->format($rur_order_total, $rur_code, $order_info['currency_value'], FALSE);


		if ($this->config->get('payment_robokassa_fiscal')) {

			$tax_type = $this->config->get('payment_robokassa_tax_type');
			$tax = $this->config->get('payment_robokassa_tax');
			$payment_method = $this->config->get('payment_robokassa_payment_method');
			$payment_object = $this->config->get('payment_robokassa_payment_object');

			$receipt = [];

			$items = [];

			foreach ($this->cart->getProducts() as $product) {

				$items[] = [
					'name' => utf8_substr(trim(htmlspecialchars($product['name'])), 0, 63),
					//'name'     => htmlspecialchars($product['name']),
					'sum' => $this->currency->format($product['price'] * $product['quantity'], 'RUB', false, false),
					'quantity' => $product['quantity'],
					'payment_method' => $payment_method,
					'payment_object' => $payment_object,
					'tax' => $tax
				];
			}

			if (isset($this->session->data['shipping_method'])) {
				$shipping_name = $this->session->data['shipping_method']['title'];
				$shipping_price = $this->session->data['shipping_method']['cost'];

				if ($shipping_price > 0) {

					$items[] = [
						'name' => utf8_substr(trim(htmlspecialchars($shipping_name)), 0, 63),
						'sum' => $this->currency->format($shipping_price, 'RUB', false, false),
						'quantity' => 1,
						'tax' => $tax,
						'payment_method' => $payment_method,
						'payment_object' => $payment_object,
					];

				}
			}

			$data['receipt'] = $receipt[] = json_encode(array(
				'sno' => $tax_type,
				'items' => $items

			));

			$data['receipt'] = urlencode($data['receipt']);

			$data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $data['receipt'] . ":" . $password_1 . ":Shp_item=1");

		} else {

			$data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $password_1 . ":Shp_item=1");

		}

		if ($this->config->get('payment_robokassa_test')) {
			$data['robokassa_test'] = '1';
		} else {
			$data['robokassa_test'] = '0';
		}


		return $this->load->view('extension/payment/robokassa', $data);
	}

	public function success()
	{


		if ($this->config->get('payment_robokassa_test')) {
			$password_1 = $this->config->get('payment_robokassa_test_password_1');
		} else {
			$password_1 = $this->config->get('payment_robokassa_password_1');
		}


		$out_summ = $this->request->post['OutSum'];
		$order_id = $this->request->post["InvId"];
		$crc = $this->request->post["SignatureValue"];

		$crc = strtoupper($crc);

		$my_crc = strtoupper(md5($out_summ . ":" . $order_id . ":" . $password_1 . ":Shp_item=1"));

		if ($my_crc == $crc) {
			$this->load->model('checkout/order');

			$order_info = $this->model_checkout_order->getOrder($order_id);

			if ($order_info['order_status_id'] == 0) {
				$this->model_checkout_order->addOrderHistory($order_id, $this->config->get('config_order_status_id'));
			}

			$this->response->redirect($this->url->link('checkout/success', '', true));

		} else {

			$this->log->write('ROBOKASSA ошибка в заказе: ' . $order_id . 'Контрольные суммы не совпадают');

			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');

			$this->response->redirect($this->url->link('error/error', '', true));

		}

		return true;
	}

	public function fail()
	{

		$this->response->redirect($this->url->link('checkout/checkout', '', true));

		return true;
	}

	public function result()
	{

		if ($this->config->get('payment_robokassa_test')) {
			$password_2 = $this->config->get('payment_robokassa_test_password_2');
		} else {
			$password_2 = $this->config->get('payment_robokassa_password_2');
		}

		$out_summ = $this->request->post['OutSum'];
		$order_id = $this->request->post["InvId"];
		$crc = $this->request->post["SignatureValue"];

		$crc = strtoupper($crc);

		$my_crc = strtoupper(md5($out_summ . ":" . $order_id . ":" . $password_2 . ":Shp_item=1"));

		if ($my_crc == $crc) {
			$this->load->model('checkout/order');

			$order_info = $this->model_checkout_order->getOrder($order_id);
			$new_order_status_id = $this->config->get('payment_robokassa_order_status_id');

			if ($order_info['order_status_id'] == 0) {
				$this->model_checkout_order->addOrderHistory($order_id, $new_order_status_id);
			}

			if ($order_info['order_status_id'] != $new_order_status_id) {
				$this->model_checkout_order->addOrderHistory($order_id, $new_order_status_id);

				if ($this->config->get('payment_robokassa_test')) {
					$this->log->write('ROBOKASSA в заказе: ' . $order_id . '. Статус заказа успешно изменен');
				}

			}


			return true;
		} else {

			if ($this->config->get('payment_robokassa_test')) {
				$this->log->write('ROBOKASSA ошибка в заказе: ' . $order_id . '. Контрольные суммы не совпадают');
			}

		}

	}
}
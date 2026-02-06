<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Payment;

class Success extends \Opencart\System\Engine\Controller {

	public function index(): void
	{
		$session = $this->session;

		if ($this->config->get('payment_robokassa_test')) {
			$password_1 = $this->config->get('payment_robokassa_test_password_1');
		} else {
			$password_1 = $this->config->get('payment_robokassa_password_1');
		}

		$out_summ = $this->request->post['OutSum'] ?? null;
		$order_id = isset($this->request->post['InvId']) ? (int)$this->request->post['InvId'] : null;
		$crc      = $this->request->post['SignatureValue'] ?? null;

		if (!$out_summ || $order_id === null || !$crc) {
			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');
			$this->response->redirect($this->url->link('error/error', '', true));
			return;
		}

		$crc = strtoupper($crc);

		$my_crc = strtoupper(md5(
			$out_summ . ":" . $order_id . ":" . $password_1 . ":Shp_item=1:Shp_label=official_opencart"
		));

		if ($my_crc !== $crc) {
			$this->log->write('ROBOKASSA success: signature mismatch. InvId=' . $order_id . ' OutSum=' . (string)$out_summ);
			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');
			$this->response->redirect($this->url->link('error/error', '', true));
			return;
		}

		// HOLD
		if ($order_id === 0) {
			$this->response->redirect($this->url->link('checkout/success', '', true));
			return;
		}

		$this->load->model('checkout/order');
		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info || !isset($order_info['order_status_id'])) {
			$this->log->write('ROBOKASSA success: order not found. InvId=' . $order_id);
			$this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');
			$this->response->redirect($this->url->link('error/error', '', true));
			return;
		}

		if ((int)$order_info['order_status_id'] === 0) {
			$this->model_checkout_order->addHistory($order_id, (int)$this->config->get('config_order_status_id'));
		}

		$this->response->redirect($this->url->link('checkout/success', '', true));
	}
}

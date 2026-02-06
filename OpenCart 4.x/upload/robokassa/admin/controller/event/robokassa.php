<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Event;

class Robokassa extends \Opencart\System\Engine\Controller
{
	private static array $done = [];

	private function shouldRun(int $order_id, int $new_status_id): bool
	{
		if ($order_id <= 0) return false;
		if ($new_status_id !== 7 && $new_status_id !== 2) return false;
		if (!(int)$this->config->get('payment_robokassa_status_hold')) return false;

		$q = $this->db->query(
			"SELECT order_status_id, payment_method
             FROM `" . DB_PREFIX . "order`
             WHERE order_id = '" . (int)$order_id . "'
             LIMIT 1"
		);

		if (!$q->num_rows) return false;

		$old_status_id  = (int)$q->row['order_status_id'];
		$payment_method = (string)$q->row['payment_method'];

		if ($old_status_id !== 1) return false;
		if (stripos($payment_method, 'robokassa') === false) return false;

		return true;
	}

	private function runOnce(int $order_id, int $new_status_id): void
	{
		$key = $order_id . ':' . $new_status_id;
		if (isset(self::$done[$key])) return;
		self::$done[$key] = true;

		if (!$this->shouldRun($order_id, $new_status_id)) return;

		$this->load->model('extension/robokassa/payment/robokassa');

		if ($new_status_id === 7) {
			$this->model_extension_robokassa_payment_robokassa->holdCancel($order_id);
			return;
		}

		if ($new_status_id === 2) {
			$this->model_extension_robokassa_payment_robokassa->holdConfirm($order_id);
			return;
		}
	}

	private function injectMessage(int $new_status_id, string $current): string
	{
		$msg = '';
		if ($new_status_id === 7) $msg = 'Robokassa: Платеж успешно отменен.';
		if ($new_status_id === 2) $msg = 'Robokassa: Платеж успешно подтвержден.';
		if ($msg === '') return $current;

		$current = trim($current);
		if ($current !== '' && mb_strpos($current, $msg) !== false) return $current;

		return $current === '' ? $msg : ($current . "\n" . $msg);
	}

	public function onOrderCall(&$route, &$args, &$output = null): void
	{
		$action = (string)($this->request->get['action'] ?? ($this->request->post['action'] ?? ''));
		if ($action !== 'sale/order.addHistory') return;

		$order_id = (int)($this->request->get['order_id'] ?? ($this->request->post['order_id'] ?? 0));

		$new_status_id = 0;
		if (isset($this->request->post['order_status_id'])) {
			$new_status_id = (int)$this->request->post['order_status_id'];
		} elseif (isset($this->request->post['order_status'])) {
			$new_status_id = (int)$this->request->post['order_status'];
		}

		$this->runOnce($order_id, $new_status_id);

		if ($this->shouldRun($order_id, $new_status_id)) {
			$cur = (string)($this->request->post['comment'] ?? '');
			$this->request->post['comment'] = $this->injectMessage($new_status_id, $cur);
		}
	}

	public function onOrderAddHistory(&$route, &$args, &$output = null): void
	{
		$order_id = (int)($args[0] ?? 0);
		$new_status_id = (int)($args[1] ?? 0);

		$this->runOnce($order_id, $new_status_id);

		if (!$this->shouldRun($order_id, $new_status_id)) return;

		$cur = isset($args[2]) ? (string)$args[2] : '';
		$args[2] = $this->injectMessage($new_status_id, $cur);
	}
}

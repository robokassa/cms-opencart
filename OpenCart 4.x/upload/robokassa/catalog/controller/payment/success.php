<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Payment;

class Success extends \Opencart\System\Engine\Controller {

    public function index(): void
    {
        if ($this->config->get('payment_robokassa_test')) {
            $password_1 = $this->config->get('payment_robokassa_test_password_1');
        } else {
            $password_1 = $this->config->get('payment_robokassa_password_1');
        }

        $request_data = $this->request->post ?: $this->request->get;

        $out_summ = $request_data['OutSum'] ?? null;
        $order_id = isset($request_data['InvId']) ? (int)$request_data['InvId'] : null;
        $crc      = $request_data['SignatureValue'] ?? null;

        if (!$out_summ || $order_id === null || !$crc) {
            $this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');
            $this->response->redirect($this->url->link('error/error', '', true));
            return;
        }

        $crc = strtoupper($crc);

        $my_crc = $this->getSignature($out_summ, $order_id, $password_1, $request_data);

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

        $this->clearCheckoutSession((int)($order_info['customer_id'] ?? 0));

        $this->response->redirect($this->url->link('checkout/success', '', true));
    }

    private function getSignature($out_summ, int $order_id, string $password, array $request_data): string
    {
        $shp = [];

        foreach ($request_data as $key => $value) {
            if (stripos((string)$key, 'Shp_') === 0 || stripos((string)$key, 'shp_') === 0) {
                $shp[(string)$key] = (string)$value;
            }
        }

        ksort($shp, SORT_STRING);

        $parts = [$out_summ, $order_id, $password];

        foreach ($shp as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return strtoupper(md5(implode(':', $parts)));
    }

    private function clearCheckoutSession(int $order_customer_id = 0): void
    {
        $this->cart->clear();
        $this->clearPersistentCart($order_customer_id);

        unset($this->session->data['order_id']);
        unset($this->session->data['payment_address']);
        unset($this->session->data['payment_method']);
        unset($this->session->data['payment_methods']);
        unset($this->session->data['shipping_address']);
        unset($this->session->data['shipping_method']);
        unset($this->session->data['shipping_methods']);
        unset($this->session->data['comment']);
        unset($this->session->data['agree']);
        unset($this->session->data['coupon']);
        unset($this->session->data['reward']);
        unset($this->session->data['voucher']);
        unset($this->session->data['vouchers']);
    }

    private function clearPersistentCart(int $order_customer_id = 0): void
    {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE `session_id` = '" . $this->db->escape($this->session->getId()) . "'");

        if ($this->customer->isLogged()) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE `customer_id` = '" . (int)$this->customer->getId() . "'");
        }

        if ($order_customer_id > 0) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE `customer_id` = '" . (int)$order_customer_id . "'");
        }
    }
}

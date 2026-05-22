<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Payment;

class Result extends \Opencart\System\Engine\Controller {
    public function index()
    {

        if ($this->config->get('payment_robokassa_test')) {
            $password_2 = $this->config->get('payment_robokassa_test_password_2');
        } else {
            $password_2 = $this->config->get('payment_robokassa_password_2');
        }

        $request_data = $this->request->post ?: $this->request->get;

        $out_summ = $request_data['OutSum'];
        $order_id = (int)$request_data["InvId"];
        $crc = $request_data["SignatureValue"];

        $crc = strtoupper($crc);

        $my_crc = $this->getSignature($out_summ, $order_id, $password_2, $request_data);

        if ($my_crc == $crc) {
            $this->load->model('checkout/order');

            $order_info = $this->model_checkout_order->getOrder($order_id);
            $new_order_status_id = $this->config->get('payment_robokassa_order_status_id');

            echo 'OK' . $order_id;

            if ($order_info['order_status_id'] == 0) {
                $this->model_checkout_order->addHistory($order_id, $new_order_status_id);
            } elseif ($order_info['order_status_id'] != $new_order_status_id) {
                $this->model_checkout_order->addHistory($order_id, $new_order_status_id);

                if ($this->config->get('payment_robokassa_test')) {
                    $this->log->write('ROBOKASSA в заказе: ' . $order_id . '. Статус заказа успешно изменен');
                }

            }

            if ($order_info) {
                $this->clearPersistentCart((int)($order_info['customer_id'] ?? 0));
            }

            return true;

        } else {

            if ($this->config->get('payment_robokassa_test')) {
                $this->log->write('ROBOKASSA ошибка в заказе: ' . $order_id . '. Контрольные суммы не совпадают');
            }

        }

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

    private function clearPersistentCart(int $order_customer_id = 0): void
    {
        if ($order_customer_id > 0) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE `customer_id` = '" . (int)$order_customer_id . "'");
        }
    }
}

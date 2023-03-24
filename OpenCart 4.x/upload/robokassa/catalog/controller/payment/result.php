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

        $out_summ = $this->request->post['OutSum'];
        $order_id = $this->request->post["InvId"];
        $crc = $this->request->post["SignatureValue"];

        $crc = strtoupper($crc);

        $my_crc = strtoupper(md5($out_summ . ":" . $order_id . ":" . $password_2 . ":Shp_item=1" . ":Shp_label=official_opencart"));

        if ($my_crc == $crc) {
            $this->load->model('checkout/order');

            $order_info = $this->model_checkout_order->getOrder($order_id);
            $new_order_status_id = $this->config->get('payment_robokassa_order_status_id');

            echo 'OK' . $this->request->post["InvId"];

            if ($order_info['order_status_id'] == 0) {
                $this->model_checkout_order->addHistory($order_id, $new_order_status_id);
            }

            if ($order_info['order_status_id'] != $new_order_status_id) {
                $this->model_checkout_order->addHistory($order_id, $new_order_status_id);

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
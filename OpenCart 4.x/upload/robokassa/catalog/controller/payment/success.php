<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Payment;

class Success extends \Opencart\System\Engine\Controller {
    public function index()
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

        $my_crc = strtoupper(md5($out_summ . ":" . $order_id . ":" . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart"));

        if ($my_crc == $crc) {
            $this->load->model('checkout/order');

            $order_info = $this->model_checkout_order->getOrder($order_id);

            if ($order_info['order_status_id'] == 0) {
                $this->model_checkout_order->addHistory($order_id, $this->config->get('config_order_status_id'));
            }

            $this->response->redirect($this->url->link('checkout/success', '', true));

        } else {

            $this->log->write('ROBOKASSA ошибка в заказе: ' . $order_id . 'Контрольные суммы не совпадают');

            $this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . ' 404 Not Found');

            $this->response->redirect($this->url->link('error/error', '', true));

        }

        return true;
    }
}
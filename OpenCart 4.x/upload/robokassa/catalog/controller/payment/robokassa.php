<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Payment;
class Robokassa extends \Opencart\System\Engine\Controller
{
    public function index() : string {

        $data['button_confirm'] = $this->language->get('button_confirm');

        $this->load->language('extension/robokassa/payment/robokassa');

        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $ruUrl = 'https://auth.robokassa.ru/Merchant/Index.aspx';
        $kzUrl = 'https://auth.robokassa.kz/Merchant/Index.aspx';



        if ($this->config->get('payment_robokassa_country') == 'RUB') {
            $paymentUrl = $ruUrl;
        } else {
            $paymentUrl = $kzUrl;
        }

        if ($this->config->get('payment_robokassa_test')) {

            $password_1 = $this->config->get('payment_robokassa_test_password_1');
            $password_2 = $this->config->get('payment_robokassa_test_password_2');
            $data['payment_url'] = $paymentUrl;

        } else {

            $password_1 = $this->config->get('payment_robokassa_password_1');
            $password_2 = $this->config->get('payment_robokassa_password_2');
            $data['payment_url'] = $paymentUrl;

        }

        $data['robokassa_login'] = $this->config->get('payment_robokassa_login');

        $data['robokassa_fiscal'] = $this->config->get('payment_robokassa_fiscal');

        $data['inv_id'] = $this->session->data['order_id'];

        $data['order_desc'] = 'Покупка в ' . $this->config->get('config_name');

        if ($order_info['currency_code'] != $this->config->get('payment_robokassa_country') && $order_info['currency_code'] != 'RUB') {
            $data['out_summ_currency'] = $order_info['currency_code'];
        }

        $data['out_summ'] = (float) $this->currency->format($order_info['total'], $order_info['currency_code'], false, false);

        $customer_language_id = $this->config->get('config_language_id');
        $languages_map = $this->config->get('payment_robokassa_languages_map');

        $language = isset($languages_map[$customer_language_id]) ? $languages_map[$customer_language_id] : 'ru';

        $data['culture'] = $language;

        if ($this->config->get('payment_robokassa_fiscal')) {

            $tax_type = $this->config->get('payment_robokassa_tax_type');
            $tax = $this->config->get('payment_robokassa_tax');
            $payment_method = $this->config->get('payment_robokassa_payment_method');
            $payment_object = $this->config->get('payment_robokassa_payment_object');

            $receipt = [];

            $items = [];

            foreach ($this->cart->getProducts() as $product) {
                $items[] = [
                    'name' => trim(htmlspecialchars($product['name'])), 0, 63,
                    //'name'     => htmlspecialchars($product['name']),
                    'cost' => $this->currency->format($product['price'], 'RUB', false, false),
                    'quantity' => $product['quantity'],
                    'payment_method' => $payment_method,
                    'payment_object' => $payment_object,
                    'tax' => $tax
                ];
            }


            if (isset($this->session->data['shipping_method'])) {
                $shipping = explode('.', $this->session->data['shipping_method']);
                $shipping_method_info = $this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]];

                $shipping_name = $shipping_method_info['title'];
                $shipping_price = $shipping_method_info['cost'];


                if ($shipping_price > 0)  {

                    $items[] = [
                        'name' => trim(htmlspecialchars($shipping_name)), 0, 63,
                        'cost' => $this->currency->format($shipping_price, 'RUB', false, false),
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

            if (isset($data['out_summ_currency'])) {
                $data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $data['out_summ_currency'] . ":" . $data['receipt'] . ":" . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart");
            } else {
                $data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $data['receipt'] . ":" . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart");
            }
        } else {
            if (isset($data['out_summ_currency'])) {
                $data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $data['out_summ_currency'] . ":" . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart");
            } else {
                $data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart");
            }
        }

        if ($this->config->get('payment_robokassa_test')) {
            $data['robokassa_test'] = '1';
        } else {
            $data['robokassa_test'] = '0';
        }

        $ruIframeUrl = "https://auth.robokassa.ru/Merchant/bundle/robokassa_iframe.js";
        $kzIframeUrl = "https://auth.robokassa.kz/Merchant/bundle/robokassa_iframe.js";

        if ($this->config->get('payment_robokassa_country') == 'RUB') {
            $data['iframeUrl'] = $ruIframeUrl;
        } else {
            $data['iframeUrl'] = $kzIframeUrl;
        }

        if ($this->config->get('payment_robokassa_status_iframe')) {
            $data['robokassa_status_iframe'] = 1;
        } else {
            $data['robokassa_status_iframe'] = 0;
        }


        return $this->load->view('extension/robokassa/payment/robokassa', $data);
    }
    
    public function test()
    {
        $this->load->model('extension/robokassa/payment/robokassa');

        $this->model_extension_payment_robokassa->sendSecondCheck(82);
    }

}



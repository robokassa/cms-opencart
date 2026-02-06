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

		$data['result2_url'] = HTTP_SERVER . 'index.php?route=extension/robokassa/payment/result_hold';

		if ($this->config->get('payment_robokassa_status_hold')) {
			$data['robokassa_status_hold'] = 1;
		} else {
			$data['robokassa_status_hold'] = 0;
		}


        $data['email'] =  $this->session->data['customer']['email'];

        if ($order_info['currency_code'] != $this->config->get('payment_robokassa_country') && $order_info['currency_code'] != 'RUB') {
            $data['out_summ_currency'] = $order_info['currency_code'];
        }

        $data['out_summ'] = (float) $this->currency->format($order_info['total'], $order_info['currency_code'], false, false);

        $customer_language_id = $this->config->get('config_language_id');
        $languages_map = $this->config->get('payment_robokassa_languages_map');

        $language = isset($languages_map[$customer_language_id]) ? $languages_map[$customer_language_id] : 'ru';

        $data['culture'] = $language;

        $order_product['quantity'] =  $this->model_checkout_order->getOrder($order_info['order_id']);

        $order_product['price'] = $this->model_checkout_order->getOrder($order_info['order_id']);

        if ($this->config->get('payment_robokassa_fiscal')) {

            $tax_type = $this->config->get('payment_robokassa_tax_type');
            $tax = $this->config->get('payment_robokassa_tax');
            $payment_method = $this->config->get('payment_robokassa_payment_method');
            $payment_object = $this->config->get('payment_robokassa_payment_object');

            $receipt = [];

            $items = [];

            $discount = 0;
            foreach ($this->model_checkout_order->getTotals($order_info['order_id']) as $row) {
                if ($row['value'] < 0) {
                    $discount = abs($row['value']);
                }
            };

            $total_price = 0;
            $order_products = $this->model_checkout_order->getOrder($order_info['order_id']);

            foreach ($this->cart->getProducts() as $order_product) {
                $total_price += $order_product['price'] * $order_product['quantity'];
            }

            $discount_percent = ($discount / $total_price)  ; // процент скидки на каждый товар

            foreach ($this->cart->getProducts() as $order_product) {
                $item_price = $order_product['price'] / $order_product['quantity'] ;

                // вычисляем стоимость скидки для каждого товара
                $item_discount = round($item_price * $discount_percent, 2);
                $item_price -= $item_discount;

                $items[] = [
                    'name' => mb_substr(trim(htmlspecialchars($order_product['name'])), 0, 128, 'UTF-8'),
                    //'name'     => htmlspecialchars($product['name']),
                    'cost' => round($item_price, 2)   ,
                    'quantity' => $order_product['quantity'],
                    'payment_method' => $payment_method,
                    'payment_object' => $payment_object,
                    'tax' => $tax
                ];
            }


            if (isset($this->session->data['shipping_method']) && is_array($this->session->data['shipping_method'])) {
                $shipping = $this->session->data['shipping_method'];

                if (isset($shipping['name'], $shipping['cost'])) {
                    $shipping_name = $shipping['name'];
                    $shipping_price = $shipping['cost'];
                    $shipping_tax = $this->config->get('payment_robokassa_tax');
                    $payment_object = $this->config->get('payment_robokassa_payment_object');
                    $payment_method = 'full_prepayment';

                    if ($shipping_price > 0) {
                        $items[] = [
                            'name' => mb_substr(trim(htmlspecialchars($shipping_name)), 0, 128, 'UTF-8'),
                            'cost' => $this->currency->format($shipping_price, 'RUB', false, false),
                            'quantity' => 1,
                            'tax' => $tax,
                            'payment_method' => $payment_method,
                            'payment_object' => $payment_object,
                        ];
                    }
                } else {
                    $this->log->write('Ошибка: Отсутствуют необходимые данные о доставке (name, cost).');
                }
            } else {
                $this->log->write('Ошибка: shipping_method не является массивом или отсутствует.');
            }

            $data['receipt'] = $receipt[] = json_encode(array(
                'sno' => $tax_type,
                'items' => $items

            ));

            $data['receipt'] = urlencode($data['receipt']);

            if (isset($data['out_summ_currency'])) {
                
				$data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $data['out_summ_currency'] . ":" . $data['receipt'] . ":" . ($data['robokassa_status_hold'] == 1 ? "true:" . urldecode($data['result2_url']) : "") . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart");
            } else {
                $data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $data['receipt'] . ":" . ($data['robokassa_status_hold'] == 1 ? "true:" . urldecode($data['result2_url']) . ":" : "") . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart");
            }
        } else {
            if (isset($data['out_summ_currency'])) {
                $data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $data['out_summ_currency'] . ":" . (($data['robokassa_status_hold'] == 1 ? "true:" . urldecode($data['result2_url']) : "") . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart"));
            } else {
                $data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . (($data['robokassa_status_hold'] == 1 ? "true:" . urldecode($data['result2_url']) . ":" : "") . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart"));
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



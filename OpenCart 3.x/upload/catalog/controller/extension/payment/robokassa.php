<?php

class ControllerExtensionPaymentRobokassa extends Controller
{
    public function index()
    {

        $data['button_confirm'] = $this->language->get('button_confirm');

        $this->load->model('checkout/order');
        $this->load->model('account/order');

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

        $data['result2_url'] = HTTP_SERVER . 'index.php?route=extension/payment/robokassa/result2';

        if (isset($this->session->data['guest']['email'])) {
            $data['email'] = $this->session->data['guest']['email'];
        } else {
            $data['email'] = '';
        }

        if ($this->config->get('payment_robokassa_status_hold')) {
            $data['robokassa_status_hold'] = 1;
        } else {
            $data['robokassa_status_hold'] = 0;
        }

        if ($order_info['currency_code'] != $this->config->get('payment_robokassa_country') && $order_info['currency_code'] != 'RUB') {
            $data['out_summ_currency'] = $order_info['currency_code'];
        }
        $bonus_points = 0; // Инициализируем переменную для учета бонусных баллов

        // Расчет стоимости заказа с учетом бонусных баллов
        $total_order_cost = $order_info['total'] - $bonus_points;
        $data['out_summ'] = (float) $this->currency->format($total_order_cost, $order_info['currency_code'], false, false);



        // $data['out_summ'] = (float) $this->currency->format($order_info['total'], $order_info['currency_code'], false, false);

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

            $discount = 0;
            foreach ($this->model_checkout_order->getOrderTotals($order_info['order_id']) as $row) {
                if ($row['value'] < 0) {
                    $discount = abs($row['value']);
                }
            };

            $total_price = 0;
            $order_products = $this->model_account_order->getOrderProducts($order_info['order_id']);

            foreach ($order_products as $order_product) {
                $total_price += $order_product['price'] * $order_product['quantity'];
            }

            $discount_percent = $discount / $total_price; // процент скидки на каждый товар

            foreach ($order_products as $order_product) {
                $item_price = $order_product['price'];

                // вычисляем стоимость скидки для каждого товара
                $item_discount = round($item_price * $discount_percent, 2);
                $item_price -= $item_discount;

                $items[] = [
                    'name' => utf8_substr(trim(htmlspecialchars($order_product['name'])), 0, 63),
                    'cost' => round($item_price, 2),
                    'quantity' => $order_product['quantity'],
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

        // подели
        if ($this->config->get('payment_robokassa_status_podeli')) {

            // Проверка, находится ли сумма в диапазоне от 300 до 35000
            $minAmount = 300;
            $maxAmount = 35000;

            if ($data['out_summ'] >= $minAmount && $data['out_summ'] <= $maxAmount) {
                $data['robokassa_status_podeli'] = 1;
                $data['IncCurrLabel'] = 'Podeli';
            } else {
                $data['robokassa_status_podeli'] = 0;
            }

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

        $my_crc = strtoupper(md5($out_summ . ":" . $order_id . ":" . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart"));

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

        $my_crc = strtoupper(md5($out_summ . ":" . $order_id . ":" . $password_2 . ":Shp_item=1" . ":Shp_label=official_opencart"));

        if ($my_crc == $crc) {
            $this->load->model('checkout/order');

            $order_info = $this->model_checkout_order->getOrder($order_id);
            $new_order_status_id = $this->config->get('payment_robokassa_order_status_id');

            echo 'OK' . $this->request->post["InvId"];

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

    public function result2()
    {
        $this->load->model('checkout/order');
        $input_data = file_get_contents('php://input');

        // Проверяем тип контента на "application/json"
        if (strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
            // Разбиваем JWT на три части
            $token_parts = explode('.', $input_data);

            // Проверяем, что есть три части
            if (count($token_parts) === 3) {
                // Декодируем вторую часть (полезные данные)
                $json_data = json_decode(base64_decode($token_parts[1]), true);

                // Проверяем наличие ключевого поля "state" со значением "HOLD"
                if (isset($json_data['data']['state']) && $json_data['data']['state'] === 'HOLD') {
                    // Изменяем статус заказа
                    /*$order = new WC_Order($json_data['data']['invId']);
                    $date_in_five_days = date('Y-m-d H:i:s', strtotime('+5 days'));
                    $order->add_order_note("Robokassa: Платеж успешно подтвержден. Он ожидает подтверждения до {$date_in_five_days}, после чего автоматически отменится");
                    $order->update_status('on-hold');*/
                    $order_id = $json_data['data']['invId'];
                    $order_info = $this->model_checkout_order->getOrder($order_id);
                    $new_order_status_id = 1; // Идентификатор статуса "Pending"
                    $message = "Robokassa: Платеж захолдирован.";

                    if ($order_info['order_status_id'] == 0) {
                        $this->model_checkout_order->addOrderHistory($order_id, $new_order_status_id);
                    }

                    if ($order_info['order_status_id'] != $new_order_status_id) {
                        $this->model_checkout_order->addOrderHistory($order_id, $new_order_status_id, $message);
                    }

                    // Добавляем событие, которое делает unhold через 5 дней
                    //wp_schedule_single_event(strtotime('+5 days'), 'robokassa_cancel_payment_event', array($order->get_id()));
                }
                if (isset($json_data['data']['state']) && $json_data['data']['state'] === 'OK') {
                    // Изменяем статус заказа
                    $order_id = $json_data['data']['invId'];
                    $order_info = $this->model_checkout_order->getOrder($order_id);
                    $new_order_status_id = 2; // Идентификатор статуса "Processing"
                    $message = "Robokassa: Платеж успешно подтвержден.";

                    if ($order_info['order_status_id'] == 0) {
                        $this->model_checkout_order->addOrderHistory($order_id, $new_order_status_id);
                    }

                    if ($order_info['order_status_id'] != $new_order_status_id) {
                        $this->model_checkout_order->addOrderHistory($order_id, $new_order_status_id, $message);
                    }

                }
                http_response_code(200);
            } else {
                http_response_code(400);
            }
        } else {
            http_response_code(415); // Unsupported Media Type
        }
    }

    public function test()
    {
        $this->load->model('extension/payment/robokassa');

        $this->model_extension_payment_robokassa->robokassa_hold_cancel(584);
    }

}
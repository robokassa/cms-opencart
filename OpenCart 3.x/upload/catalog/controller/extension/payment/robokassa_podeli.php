<?php

class ControllerExtensionPaymentRobokassaPodeli extends Controller
{
	/**
	 * Формирует название товара для отправки в чек Robokassa.
	 *
	 * @param int   $order_id
	 * @param array $order_product
	 * @param bool  $is_send_product_options
	 *
	 * @return string
	 */
	private function buildProductName($order_id, array $order_product, $is_send_product_options)
	{
		$product_name = trim(htmlspecialchars($order_product['name']));

		if (!$is_send_product_options) {
			return utf8_substr($product_name, 0, 128);
		}

		$options = $this->model_account_order->getOrderOptions($order_id, $order_product['order_product_id']);
		$formatted_options = $this->formatProductOptions($options);

		if ($formatted_options === '') {
			return utf8_substr($product_name, 0, 128);
		}

		return utf8_substr($product_name . ' (' . $formatted_options . ')', 0, 128);
	}

	/**
	 * Подготавливает опции товара в строку для чека.
	 *
	 * @param array $options
	 *
	 * @return string
	 */
	private function formatProductOptions(array $options)
	{
		$options_list = [];

		foreach ($options as $option) {
			if (empty($option['name']) || empty($option['value'])) {
				continue;
			}

			$name = trim(htmlspecialchars($option['name']));
			$value = trim(htmlspecialchars($option['value']));

			$options_list[] = $name . ': ' . $value;
		}

		return implode(', ', $options_list);
	}
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

        if (isset($this->session->data['guest']['email'])) {
            $data['email'] = $this->session->data['guest']['email'];
        } else {
            $data['email'] = '';
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

			$is_send_product_options = (bool)$this->config->get('payment_robokassa_send_product_options');

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
					'name' => $this->buildProductName($order_info['order_id'], $order_product, $is_send_product_options),,
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
                        'name' => utf8_substr(trim(htmlspecialchars($shipping_name)), 0, 128),
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


        /*        if ($this->config->get('payment_robokassa_status_iframe')) {
                    $data['robokassa_status_iframe'] = 1;
                } else {
                    $data['robokassa_status_iframe'] = 0;
                }*/

        return $this->load->view('extension/payment/robokassa_podeli', $data);
    }

}
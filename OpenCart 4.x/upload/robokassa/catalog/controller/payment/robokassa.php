<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Payment;
class Robokassa extends \Opencart\System\Engine\Controller
{
    public function index() : string {
        $this->load->language('extension/robokassa/payment/robokassa');

        $data['button_confirm'] = $this->language->get('button_confirm');

        $this->load->model('checkout/order');

        $order_id = (int)($this->session->data['order_id'] ?? 0);

        if (!$order_id) {
            return '';
        }

        $order_info = $this->model_checkout_order->getOrder($order_id);

        if (!$order_info) {
            unset($this->session->data['order_id']);

            return $this->getCheckoutRedirectScript();
        }

        if ((int)$order_info['order_status_id'] > 0) {
            unset($this->session->data['order_id']);

            return $this->getCheckoutRedirectScript();
        }

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

        $data['inv_id'] = $order_id;

        $data['order_desc'] = 'Покупка в ' . $this->config->get('config_name');

		$data['result2_url'] = $this->getCallbackUrl('extension/robokassa/payment/result_hold');

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

        $selected_payment_code = $this->getSelectedPaymentCode();

        if ($selected_payment_code === 'robokassa_sbp') {
            $this->load->language('extension/robokassa/payment/robokassa_sbp');

            return $this->renderSbpPayment($order_info, $password_1, $data);
        }

        $inc_curr_labels = [
            'robokassa_mokka' => 'Mokka',
            'robokassa_podeli' => 'Podeli',
            'robokassa_yandex_split' => 'YandexPaySplit',
            'robokassa_credit' => 'OTP'
        ];

        $data['inc_curr_label'] = $inc_curr_labels[$selected_payment_code] ?? '';

        $order_product['quantity'] =  $this->model_checkout_order->getOrder($order_info['order_id']);

        $order_product['price'] = $this->model_checkout_order->getOrder($order_info['order_id']);

        $use_hold = !empty($data['robokassa_status_hold']) && empty($data['inc_curr_label']);

        if ($this->config->get('payment_robokassa_fiscal')) {

            $tax_type = $this->config->get('payment_robokassa_tax_type');
            $tax = $this->config->get('payment_robokassa_tax');
            $payment_method = $this->config->get('payment_robokassa_payment_method');
            $payment_object = $this->config->get('payment_robokassa_payment_object');

            $receipt = [];

            $items = [];

            $discount = 0;
            $order_totals = $this->model_checkout_order->getTotals($order_info['order_id']);

            foreach ($order_totals as $row) {
                if ($row['value'] < 0) {
                    $discount = abs($row['value']);
                }
            };

            $total_price = 0;
            foreach ($this->cart->getProducts() as $order_product) {
                $total_price += $order_product['price'] * $order_product['quantity'];
            }

            $discount_percent = ($discount / $total_price)  ; // процент скидки на каждый товар

            foreach ($this->cart->getProducts() as $order_product) {
                $item_price = (float)$order_product['price'];

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

            $shipping_added = false;

            foreach ($order_totals as $row) {
                if (($row['code'] ?? '') !== 'shipping' || (float)$row['value'] <= 0) {
                    continue;
                }

                $items[] = [
                    'name' => mb_substr(trim(htmlspecialchars((string)$row['title'])), 0, 128, 'UTF-8'),
                    'cost' => round((float)$row['value'], 2),
                    'quantity' => 1,
                    'tax' => $tax,
                    'payment_method' => $payment_method,
                    'payment_object' => $payment_object
                ];

                $shipping_added = true;

                break;
            }

            if ($shipping_added) {
                // Delivery is already included from saved order totals.
            } elseif (isset($this->session->data['shipping_method']) && is_array($this->session->data['shipping_method'])) {
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
                
				$data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $data['out_summ_currency'] . ":" . $data['receipt'] . ":" . ($use_hold ? "true:" . urldecode($data['result2_url']) . ":" : "") . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart");
            } else {
                $data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $data['receipt'] . ":" . ($use_hold ? "true:" . urldecode($data['result2_url']) . ":" : "") . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart");
            }
        } else {
            if (isset($data['out_summ_currency'])) {
                $data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . $data['out_summ_currency'] . ":" . (($use_hold ? "true:" . urldecode($data['result2_url']) . ":" : "") . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart"));
            } else {
                $data['crc'] = md5($data['robokassa_login'] . ":" . $data['out_summ'] . ":" . $data['inv_id'] . ":" . (($use_hold ? "true:" . urldecode($data['result2_url']) . ":" : "") . $password_1 . ":Shp_item=1" . ":Shp_label=official_opencart"));
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

    public function status(): void
    {
        $this->load->model('checkout/order');

        $json = ['paid' => false];
        $order_id = (int)($this->request->get['order_id'] ?? ($this->request->get['amp;order_id'] ?? 0));

        if ($order_id <= 0) {
            $json['error'] = 'invalid_order';
        } else {
            $order_info = $this->model_checkout_order->getOrder($order_id);

            if (!$order_info) {
                $json['error'] = 'order_not_found';
            } else {
                $json['paid'] = $this->isPaidOrderStatus((int)$order_info['order_status_id']);
                $json['order_status_id'] = (int)$order_info['order_status_id'];
                $json['success_url'] = html_entity_decode($this->url->link('checkout/success', '', true), ENT_QUOTES, 'UTF-8');
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    private function getSelectedPaymentCode(): string
    {
        $payment_method = $this->session->data['payment_method'] ?? [];

        if (is_array($payment_method)) {
            $code = (string)($payment_method['code'] ?? '');
        } else {
            $code = (string)$payment_method;
        }

        if (strpos($code, '.') !== false) {
            $parts = explode('.', $code);
            $code = (string)end($parts);
        }

        return $code;
    }

    private function renderSbpPayment(array $order_info, string $password_1, array $base_data): string
    {
        $receipt = '';

        if ($this->config->get('payment_robokassa_fiscal')) {
            $items = [];
            $tax = $this->config->get('payment_robokassa_tax');
            $payment_method = $this->config->get('payment_robokassa_payment_method');
            $payment_object = $this->config->get('payment_robokassa_payment_object');

            foreach ($this->cart->getProducts() as $product) {
                $quantity = (float)$product['quantity'];
                $items[] = [
                    'name' => mb_substr(trim(htmlspecialchars($product['name'])), 0, 128, 'UTF-8'),
                    'sum' => round((float)$product['price'] * $quantity, 2),
                    'quantity' => ((int)$quantity == $quantity) ? (int)$quantity : $quantity,
                    'payment_method' => $payment_method,
                    'payment_object' => $payment_object,
                    'tax' => $tax
                ];
            }

            foreach ($this->model_checkout_order->getTotals($order_info['order_id']) as $row) {
                if (($row['code'] ?? '') !== 'shipping' || (float)$row['value'] <= 0) {
                    continue;
                }

                $items[] = [
                    'name' => mb_substr(trim(htmlspecialchars((string)$row['title'])), 0, 128, 'UTF-8'),
                    'sum' => round((float)$row['value'], 2),
                    'quantity' => 1,
                    'payment_method' => $payment_method,
                    'payment_object' => $payment_object,
                    'tax' => $tax
                ];

                break;
            }

            $receipt = json_encode([
                'sno' => $this->config->get('payment_robokassa_tax_type'),
                'items' => $items
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $out_summ = number_format((float)$this->currency->format($order_info['total'], $order_info['currency_code'], false, false), 2, '.', '');
        $shp_params = [
            'Shp_label' => 'official_opencart',
            'Shp_merchant_id' => $base_data['robokassa_login'],
            'Shp_order_id' => (string)$base_data['inv_id'],
            'Shp_result_url' => $this->getCallbackUrl('extension/robokassa/payment/result')
        ];

        $signature_parts = [
            $base_data['robokassa_login'],
            $out_summ,
            $base_data['inv_id']
        ];

        if ($receipt !== '') {
            $signature_parts[] = $receipt;
        }

        $signature_parts[] = $password_1;

        ksort($shp_params, SORT_STRING);

        foreach ($shp_params as $key => $value) {
            $signature_parts[] = $key . '=' . $value;
        }

        $data = [
            'error_message' => '',
            'text_qr_label' => $this->language->get('text_qr_label'),
            'text_qr_caption' => $this->language->get('text_qr_caption'),
            'text_qr_wait' => $this->language->get('text_qr_wait'),
            'qr_container_id' => 'robokassa-sbp-qr-' . $base_data['inv_id'],
            'qr_container_size' => 280,
            'inv_id' => $base_data['inv_id'],
            'status_url_js' => json_encode(html_entity_decode($this->url->link('extension/robokassa/payment/robokassa|status', 'order_id=' . $base_data['inv_id'], true), ENT_QUOTES, 'UTF-8')),
            'success_url_js' => json_encode(html_entity_decode($this->url->link('checkout/success', '', true), ENT_QUOTES, 'UTF-8')),
            'qr_container_id_js' => json_encode('robokassa-sbp-qr-' . $base_data['inv_id']),
            'shp_params_js' => json_encode($shp_params),
            'email_js' => json_encode($base_data['email']),
            'robokassa_login_js' => json_encode($base_data['robokassa_login']),
            'out_summ_js' => json_encode($out_summ),
            'inv_id_js' => json_encode((string)$base_data['inv_id']),
            'signature_js' => json_encode(md5(implode(':', $signature_parts))),
            'receipt_js' => $receipt !== '' ? json_encode($receipt) : 'null',
            'text_qr_wait_js' => json_encode($this->language->get('text_qr_wait')),
            'text_qr_error_js' => json_encode($this->language->get('text_qr_error'))
        ];

        return $this->load->view('extension/robokassa/payment/robokassa_sbp', $data);
    }

    private function isPaidOrderStatus(int $order_status_id): bool
    {
        $paid_status_ids = $this->normalizeStatusList($this->config->get('payment_robokassa_order_status_id'));
        $paid_status_ids = array_merge($paid_status_ids, $this->normalizeStatusList($this->config->get('config_processing_status')));
        $paid_status_ids = array_merge($paid_status_ids, $this->normalizeStatusList($this->config->get('config_complete_status')));

        return in_array($order_status_id, array_values(array_unique($paid_status_ids)), true);
    }

    private function normalizeStatusList($value): array
    {
        $items = is_array($value) ? $value : (($value === null || $value === '') ? [] : [$value]);
        $status_ids = [];

        foreach ($items as $item) {
            $status_id = (int)$item;

            if ($status_id > 0) {
                $status_ids[] = $status_id;
            }
        }

        return $status_ids;
    }

    private function getCheckoutRedirectScript(): string
    {
        $url = str_replace('&amp;', '&', $this->url->link('checkout/checkout', '', true));

        return '<script type="text/javascript">location = ' . json_encode($url) . ';</script>';
    }

    private function getCallbackUrl(string $route): string
    {
        $server = defined('HTTPS_SERVER') && HTTPS_SERVER ? HTTPS_SERVER : HTTP_SERVER;

        if ($this->isHttpsRequest()) {
            $server = preg_replace('/^http:\/\//i', 'https://', $server);
        }

        return rtrim($server, '/') . '/index.php?route=' . $route;
    }

    private function isHttpsRequest(): bool
    {
        if (!empty($this->request->server['HTTPS']) && strtolower((string)$this->request->server['HTTPS']) !== 'off') {
            return true;
        }

        if (!empty($this->request->server['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$this->request->server['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }

        return !empty($this->request->server['HTTP_X_FORWARDED_SSL']) && strtolower((string)$this->request->server['HTTP_X_FORWARDED_SSL']) !== 'off';
    }

}



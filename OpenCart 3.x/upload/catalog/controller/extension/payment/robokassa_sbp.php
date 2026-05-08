<?php

class ControllerExtensionPaymentRobokassaSbp extends Controller
{
    private function normalizeStatusList($value)
    {
        if (is_array($value)) {
            $items = $value;
        } elseif ($value === null || $value === '') {
            $items = array();
        } else {
            $items = array($value);
        }

        $status_ids = array();

        foreach ($items as $item) {
            $status_id = (int)$item;

            if ($status_id > 0) {
                $status_ids[] = $status_id;
            }
        }

        return array_values(array_unique($status_ids));
    }

    private function isPaidOrderStatus($order_status_id)
    {
        $paid_status_ids = $this->normalizeStatusList($this->config->get('payment_robokassa_order_status_id'));
        $paid_status_ids = array_merge($paid_status_ids, $this->normalizeStatusList($this->config->get('config_processing_status')));
        $paid_status_ids = array_merge($paid_status_ids, $this->normalizeStatusList($this->config->get('config_complete_status')));
        $paid_status_ids = array_values(array_unique($paid_status_ids));

        return in_array((int)$order_status_id, $paid_status_ids, true);
    }

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

    private function formatProductOptions(array $options)
    {
        $options_list = array();

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
        $this->load->language('extension/payment/robokassa_sbp');
        $this->load->model('checkout/order');
        $this->load->model('account/order');

        if (empty($this->session->data['order_id'])) {
            return '';
        }

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        if (!$order_info) {
            return '';
        }

        if ($this->config->get('payment_robokassa_test')) {
            $password_1 = $this->config->get('payment_robokassa_test_password_1');
        } else {
            $password_1 = $this->config->get('payment_robokassa_password_1');
        }

        $data['robokassa_login'] = trim((string)$this->config->get('payment_robokassa_login'));
        $data['inv_id'] = (int)$this->session->data['order_id'];
        $data['out_summ'] = number_format((float)$this->currency->format($order_info['total'], $order_info['currency_code'], false, false), 2, '.', '');
        $data['email'] = !empty($order_info['email']) ? $order_info['email'] : (!empty($this->session->data['guest']['email']) ? $this->session->data['guest']['email'] : '');
        $data['status_url'] = html_entity_decode($this->url->link('extension/payment/robokassa_sbp/status', 'order_id=' . $data['inv_id'], true), ENT_QUOTES, 'UTF-8');
        $data['success_url'] = html_entity_decode($this->url->link('checkout/success', '', true), ENT_QUOTES, 'UTF-8');
        $data['result_url'] = html_entity_decode($this->url->link('extension/payment/robokassa/result', '', true), ENT_QUOTES, 'UTF-8');
        $data['qr_container_id'] = 'robokassa-sbp-qr-' . $data['inv_id'];
        $data['qr_container_size'] = 280;
        $data['receipt'] = '';
        $data['signature'] = '';
        $data['error_message'] = '';
        $data['shp_params'] = array(
            'shp_label' => 'official_opencart',
            'Shp_merchant_id' => $data['robokassa_login'],
            'Shp_order_id' => (string)$data['inv_id'],
            'Shp_result_url' => html_entity_decode($data['result_url'], ENT_QUOTES, 'UTF-8')
        );

        if ($data['robokassa_login'] === '') {
            return '';
        }

        if ($data['email'] === '') {
            $data['error_message'] = $this->language->get('text_email_required');
        }

        if ($this->config->get('payment_robokassa_fiscal')) {
            $tax_type = $this->config->get('payment_robokassa_tax_type');
            $tax = $this->config->get('payment_robokassa_tax');
            $payment_method = $this->config->get('payment_robokassa_payment_method');
            $payment_object = $this->config->get('payment_robokassa_payment_object');
            $is_send_product_options = (bool)$this->config->get('payment_robokassa_send_product_options');

            $items = array();
            $discount = 0;

            foreach ($this->model_checkout_order->getOrderTotals($order_info['order_id']) as $row) {
                if ($row['value'] < 0) {
                    $discount = abs($row['value']);
                }
            }

            $total_price = 0;
            $order_products = $this->model_account_order->getOrderProducts($order_info['order_id']);

            foreach ($order_products as $order_product) {
                $total_price += $order_product['price'] * $order_product['quantity'];
            }

            $discount_percent = ($total_price > 0) ? ($discount / $total_price) : 0;

            foreach ($order_products as $order_product) {
                $item_price = $order_product['price'];
                $item_discount = round($item_price * $discount_percent, 2);
                $item_price -= $item_discount;
                $item_quantity = (float)$order_product['quantity'];
                $item_quantity = ((int)$item_quantity == $item_quantity) ? (int)$item_quantity : $item_quantity;

                $items[] = array(
                    'name' => $this->buildProductName($order_info['order_id'], $order_product, $is_send_product_options),
                    'sum' => round($item_price * $item_quantity, 2),
                    'quantity' => $item_quantity,
                    'payment_method' => $payment_method,
                    'payment_object' => $payment_object,
                    'tax' => $tax
                );
            }

            if (isset($this->session->data['shipping_method'])) {
                $shipping_name = $this->session->data['shipping_method']['title'];
                $shipping_price = $this->session->data['shipping_method']['cost'];

                if ($shipping_price > 0) {
                    $items[] = array(
                        'name' => utf8_substr(trim(htmlspecialchars($shipping_name)), 0, 128),
                        'sum' => round((float)$this->currency->format($shipping_price, 'RUB', false, false), 2),
                        'quantity' => 1,
                        'tax' => $tax,
                        'payment_method' => $payment_method,
                        'payment_object' => $payment_object
                    );
                }
            }

            $data['receipt'] = json_encode(array(
                'sno' => $tax_type,
                'items' => $items
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $signature_parts = array(
            $data['robokassa_login'],
            $data['out_summ'],
            $data['inv_id']
        );

        if ($data['receipt'] !== '') {
            $signature_parts[] = $data['receipt'];
        }

        $signature_parts[] = $password_1;
        $signature_parts[] = 'shp_label=' . $data['shp_params']['shp_label'];
        $signature_parts[] = 'Shp_merchant_id=' . $data['shp_params']['Shp_merchant_id'];
        $signature_parts[] = 'Shp_order_id=' . $data['shp_params']['Shp_order_id'];
        $signature_parts[] = 'Shp_result_url=' . $data['shp_params']['Shp_result_url'];

        $data['signature'] = md5(implode(':', $signature_parts));

        $data['robokassa_login_js'] = json_encode($data['robokassa_login']);
        $data['out_summ_js'] = json_encode((string)$data['out_summ']);
        $data['inv_id_js'] = json_encode((string)$data['inv_id']);
        $data['signature_js'] = json_encode($data['signature']);
        $data['email_js'] = json_encode($data['email']);
        $data['receipt_js'] = ($data['receipt'] !== '') ? json_encode($data['receipt']) : 'null';
        $data['status_url_js'] = json_encode($data['status_url']);
        $data['success_url_js'] = json_encode($data['success_url']);
        $data['qr_container_id_js'] = json_encode($data['qr_container_id']);
        $data['shp_params_js'] = json_encode($data['shp_params']);
        $data['error_message_js'] = json_encode($data['error_message']);
        $data['text_qr_wait_js'] = json_encode($this->language->get('text_qr_wait'));
        $data['text_qr_error_js'] = json_encode($this->language->get('text_qr_error'));

        return $this->load->view('extension/payment/robokassa_sbp', $data);
    }

    public function status()
    {
        $this->load->model('checkout/order');

        $json = array(
            'paid' => false
        );

        if (isset($this->request->get['order_id'])) {
            $order_id = (int)$this->request->get['order_id'];
        } elseif (isset($this->request->get['amp;order_id'])) {
            $order_id = (int)$this->request->get['amp;order_id'];
        } else {
            $order_id = 0;
        }

        if ($order_id <= 0) {
            $json['error'] = 'invalid_order';
        } else {
            $order_info = $this->model_checkout_order->getOrder($order_id);

            if (!$order_info) {
                $json['error'] = 'order_not_found';
            } else {
                $json['paid'] = $this->isPaidOrderStatus($order_info['order_status_id']);
                $json['order_status_id'] = (int)$order_info['order_status_id'];
                $json['success_url'] = html_entity_decode($this->url->link('checkout/success', '', true), ENT_QUOTES, 'UTF-8');
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}

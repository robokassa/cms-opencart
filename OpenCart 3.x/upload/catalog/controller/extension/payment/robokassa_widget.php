<?php

class ControllerExtensionPaymentRobokassaWidget extends Controller
{
    private function getRequestValue(array $source, array $keys, $default = '')
    {
        foreach ($keys as $key) {
            if (isset($source[$key])) {
                return $source[$key];
            }
        }

        return $default;
    }

    private function isWidgetEnabled()
    {
        $store_id = (int)$this->config->get('config_store_id');
        $query = $this->db->query(
            "SELECT `value` FROM `" . DB_PREFIX . "setting`
             WHERE `store_id` = '" . $store_id . "'
               AND `code` = 'payment_robokassa_widget'
               AND `key` = 'payment_robokassa_widget_status'
             LIMIT 1"
        );

        if ($query->num_rows) {
            return (bool)$query->row['value'];
        }

        return (bool)$this->config->get('payment_robokassa_widget_status');
    }

    private function isSecureRequest()
    {
        return !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off';
    }

    private function getPassword1()
    {
        if ($this->config->get('payment_robokassa_test')) {
            return $this->config->get('payment_robokassa_test_password_1');
        }

        return $this->config->get('payment_robokassa_password_1');
    }

    private function getWidgetConfigValue($key, $default = '')
    {
        $value = $this->config->get($key);

        return ($value === null || $value === '') ? $default : $value;
    }

    private function normalizeWidgetChoice($value, array $allowed, $default)
    {
        $value = strtolower(trim((string)$value));

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function normalizeWidgetBoolean($value, $default = true)
    {
        if ($value === null || $value === '') {
            return $default ? 'true' : 'false';
        }

        return (int)$value ? 'true' : 'false';
    }

    private function normalizeWidgetBorderRadius($value, $default)
    {
        $value = trim((string)$value);

        if ($value === '' || !preg_match('/^\d+$/', $value)) {
            return (string)$default;
        }

        return $value;
    }

    private function getWidgetViewSettings()
    {
        return array(
            'bnpl_theme' => $this->normalizeWidgetChoice($this->getWidgetConfigValue('payment_robokassa_widget_bnpl_theme', 'light'), array('light', 'dark'), 'light'),
            'bnpl_size' => $this->normalizeWidgetChoice($this->getWidgetConfigValue('payment_robokassa_widget_bnpl_size', 'm'), array('s', 'm'), 'm'),
            'bnpl_show_logo' => $this->normalizeWidgetBoolean($this->getWidgetConfigValue('payment_robokassa_widget_bnpl_show_logo', 1), true),
            'bnpl_border_radius' => $this->normalizeWidgetBorderRadius($this->getWidgetConfigValue('payment_robokassa_widget_bnpl_border_radius', '50'), '50'),
            'bnpl_has_second_line' => $this->normalizeWidgetBoolean($this->getWidgetConfigValue('payment_robokassa_widget_bnpl_has_second_line', 1), true),
            'bnpl_description_position' => $this->normalizeWidgetChoice($this->getWidgetConfigValue('payment_robokassa_widget_bnpl_description_position', 'right'), array('left', 'right'), 'right'),
            'credit_theme' => $this->normalizeWidgetChoice($this->getWidgetConfigValue('payment_robokassa_widget_credit_theme', 'dark'), array('light', 'dark'), 'dark'),
            'credit_size' => $this->normalizeWidgetChoice($this->getWidgetConfigValue('payment_robokassa_widget_credit_size', 'm'), array('s', 'm'), 'm'),
            'credit_show_logo' => $this->normalizeWidgetBoolean($this->getWidgetConfigValue('payment_robokassa_widget_credit_show_logo', 1), true),
            'credit_border_radius' => $this->normalizeWidgetBorderRadius($this->getWidgetConfigValue('payment_robokassa_widget_credit_border_radius', '12'), '12'),
            'credit_has_second_line' => $this->normalizeWidgetBoolean($this->getWidgetConfigValue('payment_robokassa_widget_credit_has_second_line', 0), false),
            'credit_description_position' => $this->normalizeWidgetChoice($this->getWidgetConfigValue('payment_robokassa_widget_credit_description_position', 'right'), array('left', 'right'), 'right')
        );
    }

    private function getCheckoutUrl($product_id = 0)
    {
        $query = '';

        if ((int)$product_id > 0) {
            $query = 'product_id=' . (int)$product_id;
        }

        return html_entity_decode($this->url->link('extension/payment/robokassa_widget/checkout', $query, $this->isSecureRequest()), ENT_QUOTES, 'UTF-8');
    }

    private function getDisplayAmount($product_info)
    {
        if ((float)$product_info['special']) {
            $price = $product_info['special'];
        } else {
            $price = $product_info['price'];
        }

        return (float)$this->tax->calculate($price, $product_info['tax_class_id'], $this->config->get('config_tax'));
    }

    private function getCustomerEmail()
    {
        if ($this->customer->isLogged() && method_exists($this->customer, 'getEmail')) {
            return trim((string)$this->customer->getEmail());
        }

        if (!empty($this->session->data['guest']['email'])) {
            return trim((string)$this->session->data['guest']['email']);
        }

        if ($this->config->get('config_email')) {
            return trim((string)$this->config->get('config_email'));
        }

        return 'test@test.ru';
    }

    private function isCreditEnabledForAmount($amount)
    {
        return $amount > 0;
    }

    private function isBnplEnabledForAmount($amount)
    {
        return $amount > 0;
    }

    private function normalizePaymentCode($value)
    {
        $normalized = strtolower((string)$value);
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized);

        if ($normalized === '') {
            return '';
        }

        if ($normalized === 'robokassacredit' || strpos($normalized, 'otp') !== false || strpos($normalized, 'credit') !== false) {
            return 'robokassa_credit';
        }

        if ($normalized === 'robokassapodeli' || strpos($normalized, 'podeli') !== false) {
            return 'robokassa_podeli';
        }

        if ($normalized === 'robokassamokka' || strpos($normalized, 'mokka') !== false) {
            return 'robokassa_mokka';
        }

        if ($normalized === 'robokassayandexsplit'
            || strpos($normalized, 'yandex') !== false
            || strpos($normalized, 'yandexpaysplit') !== false
            || strpos($normalized, 'split') !== false) {
            return 'robokassa_yandex_split';
        }

        return '';
    }

    private function extractPaymentCodeFromPayload($payload)
    {
        if (is_array($payload)) {
            $keys = array(
                'payment_method',
                'paymentMethod',
                'payment_method_hint',
                'method',
                'currLabel',
                'curr_label',
                'label',
                'alias',
                'incCurrLabel',
                'IncCurrLabel',
                'type'
            );

            foreach ($keys as $key) {
                if (!empty($payload[$key])) {
                    $payment_code = $this->normalizePaymentCode($payload[$key]);

                    if ($payment_code !== '') {
                        return $payment_code;
                    }
                }
            }

            foreach ($payload as $value) {
                if (is_array($value) || is_string($value)) {
                    $payment_code = $this->extractPaymentCodeFromPayload($value);

                    if ($payment_code !== '') {
                        return $payment_code;
                    }
                }
            }

            return '';
        }

        if (!is_string($payload)) {
            return '';
        }

        $payload = trim($payload);

        if ($payload === '') {
            return '';
        }

        $decoded = json_decode($payload, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $payment_code = $this->extractPaymentCodeFromPayload($decoded);

            if ($payment_code !== '') {
                return $payment_code;
            }
        }

        $parsed = array();
        parse_str(html_entity_decode($payload, ENT_QUOTES, 'UTF-8'), $parsed);

        if ($parsed) {
            $payment_code = $this->extractPaymentCodeFromPayload($parsed);

            if ($payment_code !== '') {
                return $payment_code;
            }
        }

        return $this->normalizePaymentCode($payload);
    }

    private function resolveSelectedPaymentCode()
    {
        $direct_keys = array(
            'payment_method',
            'paymentMethod',
            'payment_method_hint',
            'currLabel',
            'curr_label',
            'alias',
            'type'
        );

        foreach ($direct_keys as $key) {
            if (isset($this->request->post[$key])) {
                $payment_code = $this->extractPaymentCodeFromPayload($this->request->post[$key]);

                if ($payment_code !== '') {
                    return $payment_code;
                }
            }
        }

        $payload_keys = array('widget_payload', 'payload', 'event');

        foreach ($payload_keys as $key) {
            if (isset($this->request->post[$key])) {
                $payment_code = $this->extractPaymentCodeFromPayload($this->request->post[$key]);

                if ($payment_code !== '') {
                    return $payment_code;
                }
            }
        }

        return '';
    }

    private function buildReceipt($product_info, $quantity, $unit_amount)
    {
        $product_name = utf8_substr(trim(htmlspecialchars_decode($product_info['name'], ENT_QUOTES)), 0, 128);
        $quantity = max(1, (int)$quantity);
        $unit_amount = round((float)$unit_amount, 2);
        $total_amount = round($unit_amount * $quantity, 2);

        $receipt = array(
            'sno' => $this->config->get('payment_robokassa_tax_type') ? $this->config->get('payment_robokassa_tax_type') : 'osn',
            'items' => array(
                array(
                    'name' => $product_name,
                    'quantity' => $quantity,
                    'cost' => $unit_amount,
                    'sum' => $total_amount,
                    'payment_method' => $this->config->get('payment_robokassa_payment_method') ? $this->config->get('payment_robokassa_payment_method') : 'full_payment',
                    'payment_object' => $this->config->get('payment_robokassa_payment_object') ? $this->config->get('payment_robokassa_payment_object') : 'commodity',
                    'tax' => $this->config->get('payment_robokassa_tax') ? $this->config->get('payment_robokassa_tax') : 'none'
                )
            )
        );

        return urlencode(json_encode($receipt, JSON_UNESCAPED_UNICODE));
    }

    private function buildWidgetData($product_id, $quantity)
    {
        $this->load->model('catalog/product');

        $product_info = $this->model_catalog_product->getProduct((int)$product_id);

        if (!$product_info || $this->config->get('payment_robokassa_country') != 'RUB') {
            return array();
        }

        $merchant_login = trim((string)$this->config->get('payment_robokassa_login'));
        $password_1 = $this->getPassword1();

        if (!$merchant_login || !$password_1) {
            return array();
        }

        $quantity = max(1, (int)$quantity);
        $unit_amount = $this->getDisplayAmount($product_info);
        $amount = round($unit_amount * $quantity, 2);
        $show_bnpl = $this->isBnplEnabledForAmount($amount);
        $show_credit = $this->isCreditEnabledForAmount($amount);

        if (!$show_bnpl && !$show_credit) {
            return array();
        }

        $out_sum = number_format($amount, 2, '.', '');
        $receipt = $this->buildReceipt($product_info, $quantity, $unit_amount);
        $signature = md5($merchant_login . ':' . $out_sum . '::' . $receipt . ':' . $password_1);

        return array(
            'product_id' => (int)$product_id,
            'quantity' => $quantity,
            'merchant_login' => $merchant_login,
            'out_sum' => $out_sum,
            'receipt' => $receipt,
            'signature' => $signature,
            'email' => $this->getCustomerEmail(),
            'show_bnpl' => $show_bnpl,
            'show_credit' => $show_credit
        );
    }

    public function data()
    {
        $product_id = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
        $quantity = isset($this->request->get['quantity']) ? (int)$this->request->get['quantity'] : 1;
        $widget_data = $this->buildWidgetData($product_id, $quantity);

        $this->response->addHeader('Content-Type: application/json');

        if (!$widget_data) {
            $this->response->setOutput(json_encode(array('success' => false)));

            return;
        }

        $this->response->setOutput(json_encode(array(
            'success' => true,
            'merchant_login' => $widget_data['merchant_login'],
            'out_sum' => $widget_data['out_sum'],
            'receipt' => $widget_data['receipt'],
            'signature' => $widget_data['signature'],
            'email' => $widget_data['email'],
            'show_bnpl' => $widget_data['show_bnpl'],
            'show_credit' => $widget_data['show_credit']
        )));
    }

    public function checkout()
    {
        $product_id = (int)$this->getRequestValue($this->request->post, array('product_id'));

        if (!$product_id) {
            $product_id = (int)$this->getRequestValue($this->request->get, array('product_id'));
        }

        $quantity = (int)$this->getRequestValue($this->request->post, array('quantity'), 1);

        if (!$quantity) {
            $quantity = (int)$this->getRequestValue($this->request->get, array('quantity'), 1);
        }

        $quantity = $quantity > 0 ? $quantity : 1;

        $option = $this->getRequestValue($this->request->post, array('option'), array());
        $option = is_array($option) ? $option : array();

        $recurring_id = (int)$this->getRequestValue($this->request->post, array('recurring_id'), 0);

        if ($product_id > 0) {
            $this->cart->add($product_id, $quantity, $option, $recurring_id);

            unset($this->session->data['shipping_method']);
            unset($this->session->data['shipping_methods']);
            unset($this->session->data['payment_method']);
            unset($this->session->data['payment_methods']);
            unset($this->session->data['reward']);
        }

        $selected_payment_code = $this->resolveSelectedPaymentCode();

        if ($selected_payment_code === '') {
            $selected_payment_code = $this->extractPaymentCodeFromPayload($this->request->get);
        }

        if ($selected_payment_code !== '') {
            $this->session->data['robokassa_widget_payment_code'] = $selected_payment_code;

            if (isset($this->session->data['payment_methods'][$selected_payment_code])) {
                $this->session->data['payment_method'] = $this->session->data['payment_methods'][$selected_payment_code];
            }
        } else {
            unset($this->session->data['robokassa_widget_payment_code']);
        }

        $this->response->redirect($this->url->link('checkout/checkout', '', true));
    }

    public function index($setting = array())
    {
        $product_id = !empty($setting['product_id']) ? (int)$setting['product_id'] : 0;
        $quantity = !empty($setting['quantity']) ? (int)$setting['quantity'] : 1;

        if (!$product_id
            || !$this->isWidgetEnabled()
            || $this->config->get('payment_robokassa_country') != 'RUB') {
            return '';
        }

        $widget_data = $this->buildWidgetData($product_id, $quantity);

        if (!$widget_data) {
            return '';
        }

        $widget_view_settings = $this->getWidgetViewSettings();

        $data['merchant_login'] = $widget_data['merchant_login'];
        $data['out_sum'] = $widget_data['out_sum'];
        $data['receipt'] = $widget_data['receipt'];
        $data['signature'] = $widget_data['signature'];
        $data['email'] = $widget_data['email'];
        $data['show_bnpl'] = $widget_data['show_bnpl'];
        $data['show_credit'] = $widget_data['show_credit'];
        $data['product_id'] = $widget_data['product_id'];
        $data['widget_data_url'] = html_entity_decode($this->url->link('extension/payment/robokassa_widget/data', '', $this->isSecureRequest()), ENT_QUOTES, 'UTF-8');
        $data['checkout_url'] = $this->getCheckoutUrl($widget_data['product_id']);
        $data['bnpl_theme'] = $widget_view_settings['bnpl_theme'];
        $data['bnpl_size'] = $widget_view_settings['bnpl_size'];
        $data['bnpl_show_logo'] = $widget_view_settings['bnpl_show_logo'];
        $data['bnpl_border_radius'] = $widget_view_settings['bnpl_border_radius'];
        $data['bnpl_has_second_line'] = $widget_view_settings['bnpl_has_second_line'];
        $data['bnpl_description_position'] = $widget_view_settings['bnpl_description_position'];
        $data['credit_theme'] = $widget_view_settings['credit_theme'];
        $data['credit_size'] = $widget_view_settings['credit_size'];
        $data['credit_show_logo'] = $widget_view_settings['credit_show_logo'];
        $data['credit_border_radius'] = $widget_view_settings['credit_border_radius'];
        $data['credit_has_second_line'] = $widget_view_settings['credit_has_second_line'];
        $data['credit_description_position'] = $widget_view_settings['credit_description_position'];

        return $this->load->view('extension/payment/robokassa_widget', $data);
    }
}

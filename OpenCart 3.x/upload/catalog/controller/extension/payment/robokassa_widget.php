<?php

class ControllerExtensionPaymentRobokassaWidget extends Controller
{
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

        $data['merchant_login'] = $widget_data['merchant_login'];
        $data['out_sum'] = $widget_data['out_sum'];
        $data['receipt'] = $widget_data['receipt'];
        $data['signature'] = $widget_data['signature'];
        $data['email'] = $widget_data['email'];
        $data['show_bnpl'] = $widget_data['show_bnpl'];
        $data['show_credit'] = $widget_data['show_credit'];
        $data['product_id'] = $widget_data['product_id'];
        $data['widget_data_url'] = html_entity_decode($this->url->link('extension/payment/robokassa_widget/data', '', $this->isSecureRequest()), ENT_QUOTES, 'UTF-8');

        return $this->load->view('extension/payment/robokassa_widget', $data);
    }
}

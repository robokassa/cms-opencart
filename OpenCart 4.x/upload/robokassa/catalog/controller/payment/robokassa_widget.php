<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Payment;

class RobokassaWidget extends \Opencart\System\Engine\Controller
{
    private function isSecureRequest(): bool
    {
        return !empty($this->request->server['HTTPS']) && strtolower((string)$this->request->server['HTTPS']) !== 'off';
    }

    private function getActionRoute(string $method): string
    {
        $separator = defined('VERSION') && version_compare(VERSION, '4.0.2.0', '>=') ? '.' : '|';

        return 'extension/robokassa/payment/robokassa_widget' . $separator . $method;
    }

    private function getPassword1(): string
    {
        return (string)($this->config->get('payment_robokassa_test') ? $this->config->get('payment_robokassa_test_password_1') : $this->config->get('payment_robokassa_password_1'));
    }

    private function getCustomerEmail(): string
    {
        if ($this->customer->isLogged() && method_exists($this->customer, 'getEmail')) {
            return trim((string)$this->customer->getEmail());
        }

        return trim((string)$this->config->get('config_email')) ?: 'test@test.ru';
    }

    private function normalizePaymentCode(string $value): string
    {
        $value = strtolower(preg_replace('/[^a-z0-9]+/', '', $value));

        if (strpos($value, 'otp') !== false || strpos($value, 'credit') !== false) {
            return 'robokassa_credit';
        }

        if (strpos($value, 'podeli') !== false) {
            return 'robokassa_podeli';
        }

        if (strpos($value, 'mokka') !== false) {
            return 'robokassa_mokka';
        }

        if (strpos($value, 'yandex') !== false || strpos($value, 'split') !== false) {
            return 'robokassa_yandex_split';
        }

        return '';
    }

    private function resolveSelectedPaymentCode(): string
    {
        foreach (['payment_method', 'paymentMethod', 'method', 'currLabel', 'label', 'alias', 'incCurrLabel', 'type'] as $key) {
            if (!empty($this->request->post[$key])) {
                $code = $this->normalizePaymentCode((string)$this->request->post[$key]);

                if ($code !== '') {
                    return $code;
                }
            }
        }

        if (!empty($this->request->post['widget_payload'])) {
            $payload = json_decode((string)$this->request->post['widget_payload'], true);

            if (is_array($payload)) {
                foreach (['payment_method', 'paymentMethod', 'method', 'currLabel', 'label', 'alias', 'incCurrLabel', 'type'] as $key) {
                    if (!empty($payload[$key])) {
                        $code = $this->normalizePaymentCode((string)$payload[$key]);

                        if ($code !== '') {
                            return $code;
                        }
                    }
                }
            }
        }

        return $this->normalizePaymentCode((string)($this->request->get['payment_method'] ?? ''));
    }

    private function buildWidgetData(int $product_id, int $quantity): array
    {
        if (!$this->config->get('payment_robokassa_widget_status') || $this->config->get('payment_robokassa_country') !== 'RUB') {
            return [];
        }

        $this->load->model('catalog/product');
        $product_info = $this->model_catalog_product->getProduct($product_id);

        if (!$product_info) {
            return [];
        }

        $merchant_login = trim((string)$this->config->get('payment_robokassa_login'));
        $password_1 = $this->getPassword1();

        if ($merchant_login === '' || $password_1 === '') {
            return [];
        }

        $quantity = max(1, $quantity);
        $price = (float)$product_info['special'] ? (float)$product_info['special'] : (float)$product_info['price'];
        $unit_amount = (float)$this->tax->calculate($price, $product_info['tax_class_id'], $this->config->get('config_tax'));
        $out_sum = number_format(round($unit_amount * $quantity, 2), 2, '.', '');
        $receipt = urlencode(json_encode([
            'sno' => $this->config->get('payment_robokassa_tax_type') ?: 'osn',
            'items' => [[
                'name' => mb_substr(trim(htmlspecialchars_decode($product_info['name'], ENT_QUOTES)), 0, 128, 'UTF-8'),
                'quantity' => $quantity,
                'cost' => round($unit_amount, 2),
                'sum' => round($unit_amount * $quantity, 2),
                'payment_method' => $this->config->get('payment_robokassa_payment_method') ?: 'full_payment',
                'payment_object' => $this->config->get('payment_robokassa_payment_object') ?: 'commodity',
                'tax' => $this->config->get('payment_robokassa_tax') ?: 'none'
            ]]
        ], JSON_UNESCAPED_UNICODE));

        return [
            'product_id' => $product_id,
            'quantity' => $quantity,
            'merchant_login' => $merchant_login,
            'out_sum' => $out_sum,
            'receipt' => $receipt,
            'signature' => md5($merchant_login . ':' . $out_sum . '::' . $receipt . ':' . $password_1),
            'email' => $this->getCustomerEmail(),
            'show_bnpl' => true,
            'show_credit' => true
        ];
    }

    public function data(): void
    {
        $product_id = (int)($this->request->get['product_id'] ?? 0);
        $quantity = max(1, (int)($this->request->get['quantity'] ?? 1));
        $widget_data = $this->buildWidgetData($product_id, $quantity);

        $this->response->addHeader('Content-Type: application/json');

        if (!$widget_data) {
            $this->response->setOutput(json_encode(['success' => false]));
            return;
        }

        $this->response->setOutput(json_encode([
            'success' => true,
            'merchant_login' => $widget_data['merchant_login'],
            'out_sum' => $widget_data['out_sum'],
            'receipt' => $widget_data['receipt'],
            'signature' => $widget_data['signature'],
            'email' => $widget_data['email'],
            'show_bnpl' => $widget_data['show_bnpl'],
            'show_credit' => $widget_data['show_credit']
        ]));
    }

    public function checkout(): void
    {
        $product_id = (int)($this->request->post['product_id'] ?? ($this->request->get['product_id'] ?? 0));
        $quantity = max(1, (int)($this->request->post['quantity'] ?? ($this->request->get['quantity'] ?? 1)));
        $payment_method = $this->resolveSelectedPaymentCode();

        if ($product_id > 0) {
            $option = $this->request->post['option'] ?? [];
            $option = is_array($option) ? $option : [];
            $subscription_plan_id = (int)($this->request->post['subscription_plan_id'] ?? 0);

            $this->cart->add($product_id, $quantity, $option, $subscription_plan_id);
            unset($this->session->data['shipping_method'], $this->session->data['shipping_methods'], $this->session->data['payment_method'], $this->session->data['payment_methods']);
        }

        if ($payment_method !== '') {
            $this->session->data['robokassa_widget_payment_code'] = $payment_method;
        }

        $this->response->redirect($this->url->link('checkout/checkout', '', true));
    }

    public function index(array $setting = []): string
    {
        $product_id = (int)($setting['product_id'] ?? 0);
        $quantity = max(1, (int)($setting['quantity'] ?? 1));
        $widget_data = $this->buildWidgetData($product_id, $quantity);

        if (!$widget_data) {
            return '';
        }

        $data = $widget_data;
        $data['widget_data_url'] = html_entity_decode($this->url->link($this->getActionRoute('data'), '', $this->isSecureRequest()), ENT_QUOTES, 'UTF-8');
        $data['checkout_url'] = html_entity_decode($this->url->link($this->getActionRoute('checkout'), '', $this->isSecureRequest()), ENT_QUOTES, 'UTF-8');

        foreach ([
            'bnpl_theme' => 'payment_robokassa_widget_bnpl_theme',
            'bnpl_size' => 'payment_robokassa_widget_bnpl_size',
            'bnpl_show_logo' => 'payment_robokassa_widget_bnpl_show_logo',
            'bnpl_border_radius' => 'payment_robokassa_widget_bnpl_border_radius',
            'bnpl_has_second_line' => 'payment_robokassa_widget_bnpl_has_second_line',
            'bnpl_description_position' => 'payment_robokassa_widget_bnpl_description_position',
            'credit_theme' => 'payment_robokassa_widget_credit_theme',
            'credit_size' => 'payment_robokassa_widget_credit_size',
            'credit_show_logo' => 'payment_robokassa_widget_credit_show_logo',
            'credit_border_radius' => 'payment_robokassa_widget_credit_border_radius',
            'credit_has_second_line' => 'payment_robokassa_widget_credit_has_second_line',
            'credit_description_position' => 'payment_robokassa_widget_credit_description_position'
        ] as $view_key => $config_key) {
            $data[$view_key] = $this->config->get($config_key);
        }

        $data['bnpl_theme'] = $data['bnpl_theme'] ?: 'light';
        $data['bnpl_size'] = $data['bnpl_size'] ?: 'm';
        $data['bnpl_show_logo'] = $data['bnpl_show_logo'] === null || $data['bnpl_show_logo'] === '' ? 'true' : ((int)$data['bnpl_show_logo'] ? 'true' : 'false');
        $data['bnpl_border_radius'] = $data['bnpl_border_radius'] ?: '50';
        $data['bnpl_has_second_line'] = $data['bnpl_has_second_line'] === null || $data['bnpl_has_second_line'] === '' ? 'true' : ((int)$data['bnpl_has_second_line'] ? 'true' : 'false');
        $data['bnpl_description_position'] = $data['bnpl_description_position'] ?: 'right';
        $data['credit_theme'] = $data['credit_theme'] ?: 'dark';
        $data['credit_size'] = $data['credit_size'] ?: 'm';
        $data['credit_show_logo'] = $data['credit_show_logo'] === null || $data['credit_show_logo'] === '' ? 'true' : ((int)$data['credit_show_logo'] ? 'true' : 'false');
        $data['credit_border_radius'] = $data['credit_border_radius'] ?: '12';
        $data['credit_has_second_line'] = (int)$data['credit_has_second_line'] ? 'true' : 'false';
        $data['credit_description_position'] = $data['credit_description_position'] ?: 'right';

        return $this->load->view('extension/robokassa/payment/robokassa_widget', $data);
    }
}

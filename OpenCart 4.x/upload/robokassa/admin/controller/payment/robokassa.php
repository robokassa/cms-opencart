<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Payment;

class Robokassa extends \Opencart\System\Engine\Controller
{
    private array $error = [];

    public function index(): void
    {
        $this->load->language('extension/robokassa/payment/robokassa');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
        $this->load->model('localisation/language');

        if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
            $this->syncExtraPaymentControllers($this->getStoredInstallmentAliases(), $this->getStoredInstallmentLabels());
        }

        if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
            $is_update_request = isset($this->request->post['robokassa_action']) && $this->request->post['robokassa_action'] === 'update_methods';
            $methods_initialized = (int)$this->config->get('payment_robokassa_methods_initialized') === 1;
            $merchant_login = trim((string)$this->request->post['payment_robokassa_login']);
            $methods_login = trim((string)$this->config->get('payment_robokassa_methods_login'));
            $is_first_sync = !$methods_initialized;
            $is_login_changed = $methods_initialized && $methods_login !== $merchant_login;
            $methods_updated = false;

            $this->saveSettingDirect('payment_robokassa', $this->request->post);

            if ($is_update_request || $is_first_sync || $is_login_changed) {
                $methods = $this->fetchInstallmentMethods($merchant_login);

                if ($methods === false) {
                    $this->session->data['error_warning'] = 'Failed to update Robokassa payment methods. Check Merchant Login and GetCurrencies availability.';
                    $this->response->redirect($this->url->link('extension/robokassa/payment/robokassa', 'user_token=' . $this->session->data['user_token'], true));

                    return;
                }

                $aliases = $methods['aliases'];

                $this->saveSettingDirect('payment_robokassa_methods', [
                    'payment_robokassa_methods_login' => $merchant_login,
                    'payment_robokassa_methods_initialized' => 1,
                    'payment_robokassa_methods_aliases' => $aliases,
                    'payment_robokassa_methods_labels' => $methods['labels']
                ]);

                $this->syncExtraPaymentControllers($aliases, $methods['labels']);
                $methods_updated = true;
            }

            $this->registerEvents();

            $this->load->model('user/user_group');
            $route = 'extension/robokassa/event/robokassa';
            $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', $route);
            $this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', $route);

            if ($methods_updated && $is_update_request) {
                $this->session->data['robokassa_methods_success'] = 'Robokassa payment methods updated.';
            }

            if ($is_update_request) {
                $this->response->redirect($this->url->link('extension/robokassa/payment/robokassa', 'user_token=' . $this->session->data['user_token'], true));
            } else {
                $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
            }
        }

        $data['error_warning'] = $this->session->data['error_warning'] ?? ($this->error['warning'] ?? '');
        unset($this->session->data['error_warning']);
        $data['success'] = $this->session->data['robokassa_methods_success'] ?? '';
        unset($this->session->data['robokassa_methods_success']);
        $data['error_merch_login'] = $this->error['merch_login'] ?? '';
        $data['error_password1'] = $this->error['e_password1'] ?? '';
        $data['error_password2'] = $this->error['e_password2'] ?? '';

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_payment'),
            'href' => $this->url->link('marketplace/opencart/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/robokassa/payment/robokassa', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['text_all_zones'] = $this->language->get('text_all_zones');
        $data['entry_tax'] = $this->language->get('entry_tax');
        $data['entry_tax_type'] = $this->language->get('entry_tax_type');
        $data['entry_payment_method'] = $this->language->get('entry_payment_method');
        $data['entry_payment_object'] = $this->language->get('entry_payment_object');
        $data['entry_fiscal'] = $this->language->get('entry_fiscal');
        $data['text_yes'] = $this->language->get('text_yes');
        $data['text_no'] = $this->language->get('text_no');
        $data['text_kz'] = $this->language->get('text_kz');
        $data['text_ru'] = $this->language->get('text_ru');
        $data['button_update_methods'] = 'Update payment methods';

        $data['entry_login'] = $this->language->get('entry_login');
        $data['entry_password1'] = $this->language->get('entry_password1');
        $data['entry_password2'] = $this->language->get('entry_password2');
        $data['entry_test_password1'] = $this->language->get('entry_test_password1');
        $data['entry_test_password2'] = $this->language->get('entry_test_password2');
        $data['entry_result_url'] = $this->language->get('entry_result_url');
        $data['entry_success_url'] = $this->language->get('entry_success_url');
        $data['entry_fail_url'] = $this->language->get('entry_fail_url');
        $data['entry_test'] = $this->language->get('entry_test');
        $data['entry_order_status'] = $this->language->get('entry_order_status');
        $data['entry_geo_zone'] = $this->language->get('entry_geo_zone');
        $data['entry_status'] = $this->language->get('entry_status');
        $data['entry_sort_order'] = $this->language->get('entry_sort_order');
        $data['entry_country'] = $this->language->get('entry_country');
        $data['entry_iframe'] = $this->language->get('entry_iframe');
        $data['entry_hold'] = $this->language->get('entry_hold');

        $data['help_fiscal'] = $this->language->get('help_fiscal');
        $data['help_iframe'] = $this->language->get('help_iframe');
        $data['help_hold'] = $this->language->get('help_hold');

        $data['action'] = $this->url->link('extension/robokassa/payment/robokassa', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        $data['opencart_languages'] = $this->model_localisation_language->getLanguages();

        $data['payment_robokassa_login'] = $this->request->post['payment_robokassa_login'] ?? $this->config->get('payment_robokassa_login');
        $data['payment_robokassa_password_1'] = $this->request->post['payment_robokassa_password_1'] ?? $this->config->get('payment_robokassa_password_1');
        $data['payment_robokassa_password_2'] = $this->request->post['payment_robokassa_password_2'] ?? $this->config->get('payment_robokassa_password_2');
        $data['payment_robokassa_test_password_1'] = $this->request->post['payment_robokassa_test_password_1'] ?? $this->config->get('payment_robokassa_test_password_1');
        $data['payment_robokassa_test_password_2'] = $this->request->post['payment_robokassa_test_password_2'] ?? $this->config->get('payment_robokassa_test_password_2');

        $current_login_for_sync = trim((string)$data['payment_robokassa_login']);
        $current_password1_for_sync = trim((string)$data['payment_robokassa_password_1']);
        $current_password2_for_sync = trim((string)$data['payment_robokassa_password_2']);
        $current_methods_login = trim((string)$this->config->get('payment_robokassa_methods_login'));
        $data['show_update_methods'] = $current_login_for_sync !== ''
            && $current_password1_for_sync !== ''
            && $current_password2_for_sync !== ''
            && (int)$this->config->get('payment_robokassa_methods_initialized') === 1
            && $current_methods_login === $current_login_for_sync;

        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            $data['payment_robokassa_result_url'] = 'https://' . $_SERVER['SERVER_NAME'] . '/index.php?route=extension/robokassa/payment/result';
            $data['payment_robokassa_success_url'] = 'https://' . $_SERVER['SERVER_NAME'] . '/index.php?route=extension/robokassa/payment/success';
            $data['payment_robokassa_fail_url'] = 'https://' . $_SERVER['SERVER_NAME'] . '/index.php?route=extension/robokassa/payment/fail';
        } else {
            $data['payment_robokassa_result_url'] = HTTP_CATALOG . 'index.php?route=extension/robokassa/payment/result';
            $data['payment_robokassa_success_url'] = HTTP_CATALOG . 'index.php?route=extension/robokassa/payment/success';
            $data['payment_robokassa_fail_url'] = HTTP_CATALOG . 'index.php?route=extension/robokassa/payment/fail';
        }

        $data['payment_robokassa_result_method'] = 'POST';
        $data['payment_robokassa_success_method'] = 'GET';
        $data['payment_robokassa_fail_method'] = 'POST';

        $data['payment_robokassa_test'] = $this->request->post['payment_robokassa_test'] ?? $this->config->get('payment_robokassa_test');
        $data['payment_robokassa_mokka_status'] = $this->request->post['payment_robokassa_mokka_status'] ?? $this->config->get('payment_robokassa_mokka_status');
        $data['payment_robokassa_podeli_status'] = $this->request->post['payment_robokassa_podeli_status'] ?? $this->config->get('payment_robokassa_podeli_status');
        $data['payment_robokassa_yandex_split_status'] = $this->request->post['payment_robokassa_yandex_split_status'] ?? $this->config->get('payment_robokassa_yandex_split_status');
        $data['payment_robokassa_credit_status'] = $this->request->post['payment_robokassa_credit_status'] ?? $this->config->get('payment_robokassa_credit_status');
        $data['payment_robokassa_sbp_status'] = $this->request->post['payment_robokassa_sbp_status'] ?? $this->config->get('payment_robokassa_sbp_status');
        $data['payment_robokassa_widget_status'] = $this->request->post['payment_robokassa_widget_status'] ?? $this->config->get('payment_robokassa_widget_status');

        foreach ([
            'payment_robokassa_widget_bnpl_theme' => 'light',
            'payment_robokassa_widget_bnpl_size' => 'm',
            'payment_robokassa_widget_bnpl_show_logo' => 1,
            'payment_robokassa_widget_bnpl_border_radius' => '50',
            'payment_robokassa_widget_bnpl_has_second_line' => 1,
            'payment_robokassa_widget_bnpl_description_position' => 'right',
            'payment_robokassa_widget_credit_theme' => 'dark',
            'payment_robokassa_widget_credit_size' => 'm',
            'payment_robokassa_widget_credit_show_logo' => 1,
            'payment_robokassa_widget_credit_border_radius' => '12',
            'payment_robokassa_widget_credit_has_second_line' => 0,
            'payment_robokassa_widget_credit_description_position' => 'right'
        ] as $key => $default) {
            $data[$key] = $this->request->post[$key] ?? ($this->config->get($key) !== null && $this->config->get($key) !== '' ? $this->config->get($key) : $default);
        }

        if (isset($this->request->post['payment_robokassa_country'])) {
            $data['payment_robokassa_country'] = $this->request->post['payment_robokassa_country'];
        } elseif ($this->config->get('payment_robokassa_country')) {
            $data['payment_robokassa_country'] = $this->config->get('payment_robokassa_country');
        } else {
            $data['payment_robokassa_country'] = 'RUB';
        }

        $data['payment_robokassa_languages_map'] = $this->request->post['payment_robokassa_languages_map'] ?? $this->config->get('payment_robokassa_languages_map');

        $data['robokassa_available_languages'] = ['en', 'ru'];

        $data['robokassa_tax_type_list'] = [
            'osn' => 'общая СН',
            'usn_income' => 'упрощенная СН (доходы)',
            'usn_income_outcome' => 'упрощенная СН (доходы минус расходы)',
            'envd' => 'единый налог на вмененный доход',
            'esn' => 'единый сельскохозяйственный налог',
            'patent' => 'патентная СН',
        ];

        $data['robokassa_tax_list'] = [
            'none' => 'без НДС',
            'vat0' => 'НДС по ставке 0%',
            'vat5' => 'НДС по ставке 5%',
            'vat7' => 'НДС по ставке 7%',
            'vat10' => 'НДС чека по ставке 10%',
            'vat20' => 'НДС чека по ставке 20%',
            'vat22' => 'НДС чека по ставке 22%',
            'vat105' => 'НДС чека по расчетной ставке 5/105',
            'vat107' => 'НДС чека по расчетной ставке 7/107',
            'vat110' => 'НДС чека по расчетной ставке 10/110',
            'vat120' => 'НДС чека по расчетной ставке 20/120',
            'vat122' => 'НДС чека по расчетной ставке 22/122'
        ];

        $data['robokassa_tax_list_kz'] = [
            'none' => 'без НДС',
            'vat0' => 'НДС чека по ставке 8%',
            'vat12' => 'НДС чека по ставке 12%',
        ];

        $data['robokassa_payment_method_list'] = [
            'full_prepayment' => 'предоплата 100% (по умолчанию)',
            'prepayment' => 'предоплата',
            'advance' => 'аванс',
            'full_payment' => 'полный расчет',
            'partial_payment' => 'частичный расчет и кредит',
            'credit' => 'передача в кредит',
            'credit_payment' => 'оплата кредита',
        ];

        $data['robokassa_payment_object_list'] = [
            'commodity' => 'товар (по умолчанию)',
            'excise' => 'подакцизный товар',
            'job' => 'работа',
            'service' => 'услуга',
            'gambling_bet' => 'ставка азартной игры',
            'gambling_prize' => 'выигрыш азартной игры',
            'lottery' => 'лотерейный билет',
            'lottery_prize' => 'выигрыш лотереи',
            'intellectual_activity' => 'предоставление результатов интеллектуальной деятельности',
            'payment' => 'платеж',
            'agent_commission' => 'агентское вознаграждение',
            'composite' => 'составной предмет расчета',
            'another' => 'иной предмет расчета',
        ];

        $data['payment_robokassa_tax_type'] = $this->request->post['payment_robokassa_tax_type'] ?? $this->config->get('payment_robokassa_tax_type');
        $data['payment_robokassa_tax'] = $this->request->post['payment_robokassa_tax'] ?? $this->config->get('payment_robokassa_tax');
        $data['payment_robokassa_fiscal'] = $this->request->post['payment_robokassa_fiscal'] ?? $this->config->get('payment_robokassa_fiscal');
        $data['payment_robokassa_payment_method'] = $this->request->post['payment_robokassa_payment_method'] ?? $this->config->get('payment_robokassa_payment_method');
        $data['payment_robokassa_payment_object'] = $this->request->post['payment_robokassa_payment_object'] ?? $this->config->get('payment_robokassa_payment_object');

        $data['payment_robokassa_order_status_id'] = $this->request->post['payment_robokassa_order_status_id'] ?? $this->config->get('payment_robokassa_order_status_id');
        $data['payment_robokassa_order_status_id_2check'] = $this->request->post['payment_robokassa_order_status_id_2check'] ?? $this->config->get('payment_robokassa_order_status_id_2check');

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['payment_robokassa_geo_zone_id'] = $this->request->post['payment_robokassa_geo_zone_id'] ?? $this->config->get('payment_robokassa_geo_zone_id');

        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $data['payment_robokassa_status'] = $this->request->post['payment_robokassa_status'] ?? $this->config->get('payment_robokassa_status');
        $data['payment_robokassa_status_iframe'] = $this->request->post['payment_robokassa_status_iframe'] ?? $this->config->get('payment_robokassa_status_iframe');
        $data['payment_robokassa_status_hold'] = $this->request->post['payment_robokassa_status_hold'] ?? $this->config->get('payment_robokassa_status_hold');
        $data['payment_robokassa_sort_order'] = $this->request->post['payment_robokassa_sort_order'] ?? $this->config->get('payment_robokassa_sort_order');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/robokassa/payment/robokassa', $data));
    }

    private function validate(): bool
    {
        if (!$this->user->hasPermission('modify', 'extension/robokassa/payment/robokassa')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (empty($this->request->post['payment_robokassa_login'])) {
            $this->error['merch_login'] = $this->language->get('error_merch_login');
        }

        if (empty($this->request->post['payment_robokassa_password_1'])) {
            $this->error['e_password1'] = $this->language->get('error_password1');
        }

        if (empty($this->request->post['payment_robokassa_password_2'])) {
            $this->error['e_password2'] = $this->language->get('error_password2');
        }

        return !$this->error;
    }

    private function getModuleVersion(): string
    {
        $version = 'unknown';
        $file = DIR_EXTENSION . 'robokassa/install.json';

        if (is_file($file)) {
            $json = file_get_contents($file);
            if ($json) {
                $data = json_decode($json, true);
                if (isset($data['version']) && $data['version']) {
                    $version = (string)$data['version'];
                }
            }
        }

        return $version;
    }

    private function sendPulseStatusChange(string $status): void
    {
        $apiUrl = 'https://pulse.robokassa.com/api/module-status';
        $apiKey = 'robokassa-plugin-stat-key-3953';

        $merchantId = (string)$this->config->get('payment_robokassa_login');
        if ($merchantId === '') {
            $merchantId = 'unknown';
        }

        $siteUrl = defined('HTTPS_CATALOG') ? HTTPS_CATALOG : HTTP_CATALOG;

        $payload = [
            'cms'         => 'opencart4',
            'merchant_id' => $merchantId,
            'site_id'     => $siteUrl,
            'status'      => $status,
            'reported_at' => date('Y-m-d H:i:s'),
            'version'     => $this->getModuleVersion(),
        ];

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-KEY: ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }

    private function saveSettingDirect(string $code, array $data, int $store_id = 0): void
    {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '" . (int)$store_id . "' AND `code` = '" . $this->db->escape($code) . "'");

        foreach ($data as $key => $value) {
            if (substr((string)$key, 0, strlen($code)) !== $code) {
                continue;
            }

            if (is_array($value)) {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '" . (int)$store_id . "', `code` = '" . $this->db->escape($code) . "', `key` = '" . $this->db->escape((string)$key) . "', `value` = '" . $this->db->escape(json_encode($value)) . "', `serialized` = '1'");
            } else {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '" . (int)$store_id . "', `code` = '" . $this->db->escape($code) . "', `key` = '" . $this->db->escape((string)$key) . "', `value` = '" . $this->db->escape((string)$value) . "', `serialized` = '0'");
            }

            $this->config->set((string)$key, $value);
        }
    }

    private function fetchInstallmentAliases(string $merchant_login)
    {
        $methods = $this->fetchInstallmentMethods($merchant_login);

        if ($methods === false) {
            return false;
        }

        return $methods['aliases'];
    }

    private function fetchInstallmentMethods(string $merchant_login)
    {
        $merchant_login = trim($merchant_login);

        if ($merchant_login === '') {
            return false;
        }

        $currency_url = 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx/GetCurrencies?MerchantLogin=' . rawurlencode($merchant_login) . '&Language=ru';
        $currency_xml = false;

        if (function_exists('curl_init')) {
            $ch = curl_init($currency_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            $currency_xml = curl_exec($ch);
            curl_close($ch);
        }

        if ($currency_xml === false && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 3
                ]
            ]);
            $currency_xml = @file_get_contents($currency_url, false, $context);
        }

        if ($currency_xml === false || strpos($currency_xml, '<Code>0</Code>') === false) {
            return false;
        }

        if (!preg_match_all('/\bAlias="([^"]+)"/i', $currency_xml, $aliases_match)) {
            return [
                'aliases' => [],
                'labels' => []
            ];
        }

        $aliases = [];
        $labels = [];

        if (preg_match_all('/<Currency\b([^>]*)>/i', $currency_xml, $currency_matches)) {
            foreach ($currency_matches[1] as $attributes) {
                if (!preg_match('/\bAlias="([^"]+)"/i', $attributes, $alias_match)) {
                    continue;
                }

                if (!preg_match('/\bLabel="([^"]+)"/i', $attributes, $label_match)) {
                    continue;
                }

                $alias = strtolower((string)$alias_match[1]);
                $label = (string)$label_match[1];

                if (!isset($labels[$alias])) {
                    $labels[$alias] = $label;
                }

                if ($alias === 'sbp' && strtoupper($label) === 'SBPPSR') {
                    $labels[$alias] = $label;
                }
            }
        }

        foreach ($aliases_match[1] as $alias) {
            $aliases[] = strtolower((string)$alias);
        }

        return [
            'aliases' => array_values(array_unique($aliases)),
            'labels' => $labels
        ];
    }

    public function install(): void
    {
        $this->load->model('user/user_group');
        $route = 'extension/robokassa/event/robokassa';
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', $route);
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', $route);

        $this->syncExtraPaymentControllers($this->getStoredInstallmentAliases(), $this->getStoredInstallmentLabels());
        $this->registerEvents();
        $this->sendPulseStatusChange('enabled');
    }

    public function uninstall(): void
    {
        $this->syncExtraPaymentControllers([]);
        $this->unregisterEvents();
        $this->sendPulseStatusChange('disabled');
    }

    private function syncExtraPaymentControllers(array $aliases, array $labels = []): void
    {
        $aliases = array_values(array_unique(array_map(static function ($alias): string {
            return strtolower((string)$alias);
        }, $aliases)));

        $labels = array_change_key_case($labels, CASE_LOWER);

        $alias_map = [
            'robokassa_credit'       => 'otp',
            'robokassa_mokka'        => 'mokka',
            'robokassa_podeli'       => 'podeli',
            'robokassa_sbp'          => 'sbp',
            'robokassa_yandex_split' => 'yandexpaysplit'
        ];

        $bnpl_aliases = ['otp', 'mokka', 'podeli', 'yandexpaysplit'];
        $enabled_codes = [];

        foreach ($alias_map as $code => $alias) {
            if ($code === 'robokassa_sbp') {
                if (in_array($alias, $aliases, true) && strtoupper((string)($labels[$alias] ?? '')) === 'SBPPSR') {
                    $enabled_codes[] = $code;
                }

                continue;
            }

            if (in_array($alias, $aliases, true)) {
                $enabled_codes[] = $code;
            }
        }

        if (array_intersect($bnpl_aliases, $aliases)) {
            $enabled_codes[] = 'robokassa_widget';
        }

        $managed_codes = array_merge(array_keys($alias_map), ['robokassa_widget']);
        $this->load->model('setting/extension');

        foreach ($managed_codes as $code) {
            $source = DIR_EXTENSION . 'robokassa/admin/controller/robokassa_payment/' . $code . '.php';
            $target = DIR_EXTENSION . 'robokassa/admin/controller/payment/' . $code . '.php';

            if (in_array($code, $enabled_codes, true)) {
                if (is_file($source) && (!is_file($target) || md5_file($source) !== md5_file($target))) {
                    @copy($source, $target);
                }

                $this->addExtraPaymentControllerPath($code);

                continue;
            }

            $this->model_setting_extension->uninstall('payment', $code);
            $this->deleteExtraPaymentControllerPath($code);

            if (is_file($target)) {
                @unlink($target);
            }
        }
    }

    private function addExtraPaymentControllerPath(string $code): void
    {
        $path = 'robokassa/admin/controller/payment/' . $code . '.php';

        $query = $this->db->query("SELECT `extension_path_id` FROM `" . DB_PREFIX . "extension_path` WHERE `path` = '" . $this->db->escape($path) . "' LIMIT 1");

        if ($query->num_rows) {
            return;
        }

        $extension_install_id = $this->getRobokassaExtensionInstallId();

        if ($extension_install_id <= 0) {
            return;
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "extension_path` SET `extension_install_id` = '" . (int)$extension_install_id . "', `path` = '" . $this->db->escape($path) . "'");
    }

    private function deleteExtraPaymentControllerPath(string $code): void
    {
        $path = 'robokassa/admin/controller/payment/' . $code . '.php';

        $this->db->query("DELETE FROM `" . DB_PREFIX . "extension_path` WHERE `path` = '" . $this->db->escape($path) . "'");
    }

    private function getRobokassaExtensionInstallId(): int
    {
        $query = $this->db->query("SELECT `extension_install_id` FROM `" . DB_PREFIX . "extension_path` WHERE `path` IN ('robokassa/install.json', 'robokassa/admin/controller/payment/robokassa.php') ORDER BY `extension_install_id` DESC LIMIT 1");

        if ($query->num_rows) {
            return (int)$query->row['extension_install_id'];
        }

        $query = $this->db->query("SELECT `extension_install_id` FROM `" . DB_PREFIX . "extension_install` WHERE `code` IN ('robokassa', 'Robokassa') ORDER BY `extension_install_id` DESC LIMIT 1");

        return $query->num_rows ? (int)$query->row['extension_install_id'] : 0;
    }

    private function getStoredInstallmentAliases(): array
    {
        $methods_initialized = (int)$this->config->get('payment_robokassa_methods_initialized') === 1;
        $current_login = trim((string)$this->config->get('payment_robokassa_login'));
        $methods_login = trim((string)$this->config->get('payment_robokassa_methods_login'));
        $aliases = $this->config->get('payment_robokassa_methods_aliases');

        if (!$methods_initialized || $current_login === '' || $methods_login !== $current_login || !is_array($aliases)) {
            return [];
        }

        return $aliases;
    }

    private function getStoredInstallmentLabels(): array
    {
        $methods_initialized = (int)$this->config->get('payment_robokassa_methods_initialized') === 1;
        $current_login = trim((string)$this->config->get('payment_robokassa_login'));
        $methods_login = trim((string)$this->config->get('payment_robokassa_methods_login'));
        $labels = $this->config->get('payment_robokassa_methods_labels');

        if (!$methods_initialized || $current_login === '' || $methods_login !== $current_login || !is_array($labels)) {
            return [];
        }

        return $labels;
    }

    private function registerEvents(): void
    {
        $this->load->model('setting/event');

        $codes = [
            'robokassa_hold_call_before_dot',
            'robokassa_hold_call_before_pipe',
            'robokassa_hold_addhistory_before_dot',
            'robokassa_hold_addhistory_before_pipe',
            'robokassa_product_widget_after_dot',
            'robokassa_product_widget_after_pipe',
            'robokassa_checkout_bootstrap_after_dot',
            'robokassa_checkout_bootstrap_after_pipe',
            'robokassa_payment_method_graph_after_dot',
            'robokassa_payment_method_graph_after_pipe',
            'robokassa_admin_payment_extension_after_dot',
            'robokassa_admin_payment_extension_after_pipe',
        ];

        foreach ($codes as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }

        $events = [
            [
                'code'        => 'robokassa_hold_call_before_dot',
                'description' => 'Robokassa hold cancel (sale/order.call before, dot)',
                'trigger'     => 'admin/controller/sale/order.call/before',
                'action'      => 'extension/robokassa/event/robokassa.onOrderCall',
                'status'      => 1,
                'sort_order'  => 0
            ],
            [
                'code'        => 'robokassa_hold_call_before_pipe',
                'description' => 'Robokassa hold cancel (sale/order|call before, pipe)',
                'trigger'     => 'admin/controller/sale/order|call/before',
                'action'      => 'extension/robokassa/event/robokassa|onOrderCall',
                'status'      => 1,
                'sort_order'  => 1
            ],
            [
                'code'        => 'robokassa_hold_addhistory_before_dot',
                'description' => 'Robokassa hold cancel (sale/order.addHistory before, dot)',
                'trigger'     => 'admin/model/sale/order.addHistory/before',
                'action'      => 'extension/robokassa/event/robokassa.onOrderAddHistory',
                'status'      => 1,
                'sort_order'  => 2
            ],
            [
                'code'        => 'robokassa_hold_addhistory_before_pipe',
                'description' => 'Robokassa hold cancel (sale/order|addHistory before, pipe)',
                'trigger'     => 'admin/model/sale/order|addHistory/before',
                'action'      => 'extension/robokassa/event/robokassa|onOrderAddHistory',
                'status'      => 1,
                'sort_order'  => 3
            ],
            [
                'code'        => 'robokassa_product_widget_after_dot',
                'description' => 'Robokassa product widget (product/product after, dot)',
                'trigger'     => 'catalog/view/product/product/after',
                'action'      => 'extension/robokassa/event/robokassa.onProductViewAfter',
                'status'      => 1,
                'sort_order'  => 4
            ],
            [
                'code'        => 'robokassa_product_widget_after_pipe',
                'description' => 'Robokassa product widget (product|product after, pipe)',
                'trigger'     => 'catalog/view/product/product/after',
                'action'      => 'extension/robokassa/event/robokassa|onProductViewAfter',
                'status'      => 1,
                'sort_order'  => 5
            ],
            [
                'code'        => 'robokassa_checkout_bootstrap_after_dot',
                'description' => 'Robokassa checkout render bootstrap (checkout/checkout after, dot)',
                'trigger'     => 'catalog/view/checkout/checkout/after',
                'action'      => 'extension/robokassa/event/robokassa.onCheckoutViewAfter',
                'status'      => 1,
                'sort_order'  => 6
            ],
            [
                'code'        => 'robokassa_checkout_bootstrap_after_pipe',
                'description' => 'Robokassa checkout render bootstrap (checkout|checkout after, pipe)',
                'trigger'     => 'catalog/view/checkout/checkout/after',
                'action'      => 'extension/robokassa/event/robokassa|onCheckoutViewAfter',
                'status'      => 1,
                'sort_order'  => 7
            ],
            [
                'code'        => 'robokassa_payment_method_graph_after_dot',
                'description' => 'Robokassa checkout payment graph (checkout/payment_method after, dot)',
                'trigger'     => 'catalog/view/checkout/payment_method/after',
                'action'      => 'extension/robokassa/event/robokassa.onPaymentMethodViewAfter',
                'status'      => 1,
                'sort_order'  => 8
            ],
            [
                'code'        => 'robokassa_payment_method_graph_after_pipe',
                'description' => 'Robokassa checkout payment graph (checkout|payment_method after, pipe)',
                'trigger'     => 'catalog/view/checkout/payment_method/after',
                'action'      => 'extension/robokassa/event/robokassa|onPaymentMethodViewAfter',
                'status'      => 1,
                'sort_order'  => 9
            ],
            [
                'code'        => 'robokassa_admin_payment_extension_after_dot',
                'description' => 'Robokassa admin payment extension list filter (dot)',
                'trigger'     => 'admin/view/extension/payment/after',
                'action'      => 'extension/robokassa/event/robokassa.onPaymentExtensionViewAfter',
                'status'      => 1,
                'sort_order'  => 10
            ],
            [
                'code'        => 'robokassa_admin_payment_extension_after_pipe',
                'description' => 'Robokassa admin payment extension list filter (pipe)',
                'trigger'     => 'admin/view/extension/payment/after',
                'action'      => 'extension/robokassa/event/robokassa|onPaymentExtensionViewAfter',
                'status'      => 1,
                'sort_order'  => 11
            ],
        ];

        foreach ($events as $event) {
            $this->model_setting_event->addEvent($event);
        }
    }

    private function unregisterEvents(): void
    {
        $this->load->model('setting/event');

        $codes = [
            'robokassa_hold_call_before_dot',
            'robokassa_hold_call_before_pipe',
            'robokassa_hold_addhistory_before_dot',
            'robokassa_hold_addhistory_before_pipe',
            'robokassa_product_widget_after_dot',
            'robokassa_product_widget_after_pipe',
            'robokassa_checkout_bootstrap_after_dot',
            'robokassa_checkout_bootstrap_after_pipe',
            'robokassa_payment_method_graph_after_dot',
            'robokassa_payment_method_graph_after_pipe',
            'robokassa_admin_payment_extension_after_dot',
            'robokassa_admin_payment_extension_after_pipe',
        ];

        foreach ($codes as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }
    }
}

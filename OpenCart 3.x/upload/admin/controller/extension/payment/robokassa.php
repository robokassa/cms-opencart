<?php

class ControllerExtensionPaymentRobokassa extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/payment/robokassa');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
        $this->load->model('localisation/language');
        $this->load->model('extension/payment/robokassa');
        $this->model_extension_payment_robokassa->installMarkingTables();
        $this->installPaymentExtensions();
        $this->registerMarkingEvents();

        $refund_cron_token = (string)$this->config->get('payment_robokassa_refund_cron_token');

        if ($refund_cron_token === '') {
            $refund_cron_token = $this->generateRefundCronToken();
            $this->saveRefundCronToken($refund_cron_token);
        }

        $this->document->addStyle('view/stylesheet/robokassa/settings.css');
        $this->document->addScript('view/javascript/robokassa/settings.js');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $is_update_request = isset($this->request->post['robokassa_action']) && $this->request->post['robokassa_action'] === 'update_methods';
            $methods_initialized = (int)$this->config->get('payment_robokassa_methods_initialized') === 1;
            $is_first_sync = !$methods_initialized;

            $this->saveUnifiedSettings($this->request->post);

            if ($is_update_request || $is_first_sync) {
                $merchant_login = trim((string)$this->request->post['payment_robokassa_login']);
                $aliases = $this->fetchInstallmentAliases($merchant_login);

                if ($aliases === false) {
                    $this->session->data['error_warning'] = 'Не удалось обновить способы оплаты. Проверьте логин магазина и доступ к WebService.';
                    $this->response->redirect($this->url->link('extension/payment/robokassa', 'user_token=' . $this->session->data['user_token'], true));

                    return;
                }

                $this->model_setting_setting->editSetting('payment_robokassa_methods', array(
                    'payment_robokassa_methods_login' => $merchant_login,
                    'payment_robokassa_methods_initialized' => 1,
                    'payment_robokassa_methods_aliases' => $aliases
                ));
            }

            if ($is_update_request) {
                $this->session->data['success'] = 'Список способов оплаты обновлен.';
                $this->response->redirect($this->url->link('extension/payment/robokassa', 'user_token=' . $this->session->data['user_token'], true));

                return;
            }

            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        if (isset($this->session->data['error_warning'])) {
            $data['error_warning'] = $this->session->data['error_warning'];
            unset($this->session->data['error_warning']);
        } elseif (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        if (isset($this->error['merch_login'])) {
            $data['error_merch_login'] = $this->error['merch_login'];
        } else {
            $data['error_merch_login'] = '';
        }

        if (isset($this->error['e_password1'])) {
            $data['error_password1'] = $this->error['e_password1'];
        } else {
            $data['error_password1'] = '';
        }

        if (isset($this->error['e_password2'])) {
            $data['error_password2'] = $this->error['e_password2'];
        } else {
            $data['error_password2'] = '';
        }

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_payment'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/robokassa', 'user_token=' . $this->session->data['user_token'], true)
        );

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

        $data['entry_login'] = $this->language->get('entry_login');
        $data['entry_password1'] = $this->language->get('entry_password1');
        $data['entry_password2'] = $this->language->get('entry_password2');
        $data['entry_password3'] = $this->language->get('entry_password3');
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
        $data['button_update_methods'] = 'Обновить способы оплаты';
        $data['entry_country'] = $this->language->get('entry_country');
        $data['entry_iframe'] = $this->language->get('entry_iframe');
		$data['entry_product_options'] = $this->language->get('entry_product_options');
        $data['entry_marking'] = $this->language->get('entry_marking');
        $entry_widget_status = $this->language->get('entry_widget_status');
        $data['entry_widget_status'] = ($entry_widget_status && $entry_widget_status !== 'entry_widget_status')
            ? $entry_widget_status
            : 'Показывать виджет BNPL в карточке товара';

        $data['help_fiscal'] = $this->language->get('help_fiscal') . ' Для корректной работы способов оплаты через рассрочку и виджетов в карточке товара этот параметр должен быть включен.';
        $data['help_iframe'] = $this->language->get('help_iframe');
        $data['help_marking'] = $this->language->get('help_marking');
        $data['help_password3'] = $this->language->get('help_password3');
        $help_widget_status = $this->language->get('help_widget_status');
        $data['help_widget_status'] = ($help_widget_status && $help_widget_status !== 'help_widget_status')
            ? $help_widget_status
            : 'Единая настройка для отображения robokassa-widget в карточке товара.';

        $data['action'] = $this->url->link('extension/payment/robokassa', 'user_token=' . $this->session->data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        $data['opencart_languages'] = $this->model_localisation_language->getLanguages();

        if (isset($this->request->post['payment_robokassa_login'])) {
            $data['payment_robokassa_login'] = $this->request->post['payment_robokassa_login'];
        } else {
            $data['payment_robokassa_login'] = $this->config->get('payment_robokassa_login');
        }

        if (isset($this->request->post['payment_robokassa_password_1'])) {
            $data['payment_robokassa_password_1'] = $this->request->post['payment_robokassa_password_1'];
        } else {
            $data['payment_robokassa_password_1'] = $this->config->get('payment_robokassa_password_1');
        }

        if (isset($this->request->post['payment_robokassa_password_2'])) {
            $data['payment_robokassa_password_2'] = $this->request->post['payment_robokassa_password_2'];
        } else {
            $data['payment_robokassa_password_2'] = $this->config->get('payment_robokassa_password_2');
        }

        if (isset($this->request->post['payment_robokassa_password_3'])) {
            $data['payment_robokassa_password_3'] = $this->request->post['payment_robokassa_password_3'];
        } else {
            $data['payment_robokassa_password_3'] = $this->config->get('payment_robokassa_password_3');
        }

        if (isset($this->request->post['payment_robokassa_test_password_1'])) {
            $data['payment_robokassa_test_password_1'] = $this->request->post['payment_robokassa_test_password_1'];
        } else {
            $data['payment_robokassa_test_password_1'] = $this->config->get('payment_robokassa_test_password_1');
        }

        if (isset($this->request->post['payment_robokassa_test_password_2'])) {
            $data['payment_robokassa_test_password_2'] = $this->request->post['payment_robokassa_test_password_2'];
        } else {
            $data['payment_robokassa_test_password_2'] = $this->config->get('payment_robokassa_test_password_2');
        }

        $current_login_for_sync = trim((string)$data['payment_robokassa_login']);
        $current_password1_for_sync = trim((string)$data['payment_robokassa_password_1']);
        $current_password2_for_sync = trim((string)$data['payment_robokassa_password_2']);
        $data['show_update_methods'] = (int)$this->config->get('payment_robokassa_methods_initialized') === 1
            && $current_login_for_sync !== ''
            && $current_password1_for_sync !== ''
            && $current_password2_for_sync !== '';

        if (!empty($_SERVER['HTTPS']) && 'off' !== strtolower($_SERVER['HTTPS'])) {
            $data['payment_robokassa_result_url'] = 'https://' . $_SERVER['SERVER_NAME'] . '/index.php?route=extension/payment/robokassa/result';
            $data['payment_robokassa_success_url'] = 'https://' . $_SERVER['SERVER_NAME'] . '/index.php?route=extension/payment/robokassa/success';
            $data['payment_robokassa_fail_url'] = 'https://' . $_SERVER['SERVER_NAME'] . '/index.php?route=extension/payment/robokassa/fail';
        } else {
            $data['payment_robokassa_result_url'] = HTTP_CATALOG . 'index.php?route=extension/payment/robokassa/result';
            $data['payment_robokassa_success_url'] = HTTP_CATALOG . 'index.php?route=extension/payment/robokassa/success';
            $data['payment_robokassa_fail_url'] = HTTP_CATALOG . 'index.php?route=extension/payment/robokassa/fail';
        }

        $data['payment_robokassa_refund_cron_url'] = $this->maskRefundCronUrl($this->getRefundCronUrl($refund_cron_token));
        $data['payment_robokassa_refund_cron_copy_url'] = $this->url->link('extension/payment/robokassa/copyRefundCronUrl', 'user_token=' . $this->session->data['user_token'], true);
        $data['payment_robokassa_refund_cron_regenerate_url'] = $this->url->link('extension/payment/robokassa/regenerateRefundCronToken', 'user_token=' . $this->session->data['user_token'], true);

        if (isset($this->request->post['payment_robokassa_test'])) {
            $data['payment_robokassa_test'] = $this->request->post['payment_robokassa_test'];
        } else {
            $data['payment_robokassa_test'] = $this->config->get('payment_robokassa_test');
        }

        if (isset($this->request->post['payment_robokassa_country'])) {
            $data['payment_robokassa_country'] = $this->request->post['payment_robokassa_country'];
        } elseif ($this->config->get('payment_robokassa_country')) {
            $data['payment_robokassa_country'] = $this->config->get('payment_robokassa_country');
        } else {
            $data['payment_robokassa_country'] = "RUB";
        }

        if (isset($this->request->post['payment_robokassa_languages_map'])) {
            $data['payment_robokassa_languages_map'] = $this->request->post['payment_robokassa_languages_map'];
        } else {
            $data['payment_robokassa_languages_map'] = $this->config->get('payment_robokassa_languages_map');
        }

        $data['robokassa_available_languages'] = array(
            'en',
            'ru'
        );

        $data['robokassa_tax_type_list'] = array(
            'osn' => 'общая СН',
            'usn_income' => 'упрощенная СН (доходы)',
            'usn_income_outcome' => 'упрощенная СН (доходы минус расходы)',
            'envd' => 'единый налог на вмененный доход',
            'esn' => 'единый сельскохозяйственный налог',
            'patent' => 'патентная СН',
        );

        $data['robokassa_tax_list'] = array(
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
        );

        $data['robokassa_tax_list_kz'] = array(
            'none' => 'без НДС',
            'vat0' => 'НДС чека по ставке 8%',
            'vat12' => 'НДС чека по ставке 12%',
        );

        $data['robokassa_payment_method_list'] = array(
            'full_prepayment' => 'предоплата 100% (по умолчанию)',
            'prepayment' => 'предоплата',
            'advance' => 'аванс',
            'full_payment' => 'полный расчет',
            'partial_payment' => 'частичный расчет и кредит',
            'credit' => 'передача в кредит',
            'credit_payment' => 'оплата кредита',
        );

        $data['robokassa_payment_object_list'] = array(
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
            'tovar_mark' => 'маркируемый товар с кодом маркировки',
        );

        if (isset($this->request->post['payment_robokassa_tax_type'])) {
            $data['payment_robokassa_tax_type'] = $this->request->post['payment_robokassa_tax_type'];
        } else {
            $data['payment_robokassa_tax_type'] = $this->config->get('payment_robokassa_tax_type');
        }

        if (isset($this->request->post['payment_robokassa_tax'])) {
            $data['payment_robokassa_tax'] = $this->request->post['payment_robokassa_tax'];
        } else {
            $data['payment_robokassa_tax'] = $this->config->get('payment_robokassa_tax');
        }

        if (isset($this->request->post['payment_robokassa_fiscal'])) {
            $data['payment_robokassa_fiscal'] = $this->request->post['payment_robokassa_fiscal'];
        } else {
            $data['payment_robokassa_fiscal'] = $this->config->get('payment_robokassa_fiscal');
        }

        if (isset($this->request->post['payment_robokassa_marking'])) {
            $data['payment_robokassa_marking'] = $this->request->post['payment_robokassa_marking'];
        } else {
            $data['payment_robokassa_marking'] = $this->config->get('payment_robokassa_marking');
        }
		if (isset($this->request->post['payment_robokassa_send_product_options'])) {
			$data['payment_robokassa_send_product_options'] = $this->request->post['payment_robokassa_send_product_options'];
		} else {
			$data['payment_robokassa_send_product_options'] = $this->config->get('payment_robokassa_send_product_options');
		}

		if (isset($this->request->post['payment_robokassa_payment_method'])) {
            $data['payment_robokassa_payment_method'] = $this->request->post['payment_robokassa_payment_method'];
        } else {
            $data['payment_robokassa_payment_method'] = $this->config->get('payment_robokassa_payment_method');
        }

        if (isset($this->request->post['payment_robokassa_payment_object'])) {
            $data['payment_robokassa_payment_object'] = $this->request->post['payment_robokassa_payment_object'];
        } else {
            $data['payment_robokassa_payment_object'] = $this->config->get('payment_robokassa_payment_object');
        }

        if (isset($this->request->post['payment_robokassa_order_status_id'])) {
            $data['payment_robokassa_order_status_id'] = $this->request->post['payment_robokassa_order_status_id'];
        } else {
            $data['payment_robokassa_order_status_id'] = $this->config->get('payment_robokassa_order_status_id');
        }

        if (isset($this->request->post['payment_robokassa_order_status_id_2check'])) {
            $data['payment_robokassa_order_status_id_2check'] = $this->request->post['payment_robokassa_order_status_id_2check'];
        } else {
            $data['payment_robokassa_order_status_id_2check'] = $this->config->get('payment_robokassa_order_status_id_2check');
        }

        $this->load->model('localisation/order_status');

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['payment_robokassa_refund_status_id'] = (int)$this->getSettingValue('payment_robokassa_refund_status_id', 11);

        if (isset($this->request->post['payment_robokassa_geo_zone_id'])) {
            $data['payment_robokassa_geo_zone_id'] = $this->request->post['payment_robokassa_geo_zone_id'];
        } else {
            $data['payment_robokassa_geo_zone_id'] = $this->config->get('payment_robokassa_geo_zone_id');
        }

        $this->load->model('localisation/geo_zone');

        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();


        if (isset($this->request->post['payment_robokassa_status'])) {
            $data['payment_robokassa_status'] = $this->request->post['payment_robokassa_status'];
        } else {
            $data['payment_robokassa_status'] = $this->config->get('payment_robokassa_status');
        }

        if (isset($this->request->post['payment_robokassa_widget_status'])) {
            $data['payment_robokassa_widget_status'] = $this->request->post['payment_robokassa_widget_status'];
        } else {
            $data['payment_robokassa_widget_status'] = $this->config->get('payment_robokassa_widget_status');
        }

        $widget_defaults = array(
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
        );

        foreach ($widget_defaults as $key => $default) {
            $data[$key] = $this->getSettingValue($key, $default);
        }

        $available_aliases = $this->config->get('payment_robokassa_methods_aliases');
        $aliases_known = is_array($available_aliases)
            && (int)$this->config->get('payment_robokassa_methods_initialized') === 1
            && trim((string)$this->config->get('payment_robokassa_methods_login')) === trim((string)$this->config->get('payment_robokassa_login'));

        if (!$aliases_known) {
            $available_aliases = array();
        }

        $available_aliases = array_map('strtolower', $available_aliases);
        $method_definitions = array(
            array('code' => 'credit', 'alias' => 'otp', 'title' => 'Кредит и рассрочка', 'description' => 'Оплата заказа в кредит или рассрочку через OTP.'),
            array('code' => 'mokka', 'alias' => 'mokka', 'title' => 'Mokka', 'description' => 'Покупка сейчас с оплатой частями через Mokka.'),
            array('code' => 'podeli', 'alias' => 'podeli', 'title' => 'Подели', 'description' => 'Разделение стоимости заказа на несколько платежей.'),
            array('code' => 'sbp', 'alias' => 'sbp', 'title' => 'СБП', 'description' => 'Быстрая оплата по QR-коду через Систему быстрых платежей.'),
            array('code' => 'yandex_split', 'alias' => 'yandexpaysplit', 'title' => 'Яндекс Сплит', 'description' => 'Оплата заказа частями с помощью Яндекс Сплит.')
        );

        $data['robokassa_payment_methods'] = array();

        foreach ($method_definitions as $method) {
            $setting_key = 'payment_robokassa_' . $method['code'] . '_status';
            $method['setting_key'] = $setting_key;
            $method['enabled'] = (bool)$this->getSettingValue($setting_key, 0);
            $method['availability_known'] = $aliases_known;
            $method['available'] = !$aliases_known || in_array($method['alias'], $available_aliases, true);
            $data['robokassa_payment_methods'][] = $method;
        }

        if (isset($this->request->post['payment_robokassa_status_iframe'])) {
            $data['payment_robokassa_status_iframe'] = $this->request->post['payment_robokassa_status_iframe'];
        } else {
            $data['payment_robokassa_status_iframe'] = $this->config->get('payment_robokassa_status_iframe');
        }

        if (isset($this->request->post['payment_robokassa_status_hold'])) {
            $data['payment_robokassa_status_hold'] = $this->request->post['payment_robokassa_status_hold'];
        } else {
            $data['payment_robokassa_status_hold'] = $this->config->get('payment_robokassa_status_hold');
        }

        //подели
        if (isset($this->request->post['payment_robokassa_status_podeli'])) {
            $data['payment_robokassa_status_podeli'] = $this->request->post['payment_robokassa_status_podeli'];
        } else {
            $data['payment_robokassa_status_podeli'] = $this->config->get('payment_robokassa_status_podeli');
        }

        if (isset($this->request->post['payment_robokassa_sort_order'])) {
            $data['payment_robokassa_sort_order'] = $this->request->post['payment_robokassa_sort_order'];
        } else {
            $data['payment_robokassa_sort_order'] = $this->config->get('payment_robokassa_sort_order');
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/robokassa', $data));
    }

    private function getSettingValue($key, $default = '')
    {
        if (isset($this->request->post[$key])) {
            return $this->request->post[$key];
        }

        $value = $this->config->get($key);

        return ($value === null || $value === '') ? $default : $value;
    }

    private function saveUnifiedSettings(array $settings)
    {
        $child_codes = array(
            'payment_robokassa_credit',
            'payment_robokassa_mokka',
            'payment_robokassa_podeli',
            'payment_robokassa_sbp',
            'payment_robokassa_yandex_split',
            'payment_robokassa_widget'
        );
        $grouped_settings = array();

        foreach ($child_codes as $code) {
            $grouped_settings[$code] = array();
        }

        $main_settings = array();

        foreach ($settings as $key => $value) {
            if (strpos($key, 'payment_robokassa_') !== 0) {
                continue;
            }

            $child_setting = false;

            foreach ($child_codes as $code) {
                if (strpos($key, $code . '_') === 0) {
                    $grouped_settings[$code][$key] = $value;
                    $child_setting = true;
                    break;
                }
            }

            if (!$child_setting) {
                $main_settings[$key] = $value;
            }
        }

        $main_settings['payment_robokassa_refund_cron_token'] = (string)$this->config->get('payment_robokassa_refund_cron_token');

        $this->model_setting_setting->editSetting('payment_robokassa', $main_settings);

        foreach ($grouped_settings as $code => $values) {
            $existing_values = $this->model_setting_setting->getSetting($code);
            $this->model_setting_setting->editSetting($code, array_merge($existing_values, $values));
        }
    }

    private function generateRefundCronToken()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(24));
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes(24));
        }

        return hash('sha256', uniqid((string)mt_rand(), true));
    }

    private function saveRefundCronToken($token)
    {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `key` = 'payment_robokassa_refund_cron_token'");
        $this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'payment_robokassa', `key` = 'payment_robokassa_refund_cron_token', `value` = '" . $this->db->escape($token) . "', `serialized` = '0'");
        $this->config->set('payment_robokassa_refund_cron_token', $token);
    }

    private function getRefundCronUrl($token)
    {
        return HTTP_CATALOG . 'index.php?route=extension/payment/robokassa/refundCron&token=' . rawurlencode($token);
    }

    private function maskRefundCronUrl($url)
    {
        return preg_replace('/([?&]token=)[^&]*/', '$1••••••••••••', $url);
    }

    public function copyRefundCronUrl()
    {
        $this->load->language('extension/payment/robokassa');
        $json = array();

        if (!$this->user->hasPermission('access', 'extension/payment/robokassa')) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $json['error'] = 'Недостаточно прав для просмотра cron URL.';
        } else {
            $token = (string)$this->config->get('payment_robokassa_refund_cron_token');

            if ($token === '') {
                $token = $this->generateRefundCronToken();
                $this->saveRefundCronToken($token);
            }

            $json['success'] = true;
            $json['url'] = $this->getRefundCronUrl($token);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->addHeader('Cache-Control: no-store, no-cache, must-revalidate');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function regenerateRefundCronToken()
    {
        $json = array();

        if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
            $this->response->addHeader('HTTP/1.1 405 Method Not Allowed');
            $json['error'] = 'Метод не поддерживается.';
        } elseif (!$this->user->hasPermission('modify', 'extension/payment/robokassa')) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $json['error'] = 'Недостаточно прав для обновления cron-токена.';
        } else {
            $token = $this->generateRefundCronToken();
            $this->saveRefundCronToken($token);
            $url = $this->getRefundCronUrl($token);
            $json['success'] = true;
            $json['url'] = $url;
            $json['display'] = $this->maskRefundCronUrl($url);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->addHeader('Cache-Control: no-store, no-cache, must-revalidate');
        $this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function installPaymentExtensions()
    {
        $this->load->model('setting/extension');
        $installed_codes = $this->model_setting_extension->getInstalled('payment');

        $robokassa_extensions = array(
            'robokassa_credit',
            'robokassa_mokka',
            'robokassa_podeli',
            'robokassa_sbp',
            'robokassa_widget',
            'robokassa_yandex_split'
        );

        foreach ($robokassa_extensions as $code) {
            if (!in_array($code, $installed_codes, true)) {
                $this->model_setting_extension->install('payment', $code);
            }
        }
    }

    private function registerMarkingEvents()
    {
        $this->load->model('setting/event');

        $events = array(
            'robokassa_admin_menu' => array('admin/view/common/column_left/before', 'extension/payment/robokassa/adminMenu'),
            'robokassa_marking_product_form' => array('admin/view/catalog/product_form/after', 'extension/payment/robokassa/productForm'),
            'robokassa_marking_product_edit' => array('admin/model/catalog/product/editProduct/after', 'extension/payment/robokassa/saveProductMarking'),
            'robokassa_marking_product_add' => array('admin/model/catalog/product/addProduct/after', 'extension/payment/robokassa/saveProductMarking')
        );

        foreach ($events as $code => $event) {
            $this->model_setting_event->deleteEventByCode($code);
            $this->model_setting_event->addEvent($code, $event[0], $event[1]);
        }
    }

    public function adminMenu(&$route, &$data)
    {
        if (!$this->user->hasPermission('access', 'extension/payment/robokassa') || empty($data['menus']) || !is_array($data['menus'])) {
            return;
        }

        foreach ($data['menus'] as $menu) {
            if (isset($menu['id']) && $menu['id'] === 'menu-robokassa') {
                return;
            }
        }

        $robokassa_menu = array(
            'id' => 'menu-robokassa',
            'icon' => 'fa-credit-card',
            'name' => 'Robokassa',
            'href' => $this->url->link('extension/payment/robokassa', 'user_token=' . $this->session->data['user_token'], true),
            'children' => array()
        );

        array_splice($data['menus'], 1, 0, array($robokassa_menu));
    }

    public function productForm(&$route, &$data, &$output)
    {
        if (!$this->config->get('payment_robokassa_marking') || $this->config->get('payment_robokassa_country') !== 'RUB') {
            return;
        }

        $this->load->language('extension/payment/robokassa');
        $this->load->model('extension/payment/robokassa');
        $this->model_extension_payment_robokassa->installMarkingTables();

        $product_id = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
        $view_data = array(
            'entry_marking_required' => $this->language->get('entry_marking_required'),
            'help_marking_required' => $this->language->get('help_marking_required'),
            'text_yes' => $this->language->get('text_yes'),
            'text_no' => $this->language->get('text_no'),
            'robokassa_marking_required' => $product_id ? $this->model_extension_payment_robokassa->isProductMarkingRequired($product_id) : false
        );

        $tab_link = '<li><a href="#tab-robokassa-marking" data-toggle="tab">' . $this->language->get('tab_robokassa_marking') . '</a></li>';
        $tab_html = $this->load->view('extension/payment/robokassa_product_marking', $view_data);
        $design_tab_link = '<li><a href="#tab-design" data-toggle="tab">' . $data['tab_design'] . '</a></li>';

        $output = str_replace($design_tab_link, $tab_link . "\n" . $design_tab_link, $output);
        $output = str_replace('<div class="tab-pane" id="tab-design">', $tab_html . "\n" . '<div class="tab-pane" id="tab-design">', $output);
    }

    public function saveProductMarking(&$route, &$args, &$output)
    {
        $product_id = 0;

        if ($route === 'catalog/product/editProduct' && !empty($args[0])) {
            $product_id = (int)$args[0];
        } elseif ($route === 'catalog/product/addProduct') {
            $product_id = (int)$output;
        }

        if (!$product_id || !isset($this->request->post['robokassa_marking_required'])) {
            return;
        }

        $this->load->model('extension/payment/robokassa');
        $this->model_extension_payment_robokassa->installMarkingTables();
        $this->model_extension_payment_robokassa->saveProductMarkingRequired($product_id, (bool)$this->request->post['robokassa_marking_required']);
    }

    public function getOrderProductMarking()
    {
        $this->load->language('extension/payment/robokassa');
        $this->load->model('extension/payment/robokassa');
        $this->model_extension_payment_robokassa->installMarkingTables();
        $json = array('success' => false);

        if (!$this->config->get('payment_robokassa_marking') || $this->config->get('payment_robokassa_country') !== 'RUB') {
            $json['error'] = $this->language->get('error_marking_product');
        } elseif (!$this->user->hasPermission('access', 'sale/order')) {
            $json['error'] = $this->language->get('error_marking_permission');
        } else {
            $order_product_id = isset($this->request->post['order_product_id']) ? (int)$this->request->post['order_product_id'] : 0;
            $product = $this->model_extension_payment_robokassa->getOrderProduct($order_product_id);

            if (!$product || !$product['marking_required']) {
                $json['error'] = $this->language->get('error_marking_product');
            } else {
                $json['success'] = true;
                $json['product'] = array(
                    'name' => $product['name'],
                    'quantity' => (int)$product['quantity'],
                    'codes' => $this->model_extension_payment_robokassa->getOrderProductCodes($order_product_id)
                );
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function saveOrderProductMarking()
    {
        $this->load->language('extension/payment/robokassa');
        $this->load->model('extension/payment/robokassa');
        $this->model_extension_payment_robokassa->installMarkingTables();
        $json = array('success' => false);

        if (!$this->config->get('payment_robokassa_marking') || $this->config->get('payment_robokassa_country') !== 'RUB') {
            $json['error'] = $this->language->get('error_marking_product');
        } elseif (!$this->user->hasPermission('modify', 'sale/order')) {
            $json['error'] = $this->language->get('error_marking_permission');
        } else {
            $order_product_id = isset($this->request->post['order_product_id']) ? (int)$this->request->post['order_product_id'] : 0;
            $product = $this->model_extension_payment_robokassa->getOrderProduct($order_product_id);
            $input_codes = isset($this->request->post['codes']) && is_array($this->request->post['codes']) ? $this->request->post['codes'] : array();

            if (!$product || !$product['marking_required']) {
                $json['error'] = $this->language->get('error_marking_product');
            } else {
                $codes = array();
                $seen = array();

                for ($unit_index = 1; $unit_index <= (int)$product['quantity']; $unit_index++) {
                    $code = isset($input_codes[$unit_index]) ? (string)$input_codes[$unit_index] : '';

                    if ($code === '') {
                        $codes[$unit_index] = '';
                        continue;
                    }

                    if (strlen($code) > 255 || preg_match('/[^\x1D\x20-\x7E]/', $code) || trim(str_replace(chr(29), '', $code)) === '') {
                        $json['error'] = sprintf($this->language->get('error_marking_format'), $unit_index);
                        break;
                    }

                    $fingerprint = hash('sha256', $code);
                    if (isset($seen[$fingerprint])) {
                        $json['error'] = $this->language->get('error_marking_duplicate');
                        break;
                    }

                    if ($this->model_extension_payment_robokassa->isCodeUsedInOrder($order_product_id, $code)) {
                        $json['error'] = $this->language->get('error_marking_duplicate');
                        break;
                    }

                    $seen[$fingerprint] = true;
                    $codes[$unit_index] = $code;
                }

                if (empty($json['error'])) {
                    $this->model_extension_payment_robokassa->saveOrderProductCodes($order_product_id, $codes);
                    $json['success'] = true;
                    $json['status'] = $this->model_extension_payment_robokassa->getMarkingStatus($order_product_id, $product['quantity']);
                    $json['message'] = $this->language->get('text_marking_saved');
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function refund()
    {
        $this->load->language('extension/payment/robokassa');

        if (!$this->user->hasPermission('access', 'sale/order')) {
            $this->session->data['error_warning'] = $this->language->get('error_refund_permission');
            $this->response->redirect($this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true));
            return;
        }

        $this->load->model('sale/order');
        $this->load->model('extension/payment/robokassa');
        $this->model_extension_payment_robokassa->installMarkingTables();

        $order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
        $order_info = $this->model_sale_order->getOrder($order_id);

        if (!$order_info) {
            $this->session->data['error_warning'] = $this->language->get('error_refund_order');
            $this->response->redirect($this->url->link('sale/order', 'user_token=' . $this->session->data['user_token'], true));
            return;
        }

        $eligibility_error = $this->getRefundEligibilityError($order_info, false);

        if ($this->request->server['REQUEST_METHOD'] === 'POST') {
            $error = $this->getRefundEligibilityError($order_info, true);

            if ($error === '') {
                $error = $this->submitRefund($order_info);
            }

            if ($error !== '') {
                $this->session->data['error_warning'] = $error;
            }

            $this->response->redirect($this->url->link('extension/payment/robokassa/refund', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id, true));
            return;
        }

        $this->document->setTitle(sprintf($this->language->get('heading_refund'), $order_id));
        $this->document->addStyle('view/stylesheet/robokassa/settings.css');
        $this->document->addStyle('view/stylesheet/robokassa/refund.css');
        $this->document->addScript('view/javascript/robokassa/refund.js');
        $data['heading_title'] = sprintf($this->language->get('heading_refund'), $order_id);
        $data['breadcrumbs'] = array(
            array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
            array('text' => $this->language->get('text_order'), 'href' => $this->url->link('sale/order', 'user_token=' . $this->session->data['user_token'], true)),
            array('text' => $data['heading_title'], 'href' => $this->url->link('extension/payment/robokassa/refund', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id, true))
        );
        $data['action'] = $this->url->link('extension/payment/robokassa/refund', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id, true);
        $data['back'] = $this->url->link('sale/order/info', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id, true);
        $data['check_url'] = str_replace('&amp;', '&', $this->url->link('extension/payment/robokassa/checkRefund', 'user_token=' . $this->session->data['user_token'], true));
        $data['order_id'] = $order_id;
        $data['order_info'] = $order_info;
        $data['currency_code'] = $order_info['currency_code'];
        $data['order_total'] = round((float)$order_info['total'], 2);
        $data['reserved_total'] = round($this->model_extension_payment_robokassa->getReservedRefundTotal($order_id), 2);
        $data['remaining_total'] = max(0, round($data['order_total'] - $data['reserved_total'], 2));
        $data['formatted_order_total'] = $this->currency->format($data['order_total'], $order_info['currency_code'], $order_info['currency_value']);
        $data['formatted_remaining_total'] = $this->currency->format($data['remaining_total'], $order_info['currency_code'], $order_info['currency_value']);
        $data['refund_available'] = $eligibility_error === '' && $data['remaining_total'] > 0;
        $data['eligibility_error'] = $eligibility_error;
        $data['fiscal_enabled'] = (bool)$this->config->get('payment_robokassa_fiscal');
        $refundable = $this->model_extension_payment_robokassa->getRefundableData($order_id);
        $data['products'] = $refundable['products'];
        $data['shipping'] = $refundable['shipping'];
        $data['refunds'] = $this->model_extension_payment_robokassa->getRefunds($order_id);
        $data['status_labels'] = array(
            'submitting' => $this->language->get('text_refund_submitting'),
            'unknown' => $this->language->get('text_refund_unknown'),
            'processing' => $this->language->get('text_refund_processing'),
            'finished' => $this->language->get('text_refund_finished'),
            'canceled' => $this->language->get('text_refund_canceled'),
            'failed' => $this->language->get('text_refund_failed')
        );
        $data['error_warning'] = isset($this->session->data['error_warning']) ? $this->session->data['error_warning'] : '';
        $data['success'] = isset($this->session->data['success']) ? $this->session->data['success'] : '';
        unset($this->session->data['error_warning'], $this->session->data['success']);
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/robokassa_refund', $data));
    }

    public function checkRefund()
    {
        $this->load->language('extension/payment/robokassa');
        $this->load->model('extension/payment/robokassa');
        $this->model_extension_payment_robokassa->installMarkingTables();
        $json = array('success' => false);

        if (!$this->user->hasPermission('modify', 'sale/order')) {
            $json['error'] = $this->language->get('error_refund_permission');
        } else {
            $refund_id = isset($this->request->post['refund_id']) ? (int)$this->request->post['refund_id'] : 0;
            $refund = $this->model_extension_payment_robokassa->getRefund($refund_id);

            if (!$refund || !$refund['request_id']) {
                $json['error'] = $this->language->get('error_refund_not_found');
            } elseif (in_array($refund['status'], array('finished', 'canceled'), true)) {
                $json = array('success' => true, 'status' => $refund['status']);
            } else {
                $api = $this->getRefundApi();
                $result = $api->getState($refund['request_id']);

                if (!$result['success']) {
                    $this->model_extension_payment_robokassa->updateRefundState($refund_id, $refund['status'] === 'unknown' ? 'unknown' : 'processing', $result['raw'], $result['error']);
                    $json['error'] = $result['error'];
                } else {
                    $status = isset($result['data']['label']) && is_scalar($result['data']['label']) ? (string)$result['data']['label'] : '';

                    if (!in_array($status, array('processing', 'finished', 'canceled'), true)) {
                        $json['error'] = $this->language->get('error_refund_state');
                    } else {
                        $state_updated = $this->model_extension_payment_robokassa->updateRefundState($refund_id, $status, $result['raw']);

                        if ($state_updated && $status !== $refund['status'] && $status === 'finished') {
                            $this->model_extension_payment_robokassa->recordFinishedRefund(
                                $refund['order_id'],
                                sprintf($this->language->get('note_refund_finished'), $refund['amount'], $refund['request_id'])
                            );
                        } elseif ($state_updated && $status !== $refund['status'] && $status === 'canceled') {
                            $this->model_extension_payment_robokassa->addOrderNote($refund['order_id'], sprintf($this->language->get('note_refund_canceled'), $refund['request_id']));
                        }

                        $json = array('success' => true, 'status' => $status);
                    }
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    private function submitRefund(array $order_info)
    {
        $order_id = (int)$order_info['order_id'];
        $remaining = max(0, round((float)$order_info['total'] - $this->model_extension_payment_robokassa->getReservedRefundTotal($order_id), 2));
        $receipt_mode = (bool)$this->config->get('payment_robokassa_fiscal')
            && isset($this->request->post['receipt_mode'])
            && $this->request->post['receipt_mode'] === 'items';
        $invoice_items = array();
        $allocations = array();

        if ($receipt_mode) {
            try {
                $built = $this->model_extension_payment_robokassa->buildRefundInvoiceItems(
                    $order_id,
                    isset($this->request->post['refund_product']) && is_array($this->request->post['refund_product']) ? $this->request->post['refund_product'] : array(),
                    !empty($this->request->post['refund_shipping'])
                );
            } catch (Exception $e) {
                return $e->getMessage();
            }

            $amount = $built['amount'];
            $invoice_items = $built['invoice_items'];
            $allocations = $built['allocations'];
        } else {
            $amount_value = isset($this->request->post['amount']) && is_scalar($this->request->post['amount'])
                ? str_replace(',', '.', (string)$this->request->post['amount'])
                : '';

            if (!is_numeric($amount_value)) {
                return $this->language->get('error_refund_amount');
            }

            $amount = round((float)$amount_value, 2);
        }

        if ($amount <= 0 || $amount > $remaining + 0.005) {
            return sprintf($this->language->get('error_refund_amount_available'), number_format($remaining, 2, '.', ''));
        }

        $reason = isset($this->request->post['reason']) && is_scalar($this->request->post['reason'])
            ? utf8_substr(trim(strip_tags((string)$this->request->post['reason'])), 0, 255)
            : '';
        $is_full = abs($amount - $remaining) <= 0.005;
        $api = $this->getRefundApi();
        $operation = $api->getOperationKey($order_id);

        if (!$operation['success']) {
            return $operation['error'];
        }

        $fingerprint = hash('sha256', implode('|', array(
            $order_id,
            number_format($amount, 2, '.', ''),
            $reason,
            json_encode($invoice_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        )));
        $reservation = $this->model_extension_payment_robokassa->reserveRefund(
            $order_id,
            $operation['operation_key'],
            $fingerprint,
            $amount,
            $is_full,
            $reason,
            $invoice_items,
            $allocations,
            (float)$order_info['total']
        );

        if (!$reservation['success']) {
            return $reservation['error'];
        }

        $result = $api->create($operation['operation_key'], $is_full ? null : $amount, $invoice_items);

        if (!$result['success']) {
            $status = $result['uncertain'] ? 'unknown' : 'failed';
            $this->model_extension_payment_robokassa->failRefundSubmission($reservation['refund_id'], $status, $result['error'], $result['raw']);

            if ($status === 'unknown') {
                $this->model_extension_payment_robokassa->addOrderNote($order_id, $this->language->get('note_refund_unknown'));
            }

            return $result['error'] . ($status === 'unknown' ? ' ' . $this->language->get('error_refund_unknown_retry') : '');
        }

        $response = $result['data'];

        $request_id_valid = !empty($response['requestId'])
            && is_scalar($response['requestId'])
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$response['requestId']);

        if (empty($response['success']) || !$request_id_valid) {
            $message = !empty($response['message']) && is_scalar($response['message']) ? (string)$response['message'] : $this->language->get('error_refund_rejected');
            $status = !empty($response['success']) ? 'unknown' : 'failed';
            $this->model_extension_payment_robokassa->failRefundSubmission($reservation['refund_id'], $status, $message, $result['raw']);

            if ($status === 'unknown') {
                $this->model_extension_payment_robokassa->addOrderNote($order_id, $this->language->get('note_refund_unknown'));
            }

            return $message . ($status === 'unknown' ? ' ' . $this->language->get('error_refund_unknown_retry') : '');
        }

        $request_id = (string)$response['requestId'];
        $this->model_extension_payment_robokassa->completeRefundSubmission($reservation['refund_id'], $request_id, $result['raw']);
        $this->model_extension_payment_robokassa->addOrderNote($order_id, sprintf($this->language->get('note_refund_created'), number_format($amount, 2, '.', ''), $request_id, $invoice_items ? '' : ' ' . $this->language->get('text_refund_without_receipt')));
        $this->session->data['success'] = sprintf($this->language->get('success_refund_created'), $request_id);

        return '';
    }

    private function getRefundEligibilityError(array $order_info, $modify)
    {
        if (!$this->user->hasPermission($modify ? 'modify' : 'access', 'sale/order')) {
            return $this->language->get('error_refund_permission');
        }

        if (empty($order_info['payment_code']) || strpos((string)$order_info['payment_code'], 'robokassa') !== 0) {
            return $this->language->get('error_refund_payment');
        }

        if ($this->config->get('payment_robokassa_country') !== 'RUB') {
            return $this->language->get('error_refund_country');
        }

        if (strtoupper((string)$order_info['currency_code']) !== 'RUB') {
            return $this->language->get('error_refund_currency');
        }

        if ($this->config->get('payment_robokassa_test')) {
            return $this->language->get('error_refund_test');
        }

        if (trim((string)$this->config->get('payment_robokassa_password_3')) === '') {
            return $this->language->get('error_refund_password3');
        }

        return '';
    }

    private function getRefundApi()
    {
        require_once(DIR_SYSTEM . 'library/robokassa/refund_api.php');

        return new RobokassaRefundApi(
            $this->config->get('payment_robokassa_login'),
            $this->config->get('payment_robokassa_password_2'),
            $this->config->get('payment_robokassa_password_3')
        );
    }

    private function fetchInstallmentAliases($merchant_login)
    {
        $merchant_login = trim((string)$merchant_login);

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
            $context = stream_context_create(array(
                'http' => array(
                    'timeout' => 3
                )
            ));
            $currency_xml = @file_get_contents($currency_url, false, $context);
        }

        if ($currency_xml === false || strpos($currency_xml, '<Code>0</Code>') === false) {
            return false;
        }

        if (!preg_match_all('/\bAlias="([^"]+)"/i', $currency_xml, $aliases_match)) {
            return array();
        }

        $aliases = array();

        foreach ($aliases_match[1] as $alias) {
            $aliases[] = strtolower((string)$alias);
        }

        return array_values(array_unique($aliases));
    }

    private function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/robokassa')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->request->post['payment_robokassa_login']) {
            $this->error['merch_login'] = $this->language->get('error_merch_login');
        }

        if (!$this->request->post['payment_robokassa_password_1']) {
            $this->error['e_password1'] = $this->language->get('error_password1');
        }

        if (!$this->request->post['payment_robokassa_password_2']) {
            $this->error['e_password2'] = $this->language->get('error_password2');
        }

        return !$this->error;
    }

	private function getModuleVersion()
	{
		$version = 'unknown';

		$query = $this->db->query(
			"SELECT `version` FROM `" . DB_PREFIX . "modification` WHERE `code` = 'Robokassa' LIMIT 1"
		);

		if ($query->num_rows && !empty($query->row['version'])) {
			$version = $query->row['version'];
		}

		return $version;
	}

	private function sendPulseStatusChange($status)
	{
		$apiUrl = 'https://pulse.robokassa.com/api/module-status';
		$apiKey = 'robokassa-plugin-stat-key-3953';

		$merchantId = $this->config->get('payment_robokassa_login');
		if (!$merchantId) {
			$merchantId = 'unknown';
		}

		if (defined('HTTPS_CATALOG')) {
			$siteUrl = HTTPS_CATALOG;
		} else {
			$siteUrl = HTTP_CATALOG;
		}

		$moduleVersion = $this->getModuleVersion();
		$reportedAt    = date('Y-m-d H:i:s');

		$payload = array(
			'cms'         => 'opencart3',
			'merchant_id' => $merchantId,
			'site_id'     => $siteUrl,
			'status'      => $status,
			'reported_at' => $reportedAt,
			'version'     => $moduleVersion,
		);

		$ch = curl_init($apiUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'X-API-KEY: ' . $apiKey,
		));
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);

		$response = curl_exec($ch);
		$errno    = curl_errno($ch);
		$error    = curl_error($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
	}

	public function install()
	{
		$this->load->model('extension/payment/robokassa');
		$this->model_extension_payment_robokassa->installMarkingTables();
		$this->installPaymentExtensions();
		$this->registerMarkingEvents();
		$this->sendPulseStatusChange('enabled');
	}

	public function uninstall()
	{
		$this->load->model('setting/extension');

		foreach (array('robokassa_credit', 'robokassa_mokka', 'robokassa_podeli', 'robokassa_sbp', 'robokassa_widget', 'robokassa_yandex_split') as $code) {
			$this->model_setting_extension->uninstall('payment', $code);
		}

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('robokassa_admin_menu');
		$this->model_setting_event->deleteEventByCode('robokassa_marking_product_form');
		$this->model_setting_event->deleteEventByCode('robokassa_marking_product_edit');
		$this->model_setting_event->deleteEventByCode('robokassa_marking_product_add');
		$this->sendPulseStatusChange('disabled');
	}
}

<?php

class ControllerExtensionPaymentRobokassaWidget extends Controller
{
    private $error = array();

    private function getSettingValue($key, $default = '')
    {
        if (isset($this->request->post[$key])) {
            return $this->request->post[$key];
        }

        $value = $this->config->get($key);

        return ($value === null || $value === '') ? $default : $value;
    }

    public function index()
    {
        $this->load->language('extension/payment/robokassa_widget');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_robokassa_widget', $this->request->post);
            $this->db->query(
                "DELETE FROM `" . DB_PREFIX . "setting`
                 WHERE `code` = 'payment_robokassa'
                   AND `key` = 'payment_robokassa_widget_status'"
            );
            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
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
            'href' => $this->url->link('extension/payment/robokassa_widget', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['text_light'] = $this->language->get('text_light');
        $data['text_dark'] = $this->language->get('text_dark');
        $data['text_left'] = $this->language->get('text_left');
        $data['text_right'] = $this->language->get('text_right');
        $data['text_size_s'] = $this->language->get('text_size_s');
        $data['text_size_m'] = $this->language->get('text_size_m');
        $data['text_widget_bnpl'] = $this->language->get('text_widget_bnpl');
        $data['text_widget_credit'] = $this->language->get('text_widget_credit');
        $data['entry_status_widget'] = $this->language->get('entry_status_widget');
        $data['entry_bnpl_theme'] = $this->language->get('entry_bnpl_theme');
        $data['entry_bnpl_size'] = $this->language->get('entry_bnpl_size');
        $data['entry_bnpl_show_logo'] = $this->language->get('entry_bnpl_show_logo');
        $data['entry_bnpl_border_radius'] = $this->language->get('entry_bnpl_border_radius');
        $data['entry_bnpl_has_second_line'] = $this->language->get('entry_bnpl_has_second_line');
        $data['entry_bnpl_description_position'] = $this->language->get('entry_bnpl_description_position');
        $data['entry_credit_theme'] = $this->language->get('entry_credit_theme');
        $data['entry_credit_size'] = $this->language->get('entry_credit_size');
        $data['entry_credit_show_logo'] = $this->language->get('entry_credit_show_logo');
        $data['entry_credit_border_radius'] = $this->language->get('entry_credit_border_radius');
        $data['entry_credit_has_second_line'] = $this->language->get('entry_credit_has_second_line');
        $data['entry_credit_description_position'] = $this->language->get('entry_credit_description_position');
        $data['help_bnpl_theme'] = $this->language->get('help_bnpl_theme');
        $data['help_bnpl_size'] = $this->language->get('help_bnpl_size');
        $data['help_bnpl_show_logo'] = $this->language->get('help_bnpl_show_logo');
        $data['help_bnpl_border_radius'] = $this->language->get('help_bnpl_border_radius');
        $data['help_bnpl_has_second_line'] = $this->language->get('help_bnpl_has_second_line');
        $data['help_bnpl_description_position'] = $this->language->get('help_bnpl_description_position');
        $data['help_credit_theme'] = $this->language->get('help_credit_theme');
        $data['help_credit_size'] = $this->language->get('help_credit_size');
        $data['help_credit_show_logo'] = $this->language->get('help_credit_show_logo');
        $data['help_credit_border_radius'] = $this->language->get('help_credit_border_radius');
        $data['help_credit_has_second_line'] = $this->language->get('help_credit_has_second_line');
        $data['help_credit_description_position'] = $this->language->get('help_credit_description_position');

        $data['action'] = $this->url->link('extension/payment/robokassa_widget', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        $data['payment_robokassa_widget_status'] = $this->getSettingValue('payment_robokassa_widget_status', 0);
        $data['payment_robokassa_widget_bnpl_theme'] = $this->getSettingValue('payment_robokassa_widget_bnpl_theme', 'light');
        $data['payment_robokassa_widget_bnpl_size'] = $this->getSettingValue('payment_robokassa_widget_bnpl_size', 'm');
        $data['payment_robokassa_widget_bnpl_show_logo'] = $this->getSettingValue('payment_robokassa_widget_bnpl_show_logo', 1);
        $data['payment_robokassa_widget_bnpl_border_radius'] = $this->getSettingValue('payment_robokassa_widget_bnpl_border_radius', '50');
        $data['payment_robokassa_widget_bnpl_has_second_line'] = $this->getSettingValue('payment_robokassa_widget_bnpl_has_second_line', 1);
        $data['payment_robokassa_widget_bnpl_description_position'] = $this->getSettingValue('payment_robokassa_widget_bnpl_description_position', 'right');
        $data['payment_robokassa_widget_credit_theme'] = $this->getSettingValue('payment_robokassa_widget_credit_theme', 'dark');
        $data['payment_robokassa_widget_credit_size'] = $this->getSettingValue('payment_robokassa_widget_credit_size', 'm');
        $data['payment_robokassa_widget_credit_show_logo'] = $this->getSettingValue('payment_robokassa_widget_credit_show_logo', 1);
        $data['payment_robokassa_widget_credit_border_radius'] = $this->getSettingValue('payment_robokassa_widget_credit_border_radius', '12');
        $data['payment_robokassa_widget_credit_has_second_line'] = $this->getSettingValue('payment_robokassa_widget_credit_has_second_line', 0);
        $data['payment_robokassa_widget_credit_description_position'] = $this->getSettingValue('payment_robokassa_widget_credit_description_position', 'right');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/robokassa_widget', $data));
    }

    private function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/robokassa_widget')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }
}

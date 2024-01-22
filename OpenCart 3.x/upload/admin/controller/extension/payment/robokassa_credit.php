<?php

class ControllerExtensionPaymentRobokassaCredit extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/payment/robokassa_credit');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
        $this->load->model('localisation/language');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {

            $this->model_setting_setting->editSetting('payment_robokassa_credit', $this->request->post);
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
            'href' => $this->url->link('extension/payment/robokassa_credit', 'user_token=' . $this->session->data['user_token'], true)
        );



        $data['entry_status_credit'] = $this->language->get('entry_status_credit');

        $data['action'] = $this->url->link('extension/payment/robokassa_credit', 'user_token=' . $this->session->data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        $data['opencart_languages'] = $this->model_localisation_language->getLanguages();



        if (isset($this->request->post['payment_robokassa_credit_status'])) {
            $data['payment_robokassa_credit_status'] = $this->request->post['payment_robokassa_credit_status'];
        } else {
            $data['payment_robokassa_credit_status'] = $this->config->get('payment_robokassa_credit_status');
        }

        if (isset($this->request->post['payment_robokassa_credit_widget'])) {
            $data['payment_robokassa_credit_widget'] = $this->request->post['payment_robokassa_credit_widget'];
        } else {
            $data['payment_robokassa_credit_widget'] = $this->config->get('payment_robokassa_credit_widget');
        }


        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/robokassa_credit', $data));
    }

    private function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/robokassa_credit')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }
}
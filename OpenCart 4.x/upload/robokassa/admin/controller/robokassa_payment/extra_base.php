<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Payment;

class RobokassaExtraBase extends \Opencart\System\Engine\Controller
{
    protected array $error = [];
    protected string $code = '';
    protected string $setting_code = '';
    protected string $status_key = '';
    protected string $sort_order_key = '';

    public function index(): void
    {
        $this->load->language('extension/robokassa/payment/' . $this->code);
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
            $this->saveSettingDirect($this->setting_code, $this->request->post);
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $data = $this->buildData();
        $this->response->setOutput($this->load->view('extension/robokassa/payment/robokassa_extra', $data));
    }

    protected function buildData(): array
    {
        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['entry_status'] = $this->language->get('entry_status');
        $data['entry_sort_order'] = $this->language->get('entry_sort_order');
        $data['button_save'] = $this->language->get('button_save');
        $data['button_back'] = $this->language->get('button_back');
        $data['error_warning'] = $this->error['warning'] ?? '';
        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])],
            ['text' => $this->language->get('text_payment'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/robokassa/payment/' . $this->code, 'user_token=' . $this->session->data['user_token'])]
        ];
        $data['action'] = $this->url->link('extension/robokassa/payment/' . $this->code, 'user_token=' . $this->session->data['user_token'], true);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');
        $data['status_key'] = $this->status_key;
        $data['sort_order_key'] = $this->sort_order_key;
        $data['status'] = $this->request->post[$this->status_key] ?? $this->config->get($this->status_key);
        $data['sort_order'] = $this->request->post[$this->sort_order_key] ?? $this->config->get($this->sort_order_key);
        $data['is_widget'] = false;
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        return $data;
    }

    protected function validate(): bool
    {
        if (!$this->user->hasPermission('modify', 'extension/robokassa/payment/' . $this->code)) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }

    public function install(): void
    {
        $this->registerPaymentListFilterEvents();
    }

    public function uninstall(): void
    {
        $this->registerPaymentListFilterEvents();
    }

    protected function registerPaymentListFilterEvents(): void
    {
        $this->load->model('setting/event');

        foreach (['robokassa_admin_payment_extension_after_dot', 'robokassa_admin_payment_extension_after_pipe'] as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }

        foreach ([
            [
                'code'        => 'robokassa_admin_payment_extension_after_dot',
                'description' => 'Robokassa admin payment extension list filter (dot)',
                'trigger'     => 'admin/view/extension/payment/after',
                'action'      => 'extension/robokassa/event/robokassa.onPaymentExtensionViewAfter',
                'status'      => 1,
                'sort_order'  => 8
            ],
            [
                'code'        => 'robokassa_admin_payment_extension_after_pipe',
                'description' => 'Robokassa admin payment extension list filter (pipe)',
                'trigger'     => 'admin/view/extension/payment/after',
                'action'      => 'extension/robokassa/event/robokassa|onPaymentExtensionViewAfter',
                'status'      => 1,
                'sort_order'  => 9
            ]
        ] as $event) {
            $this->model_setting_event->addEvent($event);
        }
    }

    protected function saveSettingDirect(string $code, array $data, int $store_id = 0): void
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
}

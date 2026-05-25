<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Payment;

require_once DIR_EXTENSION . 'robokassa/admin/controller/payment/robokassa_sbp.php';

class RobokassaWidget extends RobokassaSbp
{
    protected string $code = 'robokassa_widget';
    protected string $setting_code = 'payment_robokassa_widget';
    protected string $status_key = 'payment_robokassa_widget_status';
    protected string $sort_order_key = 'payment_robokassa_widget_sort_order';

    protected function buildData(): array
    {
        $data = parent::buildData();
        $data['is_widget'] = true;

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

        return $data;
    }

    protected function saveSettingDirect(string $code, array $data, int $store_id = 0): void
    {
        parent::saveSettingDirect($code, $data, $store_id);
        $this->registerProductWidgetEvents();
    }

    public function install(): void
    {
        $this->registerProductWidgetEvents();
    }

    public function uninstall(): void
    {
        $this->load->model('setting/event');

        foreach ([
            'robokassa_product_widget_after_dot',
            'robokassa_product_widget_after_pipe',
            'robokassa_widget_payment_method_after_dot',
            'robokassa_widget_payment_method_after_pipe'
        ] as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }
    }

    private function registerProductWidgetEvents(): void
    {
        $this->load->model('setting/event');

        foreach ([
            'robokassa_product_widget_after_dot',
            'robokassa_product_widget_after_pipe',
            'robokassa_widget_payment_method_after_dot',
            'robokassa_widget_payment_method_after_pipe'
        ] as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }

        foreach ([
            [
                'code' => 'robokassa_product_widget_after_dot',
                'description' => 'Robokassa product widget (product/product after, dot)',
                'trigger' => 'catalog/view/product/product/after',
                'action' => 'extension/robokassa/event/robokassa.onProductViewAfter',
                'status' => 1,
                'sort_order' => 4
            ],
            [
                'code' => 'robokassa_product_widget_after_pipe',
                'description' => 'Robokassa product widget (product/product after, pipe)',
                'trigger' => 'catalog/view/product/product/after',
                'action' => 'extension/robokassa/event/robokassa|onProductViewAfter',
                'status' => 1,
                'sort_order' => 5
            ],
            [
                'code' => 'robokassa_widget_payment_method_after_dot',
                'description' => 'Robokassa product widget payment preselect (dot)',
                'trigger' => 'catalog/view/checkout/payment_method/after',
                'action' => 'extension/robokassa/event/robokassa.onPaymentMethodViewAfter',
                'status' => 1,
                'sort_order' => 6
            ],
            [
                'code' => 'robokassa_widget_payment_method_after_pipe',
                'description' => 'Robokassa product widget payment preselect (pipe)',
                'trigger' => 'catalog/view/checkout/payment_method/after',
                'action' => 'extension/robokassa/event/robokassa|onPaymentMethodViewAfter',
                'status' => 1,
                'sort_order' => 7
            ]
        ] as $event) {
            $this->model_setting_event->addEvent($event);
        }
    }
}

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
}

<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Payment;

require_once DIR_EXTENSION . 'robokassa/admin/controller/robokassa_payment/extra_base.php';

class RobokassaMokka extends RobokassaExtraBase
{
    protected string $code = 'robokassa_mokka';
    protected string $setting_code = 'payment_robokassa_mokka';
    protected string $status_key = 'payment_robokassa_mokka_status';
    protected string $sort_order_key = 'payment_robokassa_mokka_sort_order';
}

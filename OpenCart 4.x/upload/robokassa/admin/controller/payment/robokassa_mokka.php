<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Payment;

require_once DIR_EXTENSION . 'robokassa/admin/controller/payment/robokassa_sbp.php';

class RobokassaMokka extends RobokassaSbp
{
    protected string $code = 'robokassa_mokka';
    protected string $setting_code = 'payment_robokassa_mokka';
    protected string $status_key = 'payment_robokassa_mokka_status';
    protected string $sort_order_key = 'payment_robokassa_mokka_sort_order';
}

<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Payment;

require_once DIR_EXTENSION . 'robokassa/admin/controller/robokassa_payment/extra_base.php';

class RobokassaCredit extends RobokassaExtraBase
{
    protected string $code = 'robokassa_credit';
    protected string $setting_code = 'payment_robokassa_credit';
    protected string $status_key = 'payment_robokassa_credit_status';
    protected string $sort_order_key = 'payment_robokassa_credit_sort_order';
}

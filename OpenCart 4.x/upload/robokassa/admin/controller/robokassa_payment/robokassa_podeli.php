<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Payment;

require_once DIR_EXTENSION . 'robokassa/admin/controller/robokassa_payment/extra_base.php';

class RobokassaPodeli extends RobokassaExtraBase
{
    protected string $code = 'robokassa_podeli';
    protected string $setting_code = 'payment_robokassa_podeli';
    protected string $status_key = 'payment_robokassa_podeli_status';
    protected string $sort_order_key = 'payment_robokassa_podeli_sort_order';
}

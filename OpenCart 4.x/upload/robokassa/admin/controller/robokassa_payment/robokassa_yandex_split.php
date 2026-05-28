<?php
namespace Opencart\Admin\Controller\Extension\Robokassa\Payment;

require_once DIR_EXTENSION . 'robokassa/admin/controller/robokassa_payment/extra_base.php';

class RobokassaYandexSplit extends RobokassaExtraBase
{
    protected string $code = 'robokassa_yandex_split';
    protected string $setting_code = 'payment_robokassa_yandex_split';
    protected string $status_key = 'payment_robokassa_yandex_split_status';
    protected string $sort_order_key = 'payment_robokassa_yandex_split_sort_order';
}

<?php
namespace Opencart\Catalog\Model\Extension\Robokassa\Payment;

require_once DIR_EXTENSION . 'robokassa/catalog/model/payment/robokassa.php';

class RobokassaYandexSplit extends Robokassa
{
    public function getMethod($address)
    {
        return $this->getExtraMethod((array)$address, 'robokassa_yandex_split', 'yandexpaysplit', 'Robokassa Yandex Split', 10, 200000, 'payment_robokassa_yandex_split_status', 'payment_robokassa_yandex_split_sort_order');
    }

    public function getMethods($address)
    {
        return $this->getExtraMethods((array)$address, 'robokassa_yandex_split', 'yandexpaysplit', 'Robokassa Yandex Split', 10, 200000, 'payment_robokassa_yandex_split_status', 'payment_robokassa_yandex_split_sort_order');
    }
}

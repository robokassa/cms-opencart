<?php
namespace Opencart\Catalog\Model\Extension\Robokassa\Payment;

require_once DIR_EXTENSION . 'robokassa/catalog/model/payment/robokassa.php';

class RobokassaMokka extends Robokassa
{
    public function getMethod($address)
    {
        return $this->getExtraMethod((array)$address, 'robokassa_mokka', 'mokka', 'Robokassa Mokka', 1000, 250000, 'payment_robokassa_mokka_status', 'payment_robokassa_mokka_sort_order');
    }

    public function getMethods($address)
    {
        return $this->getExtraMethods((array)$address, 'robokassa_mokka', 'mokka', 'Robokassa Mokka', 1000, 250000, 'payment_robokassa_mokka_status', 'payment_robokassa_mokka_sort_order');
    }
}

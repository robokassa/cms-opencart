<?php
namespace Opencart\Catalog\Model\Extension\Robokassa\Payment;

require_once DIR_EXTENSION . 'robokassa/catalog/model/payment/robokassa.php';

class RobokassaPodeli extends Robokassa
{
    public function getMethod($address)
    {
        return $this->getExtraMethod((array)$address, 'robokassa_podeli', 'podeli', 'Robokassa Podeli', 300, 30000, 'payment_robokassa_podeli_status', 'payment_robokassa_podeli_sort_order');
    }

    public function getMethods($address)
    {
        return $this->getExtraMethods((array)$address, 'robokassa_podeli', 'podeli', 'Robokassa Podeli', 300, 30000, 'payment_robokassa_podeli_status', 'payment_robokassa_podeli_sort_order');
    }
}

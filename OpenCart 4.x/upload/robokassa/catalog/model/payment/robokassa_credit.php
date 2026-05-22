<?php
namespace Opencart\Catalog\Model\Extension\Robokassa\Payment;

require_once DIR_EXTENSION . 'robokassa/catalog/model/payment/robokassa.php';

class RobokassaCredit extends Robokassa
{
    public function getMethod($address)
    {
        return $this->getExtraMethod((array)$address, 'robokassa_credit', 'otp', 'Robokassa Credit', 2000, 300000, 'payment_robokassa_credit_status', 'payment_robokassa_credit_sort_order');
    }

    public function getMethods($address)
    {
        return $this->getExtraMethods((array)$address, 'robokassa_credit', 'otp', 'Robokassa Credit', 2000, 300000, 'payment_robokassa_credit_status', 'payment_robokassa_credit_sort_order');
    }
}

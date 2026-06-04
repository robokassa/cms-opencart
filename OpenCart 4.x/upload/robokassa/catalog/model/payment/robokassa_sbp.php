<?php
namespace Opencart\Catalog\Model\Extension\Robokassa\Payment;

require_once DIR_EXTENSION . 'robokassa/catalog/model/payment/robokassa.php';

class RobokassaSbp extends Robokassa
{
    public function getMethod($address)
    {
        return $this->getExtraMethod((array)$address, 'robokassa_sbp', 'sbp', 'Robokassa QR SBP', 1, 999999999, 'payment_robokassa_sbp_status', 'payment_robokassa_sbp_sort_order');
    }

    public function getMethods($address)
    {
        return $this->getExtraMethods((array)$address, 'robokassa_sbp', 'sbp', 'Robokassa QR SBP', 1, 999999999, 'payment_robokassa_sbp_status', 'payment_robokassa_sbp_sort_order');
    }
}

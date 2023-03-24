<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Payment;

class Fail extends \Opencart\System\Engine\Controller {
    public function index()
    {
        $this->response->redirect($this->url->link('checkout/checkout', '', true));

        return true;
    }
}
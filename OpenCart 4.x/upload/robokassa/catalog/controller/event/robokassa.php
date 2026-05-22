<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Event;

class Robokassa extends \Opencart\System\Engine\Controller
{
    public function onProductViewAfter(string &$route, array &$args, mixed &$output): void
    {
        if (!is_string($output) || strpos($output, '<div id="product">') === false) {
            return;
        }

        $data = $args[1] ?? [];
        $product_id = (int)($data['product_id'] ?? ($this->request->get['product_id'] ?? 0));

        if ($product_id <= 0) {
            return;
        }

        $widget = $this->load->controller('extension/robokassa/payment/robokassa_widget', [
            'product_id' => $product_id,
            'quantity' => isset($this->request->get['quantity']) ? (int)$this->request->get['quantity'] : 1
        ]);

        if ($widget) {
            $output = str_replace('<div id="product">', $widget . '<div id="product">', $output);
        }
    }

    public function onPaymentMethodViewAfter(string &$route, array &$args, mixed &$output): void
    {
        if (!is_string($output) || strpos($output, 'robokassa') === false) {
            return;
        }

        $login = trim((string)$this->config->get('payment_robokassa_login'));

        if (!$this->config->get('payment_robokassa_status') || $this->config->get('payment_robokassa_country') !== 'RUB' || $login === '') {
            return;
        }

        $total = isset($this->session->data['order_id']) ? $this->getOrderTotal((int)$this->session->data['order_id']) : 0;

        if ($total <= 0) {
            return;
        }

        $script = '<script type="text/javascript" src="https://auth.robokassa.ru/merchant/bundle/robokassa-iframe-badge.js"></script><script type="text/javascript">(function(){function sync(){var graphs=document.querySelectorAll(".robokassa-checkout-graph");for(var i=0;i<graphs.length;i++){var wrap=graphs[i].closest("label")||graphs[i].parentNode;var radio=wrap?wrap.querySelector("input[type=radio]"):null;graphs[i].style.display=radio&&radio.checked?"block":"none";}if(typeof window.initRobokassaGraphs==="function"){window.initRobokassaGraphs();}}document.addEventListener("change",function(e){if(e.target&&e.target.type==="radio"){sync();}},true);setTimeout(sync,100);})();</script><style>.robokassa-checkout-graph{display:none;width:690px;max-width:100%;margin:8px 0 0}.robokassa-checkout-graph robokassa-graph{display:block;width:690px;max-width:100%}</style>';

        if (strpos($output, 'robokassa-iframe-badge.js') === false) {
            $output .= $script;
        }
    }

    private function getOrderTotal(int $order_id): float
    {
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        return $order_info ? (float)$order_info['total'] : 0.0;
    }
}

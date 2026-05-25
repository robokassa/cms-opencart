<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Event;

class Robokassa extends \Opencart\System\Engine\Controller
{
    public function onProductViewAfter(string &$route, array &$args, mixed &$output): void
    {
        if (!is_string($output) || strpos($output, 'id="robokassa-product-widget"') !== false) {
            return;
        }

        $product_id = (int)($this->request->get['product_id'] ?? 0);

        if ($product_id <= 0) {
            return;
        }

        $widget = $this->load->controller('extension/robokassa/payment/robokassa_widget', [
            'product_id' => $product_id,
            'quantity' => isset($this->request->get['quantity']) ? (int)$this->request->get['quantity'] : 1
        ]);

        if (!$widget) {
            return;
        }

        foreach (['<div id="product">', '<form id="form-product"', '<div id="product-info"'] as $marker) {
            if (strpos($output, $marker) !== false) {
                $output = str_replace($marker, $widget . $marker, $output);

                return;
            }
        }
    }

    public function onPaymentMethodViewAfter(string &$route, array &$args, mixed &$output): void
    {
        if (!is_string($output)) {
            return;
        }

        $preferred_code = (string)($this->session->data['robokassa_widget_payment_code'] ?? '');

        if ($preferred_code !== '' && strpos($output, 'robokassa-widget-payment-preselect') === false) {
            $preferred_json = json_encode($preferred_code);
            $output .= '<script id="robokassa-widget-payment-preselect" type="text/javascript">(function(){var preferred=' . $preferred_json . ';function choose(){var input=document.getElementById("input-payment-method");if(!input||!preferred){return false;}var option=input.querySelector("option[value=\"" + preferred + "\"]");if(!option){return false;}if(input.value!==preferred){input.value=preferred;if(window.jQuery){window.jQuery(input).trigger("change");}else{input.dispatchEvent(new Event("change",{bubbles:true}));}}return true;}if(window.jQuery){window.jQuery(document).ajaxComplete(function(event,xhr,settings){if(settings&&settings.url&&settings.url.indexOf("checkout/payment_method|getMethods")!==-1){setTimeout(choose,0);}});}setTimeout(choose,0);})();</script>';
        }

        if (strpos($output, 'robokassa') === false) {
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

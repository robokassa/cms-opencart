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

        if ($total <= 0 && isset($this->cart)) {
            $total = method_exists($this->cart, 'getTotal') ? (float)$this->cart->getTotal() : (float)$this->cart->getSubTotal();
        }

        if ($total <= 0) {
            return;
        }

        $graph_methods = json_encode([
            'robokassa_mokka' => 'Mokka',
            'robokassa_podeli' => 'Podeli',
            'robokassa_yandex_split' => 'YandexPaySplit'
        ], JSON_UNESCAPED_SLASHES);
        $login_json = json_encode($login);
        $out_sum_json = json_encode(number_format($total, 2, '.', ''));
        $bundle_url_json = json_encode('https://auth.robokassa.ru/merchant/bundle/robokassa-iframe-badge.js');

        $script = '<style>#robokassa-checkout-graph-container{display:none;margin:12px 0 14px;max-width:100%;box-sizing:border-box}#robokassa-checkout-graph-container.is-active{display:block}#robokassa-checkout-graph-container robokassa-graph{display:block;width:690px;max-width:100%}</style><script id="robokassa-checkout-graph-script" type="text/javascript">(function(){var graphMethods=' . $graph_methods . ';var merchantLogin=' . $login_json . ';var outSum=' . $out_sum_json . ';var bundleUrl=' . $bundle_url_json . ';function normalize(value){value=String(value||"");if(value.indexOf(".")!==-1){var parts=value.split(".");value=parts[parts.length-1];}return value;}function findSelect(){return document.getElementById("input-payment-method")||document.querySelector("select[name=\"payment_method\"]");}function findCommentAnchor(){var textarea=document.getElementById("input-comment")||document.querySelector("textarea[name=\"comment\"]");if(!textarea||!textarea.parentNode){return null;}var parent=textarea.parentNode;if(textarea.id){var label=parent.querySelector("label[for=\"" + textarea.id + "\"]");if(label){return label;}}var labels=parent.getElementsByTagName("label");return labels.length?labels[0]:textarea;}function ensureContainer(){var container=document.getElementById("robokassa-checkout-graph-container");if(!container){container=document.createElement("div");container.id="robokassa-checkout-graph-container";}var anchor=findCommentAnchor();if(anchor&&anchor.parentNode&&container.parentNode!==anchor.parentNode){anchor.parentNode.insertBefore(container,anchor);}else if(!container.parentNode){var select=findSelect();var field=select?(select.closest(".mb-3,.form-group,.form-group.row")||select.parentNode):null;if(field&&field.parentNode){field.parentNode.insertBefore(container,field.nextSibling);} }return container;}function ensureBundle(callback){if(typeof window.initRobokassaGraphs==="function"){callback();return;}var script=document.querySelector("script[data-robokassa-graph-bundle=\"1\"]");if(!script){script=document.createElement("script");script.src=bundleUrl;script.async=true;script.setAttribute("data-robokassa-graph-bundle","1");document.head.appendChild(script);}var tries=0;var timer=window.setInterval(function(){tries++;if(typeof window.initRobokassaGraphs==="function"){window.clearInterval(timer);callback();}else if(tries>50){window.clearInterval(timer);}},100);}function renderGraph(){var select=findSelect();var container=ensureContainer();if(!select||!container){return;}var code=normalize(select.value);var paymentMethod=graphMethods[code];if(!paymentMethod){container.classList.remove("is-active");container.innerHTML="";container.removeAttribute("data-graph-key");return;}var key=code + "|" + outSum + "|" + merchantLogin;if(container.getAttribute("data-graph-key")!==key){container.innerHTML="";var graph=document.createElement("robokassa-graph");graph.setAttribute("merchantLogin",merchantLogin);graph.setAttribute("outSum",outSum);graph.setAttribute("paymentMethod",paymentMethod);container.appendChild(graph);container.setAttribute("data-graph-key",key);}container.classList.add("is-active");ensureBundle(function(){if(typeof window.initRobokassaGraphs==="function"){window.initRobokassaGraphs(paymentMethod);}});}function bind(){var select=findSelect();if(select&&select.getAttribute("data-robokassa-graph-bound")!=="1"){select.setAttribute("data-robokassa-graph-bound","1");select.addEventListener("change",renderGraph);}renderGraph();}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",bind);}else{bind();}if(window.jQuery){window.jQuery(document).ajaxComplete(function(event,xhr,settings){if(settings&&settings.url&&settings.url.indexOf("checkout/payment_method")!==-1){setTimeout(bind,0);}});}document.addEventListener("change",function(event){if(event.target&&event.target.id==="input-payment-method"){renderGraph();}},true);setTimeout(bind,100);})();</script>';

        if (strpos($output, 'robokassa-checkout-graph-script') === false) {
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

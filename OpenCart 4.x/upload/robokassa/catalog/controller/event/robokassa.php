<?php
namespace Opencart\Catalog\Controller\Extension\Robokassa\Event;

class Robokassa extends \Opencart\System\Engine\Controller
{
    public function onCheckoutViewAfter(string &$route, array &$args, mixed &$output): void
    {
        if (!is_string($output) || strpos($output, 'robokassa-checkout-bootstrap') !== false) {
            return;
        }

        $current_route = (string)($this->request->get['route'] ?? '');
        $is_checkout = $route === 'checkout/checkout'
            || $current_route === 'checkout/checkout'
            || strpos($output, 'id="checkout-checkout"') !== false
            || strpos($output, 'checkout-payment-method') !== false
            || strpos($output, 'checkout-confirm') !== false;

        if (!$is_checkout) {
            return;
        }

        $output .= $this->getCheckoutBootstrapScript($this->getGraphConfigJson());
    }

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

        if (strpos($output, 'robokassa-checkout-graph-config') !== false) {
            return;
        }

        $graph_config_json = $this->getGraphConfigJson();

        if ($graph_config_json === 'null') {
            return;
        }

        $config = htmlspecialchars($graph_config_json, ENT_QUOTES, 'UTF-8');

        $output .= '<div id="robokassa-checkout-graph-config" hidden data-config="' . $config . '"></div>';

        if (strpos($output, 'robokassa-checkout-bootstrap') === false) {
            $output .= $this->getCheckoutBootstrapScript($graph_config_json);
        }
    }

    private function getGraphConfigJson(): string
    {
        $login = trim((string)$this->config->get('payment_robokassa_login'));

        if (!$this->config->get('payment_robokassa_status') || $this->config->get('payment_robokassa_country') !== 'RUB' || $login === '') {
            return 'null';
        }

        $total = isset($this->session->data['order_id']) ? $this->getOrderTotal((int)$this->session->data['order_id']) : 0;

        if ($total <= 0 && isset($this->cart)) {
            $total = method_exists($this->cart, 'getTotal') ? (float)$this->cart->getTotal() : (float)$this->cart->getSubTotal();
        }

        if ($total <= 0) {
            return 'null';
        }

        $json = json_encode([
            'graphMethods' => [
                'robokassa_mokka' => 'Mokka',
                'robokassa_podeli' => 'Podeli',
                'robokassa_yandex_split' => 'YandexPaySplit'
            ],
            'merchantLogin' => $login,
            'outSum' => number_format($total, 2, '.', ''),
            'bundleUrl' => 'https://auth.robokassa.ru/merchant/bundle/robokassa-iframe-badge.js'
        ], JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : 'null';
    }

    private function getOrderTotal(int $order_id): float
    {
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        return $order_info ? (float)$order_info['total'] : 0.0;
    }

    private function getCheckoutBootstrapScript(string $graph_config_json): string
    {
        return <<<HTML
<style id="robokassa-checkout-graph-style">#robokassa-checkout-graph-container{display:none;margin:12px 0 14px;max-width:100%;box-sizing:border-box}#robokassa-checkout-graph-container.is-active{display:block}#robokassa-checkout-graph-container robokassa-graph{display:block;width:690px;max-width:100%}</style>
<script id="robokassa-checkout-bootstrap" type="text/javascript">
(function(){
    if (window.robokassaCheckoutBootstrapLoaded) {
        return;
    }

    window.robokassaCheckoutBootstrapLoaded = true;

    var bundleUrl = 'https://auth.robokassa.ru/merchant/bundle/robokassa-iframe-badge.js';
    var fallbackGraphConfig = $graph_config_json;
    var graphTimer = null;
    var sbpTimers = {};
    var shippingAutoTimer = null;
    var shippingRefreshInProgress = false;

    function normalize(value) {
        value = String(value || '');

        if (value.indexOf('|') !== -1) {
            var pipeParts = value.split('|');
            value = pipeParts[pipeParts.length - 1];
        }

        if (value.indexOf('.') !== -1) {
            var dotParts = value.split('.');
            value = dotParts[dotParts.length - 1];
        }

        if (value.indexOf('/') !== -1) {
            var slashParts = value.split('/');
            value = slashParts[slashParts.length - 1];
        }

        return value;
    }

    function ensureBundle(callback, ready) {
        ready = ready || function() {
            return (window.Robokassa && window.Robokassa.pay && typeof window.Robokassa.pay.startOp === 'function') || typeof window.initRobokassaGraphs === 'function';
        };

        if (ready()) {
            callback();
            return;
        }

        var script = document.querySelector('script[data-robokassa-badge-bundle="1"]');

        if (!script) {
            script = document.createElement('script');
            script.src = bundleUrl;
            script.async = true;
            script.setAttribute('data-robokassa-badge-bundle', '1');
            document.head.appendChild(script);
        }

        var tries = 0;
        var timer = window.setInterval(function() {
            tries++;

            if (ready()) {
                window.clearInterval(timer);
                callback();
            } else if (tries > 80) {
                window.clearInterval(timer);
            }
        }, 100);
    }

    function getGraphConfig() {
        var node = document.getElementById('robokassa-checkout-graph-config');

        if (!node || !node.getAttribute('data-config')) {
            return fallbackGraphConfig || null;
        }

        try {
            return JSON.parse(node.getAttribute('data-config'));
        } catch (e) {
            return fallbackGraphConfig || null;
        }

        return fallbackGraphConfig || null;
    }

    function findPaymentSelect() {
        return document.getElementById('input-payment-method') || document.querySelector('select[name="payment_method"]');
    }

    function findShippingSelect() {
        return document.getElementById('input-shipping-method') || document.querySelector('select[name="shipping_method"]');
    }

    function triggerChange(element) {
        if (!element) {
            return;
        }

        if (window.jQuery) {
            window.jQuery(element).trigger('change');
        } else {
            element.dispatchEvent(new Event('change', {bubbles: true}));
        }
    }

    function triggerClick(element) {
        if (!element) {
            return;
        }

        if (window.jQuery) {
            window.jQuery(element).trigger('click');
        } else {
            element.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true}));
        }
    }

    function selectOnlyAvailableShippingMethod() {
        var select = findShippingSelect();

        if (!select || select.value) {
            return false;
        }

        var available = [];

        for (var i = 0; i < select.options.length; i++) {
            var option = select.options[i];

            if (option.value && !option.disabled) {
                available.push(option);
            }
        }

        if (available.length !== 1) {
            return false;
        }

        select.value = available[0].value;
        triggerChange(select);

        return true;
    }

    function waitAndSelectShippingMethod(attempt) {
        attempt = attempt || 0;

        if (selectOnlyAvailableShippingMethod()) {
            return;
        }

        if (attempt < 25) {
            shippingAutoTimer = window.setTimeout(function() {
                waitAndSelectShippingMethod(attempt + 1);
            }, 200);
        }
    }

    function refreshShippingMethodsAfterGuestSave() {
        if (shippingAutoTimer) {
            window.clearTimeout(shippingAutoTimer);
        }

        var select = findShippingSelect();

        if (select && select.value) {
            return;
        }

        var button = document.getElementById('button-shipping-method');

        if (!button) {
            waitAndSelectShippingMethod(0);
            return;
        }

        if (!shippingRefreshInProgress) {
            shippingRefreshInProgress = true;
            triggerClick(button);

            window.setTimeout(function() {
                shippingRefreshInProgress = false;
            }, 1500);
        }

        waitAndSelectShippingMethod(0);
    }

    function findCommentAnchor() {
        var textarea = document.getElementById('input-comment') || document.querySelector('textarea[name="comment"]');

        if (!textarea || !textarea.parentNode) {
            return null;
        }

        var parent = textarea.parentNode;

        if (textarea.id) {
            var label = parent.querySelector('label[for="' + textarea.id + '"]');

            if (label) {
                return label;
            }
        }

        var labels = parent.getElementsByTagName('label');

        return labels.length ? labels[0] : textarea;
    }

    function removeLegacyConfirmGraphContainer() {
        var container = document.getElementById('robokassa-confirm-graph-container');

        if (container && container.parentNode) {
            container.parentNode.removeChild(container);
        }
    }

    function ensureGraphContainer() {
        removeLegacyConfirmGraphContainer();

        var container = document.getElementById('robokassa-checkout-graph-container');

        if (!container) {
            container = document.createElement('div');
            container.id = 'robokassa-checkout-graph-container';
        }

        var anchor = findCommentAnchor();

        if (anchor && anchor.parentNode && container.parentNode !== anchor.parentNode) {
            anchor.parentNode.insertBefore(container, anchor);
        } else if (!container.parentNode) {
            var select = findPaymentSelect();
            var field = select ? (select.closest('.mb-3,.form-group,.form-group.row') || select.parentNode) : null;

            if (field && field.parentNode) {
                field.parentNode.insertBefore(container, field.nextSibling);
            }
        }

        return container;
    }

    function renderGraph() {
        removeLegacyConfirmGraphContainer();

        var config = getGraphConfig();
        var select = findPaymentSelect();
        var container = ensureGraphContainer();

        if (!config || !select || !container) {
            return;
        }

        var code = normalize(select.value);
        var paymentMethod = config.graphMethods ? config.graphMethods[code] : '';

        if (!paymentMethod) {
            container.classList.remove('is-active');
            container.innerHTML = '';
            container.removeAttribute('data-graph-key');
            return;
        }

        var key = code + '|' + config.outSum + '|' + config.merchantLogin;

        if (container.getAttribute('data-graph-key') !== key) {
            container.innerHTML = '';

            var graph = document.createElement('robokassa-graph');
            graph.setAttribute('merchantLogin', config.merchantLogin);
            graph.setAttribute('outSum', config.outSum);
            graph.setAttribute('paymentMethod', paymentMethod);
            container.appendChild(graph);
            container.setAttribute('data-graph-key', key);
            container.removeAttribute('data-graph-initialized-key');
            container.removeAttribute('data-graph-init-pending-key');
        }

        container.classList.add('is-active');

        if (container.getAttribute('data-graph-initialized-key') === key || container.getAttribute('data-graph-init-pending-key') === key) {
            return;
        }

        container.setAttribute('data-graph-init-pending-key', key);

        ensureBundle(function() {
            if (typeof window.initRobokassaGraphs === 'function') {
                window.initRobokassaGraphs(paymentMethod);
            }

            verifyGraphRendered(container, paymentMethod, key);
        }, function() {
            return typeof window.initRobokassaGraphs === 'function';
        });
    }

    function hasGraphMarkup(container) {
        var graph = container ? container.querySelector('robokassa-graph') : null;

        if (!graph) {
            return false;
        }

        return graph.offsetHeight > 20 || graph.children.length > 0 || !!(graph.shadowRoot && graph.shadowRoot.childNodes.length);
    }

    function verifyGraphRendered(container, paymentMethod, key) {
        window.setTimeout(function() {
            if (hasGraphMarkup(container)) {
                container.setAttribute('data-graph-initialized-key', key);
                container.removeAttribute('data-graph-init-pending-key');
                container.removeAttribute('data-graph-render-attempts');
                return;
            }

            var attempts = parseInt(container.getAttribute('data-graph-render-attempts') || '0', 10) + 1;
            container.setAttribute('data-graph-render-attempts', String(attempts));
            container.removeAttribute('data-graph-initialized-key');
            container.removeAttribute('data-graph-init-pending-key');

            if (attempts <= 4) {
                window.setTimeout(renderGraph, 300);
            }
        }, 2000);
    }

    function startSbpPayments() {
        var nodes = document.querySelectorAll('.robokassa-sbp-payment[data-sbp-config]');

        for (var i = 0; i < nodes.length; i++) {
            startSbpPayment(nodes[i]);
        }
    }

    function startSbpPayment(node) {
        var config;

        try {
            config = JSON.parse(node.getAttribute('data-sbp-config') || '{}');
        } catch (e) {
            return;
        }

        if (!config || !config.stateKey || !config.qrContainerId || !config.options) {
            return;
        }

        var state = window.robokassaSbpState || (window.robokassaSbpState = {});

        state[config.stateKey] = state[config.stateKey] || {};

        var container = document.getElementById(config.qrContainerId);

        if (!container) {
            return;
        }

        var containerChanged = state[config.stateKey].container && state[config.stateKey].container !== container;
        var failedRender = (state[config.stateKey].renderAttempts || 0) > 5;

        if (state[config.stateKey].started && !state[config.stateKey].loading && !state[config.stateKey].paymentLink && !hasSbpQrMarkup(config) && (containerChanged || failedRender)) {
            if (sbpTimers[config.stateKey]) {
                window.clearInterval(sbpTimers[config.stateKey]);
                sbpTimers[config.stateKey] = null;
            }

            state[config.stateKey].started = false;
            state[config.stateKey].loading = false;
            state[config.stateKey].renderAttempts = 0;
        }

        state[config.stateKey].container = container;

        if (state[config.stateKey].started || state[config.stateKey].loading) {
            return;
        }

        state[config.stateKey].loading = true;

        ensureBundle(function() {
            state[config.stateKey].loading = false;

            if (state[config.stateKey].started) {
                return;
            }

            if (!window.Robokassa || !window.Robokassa.pay || typeof window.Robokassa.pay.startOp !== 'function') {
                setSbpStatus(config, config.textError || '');
                return;
            }

            state[config.stateKey].started = true;

            setSbpStatus(config, config.textWait || '');
            startSbpPolling(config);
            config.options.onpaymentlink = function(url) {
                state[config.stateKey].paymentLink = url;
                renderSbpPaymentLinkQr(config, url);
                return url;
            };
            window.Robokassa.pay.startOp(config.options);
            verifySbpQrRendered(config, node);
        }, function() {
            return window.Robokassa && window.Robokassa.pay && typeof window.Robokassa.pay.startOp === 'function';
        });
    }

    function loadQrLibrary(callback) {
        if (typeof window.QRCode === 'function') {
            callback();
            return;
        }

        if (window.robokassaQrLibraryLoading) {
            var waitInterval = window.setInterval(function() {
                if (typeof window.QRCode === 'function') {
                    window.clearInterval(waitInterval);
                    callback();
                }
            }, 100);

            window.setTimeout(function() {
                window.clearInterval(waitInterval);
            }, 5000);

            return;
        }

        window.robokassaQrLibraryLoading = true;

        var script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
        script.async = true;
        script.onload = function() {
            window.robokassaQrLibraryLoading = false;
            callback();
        };
        script.onerror = function() {
            window.robokassaQrLibraryLoading = false;
        };

        document.head.appendChild(script);
    }

    function renderSbpPaymentLinkQr(config, url) {
        var container = document.getElementById(config.qrContainerId);

        if (!container || !url) {
            return;
        }

        if (container.getAttribute('data-payment-link') === url && hasSbpQrMarkup(config)) {
            return;
        }

        loadQrLibrary(function() {
            if (typeof window.QRCode !== 'function') {
                return;
            }

            container.innerHTML = '';
            container.setAttribute('data-payment-link', url);

            new window.QRCode(container, {
                text: url,
                width: 280,
                height: 280,
                correctLevel: window.QRCode.CorrectLevel.M
            });
        });
    }

    function sizeSbpQrIframe(config) {
        var container = document.getElementById(config.qrContainerId);

        if (!container) {
            return;
        }

        var size = config.qrContainerSize || (config.options ? config.options.qrContainerSize : 280) || 280;
        container.style.minHeight = size + 'px';

        var iframe = container.querySelector('iframe');

        if (!iframe) {
            return;
        }

        iframe.style.setProperty('display', 'block', 'important');
        iframe.style.setProperty('width', size + 'px', 'important');
        iframe.style.setProperty('max-width', '100%', 'important');
        iframe.style.setProperty('height', size + 'px', 'important');
        iframe.style.setProperty('min-height', size + 'px', 'important');
        iframe.style.setProperty('border', '0', 'important');
        iframe.style.setProperty('margin', '0 auto', 'important');
    }

    function isVisibleSbpQrNode(node) {
        if (!node) {
            return false;
        }

        var rect = node.getBoundingClientRect();
        var style = window.getComputedStyle(node);

        return rect.width > 20 && rect.height > 20 && style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    }

    function hasSbpQrMarkup(config) {
        var container = document.getElementById(config.qrContainerId);

        if (!container) {
            return false;
        }

        var nodes = container.querySelectorAll('iframe,canvas,svg,img,table');

        for (var i = 0; i < nodes.length; i++) {
            if (isVisibleSbpQrNode(nodes[i])) {
                return true;
            }
        }

        return false;
    }

    function verifySbpQrRendered(config, node) {
        var state = window.robokassaSbpState || (window.robokassaSbpState = {});

        state[config.stateKey] = state[config.stateKey] || {};

        window.setTimeout(function() {
            if (state[config.stateKey].paymentLink) {
                renderSbpPaymentLinkQr(config, state[config.stateKey].paymentLink);

                if (hasSbpQrMarkup(config)) {
                    state[config.stateKey].renderAttempts = 0;
                    return;
                }

                state[config.stateKey].renderAttempts = (state[config.stateKey].renderAttempts || 0) + 1;

                if (state[config.stateKey].renderAttempts > 10) {
                    setSbpStatus(config, config.textError || '');
                    return;
                }

                verifySbpQrRendered(config, node);
                return;
            }

            if (hasSbpQrMarkup(config)) {
                state[config.stateKey].renderAttempts = 0;
                return;
            }

            state[config.stateKey].renderAttempts = (state[config.stateKey].renderAttempts || 0) + 1;

            if (state[config.stateKey].renderAttempts > 5) {
                setSbpStatus(config, config.textError || '');
                return;
            }

            if (sbpTimers[config.stateKey]) {
                window.clearInterval(sbpTimers[config.stateKey]);
                sbpTimers[config.stateKey] = null;
            }

            state[config.stateKey].started = false;
            state[config.stateKey].loading = false;
            window.setTimeout(function() {
                startSbpPayment(node);
            }, 300);
        }, 1500);
    }

    function setSbpStatus(config, text) {
        var statusNode = document.getElementById('robokassa-sbp-status-' + config.invId);

        if (statusNode && text) {
            statusNode.textContent = text;
        }
    }

    function startSbpPolling(config) {
        if (!config.statusUrl) {
            return;
        }

        if (sbpTimers[config.stateKey]) {
            window.clearInterval(sbpTimers[config.stateKey]);
        }

        var poll = function() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', config.statusUrl, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4 || xhr.status < 200 || xhr.status >= 300) {
                    return;
                }

                try {
                    var response = JSON.parse(xhr.responseText);

                    if (response && response.paid) {
                        window.clearInterval(sbpTimers[config.stateKey]);
                        window.location.href = response.success_url || config.successUrl;
                    }
                } catch (e) {}
            };
            xhr.send();
        };

        poll();
        sbpTimers[config.stateKey] = window.setInterval(poll, 1000);
    }

    function scan() {
        if (graphTimer) {
            window.clearTimeout(graphTimer);
        }

        graphTimer = window.setTimeout(function() {
            renderGraph();
        }, 50);
    }

    window.robokassaCheckoutGraphScan = scan;
    document.addEventListener('robokassa:checkout-graph-config', scan);

    document.addEventListener('change', function(event) {
        if (event.target && (event.target.id === 'input-payment-method' || event.target.name === 'payment_method')) {
            scan();
        }
    }, true);

    if (window.jQuery) {
        window.jQuery(document).ajaxComplete(function(event, xhr, settings) {
            var url = settings && settings.url ? settings.url : '';

            if (url.indexOf('checkout/register|save') !== -1) {
                window.setTimeout(refreshShippingMethodsAfterGuestSave, 100);
            }

            if (url.indexOf('checkout/shipping_method|getMethods') !== -1) {
                window.setTimeout(function() {
                    shippingRefreshInProgress = false;
                    waitAndSelectShippingMethod(0);
                }, 100);
            }

            scan();
        });
    }

    if (window.MutationObserver) {
        var root = document.getElementById('checkout-checkout') || document.body;
        var observer = new MutationObserver(scan);
        observer.observe(root, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }
})();
</script>
HTML;
    }
}

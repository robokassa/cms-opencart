(function () {
    'use strict';

    var graphData = null;
    var graphRequest = null;
    var renderTimer = null;
    var scriptLoading = false;
    var styleReady = false;

    function isCheckoutContext() {
        return window.location.href.indexOf('checkout') !== -1
            || !!document.querySelector('input[name="payment_method"], input[type="radio"][value*="robokassa"]');
    }

    function getBaseUrl() {
        var base = document.querySelector('base[href]');

        return base ? base.href : window.location.origin + '/';
    }

    function getGraphDataUrl() {
        return new URL('index.php?route=extension/payment/robokassa_widget/graph', getBaseUrl()).toString();
    }

    function escapeAttribute(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function ensureStyle() {
        if (styleReady) {
            return;
        }

        styleReady = true;

        var style = document.createElement('style');
        style.type = 'text/css';
        style.textContent = '.robokassa-checkout-payment-list{display:block!important;width:100%;max-width:100%}.robokassa-checkout-payment-list>.radio,.robokassa-checkout-payment-list>label,.robokassa-checkout-payment-list>.payment-method,.robokassa-checkout-payment-list>.payment-method-item,.robokassa-checkout-payment-list>.checkout-payment-method,.robokassa-checkout-payment-list>.payment-option{display:block!important;float:none!important;width:100%!important;max-width:100%!important;margin:0 0 8px!important;clear:both;box-sizing:border-box}.robokassa-checkout-payment-list input[name="payment_method"]{margin-right:6px}.robokassa-checkout-graph{display:none;position:relative;width:690px;max-width:100%;margin:8px 0 12px 22px;box-sizing:border-box;overflow:visible}.robokassa-checkout-graph.is-active{display:block}.robokassa-checkout-graph robokassa-graph{display:block;width:690px;max-width:100%;box-sizing:border-box}';

        document.head.appendChild(style);
    }

    function loadGraphData(callback) {
        if (graphData) {
            callback(graphData);

            return;
        }

        if (graphRequest) {
            graphRequest.push(callback);

            return;
        }

        graphRequest = [callback];

        var xhr = new XMLHttpRequest();
        xhr.open('GET', getGraphDataUrl(), true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            var callbacks = graphRequest;
            graphRequest = null;

            if (xhr.status < 200 || xhr.status >= 300) {
                return;
            }

            try {
                graphData = JSON.parse(xhr.responseText);
            } catch (e) {
                graphData = null;
            }

            if (!graphData || !graphData.success) {
                return;
            }

            for (var i = 0; i < callbacks.length; i++) {
                callbacks[i](graphData);
            }
        };
        xhr.send();
    }

    function normalizePaymentCode(value) {
        var normalized = String(value || '').toLowerCase();

        if (normalized.indexOf('robokassa_yandex_split') !== -1 || normalized.indexOf('yandexpaysplit') !== -1) {
            return 'robokassa_yandex_split';
        }

        if (normalized.indexOf('robokassa_podeli') !== -1 || normalized.indexOf('podeli') !== -1) {
            return 'robokassa_podeli';
        }

        if (normalized.indexOf('robokassa_mokka') !== -1 || normalized.indexOf('mokka') !== -1) {
            return 'robokassa_mokka';
        }

        return '';
    }

    function findPaymentInputs(methods) {
        var inputs = document.querySelectorAll('input[name="payment_method"], input[type="radio"][value*="robokassa"]');
        var result = [];

        for (var i = 0; i < inputs.length; i++) {
            var code = normalizePaymentCode(inputs[i].value);

            if (code && methods[code]) {
                result.push({
                    input: inputs[i],
                    code: code,
                    paymentMethod: methods[code]
                });
            }
        }

        return result;
    }

    function findPaymentContainer(input) {
        return input.closest('.radio, .payment-method, .payment-method-item, .checkout-payment-method, .payment-option, .form-group, li, label')
            || input.parentNode;
    }

    function markPaymentList(input) {
        var container = findPaymentContainer(input);
        var list = container && container.parentNode ? container.parentNode : null;

        if (list && list.classList) {
            list.classList.add('robokassa-checkout-payment-list');
        }
    }

    function ensureGraphContainer(item, data) {
        var container = findPaymentContainer(item.input);
        markPaymentList(item.input);

        if (!container || container.querySelector('.robokassa-checkout-graph')) {
            return;
        }

        var graph = document.createElement('div');
        graph.className = 'robokassa-checkout-graph';
        graph.setAttribute('data-robokassa-graph-for', item.code);
        graph.setAttribute('data-payment-method', item.paymentMethod);
        graph.innerHTML = '<robokassa-graph merchantLogin="' + escapeAttribute(data.merchant_login) + '" outSum="' + escapeAttribute(data.out_sum) + '" paymentMethod="' + escapeAttribute(item.paymentMethod) + '"></robokassa-graph>';

        container.appendChild(graph);
    }

    function syncGraphVisibility() {
        var graphs = document.querySelectorAll('.robokassa-checkout-graph[data-robokassa-graph-for]');
        var activeMethods = [];

        for (var i = 0; i < graphs.length; i++) {
            var graph = graphs[i];
            var code = graph.getAttribute('data-robokassa-graph-for');
            var input = document.querySelector('input[name="payment_method"][value="' + code + '"], input[type="radio"][value="' + code + '"]');

            if (!input) {
                var inputs = document.querySelectorAll('input[name="payment_method"], input[type="radio"][value*="robokassa"]');

                for (var j = 0; j < inputs.length; j++) {
                    if (normalizePaymentCode(inputs[j].value) === code) {
                        input = inputs[j];
                        break;
                    }
                }
            }

            if (input && input.checked) {
                graph.classList.add('is-active');
                activeMethods.push(graph.getAttribute('data-payment-method'));
            } else {
                graph.classList.remove('is-active');
            }
        }

        if (typeof window.initRobokassaGraphs === 'function' && activeMethods.length) {
            window.initRobokassaGraphs(activeMethods.length === 1 ? activeMethods[0] : activeMethods);
        }
    }

    function bindPaymentInputs() {
        var inputs = document.querySelectorAll('input[name="payment_method"], input[type="radio"][value*="robokassa"]');

        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].getAttribute('data-robokassa-graph-bound') === '1') {
                continue;
            }

            inputs[i].setAttribute('data-robokassa-graph-bound', '1');
            inputs[i].addEventListener('change', syncGraphVisibility);
        }
    }

    function ensureGraphScript(data, callback) {
        if (typeof window.initRobokassaGraphs === 'function') {
            callback();

            return;
        }

        if (scriptLoading) {
            window.setTimeout(function () {
                ensureGraphScript(data, callback);
            }, 100);

            return;
        }

        scriptLoading = true;

        var script = document.createElement('script');
        script.src = data.script;
        script.onload = function () {
            scriptLoading = false;
            callback();
        };
        script.onerror = function () {
            scriptLoading = false;
        };

        document.head.appendChild(script);
    }

    function renderGraphs() {
        if (!isCheckoutContext()) {
            return;
        }

        loadGraphData(function (data) {
            var items = findPaymentInputs(data.methods || {});

            for (var i = 0; i < items.length; i++) {
                ensureGraphContainer(items[i], data);
            }

            ensureStyle();
            bindPaymentInputs();
            ensureGraphScript(data, syncGraphVisibility);
        });
    }

    function scheduleRender() {
        if (!isCheckoutContext()) {
            return;
        }

        if (renderTimer) {
            window.clearTimeout(renderTimer);
        }

        renderTimer = window.setTimeout(renderGraphs, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleRender);
    } else {
        scheduleRender();
    }

    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches && event.target.matches('input[name="payment_method"], input[type="radio"][value*="robokassa"]')) {
            scheduleRender();
        }
    }, true);

    if (window.MutationObserver && window.location.href.indexOf('checkout') !== -1) {
        var observer = new MutationObserver(scheduleRender);

        observer.observe(document.documentElement, {
            childList: true,
            subtree: true
        });
    }
})();

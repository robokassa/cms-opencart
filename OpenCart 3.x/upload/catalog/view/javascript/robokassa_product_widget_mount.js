(function () {
    'use strict';

    function getBaseUrl() {
        var base = document.querySelector('base[href]');

        return base ? base.href : window.location.origin + '/';
    }

    function buildUrl(path) {
        if (window.URL) {
            return new URL(path, getBaseUrl()).toString();
        }

        return getBaseUrl().replace(/\/+$/, '/') + path;
    }

    function getUrlParameter(name) {
        var match = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);

        return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
    }

    function getProductId() {
        var input = document.querySelector('input[name="product_id"], #product input[name="product_id"]');

        return input && input.value ? input.value : getUrlParameter('product_id');
    }

    function getQuantity() {
        var input = document.querySelector('#input-quantity, #product input[name="quantity"], input[name="quantity"], #product input[type="number"]');
        var quantity = input ? parseInt(input.value, 10) : 1;

        return quantity && quantity > 0 ? quantity : 1;
    }

    function getWidgetUrl() {
        return buildUrl('index.php?route=extension/payment/robokassa_widget/html&product_id=' + encodeURIComponent(getProductId()) + '&quantity=' + encodeURIComponent(getQuantity()));
    }

    function findMountReference() {
        var product = document.getElementById('product');
        var selectors = [
            '#product .button-group-page',
            '#product .product-buttons',
            '#product .cart-group',
            '#product .buttons-wrapper',
            '#product .button-group',
            '#product .form-group.product-quantity',
            '#product #button-cart',
            '#product button[id*="button-cart"]',
            '#product button[onclick*="cart.add"]'
        ];

        for (var i = 0; i < selectors.length; i++) {
            var node = document.querySelector(selectors[i]);

            if (!node) {
                continue;
            }

            var wrapper = node.closest('.button-group-page, .product-buttons, .cart-group, .buttons-wrapper, .button-group, .form-group') || node;

            return {
                node: wrapper,
                position: 'beforebegin'
            };
        }

        if (product) {
            return {
                node: product,
                position: 'afterbegin'
            };
        }

        return null;
    }

    function executeScripts(scripts, index) {
        if (index >= scripts.length) {
            return;
        }

        var source = scripts[index];
        var script = document.createElement('script');

        for (var i = 0; i < source.attributes.length; i++) {
            script.setAttribute(source.attributes[i].name, source.attributes[i].value);
        }

        if (source.src) {
            script.onload = function () {
                executeScripts(scripts, index + 1);
            };
            script.onerror = function () {
                executeScripts(scripts, index + 1);
            };
            script.src = source.src;
        } else {
            script.text = source.text || source.textContent || source.innerHTML || '';
        }

        document.head.appendChild(script);

        if (!source.src) {
            executeScripts(scripts, index + 1);
        }
    }

    function insertWidget(html) {
        if (!html || document.getElementById('robokassa-product-widget')) {
            return;
        }

        var reference = findMountReference();

        if (!reference) {
            return;
        }

        var template = document.createElement('div');
        template.innerHTML = html;

        var scripts = Array.prototype.slice.call(template.querySelectorAll('script'));

        for (var i = 0; i < scripts.length; i++) {
            if (scripts[i].parentNode) {
                scripts[i].parentNode.removeChild(scripts[i]);
            }
        }

        var fragment = document.createDocumentFragment();

        while (template.firstChild) {
            fragment.appendChild(template.firstChild);
        }

        reference.node.insertAdjacentElement(reference.position, document.createElement('span'));
        var placeholder = reference.node.previousSibling;

        if (reference.position === 'afterbegin') {
            placeholder = reference.node.firstChild;
        }

        placeholder.parentNode.insertBefore(fragment, placeholder);
        placeholder.parentNode.removeChild(placeholder);

        executeScripts(scripts, 0);
    }

    function requestWidget() {
        if (!getProductId() || document.getElementById('robokassa-product-widget')) {
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', getWidgetUrl(), true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4 || xhr.status < 200 || xhr.status >= 300) {
                return;
            }

            insertWidget(xhr.responseText);
        };
        xhr.send();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', requestWidget);
    } else {
        requestWidget();
    }
})();

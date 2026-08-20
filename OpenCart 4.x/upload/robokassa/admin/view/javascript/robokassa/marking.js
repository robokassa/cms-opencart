(function ($) {
    'use strict';

    var modalElement = document.getElementById('robokassa-marking-modal');
    var fields = $('#robokassa-marking-fields');
    var message = $('#robokassa-marking-message');
    var currentOrderProductId = 0;

    if (!modalElement || !window.robokassaMarking) {
        return;
    }

    function showMessage(text, type) {
        message.removeClass('text-danger text-success')
            .addClass(type === 'success' ? 'text-success' : 'text-danger')
            .text(text || '');
    }

    function showModal() {
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
        } else {
            $(modalElement).modal('show');
        }
    }

    function updateStatus(orderProductId, status) {
        var button = $('.robokassa-marking-button[data-order-product-id="' + orderProductId + '"]');

        button.removeClass('btn-danger btn-warning btn-success')
            .addClass(status === 'filled' ? 'btn-success' : (status === 'partial' ? 'btn-warning' : 'btn-danger'));
        button.find('.robokassa-marking-status').text(button.data('label-' + status));
    }

    $(document).on('click', '.robokassa-marking-button', function () {
        currentOrderProductId = parseInt($(this).data('order-product-id'), 10);
        fields.empty();
        showMessage('');

        $.ajax({
            url: window.robokassaMarking.getUrl,
            type: 'POST',
            dataType: 'json',
            data: {order_product_id: currentOrderProductId},
            success: function (response) {
                if (!response.success) {
                    showMessage(response.error || window.robokassaMarking.loadError);
                    showModal();
                    return;
                }

                $('#robokassa-marking-title').text(response.product.name);

                for (var index = 1; index <= response.product.quantity; index++) {
                    var group = $('<div class="mb-3">');
                    var label = $('<label class="form-label">').text(window.robokassaMarking.unitLabel + ' #' + index);
                    var input = $('<input type="text" class="form-control robokassa-marking-input" autocomplete="off">')
                        .attr('data-unit-index', index)
                        .val(response.product.codes[index] || '');

                    group.append(label, input);
                    fields.append(group);
                }

                showModal();
                window.setTimeout(function () {
                    fields.find('input').filter(function () { return !this.value; }).first().trigger('focus');
                }, 200);
            },
            error: function () {
                showMessage(window.robokassaMarking.loadError);
                showModal();
            }
        });
    });

    $(document).on('keydown', '.robokassa-marking-input', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            $(this).closest('.mb-3').next('.mb-3').find('input').trigger('focus').trigger('select');
        }
    });

    $('#robokassa-marking-save').on('click', function () {
        var data = {order_product_id: currentOrderProductId, codes: {}};

        fields.find('input').each(function () {
            data.codes[$(this).data('unit-index')] = $(this).val();
        });

        $.ajax({
            url: window.robokassaMarking.saveUrl,
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function (response) {
                if (!response.success) {
                    showMessage(response.error || window.robokassaMarking.saveError);
                    return;
                }

                updateStatus(currentOrderProductId, response.status);
                showMessage(response.message, 'success');
            },
            error: function () {
                showMessage(window.robokassaMarking.saveError);
            }
        });
    });
})(jQuery);

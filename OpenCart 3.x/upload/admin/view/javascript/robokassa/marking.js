(function ($) {
    'use strict';

    var modal = $('#robokassa-marking-modal');
    var fields = $('#robokassa-marking-fields');
    var message = $('#robokassa-marking-message');
    var currentOrderProductId = 0;

    function showMessage(text, type) {
        message.removeClass('text-danger text-success')
            .addClass(type === 'success' ? 'text-success' : 'text-danger')
            .text(text || '');
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
                    showMessage(response.error);
                    return;
                }

                $('#robokassa-marking-title').text(response.product.name);

                for (var index = 1; index <= response.product.quantity; index++) {
                    var group = $('<div class="form-group">');
                    var label = $('<label class="control-label">').text(window.robokassaMarking.unitLabel + ' #' + index);
                    var input = $('<input type="text" class="form-control robokassa-marking-input">')
                        .attr('data-unit-index', index)
                        .val(response.product.codes[index] || '');

                    group.append(label, input);
                    fields.append(group);
                }

                modal.modal('show');
                setTimeout(function () {
                    fields.find('input').filter(function () { return !this.value; }).first().focus();
                }, 200);
            },
            error: function () {
                showMessage(window.robokassaMarking.loadError);
            }
        });
    });

    $(document).on('keydown', '.robokassa-marking-input', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            $(this).closest('.form-group').next('.form-group').find('input').focus().select();
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
                    showMessage(response.error);
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

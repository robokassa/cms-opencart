(function ($) {
  'use strict';

  function isItemsMode() {
    return $('input[name="receipt_mode"]:checked').val() === 'items';
  }

  var manualAmount = '';

  function refreshAmount() {
    var $amount = $('#input-refund-amount');

    if (!$amount.length) {
      return;
    }

    var remaining = parseFloat($amount.data('remaining')) || 0;

    if (isItemsMode()) {
      var total = 0;

      $('.robokassa-refund-quantity').each(function () {
        total += (parseFloat(this.value) || 0) * (parseFloat($(this).data('cost')) || 0);
      });

      $('.robokassa-refund-shipping:checked').each(function () {
        total += parseFloat($(this).data('cost')) || 0;
      });

      $amount.val(total.toFixed(2)).prop('readonly', true);
    } else {
      $amount.prop('readonly', false);
    }

    var amount = parseFloat(String($amount.val() || '').replace(',', '.')) || 0;
    $('#robokassa-refund-type').text(Math.abs(amount - remaining) <= 0.005 ? 'Полный возврат' : 'Частичный возврат');
  }

  $(function () {
    $('input[name="receipt_mode"]').on('change', function () {
      $('.robokassa-refund-mode label').removeClass('is-active');
      $(this).closest('label').addClass('is-active');
      $('#robokassa-refund-items').toggle(isItemsMode());

      if (!isItemsMode()) {
        $('#input-refund-amount').val(manualAmount || $('#input-refund-amount').data('remaining'));
      }

      refreshAmount();
    });
    $('.robokassa-refund-quantity, .robokassa-refund-shipping').on('input change', refreshAmount);
    $('#input-refund-amount').on('input change', function () {
      if (!isItemsMode()) {
        manualAmount = $(this).val();
      }

      refreshAmount();
    });
    manualAmount = $('#input-refund-amount').val();
    refreshAmount();

    $('#robokassa-refund-form').on('submit', function () {
      return window.confirm(window.robokassaRefund.confirmText);
    });

    $('.robokassa-check-refund').on('click', function () {
      var $button = $(this);
      var refundId = $button.data('refund-id');
      $button.prop('disabled', true).find('i').addClass('fa-spin');

      $.ajax({
        url: window.robokassaRefund.checkUrl,
        type: 'post',
        dataType: 'json',
        data: { refund_id: refundId },
        complete: function () {
          $button.prop('disabled', false).find('i').removeClass('fa-spin');
        },
        success: function (json) {
          if (json.success) {
            window.location.reload();
          } else {
            window.alert(json.error || 'Не удалось проверить статус возврата.');
          }
        },
        error: function () {
          window.alert('Не удалось проверить статус возврата.');
        }
      });
    });
  });
})(window.jQuery);

(function ($) {
  'use strict';

  function activateTab(name) {
    var $root = $('.robokassa-settings');

    $root.find('[data-robokassa-tab]').removeClass('is-active').attr('aria-selected', 'false');
    $root.find('[data-robokassa-tab="' + name + '"]').addClass('is-active').attr('aria-selected', 'true');
    $root.find('[data-robokassa-panel]').removeClass('is-active');
    $root.find('[data-robokassa-panel="' + name + '"]').addClass('is-active');

    try {
      window.localStorage.setItem('robokassa-settings-tab', name);
    } catch (error) {}
  }

  function refreshConditionalFields() {
    var fiscalEnabled = $('#input-fiscal').val() === '1';
    var isRussia = $('#input-country').val() === 'RUB';

    $('[data-fiscal-only]').toggleClass('is-hidden', !fiscalEnabled);
    $('[data-russia-only]').each(function () {
      $(this).toggleClass('is-hidden', !isRussia || ($(this).is('[data-fiscal-only]') && !fiscalEnabled));
    });
  }

  function copyText(text, callback) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(callback);
      return;
    }

    var input = document.createElement('textarea');
    input.value = text;
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);
    callback();
  }

  $(function () {
    var savedTab = '';

    try {
      savedTab = window.localStorage.getItem('robokassa-settings-tab') || '';
    } catch (error) {}

    if (savedTab && $('[data-robokassa-tab="' + savedTab + '"]').length) {
      activateTab(savedTab);
    }

    $('[data-robokassa-tab]').on('click', function () {
      activateTab($(this).data('robokassa-tab'));
    });

    $('#input-country, #input-fiscal').on('change', refreshConditionalFields);
    refreshConditionalFields();

    $('[data-copy-target]').on('click', function () {
      var button = this;
      var target = document.getElementById($(this).data('copy-target'));

      if (!target) {
        return;
      }

      copyText(target.textContent || target.innerText || '', function () {
        $(button).addClass('is-copied').html('<i class="fa fa-check"></i>');
        window.setTimeout(function () {
          $(button).removeClass('is-copied').html('<i class="fa fa-copy"></i>');
        }, 1400);
      });
    });

    $('[data-copy-url]').on('click', function () {
      var button = this;
      var $button = $(button);

      $button.prop('disabled', true);
      $.ajax({ url: $button.data('copy-url'), dataType: 'json', cache: false })
        .done(function (json) {
          if (!json || !json.success || !json.url) {
            window.alert((json && json.error) || 'Не удалось получить cron URL.');
            return;
          }

          copyText(json.url, function () {
            $button.addClass('is-copied').html('<i class="fa fa-check"></i>');
            window.setTimeout(function () {
              $button.removeClass('is-copied').html('<i class="fa fa-copy"></i>');
            }, 1400);
          });
        })
        .fail(function () { window.alert('Не удалось получить cron URL.'); })
        .always(function () { $button.prop('disabled', false); });
    });

    $('[data-regenerate-url]').on('click', function () {
      var button = this;
      var $button = $(button);

      if (!window.confirm($button.data('confirm'))) {
        return;
      }

      $button.prop('disabled', true);
      $.ajax({ url: $button.data('regenerate-url'), type: 'POST', dataType: 'json', cache: false })
        .done(function (json) {
          if (!json || !json.success || !json.url) {
            window.alert((json && json.error) || 'Не удалось создать новый cron-токен.');
            return;
          }

          $('#robokassa-refund-cron-url').text(json.display);
          copyText(json.url, function () {
            $button.addClass('is-copied').html('<i class="fa fa-check"></i>');
            window.alert('Новый cron URL скопирован. Обновите его в cron-задаче.');
            window.setTimeout(function () {
              $button.removeClass('is-copied').html('<i class="fa fa-refresh"></i>');
            }, 1400);
          });
        })
        .fail(function () { window.alert('Не удалось создать новый cron-токен.'); })
        .always(function () { $button.prop('disabled', false); });
    });

    $('.robokassa-toggle input[type="checkbox"]').on('change', function () {
      $(this).closest('.robokassa-method-card').toggleClass('is-enabled', this.checked);
    });
  });
})(window.jQuery);

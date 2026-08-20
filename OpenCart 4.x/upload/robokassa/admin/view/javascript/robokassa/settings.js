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
        $(button).addClass('is-copied').html('<i class="fas fa-check"></i>');
        window.setTimeout(function () {
          $(button).removeClass('is-copied').html('<i class="fas fa-copy"></i>');
        }, 1400);
      });
    });

    $('.robokassa-toggle input[type="checkbox"]').on('change', function () {
      $(this).closest('.robokassa-method-card').toggleClass('is-enabled', this.checked);
    });
  });
})(window.jQuery);

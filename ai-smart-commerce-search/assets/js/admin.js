/**
 * AI Smart Commerce Search — Admin JS
 * Test-connection button handler.
 */
(function ($) {
  'use strict';

  if (typeof $ === 'undefined') return;

  const cfg = window.ascAdmin || {};
  const i18n = cfg.i18n || {};

  $(function () {
    var $btn    = $('#asc-test-connection');
    var $result = $('#asc-test-result');

    $btn.on('click', function (e) {
      e.preventDefault();
      $btn.prop('disabled', true);
      $result.text(i18n.testing || 'Testing…').removeClass('asc-ok asc-fail');

      $.ajax({
        url: cfg.testEndpoint,
        method: 'POST',
        beforeSend: function (xhr) {
          xhr.setRequestHeader('X-WP-Nonce', cfg.restNonce);
        },
        success: function (data) {
          var status = data.status || 'unavailable';
          var label = i18n[camelize(status)] || data.message || status;
          var isOk = status === 'connected';
          $result
            .text(label)
            .addClass(isOk ? 'asc-ok' : 'asc-fail');
        },
        error: function (xhr) {
          $result.text(i18n.unavailable || 'API Unavailable').addClass('asc-fail');
        },
        complete: function () {
          $btn.prop('disabled', false);
        },
      });
    });

    function camelize(str) {
      return str.replace(/_([a-z])/g, function (m, c) { return c.toUpperCase(); });
    }
  });
})(window.jQuery);

(function () {
  'use strict';

  var cfg = window.signdocsAdmin || {};

  function setResult(text, isError) {
    var el = document.getElementById('signdocs-action-result');
    if (!el) return;
    el.textContent = text;
    el.style.color = isError ? '#d63638' : '#00a32a';
  }

  function ajaxPost(action, btn, loadingText) {
    return new Promise(function (resolve, reject) {
      btn.disabled = true;
      var origText = btn.textContent;
      btn.textContent = loadingText;
      setResult('', false);

      var body = new URLSearchParams({
        action: action,
        nonce: cfg.nonce,
      });

      fetch(cfg.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          btn.disabled = false;
          btn.textContent = origText;
          if (data.success) {
            resolve(data.data);
          } else {
            reject(data.data && data.data.message ? data.data.message : 'Unknown error');
          }
        })
        .catch(function (err) {
          btn.disabled = false;
          btn.textContent = origText;
          reject(err.message || 'Network error');
        });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var testBtn = document.getElementById('signdocs-test-connection');
    if (testBtn) {
      testBtn.addEventListener('click', function () {
        ajaxPost('signdocs_test_connection', testBtn, cfg.i18n.testing)
          .then(function () { setResult(cfg.i18n.success, false); })
          .catch(function (msg) { setResult(cfg.i18n.error + ' ' + msg, true); });
      });
    }

    var webhookBtn = document.getElementById('signdocs-register-webhook');
    if (webhookBtn) {
      webhookBtn.addEventListener('click', function () {
        ajaxPost('signdocs_register_webhook', webhookBtn, cfg.i18n.registering)
          .then(function () { setResult(cfg.i18n.registered, false); })
          .catch(function (msg) { setResult(cfg.i18n.registerError + ' ' + msg, true); });
      });
    }
  });
})();

(function () {
  'use strict';

  /**
   * SignDocs WordPress Frontend
   *
   * Flow:
   * 1. User clicks "Assinar Documento" button
   * 2. JS sends AJAX to WP to create a signing session (server-side, credentials stay safe)
   * 3. WP returns clientSecret
   * 4. JS uses @signdocs-brasil/js SDK to open the signing experience
   */

  function initWidgets() {
    var widgets = document.querySelectorAll('.signdocs-signing-widget');
    widgets.forEach(initWidget);
  }

  function initWidget(container) {
    var config;
    try {
      config = JSON.parse(container.dataset.signdocsConfig);
    } catch (e) {
      return;
    }

    var btn = container.querySelector('.signdocs-sign-btn');
    var statusEl = container.querySelector('.signdocs-status');
    if (!btn) return;

    btn.addEventListener('click', function () {
      handleClick(container, config, btn, statusEl);
    });
  }

  function handleClick(container, config, btn, statusEl) {
    // Gather signer data
    var signerName = config.signerName || '';
    var signerEmail = config.signerEmail || '';

    if (config.showForm) {
      var nameInput = container.querySelector('.signdocs-field-name');
      var emailInput = container.querySelector('.signdocs-field-email');
      if (nameInput) signerName = nameInput.value.trim();
      if (emailInput) signerEmail = emailInput.value.trim();
    }

    if (!signerName) {
      showStatus(statusEl, config.i18n.nameRequired, true);
      return;
    }
    if (!signerEmail) {
      showStatus(statusEl, config.i18n.emailRequired, true);
      return;
    }

    btn.disabled = true;
    var origText = btn.textContent;
    btn.textContent = config.i18n.loading;
    hideStatus(statusEl);

    // For popup mode: pre-open the window synchronously to avoid popup blockers
    var preOpenedPopup = null;
    if (config.mode === 'popup') {
      preOpenedPopup = window.open('about:blank', 'signdocs-checkout', 'width=500,height=700,scrollbars=yes');
    }

    // Create signing session via WP AJAX
    var body = new URLSearchParams({
      action: 'signdocs_create_session',
      nonce: config.nonce,
      document_id: config.documentId,
      signer_name: signerName,
      signer_email: signerEmail,
      policy: config.policy,
      locale: config.locale,
      return_url: config.returnUrl,
    });

    fetch(config.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
      .then(function (res) { return res.json(); })
      .then(function (response) {
        if (!response.success) {
          throw new Error(response.data && response.data.message ? response.data.message : 'Unknown error');
        }

        var clientSecret = response.data.clientSecret;
        var sessionUrl = response.data.sessionUrl;

        if (!clientSecret && !sessionUrl) {
          throw new Error('No clientSecret or sessionUrl returned');
        }

        // Initialize the SignDocs JS SDK
        if (typeof SignDocsBrasil === 'undefined') {
          throw new Error('SignDocs JS SDK not loaded');
        }

        var sd = SignDocsBrasil.init({ locale: config.locale });

        if (config.mode === 'redirect') {
          // Full-page redirect — no popup concerns
          sd.redirect({ clientSecret: clientSecret });
          return;
        }

        if (config.mode === 'popup' && preOpenedPopup) {
          // Navigate the pre-opened popup to the signing URL
          // Build the signing page URL with the clientSecret as a query param
          var signingUrl = sessionUrl + (sessionUrl.indexOf('?') >= 0 ? '&' : '?') + 'cs=' + encodeURIComponent(clientSecret);
          preOpenedPopup.location.href = signingUrl;

          // Set up completion listener via SDK checkout
          // The SDK will detect the existing popup via PostMessage
          sd.checkout({
            clientSecret: clientSecret,
            onComplete: function (event) {
              btn.disabled = false;
              btn.textContent = origText;
              showStatus(statusEl, config.i18n.success, false);
              if (event.redirectUrl) {
                window.location.href = event.redirectUrl;
              }
            },
            onError: function (event) {
              btn.disabled = false;
              btn.textContent = origText;
              showStatus(statusEl, config.i18n.error + (event.message ? ' ' + event.message : ''), true);
            },
            onClose: function () {
              btn.disabled = false;
              btn.textContent = origText;
            },
          });
          return;
        }

        // Overlay mode or fallback popup via SDK
        sd.checkout({
          clientSecret: clientSecret,
          windowFeatures: config.mode === 'overlay' ? 'overlay' : undefined,
          onComplete: function (event) {
            btn.disabled = false;
            btn.textContent = origText;
            showStatus(statusEl, config.i18n.success, false);
            if (event.redirectUrl) {
              window.location.href = event.redirectUrl;
            }
          },
          onError: function (event) {
            btn.disabled = false;
            btn.textContent = origText;
            showStatus(statusEl, config.i18n.error + (event.message ? ' ' + event.message : ''), true);
          },
          onClose: function () {
            btn.disabled = false;
            btn.textContent = origText;
          },
        });
      })
      .catch(function (err) {
        btn.disabled = false;
        btn.textContent = origText;
        if (preOpenedPopup) {
          preOpenedPopup.close();
        }
        showStatus(statusEl, config.i18n.error + ' ' + err.message, true);
      });
  }

  function showStatus(el, text, isError) {
    if (!el) return;
    el.style.display = 'block';
    el.textContent = text;
    el.className = 'signdocs-status ' + (isError ? 'signdocs-status--error' : 'signdocs-status--success');
  }

  function hideStatus(el) {
    if (!el) return;
    el.style.display = 'none';
    el.textContent = '';
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWidgets);
  } else {
    initWidgets();
  }
})();

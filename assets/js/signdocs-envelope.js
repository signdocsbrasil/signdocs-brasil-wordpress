/**
 * Envelope compose screen: media picker + signer repeater.
 *
 * Rows are indexed by position in the table rather than by a running counter,
 * so removing a row and adding another cannot produce two inputs with the same
 * name — which PHP would collapse into one signer, silently dropping somebody
 * from the envelope.
 */
(function () {
  'use strict';

  var cfg = window.signdocsEnvelope || { i18n: {} };

  function reindex(tbody) {
    var rows = tbody.querySelectorAll('tr.signdocs-signer-row');
    Array.prototype.forEach.call(rows, function (row, i) {
      Array.prototype.forEach.call(row.querySelectorAll('input'), function (input) {
        input.name = input.name.replace(
          /signdocs_envelope_signers\[\d+\]/,
          'signdocs_envelope_signers[' + i + ']'
        );
      });
    });
  }

  function addRow(tbody) {
    var last = tbody.querySelector('tr.signdocs-signer-row');
    if (!last) return;

    var row = last.cloneNode(true);
    Array.prototype.forEach.call(row.querySelectorAll('input'), function (input) {
      input.value = '';
    });
    tbody.appendChild(row);
    reindex(tbody);

    var first = row.querySelector('input');
    if (first) first.focus();
  }

  document.addEventListener('DOMContentLoaded', function () {
    var table = document.getElementById('signdocs-envelope-signers');
    if (table) {
      var tbody = table.querySelector('tbody');

      var addBtn = document.getElementById('signdocs-envelope-add-signer');
      if (addBtn) {
        addBtn.addEventListener('click', function () {
          addRow(tbody);
        });
      }

      // Delegated: rows added after load need it too.
      tbody.addEventListener('click', function (ev) {
        if (!ev.target.classList.contains('signdocs-remove-signer')) return;
        ev.preventDefault();

        var rows = tbody.querySelectorAll('tr.signdocs-signer-row');
        // Keep one row so the table never becomes un-refillable: with no row
        // left there is nothing to clone from and the repeater is dead.
        if (rows.length <= 1) {
          Array.prototype.forEach.call(rows[0].querySelectorAll('input'), function (input) {
            input.value = '';
          });
          return;
        }
        ev.target.closest('tr').remove();
        reindex(tbody);
      });
    }

    var pick = document.getElementById('signdocs-envelope-pick');
    if (pick && window.wp && window.wp.media) {
      var frame = null;
      pick.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (frame) {
          frame.open();
          return;
        }
        frame = window.wp.media({
          title: cfg.i18n.chooseDocument,
          button: { text: cfg.i18n.useDocument },
          library: { type: 'application/pdf' },
          multiple: false
        });
        frame.on('select', function () {
          var doc = frame.state().get('selection').first().toJSON();
          document.getElementById('signdocs-envelope-document').value = doc.id;
          document.getElementById('signdocs-envelope-document-name').textContent =
            doc.filename || doc.title || '';
        });
        frame.open();
      });
    }
  });
})();

(function () {
  'use strict';

  var modalId = 'local-index-hotkey-modal';
  var lastFocusedElement = null;
  var isRequestInFlight = false;

  function isEditableElement(element) {
    if (!element) {
      return false;
    }

    var tagName = element.tagName ? element.tagName.toLowerCase() : '';

    return (
      tagName === 'input' ||
      tagName === 'textarea' ||
      tagName === 'select' ||
      element.isContentEditable
    );
  }

  function getModal() {
    return document.getElementById(modalId);
  }

  function ensureModal() {
    var existing = getModal();

    if (existing) {
      return existing;
    }

    var modal = document.createElement('div');
    modal.id = modalId;
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = [
      '<div class="local-index-hotkey__backdrop" data-local-index-close="true"></div>',
      '<section class="local-index-hotkey__dialog" role="dialog" aria-modal="true" aria-labelledby="local-index-hotkey-title" aria-describedby="local-index-hotkey-message">',
      '  <div class="local-index-hotkey__header">',
      '    <h2 id="local-index-hotkey-title">Content Index</h2>',
      '  </div>',
      '  <div class="local-index-hotkey__body">',
      '    <p id="local-index-hotkey-message"></p>',
      '    <p id="local-index-hotkey-details" class="local-index-hotkey__details"></p>',
      '  </div>',
      '  <div id="local-index-hotkey-actions" class="local-index-hotkey__actions"></div>',
      '</section>'
    ].join('');

    document.body.appendChild(modal);

    modal.addEventListener('click', function (event) {
      if (event.target.getAttribute('data-local-index-close') === 'true') {
        closeModal();
      }
    });

    return modal;
  }

  function setModalState(title, message, details, actions) {
    var modal = ensureModal();
    var titleElement = modal.querySelector('#local-index-hotkey-title');
    var messageElement = modal.querySelector('#local-index-hotkey-message');
    var detailsElement = modal.querySelector('#local-index-hotkey-details');
    var actionsElement = modal.querySelector('#local-index-hotkey-actions');

    titleElement.textContent = title;
    messageElement.textContent = message;
    detailsElement.textContent = details || '';

    actionsElement.innerHTML = '';

    actions.forEach(function (action) {
      var button;

      if (action.href) {
        button = document.createElement('a');
        button.href = action.href;
        button.className = action.className || 'btn btn-secondary';
        button.textContent = action.label;

        if (action.download) {
          button.setAttribute('download', '');
        }
      } else {
        button = document.createElement('button');
        button.type = 'button';
        button.className = action.className || 'btn btn-secondary';
        button.textContent = action.label;
        button.disabled = Boolean(action.disabled);

        if (typeof action.onClick === 'function') {
          button.addEventListener('click', action.onClick);
        }
      }

      actionsElement.appendChild(button);
    });

    return modal;
  }

  function openModal() {
    var modal = ensureModal();

    lastFocusedElement = document.activeElement;
    modal.classList.add('local-index-hotkey--open');
    modal.setAttribute('aria-hidden', 'false');

    window.setTimeout(function () {
      var focusTarget = modal.querySelector('button, a[href]');

      if (focusTarget) {
        focusTarget.focus();
      }
    }, 0);
  }

  function closeModal() {
    var modal = getModal();

    if (!modal || isRequestInFlight) {
      return;
    }

    modal.classList.remove('local-index-hotkey--open');
    modal.setAttribute('aria-hidden', 'true');

    if (
      lastFocusedElement &&
      typeof lastFocusedElement.focus === 'function'
    ) {
      lastFocusedElement.focus();
    }
  }

  function showConfirmation() {
    if (isRequestInFlight) {
      return;
    }

    setModalState(
      'Start a full content index now?',
      'This checks Videos, Books, and Audiobooks for newly added or updated files.',
      '',
      [
        {
          label: 'Cancel',
          className: 'btn btn-secondary',
          onClick: closeModal
        },
        {
          label: 'Start Index',
          className: 'btn btn-danger',
          onClick: startIndex
        }
      ]
    );

    openModal();
  }

  function showRunning() {
    setModalState(
      'Checking and indexing new content…',
      'Please keep this page open until the index completes.',
      'The scan may take several minutes for a large content library.',
      [
        {
          label: 'Indexing…',
          className: 'btn btn-secondary',
          disabled: true
        }
      ]
    );
  }

  function showFailure(message) {
    isRequestInFlight = false;

    setModalState(
      'Index failed',
      message || 'The content index could not be completed.',
      '',
      [
        {
          label: 'Dismiss',
          className: 'btn btn-secondary',
          onClick: closeModal
        }
      ]
    );
  }

  function showComplete(result) {
    isRequestInFlight = false;

    var newCount = Number(result.new || 0);
    var updatedCount = Number(result.updated || 0);
    var totalCount = Number(result.total || 0);
    var skippedCount = Number(result.skipped || 0);
    var thumbnailCount = Number(result.thumbnails_generated || 0);
    var details = 'Scanned: ' + totalCount + ' | Skipped: ' + skippedCount;

    if (thumbnailCount > 0) {
      details += ' | Thumbnails generated: ' + thumbnailCount;
    }

    if (newCount === 0 && updatedCount === 0) {
      setModalState(
        'Index complete — no new or updated content was found.',
        'Your content index is already up to date.',
        details,
        [
          {
            label: 'Dismiss',
            className: 'btn btn-secondary',
            onClick: closeModal
          }
        ]
      );

      return;
    }

    var actions = [];

    if (result.verification_report_url) {
      actions.push({
        label: 'Download verification CSV',
        href: result.verification_report_url,
        className: 'btn btn-primary',
        download: true
      });
    }

    actions.push({
      label: 'Dismiss',
      className: 'btn btn-secondary',
      onClick: closeModal
    });

    setModalState(
      'Index complete',
      'New: ' + newCount + ' | Updated: ' + updatedCount,
      details,
      actions
    );
  }

  function parseResponse(response) {
    return response.text().then(function (text) {
      var data;

      try {
        data = JSON.parse(text);
      } catch (error) {
        data = {
          success: false,
          error: 'The server returned an unexpected response.'
        };
      }

      if (!response.ok) {
        data.success = false;

        if (!data.error) {
          data.error = 'The server could not complete the index request.';
        }
      }

      return data;
    });
  }

  function startIndex() {
    if (isRequestInFlight) {
      return;
    }

    isRequestInFlight = true;
    showRunning();

    fetch('/api/local-index-start.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json'
      }
    })
      .then(parseResponse)
      .then(function (result) {
        if (!result.success) {
          showFailure(result.error);
          return;
        }

        showComplete(result);
      })
      .catch(function () {
        showFailure(
          'The browser could not reach the local indexing service. '
          + 'Check that the EduTek app is running and try again.'
        );
      });
  }

  document.addEventListener('keydown', function (event) {
    var key = String(event.key || '').toLowerCase();

    if (event.key === 'Escape' && getModal() && getModal().classList.contains('local-index-hotkey--open')) {
      closeModal();
      return;
    }

    if (
      event.ctrlKey &&
      event.altKey &&
      event.shiftKey &&
      key === 'i' &&
      !isEditableElement(document.activeElement) &&
      !isRequestInFlight
    ) {
      event.preventDefault();
      showConfirmation();
    }
  });
}());
/**
 * Student Assignments View – Redesign (FRE-48)
 *
 * Full-width assignment cards with progress rings, expand/collapse,
 * and numbered step list for content items.
 *
 * Vanilla JS only. No frameworks, no build tools.
 */

(function () {
  'use strict';

  var API_PROGRESS = (window.EDUTEK_BASE || '') + '/api/lesson_progress.php';
  var HEARTBEAT_URL = (window.EDUTEK_BASE || '') + '/api/progress_heartbeat.php';
  var heartbeatTimer = null;

  /* ── Helpers ───────────────────────────────────────────────────────── */

  function esc(str) {
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function secondsToHM(sec) {
    sec = Math.max(0, parseInt(sec, 10) || 0);
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    if (h > 0) return h + 'h ' + m + 'm';
    return m + 'm';
  }

  function formatDate(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr);
    return d.toLocaleDateString('en', { month: 'short', day: 'numeric' });
  }

  function formatPosition(sec) {
    sec = Math.max(0, parseInt(sec, 10) || 0);
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  /* ── SVG Helpers ──────────────────────────────────────────────────── */

  function progressRingSVG(pct, size) {
    size = size || 56;
    var r = (size / 2) - 5;
    var circ = 2 * Math.PI * r;
    var offset = circ - (pct / 100) * circ;
    var color = pct >= 100 ? '#27ae60' : pct >= 80 ? '#27ae60' : pct >= 40 ? '#f39c12' : '#4ECDC4';
    var fontSize = size <= 48 ? 10 : 12;

    return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">' +
      '<circle cx="' + (size/2) + '" cy="' + (size/2) + '" r="' + r + '" fill="none" stroke="#e8e4df" stroke-width="4"/>' +
      '<circle cx="' + (size/2) + '" cy="' + (size/2) + '" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="4" ' +
        'stroke-dasharray="' + circ + '" stroke-dashoffset="' + offset + '" stroke-linecap="round" transform="rotate(-90 ' + (size/2) + ' ' + (size/2) + ')"/>' +
      '<text x="' + (size/2) + '" y="' + ((size/2) + 4) + '" text-anchor="middle" font-size="' + fontSize + '" font-weight="700" fill="#1a1a2e">' + pct + '%</text>' +
    '</svg>';
  }

  function chevronSVG() {
    return '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }

  function checkSVG() {
    return '<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M4.5 9.5l3 3 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }

  /* ── Content type icon SVGs ──────────────────────────────────────── */

  function typeIconSVG(type) {
    var icons = {
      video: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><polygon points="5,3 19,12 5,21" fill="currentColor"/></svg>',
      audio: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M9 18V5l12-2v13" stroke="currentColor" stroke-width="2" fill="none"/><circle cx="6" cy="18" r="3" fill="currentColor"/><circle cx="18" cy="16" r="3" fill="currentColor"/></svg>',
      book: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M4 19.5A2.5 2.5 0 016.5 17H20" stroke="currentColor" stroke-width="2"/><path d="M4 4.5A2.5 2.5 0 016.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15z" stroke="currentColor" stroke-width="2" fill="none"/></svg>',
      pdf: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" stroke="currentColor" stroke-width="2" fill="none"/><path d="M14 2v6h6" stroke="currentColor" stroke-width="2"/></svg>',
      interactive: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="2" y="3" width="20" height="14" rx="2" stroke="currentColor" stroke-width="2" fill="none"/><path d="M8 21h8M12 17v4" stroke="currentColor" stroke-width="2"/></svg>'
    };
    return icons[type] || icons.book;
  }

  function contentTypeLabel(type) {
    var labels = {
      video: 'Video',
      audio: 'Audio',
      book: 'Book',
      pdf: 'PDF',
      interactive: 'Interactive'
    };
    return labels[type] || type || 'Content';
  }

  function contentTypeBadgeClass(type) {
    var map = { video: 'video', audio: 'audio', book: 'book', pdf: 'pdf', interactive: 'interactive' };
    return 'sa-type-badge--' + (map[type] || 'default');
  }

  /* ── Init ──────────────────────────────────────────────────────────── */

  function init() {
    var root = document.getElementById('my-assignments-root');
    if (!root) return;

    fetch(API_PROGRESS + '?action=my_assignments')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var assignments = data.assignments || [];
        renderAssignments(root, assignments);
      })
      .catch(function () {
        root.innerHTML = '';
      });
  }

  /* ── Render Assignments ────────────────────────────────────────────── */

  function renderAssignments(root, assignments) {
    if (assignments.length === 0) {
      root.innerHTML =
        '<div class="sa-header">' +
          '<h2 class="sa-title">My Assignments</h2>' +
        '</div>' +
        '<div class="sa-empty">' +
          '<div class="sa-empty-icon">' +
            '<svg width="48" height="48" viewBox="0 0 48 48" fill="none"><rect x="8" y="6" width="32" height="36" rx="4" stroke="#b0aaa4" stroke-width="2.5" fill="none"/><path d="M16 18h16M16 26h10" stroke="#b0aaa4" stroke-width="2.5" stroke-linecap="round"/></svg>' +
          '</div>' +
          '<div class="sa-empty-title">No assignments yet</div>' +
          '<div class="sa-empty-text">Your teacher will assign lesson plans here.</div>' +
        '</div>';
      return;
    }

    var html =
      '<div class="sa-header">' +
        '<h2 class="sa-title">My Assignments</h2>' +
        '<span class="sa-count-badge">' + assignments.length + '</span>' +
      '</div>' +
      '<div class="sa-stack" role="list">';

    assignments.forEach(function (a) {
      var pct = a.progress_pct || 0;
      var isComplete = (pct >= 100);
      var completed = a.completed_items || 0;
      var total = a.item_count || 0;

      /* Mode badge */
      var modeBadge = '';
      if (a.mode === 'guided') {
        modeBadge = '<span class="sa-badge sa-badge--guided">Guided</span>';
      } else if (a.mode === 'individual') {
        modeBadge = '<span class="sa-badge sa-badge--individual">Individual</span>';
      }

      /* Due date */
      var dueLine = '';
      if (a.due_date) {
        var dueDate = new Date(a.due_date);
        var now = new Date();
        var daysLeft = Math.ceil((dueDate - now) / 86400000);
        var dueClass = daysLeft <= 1 ? 'sa-due--urgent' : daysLeft <= 3 ? 'sa-due--soon' : '';
        dueLine = '<span class="sa-due ' + dueClass + '">Due ' + formatDate(a.due_date) + '</span>';
      }

      /* Action button */
      var actionBtn = '';
      if (isComplete) {
        actionBtn = '<span class="sa-action-btn sa-action-btn--complete">' + checkSVG() + ' Completed</span>';
      } else if (completed > 0 && a.next_content_id) {
        actionBtn = '<button class="sa-action-btn sa-primary-action" ' +
          'data-aid="' + a.assignment_id + '" ' +
          'data-cid="' + esc(a.next_content_id) + '" ' +
          'data-pos="' + (a.next_position || 0) + '">Continue</button>';
      } else {
        actionBtn = '<button class="sa-action-btn sa-primary-action" ' +
          'data-aid="' + a.assignment_id + '" ' +
          'data-cid="' + esc(a.next_content_id || '') + '" ' +
          'data-pos="0">Start</button>';
      }

      html +=
        '<div class="sa-card" role="listitem" data-aid="' + a.assignment_id + '" data-plan="' + (a.lesson_plan_id || '') + '">' +
          '<div class="sa-card-header" role="button" tabindex="0" aria-expanded="false" aria-label="Expand ' + esc(a.plan_title) + '">' +
            '<div class="sa-ring">' + progressRingSVG(pct) + '</div>' +
            '<div class="sa-card-info">' +
              '<div class="sa-card-title">' + esc(a.plan_title) + '</div>' +
              '<div class="sa-card-meta">' +
                '<span class="sa-card-teacher">From ' + esc(a.teacher_name) + '</span>' +
                '<span class="sa-card-items-count">' + completed + '/' + total + ' completed</span>' +
                dueLine +
              '</div>' +
            '</div>' +
            '<div class="sa-card-right">' +
              modeBadge +
              actionBtn +
              '<span class="sa-chevron">' + chevronSVG() + '</span>' +
            '</div>' +
          '</div>' +
          '<div class="sa-items-panel">' +
            '<div class="sa-items-inner">' +
              '<div class="sa-items-loading">Loading items...</div>' +
            '</div>' +
          '</div>' +
        '</div>';
    });

    html += '</div>';
    root.innerHTML = html;

    /* Bind events */
    root.querySelectorAll('.sa-card-header').forEach(function (header) {
      header.addEventListener('click', function (e) {
        /* Don't toggle if clicking the primary action button */
        if (e.target.closest('.sa-primary-action')) return;
        toggleCard(header.closest('.sa-card'));
      });
      header.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          if (!e.target.closest('.sa-primary-action')) {
            toggleCard(header.closest('.sa-card'));
          }
        }
      });
    });

    root.querySelectorAll('.sa-primary-action').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        navigateToContent(btn);
      });
    });
  }

  /* ── Toggle Card Expand/Collapse ──────────────────────────────────── */

  function toggleCard(card) {
    var isExpanded = card.classList.contains('sa-card--expanded');

    if (isExpanded) {
      card.classList.remove('sa-card--expanded');
      card.querySelector('.sa-card-header').setAttribute('aria-expanded', 'false');
      return;
    }

    card.classList.add('sa-card--expanded');
    card.querySelector('.sa-card-header').setAttribute('aria-expanded', 'true');

    /* Load items if not already loaded */
    var inner = card.querySelector('.sa-items-inner');
    if (inner.querySelector('.sa-items-loading')) {
      var aid = card.getAttribute('data-aid');
      loadItems(inner, aid);
    }
  }

  /* ── Load Items ───────────────────────────────────────────────────── */

  function loadItems(container, aid) {
    var userId = document.body.getAttribute('data-user-id') || '';

    fetch(API_PROGRESS + '?action=student_detail&assignment_id=' + aid + '&student_id=' + userId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var items = data.items || [];
        if (items.length === 0) {
          container.innerHTML = '<div class="sa-items-empty">No items in this assignment.</div>';
          return;
        }

        /* Find the first non-completed item (current/next) */
        var currentIdx = -1;
        for (var i = 0; i < items.length; i++) {
          if (items[i].status !== 'completed') {
            currentIdx = i;
            break;
          }
        }

        var html = '';
        items.forEach(function (item, idx) {
          var stepNum = idx + 1;
          var isCurrent = (idx === currentIdx);
          var statusClass = 'sa-step--' + item.status.replace('_', '-');
          if (isCurrent && item.status !== 'completed') {
            statusClass += ' sa-step--current';
          }

          /* Thumbnail or type icon */
          var visual = '';
          if (item.thumbnail) {
            visual = '<img class="sa-step-thumb" src="' + esc(item.thumbnail) + '" alt="" loading="lazy">';
          } else {
            visual = '<div class="sa-step-type-icon">' + typeIconSVG(item.content_type) + '</div>';
          }

          /* Step number content — checkmark for completed */
          var numContent = item.status === 'completed' ? checkSVG() : String(stepNum);

          /* Type badge */
          var typeBadge = '<span class="sa-type-badge ' + contentTypeBadgeClass(item.content_type) + '">' +
            contentTypeLabel(item.content_type) + '</span>';

          /* Duration */
          var durationText = '';
          if (item.duration && item.duration > 0) {
            durationText = '<span>' + secondsToHM(item.duration) + '</span>';
          }

          /* Status text */
          var statusText = '';
          var statusTextClass = 'sa-step-status--' + item.status.replace('_', '-');

          /* Right-side action */
          var rightAction = '';

          if (item.status === 'completed') {
            statusText = '<span class="sa-step-status sa-step-status--completed">' + checkSVG() + ' Completed</span>';
            rightAction = '';
          } else if (item.status === 'in_progress') {
            var resumeLabel = 'Resume';
            if (item.last_position && item.last_position > 0) {
              resumeLabel = 'Resume at ' + formatPosition(item.last_position);
            }
            statusText = '<span class="sa-step-status sa-step-status--in-progress">In Progress</span>';
            rightAction = '<button class="sa-step-btn" ' +
              'data-aid="' + aid + '" ' +
              'data-cid="' + esc(item.content_id) + '" ' +
              'data-pos="' + (item.last_position || 0) + '" ' +
              'data-dur="' + (item.duration || 0) + '">' + esc(resumeLabel) + '</button>';
          } else {
            statusText = '';
            rightAction = '<button class="sa-step-btn" ' +
              'data-aid="' + aid + '" ' +
              'data-cid="' + esc(item.content_id) + '" ' +
              'data-pos="0" ' +
              'data-dur="' + (item.duration || 0) + '">Start</button>';
          }

          html +=
            '<div class="sa-step ' + statusClass + '">' +
              '<div class="sa-step-num">' + numContent + '</div>' +
              visual +
              '<div class="sa-step-info">' +
                '<div class="sa-step-title">' + esc(item.title) + '</div>' +
                '<div class="sa-step-meta">' + typeBadge + durationText + '</div>' +
              '</div>' +
              '<div class="sa-step-right">' + statusText + rightAction + '</div>' +
            '</div>';
        });

        container.innerHTML = html;

        /* Bind step buttons */
        container.querySelectorAll('.sa-step-btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            navigateToContent(btn);
          });
        });
      })
      .catch(function () {
        container.innerHTML = '<div class="sa-items-empty">Failed to load items.</div>';
      });
  }

  /* ── Navigate to Content ──────────────────────────────────────────── */

  function navigateToContent(btn) {
    var cid = btn.getAttribute('data-cid');
    var pos = parseInt(btn.getAttribute('data-pos'), 10) || 0;
    var assignId = btn.getAttribute('data-aid');

    if (!cid) return;

    sessionStorage.setItem('edupak_assignment_id', assignId);
    sessionStorage.setItem('edupak_assignment_cid', cid);
    sessionStorage.setItem('edupak_resume_pos', String(pos));

    // Build watch URL with content_id and optional lesson plan context
    var url = '/watch.php?content_id=' + encodeURIComponent(cid) + '&t=' + pos;
    var card = btn.closest('.sa-card');
    var planId = card ? card.getAttribute('data-plan') : '';
    if (planId) {
      url += '&plan=' + encodeURIComponent(planId);
    }
    window.location.href = url;
  }

  /* ── Heartbeat for assigned content ─────────────────────────────── */

  function startHeartbeat(assignmentId, contentId, videoEl) {
    stopHeartbeat();

    heartbeatTimer = setInterval(function () {
      if (!videoEl || videoEl.paused) return;

      var payload = {
        assignment_id: parseInt(assignmentId, 10),
        content_id: contentId,
        last_position: Math.floor(videoEl.currentTime || 0),
        duration_seconds: Math.floor(videoEl.duration || 0),
      };

      if (navigator.sendBeacon) {
        navigator.sendBeacon(HEARTBEAT_URL, JSON.stringify(payload));
      } else {
        fetch(HEARTBEAT_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        }).catch(function () {});
      }
    }, 30000);
  }

  function stopHeartbeat() {
    if (heartbeatTimer) {
      clearInterval(heartbeatTimer);
      heartbeatTimer = null;
    }
  }

  /* ── Public API ────────────────────────────────────────────────────── */

  window.StudentAssignments = {
    init: init,
    startHeartbeat: startHeartbeat,
    stopHeartbeat: stopHeartbeat,
  };

  if (document.getElementById('my-assignments-root')) {
    init();
  }
})();

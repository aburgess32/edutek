/**
 * Student Assignments View (FRE-52)
 *
 * Renders "My Assignments" section on the student homepage.
 * Shows assigned lesson plans with progress bars, Continue button,
 * and video resume functionality.
 *
 * Vanilla JS only. No frameworks, no build tools.
 */

(function () {
  'use strict';

  var API_PROGRESS = '/api/lesson_progress.php';
  var HEARTBEAT_URL = '/api/progress_heartbeat.php';
  var heartbeatTimer = null;

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

  function init() {
    var root = document.getElementById('my-assignments-root');
    if (!root) return;

    fetch(API_PROGRESS + '?action=my_assignments')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var assignments = data.assignments || [];
        if (assignments.length === 0) {
          root.style.display = 'none';
          return;
        }
        renderAssignments(root, assignments);
      })
      .catch(function () {
        root.style.display = 'none';
      });
  }

  function renderAssignments(root, assignments) {
    var html = '<div class="sa-header">' +
      '<h2 class="sa-title">My Assignments</h2>' +
      '</div>' +
      '<div class="sa-scroll" role="list">';

    assignments.forEach(function (a) {
      var pct = a.progress_pct || 0;
      var barColor = pct >= 80 ? '#27ae60' : pct >= 40 ? '#f39c12' : '#4ECDC4';
      var isComplete = (pct >= 100);

      var modeBadge = a.mode === 'guided'
        ? '<span class="sa-badge sa-badge--guided">Guided</span>'
        : '';

      var dueLine = '';
      if (a.due_date) {
        var dueDate = new Date(a.due_date);
        var now = new Date();
        var daysLeft = Math.ceil((dueDate - now) / 86400000);
        var dueClass = daysLeft <= 1 ? 'sa-due--urgent' : daysLeft <= 3 ? 'sa-due--soon' : '';
        dueLine = '<span class="sa-due ' + dueClass + '">Due ' + formatDate(a.due_date) + '</span>';
      }

      var continueBtn = '';
      if (!isComplete && a.next_content_id) {
        continueBtn = '<button class="sa-continue-btn" ' +
          'data-aid="' + a.assignment_id + '" ' +
          'data-cid="' + esc(a.next_content_id) + '" ' +
          'data-pos="' + (a.next_position || 0) + '">Continue</button>';
      } else if (isComplete) {
        continueBtn = '<span class="sa-complete-label">Completed</span>';
      }

      html += '<div class="sa-card" role="listitem">' +
        '<div class="sa-card-body">' +
          '<div class="sa-card-title">' + esc(a.plan_title) + '</div>' +
          '<div class="sa-card-meta">' +
            '<span class="sa-card-teacher">From ' + esc(a.teacher_name) + '</span>' +
            modeBadge + dueLine +
          '</div>' +
          '<div class="sa-progress">' +
            '<div class="sa-progress-bar">' +
              '<div class="sa-progress-fill" style="width:' + pct + '%;background:' + barColor + '"></div>' +
            '</div>' +
            '<span class="sa-progress-label">' + a.completed_items + '/' + a.item_count + ' items &middot; ' + pct + '%</span>' +
          '</div>' +
          '<div class="sa-card-actions">' + continueBtn + '</div>' +
        '</div>' +
      '</div>';
    });

    html += '</div>';
    root.innerHTML = html;

    // Bind Continue buttons — expand to show item list
    root.querySelectorAll('.sa-continue-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var aid = btn.getAttribute('data-aid');
        var card = btn.closest('.sa-card');
        toggleItemList(card, aid);
      });
    });
  }

  function toggleItemList(card, aid) {
    var existing = card.querySelector('.sa-item-list');
    if (existing) {
      existing.remove();
      return;
    }

    var listEl = document.createElement('div');
    listEl.className = 'sa-item-list';
    listEl.innerHTML = '<div class="sa-item-loading">Loading...</div>';
    card.appendChild(listEl);

    var userId = document.body.getAttribute('data-user-id') || '';

    fetch(API_PROGRESS + '?action=student_detail&assignment_id=' + aid + '&student_id=' + userId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var items = data.items || [];
        if (items.length === 0) {
          listEl.innerHTML = '<div class="sa-item-empty">No items in this assignment.</div>';
          return;
        }

        var html = '';
        items.forEach(function (item) {
          var statusClass = 'sa-item--' + item.status.replace('_', '-');
          var statusLabel = item.status === 'completed' ? 'Done'
            : item.status === 'in_progress' ? 'In Progress'
            : 'Not Started';

          var resumeBtn = '';
          if (item.status !== 'completed') {
            resumeBtn = '<button class="sa-item-play" ' +
              'data-aid="' + aid + '" ' +
              'data-cid="' + esc(item.content_id) + '" ' +
              'data-pos="' + (item.last_position || 0) + '" ' +
              'data-dur="' + (item.duration || 0) + '">' +
              (item.status === 'in_progress' ? 'Resume' : 'Start') + '</button>';
          }

          html += '<div class="sa-item ' + statusClass + '">' +
            '<div class="sa-item-info">' +
              '<span class="sa-item-title">' + esc(item.title) + '</span>' +
              '<span class="sa-item-status">' + statusLabel + ' &middot; ' + item.progress_pct + '%</span>' +
            '</div>' +
            resumeBtn +
          '</div>';
        });

        listEl.innerHTML = html;

        // Bind play/resume buttons
        listEl.querySelectorAll('.sa-item-play').forEach(function (playBtn) {
          playBtn.addEventListener('click', function () {
            var cid = playBtn.getAttribute('data-cid');
            var pos = parseInt(playBtn.getAttribute('data-pos'), 10) || 0;
            var assignId = playBtn.getAttribute('data-aid');
            // Navigate to content with resume position
            // Store assignment context for heartbeat tracking
            sessionStorage.setItem('edupak_assignment_id', assignId);
            sessionStorage.setItem('edupak_assignment_cid', cid);
            sessionStorage.setItem('edupak_resume_pos', String(pos));
            // Navigate to the content — use watch.php with content_id param
            window.location.href = '/watch.php?content_id=' + encodeURIComponent(cid) + '&t=' + pos;
          });
        });
      })
      .catch(function () {
        listEl.innerHTML = '<div class="sa-item-empty">Failed to load items.</div>';
      });
  }

  // ─── Heartbeat for assigned content ─────────────────────────────────────────
  // Called from the video player page to report progress on assigned content

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

  window.StudentAssignments = {
    init: init,
    startHeartbeat: startHeartbeat,
    stopHeartbeat: stopHeartbeat,
  };

  // Auto-initialize when the DOM element exists
  if (document.getElementById('my-assignments-root')) {
    init();
  }
})();

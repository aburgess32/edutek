/**
 * Teacher Assignments Tab (FRE-50 / FRE-52)
 *
 * Manages the Assignments tab in teacher.php:
 * - Lists all assignments with status filters
 * - Shows per-assignment progress with expandable student breakdown
 * - Drill-down to item-by-item view
 * - Guided mode live class panel with 5s polling
 *
 * Vanilla JS only. No frameworks, no build tools.
 */

(function () {
  'use strict';

  var API_ASSIGNMENTS = '/api/lesson_assignments.php';
  var API_PROGRESS = '/api/lesson_progress.php';
  var root = null;
  var guidedPollTimer = null;

  // ─── SVG Icons ──────────────────────────────────────────────────────────────

  var ICONS = {
    assignment: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>',
    clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
    alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>',
    chevron: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>',
  };

  function icon(name, w, h) {
    w = w || 18; h = h || 18;
    return '<span style="display:inline-flex;align-items:center;width:' + w + 'px;height:' + h + 'px">' + (ICONS[name] || '') + '</span>';
  }

  // ─── Helpers ────────────────────────────────────────────────────────────────

  function esc(str) {
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function fetchJSON(url) {
    return fetch(url).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  function secondsToHM(sec) {
    sec = Math.max(0, parseInt(sec, 10) || 0);
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    if (h > 0) return h + 'h ' + m + 'm';
    return m + 'm';
  }

  function relativeDate(dateStr) {
    if (!dateStr) return '--';
    var d = new Date(dateStr);
    var now = new Date();
    var diffDay = Math.floor((now - d) / 86400000);
    if (diffDay < 1) return 'Today';
    if (diffDay === 1) return 'Yesterday';
    if (diffDay < 7) return diffDay + 'd ago';
    return d.toLocaleDateString('en', { month: 'short', day: 'numeric' });
  }

  function formatDate(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr);
    return d.toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  // ─── Completion ring SVG ────────────────────────────────────────────────────

  function completionRing(pct, size) {
    size = size || 48;
    var r = (size - 6) / 2;
    var circ = 2 * Math.PI * r;
    var offset = circ - (pct / 100) * circ;
    var color = pct >= 80 ? 'var(--green)' : pct >= 40 ? 'var(--amber)' : 'var(--red)';

    return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '" class="ta-ring">' +
      '<circle cx="' + size / 2 + '" cy="' + size / 2 + '" r="' + r + '" fill="none" stroke="var(--teacher-border)" stroke-width="4"/>' +
      '<circle cx="' + size / 2 + '" cy="' + size / 2 + '" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="4" ' +
        'stroke-dasharray="' + circ + '" stroke-dashoffset="' + offset + '" stroke-linecap="round" transform="rotate(-90 ' + size / 2 + ' ' + size / 2 + ')"/>' +
      '<text x="' + size / 2 + '" y="' + (size / 2 + 4) + '" text-anchor="middle" font-size="12" font-weight="700" fill="var(--teacher-text)">' + pct + '%</text>' +
    '</svg>';
  }

  // ─── Main render ────────────────────────────────────────────────────────────

  function render() {
    if (!root) return;

    root.innerHTML =
      '<div class="ta-header">' +
        '<h2 class="ta-title">' + icon('assignment') + ' Assignments</h2>' +
        '<div class="ta-header-right">' +
          '<div class="ta-filters">' +
            '<button class="ta-filter-btn ta-filter-btn--active" data-filter="active">Active</button>' +
            '<button class="ta-filter-btn" data-filter="completed">Completed</button>' +
            '<button class="ta-filter-btn" data-filter="archived">Archived</button>' +
            '<button class="ta-filter-btn" data-filter="">All</button>' +
          '</div>' +
          '<button class="lb-btn lb-btn-accent ta-new-assign-btn" id="ta-new-assign-btn">+ New Assignment</button>' +
        '</div>' +
      '</div>' +
      '<div class="ta-list" id="ta-list">' +
        '<div class="ta-loading"><div class="dash-spinner"></div> Loading assignments...</div>' +
      '</div>';

    // Bind filter buttons
    root.querySelectorAll('.ta-filter-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        root.querySelectorAll('.ta-filter-btn').forEach(function (b) { b.classList.remove('ta-filter-btn--active'); });
        btn.classList.add('ta-filter-btn--active');
        loadAssignments(btn.getAttribute('data-filter'));
      });
    });

    // Bind "+ New Assignment" button
    var newBtn = document.getElementById('ta-new-assign-btn');
    if (newBtn) {
      newBtn.addEventListener('click', function () { openNewAssignModal(); });
    }

    loadAssignments('active');
  }

  function loadAssignments(statusFilter) {
    var url = API_ASSIGNMENTS + '?action=list';
    if (statusFilter) url += '&status=' + encodeURIComponent(statusFilter);

    fetchJSON(url).then(function (data) {
      var listEl = document.getElementById('ta-list');
      if (!listEl) return;

      var assignments = data.assignments || [];
      if (assignments.length === 0) {
        listEl.innerHTML =
          '<div class="ta-empty">' +
            '<div class="ta-empty-icon">' + icon('assignment', 48, 48) + '</div>' +
            '<h3>No assignments yet</h3>' +
            '<p>Assign a lesson plan to your students to get started.</p>' +
            '<button class="lb-btn lb-btn-accent ta-empty-assign-btn" id="ta-empty-assign-btn">+ New Assignment</button>' +
          '</div>';
        var emptyBtn = document.getElementById('ta-empty-assign-btn');
        if (emptyBtn) {
          emptyBtn.addEventListener('click', function () { openNewAssignModal(); });
        }
        return;
      }

      listEl.innerHTML = '';
      assignments.forEach(function (a) {
        listEl.innerHTML += renderAssignmentCard(a);
      });

      // Bind expand toggles
      listEl.querySelectorAll('.ta-card-header').forEach(function (header) {
        header.addEventListener('click', function () {
          var card = header.closest('.ta-card');
          var aid = card.getAttribute('data-aid');
          var body = card.querySelector('.ta-card-body');
          var isOpen = card.classList.contains('ta-card--open');

          if (isOpen) {
            card.classList.remove('ta-card--open');
            body.innerHTML = '';
            if (guidedPollTimer) { clearInterval(guidedPollTimer); guidedPollTimer = null; }
          } else {
            card.classList.add('ta-card--open');
            loadAssignmentDetail(aid, body, card.getAttribute('data-mode'));
          }
        });
      });

      // Bind action buttons
      listEl.querySelectorAll('.ta-action-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          var action = btn.getAttribute('data-action');
          var aid = btn.getAttribute('data-aid');
          handleAction(action, aid);
        });
      });
    }).catch(function () {
      var listEl = document.getElementById('ta-list');
      if (listEl) listEl.innerHTML = '<div class="ta-error">Failed to load assignments.</div>';
    });
  }

  function renderAssignmentCard(a) {
    var modeBadge = a.mode === 'guided'
      ? '<span class="ta-badge ta-badge--guided">Guided</span>'
      : '<span class="ta-badge ta-badge--individual">Individual</span>';

    var statusBadge = '<span class="ta-badge ta-badge--' + a.status + '">' +
      a.status.charAt(0).toUpperCase() + a.status.slice(1) + '</span>';

    var targetLabel = a.assigned_to_name
      ? esc(a.assigned_to_name)
      : 'Whole class';

    var dueDateLabel = a.due_date ? 'Due: ' + formatDate(a.due_date) : '';

    var actions = '';
    if (a.status === 'active') {
      actions =
        '<button class="ta-action-btn" data-action="complete" data-aid="' + a.id + '" title="Mark completed">Complete</button>' +
        '<button class="ta-action-btn ta-action-btn--danger" data-action="archive" data-aid="' + a.id + '" title="Archive">Archive</button>';
    } else if (a.status === 'completed') {
      actions =
        '<button class="ta-action-btn ta-action-btn--danger" data-action="archive" data-aid="' + a.id + '" title="Archive">Archive</button>';
    }

    return '<div class="ta-card" data-aid="' + a.id + '" data-mode="' + a.mode + '">' +
      '<div class="ta-card-header">' +
        '<div class="ta-card-left">' +
          '<div class="ta-card-chevron">' + icon('chevron') + '</div>' +
          '<div class="ta-card-info">' +
            '<div class="ta-card-title">' + esc(a.plan_title) + '</div>' +
            '<div class="ta-card-meta">' +
              modeBadge + statusBadge +
              '<span class="ta-card-target">' + icon('users', 14, 14) + ' ' + targetLabel + '</span>' +
              '<span class="ta-card-items">' + a.item_count + ' items</span>' +
              (dueDateLabel ? '<span class="ta-card-due">' + icon('clock', 14, 14) + ' ' + dueDateLabel + '</span>' : '') +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div class="ta-card-actions">' + actions + '</div>' +
      '</div>' +
      '<div class="ta-card-body"></div>' +
    '</div>';
  }

  function loadAssignmentDetail(aid, bodyEl, mode) {
    bodyEl.innerHTML = '<div class="ta-loading"><div class="dash-spinner"></div> Loading progress...</div>';

    if (mode === 'guided') {
      loadGuidedLive(aid, bodyEl);
      guidedPollTimer = setInterval(function () {
        var card = document.querySelector('.ta-card[data-aid="' + aid + '"]');
        if (card && card.classList.contains('ta-card--open')) {
          loadGuidedLive(aid, bodyEl);
        } else {
          clearInterval(guidedPollTimer);
          guidedPollTimer = null;
        }
      }, 5000);
      return;
    }

    fetchJSON(API_PROGRESS + '?action=summary&assignment_id=' + aid).then(function (data) {
      renderProgressSummary(bodyEl, data, aid);
    }).catch(function () {
      bodyEl.innerHTML = '<div class="ta-error">Failed to load progress data.</div>';
    });
  }

  function renderProgressSummary(bodyEl, data, aid) {
    var html = '<div class="ta-progress-summary">' +
      '<div class="ta-progress-overview">' +
        completionRing(data.overall_pct, 64) +
        '<div class="ta-progress-stats">' +
          '<div class="ta-stat"><strong>' + data.completed_count + '</strong> / ' + data.total_students + ' students completed</div>' +
          '<div class="ta-stat">' + data.total_items + ' content items per student</div>' +
        '</div>' +
      '</div>';

    // At-risk students
    var atRisk = (data.students || []).filter(function (s) { return s.at_risk; });
    if (atRisk.length > 0) {
      html += '<div class="ta-at-risk">' +
        '<div class="ta-at-risk-header">' + icon('alert') + ' At Risk (' + atRisk.length + ')</div>';
      atRisk.forEach(function (s) {
        html += '<div class="ta-at-risk-item">' +
          '<span class="ta-student-dot" style="background:' + esc(s.avatar_color) + '"></span>' +
          esc(s.display_name) +
          '<span class="ta-at-risk-detail">' + s.progress_pct + '% &middot; ' + s.days_inactive + 'd inactive</span>' +
        '</div>';
      });
      html += '</div>';
    }

    // Student breakdown
    html += '<div class="ta-student-breakdown">' +
      '<div class="ta-breakdown-header">Student Progress</div>';

    (data.students || []).forEach(function (s) {
      var barColor = s.is_complete ? 'var(--green)' : s.at_risk ? 'var(--red)' : 'var(--accent)';
      html += '<div class="ta-student-row" data-sid="' + s.student_id + '" data-aid="' + aid + '">' +
        '<div class="ta-student-info">' +
          '<span class="ta-student-dot" style="background:' + esc(s.avatar_color) + '"></span>' +
          '<span class="ta-student-name">' + esc(s.display_name) + '</span>' +
          (s.at_risk ? '<span class="ta-at-risk-badge">At Risk</span>' : '') +
          (s.is_complete ? '<span class="ta-complete-badge">' + icon('check', 14, 14) + ' Done</span>' : '') +
        '</div>' +
        '<div class="ta-student-progress">' +
          '<div class="ta-progress-bar"><div class="ta-progress-fill" style="width:' + s.progress_pct + '%;background:' + barColor + '"></div></div>' +
          '<span class="ta-progress-label">' + s.completed_items + '/' + s.total_items + ' &middot; ' + secondsToHM(s.time_spent) + '</span>' +
        '</div>' +
      '</div>';
    });

    html += '</div></div>';
    bodyEl.innerHTML = html;

    // Bind student rows for drill-down
    bodyEl.querySelectorAll('.ta-student-row').forEach(function (row) {
      row.addEventListener('click', function () {
        var sid = row.getAttribute('data-sid');
        var existingDetail = row.querySelector('.ta-item-detail');
        if (existingDetail) {
          existingDetail.remove();
          return;
        }
        loadStudentItemDetail(row, aid, sid);
      });
    });
  }

  function loadStudentItemDetail(rowEl, aid, sid) {
    var detail = document.createElement('div');
    detail.className = 'ta-item-detail';
    detail.innerHTML = '<div class="ta-loading-sm">Loading items...</div>';
    rowEl.appendChild(detail);

    fetchJSON(API_PROGRESS + '?action=student_detail&assignment_id=' + aid + '&student_id=' + sid)
      .then(function (data) {
        var items = data.items || [];
        if (items.length === 0) {
          detail.innerHTML = '<div class="ta-item-empty">No progress data yet.</div>';
          return;
        }

        var html = '';
        items.forEach(function (item) {
          var statusClass = 'ta-item-status--' + item.status.replace('_', '-');
          var statusLabel = item.status === 'completed' ? 'Done' : item.status === 'in_progress' ? 'In Progress' : 'Not Started';
          html += '<div class="ta-item-row">' +
            '<div class="ta-item-title">' + esc(item.title) + '</div>' +
            '<div class="ta-item-meta">' +
              '<span class="ta-item-status ' + statusClass + '">' + statusLabel + '</span>' +
              '<span class="ta-item-pct">' + item.progress_pct + '%</span>' +
              '<span class="ta-item-time">' + secondsToHM(item.time_spent) + '</span>' +
            '</div>' +
          '</div>';
        });
        detail.innerHTML = html;
      })
      .catch(function () {
        detail.innerHTML = '<div class="ta-item-empty">Failed to load item details.</div>';
      });
  }

  // ─── Guided Mode Live Panel ─────────────────────────────────────────────────

  function loadGuidedLive(aid, bodyEl) {
    fetchJSON(API_PROGRESS + '?action=guided_live&assignment_id=' + aid).then(function (data) {
      var students = data.students || [];
      var html = '<div class="ta-guided-panel">' +
        '<div class="ta-guided-header">Live Class View <span class="ta-guided-dot"></span></div>';

      if (students.length === 0) {
        html += '<div class="ta-guided-empty">No student activity yet.</div>';
      } else {
        students.forEach(function (s) {
          var statusClass = s.is_online ? 'ta-guided-online' : 'ta-guided-offline';
          var statusLabel = s.is_online ? 'Online' : 'Offline';

          html += '<div class="ta-guided-student ' + statusClass + '">' +
            '<div class="ta-guided-student-info">' +
              '<span class="ta-student-dot" style="background:' + esc(s.avatar_color) + '"></span>' +
              '<span class="ta-guided-name">' + esc(s.display_name) + '</span>' +
              '<span class="ta-guided-status">' + statusLabel + '</span>' +
            '</div>' +
            '<div class="ta-guided-items">';

          (s.items || []).forEach(function (item) {
            var cls = 'ta-guided-item-dot ta-guided-item--' + item.status.replace('_', '-');
            html += '<span class="' + cls + '" title="' + esc(item.content_id) + ': ' + item.progress_pct + '%"></span>';
          });

          html += '</div></div>';
        });
      }

      html += '</div>';
      bodyEl.innerHTML = html;
    }).catch(function () {
      bodyEl.innerHTML = '<div class="ta-error">Failed to load live data.</div>';
    });
  }

  // ─── New Assignment Modal (lesson plan picker → assign modal) ──────────────

  function openNewAssignModal() {
    var overlay = document.createElement('div');
    overlay.className = 'lb-modal-overlay assign-modal-overlay';

    var modal = document.createElement('div');
    modal.className = 'lb-modal assign-modal';

    var title = document.createElement('h3');
    title.textContent = 'New Assignment';
    modal.appendChild(title);

    // Lesson plan picker
    var field = document.createElement('div');
    field.className = 'assign-field';
    var label = document.createElement('label');
    label.className = 'assign-label';
    label.textContent = 'Lesson Plan';
    field.appendChild(label);

    var select = document.createElement('select');
    select.className = 'assign-select';
    select.id = 'ta-plan-select';
    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Loading lesson plans...';
    placeholder.disabled = true;
    placeholder.selected = true;
    select.appendChild(placeholder);
    field.appendChild(select);
    modal.appendChild(field);

    var statusMsg = document.createElement('div');
    statusMsg.className = 'assign-status';
    statusMsg.id = 'ta-pick-status';
    modal.appendChild(statusMsg);

    // Action buttons
    var actions = document.createElement('div');
    actions.className = 'lb-modal-actions';

    var cancelBtn = document.createElement('button');
    cancelBtn.className = 'lb-btn lb-btn-secondary';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', function () { overlay.remove(); });
    actions.appendChild(cancelBtn);

    var nextBtn = document.createElement('button');
    nextBtn.className = 'lb-btn lb-btn-accent';
    nextBtn.textContent = 'Next';
    nextBtn.disabled = true;
    actions.appendChild(nextBtn);

    modal.appendChild(actions);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Enable Next when a plan is selected
    select.addEventListener('change', function () {
      nextBtn.disabled = !select.value;
    });

    // Next → open the full assign modal for the chosen plan
    nextBtn.addEventListener('click', function () {
      var planId = select.value;
      if (!planId) return;
      var selectedOption = select.options[select.selectedIndex];
      var plan = {
        id: parseInt(planId, 10),
        title: selectedOption.textContent
      };
      overlay.remove();
      if (typeof window.LessonBuilder !== 'undefined' && window.LessonBuilder.openAssignModal) {
        window.LessonBuilder.openAssignModal(plan);
      }
    });

    // Fetch lesson plans
    fetch('/api/lesson_plans.php?action=list')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var plans = data.plans || [];
        select.innerHTML = '';
        if (plans.length === 0) {
          var empty = document.createElement('option');
          empty.value = '';
          empty.textContent = 'No lesson plans found';
          empty.disabled = true;
          empty.selected = true;
          select.appendChild(empty);
          statusMsg.textContent = 'Create a lesson plan first in the Lesson Plans tab.';
          return;
        }
        var def = document.createElement('option');
        def.value = '';
        def.textContent = 'Select a lesson plan...';
        def.disabled = true;
        def.selected = true;
        select.appendChild(def);
        plans.forEach(function (p) {
          var opt = document.createElement('option');
          opt.value = p.id;
          opt.textContent = p.title + ' (' + p.item_count + ' items)';
          select.appendChild(opt);
        });
      })
      .catch(function () {
        select.innerHTML = '';
        var errOpt = document.createElement('option');
        errOpt.value = '';
        errOpt.textContent = 'Failed to load lesson plans';
        errOpt.disabled = true;
        errOpt.selected = true;
        select.appendChild(errOpt);
        statusMsg.textContent = 'Network error. Please try again.';
      });
  }

  // ─── Actions ────────────────────────────────────────────────────────────────

  function handleAction(action, aid) {
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var status = '';
    if (action === 'complete') status = 'completed';
    else if (action === 'archive') status = 'archived';
    else if (action === 'delete') {
      // Delete action
      var body = new FormData();
      body.append('action', 'delete');
      body.append('_csrf_token', csrfToken);
      body.append('assignment_id', aid);
      fetch(API_ASSIGNMENTS, { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) render();
        });
      return;
    }

    if (!status) return;

    var body = new FormData();
    body.append('action', 'update');
    body.append('_csrf_token', csrfToken);
    body.append('assignment_id', aid);
    body.append('status', status);

    fetch(API_ASSIGNMENTS, { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          // Re-load with current filter
          var activeFilter = root.querySelector('.ta-filter-btn--active');
          var currentFilter = activeFilter ? activeFilter.getAttribute('data-filter') : 'active';
          loadAssignments(currentFilter);
        }
      });
  }

  // ─── Init ───────────────────────────────────────────────────────────────────

  function init(el) {
    root = el;
    render();

    // Refresh list when a new assignment is created from the assign modal
    document.addEventListener('assignment-created', function () {
      if (!root) return;
      var activeFilter = root.querySelector('.ta-filter-btn--active');
      var currentFilter = activeFilter ? activeFilter.getAttribute('data-filter') : 'active';
      loadAssignments(currentFilter);
    });
  }

  function cleanup() {
    if (guidedPollTimer) {
      clearInterval(guidedPollTimer);
      guidedPollTimer = null;
    }
  }

  window.TeacherAssignments = {
    init: init,
    cleanup: cleanup,
  };
})();

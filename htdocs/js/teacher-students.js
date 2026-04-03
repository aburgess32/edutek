/**
 * Teacher Students Manager (FRE-47)
 *
 * Handles: student list, add/remove, detail panel, groups management
 * Mounts into #students-root on teacher.php
 */
(function () {
  'use strict';

  var root = null;
  var csrf = '';
  var state = {
    students: [],
    groups: [],
    ungroupedCount: 0,
    activeGroupFilter: null,   // null = all, 0 = ungrouped, N = group id
    sortBy: 'name',
    search: '',
    detailStudent: null,
  };

  // ──────────────────────────────────────────────────────────
  // Helpers
  // ──────────────────────────────────────────────────────────

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return (ctx || document).querySelectorAll(sel); }

  function api(url, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    if (opts.method === 'POST') {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = csrf;
    }
    opts.headers = headers;
    return fetch(url, opts).then(function (r) { return r.json(); });
  }

  function formatTime(sec) {
    sec = parseInt(sec, 10) || 0;
    if (sec < 60) return sec + 's';
    if (sec < 3600) return Math.round(sec / 60) + 'min';
    var h = Math.floor(sec / 3600);
    var m = Math.round((sec % 3600) / 60);
    return h + 'h ' + m + 'm';
  }

  function timeAgo(dateStr) {
    if (!dateStr) return 'Never';
    var diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    var days = Math.floor(diff / 86400);
    if (days === 1) return '1 day ago';
    if (days < 30) return days + ' days ago';
    return Math.floor(days / 30) + ' mo ago';
  }

  function avatarHtml(name, color) {
    var initial = (name || '?').charAt(0).toUpperCase();
    var bg = color || '#4ECDC4';
    return '<div class="stu-avatar" style="background:' + bg + '">' + initial + '</div>';
  }

  function escHtml(str) {
    var d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
  }

  // ──────────────────────────────────────────────────────────
  // Data loading
  // ──────────────────────────────────────────────────────────

  function loadStudents() {
    var url = '/api/teacher/students.php?action=list&sort=' + state.sortBy;
    if (state.activeGroupFilter !== null) url += '&group_id=' + state.activeGroupFilter;
    if (state.search) url += '&search=' + encodeURIComponent(state.search);
    return api(url).then(function (data) {
      state.students = data.students || [];
    });
  }

  function loadGroups() {
    return api('/api/teacher/groups.php?action=list').then(function (data) {
      state.groups = data.groups || [];
      state.ungroupedCount = data.ungrouped_count || 0;
    });
  }

  function reload() {
    return Promise.all([loadStudents(), loadGroups()]).then(render);
  }

  // ──────────────────────────────────────────────────────────
  // Render — Main view
  // ──────────────────────────────────────────────────────────

  function render() {
    if (!root) return;
    root.innerHTML = '';

    // Header bar
    var header = document.createElement('div');
    header.className = 'stu-header';
    header.innerHTML =
      '<div class="stu-header__left">' +
        '<h2 class="stu-title">Students</h2>' +
        '<span class="stu-count">' + state.students.length + ' assigned</span>' +
      '</div>' +
      '<div class="stu-header__actions">' +
        '<button class="stu-btn stu-btn--secondary" id="stu-manage-groups">' +
          '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>' +
          ' Groups' +
        '</button>' +
        '<button class="stu-btn stu-btn--primary" id="stu-add-btn">' +
          '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>' +
          ' Add Students' +
        '</button>' +
      '</div>';
    root.appendChild(header);

    // Toolbar: search + sort + group filter
    var toolbar = document.createElement('div');
    toolbar.className = 'stu-toolbar';

    var searchHtml =
      '<input type="text" class="stu-search" id="stu-search" placeholder="Search by name..." value="' + escHtml(state.search) + '">';

    var sortHtml =
      '<select class="stu-select" id="stu-sort">' +
        '<option value="name"' + (state.sortBy === 'name' ? ' selected' : '') + '>Name</option>' +
        '<option value="last_active"' + (state.sortBy === 'last_active' ? ' selected' : '') + '>Last Active</option>' +
        '<option value="screen_time"' + (state.sortBy === 'screen_time' ? ' selected' : '') + '>Screen Time</option>' +
      '</select>';

    var filterHtml = '<div class="stu-filters" id="stu-filters">';
    filterHtml += '<button class="stu-filter-pill' + (state.activeGroupFilter === null ? ' stu-filter-pill--active' : '') + '" data-gid="all">All</button>';
    for (var i = 0; i < state.groups.length; i++) {
      var g = state.groups[i];
      var active = state.activeGroupFilter === g.id;
      filterHtml += '<button class="stu-filter-pill' + (active ? ' stu-filter-pill--active' : '') + '" data-gid="' + g.id + '" style="--pill-color:' + g.color + '">' + escHtml(g.name) + ' <span class="stu-pill-count">' + g.member_count + '</span></button>';
    }
    if (state.ungroupedCount > 0) {
      filterHtml += '<button class="stu-filter-pill' + (state.activeGroupFilter === 0 ? ' stu-filter-pill--active' : '') + '" data-gid="0">Ungrouped <span class="stu-pill-count">' + state.ungroupedCount + '</span></button>';
    }
    filterHtml += '</div>';

    toolbar.innerHTML = '<div class="stu-toolbar__row">' + searchHtml + sortHtml + '</div>' + filterHtml;
    root.appendChild(toolbar);

    // Student list
    var list = document.createElement('div');
    list.className = 'stu-list';

    if (state.students.length === 0) {
      list.innerHTML =
        '<div class="stu-empty">' +
          '<svg width="48" height="48" viewBox="0 0 48 48" fill="none"><circle cx="24" cy="18" r="8" stroke="#bec2c8" stroke-width="2"/><path d="M12 40c0-6.627 5.373-12 12-12s12 5.373 12 12" stroke="#bec2c8" stroke-width="2" stroke-linecap="round"/></svg>' +
          '<p class="stu-empty__text">No students assigned yet.</p>' +
          '<p class="stu-empty__sub">Tap <strong>Add Students</strong> to assign students from this device.</p>' +
        '</div>';
    } else {
      var html = '<table class="stu-table"><thead><tr>' +
        '<th class="stu-th">Student</th>' +
        '<th class="stu-th">Group</th>' +
        '<th class="stu-th stu-th--num">Videos</th>' +
        '<th class="stu-th stu-th--num">Screen Time</th>' +
        '<th class="stu-th">Last Active</th>' +
        '<th class="stu-th stu-th--action"></th>' +
      '</tr></thead><tbody>';

      for (var j = 0; j < state.students.length; j++) {
        var s = state.students[j];
        var inactive = s.days_inactive > 7;
        html += '<tr class="stu-row' + (inactive ? ' stu-row--inactive' : '') + '" data-sid="' + s.id + '">' +
          '<td class="stu-td">' +
            '<div class="stu-student-cell">' +
              avatarHtml(s.display_name, s.avatar_color) +
              '<div>' +
                '<div class="stu-name">' + escHtml(s.display_name) + '</div>' +
                '<div class="stu-type">' + escHtml(s.user_type) + '</div>' +
              '</div>' +
            '</div>' +
          '</td>' +
          '<td class="stu-td">' +
            (s.group_name
              ? '<span class="stu-group-badge" style="--badge-color:' + (s.group_color || '#4ECDC4') + '">' + escHtml(s.group_name) + '</span>'
              : '<span class="stu-muted">—</span>') +
          '</td>' +
          '<td class="stu-td stu-td--num">' + s.watch_count + '</td>' +
          '<td class="stu-td stu-td--num">' + formatTime(s.screen_time_sec) + '</td>' +
          '<td class="stu-td">' +
            '<span class="stu-last-active' + (inactive ? ' stu-last-active--warn' : '') + '">' + timeAgo(s.last_active) + '</span>' +
          '</td>' +
          '<td class="stu-td stu-td--action">' +
            '<button class="stu-icon-btn stu-remove-btn" data-sid="' + s.id + '" data-name="' + escHtml(s.display_name) + '" title="Remove student">' +
              '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3 3l8 8M11 3l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>' +
            '</button>' +
          '</td>' +
        '</tr>';
      }
      html += '</tbody></table>';
      list.innerHTML = html;
    }

    root.appendChild(list);

    // ── Event bindings ──
    var searchInput = $('#stu-search', root);
    var searchTimer = null;
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
          state.search = searchInput.value.trim();
          loadStudents().then(render);
        }, 300);
      });
    }

    var sortSel = $('#stu-sort', root);
    if (sortSel) {
      sortSel.addEventListener('change', function () {
        state.sortBy = this.value;
        loadStudents().then(render);
      });
    }

    // Group filter pills
    $$('.stu-filter-pill', root).forEach(function (pill) {
      pill.addEventListener('click', function () {
        var gid = this.getAttribute('data-gid');
        state.activeGroupFilter = gid === 'all' ? null : parseInt(gid, 10);
        loadStudents().then(render);
      });
    });

    // Row click → detail
    $$('.stu-row', root).forEach(function (row) {
      row.addEventListener('click', function (e) {
        if (e.target.closest('.stu-remove-btn')) return;
        var sid = parseInt(this.getAttribute('data-sid'), 10);
        openDetail(sid);
      });
    });

    // Remove buttons
    $$('.stu-remove-btn', root).forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var sid = parseInt(this.getAttribute('data-sid'), 10);
        var name = this.getAttribute('data-name');
        if (confirm('Remove ' + name + ' from your student list? They can still use EduPak.')) {
          api('/api/teacher/students.php?action=remove', {
            method: 'POST',
            body: JSON.stringify({ action: 'remove', student_id: sid }),
          }).then(reload);
        }
      });
    });

    // Add Students button
    var addBtn = $('#stu-add-btn', root);
    if (addBtn) addBtn.addEventListener('click', openAddModal);

    // Manage Groups button
    var grpBtn = $('#stu-manage-groups', root);
    if (grpBtn) grpBtn.addEventListener('click', openGroupsModal);
  }

  // ──────────────────────────────────────────────────────────
  // Add Students Modal
  // ──────────────────────────────────────────────────────────

  function openAddModal() {
    api('/api/teacher/students.php?action=available').then(function (data) {
      var students = data.students || [];
      var overlay = document.createElement('div');
      overlay.className = 'stu-modal-overlay';

      var html = '<div class="stu-modal">' +
        '<div class="stu-modal__header">' +
          '<h3>Add Students</h3>' +
          '<button class="stu-modal__close">&times;</button>' +
        '</div>' +
        '<div class="stu-modal__body">';

      if (students.length === 0) {
        html += '<p class="stu-empty__text">No student accounts on this device yet.</p>';
      } else {
        html += '<div class="stu-add-list">';
        for (var i = 0; i < students.length; i++) {
          var s = students[i];
          var warn = s.other_teachers ? ' <span class="stu-add-warn">(Also with: ' + escHtml(s.other_teachers) + ')</span>' : '';
          html += '<label class="stu-add-item">' +
            '<input type="checkbox" class="stu-add-check" value="' + s.id + '">' +
            avatarHtml(s.display_name, s.avatar_color) +
            '<span class="stu-add-name">' + escHtml(s.display_name) + warn + '</span>' +
          '</label>';
        }
        html += '</div>';
      }

      html += '</div>' +
        '<div class="stu-modal__footer">' +
          '<button class="stu-btn stu-btn--secondary stu-modal__cancel">Cancel</button>' +
          '<button class="stu-btn stu-btn--primary" id="stu-assign-btn" ' + (students.length === 0 ? 'disabled' : '') + '>Assign Selected</button>' +
        '</div>' +
      '</div>';

      overlay.innerHTML = html;
      document.body.appendChild(overlay);

      // Close handlers
      function close() { overlay.remove(); }
      $('.stu-modal__close', overlay).addEventListener('click', close);
      $('.stu-modal__cancel', overlay).addEventListener('click', close);
      overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

      // Assign
      var assignBtn = $('#stu-assign-btn', overlay);
      if (assignBtn) {
        assignBtn.addEventListener('click', function () {
          var ids = [];
          $$('.stu-add-check:checked', overlay).forEach(function (cb) {
            ids.push(parseInt(cb.value, 10));
          });
          if (ids.length === 0) return;
          assignBtn.disabled = true;
          assignBtn.textContent = 'Assigning...';
          api('/api/teacher/students.php?action=assign', {
            method: 'POST',
            body: JSON.stringify({ action: 'assign', student_ids: ids }),
          }).then(function () {
            close();
            reload();
          });
        });
      }
    });
  }

  // ──────────────────────────────────────────────────────────
  // Student Detail Panel
  // ──────────────────────────────────────────────────────────

  function openDetail(studentId) {
    api('/api/teacher/students.php?action=detail&id=' + studentId).then(function (s) {
      var overlay = document.createElement('div');
      overlay.className = 'stu-modal-overlay stu-detail-overlay';

      var groupBadge = s.group
        ? '<span class="stu-group-badge" style="--badge-color:' + s.group.color + '">' + escHtml(s.group.name) + '</span>'
        : '<span class="stu-muted">No group</span>';

      // Assign to group dropdown
      var groupOptions = '<option value="">No group</option>';
      for (var gi = 0; gi < state.groups.length; gi++) {
        var g = state.groups[gi];
        var sel = s.group && s.group.id === g.id ? ' selected' : '';
        groupOptions += '<option value="' + g.id + '"' + sel + '>' + escHtml(g.name) + '</option>';
      }

      var html = '<div class="stu-detail">' +
        '<div class="stu-detail__header">' +
          '<button class="stu-detail__back">' +
            '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M13 4l-6 6 6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
          '</button>' +
          '<div class="stu-detail__identity">' +
            avatarHtml(s.display_name, s.avatar_color) +
            '<div>' +
              '<h3 class="stu-detail__name">' + escHtml(s.display_name) + '</h3>' +
              '<div class="stu-detail__meta">' + escHtml(s.user_type) + ' &middot; Joined ' + timeAgo(s.created_at) + '</div>' +
            '</div>' +
          '</div>' +
        '</div>' +

        // Stats cards
        '<div class="stu-detail__stats">' +
          '<div class="stu-stat-card">' +
            '<div class="stu-stat-card__val">' + (s.stats ? s.stats.total_watched : 0) + '</div>' +
            '<div class="stu-stat-card__label">Videos Watched</div>' +
          '</div>' +
          '<div class="stu-stat-card">' +
            '<div class="stu-stat-card__val">' + formatTime(s.stats ? s.stats.total_screen_time : 0) + '</div>' +
            '<div class="stu-stat-card__label">Screen Time</div>' +
          '</div>' +
          '<div class="stu-stat-card">' +
            '<div class="stu-stat-card__val">' + Math.round(s.stats ? s.stats.avg_completion : 0) + '%</div>' +
            '<div class="stu-stat-card__label">Avg Completion</div>' +
          '</div>' +
        '</div>' +

        // Group
        '<div class="stu-detail__section">' +
          '<h4 class="stu-detail__section-title">Group</h4>' +
          '<select class="stu-select stu-detail__group-sel" id="stu-detail-group">' + groupOptions + '</select>' +
        '</div>' +

        // Top subjects
        '<div class="stu-detail__section">' +
          '<h4 class="stu-detail__section-title">Top Subjects</h4>';

      if (s.top_subjects && s.top_subjects.length > 0) {
        html += '<div class="stu-detail__subjects">';
        for (var si = 0; si < s.top_subjects.length; si++) {
          html += '<span class="stu-subject-tag">' + escHtml(s.top_subjects[si].subject) + ' <span class="stu-subject-count">' + s.top_subjects[si].cnt + '</span></span>';
        }
        html += '</div>';
      } else {
        html += '<p class="stu-muted">No subject data yet</p>';
      }

      html += '</div>' +

        // Watch history
        '<div class="stu-detail__section">' +
          '<h4 class="stu-detail__section-title">Recent Activity</h4>';

      if (s.watch_history && s.watch_history.length > 0) {
        html += '<div class="stu-detail__history">';
        for (var wi = 0; wi < s.watch_history.length; wi++) {
          var w = s.watch_history[wi];
          html += '<div class="stu-history-item">' +
            '<div class="stu-history-item__title">' + escHtml(w.content_title || w.content_id) + '</div>' +
            '<div class="stu-history-item__meta">' +
              '<span class="stu-history-item__pct">' + w.completion_pct + '%</span>' +
              '<div class="stu-progress-bar"><div class="stu-progress-bar__fill" style="width:' + w.completion_pct + '%"></div></div>' +
              '<span class="stu-history-item__time">' + timeAgo(w.last_watched) + '</span>' +
            '</div>' +
          '</div>';
        }
        html += '</div>';
      } else {
        html += '<p class="stu-muted">No activity yet</p>';
      }

      html += '</div></div>';

      overlay.innerHTML = html;
      document.body.appendChild(overlay);

      function close() { overlay.remove(); }
      $('.stu-detail__back', overlay).addEventListener('click', close);
      overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

      // Group change
      var groupSel = $('#stu-detail-group', overlay);
      if (groupSel) {
        groupSel.addEventListener('change', function () {
          var gid = parseInt(this.value, 10);
          if (gid) {
            api('/api/teacher/groups.php?action=assign', {
              method: 'POST',
              body: JSON.stringify({ action: 'assign', group_id: gid, student_ids: [studentId] }),
            }).then(reload);
          } else {
            api('/api/teacher/groups.php?action=unassign', {
              method: 'POST',
              body: JSON.stringify({ action: 'unassign', student_id: studentId }),
            }).then(reload);
          }
        });
      }
    });
  }

  // ──────────────────────────────────────────────────────────
  // Groups Management Modal
  // ──────────────────────────────────────────────────────────

  function openGroupsModal() {
    var overlay = document.createElement('div');
    overlay.className = 'stu-modal-overlay';

    function renderModal() {
      var html = '<div class="stu-modal stu-modal--groups">' +
        '<div class="stu-modal__header">' +
          '<h3>Manage Groups</h3>' +
          '<button class="stu-modal__close">&times;</button>' +
        '</div>' +
        '<div class="stu-modal__body">';

      if (state.groups.length === 0) {
        html += '<p class="stu-empty__text">No groups yet. Create a group to organize students by class or period.</p>';
      } else {
        html += '<div class="stu-groups-list">';
        for (var i = 0; i < state.groups.length; i++) {
          var g = state.groups[i];
          html += '<div class="stu-group-row" data-gid="' + g.id + '">' +
            '<span class="stu-group-dot" style="background:' + g.color + '"></span>' +
            '<span class="stu-group-name">' + escHtml(g.name) + '</span>' +
            '<span class="stu-group-count">' + g.member_count + ' students</span>' +
            '<button class="stu-icon-btn stu-group-delete" data-gid="' + g.id + '" data-name="' + escHtml(g.name) + '" title="Delete group">' +
              '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3 3l8 8M11 3l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>' +
            '</button>' +
          '</div>';
        }
        html += '</div>';
      }

      // Create new group form
      html += '<div class="stu-group-create">' +
        '<input type="text" class="stu-search" id="stu-new-group-name" placeholder="New group name..." maxlength="100">' +
        '<input type="color" class="stu-color-input" id="stu-new-group-color" value="#4ECDC4">' +
        '<button class="stu-btn stu-btn--primary stu-btn--sm" id="stu-create-group">Create</button>' +
      '</div>';

      html += '</div>' +
        '<div class="stu-modal__footer">' +
          '<button class="stu-btn stu-btn--secondary stu-modal__cancel">Done</button>' +
        '</div>' +
      '</div>';

      overlay.innerHTML = html;

      function close() { overlay.remove(); reload(); }
      $('.stu-modal__close', overlay).addEventListener('click', close);
      $('.stu-modal__cancel', overlay).addEventListener('click', close);
      overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

      // Delete group
      $$('.stu-group-delete', overlay).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var gid = parseInt(this.getAttribute('data-gid'), 10);
          var name = this.getAttribute('data-name');
          if (confirm('Delete "' + name + '"? Members will become ungrouped.')) {
            api('/api/teacher/groups.php?action=delete', {
              method: 'POST',
              body: JSON.stringify({ action: 'delete', id: gid }),
            }).then(function () {
              return loadGroups();
            }).then(renderModal);
          }
        });
      });

      // Create group
      var createBtn = $('#stu-create-group', overlay);
      if (createBtn) {
        createBtn.addEventListener('click', function () {
          var nameInput = $('#stu-new-group-name', overlay);
          var colorInput = $('#stu-new-group-color', overlay);
          var name = nameInput.value.trim();
          if (!name) { nameInput.focus(); return; }
          createBtn.disabled = true;
          api('/api/teacher/groups.php?action=create', {
            method: 'POST',
            body: JSON.stringify({ action: 'create', name: name, color: colorInput.value }),
          }).then(function () {
            return loadGroups();
          }).then(renderModal);
        });

        // Enter key support
        $('#stu-new-group-name', overlay).addEventListener('keydown', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); createBtn.click(); }
        });
      }
    }

    document.body.appendChild(overlay);
    renderModal();
  }

  // ──────────────────────────────────────────────────────────
  // Public init
  // ──────────────────────────────────────────────────────────

  window.TeacherStudents = {
    init: function (el) {
      root = el;
      csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content') || '';
      root.innerHTML = '<div class="dash-loading"><div class="dash-spinner"></div><div>Loading students...</div></div>';
      reload();
    }
  };

})();

/**
 * Teacher Students Manager (FRE-47)
 *
 * Handles: student list, add/remove, detail panel, groups management,
 *          CSV import, batch actions, pagination, select-all / shift-click
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
    // Pagination
    page: 1,
    perPage: 50,
    total: 0,
    totalPages: 1,
    // Bulk selection
    selected: {},        // { studentId: true }
    lastChecked: null,   // for shift-click range
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

  function selectedIds() {
    var ids = [];
    for (var k in state.selected) {
      if (state.selected[k]) ids.push(parseInt(k, 10));
    }
    return ids;
  }

  function selectedCount() { return selectedIds().length; }

  function clearSelection() {
    state.selected = {};
    state.lastChecked = null;
  }

  // ──────────────────────────────────────────────────────────
  // Data loading
  // ──────────────────────────────────────────────────────────

  function loadStudents() {
    var url = '/api/teacher/students.php?action=list&sort=' + state.sortBy +
      '&page=' + state.page + '&per_page=' + state.perPage;
    if (state.activeGroupFilter !== null) url += '&group_id=' + state.activeGroupFilter;
    if (state.search) url += '&search=' + encodeURIComponent(state.search);
    return api(url).then(function (data) {
      state.students = data.students || [];
      state.total = data.total || 0;
      state.page = data.page || 1;
      state.totalPages = data.total_pages || 1;
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
        '<span class="stu-count">' + state.total + ' assigned</span>' +
      '</div>' +
      '<div class="stu-header__actions">' +
        '<button class="stu-btn stu-btn--secondary" id="stu-import-btn">' +
          '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 2v8M4 7l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 12v2h12v-2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
          ' Import' +
        '</button>' +
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

    if (state.students.length === 0 && state.total === 0) {
      list.innerHTML =
        '<div class="stu-empty">' +
          '<svg width="48" height="48" viewBox="0 0 48 48" fill="none"><circle cx="24" cy="18" r="8" stroke="#bec2c8" stroke-width="2"/><path d="M12 40c0-6.627 5.373-12 12-12s12 5.373 12 12" stroke="#bec2c8" stroke-width="2" stroke-linecap="round"/></svg>' +
          '<p class="stu-empty__text">No students assigned yet.</p>' +
          '<p class="stu-empty__sub">Tap <strong>Add Students</strong> to assign students from this device, or use <strong>Import</strong> to add from a list.</p>' +
        '</div>';
    } else {
      var allChecked = state.students.length > 0 && state.students.every(function (s) { return !!state.selected[s.id]; });
      var someChecked = selectedCount() > 0;

      var html = '<table class="stu-table"><thead><tr>' +
        '<th class="stu-th stu-th--check"><input type="checkbox" class="stu-check-all" id="stu-check-all"' + (allChecked ? ' checked' : '') + (someChecked && !allChecked ? ' data-indeterminate="true"' : '') + '></th>' +
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
        var checked = !!state.selected[s.id];
        html += '<tr class="stu-row' + (inactive ? ' stu-row--inactive' : '') + (checked ? ' stu-row--selected' : '') + '" data-sid="' + s.id + '" data-idx="' + j + '">' +
          '<td class="stu-td stu-td--check"><input type="checkbox" class="stu-row-check" value="' + s.id + '"' + (checked ? ' checked' : '') + '></td>' +
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
              : '<span class="stu-muted">\u2014</span>') +
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

    // Pagination
    if (state.totalPages > 1) {
      var pag = document.createElement('div');
      pag.className = 'stu-pagination';
      var pagHtml = '<span class="stu-pagination__info">Page ' + state.page + ' of ' + state.totalPages + ' (' + state.total + ' students)</span>';
      pagHtml += '<div class="stu-pagination__btns">';
      pagHtml += '<button class="stu-btn stu-btn--sm stu-btn--secondary stu-page-prev"' + (state.page <= 1 ? ' disabled' : '') + '>Prev</button>';

      // Show page numbers (max 5 visible)
      var startP = Math.max(1, state.page - 2);
      var endP = Math.min(state.totalPages, startP + 4);
      startP = Math.max(1, endP - 4);
      for (var p = startP; p <= endP; p++) {
        pagHtml += '<button class="stu-btn stu-btn--sm' + (p === state.page ? ' stu-btn--primary' : ' stu-btn--secondary') + ' stu-page-num" data-page="' + p + '">' + p + '</button>';
      }

      pagHtml += '<button class="stu-btn stu-btn--sm stu-btn--secondary stu-page-next"' + (state.page >= state.totalPages ? ' disabled' : '') + '>Next</button>';
      pagHtml += '</div>';
      pag.innerHTML = pagHtml;
      root.appendChild(pag);

      // Pagination events
      var prevBtn = $('.stu-page-prev', pag);
      if (prevBtn) prevBtn.addEventListener('click', function () { if (state.page > 1) { state.page--; loadStudents().then(render); } });
      var nextBtn = $('.stu-page-next', pag);
      if (nextBtn) nextBtn.addEventListener('click', function () { if (state.page < state.totalPages) { state.page++; loadStudents().then(render); } });
      $$('.stu-page-num', pag).forEach(function (btn) {
        btn.addEventListener('click', function () {
          state.page = parseInt(this.getAttribute('data-page'), 10);
          loadStudents().then(render);
        });
      });
    }

    // Bulk action bar (visible when items are selected)
    var bulkBar = document.createElement('div');
    bulkBar.className = 'stu-bulk-bar' + (selectedCount() > 0 ? ' stu-bulk-bar--visible' : '');
    bulkBar.id = 'stu-bulk-bar';

    var groupOpts = '<option value="">Move to Group...</option>';
    for (var gi = 0; gi < state.groups.length; gi++) {
      groupOpts += '<option value="' + state.groups[gi].id + '">' + escHtml(state.groups[gi].name) + '</option>';
    }
    groupOpts += '<option value="0">Ungrouped</option>';

    bulkBar.innerHTML =
      '<div class="stu-bulk-bar__inner">' +
        '<span class="stu-bulk-bar__count"><strong id="stu-sel-count">' + selectedCount() + '</strong> selected</span>' +
        '<select class="stu-select stu-bulk-bar__group-sel" id="stu-bulk-group">' + groupOpts + '</select>' +
        '<button class="stu-btn stu-btn--sm stu-btn--danger" id="stu-bulk-remove">Remove Selected</button>' +
        '<button class="stu-btn stu-btn--sm stu-btn--secondary" id="stu-bulk-clear">Clear</button>' +
      '</div>';
    root.appendChild(bulkBar);

    // ── Event bindings ──
    bindEvents();
  }

  // ──────────────────────────────────────────────────────────
  // Event bindings
  // ──────────────────────────────────────────────────────────

  function bindEvents() {
    // Search
    var searchInput = $('#stu-search', root);
    var searchTimer = null;
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
          state.search = searchInput.value.trim();
          state.page = 1;
          clearSelection();
          loadStudents().then(render);
        }, 300);
      });
    }

    // Sort
    var sortSel = $('#stu-sort', root);
    if (sortSel) {
      sortSel.addEventListener('change', function () {
        state.sortBy = this.value;
        state.page = 1;
        loadStudents().then(render);
      });
    }

    // Group filter pills
    $$('.stu-filter-pill', root).forEach(function (pill) {
      pill.addEventListener('click', function () {
        var gid = this.getAttribute('data-gid');
        state.activeGroupFilter = gid === 'all' ? null : parseInt(gid, 10);
        state.page = 1;
        clearSelection();
        loadStudents().then(render);
      });
    });

    // Select-all checkbox
    var checkAll = $('#stu-check-all', root);
    if (checkAll) {
      // Set indeterminate state
      if (checkAll.getAttribute('data-indeterminate') === 'true') {
        checkAll.indeterminate = true;
      }
      checkAll.addEventListener('change', function () {
        var checked = this.checked;
        state.students.forEach(function (s) {
          if (checked) { state.selected[s.id] = true; }
          else { delete state.selected[s.id]; }
        });
        state.lastChecked = null;
        render();
      });
    }

    // Individual row checkboxes with shift-click range support
    $$('.stu-row-check', root).forEach(function (cb, idx) {
      cb.addEventListener('click', function (e) {
        e.stopPropagation();
        var sid = parseInt(this.value, 10);
        var rowIdx = parseInt(this.closest('.stu-row').getAttribute('data-idx'), 10);

        if (e.shiftKey && state.lastChecked !== null) {
          // Range select
          var start = Math.min(state.lastChecked, rowIdx);
          var end = Math.max(state.lastChecked, rowIdx);
          var shouldCheck = this.checked;
          for (var ri = start; ri <= end; ri++) {
            var s = state.students[ri];
            if (s) {
              if (shouldCheck) { state.selected[s.id] = true; }
              else { delete state.selected[s.id]; }
            }
          }
        } else {
          if (this.checked) { state.selected[sid] = true; }
          else { delete state.selected[sid]; }
        }

        state.lastChecked = rowIdx;
        render();
      });
    });

    // Row click → detail (only if not clicking checkbox)
    $$('.stu-row', root).forEach(function (row) {
      row.addEventListener('click', function (e) {
        if (e.target.closest('.stu-remove-btn') || e.target.closest('.stu-row-check') || e.target.tagName === 'INPUT') return;
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
          }).then(function () {
            delete state.selected[sid];
            reload();
          });
        }
      });
    });

    // Add Students button
    var addBtn = $('#stu-add-btn', root);
    if (addBtn) addBtn.addEventListener('click', openAddModal);

    // Import button
    var importBtn = $('#stu-import-btn', root);
    if (importBtn) importBtn.addEventListener('click', openImportModal);

    // Manage Groups button
    var grpBtn = $('#stu-manage-groups', root);
    if (grpBtn) grpBtn.addEventListener('click', openGroupsModal);

    // ── Bulk action bar events ──
    var bulkGroup = $('#stu-bulk-group', root);
    if (bulkGroup) {
      bulkGroup.addEventListener('change', function () {
        var gid = this.value;
        if (gid === '') return;
        var ids = selectedIds();
        if (ids.length === 0) return;
        var groupId = parseInt(gid, 10);
        var label = groupId === 0 ? 'Ungrouped' : this.options[this.selectedIndex].textContent;
        if (confirm('Move ' + ids.length + ' student(s) to "' + label + '"?')) {
          api('/api/teacher/students.php?action=batch_group', {
            method: 'POST',
            body: JSON.stringify({ action: 'batch_group', student_ids: ids, group_id: groupId || null }),
          }).then(function () {
            clearSelection();
            reload();
          });
        }
        this.value = '';
      });
    }

    var bulkRemove = $('#stu-bulk-remove', root);
    if (bulkRemove) {
      bulkRemove.addEventListener('click', function () {
        var ids = selectedIds();
        if (ids.length === 0) return;
        if (confirm('Remove ' + ids.length + ' student(s) from your list?')) {
          api('/api/teacher/students.php?action=remove_bulk', {
            method: 'POST',
            body: JSON.stringify({ action: 'remove_bulk', student_ids: ids }),
          }).then(function () {
            clearSelection();
            reload();
          });
        }
      });
    }

    var bulkClear = $('#stu-bulk-clear', root);
    if (bulkClear) {
      bulkClear.addEventListener('click', function () {
        clearSelection();
        render();
      });
    }
  }

  // ──────────────────────────────────────────────────────────
  // Add Students Modal (with select-all + shift-click)
  // ──────────────────────────────────────────────────────────

  function openAddModal() {
    api('/api/teacher/students.php?action=available').then(function (data) {
      var students = data.students || [];
      var overlay = document.createElement('div');
      overlay.className = 'stu-modal-overlay';
      var addSelected = {};
      var addLastChecked = null;

      function renderAddContent() {
        var selCount = Object.keys(addSelected).length;
        var allChecked = students.length > 0 && students.every(function (s) { return !!addSelected[s.id]; });

        var html = '<div class="stu-modal stu-modal--add">' +
          '<div class="stu-modal__header">' +
            '<h3>Add Students</h3>' +
            '<button class="stu-modal__close">&times;</button>' +
          '</div>' +
          '<div class="stu-modal__body">';

        if (students.length === 0) {
          html += '<p class="stu-empty__text">No student accounts on this device yet.</p>' +
            '<p class="stu-empty__sub">Use the <strong>Import</strong> button to add students from a list.</p>';
        } else {
          html += '<div class="stu-add-toolbar">' +
            '<label class="stu-add-item stu-add-item--all">' +
              '<input type="checkbox" class="stu-add-check-all" id="stu-add-check-all"' + (allChecked ? ' checked' : '') + '>' +
              '<span class="stu-add-name"><strong>Select All</strong> (' + students.length + ' students)</span>' +
            '</label>' +
            (selCount > 0 ? '<span class="stu-add-count">' + selCount + ' selected</span>' : '') +
          '</div>';
          html += '<div class="stu-add-list">';
          for (var i = 0; i < students.length; i++) {
            var s = students[i];
            var warn = s.other_teachers ? ' <span class="stu-add-warn">(Also with: ' + escHtml(s.other_teachers) + ')</span>' : '';
            var checked = !!addSelected[s.id];
            html += '<label class="stu-add-item' + (checked ? ' stu-add-item--checked' : '') + '" data-idx="' + i + '">' +
              '<input type="checkbox" class="stu-add-check" value="' + s.id + '"' + (checked ? ' checked' : '') + '>' +
              avatarHtml(s.display_name, s.avatar_color) +
              '<span class="stu-add-name">' + escHtml(s.display_name) + warn + '</span>' +
            '</label>';
          }
          html += '</div>';
        }

        html += '</div>' +
          '<div class="stu-modal__footer">' +
            '<button class="stu-btn stu-btn--secondary stu-modal__cancel">Cancel</button>' +
            '<button class="stu-btn stu-btn--primary" id="stu-assign-btn" ' + (selCount === 0 ? 'disabled' : '') + '>Assign' + (selCount > 0 ? ' (' + selCount + ')' : '') + '</button>' +
          '</div>' +
        '</div>';

        overlay.innerHTML = html;

        // Close handlers
        function close() { overlay.remove(); }
        $('.stu-modal__close', overlay).addEventListener('click', close);
        $('.stu-modal__cancel', overlay).addEventListener('click', close);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

        // Select all
        var checkAllBtn = $('#stu-add-check-all', overlay);
        if (checkAllBtn) {
          checkAllBtn.addEventListener('change', function () {
            var on = this.checked;
            students.forEach(function (s) {
              if (on) addSelected[s.id] = true;
              else delete addSelected[s.id];
            });
            addLastChecked = null;
            renderAddContent();
          });
        }

        // Individual checks with shift-click
        $$('.stu-add-check', overlay).forEach(function (cb) {
          cb.addEventListener('click', function (e) {
            var sid = parseInt(this.value, 10);
            var idx = parseInt(this.closest('.stu-add-item').getAttribute('data-idx'), 10);

            if (e.shiftKey && addLastChecked !== null) {
              var start = Math.min(addLastChecked, idx);
              var end = Math.max(addLastChecked, idx);
              var shouldCheck = this.checked;
              for (var ri = start; ri <= end; ri++) {
                if (shouldCheck) addSelected[students[ri].id] = true;
                else delete addSelected[students[ri].id];
              }
            } else {
              if (this.checked) addSelected[sid] = true;
              else delete addSelected[sid];
            }

            addLastChecked = idx;
            renderAddContent();
          });
        });

        // Assign
        var assignBtn = $('#stu-assign-btn', overlay);
        if (assignBtn) {
          assignBtn.addEventListener('click', function () {
            var ids = [];
            for (var k in addSelected) { if (addSelected[k]) ids.push(parseInt(k, 10)); }
            if (ids.length === 0) return;
            assignBtn.disabled = true;
            assignBtn.textContent = 'Assigning ' + ids.length + '...';
            api('/api/teacher/students.php?action=assign', {
              method: 'POST',
              body: JSON.stringify({ action: 'assign', student_ids: ids }),
            }).then(function () {
              close();
              reload();
            });
          });
        }
      }

      document.body.appendChild(overlay);
      renderAddContent();
    });
  }

  // ──────────────────────────────────────────────────────────
  // CSV Import Modal
  // ──────────────────────────────────────────────────────────

  function openImportModal() {
    var overlay = document.createElement('div');
    overlay.className = 'stu-modal-overlay';

    var html = '<div class="stu-modal stu-modal--import">' +
      '<div class="stu-modal__header">' +
        '<h3>Import Students</h3>' +
        '<button class="stu-modal__close">&times;</button>' +
      '</div>' +
      '<div class="stu-modal__body">' +
        '<p class="stu-import-hint">Paste student names (one per line) or CSV data with a <strong>name</strong> column. New accounts will be created automatically.</p>' +
        '<div class="stu-import-tabs">' +
          '<button class="stu-import-tab stu-import-tab--active" data-tab="paste">Paste Names</button>' +
          '<button class="stu-import-tab" data-tab="file">Upload CSV</button>' +
        '</div>' +
        '<div class="stu-import-panel" id="stu-import-paste">' +
          '<textarea class="stu-import-textarea" id="stu-import-text" placeholder="John Doe\nJane Smith\nAlex B\n\n— or CSV format —\nname,type\nJohn Doe,student\nJane Smith,teen" rows="10"></textarea>' +
        '</div>' +
        '<div class="stu-import-panel stu-import-panel--hidden" id="stu-import-file">' +
          '<label class="stu-file-drop" id="stu-file-drop">' +
            '<input type="file" accept=".csv,.txt" id="stu-file-input" class="stu-file-input">' +
            '<svg width="32" height="32" viewBox="0 0 32 32" fill="none"><path d="M16 4v18M10 10l6-6 6 6" stroke="#bec2c8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 22v4a2 2 0 002 2h20a2 2 0 002-2v-4" stroke="#bec2c8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
            '<span class="stu-file-drop__text">Drop a CSV/TXT file here or click to browse</span>' +
            '<span class="stu-file-drop__name" id="stu-file-name"></span>' +
          '</label>' +
        '</div>' +
        '<div class="stu-import-result" id="stu-import-result"></div>' +
      '</div>' +
      '<div class="stu-modal__footer">' +
        '<button class="stu-btn stu-btn--secondary stu-modal__cancel">Cancel</button>' +
        '<button class="stu-btn stu-btn--primary" id="stu-import-go">Import</button>' +
      '</div>' +
    '</div>';

    overlay.innerHTML = html;
    document.body.appendChild(overlay);

    var activeTab = 'paste';
    var fileContent = null;

    function close() { overlay.remove(); }
    $('.stu-modal__close', overlay).addEventListener('click', close);
    $('.stu-modal__cancel', overlay).addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

    // Tab switching
    $$('.stu-import-tab', overlay).forEach(function (tab) {
      tab.addEventListener('click', function () {
        activeTab = this.getAttribute('data-tab');
        $$('.stu-import-tab', overlay).forEach(function (t) { t.classList.remove('stu-import-tab--active'); });
        this.classList.add('stu-import-tab--active');
        $('#stu-import-paste', overlay).classList.toggle('stu-import-panel--hidden', activeTab !== 'paste');
        $('#stu-import-file', overlay).classList.toggle('stu-import-panel--hidden', activeTab !== 'file');
      });
    });

    // File input
    var fileInput = $('#stu-file-input', overlay);
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        $('#stu-file-name', overlay).textContent = file.name;
        var reader = new FileReader();
        reader.onload = function (e) { fileContent = e.target.result; };
        reader.readAsText(file);
      });
    }

    // Import action
    var goBtn = $('#stu-import-go', overlay);
    goBtn.addEventListener('click', function () {
      var text = activeTab === 'paste'
        ? ($('#stu-import-text', overlay).value || '').trim()
        : (fileContent || '').trim();

      if (!text) {
        $('#stu-import-result', overlay).innerHTML = '<span class="stu-import-err">Please enter or upload student names.</span>';
        return;
      }

      goBtn.disabled = true;
      goBtn.textContent = 'Importing...';
      $('#stu-import-result', overlay).innerHTML = '<span class="stu-import-info">Processing...</span>';

      api('/api/teacher/students.php?action=import_csv', {
        method: 'POST',
        body: JSON.stringify({ action: 'import_csv', csv_text: text }),
      }).then(function (res) {
        if (res.error) {
          $('#stu-import-result', overlay).innerHTML = '<span class="stu-import-err">' + escHtml(res.error) + '</span>';
          goBtn.disabled = false;
          goBtn.textContent = 'Import';
          return;
        }

        var msg = '<span class="stu-import-ok">' +
          '<strong>' + res.assigned + '</strong> student(s) assigned' +
          (res.created > 0 ? ', <strong>' + res.created + '</strong> new account(s) created' : '') +
          (res.skipped > 0 ? ', <strong>' + res.skipped + '</strong> skipped' : '') +
          '</span>';
        if (res.errors && res.errors.length > 0) {
          msg += '<div class="stu-import-errs">' + res.errors.map(escHtml).join('<br>') + '</div>';
        }
        $('#stu-import-result', overlay).innerHTML = msg;
        goBtn.textContent = 'Done';

        // Auto-close after 1.5s
        setTimeout(function () { close(); reload(); }, 1500);
      });
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
          var title = (w.content_title || w.content_id || '').replace(/\.(mp4|mov|wmv|flv|f4v|avi|webm|mkv)$/i, '');
          var href = w.url ? w.url : '/' + (w.content_id || '');
          html += '<a class="stu-history-item stu-history-item--link" href="' + escHtml(href) + '" target="_blank">' +
            '<div class="stu-history-item__title">' + escHtml(title) +
              '<svg class="stu-history-item__icon" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M4.5 2H2.5a1 1 0 00-1 1v6.5a1 1 0 001 1H9a1 1 0 001-1V7.5M7 1.5h3.5V5M5.5 6.5L10.5 1.5" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
            '</div>' +
            '<div class="stu-history-item__meta">' +
              '<span class="stu-history-item__pct">' + w.completion_pct + '%</span>' +
              '<div class="stu-progress-bar"><div class="stu-progress-bar__fill" style="width:' + w.completion_pct + '%"></div></div>' +
              '<span class="stu-history-item__time">' + timeAgo(w.last_watched) + '</span>' +
            '</div>' +
          '</a>';
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

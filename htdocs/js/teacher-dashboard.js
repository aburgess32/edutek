/**
 * Teacher Dashboard JS (FRE-39)
 *
 * Fetches from dashboard_overview.php and dashboard_usage.php,
 * renders the full visual storytelling dashboard with SVG charts.
 *
 * Vanilla JS only — no libraries. < 50KB budget.
 */

(function() {
  'use strict';

  var API_OVERVIEW = '/api/teacher/dashboard_overview.php';
  var API_USAGE    = '/api/teacher/dashboard_usage.php';
  var API_SEED     = '/api/teacher/seed_dashboard.php';
  var API_GROUPS   = '/api/teacher/groups.php';
  var API_STUDENTS = '/api/teacher/students.php';
  var root = null;
  var currentScope = 'all';  // FRE-47: 'all', 'mine', or group ID

  // ─── SVG Icon Library (no emojis) ───────────────────────────────────────────

  var ICONS = {
    chart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="12" width="4" height="9" rx="1"/><rect x="10" y="7" width="4" height="14" rx="1"/><rect x="17" y="3" width="4" height="18" rx="1"/></svg>',
    refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>',
    clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    play: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>',
    doc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
    headphones: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 18v-6a9 9 0 0118 0v6"/><path d="M21 19a2 2 0 01-2 2h-1a2 2 0 01-2-2v-3a2 2 0 012-2h3v5z"/><path d="M3 19a2 2 0 002 2h1a2 2 0 002-2v-3a2 2 0 00-2-2H3v5z"/></svg>',
    puzzle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg>',
    alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
  };

  function icon(name, w, h) {
    w = w || 18; h = h || 18;
    return '<span style="display:inline-flex;align-items:center;width:' + w + 'px;height:' + h + 'px">' + (ICONS[name] || '') + '</span>';
  }

  function typeIcon(contentType) {
    var t = (contentType || 'video').toLowerCase();
    if (t === 'video') return 'play';
    if (t === 'audiobook') return 'headphones';
    if (t === 'interactive' || t === 'tool') return 'puzzle';
    return 'doc';
  }

  // ─── Helpers ────────────────────────────────────────────────────────────────

  function esc(str) {
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function fetchJSON(url) {
    return fetch(url).then(function(r) {
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
    if (diffDay < 30) return Math.floor(diffDay / 7) + 'w ago';
    return d.toLocaleDateString('en', { month: 'short', day: 'numeric' });
  }

  // ─── Skeleton ───────────────────────────────────────────────────────────────

  function renderSkeleton() {
    var teacherName = document.body.getAttribute('data-teacher-name') || 'Teacher';
    return '' +
      '<div class="dash-scope-bar" id="d-scope-bar">' +
        '<label class="dash-scope-label">Showing:</label>' +
        '<select class="dash-scope-select" id="d-scope-select">' +
          '<option value="all">All Students</option>' +
          '<option value="mine">My Students</option>' +
        '</select>' +
      '</div>' +
      '<div class="card welcome-banner" id="d-welcome">' +
        '<h2>Welcome back, ' + esc(teacherName) + '</h2>' +
        '<p id="d-welcome-sub">Loading your dashboard...</p>' +
      '</div>' +

      '<div class="kpi-grid" id="d-kpis">' +
        '<div class="kpi-card"><div class="kpi-label">Active Students</div><div class="kpi-value">--</div></div>' +
        '<div class="kpi-card"><div class="kpi-label">Watch Time (7d)</div><div class="kpi-value">--</div></div>' +
        '<div class="kpi-card"><div class="kpi-label">Avg Completion</div><div class="kpi-value">--</div></div>' +
        '<div class="kpi-card"><div class="kpi-label">Content Library</div><div class="kpi-value">--</div></div>' +
      '</div>' +

      '<div class="two-col">' +
        '<div class="card" id="d-top-content"><div class="card-title">Top Content (7d)</div><div class="card-subtitle">Loading...</div></div>' +
        '<div class="card" id="d-attention"><div class="card-title">Needs Attention</div><div class="card-subtitle">Loading...</div></div>' +
      '</div>' +

      '<div class="card" id="d-engagement"><div class="card-title">Weekly Engagement</div><div class="card-subtitle">Loading...</div></div>' +

      '<div class="card" id="d-content-insights"><div class="card-title">Content Insights</div><div class="card-subtitle">Loading...</div></div>' +

      '<div class="card" id="d-search-queries"><div class="card-title">' + icon('search') + ' Search Queries</div><div class="card-subtitle">Loading...</div></div>' +

      '<div class="card" id="d-insights"><div class="card-title">Teacher Insights & Recommendations</div><div class="card-subtitle">Loading...</div></div>' +

      '<div class="card device-health" id="d-device"><div class="card-title">Device Health</div><div class="card-subtitle">Loading...</div></div>' +

      '<details class="usage-analysis" id="d-usage">' +
        '<summary>Usage Analysis <span class="usage-analysis__badge">Admin / Dev</span></summary>' +
        '<div class="usage-analysis__body" id="d-usage-body">Loading usage data...</div>' +
      '</details>' +

      '<div class="quick-actions">' +
        '<button class="action-btn" onclick="window.location.hash=\'playlists\'">My Lesson Plans</button>' +
        '<button class="action-btn" onclick="window.location.href=\'/index.php\'">Browse Content</button>' +
        '<button class="action-btn" id="d-seed-btn">Seed Test Data</button>' +
      '</div>';
  }

  // ─── KPI Cards ──────────────────────────────────────────────────────────────

  function renderKPIs(data) {
    var sparkMax = Math.max.apply(null, data.sparkline.length ? data.sparkline : [1]);
    if (sparkMax === 0) sparkMax = 1;

    function sparkBars(arr) {
      var html = '<div class="kpi-sparkline">';
      for (var i = 0; i < arr.length; i++) {
        var pct = Math.round((arr[i] / sparkMax) * 100);
        var isLast = i === arr.length - 1;
        var bg = isLast ? 'var(--accent)' : 'var(--accent-light)';
        if (pct < 15) bg = 'var(--teacher-border)';
        html += '<div class="kpi-sparkline__bar" style="height:' + Math.max(4, pct) + '%;background:' + bg + '"></div>';
      }
      return html + '</div>';
    }

    var delta = data.active_this_week - data.prev_active_week;
    var deltaClass = delta > 0 ? 'kpi-delta--up' : delta < 0 ? 'kpi-delta--down' : 'kpi-delta--neutral';
    var deltaArrow = delta > 0 ? '&#9650; ' : delta < 0 ? '&#9660; ' : '';
    var deltaText = delta !== 0 ? deltaArrow + Math.abs(delta) + ' from last week' : 'Same as last week';

    var watchMin = Math.round(data.watch_time_sec / 60);
    var avgPerStudent = data.active_this_week > 0 ? Math.round(watchMin / data.active_this_week) : 0;

    var el = document.getElementById('d-kpis');
    el.innerHTML =
      '<div class="kpi-card">' +
        '<div class="kpi-label">Active Students</div>' +
        '<div class="kpi-value">' + data.active_this_week + ' / ' + data.total_students + '</div>' +
        '<div class="kpi-delta ' + deltaClass + '">' + deltaText + '</div>' +
        sparkBars(data.sparkline) +
        '<div class="kpi-context">' + (delta > 0 ? 'Trending up -- ' + delta + ' students re-engaged this week' : delta < 0 ? 'Some students dropped off this week' : 'Holding steady') + '</div>' +
      '</div>' +

      '<div class="kpi-card">' +
        '<div class="kpi-label">Watch Time (7d)</div>' +
        '<div class="kpi-value">' + secondsToHM(data.watch_time_sec) + '</div>' +
        '<div class="kpi-delta kpi-delta--neutral">avg ' + avgPerStudent + ' min per student</div>' +
        sparkBars(data.sparkline) +
        '<div class="kpi-context">Across all students this week</div>' +
      '</div>' +

      '<div class="kpi-card">' +
        '<div class="kpi-label">Avg Completion</div>' +
        '<div class="kpi-value">' + data.avg_completion + '%</div>' +
        '<div class="kpi-delta ' + (data.avg_completion >= 70 ? 'kpi-delta--up' : data.avg_completion >= 50 ? 'kpi-delta--neutral' : 'kpi-delta--down') + '">' +
          (data.avg_completion >= 70 ? 'Healthy' : data.avg_completion >= 50 ? 'Needs improvement' : 'Below target') +
        '</div>' +
        '<div class="kpi-context">' + (data.avg_completion >= 70 ? 'Students are finishing most content' : 'Consider shorter content or more engaging material') + '</div>' +
      '</div>' +

      '<div class="kpi-card">' +
        '<div class="kpi-label">Content Library</div>' +
        '<div class="kpi-value">' + data.content_count + '</div>' +
        '<div class="kpi-delta kpi-delta--neutral">items indexed</div>' +
        '<div class="kpi-context">Videos, audiobooks, and interactive content</div>' +
      '</div>';
  }

  // ─── Welcome Sub ────────────────────────────────────────────────────────────

  function renderWelcomeSub(kpis) {
    var el = document.getElementById('d-welcome-sub');
    if (el) {
      el.textContent = 'Your students had ' + kpis.active_this_week + ' active learners and ' +
        secondsToHM(kpis.watch_time_sec) + ' of watch time this week';
    }
  }

  // ─── Top Content ────────────────────────────────────────────────────────────

  function renderTopContent(data) {
    var el = document.getElementById('d-top-content');
    if (!data || data.length === 0) {
      el.innerHTML = '<div class="card-title">Top Content (7d)</div>' +
        '<div class="card-subtitle">No watch data yet</div>';
      return;
    }

    var html = '<div class="card-title">Top Content (7d)</div>' +
      '<div class="card-subtitle">What students are watching most -- ranked by views</div>' +
      '<ul class="content-rank-list">';

    var rankClasses = ['rank-num--gold', 'rank-num--silver', 'rank-num--bronze', '', ''];

    data.forEach(function(item, i) {
      html += '<li class="content-rank-item">' +
        '<span class="rank-num ' + (rankClasses[i] || '') + '">' + (i + 1) + '</span>' +
        '<div class="rank-info">' +
          '<div class="rank-title">' + esc(item.title) + '</div>' +
          '<div class="rank-meta">' + item.views + ' views &middot; ' + item.avg_completion + '% completion</div>' +
          '<div class="rank-progress-track"><div class="rank-progress-bar" style="width:' + item.avg_completion + '%"></div></div>' +
        '</div>' +
      '</li>';
    });

    el.innerHTML = html + '</ul>';
  }

  // ─── Needs Attention ────────────────────────────────────────────────────────

  function renderAttention(data) {
    var el = document.getElementById('d-attention');
    if (!data || data.length === 0) {
      el.innerHTML = '<div class="card-title">Needs Attention</div>' +
        '<div class="card-subtitle">All students are active -- great work!</div>';
      return;
    }

    var html = '<div class="card-title">Needs Attention</div>' +
      '<div class="card-subtitle">Students who may need a check-in or encouragement</div>' +
      '<div class="attention-header">' +
        '<span class="attention-dot"></span>' +
        '<span class="attention-count">' + data.length + ' student' + (data.length !== 1 ? 's' : '') + ' inactive for 7+ days</span>' +
      '</div>' +
      '<ul class="attention-list">';

    data.forEach(function(s) {
      var days = parseInt(s.days_inactive, 10) || 0;
      var sevClass = days >= 14 ? 'attention-severity--high' : 'attention-severity--med';
      html += '<li class="attention-item">' +
        '<span class="attention-name"><span class="dot"></span> ' + esc(s.display_name || s.avatar_name || 'Unknown') +
        '<span class="attention-severity ' + sevClass + '">' + days + 'd</span></span>' +
        '<span class="attention-time">' + relativeDate(s.last_active) + '</span>' +
      '</li>';
    });

    el.innerHTML = html + '</ul>';
  }

  // ─── Weekly Engagement ──────────────────────────────────────────────────────

  function renderEngagement(data) {
    var el = document.getElementById('d-engagement');
    if (!data || data.length === 0) {
      el.innerHTML = '<div class="card-title">Weekly Engagement</div>' +
        '<div class="card-subtitle">No engagement data yet</div>';
      return;
    }

    var maxSec = Math.max.apply(null, data.map(function(d) { return d.seconds; }));
    if (maxSec === 0) maxSec = 1;

    var html = '<div class="card-title">Weekly Engagement</div>' +
      '<div class="card-subtitle">Watch time by day -- look for patterns</div>';

    data.forEach(function(d) {
      var pct = Math.round((d.seconds / maxSec) * 100);
      var fillClass = 'chart-bar-fill';
      var annotation = '<span style="width:80px"></span>';

      if (d.is_peak && d.seconds > 0) {
        fillClass += ' chart-bar-fill--peak';
        annotation = '<span class="chart-annotation chart-annotation--peak" style="width:80px">Peak day</span>';
      } else if (d.is_low && d.seconds === 0) {
        fillClass += ' chart-bar-fill--low';
        annotation = '<span class="chart-annotation chart-annotation--low" style="width:80px">No activity</span>';
      }

      html += '<div class="chart-row">' +
        '<span class="chart-day">' + esc(d.day_name) + '</span>' +
        '<div class="chart-bar-track"><div class="' + fillClass + '" style="width:' + Math.max(2, pct) + '%"></div></div>' +
        '<span class="chart-pct">' + secondsToHM(d.seconds) + '</span>' +
        annotation +
      '</div>';
    });

    // Legend
    html += '<div class="chart-legend">' +
      '<div class="chart-legend__item"><span class="chart-legend__dot" style="background:var(--accent)"></span> Weekday</div>' +
      '<div class="chart-legend__item"><span class="chart-legend__dot" style="background:linear-gradient(90deg,var(--accent),var(--accent-dark))"></span> Peak</div>' +
    '</div>';

    el.innerHTML = html;
  }

  // ─── Content Insights (uses top_content data) ──────────────────────────────

  function renderContentInsights(data) {
    var el = document.getElementById('d-content-insights');
    if (!data || data.length === 0) {
      el.innerHTML = '<div class="card-title">Content Insights</div>' +
        '<div class="card-subtitle">No content data yet</div>';
      return;
    }

    var html = '<div class="card-title">Content Insights</div>' +
      '<div class="card-subtitle">How students interact with each piece of content</div>' +
      '<div class="content-table-wrap"><table class="content-table">' +
      '<thead><tr><th>Content</th><th>Type</th><th>Views</th><th>Completion</th><th>Drop-off</th></tr></thead><tbody>';

    data.forEach(function(item) {
      var comp = item.avg_completion;
      var fillClass = comp >= 80 ? 'td-minibar__fill--high' : comp >= 50 ? 'td-minibar__fill--med' : 'td-minibar__fill--low';
      var dropoff = comp >= 80 ? '<span class="dropoff-badge dropoff--low">Low</span>' :
                    comp >= 50 ? '<span class="dropoff-badge dropoff--medium">Medium</span>' :
                    '<span class="dropoff-badge dropoff--high">High</span>';

      var iconName = typeIcon(item.content_type);
      html += '<tr>' +
        '<td class="td-title">' + icon(iconName, 16, 16) + ' ' + esc(item.title) + '</td>' +
        '<td>' + esc(item.content_type) + '</td>' +
        '<td>' + item.views + '</td>' +
        '<td><div class="td-minibar"><div class="td-minibar__track"><div class="td-minibar__fill ' + fillClass + '" style="width:' + comp + '%"></div></div> ' + comp + '%</div></td>' +
        '<td>' + dropoff + '</td>' +
      '</tr>';
    });

    el.innerHTML = html + '</tbody></table></div>';
  }

  // ─── Search Queries Card (main dashboard) ───────────────────────────────────

  function renderSearchQueriesCard(data) {
    var el = document.getElementById('d-search-queries');
    if (!el) return;

    if (!data || data.length === 0) {
      el.innerHTML = '<div class="card-title">' + icon('search') + ' Search Queries</div>' +
        '<div class="card-subtitle">No searches recorded yet. Queries will appear here once students use the search feature.</div>';
      return;
    }

    var maxSearches = Math.max.apply(null, data.map(function(d) { return parseInt(d.searches, 10); }));
    if (maxSearches === 0) maxSearches = 1;

    var html = '<div class="card-title">' + icon('search') + ' Search Queries</div>' +
      '<div class="card-subtitle">What students are searching for -- zero-result queries reveal content gaps</div>' +
      '<div class="content-table-wrap"><table class="content-table">' +
      '<thead><tr><th>Query</th><th>Popularity</th><th>Results</th><th>Searches</th></tr></thead><tbody>';

    data.forEach(function(item) {
      var searches = parseInt(item.searches, 10);
      var results = parseFloat(item.avg_results) || 0;
      var popPct = Math.round((searches / maxSearches) * 100);
      var isZero = results === 0;
      var fillClass = isZero ? 'sq-pop-fill sq-pop-fill--zero' : 'sq-pop-fill';
      var hitsClass = isZero ? 'sq-hits sq-hits--zero' : 'sq-hits';
      var zeroNote = isZero ? ' <span class="sq-zero-note">content gap</span>' : '';

      html += '<tr>' +
        '<td><strong>' + esc(item.query) + '</strong>' + zeroNote + '</td>' +
        '<td><div class="sq-pop-bar"><div class="' + fillClass + '" style="width:' + popPct + '%"></div></div></td>' +
        '<td><span class="' + hitsClass + '">' + Math.round(results) + '</span></td>' +
        '<td>' + searches + '</td>' +
      '</tr>';
    });

    el.innerHTML = html + '</tbody></table></div>';
  }

  // ─── Teacher Insights ──────────────────────────────────────────────────────

  function renderInsights(data) {
    var el = document.getElementById('d-insights');
    if (!data || data.length === 0) {
      el.innerHTML = '<div class="card-title">Teacher Insights & Recommendations</div>' +
        '<div class="card-subtitle">Not enough data yet to generate insights</div>';
      return;
    }

    var html = '<div class="card-title">Teacher Insights & Recommendations</div>' +
      '<div class="card-subtitle">Actionable suggestions based on your students\' activity patterns</div>';

    data.forEach(function(ins) {
      var pClass = 'priority-badge--' + ins.priority;
      var dClass = 'priority-dot--' + ins.priority;
      html += '<div class="insight-row">' +
        '<span class="priority-badge ' + pClass + '">' +
          '<span class="priority-dot ' + dClass + '"></span> ' + ins.priority.toUpperCase() +
        '</span>' +
        '<span class="insight-text">' + ins.text + '</span>' +
      '</div>';
    });

    el.innerHTML = html;
  }

  // ─── Device Health ──────────────────────────────────────────────────────────

  function renderDeviceHealth(data) {
    var el = document.getElementById('d-device');
    el.innerHTML =
      '<div class="card-title">Device Health</div>' +
      '<div class="health-row"><span class="health-label">Content Items</span><span class="health-value">' + data.content_items + '</span></div>' +
      '<div class="health-row"><span class="health-label">Total Users</span><span class="health-value">' + data.total_users + '</span></div>' +
      '<div class="health-row"><span class="health-label">Watch Records</span><span class="health-value">' + data.watch_records + '</span></div>' +
      '<div class="health-row"><span class="health-label">Database Size</span><span class="health-value">' + data.db_size_mb + ' MB</span></div>' +
      '<div class="health-status">' + (data.status === 'healthy' ? 'All systems normal' : data.status) + '</div>';
  }

  // ─── Usage Analysis: Age Groups ─────────────────────────────────────────────

  function renderAgeGroups(data) {
    if (!data || data.length === 0) return '<div class="ua-card ua-card--accent"><div class="ua-card__title">' + icon('chart') + ' Age Group Distribution</div><div class="ua-card__subtitle">No student data yet</div></div>';

    var maxCount = Math.max.apply(null, data.map(function(d) { return d.count; }));
    if (maxCount === 0) maxCount = 1;

    var html = '<div class="ua-card ua-card--accent">' +
      '<div class="ua-card__header"><div class="ua-card__title"><span class="ua-card__icon">' + ICONS.chart + '</span> Age Group Distribution</div></div>' +
      '<div class="ua-card__subtitle">Which age groups are using the device most?</div>';

    data.forEach(function(d, i) {
      var pct = Math.round((d.count / maxCount) * 100);
      html += '<div class="age-row">' +
        '<span class="age-tag age-tag--' + Math.min(i, 3) + '">' + esc(d.label).replace(/ /g, '') + '</span>' +
        '<div class="age-bar-track"><div class="age-bar-fill age-bar-fill--' + Math.min(i, 3) + '" style="width:' + Math.max(8, pct) + '%">' + pct + '%</div></div>' +
        '<div class="age-stat"><div class="age-stat__count">' + d.count + '</div><div class="age-stat__label">students</div></div>' +
      '</div>';
    });

    return html + '</div>';
  }

  // ─── Usage Analysis: Returning Users (SVG donut) ────────────────────────────

  function renderReturningUsers(data) {
    var ret = data.returning || 0;
    var one = data.onetime || 0;
    var total = ret + one;
    if (total === 0) return '<div class="ua-card ua-card--green"><div class="ua-card__title">' + icon('refresh') + ' Returning vs. One-Time</div><div class="ua-card__subtitle">No user data yet</div></div>';

    var retPct = Math.round((ret / total) * 100);
    var onePct = 100 - retPct;

    // SVG donut: r=54, C = 2*pi*54 = 339.29
    var C = 339.29;
    var retArc = (retPct / 100) * C;
    var oneArc = C - retArc;

    var html = '<div class="ua-card ua-card--green">' +
      '<div class="ua-card__header"><div class="ua-card__title"><span class="ua-card__icon">' + ICONS.refresh + '</span> Returning vs. One-Time Users</div></div>' +
      '<div class="ua-card__subtitle">Are users coming back? A healthy system has mostly returning visitors.</div>' +
      '<div class="ret-layout">' +
        '<div class="ret-donut">' +
          '<svg width="140" height="140" viewBox="0 0 140 140" style="transform:rotate(-90deg)">' +
            '<circle cx="70" cy="70" r="54" fill="none" stroke="#e8e4df" stroke-width="16"/>' +
            '<circle cx="70" cy="70" r="54" fill="none" stroke="#27ae60" stroke-width="16" ' +
              'stroke-dasharray="' + retArc.toFixed(1) + ' ' + oneArc.toFixed(1) + '" stroke-linecap="round"/>' +
          '</svg>' +
          '<div class="ret-donut__center">' +
            '<span class="ret-donut__total">' + total + '</span>' +
            '<span class="ret-donut__label">Total Users</span>' +
          '</div>' +
        '</div>' +
        '<div class="ret-details">' +
          '<div class="ret-detail-row">' +
            '<span class="ret-detail-dot" style="background:var(--green)"></span>' +
            '<div class="ret-detail-info"><div class="ret-detail-name">Returning</div><div class="ret-detail-desc">Visited 2+ times</div></div>' +
            '<span class="ret-detail-val" style="color:var(--green)">' + ret + '</span>' +
            '<span class="ret-detail-pct">' + retPct + '%</span>' +
          '</div>' +
          '<div class="ret-detail-row">' +
            '<span class="ret-detail-dot" style="background:var(--teacher-border)"></span>' +
            '<div class="ret-detail-info"><div class="ret-detail-name">One-Time</div><div class="ret-detail-desc">Only visited once</div></div>' +
            '<span class="ret-detail-val" style="color:var(--amber)">' + one + '</span>' +
            '<span class="ret-detail-pct">' + onePct + '%</span>' +
          '</div>' +
        '</div>' +
      '</div>';

    return html + '</div>';
  }

  // ─── Usage Analysis: Peak Hours (timeline bubbles) ──────────────────────────

  function renderPeakHours(data) {
    if (!data || data.length === 0) return '<div class="ua-card ua-card--amber"><div class="ua-card__title">' + icon('clock') + ' Peak Usage Hours</div><div class="ua-card__subtitle">No activity data yet</div></div>';

    // Show only hours 6am to 10pm for readability
    var filtered = data.filter(function(d) { return d.hour >= 6 && d.hour <= 22; });
    // Group into 2-hour blocks for cleaner display
    var blocks = [];
    for (var h = 6; h <= 20; h += 2) {
      var count = 0;
      var label = '';
      for (var j = h; j < h + 2 && j < 24; j++) {
        var match = filtered.find(function(d) { return d.hour === j; });
        if (match) count += match.count;
      }
      label = (h > 12 ? (h - 12) : h) + (h < 12 ? 'AM' : 'PM');
      blocks.push({ hour: h, label: label, count: count });
    }

    var maxCount = Math.max.apply(null, blocks.map(function(b) { return b.count; }));
    if (maxCount === 0) maxCount = 1;

    var html = '<div class="ua-card ua-card--amber">' +
      '<div class="ua-card__header"><div class="ua-card__title"><span class="ua-card__icon">' + ICONS.clock + '</span> Peak Usage Hours</div></div>' +
      '<div class="ua-card__subtitle">Bubble size = number of sessions. Schedule lessons around peak times.</div>' +
      '<div class="peak-timeline">';

    blocks.forEach(function(b) {
      var ratio = b.count / maxCount;
      var size = Math.round(24 + ratio * 36); // 24px to 60px
      var tempClass = ratio === 0 ? 'peak-bubble--cold' :
                      ratio < 0.3 ? 'peak-bubble--cold' :
                      ratio < 0.6 ? 'peak-bubble--warm' :
                      ratio < 0.9 ? 'peak-bubble--hot' : 'peak-bubble--hottest';
      var peakLabel = ratio >= 0.9 && b.count > 0 ? '<span class="peak-label">Peak</span>' : '';
      var fontSize = size < 32 ? 10 : size < 44 ? 12 : 14;

      html += '<div class="peak-slot">' +
        peakLabel +
        '<div class="peak-bubble ' + tempClass + '" style="width:' + size + 'px;height:' + size + 'px;font-size:' + fontSize + 'px">' + b.count + '</div>' +
        '<span class="peak-time">' + b.label + '</span>' +
      '</div>';
    });

    return html + '</div></div>';
  }

  // ─── Usage Analysis: Downloads ──────────────────────────────────────────────

  function renderDownloads(data) {
    var html = '<div class="ua-card ua-card--accent">' +
      '<div class="ua-card__header"><div class="ua-card__title"><span class="ua-card__icon">' + ICONS.download + '</span> Most Downloaded Content</div></div>' +
      '<div class="ua-card__subtitle">What content is being saved offline?</div>';

    if (!data || data.length === 0) {
      html += '<div style="padding:12px 0;color:var(--teacher-muted);font-size:13px;font-style:italic">No downloads recorded yet. Downloads will appear here once users save content offline.</div>';
      return html + '</div>';
    }

    html += '<div class="content-table-wrap"><table class="dl-table">' +
      '<thead><tr><th>Content</th><th>Type</th><th>Downloads</th></tr></thead><tbody>';

    data.forEach(function(item) {
      var iconName = typeIcon(item.content_type);
      var iconClass = item.content_type === 'video' ? 'dl-type-icon--video' : 'dl-type-icon--doc';
      html += '<tr>' +
        '<td><div class="dl-content-cell"><span class="dl-type-icon ' + iconClass + '">' + ICONS[iconName] + '</span><strong>' + esc(item.title) + '</strong></div></td>' +
        '<td>' + esc(item.content_type) + '</td>' +
        '<td><strong>' + item.downloads + '</strong></td>' +
      '</tr>';
    });

    return html + '</tbody></table></div></div>';
  }

  // ─── Usage Analysis: Search Queries ─────────────────────────────────────────

  function renderSearchQueries(data) {
    var html = '<div class="ua-card ua-card--accent">' +
      '<div class="ua-card__header"><div class="ua-card__title"><span class="ua-card__icon">' + ICONS.search + '</span> Search Queries</div></div>' +
      '<div class="ua-card__subtitle">What are students searching for? Zero-hit queries reveal content gaps.</div>';

    if (!data || data.length === 0) {
      html += '<div style="padding:12px 0;color:var(--teacher-muted);font-size:13px;font-style:italic">No searches recorded yet. Queries will appear here once students use the search feature.</div>';
      return html + '</div>';
    }

    var maxSearches = Math.max.apply(null, data.map(function(d) { return parseInt(d.searches, 10); }));
    if (maxSearches === 0) maxSearches = 1;

    html += '<div class="content-table-wrap"><table class="sq-table">' +
      '<thead><tr><th>Query</th><th>Popularity</th><th>Results</th><th>Searches</th></tr></thead><tbody>';

    data.forEach(function(item) {
      var searches = parseInt(item.searches, 10);
      var results = parseFloat(item.avg_results) || 0;
      var popPct = Math.round((searches / maxSearches) * 100);
      var isZero = results === 0;
      var fillClass = isZero ? 'sq-pop-fill sq-pop-fill--zero' : 'sq-pop-fill';
      var hitsClass = isZero ? 'sq-hits sq-hits--zero' : 'sq-hits';
      var zeroNote = isZero ? ' <span class="sq-zero-note">content gap</span>' : '';

      html += '<tr>' +
        '<td><strong>' + esc(item.query) + '</strong>' + zeroNote + '</td>' +
        '<td><div class="sq-pop-bar"><div class="' + fillClass + '" style="width:' + popPct + '%"></div></div></td>' +
        '<td><span class="' + hitsClass + '">' + Math.round(results) + '</span></td>' +
        '<td>' + searches + '</td>' +
      '</tr>';
    });

    return html + '</tbody></table></div></div>';
  }

  // ─── Seed Button ────────────────────────────────────────────────────────────

  function bindSeedButton() {
    var btn = document.getElementById('d-seed-btn');
    if (!btn) return;
    btn.addEventListener('click', function() {
      btn.textContent = 'Seeding...';
      btn.disabled = true;

      fetch(API_SEED, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        btn.textContent = 'Done! Refreshing...';
        setTimeout(function() {
          init(root);
        }, 500);
      })
      .catch(function(err) {
        btn.textContent = 'Seed Failed';
        setTimeout(function() {
          btn.textContent = 'Seed Test Data';
          btn.disabled = false;
        }, 2000);
      });
    });
  }

  // ─── FRE-47: Scope helpers ───────────────────────────────────────────────────────

  function loadScopeGroups() {
    fetchJSON(API_GROUPS + '?action=list').then(function(data) {
      var sel = document.getElementById('d-scope-select');
      if (!sel || !data.groups) return;
      data.groups.forEach(function(g) {
        var opt = document.createElement('option');
        opt.value = String(g.id);
        opt.textContent = g.name;
        sel.appendChild(opt);
      });
      sel.value = currentScope;
    }).catch(function() { /* groups table may not exist yet */ });
  }

  function resolveDefaultScope() {
    return fetchJSON(API_STUDENTS + '?action=list').then(function(data) {
      if (data.students && data.students.length > 0) {
        currentScope = 'mine';
        var sel = document.getElementById('d-scope-select');
        if (sel) sel.value = 'mine';
      }
    }).catch(function() { /* keep default */ });
  }

  function bindScopeSelect() {
    var sel = document.getElementById('d-scope-select');
    if (!sel) return;
    sel.addEventListener('change', function() {
      currentScope = this.value;
      loadDashboardData();
    });
  }

  function loadDashboardData() {
    var scopeParam = '&scope=' + encodeURIComponent(currentScope);
    Promise.all([
      fetchJSON(API_OVERVIEW + '?action=kpis' + scopeParam),
      fetchJSON(API_OVERVIEW + '?action=top_content' + scopeParam),
      fetchJSON(API_OVERVIEW + '?action=needs_attention' + scopeParam),
      fetchJSON(API_OVERVIEW + '?action=engagement' + scopeParam),
      fetchJSON(API_OVERVIEW + '?action=insights' + scopeParam),
      fetchJSON(API_OVERVIEW + '?action=device_health'),
      fetchJSON(API_USAGE + '?action=age_groups'),
      fetchJSON(API_USAGE + '?action=returning_users'),
      fetchJSON(API_USAGE + '?action=peak_hours'),
      fetchJSON(API_USAGE + '?action=downloads'),
      fetchJSON(API_USAGE + '?action=search_queries'),
    ]).then(function(results) {
      var kpis        = results[0];
      var topContent  = results[1];
      var attention   = results[2];
      var engagement  = results[3];
      var insights    = results[4];
      var health      = results[5];
      var ageGroups   = results[6];
      var returning   = results[7];
      var peakHours   = results[8];
      var downloads   = results[9];
      var searches    = results[10];

      renderKPIs(kpis);
      renderWelcomeSub(kpis);
      renderTopContent(topContent);
      renderAttention(attention);
      renderEngagement(engagement);
      renderContentInsights(topContent);
      renderSearchQueriesCard(searches);
      renderInsights(insights);
      renderDeviceHealth(health);

      var usageBody = document.getElementById('d-usage-body');
      if (usageBody) {
        usageBody.innerHTML =
          renderAgeGroups(ageGroups) +
          renderReturningUsers(returning) +
          renderPeakHours(peakHours) +
          renderDownloads(downloads) +
          renderSearchQueries(searches);
      }
    }).catch(function() {
      root.innerHTML =
        '<div class="card" style="text-align:center;padding:40px">' +
          '<div style="font-size:16px;font-weight:700;margin-bottom:8px">Dashboard could not load</div>' +
          '<div style="font-size:14px;color:var(--teacher-muted);margin-bottom:16px">' +
            'The API returned an error. Make sure the database tables exist.' +
          '</div>' +
          '<button class="action-btn" id="d-seed-btn-err" style="max-width:200px;margin:0 auto">Seed Test Data</button>' +
          '<div style="font-size:12px;color:var(--teacher-muted);margin-top:8px">This will create tables and sample data so you can test the dashboard.</div>' +
        '</div>';

      var errBtn = document.getElementById('d-seed-btn-err');
      if (errBtn) {
        errBtn.addEventListener('click', function() {
          errBtn.textContent = 'Seeding...';
          errBtn.disabled = true;
          fetch(API_SEED, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); })
            .then(function() {
              errBtn.textContent = 'Done! Refreshing...';
              setTimeout(function() { init(root); }, 500);
            })
            .catch(function() {
              errBtn.textContent = 'Failed - Check DB Connection';
            });
        });
      }
    });
  }

  // ─── Init ───────────────────────────────────────────────────────────────────

  function init(el) {
    root = el;
    root.innerHTML = renderSkeleton();
    bindSeedButton();
    bindScopeSelect();
    loadScopeGroups();
    resolveDefaultScope().then(function() { loadDashboardData(); });
  }

  // Export
  window.TeacherDashboard = { init: init };

  // Auto-initialize if dashboard-root exists (handles script load race condition)
  var autoRoot = document.getElementById('dashboard-root');
  if (autoRoot && autoRoot.closest('.tab-panel--active')) {
    init(autoRoot);
  }
})();

/**
 * Teacher Dashboard JS (FRE-13)
 *
 * Fetches student data and renders:
 * - Student table with totals
 * - Inline detail view per student (watched list + screen time chart)
 * - SVG bar chart for screen time (no charting library)
 *
 * < 50KB budget — vanilla JS only
 */

(function() {
  'use strict';

  var API_BASE = '/api/teacher/dashboard.php';
  var root = null;
  var expandedStudentId = null;

  // ─── Helpers ───────────────────────────────────────────────────────────────

  function secondsToHM(sec) {
    sec = Math.max(0, parseInt(sec, 10) || 0);
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    if (h > 0) return h + 'h ' + m + 'm';
    return m + 'm';
  }

  function relativeDate(dateStr) {
    if (!dateStr) return '—';
    var d = new Date(dateStr);
    var now = new Date();
    var diffMs = now - d;
    var diffMin = Math.floor(diffMs / 60000);
    var diffHr = Math.floor(diffMin / 60);
    var diffDay = Math.floor(diffHr / 24);

    if (diffMin < 1) return 'Just now';
    if (diffMin < 60) return diffMin + 'm ago';
    if (diffHr < 24) return diffHr + 'h ago';
    if (diffDay < 7) return diffDay + 'd ago';
    if (diffDay < 30) return Math.floor(diffDay / 7) + 'w ago';
    return d.toLocaleDateString('en', { month: 'short', day: 'numeric' });
  }

  function esc(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function fetchJSON(url) {
    return fetch(url).then(function(r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  // ─── Student Table ─────────────────────────────────────────────────────────

  function renderStudentTable(students) {
    if (!students || students.length === 0) {
      root.innerHTML =
        '<div class="dash-empty">' +
          '<div class="dash-empty__icon">&#x1F393;</div>' +
          '<div class="dash-empty__text">No student activity recorded yet.</div>' +
        '</div>';
      return;
    }

    var html =
      '<table class="student-table">' +
      '<thead><tr>' +
        '<th>Student</th>' +
        '<th>Type</th>' +
        '<th>Videos</th>' +
        '<th>Screen Time</th>' +
        '<th>Sessions</th>' +
        '<th>Last Active</th>' +
      '</tr></thead>' +
      '<tbody>';

    students.forEach(function(s) {
      var initials = (s.display_name || '?').charAt(0).toUpperCase();
      var typeBadge = s.user_type || 'student';
      var badgeClass = 'type-badge type-badge--' + typeBadge;

      html +=
        '<tr class="student-row" data-student-id="' + s.id + '">' +
          '<td data-label="Student">' +
            '<div class="student-name">' +
              '<div class="student-name__avatar">' + esc(initials) + '</div>' +
              '<span class="student-name__text">' + esc(s.display_name || 'Unknown') + '</span>' +
            '</div>' +
          '</td>' +
          '<td data-label="Type"><span class="' + badgeClass + '">' + esc(typeBadge) + '</span></td>' +
          '<td data-label="Videos">' + (parseInt(s.total_watched, 10) || 0) + '</td>' +
          '<td data-label="Screen Time">' + secondsToHM(s.total_screen_time_sec) + '</td>' +
          '<td data-label="Sessions">' + (parseInt(s.total_sessions, 10) || 0) + '</td>' +
          '<td data-label="Last Active" class="text-muted">' + relativeDate(s.last_active) + '</td>' +
        '</tr>' +
        '<tr class="student-detail" data-detail-for="' + s.id + '">' +
          '<td colspan="6">' +
            '<div class="student-detail__inner">' +
              '<div class="dash-loading"><div class="dash-spinner"></div><div>Loading...</div></div>' +
            '</div>' +
          '</td>' +
        '</tr>';
    });

    html += '</tbody></table>';
    root.innerHTML = html;

    // Bind row clicks
    root.querySelectorAll('.student-row').forEach(function(row) {
      row.addEventListener('click', function() {
        toggleStudentDetail(parseInt(this.getAttribute('data-student-id'), 10));
      });
    });
  }

  // ─── Student Detail Toggle ─────────────────────────────────────────────────

  function toggleStudentDetail(studentId) {
    // Close currently expanded
    if (expandedStudentId !== null) {
      var prevRow = root.querySelector('.student-row[data-student-id="' + expandedStudentId + '"]');
      var prevDetail = root.querySelector('.student-detail[data-detail-for="' + expandedStudentId + '"]');
      if (prevRow) prevRow.classList.remove('student-row--expanded');
      if (prevDetail) prevDetail.classList.remove('student-detail--open');
    }

    // If clicking same row, just collapse
    if (expandedStudentId === studentId) {
      expandedStudentId = null;
      return;
    }

    expandedStudentId = studentId;
    var row = root.querySelector('.student-row[data-student-id="' + studentId + '"]');
    var detail = root.querySelector('.student-detail[data-detail-for="' + studentId + '"]');
    if (row) row.classList.add('student-row--expanded');
    if (detail) {
      detail.classList.add('student-detail--open');
      loadStudentDetail(studentId, detail.querySelector('.student-detail__inner'));
    }
  }

  function loadStudentDetail(studentId, container) {
    container.innerHTML =
      '<div class="dash-loading"><div class="dash-spinner"></div><div>Loading...</div></div>';

    Promise.all([
      fetchJSON(API_BASE + '?action=watched&student_id=' + studentId + '&offset=0'),
      fetchJSON(API_BASE + '?action=screen_time&student_id=' + studentId + '&days=14')
    ]).then(function(results) {
      var watched = results[0];
      var screenTime = results[1];

      container.innerHTML =
        '<div class="detail-sections">' +
          '<div class="detail-section">' +
            '<div class="detail-section__title">Screen Time</div>' +
            '<div class="chart-controls">' +
              '<button class="chart-btn chart-btn--active" data-days="7">7d</button>' +
              '<button class="chart-btn" data-days="14">14d</button>' +
              '<button class="chart-btn" data-days="30">30d</button>' +
            '</div>' +
            '<div class="chart-container" id="chart-' + studentId + '"></div>' +
          '</div>' +
          '<div class="detail-section">' +
            '<div class="detail-section__title">Recently Watched</div>' +
            '<div class="watched-list" id="watched-' + studentId + '"></div>' +
            (watched.length >= 50 ?
              '<button class="load-more-btn" data-student-id="' + studentId + '" data-offset="50">Load more</button>' : '') +
          '</div>' +
        '</div>';

      // Render chart (show last 7 days from the 14-day data initially)
      renderScreenTimeChart(
        document.getElementById('chart-' + studentId),
        screenTime, 7
      );

      // Render watched list
      renderWatchedList(
        document.getElementById('watched-' + studentId),
        watched
      );

      // Chart toggle buttons
      container.querySelectorAll('.chart-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
          container.querySelectorAll('.chart-btn').forEach(function(b) {
            b.classList.remove('chart-btn--active');
          });
          this.classList.add('chart-btn--active');
          var days = parseInt(this.getAttribute('data-days'), 10);
          var chartEl = document.getElementById('chart-' + studentId);
          chartEl.innerHTML =
            '<div class="dash-loading"><div class="dash-spinner"></div></div>';

          fetchJSON(API_BASE + '?action=screen_time&student_id=' + studentId + '&days=' + days)
            .then(function(data) {
              renderScreenTimeChart(chartEl, data, days);
            });
        });
      });

      // Load more button
      var loadMoreBtn = container.querySelector('.load-more-btn');
      if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function() {
          var btn = this;
          var offset = parseInt(btn.getAttribute('data-offset'), 10);
          btn.textContent = 'Loading...';
          btn.disabled = true;

          fetchJSON(API_BASE + '?action=watched&student_id=' + studentId + '&offset=' + offset)
            .then(function(data) {
              if (data.length === 0) {
                btn.remove();
                return;
              }
              renderWatchedList(
                document.getElementById('watched-' + studentId),
                data, true
              );
              btn.setAttribute('data-offset', offset + 50);
              btn.textContent = 'Load more';
              btn.disabled = false;
              if (data.length < 50) btn.remove();
            });
        });
      }
    }).catch(function() {
      container.innerHTML =
        '<div class="dash-empty"><div class="dash-empty__text">Failed to load student data.</div></div>';
    });
  }

  // ─── Watched List ──────────────────────────────────────────────────────────

  function renderWatchedList(container, items, append) {
    if (!items || items.length === 0) {
      if (!append) {
        container.innerHTML =
          '<div class="dash-empty" style="padding:20px 0">' +
            '<div class="dash-empty__text" style="font-size:13px">No watched content yet.</div>' +
          '</div>';
      }
      return;
    }

    var html = '';
    items.forEach(function(item) {
      var pct = Math.min(100, Math.max(0, parseInt(item.progress_pct, 10) || 0));
      var title = item.content_title || item.content_id || 'Untitled';

      html +=
        '<div class="watched-item">' +
          '<div class="watched-item__info">' +
            '<div class="watched-item__title">' + esc(title) + '</div>' +
            '<div class="watched-item__meta">' +
              secondsToHM(item.progress_seconds) + ' / ' +
              secondsToHM(item.duration_seconds) +
              ' &middot; ' + relativeDate(item.last_watched) +
            '</div>' +
            '<div class="watched-item__progress">' +
              '<div class="watched-item__progress-bar" style="width:' + pct + '%"></div>' +
            '</div>' +
          '</div>' +
        '</div>';
    });

    if (append) {
      container.insertAdjacentHTML('beforeend', html);
    } else {
      container.innerHTML = html;
    }
  }

  // ─── SVG Bar Chart ─────────────────────────────────────────────────────────

  function renderScreenTimeChart(container, data, maxDays) {
    if (!data || data.length === 0) {
      container.innerHTML =
        '<div class="dash-empty" style="padding:20px 0">' +
          '<div class="dash-empty__text" style="font-size:13px">No screen time data for this period.</div>' +
        '</div>';
      return;
    }

    // Build full date range (fill gaps with 0)
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var dateMap = {};
    data.forEach(function(d) { dateMap[d.watch_date] = parseInt(d.seconds, 10) || 0; });

    var fullData = [];
    for (var i = maxDays - 1; i >= 0; i--) {
      var dt = new Date(today);
      dt.setDate(dt.getDate() - i);
      var key = dt.toISOString().slice(0, 10);
      fullData.push({
        date: key,
        seconds: dateMap[key] || 0,
        dayObj: dt
      });
    }

    var maxSec = Math.max.apply(null, fullData.map(function(d) { return d.seconds; }));
    if (maxSec === 0) maxSec = 1;

    var svgHeight = 180;
    var labelHeight = 22;
    var topPad = 20;
    var chartHeight = svgHeight - labelHeight - topPad;
    var barGap = 2;
    var totalBars = fullData.length;
    var availWidth = Math.max(totalBars * 20, 300);
    var barWidth = Math.max(8, Math.floor((availWidth - (totalBars - 1) * barGap) / totalBars));
    var totalWidth = totalBars * (barWidth + barGap);

    var bars = '';
    fullData.forEach(function(d, i) {
      var h = Math.max(0, Math.round((d.seconds / maxSec) * chartHeight));
      var x = i * (barWidth + barGap);
      var y = topPad + chartHeight - h;
      var mins = Math.round(d.seconds / 60);
      var dayLabel = d.dayObj.toLocaleDateString('en', { weekday: 'short' }).slice(0, 3);

      // Bar
      bars += '<rect x="' + x + '" y="' + y + '" width="' + barWidth + '" height="' + h +
              '" fill="var(--accent, #4ECDC4)" rx="3" class="chart-bar">' +
              '<title>' + mins + ' min – ' + d.date + '</title></rect>';

      // Minutes label above bar (only if > 0)
      if (mins > 0) {
        bars += '<text x="' + (x + barWidth / 2) + '" y="' + (y - 4) +
                '" text-anchor="middle" class="bar-label">' + mins + '</text>';
      }

      // Day label below
      bars += '<text x="' + (x + barWidth / 2) + '" y="' + (svgHeight - 4) +
              '" text-anchor="middle" font-size="10">' + dayLabel + '</text>';
    });

    container.innerHTML =
      '<svg width="100%" height="' + svgHeight +
      '" viewBox="0 0 ' + totalWidth + ' ' + svgHeight +
      '" preserveAspectRatio="xMinYMid meet">' + bars + '</svg>';
  }

  // ─── Init ──────────────────────────────────────────────────────────────────

  function init(el) {
    root = el;
    root.innerHTML =
      '<div class="dash-loading"><div class="dash-spinner"></div><div>Loading student data...</div></div>';

    fetchJSON(API_BASE + '?action=students')
      .then(function(students) {
        renderStudentTable(students);
      })
      .catch(function() {
        root.innerHTML =
          '<div class="dash-empty">' +
            '<div class="dash-empty__icon">&#x26A0;</div>' +
            '<div class="dash-empty__text">Failed to load dashboard data.</div>' +
          '</div>';
      });
  }

  // Export
  window.TeacherDashboard = { init: init };
})();

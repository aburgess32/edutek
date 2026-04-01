<?php
/**
 * Teacher Hub (FRE-13)
 *
 * Role-gated page with 3 tabs: Dashboard, Playlists, Profile.
 * Tab switching via vanilla JS with URL hash support.
 */

require_once __DIR__ . '/includes/auth.php';

// Redirect non-teachers to login
requireTeacher();

$user = getCurrentUser();
$displayName = htmlspecialchars($user['display_name'] ?? 'Teacher');
$initials = mb_strtoupper(mb_substr($displayName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Teacher Hub – EduPak</title>
  <link rel="stylesheet" href="/css/teacher.css">
</head>
<body class="teacher-page">

  <!-- Header -->
  <header class="teacher-header">
    <div class="teacher-header__left">
      <div class="teacher-header__avatar"><?= $initials ?></div>
      <div>
        <div class="teacher-header__name"><?= $displayName ?></div>
        <div class="teacher-header__role">Teacher</div>
      </div>
    </div>
    <a href="/logout.php" class="teacher-header__logout">Sign Out</a>
  </header>

  <!-- Tab Bar -->
  <nav class="teacher-tabs" role="tablist">
    <button class="teacher-tabs__btn teacher-tabs__btn--active"
            role="tab" aria-selected="true" aria-controls="dashboard"
            data-tab="dashboard">Dashboard</button>
    <button class="teacher-tabs__btn"
            role="tab" aria-selected="false" aria-controls="playlists"
            data-tab="playlists">Playlists</button>
    <button class="teacher-tabs__btn"
            role="tab" aria-selected="false" aria-controls="profile"
            data-tab="profile">Profile</button>
  </nav>

  <!-- Tab Content -->
  <main class="teacher-content">

    <!-- Dashboard Tab -->
    <section id="dashboard" class="tab-panel tab-panel--active" role="tabpanel">
      <div id="dashboard-root">
        <div class="dash-loading">
          <div class="dash-spinner"></div>
          <div>Loading student data...</div>
        </div>
      </div>
    </section>

    <!-- Playlists Tab (placeholder for Phase 4) -->
    <section id="playlists" class="tab-panel" role="tabpanel">
      <div class="placeholder-content">
        <div class="placeholder-content__icon">&#x1F4DA;</div>
        <div class="placeholder-content__text">Playlists</div>
        <div class="placeholder-content__sub">Coming soon — create and manage lesson plans.</div>
      </div>
    </section>

    <!-- Profile Tab (placeholder for Phase 4) -->
    <section id="profile" class="tab-panel" role="tabpanel">
      <div class="placeholder-content">
        <div class="placeholder-content__icon">&#x1F464;</div>
        <div class="placeholder-content__text">Profile</div>
        <div class="placeholder-content__sub">Coming soon — manage your account settings.</div>
      </div>
    </section>

  </main>

  <script>
  (function() {
    var tabs = document.querySelectorAll('.teacher-tabs__btn');
    var panels = document.querySelectorAll('.tab-panel');
    var dashLoaded = false;

    function activateTab(tabName) {
      tabs.forEach(function(btn) {
        var active = btn.getAttribute('data-tab') === tabName;
        btn.classList.toggle('teacher-tabs__btn--active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      panels.forEach(function(panel) {
        panel.classList.toggle('tab-panel--active', panel.id === tabName);
      });
      if (tabName === 'dashboard' && !dashLoaded) {
        dashLoaded = true;
        loadDashboard();
      }
    }

    function loadDashboard() {
      if (typeof window.TeacherDashboard !== 'undefined') {
        window.TeacherDashboard.init(document.getElementById('dashboard-root'));
      }
    }

    // Tab click handlers
    tabs.forEach(function(btn) {
      btn.addEventListener('click', function() {
        var tab = this.getAttribute('data-tab');
        activateTab(tab);
        history.replaceState(null, '', '#' + tab);
      });
    });

    // URL hash support
    var hash = location.hash.replace('#', '');
    if (hash && document.getElementById(hash)) {
      activateTab(hash);
    } else {
      // Default: dashboard
      activateTab('dashboard');
    }
  })();
  </script>
  <script src="/js/teacher-dashboard.js"></script>
</body>
</html>

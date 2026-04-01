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
$teacherEmail = htmlspecialchars($user['email'] ?? '');
$initials = mb_strtoupper(mb_substr($displayName, 0, 1));
$teacherId = (int)($user['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Teacher Hub – EduPak</title>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="/css/teacher.css">
  <link rel="stylesheet" href="/css/playlist.css">
  <link rel="stylesheet" href="/css/lesson-publish.css">
</head>
<body class="teacher-page" data-teacher-id="<?= $teacherId ?>" data-teacher-name="<?= $displayName ?>" data-teacher-email="<?= $teacherEmail ?>">

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

    <!-- Playlists Tab -->
    <section id="playlists" class="tab-panel" role="tabpanel">
      <div id="playlists-content">
        <!-- Populated by lesson-builder.js -->
      </div>
    </section>

    <!-- Profile Tab -->
    <section id="profile" class="tab-panel" role="tabpanel">
      <div id="profile-root">
        <!-- Populated by teacher-profile.js -->
      </div>
    </section>

  </main>

  <script>
  (function() {
    var tabs = document.querySelectorAll('.teacher-tabs__btn');
    var panels = document.querySelectorAll('.tab-panel');
    var initialized = {};

    function activateTab(tabName) {
      tabs.forEach(function(btn) {
        var active = btn.getAttribute('data-tab') === tabName;
        btn.classList.toggle('teacher-tabs__btn--active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      panels.forEach(function(panel) {
        panel.classList.toggle('tab-panel--active', panel.id === tabName);
      });

      // Initialize tab content on first activation
      if (!initialized[tabName]) {
        initialized[tabName] = true;
        initTab(tabName);
      }
    }

    function initTab(tabName) {
      switch (tabName) {
        case 'dashboard':
          if (typeof window.TeacherDashboard !== 'undefined') {
            window.TeacherDashboard.init(document.getElementById('dashboard-root'));
          }
          break;
        case 'playlists':
          if (typeof window.LessonBuilder !== 'undefined') {
            window.LessonBuilder.renderListView();
          }
          break;
        case 'profile':
          if (typeof window.TeacherProfile !== 'undefined') {
            window.TeacherProfile.init(document.getElementById('profile-root'));
          }
          break;
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
      activateTab('dashboard');
    }
  })();
  </script>
  <script src="/js/teacher-dashboard.js"></script>
  <script src="/js/lesson-publish.js"></script>
  <script src="/js/lesson-builder.js"></script>
  <script src="/js/teacher-profile.js"></script>
</body>
</html>

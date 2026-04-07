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
  <link rel="stylesheet" href="css/teacher.css">
  <link rel="stylesheet" href="css/playlist.css">
  <link rel="stylesheet" href="css/lesson-publish.css">
  <link rel="stylesheet" href="css/search-results.css">
  <link rel="stylesheet" href="css/teacher-students.css">
  <link rel="stylesheet" href="css/teacher-assignments.css">
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
    <div class="teacher-menu teacher-menu--hub" id="teacherMenuHub">
      <span class="teacher-menu__name teacher-menu__name--hub" role="button" tabindex="0" aria-expanded="false" aria-haspopup="true"><?= $displayName ?></span>
      <div class="teacher-menu__popup" role="menu">
        <a href="index.php" class="teacher-menu__item" role="menuitem">Browse Content</a>
        <a href="logout.php" class="teacher-menu__item" role="menuitem">Sign Out</a>
      </div>
    </div>
  </header>

  <!-- Tab Bar -->
  <nav class="teacher-tabs" role="tablist">
    <button class="teacher-tabs__btn teacher-tabs__btn--active"
            role="tab" aria-selected="true" aria-controls="dashboard"
            data-tab="dashboard">Dashboard</button>
    <button class="teacher-tabs__btn"
            role="tab" aria-selected="false" aria-controls="playlists"
            data-tab="playlists">Lesson Plans</button>
    <button class="teacher-tabs__btn"
            role="tab" aria-selected="false" aria-controls="assignments"
            data-tab="assignments">Assignments</button>
    <button class="teacher-tabs__btn"
            role="tab" aria-selected="false" aria-controls="students"
            data-tab="students">Students</button>
    <button class="teacher-tabs__btn"
            role="tab" aria-selected="false" aria-controls="profile"
            data-tab="profile">Profile</button>
  </nav>

  <!-- Tab Content -->
  <main class="teacher-content">

    <!-- Dashboard Tab (FRE-39) -->
    <section id="dashboard" class="tab-panel tab-panel--active" role="tabpanel">
      <div id="dashboard-root">
        <div class="dash-loading">
          <div class="dash-spinner"></div>
          <div>Loading dashboard...</div>
        </div>
      </div>
    </section>

    <!-- Playlists Tab -->
    <section id="playlists" class="tab-panel" role="tabpanel">
      <div id="playlists-content">
        <!-- Populated by lesson-builder.js -->
      </div>
    </section>

    <!-- Assignments Tab (FRE-50) -->
    <section id="assignments" class="tab-panel" role="tabpanel">
      <div id="assignments-root">
        <!-- Populated by teacher-assignments.js -->
      </div>
    </section>

    <!-- Students Tab (FRE-47) -->
    <section id="students" class="tab-panel" role="tabpanel">
      <div id="students-root">
        <!-- Populated by teacher-students.js -->
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
        case 'assignments':
          if (typeof window.TeacherAssignments !== 'undefined') {
            window.TeacherAssignments.init(document.getElementById('assignments-root'));
          }
          break;
        case 'students':
          if (typeof window.TeacherStudents !== 'undefined') {
            window.TeacherStudents.init(document.getElementById('students-root'));
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
  <!-- Teacher mini-menu toggle (FRE-13) -->
  <script>
  (function() {
    var names = document.querySelectorAll('.teacher-menu__name');
    if (!names.length) return;

    names.forEach(function(name) {
      var wrapper = name.closest('.teacher-menu');

      name.addEventListener('click', function(e) {
        e.stopPropagation();
        var isOpen = wrapper.classList.contains('teacher-menu--open');

        document.querySelectorAll('.teacher-menu--open').forEach(function(el) {
          el.classList.remove('teacher-menu--open');
          el.querySelector('.teacher-menu__name').setAttribute('aria-expanded', 'false');
        });

        if (!isOpen) {
          wrapper.classList.add('teacher-menu--open');
          name.setAttribute('aria-expanded', 'true');
        }
      });

      name.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          name.click();
        }
      });
    });

    document.addEventListener('click', function() {
      document.querySelectorAll('.teacher-menu--open').forEach(function(el) {
        el.classList.remove('teacher-menu--open');
        el.querySelector('.teacher-menu__name').setAttribute('aria-expanded', 'false');
      });
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.teacher-menu--open').forEach(function(el) {
          el.classList.remove('teacher-menu--open');
          var btn = el.querySelector('.teacher-menu__name');
          btn.setAttribute('aria-expanded', 'false');
          btn.focus();
        });
      }
    });
  })();
  </script>
  <script>
    window.EDUTEK_BASE = (function() {
      var p = window.location.pathname;
      var idx = p.indexOf('/Edutek');
      if (idx !== -1) return p.substring(0, idx + '/Edutek'.length);
      return '';
    })();
  </script>
  <script src="js/search-typeahead.js"></script>
  <script src="js/teacher-dashboard.js"></script>
  <script src="js/lesson-publish.js"></script>
  <script src="js/lesson-builder.js"></script>
  <script src="js/teacher-assignments.js"></script>
  <script src="js/teacher-students.js"></script>
  <script src="js/teacher-profile.js"></script>
</body>
</html>

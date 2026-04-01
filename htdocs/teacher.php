<?php
/**
 * Teacher Hub (FRE-13)
 *
 * Role-gated page with tabbed interface:
 * - Dashboard (Tab 1) — from PR #11
 * - Playlists (Tab 2) — this phase
 * - Profile (Tab 3) — from PR #11
 *
 * This file provides the base structure. PR #11 (teacher-hub-dashboard)
 * will add the full 3-tab layout, dashboard JS, and additional styles.
 * This version ensures the Playlists tab and lesson-builder.js are loaded.
 */

include_once __DIR__ . '/includes/auth.php';

requireTeacher();

$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Hub - <?php echo SITE_TITLE; ?></title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/teacher.css" rel="stylesheet">
</head>
<body class="teacher-page">

<div class="teacher-hub">
    <header class="teacher-header">
        <h1>Teacher Hub</h1>
        <span class="teacher-header-user"><?php echo htmlspecialchars($user['display_name'] ?? 'Teacher', ENT_QUOTES, 'UTF-8'); ?></span>
    </header>

    <!-- Tab Navigation -->
    <nav class="teacher-tabs">
        <button class="teacher-tab" data-tab="dashboard">Dashboard</button>
        <button class="teacher-tab active" data-tab="playlists">Playlists</button>
        <button class="teacher-tab" data-tab="profile">Profile</button>
    </nav>

    <!-- Tab Content -->
    <div class="teacher-tab-content">
        <section id="dashboard-content" class="tab-pane" style="display:none;">
            <p>Dashboard content will be loaded by teacher-dashboard.js (PR #11).</p>
        </section>

        <section id="playlists-content" class="tab-pane">
            <!-- Populated by lesson-builder.js -->
        </section>

        <section id="profile-content" class="tab-pane" style="display:none;">
            <p>Profile content will be loaded by PR #11.</p>
        </section>
    </div>
</div>

<script>
// Simple tab switching
(function() {
    var tabs = document.querySelectorAll('.teacher-tab');
    var panes = document.querySelectorAll('.tab-pane');
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            var target = tab.getAttribute('data-tab');
            tabs.forEach(function(t) { t.classList.remove('active'); });
            tab.classList.add('active');
            panes.forEach(function(p) { p.style.display = 'none'; });
            var pane = document.getElementById(target + '-content');
            if (pane) pane.style.display = '';
            // Notify lesson builder when playlists tab is activated
            if (target === 'playlists' && window.LessonBuilder) {
                window.LessonBuilder.renderListView();
            }
        });
    });
})();
</script>
<script src="js/lesson-builder.js"></script>
</body>
</html>

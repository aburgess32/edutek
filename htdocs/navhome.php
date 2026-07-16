<?php
// Auth & session MUST be loaded before any HTML output to avoid
// "Cannot start session when headers already sent" errors.
include_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auto-index.php'; checkAndReindex();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="">
  <meta name="author" content="">
  <title>Edutek Global</title>
  <!-- Bootstrap core CSS-->
  <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <!-- Custom fonts for this template-->
  <link href="vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet" type="text/css">
  <!-- Custom styles for this template-->
  <link href="css/sb-admin.css" rel="stylesheet">
  <link href="css/style.css" rel="stylesheet">
  <link href="css/HomeVideos.css" rel="stylesheet">
  <link href="css/breadcrumb.css" rel="stylesheet">
  <link href="css/login.css" rel="stylesheet">
  <style>
  .hidden{
	  display:none;
  }
  </style>

    <!-- FONT AWESOME CSS -->
<link href="assets/css/font-awesome.min.css" rel="stylesheet" />
     <!-- FLEXSLIDER CSS -->
<link href="assets/css/flexslider.css" rel="stylesheet" />
    <!-- CUSTOM STYLE CSS -->
    <link href="assets/css/style.css" rel="stylesheet" />
<script>
function myFunction() {
  document.getElementById("myNumber").stepUp();
}
</script>
</head>
<body class="fixed-nav sticky-footer" id="page-top"
  data-mode="<?php echo htmlspecialchars(getMode(), ENT_QUOTES, 'UTF-8'); ?>"
  data-user-role="<?php echo htmlspecialchars($_SESSION['user_role'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
  data-avatar-name="<?php echo htmlspecialchars($_SESSION['avatar_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
  data-avatar-color="<?php echo htmlspecialchars($_SESSION['avatar_color'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
  data-real-name="<?php echo htmlspecialchars($_SESSION['display_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
  data-user-id="<?php echo (int)($_SESSION['user_id'] ?? 0); ?>">

	<!-- Navbar-->
<nav class="navbar navbar-expand-lg navbar-dark fixed-top app-navbar" id="mainNav">
  <div class="app-navbar-shell">

    <!-- Desktop navbar -->
    <div class="app-navbar-desktop">
      <a class="navbar-brand app-navbar-brand" href="index.php">
        <img src="assets/img/edutek-logo.jpg" alt="EduTek" class="navbar-brand-logo">
        <span class="app-navbar-brand-copy">
          <span class="app-navbar-brand-title">Community Learning Platform</span>
          <span class="app-navbar-brand-subtitle">EduTek Global</span>
        </span>
      </a>

      <div class="app-navbar-desktop-main">
        <ul class="nav navbar-nav app-navbar-links">
          <li class="nav-item">
            <a class="nav-link" href="index.php">
              <i class="fa fa-fw fa-institution"></i>
              <span class="nav-link-text">Home</span>
            </a>
          </li>

          <li class="nav-item">
            <a class="nav-link" href="directory.php">
              <i class="fa fa-fw fa-list"></i>
              <span class="nav-link-text">Directory</span>
            </a>
          </li>
        </ul>

        <form class="app-navbar-search app-navbar-search--desktop" method="get" action="result.php">
          <div class="input-group">
            <input class="form-control" type="text" name="q" placeholder="Search for..." aria-label="Search">
            <span class="input-group-btn">
              <button type="submit" class="btn btn-danger">
                <i class="fa fa-search"></i>
              </button>
            </span>
          </div>
        </form>
<!--
        <div class="app-navbar-actions">
          <a class="nav-link app-navbar-cta" href="login.php">
            <i class="fa fa-fw fa-sign-in"></i>
            <span class="nav-link-text">Get Started</span>
          </a>
        </div>
	-->
      </div>
    </div>

    <!-- Mobile navbar -->
    <div class="app-navbar-mobile">
      <div class="app-navbar-mobile-top">
        <a class="navbar-brand app-navbar-brand app-navbar-brand--mobile" href="index.php">
          <img src="assets/img/edutek-logo.jpg" alt="EduTek" class="navbar-brand-logo">
          <span class="app-navbar-brand-copy">
            <span class="app-navbar-brand-title">Community Learning Platform</span>
            <span class="app-navbar-brand-subtitle">EduTek Global</span>
          </span>
        </a>

        <button
          class="app-navbar-menu-toggle"
          type="button"
          aria-expanded="false"
          aria-controls="appMobileMenu"
          aria-label="Toggle navigation menu">
          <span class="app-navbar-menu-toggle__icon">
            <span></span>
            <span></span>
            <span></span>
          </span>
          <span class="app-navbar-menu-toggle__label">Menu</span>
        </button>
      </div>

      <form class="app-navbar-search app-navbar-search--mobile" method="get" action="result.php">
        <div class="input-group">
          <input class="form-control" type="text" name="q" placeholder="Search for..." aria-label="Search">
          <span class="input-group-btn">
            <button type="submit" class="btn btn-danger">
              <i class="fa fa-search"></i>
            </button>
          </span>
        </div>
      </form>

      <div class="app-navbar-mobile-panel" id="appMobileMenu">
        <div class="app-navbar-mobile-links">
          <a class="nav-link" href="index.php">
            <i class="fa fa-fw fa-institution"></i>
            <span class="nav-link-text">Home</span>
          </a>

          <a class="nav-link" href="directory.php">
            <i class="fa fa-fw fa-list"></i>
            <span class="nav-link-text">Directory</span>
          </a>
	<!--
          <a class="nav-link app-navbar-cta" href="login.php">
            <i class="fa fa-fw fa-sign-in"></i>
            <span class="nav-link-text">Get Started</span>
          </a>
	-->
        </div>
      </div>
    </div>

  </div>
</nav>
	
	
	<!-----Main Contianer-->
  <div class="content-wrapper">

    </div>
    <!-- Bootstrap core JavaScript — all local, no CDN fallbacks (offline-first) -->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin.min.js"></script>
    <!--  Flexslider Scripts -->
    <script src="assets/js/jquery.flexslider.js"></script>
    <!--  Scrolling Reveal Script -->
    <script src="assets/js/scrollReveal.js"></script>
    <!--  Scroll Scripts -->
    <script src="assets/js/jquery.easing.min.js"></script>
    <!--  Custom Scripts -->
    <script src="assets/js/custom.js"></script>
    <!-- Custom scripts for this page-->
    <!-- Toggle between fixed and static navbar-->
    <script>
    $('#toggleNavPosition').click(function() {
      $('body').toggleClass('fixed-nav');
      $('nav').toggleClass('fixed-top static-top');
    });

    </script>
    <!-- Toggle between dark and light navbar-->
    <script>
    $('#toggleNavColor').click(function() {
      $('nav').toggleClass('navbar-dark navbar-light');
      $('nav').toggleClass('bg-dark bg-light');
      $('body').toggleClass('bg-dark bg-light');
    });

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

          // Close all other menus first
          document.querySelectorAll('.teacher-menu--open').forEach(function(el) {
            el.classList.remove('teacher-menu--open');
            el.querySelector('.teacher-menu__name').setAttribute('aria-expanded', 'false');
          });

          if (!isOpen) {
            wrapper.classList.add('teacher-menu--open');
            name.setAttribute('aria-expanded', 'true');
          }
        });

        // Allow Enter/Space to toggle
        name.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            name.click();
          }
        });
      });

      // Close on outside click
      document.addEventListener('click', function() {
        document.querySelectorAll('.teacher-menu--open').forEach(function(el) {
          el.classList.remove('teacher-menu--open');
          el.querySelector('.teacher-menu__name').setAttribute('aria-expanded', 'false');
        });
      });

      // Close on Escape
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


    <!-- Student mini-menu toggle (Switch User dropdown) -->
    <script>
    (function() {
      var names = document.querySelectorAll('.student-menu__name');
      if (!names.length) return;

      names.forEach(function(name) {
        var wrapper = name.closest('.student-menu');

        name.addEventListener('click', function(e) {
          e.stopPropagation();
          var isOpen = wrapper.classList.contains('student-menu--open');

          // Close all other student menus first
          document.querySelectorAll('.student-menu--open').forEach(function(el) {
            el.classList.remove('student-menu--open');
            el.querySelector('.student-menu__name').setAttribute('aria-expanded', 'false');
          });

          if (!isOpen) {
            wrapper.classList.add('student-menu--open');
            name.setAttribute('aria-expanded', 'true');
          }
        });

        // Allow Enter/Space to toggle
        name.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            name.click();
          }
        });
      });

      // Close on outside click
      document.addEventListener('click', function() {
        document.querySelectorAll('.student-menu--open').forEach(function(el) {
          el.classList.remove('student-menu--open');
          el.querySelector('.student-menu__name').setAttribute('aria-expanded', 'false');
        });
      });

      // Close on Escape
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          document.querySelectorAll('.student-menu--open').forEach(function(el) {
            el.classList.remove('student-menu--open');
            var btn = el.querySelector('.student-menu__name');
            btn.setAttribute('aria-expanded', 'false');
            btn.focus();
          });
        }
      });
    })();
    </script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.querySelector('.app-navbar-menu-toggle');
  var panel = document.getElementById('appMobileMenu');

  if (!toggle || !panel) {
    return;
  }

  toggle.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();

    var isOpen = panel.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  });

  document.addEventListener('click', function (event) {
    var clickedToggle = toggle.contains(event.target);
    var clickedPanel = panel.contains(event.target);

    if (!clickedToggle && !clickedPanel) {
      panel.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    }
  });

  window.addEventListener('resize', function () {
    if (window.innerWidth > 991) {
      panel.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    }
  });
});
</script>

</body>

</html>

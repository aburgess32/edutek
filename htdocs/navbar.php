<?php
// Auth & session MUST be loaded before any HTML output to avoid
// "Cannot start session when headers already sent" errors.
include_once __DIR__ . '/includes/auth.php';
// require_once __DIR__ . '/includes/auto-index.php'; checkAndReindex(); // Disabled: use CLI content-indexer.php instead
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
  <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet" onerror="this.href='https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css'">
  <!-- Custom fonts for this template-->
  <link href="vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet" type="text/css" onerror="this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css'">
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
</head>
<body class="fixed-nav sticky-footer" id="page-top"
  data-mode="<?php echo htmlspecialchars(getMode(), ENT_QUOTES, 'UTF-8'); ?>"
  data-user-role="<?php echo htmlspecialchars($_SESSION['user_role'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
  data-avatar-name="<?php echo htmlspecialchars($_SESSION['avatar_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
  data-avatar-color="<?php echo htmlspecialchars($_SESSION['avatar_color'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
  data-real-name="<?php echo htmlspecialchars($_SESSION['display_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

	<!-- Navbar-->
  <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
		<!-----user name code here-->
    <a class="navbar-brand" href="index.php"><img src="assets/img/edutek-logo.jpg" alt="EduTek" class="navbar-brand-logo"> Community Learning Platform</a>
    <button class="navbar-toggler navbar-toggler-right" type="button" data-toggle="collapse" data-target="#navbarResponsive" aria-controls="navbarResponsive" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarResponsive">
	
		<ul class="navbar-nav ml-auto">
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
        <li class="nav-item">
          <form class="form-inline my-2 my-lg-0 mr-lg-2" method="get" action="result.php">
		  <div class="col-md-12">
            <div class="input-group">
              <!-- TODO (autocomplete): Wire up search suggestions here. Data sources available:
                   1. content_meta table (titles, categories, subcategories) — already powers /api/search-suggest.php
                   2. search_aliases table — synonym mappings (e.g. "beats" → "rhythm, tempo, BPM")
                   3. search_log table — popular past queries could feed "trending searches"
                   Quickest win: add data-search-typeahead attribute to this input and include
                   js/search-typeahead.js + css/search-results.css — the typeahead component
                   (FRE-40) is already built but not linked from the navbar.
                   Alternatively, an HTML5 <datalist> populated server-side with top categories
                   would give zero-JS autocomplete with no extra HTTP requests. -->
              <input class="form-control" type="text" name="q" placeholder="Search for...">
              <span class="input-group-btn">
                <button type="submit" class="btn btn-danger"><i class="fa fa-search"></i></button>
              </span>
            </div>
		  </div>
          </form>
        </li>
        <li class="nav-item">
          <?php if (isLoggedIn() && isTeacher()): ?>
          <span class="nav-link nav-user-indicator teacher-menu" id="teacherMenu">
            <span class="nav-user-dot" style="background: #7C3AED;"></span>
            <span class="teacher-menu__name" role="button" tabindex="0" aria-expanded="false" aria-haspopup="true"><?php echo htmlspecialchars(getUserDisplay(), ENT_QUOTES, 'UTF-8'); ?></span>
            <div class="teacher-menu__popup" role="menu">
              <a href="teacher.php" class="teacher-menu__item" role="menuitem">Teacher Hub</a>
              <a href="logout.php" class="teacher-menu__item" role="menuitem">Sign Out</a>
            </div>
          </span>
          <?php elseif (isLoggedIn()): ?>
          <span class="nav-link nav-user-indicator student-menu" id="studentMenu">
            <span class="nav-user-dot" style="background: <?php echo htmlspecialchars($_SESSION['avatar_color'] ?? '#FF6B35', ENT_QUOTES, 'UTF-8'); ?>;"></span>
            <span class="student-menu__name" role="button" tabindex="0" aria-expanded="false" aria-haspopup="true"><?php echo htmlspecialchars(getUserDisplay(), ENT_QUOTES, 'UTF-8'); ?></span>
            <div class="student-menu__popup" role="menu">
              <a href="/logout.php" class="student-menu__item" role="menuitem"><i class="fa fa-exchange"></i> Switch User</a>
            </div>
          </span>
          <?php else: ?>
          <a class="nav-link" href="login.php" style="font-size: 13px;">
            <i class="fa fa-fw fa-sign-in"></i> <span class="nav-link-text">Get Started</span>
          </a>
          <?php endif; ?>
        </li>
      </ul>
		<!-----/theird ul navbar-->
    </div>
  </nav>
	<!--/navbar-->

      
    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script>window.jQuery || document.write('<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js"><\/script>')</script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>if(typeof jQuery!=='undefined'&&typeof jQuery.fn.tooltip==='undefined')document.write('<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"><\/script>')</script>
    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script>if(typeof jQuery!=='undefined'&&typeof jQuery.easing!=='undefined'&&typeof jQuery.easing.easeInOutExpo==='undefined')document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-easing/1.4.1/jquery.easing.min.js"><\/script>')</script>
    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin.min.js"></script>
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
</body>

</html>

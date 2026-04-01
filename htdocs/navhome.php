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
<?php include_once __DIR__ . '/includes/auth.php'; ?>
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
          <form class="form-inline my-2 my-lg-0 mr-lg-2" method="post" action="result.php">
		  <div class="col-md-12">
            <div class="input-group">
              <input class="form-control" type="text" name="search" placeholder="Search for...">
              <span class="input-group-btn">
                <input class="btn btn-danger" name="submit" type="submit">
                 <button> <i class="fa fa-search"></i>
                </button>
              </span>
            </div>
		  </div>
          </form>
        </li>
        <li class="nav-item">
          <?php if (isLoggedIn() && isTeacher()): ?>
          <span class="nav-link nav-user-indicator teacher-dropdown" id="teacherDropdownHome">
            <span class="nav-user-dot" style="background: #7C3AED;"></span>
            <button type="button" class="teacher-dropdown__trigger" aria-expanded="false" aria-haspopup="true">
              <?php echo htmlspecialchars(getUserDisplay(), ENT_QUOTES, 'UTF-8'); ?>
              <svg class="teacher-dropdown__caret" width="12" height="8" viewBox="0 0 12 8" fill="none"><path d="M1 1.5L6 6.5L11 1.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="teacher-dropdown__menu" role="menu">
              <a href="index.php" class="teacher-dropdown__item" role="menuitem">
                <svg class="teacher-dropdown__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                Browse Content
              </a>
              <a href="teacher.php" class="teacher-dropdown__item" role="menuitem">
                <svg class="teacher-dropdown__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Teacher Hub
              </a>
              <a href="teacher.php#profile" class="teacher-dropdown__item" role="menuitem">
                <svg class="teacher-dropdown__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                My Profile
              </a>
              <div class="teacher-dropdown__divider"></div>
              <a href="logout.php" class="teacher-dropdown__item teacher-dropdown__item--danger" role="menuitem">
                <svg class="teacher-dropdown__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sign Out
              </a>
            </div>
          </span>
          <?php elseif (isLoggedIn()): ?>
          <span class="nav-link nav-user-indicator">
            <span class="nav-user-dot" style="background: <?php echo htmlspecialchars($_SESSION['avatar_color'] ?? '#FF6B35', ENT_QUOTES, 'UTF-8'); ?>;"></span>
            <span class="nav-user-name"><?php echo htmlspecialchars(getUserDisplay(), ENT_QUOTES, 'UTF-8'); ?></span>
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
	
	
	<!-----Main Contianer-->
  <div class="content-wrapper">
    <!-- Hero Slider -->
    <div class="hero-slider" id="heroSlider">
        <?php
        $featured = getFeaturedContent();
        if (!empty($featured)):
            foreach ($featured as $fi => $fItem):
                $badge = getContentTypeBadge($fItem['badge'] ?? 'video');
                $bgImg = htmlspecialchars($fItem['bg_image'] ?? '', ENT_QUOTES, 'UTF-8');
                $bgJpg = str_replace('.webp', '.jpg', $bgImg);
        ?>
        <div class="hero-slide<?php echo $fi === 0 ? ' active' : ''; ?>" data-index="<?php echo $fi; ?>">
            <picture class="hero-slide-bg">
                <source srcset="<?php echo $bgImg; ?>" type="image/webp">
                <img src="<?php echo $bgJpg; ?>" alt="<?php echo htmlspecialchars($fItem['headline'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" loading="<?php echo $fi === 0 ? 'eager' : 'lazy'; ?>">
            </picture>
            <div class="hero-slide-overlay"></div>
            <div class="hero-slide-content">
                <span class="hero-badge" style="background:<?php echo htmlspecialchars($badge['color'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <h1 class="hero-headline"><?php echo htmlspecialchars($fItem['headline'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="hero-subhead"><?php echo htmlspecialchars($fItem['subhead'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                <a href="<?php echo htmlspecialchars($fItem['cta_href'] ?? '#', ENT_QUOTES, 'UTF-8'); ?>" class="hero-cta">
                    <?php echo htmlspecialchars($fItem['cta_label'] ?? 'Explore', ENT_QUOTES, 'UTF-8'); ?> &rarr;
                </a>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="hero-indicators" id="heroDots">
            <?php foreach ($featured as $fi => $fItem): ?>
            <button class="hero-dot<?php echo $fi === 0 ? ' active' : ''; ?>"
                    data-index="<?php echo $fi; ?>"
                    aria-label="Slide <?php echo $fi + 1; ?>"></button>
            <?php endforeach; ?>
        </div>
        <div class="hero-progress" id="heroProgress"></div>
        <?php endif; ?>
    </div>
    </div>
    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin.min.js"></script>
	    <script src="assets/js/jquery-1.10.2.js"></script>
    <!--  Core Bootstrap Script -->
    <script src="assets/js/bootstrap.js"></script>
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

    <!-- Hero Slider Script -->
    <script>
    (function() {
        var slides = document.querySelectorAll('.hero-slide');
        var dots = document.querySelectorAll('.hero-dot');
        var progress = document.getElementById('heroProgress');
        var heroEl = document.getElementById('heroSlider');
        if (!slides.length) return;

        var INTERVAL = 6000;
        var current = 0;
        var timer = null;
        var startTime = 0;
        var rafId = null;

        function goTo(index) {
            slides[current].classList.remove('active');
            dots[current].classList.remove('active');
            current = index;
            slides[current].classList.add('active');
            dots[current].classList.add('active');
            resetTimer();
        }

        function next() {
            goTo((current + 1) % slides.length);
        }

        function resetTimer() {
            clearTimeout(timer);
            cancelAnimationFrame(rafId);
            startTime = Date.now();
            if (progress) progress.style.width = '0%';
            tick();
            timer = setTimeout(next, INTERVAL);
        }

        function tick() {
            var elapsed = Date.now() - startTime;
            var pct = Math.min((elapsed / INTERVAL) * 100, 100);
            if (progress) progress.style.width = pct + '%';
            if (pct < 100) rafId = requestAnimationFrame(tick);
        }

        // Dot click handlers
        for (var i = 0; i < dots.length; i++) {
            (function(idx) {
                dots[idx].addEventListener('click', function() { goTo(idx); });
            })(i);
        }

        // Pause on hover
        if (heroEl) {
            heroEl.addEventListener('mouseenter', function() {
                clearTimeout(timer);
                cancelAnimationFrame(rafId);
            });
            heroEl.addEventListener('mouseleave', function() {
                resetTimer();
            });
        }

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowRight') goTo((current + 1) % slides.length);
            if (e.key === 'ArrowLeft') goTo((current - 1 + slides.length) % slides.length);
        });

        // Start
        resetTimer();
    })();
    </script>
    <!-- Teacher profile dropdown toggle (FRE-13) -->
    <script>
    (function() {
      var triggers = document.querySelectorAll('.teacher-dropdown__trigger');
      if (!triggers.length) return;

      triggers.forEach(function(trigger) {
        var wrapper = trigger.closest('.teacher-dropdown');
        var menu = wrapper.querySelector('.teacher-dropdown__menu');

        trigger.addEventListener('click', function(e) {
          e.stopPropagation();
          var isOpen = wrapper.classList.contains('teacher-dropdown--open');

          // Close all other dropdowns first
          document.querySelectorAll('.teacher-dropdown--open').forEach(function(el) {
            el.classList.remove('teacher-dropdown--open');
            el.querySelector('.teacher-dropdown__trigger').setAttribute('aria-expanded', 'false');
          });

          if (!isOpen) {
            wrapper.classList.add('teacher-dropdown--open');
            trigger.setAttribute('aria-expanded', 'true');
          }
        });
      });

      // Close on outside click
      document.addEventListener('click', function() {
        document.querySelectorAll('.teacher-dropdown--open').forEach(function(el) {
          el.classList.remove('teacher-dropdown--open');
          el.querySelector('.teacher-dropdown__trigger').setAttribute('aria-expanded', 'false');
        });
      });

      // Close on Escape
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          document.querySelectorAll('.teacher-dropdown--open').forEach(function(el) {
            el.classList.remove('teacher-dropdown--open');
            var btn = el.querySelector('.teacher-dropdown__trigger');
            btn.setAttribute('aria-expanded', 'false');
            btn.focus();
          });
        }
      });
    })();
    </script>
</body>

</html>

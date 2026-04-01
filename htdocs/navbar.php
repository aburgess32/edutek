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
</head>
<?php include_once __DIR__ . '/includes/auth.php'; ?>
<body class="fixed-nav sticky-footer" id="page-top"
  data-user-role="<?php echo htmlspecialchars($_SESSION['role'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
  data-avatar-name="<?php echo htmlspecialchars($_SESSION['avatar_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
  data-avatar-color="<?php echo htmlspecialchars($_SESSION['avatar_color'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
  data-real-name="<?php echo htmlspecialchars($_SESSION['display_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

	<!-- Navbar-->
  <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
		<!-----user name code here-->
    <a href="" class="navbar-brand" href="index.php"><i class="fa fa-fw fa-user"></i> EDUCATION WITH MODERN TECHNOLOGY</a>
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
          <span class="nav-link nav-user-indicator">
            <span class="nav-user-dot" style="background: #7C3AED;"></span>
            <span class="nav-user-name"><?php echo htmlspecialchars(getUserDisplay(), ENT_QUOTES, 'UTF-8'); ?></span>
            &middot; <a href="logout.php" style="color: #fbd6de;">Log Out</a>
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

      
    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
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
</body>

</html>

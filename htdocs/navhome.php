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

<body class="fixed-nav sticky-footer" id="page-top">


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
      </ul>
		<!-----/theird ul navbar-->
    </div>
  </nav>
	<!--/navbar-->
	
	
	<!-----Main Contianer-->
  <div class="content-wrapper">
		<!-----Container Fluid-->
		     <div class="home-sec" id="home" >
           <div class="overlay">
 <div class="container">
           <div class="row text-center " >
           
               <div class="col-lg-12  col-md-12 col-sm-12">
               
                <div class="flexslider set-flexi" id="main-section" >
                    <ul class="slides move-me">
                        <!-- Slider 01 -->
                        <li>
                              <h3>Vision for Africa Delivering Quality Education</h3>
                           <h1>EDUCATION FOR ALL</h1>
                            <a  href="#all" class="btn btn-info btn-lg" >
                                ENJOY SOMETHING NEW 
                            </a>
                           
                        </li>
                        <!-- End Slider 01 -->
                        
                        <!-- Slider 02 -->
                        <li>
                            <h3>Delivering Quality Education</h3>
                           <h1>UNMATCHED APPROACH</h1>
                             <a  href="#all" class="btn btn-primary btn-lg" >
                               ENJOY SOMETHING NEW 
                            </a>
                      
                        </li>
                        <!-- End Slider 02 -->
                        
                        <!-- Slider 03 -->
                        <li>
                            <h3>Delivering Quality Education</h3>
                           <h1>AWESOME VIDEOS IN ALL SUBJECTS</h1>
                             <a  href="#all" class="btn btn-default btn-lg" >
                                ENJOY SOMETHING NEW 
                            </a>
                             <a  href="#all" class="btn btn-info btn-lg" >
                                FEATURE LIST
                            </a>
                        </li>
                        <!-- End Slider 03 -->
                    </ul>
                </div>
                   
     
              
              
            </div>
                
               </div>
                </div>
           </div>
           
       </div>
    </div></div>
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
</body>

</html>

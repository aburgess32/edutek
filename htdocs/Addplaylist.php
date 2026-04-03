<?php
    include_once"navbar.php";
?>
    <!-- Custom styles for this Home-->
  <link href="css/sittings.css" rel="stylesheet">

    
        <!--contens are here-->
    
    <div class="SITTWrapper">
        <span class="fa fa-wa fa-folder"></span> Add Playlis
    </div>
    
    
    <div class="CONTWrapper">
        
        <form action="playlist.php" method="POST">
        
            <!--firt column-->
            <div class="row">
                <div class="col-lg-6">
                    <div class="fom-group">
                        <label class="control-label">Name:</label>
                        <div class="input-group">
                            <div class="input-group-addon">
                                <span class="fa fa-circle"></span>
                            </div>
                            <input type="text" name="p_name" class="form-control" placeholder="Lesson Plan Name">
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="fom-group">
                        <label class="control-label">Pormotion:</label>
                        <div class="input-group">
                            <div class="input-group-addon">
                                <span class="fa fa-check"></span>
                            </div>
                            <select name="pormotion" class="form-control">
                                <option name="pormotion" value="public">public</option>
                                <option name="pormotion" value="private">private</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <!--//firt column-->
            
            
            
            
            
            <div class="dropdown-divider"></div>
            
            <div class="btn-group">
                <a href="storage.php" class="btn btn-danger btn-lg">
                    <span class="fa fa-w fa-close"></span> Cencel
                </a>
                
                <button type="Submit" class="btn btn-primary btn-lg">
                    <span class="fa fa-w fa-plus"></span> Add
                </button>
            </div>
            
        </form>
    </div>
        <!--send Of Contents>
    
<?php
    include_once"footer.php";
?>


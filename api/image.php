<?php
error_reporting(E_ALL);
ini_set('display_errors','On');
date_default_timezone_set('GMT');


include_once('./functions.php');


// GET REQUEST VARS
$lat  = (isset($_REQUEST['lat']))?round($_REQUEST['lat'],5):0;
$lng  = (isset($_REQUEST['lng']))?round($_REQUEST['lng'],5):0;
$panoid = (isset($_REQUEST['panoid']))?$_REQUEST['panoid']:''; 
$time = (isset($_REQUEST['time']))?intval($_REQUEST['time']):time(); 

$debug = (isset($_REQUEST['debug']))?($_REQUEST['debug']=='true'):NULL;


// get sun stuff
$sun = sun($lat,$lng,$time,$panoid);
//print_pre($sun);
header($sun['header']); 
ImageGif($sun['ImageGif']); 






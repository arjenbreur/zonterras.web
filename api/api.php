<?php
error_reporting(E_ALL);
ini_set('display_errors','Off');
date_default_timezone_set('GMT');


include('./functions.php');

// L243
//$lat = 52.355739; $lng = 4.928056;
// oeterwalerbrug
$lat_default = 52.355239;
$lng_default = 4.928268;
$timeGMTstart_default = time();
$mode_default = 'search';
$radius_default = 500;

$lat  = (isset($_REQUEST['lat']))?$_REQUEST['lat']:$lat_default;
$lng  = (isset($_REQUEST['lng']))?$_REQUEST['lng']:$lng_default;
$timeGMTstart = (isset($_REQUEST['timeGMTstart']))?$_REQUEST['timeGMTstart']:$timeGMTstart_default;
$mode = (isset($_REQUEST['mode']))?$_REQUEST['mode']:$mode_default;
$radius = (isset($_REQUEST['radius']))?$_REQUEST['radius']:$radius_default;
$debug = (isset($_REQUEST['debug']))?$_REQUEST['debug']:NULL;

$timestampGMTstart = (isset($_REQUEST['timestampGMTstart']))?$_REQUEST['timestampGMTstart']:NULL;
if($timestampGMTstart){
	preg_match('~^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$~', $timestampGMTstart, $m);
	$timeGMTstart = gmmktime($m[4], $m[5], $m[6], $m[2], $m[3], $m[1]);
}
$timeWindow = 120;// minutes
$timeGMTend = strtotime("+".$timeWindow." minutes",$timeGMTstart);

//$mode = 'sunwindows';
//$mode = 'search';

if($mode=='search'){
	$searchterm = 'cafe';
    $timewindow_format = "Y-m-d H:i:s [e]";
	$sunwindow_format = "H:i";
	
    $search = new gLocalSearch($lat,$lng,$radius,$searchterm);
    
    
    // set html headers
	header('Content-Type: text/xml');
	echo '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
    echo '<zonterras>';
    echo '	<searchparams>';
    echo '		<lat>'.$lat.'</lat>';
    echo '		<lng>'.$lng.'</lng>';
    echo '		<searchradius unit="meter" >'.$radius.'</searchradius>';
	echo '	</searchparams>';
	echo '	<timewindow>';
    echo '		<windowsize unit="minutes">'.$timeWindow.'</windowsize>';
    echo '		<start GMT="true" format="'.$timewindow_format.'">'.date($timewindow_format, $timeGMTstart).'</start>';
    echo '		<end GMT="true" format="'.$timewindow_format.'">'.date($timewindow_format, $timeGMTend).'</end>';
	echo '	</timewindow>';
    echo '	<searchresults unmaskedresults="'.count($search->searchResults).'">';
    foreach($search->searchResults as $aResult){
        $sunshine = new Sunshine($aResult['lat'],$aResult['lng']);
        $aSunWindows = $sunshine->getSunWindows($timeGMTstart, $timeGMTend);
        if($aSunWindows['status']=='OK' && count($aSunWindows['results'])){
	    	echo '		<searchresult lat="'.$aResult['lat'].'" lng="'.$aResult['lng'].'" >';
    		echo '			<name>'.$aResult['name'].'</name>';
        	echo '			<panourl>'.urlencode('http://'.$_SERVER['SERVER_NAME'].$_SERVER['PHP_SELF'].'?mode=pano&lat='.$sunshine->pano->lat.'&lng='.$sunshine->pano->lng.'&timeGMTstart='.$timeGMTstart).'</panourl>';
			echo '			<sunshine lat="'.$sunshine->pano->lat.'" lng="'.$sunshine->pano->lng.'">';
       		echo '				<sunwindows status="'.$aSunWindows['status'].'">';
        	foreach($aSunWindows['results'] as $key=>$aSunWindow){
 				echo '					<sunwindow>';
	            echo '						<start GMT="false" format="'.$sunwindow_format.'">' . date($sunwindow_format, $aSunWindow['timeLocalStart']) . '</start>';
	            echo '						<end GMT="false" format="'.$sunwindow_format.'">' . date($sunwindow_format, $aSunWindow['timeLocalEnd']) . '</end>';
 				echo '					</sunwindow>';
        	}
	       	echo '				</sunwindows>';
	       	echo '				<log>'.$sunshine->error.'</log>';
    	    echo '			</sunshine>';
	    	echo '		</searchresult>';
        }
    }
    echo '	</searchresults>';
    echo '</zonterras>';
    exit;
}





if($mode=='pano'){
    $sunshine = new Sunshine($lat,$lng);
    $sunshine->initPanoMarked($sunshine->panoRedSky);

    $aSunWindows = $sunshine->getSunWindows($timeGMTstart, strtotime("+19 hours",$timeGMTstart));
    $sunshine->cropPano($sunshine->panoMarked,FALSE,TRUE);
    $sunshine->panoMarked->showImg();
}

if($mode=='sunwindows'){
    $sunshine = new Sunshine($lat,$lng);
    $aSunWindows = $sunshine->getSunWindows(strtotime("today 6:00"));
//    $aSunWindows = $sunshine->getSunWindows(time());

    foreach($aSunWindows as $key => $aSunWindow){
        echo date("H:i",$aSunWindow['timeStart']);
        echo '-';
        echo date("H:i",$aSunWindow['timeEnd']).'<br/>';
    }
}


?>
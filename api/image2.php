<?php
error_reporting(E_ALL);
ini_set('display_errors','On');
date_default_timezone_set('GMT');

include_once('./functions.php');

// GET REQUEST VARS
$panoid = (isset($_REQUEST['panoid']))?$_REQUEST['panoid']:''; 
$yaw    = (isset($_REQUEST['yaw']))?intval($_REQUEST['yaw']):0; 
$pitch  = (isset($_REQUEST['pitch']))?intval($_REQUEST['pitch']):0;
$lat  = (isset($_REQUEST['lat']))?round($_REQUEST['lat'],5):0;
$lng  = (isset($_REQUEST['lng']))?round($_REQUEST['lng'],5):0;
$debug = (isset($_REQUEST['debug']))?($_REQUEST['debug']=='true'):NULL;

// GET IMAGE
$src = "http://cbk0.google.com/cbk?output=thumbnail&panoid=$panoid&yaw=$yaw&pitch=$pitch";
$im = imagecreatefromjpeg($src);
$im_temp = imagecreatefromjpeg($src);;
$im_forground = imagecreatefromjpeg($src);;
// GET IMAGE SIZE
$size = getimagesize($src);
$width  = (count($size)>=2)?$size[0]:null;
$height = (count($size)>=2)?$size[1]:null;

// SET COLORS (texts and sun etc)
$red = imagecolorallocate($im, 255, 0, 0); 
$black = imagecolorallocate($im, 0, 0, 0); 
$colorSun = imagecolorallocate($im, 255, 255, 0); 
$white = imagecolorallocate($im, 255, 255, 255);
$grey = imagecolorallocate($im, 128, 128, 128);
// SET FONT
$font = 'arial.ttf';

// SET TWEAK VARS
$brightener         = 75;  // desolves cloudes to beter find real edges (tweak to improve edge finding)
$contrast_tolerance = 100;  // sets how 'hard' an skyline edge must be to be found,
$sunDiameterAngular = 20;   // degrees, should be 0.53, but is exagurated to be visible on the image (src= http://en.wikipedia.org/wiki/Angular_diameter)

// SET GOOGLE STREETVIEW PARAMETERS
$googleStreetViewPanoramaVerticalSpread = 180;      // vertical degrees of visibility on panorama picture
$googleStreetViewPanoramaHorizontalSpread = 360;    // horizontal degrees of visibility on panorama picture


if($im && $width && $height){
    imagefilter($im_temp, IMG_FILTER_BRIGHTNESS,$brightener);   // brighten to desolve clouds, tweak to get best 'find edge' result
    imagefilter($im_temp, IMG_FILTER_MEAN_REMOVAL);             // sketchy effect, enhances edges
    imagefilter($im_temp, IMG_FILTER_MEAN_REMOVAL);             // sketchy effect, enhances edges
//    imagefilter($im_temp, IMG_FILTER_CONTRAST, 25);             // more contrast, enhances edges
//    imagefilter($im_temp, IMG_FILTER_BRIGHTNESS,25);   // brighten to desolve clouds, tweak to get best 'find edge' result
    
    $sunDiameterPixels = round(($size[0]/$googleStreetViewPanoramaHorizontalSpread)*$sunDiameterAngular);
    $history = array();
    $mean = 0;

    $x= round(($size[0]/2) - $sunDiameterPixels); // start close to sun position, to save time
    for($x=$x;$x<round(($size[0]/2) + $sunDiameterPixels);$x++){ // stop close after sun position, to save time
        $y = 0;
        $monoPixel_prev = imageGrayscaleValueAt($im_temp,$x,$y);

        for($y=0;$y<$size[1];$y++){
            $monoPixel = imageGrayscaleValueAt($im_temp,$x,$y);     // get grayscale value of pixel
            $contrast = abs($monoPixel-$monoPixel_prev);            // get the contrast of this pixel compared to the previous one
            $monoPixel_prev = $monoPixel;                           // remember, to compare with next pixel

            if($contrast>$contrast_tolerance){
                // edge found,
                // save y in history (below)
                break 1; // break from y loop
            }else{
                // no edge found
                // check if mean y is not surpassed
                if($y>$mean && $x!=0){
                    // y greater than mean (or still checking first column so no mean available yet)
                    // continue to see how big y will get (it will be saved in history to calculate the mean)
                }else{
                    // no edge, no mean surpassed
                    // check if pixel is light (sky) or dark (anything else)
                    // this var needs to be tweaked!
                    if($monoPixel<300){
                        // color this pixel with a temporary color, wich will be set to transparent later on
//                        imagesetpixel($im_forground, $x,$y, $red);
                        $y2 = ($y>0)?$y-1:0;
                        imagefill($im_forground, $x,$y2, $red);
                    }else{
                        die("monoPixel=$monoPixel (<300)");
                    }
                }
            }
        }
        // save y in history buffer
        // update mean y
        array_push($history,$y);
        while(count($history)>3){array_shift($history);}
        $mean = (array_sum($history)/count($history));
        $y=0;
    }

    // Make the tmp color transparent, so backgound will be revealed later on
    imagecolortransparent($im_forground, $red);

    // draw the sun on the background, wich might be (partialy) obscured by the forground
    $pixPerDegree = $size[1]/$googleStreetViewPanoramaVerticalSpread;
    $pitchInverter = ($pitch>=0)?1:-1;
    $pixPitch = $size[1]/2+ -1*$pitchInverter*(($pitchInverter*$pitch)*$pixPerDegree); // elevation of the sun in pixels from the top
    $pixYaw = round($size[0]/2); // sun should already be at the center of the image
    $pixDiameter = $sunDiameterPixels;
    imagefilledarc ($im,  $pixYaw,  $pixPitch,  $pixDiameter,  $pixDiameter,  0, 360, $colorSun,IMG_ARC_PIE);

    // Merge forground and background
    imagecopymerge($im, $im_forground, 0, 0, 0, 0, $size[0], $size[1], 100);

    // Check if the center of the sun is visible or obscured by foreground
    $pixelColor = imagecolorat($im,$pixYaw,$pixPitch);
    $sunVisible = ($pixelColor==$colorSun)?TRUE:FALSE;
    


    // make the total image darker if the sun is below the horizon (just for fun)
    if($pitch<0){
        imagefilter($im, IMG_FILTER_BRIGHTNESS,($pitch*(200/90))); // the further below the horizon, the darker the image
    }

    // Draw sun outline on forground, to indicate sun position if obscured by foreground
    imagearc ($im,  $pixYaw,  $pixPitch,  $pixDiameter,  $pixDiameter,  0, 360, $colorSun);


//    // draw sun outline in 60 minutes from now
//    $text = '+60';
//    $font = 'arial.ttf';
//    $fontsize = 10;
//    $pixYaw += round(($size[0]/24/60)*60);
//    $pixPitch += round(($size[1]/12/60)*60);
//    imagearc ($im,  $pixYaw,  $pixPitch,  $pixDiameter,  $pixDiameter,  0, 360, $colorSun);
//    imagettftext($im, $fontsize, 0, ($pixYaw+1)-$fontsize, ($pixPitch+1)+($fontsize/2), $colorSun, $font, $text);
//    imagettftext($im, $fontsize, 0, $pixYaw-$fontsize, $pixPitch+($fontsize/2), $black, $font, $text);
//    // draw sun outline in 60 minutes from now
//    $pixYaw += round(($size[0]/24/60)*60);
//    $pixPitch += round(($size[1]/12/60)*60);
//    imagearc ($im,  $pixYaw,  $pixPitch,  $pixDiameter,  $pixDiameter,  0, 360, $colorSun);
//    imagettftext($im, $fontsize, 0, ($pixYaw+1)-$fontsize, ($pixPitch+1)+($fontsize/2), $colorSun, $font, $text);
//    imagettftext($im, $fontsize, 0, $pixYaw-$fontsize, $pixPitch+($fontsize/2), $black, $font, $text);

    // display title on image
    $text = 'Zonpositie nu';
    $fontsize = 14;
    imagettftext($im, $fontsize, 0, 11, 21, $white, $font, $text); // shadow
    imagettftext($im, $fontsize, 0, 10, 20, $black, $font, $text);

    $text = ($sunVisible)?'Zon!':'Schaduw!';
    $text = ($pitch<0)?'Zon is onder!':$text;
    $fontsize = 11;
    imagettftext($im, $fontsize, 0, 11, 41, $white, $font, $text); // shadow
    imagettftext($im, $fontsize, 0, 10, 40, $black, $font, $text);

    // display datetime on image
    $text = date("Y-m-d H:i",strtotime("+2 hours")); // UGLY HACK! account for timezone and daylight saving time, should be automated!!
    $text .= ", lat=$lat, lng=$lng";
    $text .= ", yaw=$yaw, pitch=$pitch";
    $fontsize = 10;
    imagettftext($im, $fontsize, 0, 5, $size[1]-$fontsize-2, $white, $font, $text);

    // output image to client
    header("Content-Type: image/gif"); 
//    ImageGif($im);
    ImageGif($im_temp);
   
}

//Free up resources
ImageDestroy($im); 
ImageDestroy($im_temp); 
ImageDestroy($im_forground); 





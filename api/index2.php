<?php
error_reporting(E_ALL);
ini_set('display_errors','On');
date_default_timezone_set('GMT');

include_once('./functions.php');

$lat = (isset($_REQUEST['lat']))?$_REQUEST['lat']:0;
$lng = (isset($_REQUEST['lng']))?$_REQUEST['lng']:0;
$time = (isset($_REQUEST['time']))?intval($_REQUEST['time']):time();
$panoid = (isset($_REQUEST['panoid']))?$_REQUEST['panoid']:null; 

$mode = (isset($_REQUEST['mode']) && in_array($_REQUEST['mode'],array('streetview','pano')))?$_REQUEST['mode']:'pano'; 

$numImages=0;
$numLoops=0;
while($numImages<4 && $numLoops<10){
	$latmax = 52.41461;
	$lngmin = 4.80179;
	$latmin = 52.30264;
	$lngmax = 5.01842;
	$lat = rand($latmin*10000000000000,$latmax*10000000000000 )/10000000000000;
	$lng = rand($lngmin*10000000000000,$lngmax*10000000000000 )/10000000000000;


	$sunpos = sunpos($lat,$lng,$time);

	$sUrl = "http://cbk0.google.com/cbk?output=xml&ll=$lat,$lng";
	$sXml = get_url_contents($sUrl);
	$oXml = new SimpleXMLElement($sXml);
	if(is_object($oXml) && isset($oXml->annotation_properties) && isset($oXml->annotation_properties->link)){
		$attr = $oXml->annotation_properties->link[0]->attributes();
		$panoid = $attr['pano_id'];
		$sImgSrc1 = "image.php?panoid=$panoid&yaw=".round($sunpos['azimuth'])."&pitch=".round($sunpos['elevation'])."&lat=$lat&lng=$lng";
		$sImgSrc2 = "image2.php?panoid=$panoid&yaw=".round($sunpos['azimuth'])."&pitch=".round($sunpos['elevation'])."&lat=$lat&lng=$lng";
		?>
		<div style="border:1px solid red;">
			<img src="<?php echo $sImgSrc1; ?>" style="XXXwidth:100%" />
			<img src="<?php echo $sImgSrc2; ?>" style="XXXwidth:100%" /><br/>
		</div>
		<?php
		$numImages++;
	}
	$numLoops++;
}

?>
<h3>Lastige plaatjes, werkte wel, nog steeds?</h3>
<img src="http://www.3r13.nl/zonterras/image.php?panoid=qVcMopQzo0xI_6HenDdqPg&yaw=156&pitch=40&lat=52.417167188&lng=4.917597872" />
<img src="http://www.3r13.nl/zonterras/image.php?panoid=IGaXIpR9dwbq__97LwLhsg&yaw=147&pitch=38&lat=52.383642643&lng=4.91039882" />
<img src="http://www.3r13.nl/zonterras/image.php?panoid=Fpm2CFqL1REGYusJBXgDZA&yaw=209&pitch=39&lat=52.39223908&lng=4.905710841" />
<h3>WERKT NOG NIET!!</h3>
<img src="http://www.3r13.nl/zonterras/image.php?panoid=GrfbdQfMGI_-kzK4p9N8Yw&yaw=155&pitch=38&lat=52.426449249&lng=4.943674718" />
<img src="http://www.3r13.nl/zonterras/image.php?panoid=wwXS0TbPYR1IDS4au0Cq1A&yaw=220&pitch=36&lat=52.389417057&lng=4.95670868" />
<img src="http://www.3r13.nl/zonterras/image.php?panoid=ljD4I4WU5Z3v28PSNDDZVw&yaw=224&pitch=35&lat=52.499498108734&lng=4.9452562468519" />
<img src="http://www.3r13.nl/zonterras/image.php?panoid=x7trVroOsrDeiIncfjBQWw&yaw=225&pitch=34&lat=52.331011200283&lng=4.8613020764299" />
<img src="http://www.3r13.nl/zonterras/image.php?panoid=miSvZ8aR1NsEOVjYuqHXVA&yaw=264&pitch=11&lat=52.401134975127&lng=5.0149539336555" />
<img src="http://www.3r13.nl/zonterras/image.php?panoid=RPI5YziFVDJiLSodotni9A&yaw=265&pitch=10&lat=52.323688647416&lng=4.9507711161482" />
<img src="http://www.3r13.nl/zonterras/image.php?panoid=0wCrwyMwp7XAwYFMYszR9w&yaw=265&pitch=10&lat=52.342157457971&lng=4.8956814543466" />
<img src="http://www.3r13.nl/zonterras/image.php?panoid=n7v0P_7dpsN7SIw257ExMg&yaw=265&pitch=10&lat=52.412443901097&lng=4.894308317361" />
<?php



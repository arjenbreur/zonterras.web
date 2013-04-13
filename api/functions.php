<?php
class gLocalSearch{
    private $urlFormat = "https://maps.googleapis.com/maps/api/place/search/%s?location=%s&radius=%s&types=%s&name=%s&sensor=%s&key=%s";
    private $apiKey = 'AIzaSyCE02WHYzLOnzsAc_RPtEg4281Hzb7PUfQ';
    // Api key console: https://code.google.com/apis/console
    
    public $searchResults = array();
    
    public function __construct($lat,$lng,$radius,$types,$name=NULL){
        $sUrl = sprintf($this->urlFormat,'xml',$lat.','.$lng,$radius,$types,$name,"false",$this->apiKey);
        $sXml = get_url_contents($sUrl,'file_get_contents');
        $oXml = new SimpleXMLElement($sXml);
        if(is_object($oXml) && isset($oXml->status)){
            if($oXml->status=='OK'){
                $aResults = array();
                foreach($oXml->result as $oResult){
                    $aResults[]= array(
                        'name'=>$oResult->name,
                        'vicinity'=>$oResult->vicinity,
                        'lat'=>$oResult->geometry->location->lat,
                        'lng'=>$oResult->geometry->location->lng,
                    );
                }
                $this->searchResults = $aResults;
            }else{
                die($oXml->status);
            }
        }else{
            die('Error #23883: Invalid result');
        }
    }
}



class Pano{
    public $id;
    public $google_id;
    public $lat;
    public $lng;
    public $width;
    public $height;
    public $angularWidth;
    public $angularHeight;
    public $pitch;
    public $yaw;
    public $img;
    public $path;
    public $time;

    public function __construct(){
        //
    }
    public function __clone(){
        // this is executed AFTER the object has been cloned
        // the clone has its img property still pointing to the same image recource reference
        // we need to copy the image for it to be a new image (this might be lossy)
        if($this->img && $this->width>0 && $this->height>0){
            $new_img = ImageCreateTrueColor( $this->width, $this->height );
            // copy the whole cloned source image onto the new clone
            imagecopy ($new_img, $this->img, 0, 0, 0, 0,$this->width, $this->height);
            // save reference to new image (overwriting the reference to the clone source)
            $this->img = $new_img;
        }
    }
    
    public function populate($a){
        foreach($a as $key=>$value){
            $this->$key = $value;
        }
        // get image, if path is set
        if(array_key_exists('path',$a)){
            if(!$this->img && $this->path){
                $this->img=imagecreatefromjpeg($this->path);
                // GET IMAGE SIZE
                $size = getimagesize($this->path);
                if(count($size)){
                    $this->width = $size[0];
                    $this->height = $size[1];
                }else{
                    $this->throwError('Error #59sd3kk3: Image has no dimentions.');
                }
            }
        }
    }
    public function showImg(){
        if($this->img){
 //die('DEBUG MODE, NOT SHOWING IMAGE');
            header('Content-Type: image/png'); 
            ImagePng($this->img);
            exit;
        }else{die('Fatal error: no img set in function showImg()');}
    }
}

class Sunshine{
	
    // set tweak vars
    protected $googleStreetViewPanoramaVerticalSpread = 180;      // vertical degrees of visibility on panorama picture
    protected $googleStreetViewPanoramaHorizontalSpread = 360;    // horizontal degrees of visibility on panorama picture
    protected $sunAngularDiameter = 0.536; // visual angular diameter of the sun, in degrees (average)
    protected $sunSizeMultiplier = 10; // multiply sun size, so artefactal craighs in the redSky pano dont cause sunvisibility (multiplies the angular diameter)
    protected $isSunVisibleThreshold = 0.5; // fraction of sample that need to be 'visible' for the isVisible test to return true
    protected $getSunWindowsInterval = 900; // 900''=15', 3600''=60' time between each check for sun visibility (in seconds)


    // init static variables
    public static $timeZoneCache = array();

    // init constructor arguments
    protected $lat;
    protected $lng;

    // init public vars
    public $pano;
    public $panoRedSky;
    public $panoSunPos;
    public $panoCropped;
    public $panoMarked;
    
    public $timeGMT;
    public $timeLocal;

    public $db;
    
    // init misc
    public $error = '';
	private $debugmode = false;
   
   	// constructor
	public function __construct($lat,$lng){
        $this->lat=floatval($lat);
        $this->lng=floatval($lng);
        // SETUP DATABASE CONNECTION
        include_once("database.php");
        $this->db = new Database();
        $this->db->connect();
        
        // get the GMT time, 
        date_default_timezone_set('GMT');
        $this->timeGMT = time();
        $this->timeLocal = $this->localTime();

        // instantiate new pano 
        $this->pano = new Pano();
        // get nearest pano to given location from database
        $this->pano->populate($this->getPanoDataFromDatabase($this->lat, $this->lng));
        // or, get get it from google 
        if(!$this->pano->id){
            $this->pano->populate($this->getPanoDataFromGoogle($this->lat, $this->lng));
            if($this->pano->google_id){
                // save all pano data to database
                $this->savePanoToDatabase($this->pano);
            }else{
                $this->throwError('error #239dsR: cant find pano data.');
            }
        }
        // pano now polulated
    }
	public function __destruct() {
        if($this->debugmode == true && strlen($this->error)){
            echo("<p>".$this->error."</p>");
        }
    }

    private function throwError($error){
        $this->error .= '<p>'.$error."</p>";
    }


    // PUBLIC METHODS ////////////////////////////////////////////////////////////////////////////////////////
    public function isSunVisible($timeGMT=NULL){
        $timeGMT = (!is_null($timeGMT))?$timeGMT:$this->timeGMT;
        if(!$this->pano){
            // pano is not populated with an image, something went wrong during construction
            $this->throwError('error #010422: Pano is not available for this location.');
            return array('success'=>FALSE,'error'=>'Pano is not available for this location.');
        }
        if(!$this->panoRedSky){$this->initPanoRedSky($this->pano);}// use full pano to paint red sky, this takes longer than the cropped img, but works much better in finding the skyline
        if(!$this->panoMarked){$this->initPanoMarked($this->pano);}
        // select the pano's to use:
        $pano = $this->panoRedSky;
        $panoMarked = $this->panoMarked;
        // get sun pitch and yaw for pano location
        $aSunPos = $this->getSunPos($this->pano->lat,$this->pano->lng,$timeGMT);
        // get pixel position of sun on pano
        $pixPitch = $this->getPixPitch($pano,$aSunPos['sunPitch']);
        $pixYaw = $this->getPixYaw($pano,$aSunPos['sunYaw']);
        // Check if the center of the sun is in the red area (vissible sky)
        // sample an circular area (not just 1 pixel)
        // leave a mark where we have sampled (on the panoMarked)
        // get image numbers, stored in the topleft of the (pallete?) image
        $yellow = imagecolorat($pano->img,0,0);
        $red = imagecolorat($pano->img,1,0);
        $black = imagecolorat($pano->img,2,0);
        // init counters
        $iSunPixCount = 0; // count the total number of pixeld of the sun
        $iVisiblePixCount = 0; // count the visible number of pixels
        // set sample size (= sun size)
        $pixSunDiameter = $this->sunSizeMultiplier * $this->getPixYaw($pano,$this->sunAngularDiameter);// abuse method to calculate diameter of sun in pixels
        // use Bresenham's algorithm (or Midpoint algorithm)
        $mpXc = $pixYaw;
        $mpYc = $pixPitch;
        $mpR = round($pixSunDiameter/2);
        $mpColor = $yellow; // yellow
        $mpX = 0; 
        $mpY = $mpR; 
        $mpP = 3 - 2 * $mpR;

        $fill = TRUE;
        $outline = TRUE;
        while ($mpY >= $mpX){ // only formulate 1/8 of circle
            foreach(array(array($mpX,$mpY),array($mpY,$mpX)) as $a){
                foreach(array(-1,1) as $invert){
                    $delta = $a[1]*$invert;
                    // mark this sunposition on the marked pano
                    for($lineX=$mpXc-$a[0];$lineX<=$mpXc+$a[0];$lineX++){
                        // count num pixels of the sun
                        $iSunPixCount++;
                        // check if this pixel of the sun is visible
                        if(imagecolorat($pano->img,$lineX, $mpYc+$delta)==$red){
                            $iVisiblePixCount++;
                            $color = $yellow;
                        }else{
                            $color = $black;
                        }
                        if($fill){
                            // draw lines, filling the circle
                            imagesetpixel($panoMarked->img, $lineX, $mpYc+$delta, $color);//upper left left
                        }
                    }
                    if($outline){
                        // draw edgepixels only
                        imagesetpixel($panoMarked->img, $mpXc-$a[0], $mpYc+$delta, $mpColor);//upper left left
                        imagesetpixel($panoMarked->img, $mpXc+$a[0], $mpYc+$delta, $mpColor);//upper right right
                    }
                }
            }
            if ($mpP < 0){
                $mpP += 4*$mpX++ + 6;
            }else{
                $mpP += 4*($mpX++ - $mpY--) + 10;
            }
        } 
        // return fraction of the sun that is visible      
        return round($iVisiblePixCount/$iSunPixCount,1);
    }

    public function sunnyUntil($timeGMT=null){
        $timeGMT = (!is_null($timeGMT))?$timeGMT:$this->timeGMT;
        $bSunVisible = ($this->isSunVisible($timeGMT)>=$this->isSunVisibleThreshold)?TRUE:FALSE;
        if($bSunVisible){
            $aSunWindows = $this->getSunWindows($timeGMT, NULL,1);
            if($aSunWindows['status']=='OK' && count($aSunWindows['results'])){
                return $aSunWindows['results'][0]['timeGMTEnd']; 
            }
        }else{
            return false;
            //return $timeGMT-1;
        }
    }
    public function getSunWindows($timeGMTStart=NULL, $timeGMTEnd=NULL, $limit=FALSE){
        // init return array
        $return = array('status'=>FALSE,'results'=>array(),'error'=>'');
        // check if pano img exists
        if(!$this->pano->img){
            $return['error'].= 'error #28-22432: pano img is not set';
            return $return;
        }
        // get starttime
        $timeGMT = (!is_null($timeGMTStart))?$timeGMTStart:$this->timeGMT;
        // use end of day for timeEnd, if none give
        $timeGMTEnd = (!is_null($timeGMTEnd))?$timeGMTEnd:strtotime("23:59",$timeGMTStart);

        $date_gmt_offset = $this->getTimezone($this->pano->lat, $this->pano->lng, $timeGMT);
        $date_zenith = "90.583333"; // default value for php

        // default to sunrise and sunset, if no start time is given
        // overrule requested times if sun is set anyway
        $timeGMTSunrise = date_sunrise($timeGMT, SUNFUNCS_RET_TIMESTAMP, $this->pano->lat, $this->pano->lng, $date_zenith, $date_gmt_offset);
        $timeGMTSunset  = date_sunset ($timeGMT, SUNFUNCS_RET_TIMESTAMP, $this->pano->lat, $this->pano->lng, $date_zenith, $date_gmt_offset);
        $timeGMTStart  = (!$timeGMTStart || $timeGMTStart<$timeGMTSunrise)?$timeGMTSunrise:$timeGMTStart;
        $timeGMTEnd    = (!$timeGMTEnd   || $timeGMTEnd>$timeGMTSunset)   ?$timeGMTSunset :$timeGMTEnd;
        // if timeStart is after sunSet, return empty
        if($timeGMTStart>$timeGMTSunset){
            return array('status'=>FALSE, 'error #2325431: requested time is after sunset.');
        }

        $interval = $this->getSunWindowsInterval;// seconds
        $aSunWindows = array();
        
        $bSunVisibleAtStart = ($this->isSunVisible($timeGMTStart)>=$this->isSunVisibleThreshold)?TRUE:FALSE;
        if($bSunVisibleAtStart){
            // sun is now visible, save start of sunwindow in array
            array_push($aSunWindows, array(
                'timeGMTStart'=>$timeGMTStart,
                'timeLocalStart'=>$this->localTime($timeGMTStart),
                'timeGMTEnd'=>NULL,
                'timeLocalEnd'=>NULL
            ));
        }
        for($s=$timeGMTStart+$interval;$s<=$timeGMTEnd;$s+=$interval){
            $bSunVisible = ($this->isSunVisible($s)>=$this->isSunVisibleThreshold)?TRUE:FALSE;
            if($bSunVisibleAtStart!=$bSunVisible){
                // change of sun visibility
                if($bSunVisible){
                    // sun is now visible, save start of sunwindow in array
                    array_push($aSunWindows, array(
                        'timeGMTStart'=>$s,
                        'timeLocalStart'=>$this->localTime($s),
                        'timeGMTEnd'=>NULL,
                        'timeLocalEnd'=>NULL
                    ));
                }else{
                    // sun is now invisible, save end of sunwindow in array
                    $aSunWindows[count($aSunWindows)-1]['timeGMTEnd'] = $s;
                    $aSunWindows[count($aSunWindows)-1]['timeLocalEnd'] = $this->localTime($s);
                }
                $bSunVisibleAtStart = $bSunVisible;
                if($limit && count($aSunWindows)>=$limit){
                    break; // only return limited amound of windows
                }
            }
        }
        // close sunwindow if last window still open
        if(isset($aSunWindows[count($aSunWindows)-1]) && is_null($aSunWindows[count($aSunWindows)-1]['timeGMTEnd'])){
            $aSunWindows[count($aSunWindows)-1]['timeGMTEnd'] = $timeGMTEnd;
            $aSunWindows[count($aSunWindows)-1]['timeLocalEnd'] = $this->localTime($this->pano->lat, $this->pano->lng, $timeGMTEnd);
        }
        $return['status']='OK';
        $return['results']=$aSunWindows;
        return $return;
    }
    
    public function initPanoCropped($pano=NULL){        
        $pano= (!is_null($pano))?$pano:$this->pano;
        // crop to possible sun locations only
        $this->panoCropped = $this->cropPano($pano,TRUE);
    }
    public function initPanoRedSky($pano=NULL){        
        $pano= (!is_null($pano))?$pano:$this->pano;
        // make redSky image
        $this->panoRedSky = clone $pano;
        $this->panoRedSky = $this->drawRedSky($this->panoRedSky);
    }
    public function initPanoMarked($pano=NULL){        
        $pano= (!is_null($pano))?$pano:$this->pano;
        $this->panoMarked = clone $pano;
//        if($this->panoMarked->img==$this->panoRedSky->img){
//            // make panoRedSkyMarked into 3 color palette img
//            imagetruecolortopalette($this->panoMarked->img,FALSE,3);
//        }
    }
    public function initPanoSunPos($pano=NULL, $timeGMT=NULL){        
        $pano= (!is_null($pano))?$pano:$this->pano;
        $timeGMT= (!is_null($timeGMT))?$timeGMT:$this->timeGMT;
        // make sunPos image
        $this->panoSunPos = clone $pano;
        $this->panoSunPos = $this->drawSunPos($this->panoSunPos,$timeGMT);
        $this->panoSunPos = $this->displayTextOnPano($this->panoSunPos,$timeGMT);
        $this->panoSunPos->populate(array('time'=>$timeGMT));
    }



    // GET/SAVE PANO DATA/IMAGE METHODS /////////////////////////////////////////////////////////////////////    
    private function getPanoDataFromGoogle($lat,$lng){
$this->throwError("Fetching pano from google...");

        // GET PANO META DATA FROM GOOGLE
        $return = array(
            'google_id'=>NULL,
            'lat'=>NULL,
            'lng'=>NULL
        );
        // query google for the pano id
        $sUrl = 'http://cbk0.google.com/cbk?output=xml&ll='.strval($lat).','.strval($lng);
        $sXml = get_url_contents($sUrl);
        $oXml = new SimpleXMLElement($sXml);
        if(is_object($oXml) && isset($oXml->data_properties)){
            if(isset($oXml->data_properties['pano_id'])){
                $return['google_id'] = (string) $oXml->data_properties['pano_id'];
            }
            if(isset($oXml->data_properties['lat']) && isset($oXml->data_properties['lng'])){
                // update lat/lng for exact pano location
                $return['lat'] = (float) $oXml->data_properties['lat'];
                $return['lng'] = (float) $oXml->data_properties['lng'];
            }
        }
        // GET PANO IMG PATH FROM GOOGLE
        $yaw = 180; // make google serve images with south as center
        $pitch = 0; // google panorama's are more or less aimed at the horizon
        // SET GOOGLE DEFAULTS
        $return['img']=NULL;
        $return['width']=NULL;
        $return['height']=NULL;
        $return['pitch']=$pitch;
        $return['yaw']=$yaw;
        $return['angularWidth']=$this->googleStreetViewPanoramaHorizontalSpread;
        $return['angularHeight']=$this->googleStreetViewPanoramaVerticalSpread;
        // build image path
        $return['path'] = "http://cbk0.google.com/cbk?output=thumbnail&panoid=".$return['google_id']."&yaw=$yaw&pitch=$pitch";

        // return the data array
        return $return;
    }
    private function savePanoToDatabase($pano){
        // make sure pano->id === NULL, if it has no value
        $pano->id = ($pano->id)?$pano->id:NULL;
        $data = array(
            'google_id'=>$pano->google_id,
            'lat'=>$pano->lat,
            'lng'=>$pano->lng,
            'width'=>$pano->width,
            'height'=>$pano->height,
            'angularWidth'=>$pano->angularWidth,
            'angularHeight'=>$pano->angularHeight,
            'pitch'=>$pano->pitch,
            'yaw'=>$pano->yaw,
            'path'=>$pano->path,
        );
        // if pano already has an database id, add it to the dataarray
        if($pano->id){ $data['id'] = $pano->id;}
        if($pano->time){ $data['time'] = $pano->time;}
        // insert pano into the database
        $this->db->query_insert('pano',$data);

        // retrieve id for just inserted pano
        $sql = 'SELECT id FROM '.$this->db->pre.'pano WHERE ';
        foreach ($data AS $key=>$value){
            $sql .= $key."='".$value."' AND ";
        }
        //strip the last AND
        $sql = substr($sql,0,-strlen(" AND "));
        $row = $this->db->query_first($sql);
        if($row){
            $this->pano->id = $row['id'];
$this->throwError("inserterd pano:".$this->pano->id);
        }else{
$this->throwError("insert failed:");
        }
    }
    private function getPanoDataFromDatabase($lat,$lng){
        $max_distance = 100 /1000;// in km
        $sql = "SELECT *, ( 6371 * acos( cos( radians($lat) ) * cos( radians( lat ) ) * cos( radians( lng ) - radians($lng) ) + sin( radians($lat) ) * sin( radians( lat ) ) ) ) AS distance "
            .  "FROM ".$this->db->pre."pano HAVING distance < $max_distance ORDER BY distance LIMIT 1;";
        $row = $this->db->query_first($sql);
        if(!$row){
            return array();
        }
        return $row;
    }


    // TIME DEPENDEND METHODS //////////////////////////////////////////////////////////////////////////
    private function getSunPos($lat,$lng,$timeGMT){
        // get yaw and pitch of sun location
        $sunpos = sunpos($lat,$lng,$timeGMT);
        return array(
            'sunYaw'=>round($sunpos['azimuth']),
            'sunPitch'=>round($sunpos['elevation'])
        );
    }
    public function localTime($timeGMT=NULL,$lat=NULL,$lng=NULL){
        $timeGMT = (!is_null($timeGMT))?$timeGMT:$this->timeGMT;
        $lat = (!is_null($lat))?$lat:$this->lat;// dont have to use this->pano->lat, wich will almost alway be in the same timezone als this->lat
        $lng = (!is_null($lng))?$lng:$this->lng;
        // convert requested time to local time at lat,lng
        $offset = $this->getTimeZone($lat,$lng,$timeGMT);
        $sOffset = ($offset>0)?'+'.$offset:$offset; // add + sign to positive offset, to be able to use in strtotime()
        $timeLocal = strtotime("$sOffset hours", $timeGMT);
        return $timeLocal;        
    }
    public function gmtTime($timeLocal=NULL,$lat=NULL,$lng=NULL){
        $timeLocal = (!is_null($timeLocal))?$timeLocal:$this->timeLocal;
        $lat = (!is_null($lat))?$lat:$this->lat;// dont have to use this->pano->lat, wich will almost alway be in the same timezone als this->lat
        $lng = (!is_null($lng))?$lng:$this->lng;
        // convert requested local time at lat,lng to GMT time 
        $offset = $this->getTimeZone($lat,$lng,$timeLocal);
        // invert offset to transform to GMT time
        $offset *=-1;
        $sOffset = ($offset>0)?'+'.$offset:$offset; // add + sign to positive offset, to be able to use in strtotime()
        $timeGMT = strtotime("$sOffset hours", $timeLocal);
        return $timeGMT;        
    }

    private function getTimezone($lat,$lng,$timeGMT){
        // weird bugfix, sometimes lng and timeGMT arguments are switched around in the method, but not at calltime.
        if($lng>$timeGMT){
            $this->throwError(__FUNCTION__.":BUGFIX");
            $this->throwError("lat:$lat");
            $this->throwError("lng:$lng");
            $this->throwError("timeGMT:$timeGMT");
            $this->throwError("switch em around");
            return $this->getTimezone($lng,$timeGMT,$lat);
        }

        $timeZone = NULL;
        // get the timezone (gmt Offset) for this location and date
        $fLat = round($lat,1);
        $fLng = round($lng,2);
        $sDateId = date("m-d",$timeGMT);
        // see if the timezone is cached (use aproximate location, rounded)
        $id = $fLat.','.$fLng.','.$sDateId;
        if(isset(self::$timeZoneCache[$id])){
            // get cached gmt offset from class property
            $timeZone = (float) self::$timeZoneCache[$id];
        }else{
            // get stored gmt offset from database
            $sql = "SELECT timezone FROM ". $this->db->pre ."timezone_cache"
                . " WHERE id='". $id ."' "
                . " ORDER BY timestamp DESC LIMIT 1"; 
            $row = $this->db->query_first($sql);
            if($row){
                $timeZone = (float) $row['timezone'];
                // cache local
                self::$timeZoneCache[$id]=$timeZone;
            }
        }
        // no timezone in cache, get from url
        if(is_null($timeZone)){
            // get gmt offset from geonames.org
            $sDateUrl = date("Y-m-d",$timeGMT);
            $sUrl = "http://api.geonames.org/timezone?lat=$lat&lng=$lng&date=$sDateUrl&username=eriestuff";
            $sXml = get_url_contents($sUrl);
            $oXml = new SimpleXMLElement($sXml);
            if(is_object($oXml) && isset($oXml->timezone) && isset($oXml->timezone->timezoneId) && strlen($oXml->timezone->timezoneId)){
                $origin_tz = $oXml->timezone->timezoneId;
                $remote_tz = "GMT";
                $origin_dtz = new DateTimeZone($origin_tz);
                $remote_dtz = new DateTimeZone($remote_tz);
                $origin_dt = new DateTime("now", $origin_dtz);
                $remote_dt = new DateTime("now", $remote_dtz);
                $offset = $origin_dtz->getOffset($origin_dt) - $remote_dtz->getOffset($remote_dt);

                $offsetHours = $offset/60/60;
                // return offset
                $timeZone = (float) round($offsetHours,1);
                // save offset in cache (for subsequent request)
                self::$timeZoneCache[$id] = $timeZone;
                // save offset in database (for other threads)
                $data = array('id'=>$id,'lat'=>$fLat,'lng'=>$fLng,'date'=>$sDateId,'timezone'=>$timeZone);
                $this->db->query_insert('timezone_cache',$data);
            }else{
                $this->throwError($sUrl);
                if(is_object($oXml) && isset($oXml->status)  ){
                    $attr = $oXml->status->attributes();
                    $this->throwError('geonames api error:'.$attr['message']);
                }
                $this->throwError('Error: No timezone offset found for '.$id);
            }
        }
        return $timeZone;
    }

    // HELPER METHODS /////////////////////////////////////////////////////////////////////////////////
    private function getPixPitch($pano, $sunPitch){
        $pixPerDegree = $pano->height/$pano->angularHeight;
        $pitchInverter = ($sunPitch>=0)?1:-1;
        $pixPitch = $pano->height/2+ -1*$pitchInverter*(($pitchInverter*$sunPitch)*$pixPerDegree); // elevation of the sun in pixels from the top
        return round($pixPitch);
    }
    private function getPixYaw($pano, $sunYaw){
        // center of image is in direction: $pano->yaw
        $pixPerDegree = $pano->width/$pano->angularWidth;
        $panoLeftYaw = $pano->yaw - $pano->angularWidth/2;
        if($panoLeftYaw<0){
            $panoLeftYaw = 360+$panoLeftYaw;
        }
        if($panoLeftYaw>=360){
            $panoLeftYaw = 360-$panoLeftYaw;
        }
        $pixYaw = ($panoLeftYaw+$sunYaw)*$pixPerDegree;
        return round($pixYaw);
    }


    // IMAGE MANIPULATION METHODS /////////////////////////////////////////////////////////////////////
    public function cropPano($pano, $sunWidthMargins=TRUE,$bMaskOnly=FALSE){
        // get date of widest sun-arc for this pano's hemisphere
        $strtotime = ($pano->lat>=0)?'june 21th 12:00':'december 21th 12:00';
        $dateSolstice = $this->gmtTime(strtotime($strtotime));
        $dateNow = time();
        // pick date to use
        $date = ($bMaskOnly)?$dateNow:$dateSolstice;
        // get sunrise time
        // get sunset time
        // get noon time
        $date_gmt_offset = $this->getTimezone($pano->lat, $pano->lng, $date);
        $date_zenith = "90.583333"; // default value for php
        $timeGMTSunrise = date_sunrise($date, SUNFUNCS_RET_TIMESTAMP, $pano->lat, $pano->lng, $date_zenith, $date_gmt_offset);
        $timeGMTSunset  = date_sunset ($date, SUNFUNCS_RET_TIMESTAMP, $pano->lat, $pano->lng, $date_zenith, $date_gmt_offset);
        $timeGMTNoon    = ($timeGMTSunrise+$timeGMTSunset)/2;

        // get sunrise azimuth (=yaw)
        $aSunPos = $this->getSunPos($pano->lat,$pano->lng,$timeGMTSunrise);
        $yawSunrise = $aSunPos['sunYaw'];
        // get sunset azimuth
        $aSunPos = $this->getSunPos($pano->lat,$pano->lng,$timeGMTSunset);
        $yawSunset = $aSunPos['sunYaw'];
        // get noon  elevation (=pitch)
        $aSunPos = $this->getSunPos($pano->lat,$pano->lng,$timeGMTNoon);
        $pitchNoon = $aSunPos['sunPitch'];
        $yawNoon = $aSunPos['sunYaw']; // should be 180

        // set margins
        $pixSunDiameter = $this->sunSizeMultiplier * $this->getPixYaw($pano,$this->sunAngularDiameter);// abuse method to calculate diameter of sun in pixels
        $margin = ($sunWidthMargins)?$pixSunDiameter:0;

        // calculate pixels to crop (including the margin)
        $pixEast = $this->getPixYaw($pano,$yawSunrise) - $margin;
        $pixWest = $this->getPixYaw($pano,$yawSunset) + $margin;
        $pixElevation = $this->getPixPitch($pano,$pitchNoon) - $margin;
        $pixHorizon = $this->getPixPitch($pano,0) + $margin; // 0 degrees elevation
        // set noon 
        $pixNoon = $this->getPixYaw($pano,$yawNoon);

        // calculate cropped width/height
        $cropWidth = round($pixWest - $pixEast); 
        $cropHeight = round($pixHorizon - $pixElevation);

        if(!$bMaskOnly){
            // copy/crop pano
            $croppedImg = imagecreatetruecolor($cropWidth, $cropHeight);
            imagecopy($croppedImg, $pano->img, 0,0, $pixEast, $pixElevation, $cropWidth, $cropHeight);
            // build pano object to return
            $panoCropped = new Pano();
            $aPop = array(
                'id'=>$pano->id.'_cropped',
                'lat'=>$pano->lat,
                'lng'=>$pano->lng,
                'width'=>$cropWidth,
                'height'=>$cropHeight,
                'angularWidth'=>abs($yawSunset-$yawSunrise),
                'angularHeight'=>$pitchNoon,
                'pitch'=>$pitchNoon/2,
                'yaw'=>$yawSunrise+abs($yawSunset-$yawSunrise)/2,
                'img'=>$croppedImg,
                'time'=>NULL            
            );
            $panoCropped->populate($aPop);
            return $panoCropped;     
        }else{
            $black = imagecolorallocate($pano->img, 0, 0, 0); 
            // window
            imageline($pano->img,$pixEast, $pixElevation, $pixWest, $pixElevation,$black);// top
            imageline($pano->img,$pixWest, $pixElevation, $pixWest, $pixHorizon,$black);// right
            imageline($pano->img,$pixWest, $pixHorizon, $pixEast, $pixHorizon,$black);// bottom
            imageline($pano->img,$pixEast, $pixHorizon, $pixEast, $pixElevation,$black);// left
            // noon line
            imageline($pano->img,$pixNoon, $pixElevation, $pixNoon, $pixHorizon,$black);// noon
        }
    }
    private function drawRedSky($pano){
        // SET COLORS
        $red = imagecolorallocate($pano->img, 255, 0, 0); 
        $white = imagecolorallocate($pano->img, 255, 255, 255);
        $yellow = imagecolorallocate($pano->img, 255, 255, 0); 
        $black = imagecolorallocate($pano->img, 0, 0, 0); 
        // SET TWEAK VARS
        $brightener         = 75;  // desolves cloudes to beter find real edges (tweak to improve edge finding)
        $contrast_tolerance = 100;  // 100.  sets how 'hard' an skyline edge must be to be found,
        // APPLY IMAGE FILTERS TO BETTER FIND EDGE OF SKYLINE      
        imagefilter($pano->img, IMG_FILTER_BRIGHTNESS,$brightener);   // brighten to desolve clouds, tweak to get best 'find edge' result
        imagefilter($pano->img, IMG_FILTER_MEAN_REMOVAL);             // sketchy effect, enhances edges
        //imagefilter($pano->img, IMG_FILTER_MEAN_REMOVAL);             // sketchy effect, enhances edges
        //imagefilter($pano->img, IMG_FILTER_CONTRAST, 25);             // more contrast, enhances edges
        //imagefilter($pano->img, IMG_FILTER_BRIGHTNESS,25);   // brighten to desolve clouds, tweak to get best 'find edge' result
            
        $history = array();
        $mean = 0;
        
        // COLOR THE SKY RED
        // -> in the old version of this script, only the part around the sun was done, to save time
        // traverse al pixels from top-left to right,
        // checking down each 'column' from top downwards for the edge of the skyline
        $imgRedSky = imagecreatetruecolor($pano->width,$pano->height);
        
        // loop from left to right traversing the image width
        for($x=0;$x<$pano->width;$x++){
            $y = 0;
            //save the monochrome color of this pixel, to compare to the next, checking for contrast thresholds
            $monoPixel_prev = imageGrayscaleValueAt($pano->img,$x,$y);
    
            // loop from top downward to the edge of the skyline
            for($y=0;$y<$pano->height;$y++){

                // FIRST, MAKE SURE THE BLUE SKY IS WHITE
                // AND GREEN STUFF IS WHITE TOO
                // this helps to prevent false positives if the sky color is not uniform (ie clouds)
                // save the pixelcolor
                $pixelColor = imageColorsAt($pano->img,$x,$y);
                // break down the pixel color in HSV values
                $pixelHSV = rgb2hsv($pixelColor['red'],$pixelColor['green'],$pixelColor['blue']);
                // if pixel has a blue hue, it's probably a shade of sky (even if its dark)
                // turn pixel into white, to equalize the sky
                if($pixelHSV['H']>200 && $pixelHSV['H']<270){
                    imagesetpixel($pano->img, $x,$y, $white);
                }
                // if pixel has a green hue, it's probably a shade of tree (even if its dark)
                // turn pixel into white, to equalize the sky
                if($pixelHSV['H']==0 || ($pixelHSV['H']>=0 && $pixelHSV['H']<170)){
                    imagesetpixel($pano->img, $x,$y, $white);
                }
                // SECOND, COMPARE GRAYSCALE VALUE TO PREVIOUS PIXEL
                // OR, CHECK IF MEAN HEIGHT OF PREVIOUS SKYLINE EDGE IS NOT SURPASSED (to catch mistakes, the skyline will not often drop in a straight line)
                // OR, CHECK IF BRIGHTNESS OF PIXEL IS NOT SO LOW THAT IT MIGHT BE A BUILDING (to catch gradients with no hard contrast line)
                // get greyscale value of pixel
                $monoPixel = imageGrayscaleValueAt($pano->img,$x,$y);     // get grayscale value of pixel
                // calculate the contrast with the previous pixel
                $contrast = $monoPixel-$monoPixel_prev;            // get the contrast of this pixel compared to the previous one
                // check contrast against the hard-set contranst tolerance             
                if(abs($contrast)>$contrast_tolerance){// $contrast<0 means light to dark 
                    // edge found,
                    // save y in history (below) to calculate mean-skyline height
                    break 1; // break from y loop
                }else{
                    // no edge found
                    // check if mean y (mean skyline height) is not surpassed
                    if($y>$mean && $x!=0){
                        // y greater than mean (or still checking first column so no mean available yet)
                        // continue to see how big y will get (it will be saved in history to calculate the mean)
                     }else{
                        // no edge, no mean surpassed
                        // check if pixel is dark (building) or light (sky)
                        if($monoPixel>150 ){ // minimum: 150, tweak if sun is still shown in dark area's
                            // color this pixel red, in the redSky image
                            imagesetpixel($imgRedSky, $x,$y, $red);
                        }else{
                            //die('Pixel is too dark: '.$monoPixel);
                        }
                    }
                }
                // SAVE MONOCHROME VALUE
                $monoPixel_prev = $monoPixel;                           // remember, to compare with next pixel
            }
            // save y in history buffer
            // update mean y
            array_push($history,$y);
            while(count($history)>3){array_shift($history);}
            $mean = (array_sum($history)/count($history));
            $y=0;
        }
        // leave a yellow dot in upper left corner, to save color and be able to retrieve it later
        imagesetpixel($imgRedSky,0,0,$yellow);
        imagesetpixel($imgRedSky,1,0,$red);
        imagesetpixel($imgRedSky,2,0,$black);

//        imagetruecolortopalette($imgRedSky,FALSE,3);

//        // save image to filesystem
//        imagepng($imgRedSky, './pano/'.$pano->id.'_redSky.gif');

        // swap source and redSky img
        imagedestroy($pano->img); // free resource
        $pano->img = $imgRedSky;
        return $pano;
    }
/*  OLD * /
    private function drawSunPos($pano,$timeGMT=NULL){
        $timeGMT = (!is_null($timeGMT))?$timeGMT:$this->timeGMT;
        
        // SET COLORS
        $red = imagecolorallocate($pano->img, 255, 0, 0); 
        $colorSun = imagecolorallocate($pano->img, 255, 255, 0); 
        // GET SUN POS
        $aSunPos = $this->getSunPos($pano->lat,$pano->lng,$timeGMT);
        // CALCULATE WHERE THE SUN IS IN THIS PANO IMAGE
        $pixPitch = $this->getPixPitch($pano, $aSunPos['sunPitch']);
        $pixYaw = $this->getPixYaw($pano, $aSunPos['sunYaw']);
        // SET SUN SIZE
        $sunDiameterAngular = 20;   // degrees, should be 0.53, but is exagurated to be visible on the image (src= http://en.wikipedia.org/wiki/Angular_diameter)
        $sunDiameterPixels = round(($pano->width/$pano->angularWidth)*$sunDiameterAngular);
        // draw the sun on the pano img
        imagefilledarc ($pano->img,  $pixYaw,  $pixPitch,  $sunDiameterPixels,  $sunDiameterPixels,  0, 360, $colorSun,IMG_ARC_PIE);
        return $pano;
    }
    private function displayTextOnPano($pano,$timeGMT=NULL){
        $timeGMT = (!is_null($timeGMT))?$timeGMT:$this->timeGMT;

        $dstOffset = 0.0;
        $aSunPos = $this->getSunPos($pano->lat, $pano->lng, $timeGMT);
        
        // GET SUN VISIBILITY
        $bSunVisible = ($this->isSunVisible($timeGMT)>=$this->isSunVisibleThreshold)?TRUE:FALSE;;

        // display title on image
        // set text colors
        $black = imagecolorallocate($pano->img, 0, 0, 0); 
        $grey = imagecolorallocate($pano->img, 128, 128, 128);
        $white = imagecolorallocate($pano->img, 255, 255, 255);
        // set font
        $font = 'arial.ttf'; // needs to be available as file in current directory
        // write title
        $text = 'Sun position';
        $fontsize = 14;
        imagettftext($pano->img, $fontsize, 0, 11, 21, $white, $font, $text); // shadow
        imagettftext($pano->img, $fontsize, 0, 10, 20, $black, $font, $text);
        // write sun/shade
        $text = ($bSunVisible)?'In the sun':'In the shade';
        $text = ($aSunPos['sunPitch']<0)?'Sun has set':$text;
        $fontsize = 11;
        imagettftext($pano->img, $fontsize, 0, 11, 41, $white, $font, $text); // shadow
        imagettftext($pano->img, $fontsize, 0, 10, 40, $black, $font, $text);
        // display datetime on image
        $text = 'Local time: '.date("Y-m-d H:i",strtotime("+$dstOffset hours",$this->localTime($timeGMT)));
        $fontsize = 10;
        imagettftext($pano->img, $fontsize, 0, 5, $pano->height-3*($fontsize-2), $white, $font, $text);
        // display lat lng yaw pitsh on image
        $text = "lat=$pano->lat, lng=$pano->lng";
        $text .= ', azimuth='.$aSunPos['sunYaw'].', elevation='.$aSunPos['sunPitch'];
        imagettftext($pano->img, $fontsize, 0, 5, $pano->height-1*($fontsize-2), $white, $font, $text);

        return $pano;
    }

    private function drawSunOnPanoImage($backgroundImage,$redSkyImage, $width,$height, $sunPitch,$sunYaw, $dstOffset){
        die('FUNCTION NOT REMODELED YET');
        // SET COLORS
        $red = imagecolorallocate($redSkyImage, 255, 0, 0); 
        $colorSun = imagecolorallocate($im, 255, 255, 0); 
        // GET GOOGLE STREETVIEW PARAMETERS
        $googleStreetViewPanoramaVerticalSpread = $this->googleStreetViewPanoramaVerticalSpread;
        $googleStreetViewPanoramaHorizontalSpread = $this->googleStreetViewPanoramaHorizontalSpread;
        // CALCULATE WHERE THE SUN IS IN THIS IMAGE
        $pixPitch = $this->getPixPitch($sunPitch,$height,$googleStreetViewPanoramaVerticalSpread);
        $pixYaw = $this->getPixYaw($sunYaw,$width,$googleStreetViewPanoramaHorizontalSpread);
        // SET SUN SIZE
        $sunDiameterAngular = 20;   // degrees, should be 0.53, but is exagurated to be visible on the image (src= http://en.wikipedia.org/wiki/Angular_diameter)
        $sunDiameterPixels = round(($width/$googleStreetViewPanoramaHorizontalSpread)*$sunDiameterAngular);

        // Make the red sky transparent, so backgound will be revealed later on
        imagecolortransparent($redSkyImage, $red);
        // draw the sun on the background, wich might be (partialy) obscured by the forground
        imagefilledarc ($backgroundImage,  $pixYaw,  $pixPitch,  $sunDiameterPixels,  $sunDiameterPixels,  0, 360, $colorSun,IMG_ARC_PIE);
        // Merge forground and background
        imagecopymerge($backgroundImage, $redSkyImage, 0, 0, 0, 0, $width, $height, 100);
        // Draw sun outline on forground, to indicate sun position if obscured by foreground
        imagearc ($backgroundImage,  $pixYaw,  $pixPitch,  $sunDiameterPixels,  $sunDiameterPixels,  0, 360, $colorSun);
  
        //Free up resources
        ImageDestroy($redSkyImage); 

        return $backgroundImage;
    }
/*  END OLD */

}


function sun($lat=0,$lng=0,$time=NULL,$panoid=''){

    $time=($time)?$time:time();
    $pitch = NULL;
    $yaw = NULL;
    $sunVisible = NULL;
    $dstOffset = NULL;
    $im = '';
    $error = '';
    
    if(!$panoid && $lat && $lng){
        // no pano id, but we do have coords,
        // query google for the pano id
        $sUrl = "http://cbk0.google.com/cbk?output=xml&ll=$lat,$lng";
        $sXml = get_url_contents($sUrl);
        $oXml = new SimpleXMLElement($sXml);
        if(is_object($oXml) && isset($oXml->data_properties)){
            //echo "<pre>";print_r($oXml);echo "</pre>";die();
            if(isset($oXml->data_properties['pano_id'])){
                $panoid = (string) $oXml->data_properties['pano_id'];
            }
            if(isset($oXml->data_properties['lat']) && isset($oXml->data_properties['lng'])){
                // update lat/lng for exact pano location
                $lat = (float) $oXml->data_properties['lat'];
                $lng = (float) $oXml->data_properties['lng'];
            }
        }
    }elseif($panoid){
        // we got the panoid, query google for the coords! (cant trust lat lng from request, if given)
        $sUrl = "http://cbk0.google.com/cbk?output=xml&panoid=$panoid";
        $sXml = get_url_contents($sUrl);
        $oXml = new SimpleXMLElement($sXml);
        if(is_object($oXml) && isset($oXml->data_properties)){
            //echo "<pre>";print_r($oXml);echo "</pre>";die();
            if(isset($oXml->data_properties['lat']) && isset($oXml->data_properties['lng'])){
                // update lat/lng for exact pano location
                $lat = $oXml->data_properties['lat'];
                $lng = $oXml->data_properties['lng'];
            }
        }
    }
    
    if($time && $lat && $lng){
        // get yaw and pitch of sun location
        $sunpos = sunpos($lat,$lng,$time);
        $yaw = round($sunpos['azimuth']);
        $pitch = round($sunpos['elevation']);
    
        // get the timezone (gmt Offset)
        $sDate = date("Y-m-d",$time);
        $sUrl = "http://api.geonames.org/timezone?lat=$lat&lng=$lng&date=$sDate&username=eriestuff";
        $sXml = get_url_contents($sUrl);
        $oXml = new SimpleXMLElement($sXml);
        if(is_object($oXml) && isset($oXml->timezone) && isset($oXml->timezone->dstOffset)){
            $dstOffset = (float) $oXml->timezone->dstOffset;
        }
    }
    
    if($panoid && $pitch && $yaw){
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
        $contrast_tolerance = 100;  // 100.  sets how 'hard' an skyline edge must be to be found,
        $sunDiameterAngular = 20;   // degrees, should be 0.53, but is exagurated to be visible on the image (src= http://en.wikipedia.org/wiki/Angular_diameter)
        
        // SET GOOGLE STREETVIEW PARAMETERS
        $googleStreetViewPanoramaVerticalSpread = 180;      // vertical degrees of visibility on panorama picture
        $googleStreetViewPanoramaHorizontalSpread = 360;    // horizontal degrees of visibility on panorama picture
        
        if($im && $width && $height){
            imagefilter($im_temp, IMG_FILTER_BRIGHTNESS,$brightener);   // brighten to desolve clouds, tweak to get best 'find edge' result
            imagefilter($im_temp, IMG_FILTER_MEAN_REMOVAL);             // sketchy effect, enhances edges
        //    imagefilter($im_temp, IMG_FILTER_MEAN_REMOVAL);             // sketchy effect, enhances edges
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
                    $pixelColor = imageColorsAt($im,$x,$y);
        
                    $pixelHSV = rgb2hsv($pixelColor['red'],$pixelColor['green'],$pixelColor['blue']);
                    // if pixel has a blue hue, it's probably a shade of sky (even if its dark)
                    // turn pixel into white, to equalize the sky
                    if($pixelHSV['H']>200 && $pixelHSV['H']<270){
                        imagesetpixel($im_temp, $x,$y, $white);
                    }
        
                    // if pixel has a green hue, it's probably a shade of tree (even if its dark)
                    // turn pixel into white, to equalize the sky
                    if($pixelHSV['H']==0 || ($pixelHSV['H']>=0 && $pixelHSV['H']<170)){
                        imagesetpixel($im_temp, $x,$y, $white);
                    }
        
        
        
                    // get greyscale value of pixel
                    $monoPixel = imageGrayscaleValueAt($im_temp,$x,$y);     // get grayscale value of pixel
                    $contrast = $monoPixel-$monoPixel_prev;            // get the contrast of this pixel compared to the previous one
                    
        
                    if(
                        abs($contrast)>$contrast_tolerance    // $contrast<0 means light to dark 
                    ){
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
                            // check if pixel is dark (building) or light (sky)
                            if($monoPixel>150 ){ // minimum: 150, tweak if sun is still shown in dark area's
                                // color this pixel with a temporary color, wich will be set to transparent later on
                                imagesetpixel($im_forground, $x,$y, $red);
                                //imagesetpixel($im_temp, $x,$y, $red);
                            }else{
                                //die('Pixel is too dark: '.$monoPixel);
                            }
                        }
                    }
        
                    $monoPixel_prev = $monoPixel;                           // remember, to compare with next pixel
        
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
            $sunDiameterPixels = $sunDiameterPixels;
            imagefilledarc ($im,  $pixYaw,  $pixPitch,  $sunDiameterPixels,  $sunDiameterPixels,  0, 360, $colorSun,IMG_ARC_PIE);
        
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
            imagearc ($im,  $pixYaw,  $pixPitch,  $sunDiameterPixels,  $sunDiameterPixels,  0, 360, $colorSun);
        
            // display title on image
            $text = 'Sun position';
            $fontsize = 14;
            imagettftext($im, $fontsize, 0, 11, 21, $white, $font, $text); // shadow
            imagettftext($im, $fontsize, 0, 10, 20, $black, $font, $text);
        
            $text = ($sunVisible)?'In the sun':'In the shade';
            $text = ($pitch<0)?'Sun has set':$text;
            $fontsize = 11;
            imagettftext($im, $fontsize, 0, 11, 41, $white, $font, $text); // shadow
            imagettftext($im, $fontsize, 0, 10, 40, $black, $font, $text);
        
            // display datetime on image
            $text = 'Local time: '.date("Y-m-d H:i",strtotime("+$dstOffset hours",$time));
            $fontsize = 10;
            imagettftext($im, $fontsize, 0, 5, $size[1]-3*($fontsize-2), $white, $font, $text);
            // display lat lng yaw pitsh on image
            $text = "lat=$lat, lng=$lng";
            $text .= ", azimuth=$yaw, elevation=$pitch";
            imagettftext($im, $fontsize, 0, 5, $size[1]-1*($fontsize-2), $white, $font, $text);
  
        }
        
        //Free up resources
//        ImageDestroy($im);  // needs to be returned
        ImageDestroy($im_temp); 
        ImageDestroy($im_forground);
        
    }else{
        //
        $error .= 'no result';
    }

    $aReturn = array(
        'lat'=>$lat,
        'lng'=>$lng,
        'time'=>$time,
        'panoid'=>$panoid,
        'yaw'=>$yaw,
        'pitch'=>$pitch,
        'sunVisible'=>$sunVisible,
        'dstOffset'=>$dstOffset,
        'localTime'=>strtotime("-$dstOffset hours",$time),
        'header'=>'Content-Type: image/gif',
        'ImageGif'=>$im,
        'error'=>$error,
    );
    
    return $aReturn;
}



function imageGrayscaleValueAt($image,$pixelX,$pixelY){
    $colors = imagecolorsforindex($image, imagecolorat($image, $pixelX, $pixelY));
    return round(((0.2125 * $colors['red']) + (0.7154 * $colors['green']) + (0.0721 * $colors['blue'])));
}
function imageBlueValueAt($image,$pixelX,$pixelY){
    $colors = imagecolorsforindex($image, imagecolorat($image, $pixelX, $pixelY));
    return $colors['blue'];
}
function imageColorsAt($image,$pixelX,$pixelY){
    $colors = imagecolorsforindex($image, imagecolorat($image, $pixelX, $pixelY));
    return $colors;
}


function rgb2hsv($R, $G, $B){                                 
// RGB Values:Number 0-255
// HSV Values:number 0-360
   $HSL = array();

   $var_R = ($R / 255);
   $var_G = ($G / 255);
   $var_B = ($B / 255);

   $var_Min = min($var_R, $var_G, $var_B);
   $var_Max = max($var_R, $var_G, $var_B);
   $del_Max = $var_Max - $var_Min;

   $V = $var_Max;

   if ($del_Max == 0){
      $H = 0;
      $S = 0;
   }else{
      $S = $del_Max / $var_Max;

      $del_R = ( ( ( $var_Max - $var_R ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;
      $del_G = ( ( ( $var_Max - $var_G ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;
      $del_B = ( ( ( $var_Max - $var_B ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;

      if      ($var_R == $var_Max) $H = $del_B - $del_G;
      else if ($var_G == $var_Max) $H = ( 1 / 3 ) + $del_R - $del_B;
      else if ($var_B == $var_Max) $H = ( 2 / 3 ) + $del_G - $del_R;

      if ($H<0) $H++;
      if ($H>1) $H--;
   }
    
   // fraction to 0-360
   $HSL['H'] = round($H*360);
   $HSL['S'] = round($S*360);
   $HSL['V'] = round($V*360);

   return $HSL;
}

function FourDec($g) {
	return round($g*10000)/10000;
}

function trunc($g) {
//C code expects an integer arithmetic to do truncation, i.e. just return the quotient
		if ($g>0){
            return floor($g);
        }else{
            return ceil($g);
        } 
}

function sunpos($lat,$lng,$time=null){
//converted from javascript at http://pvcdrom.pveducation.org/SUNLIGHT/sunPSA.HTM
//converted from C++ code at www.psa.es/sdg/sunpos.htm Please check website for latest code
    if(is_null($time)){
        $time = time();
    }

	$udtTimeiYear       = date('Y',$time);
	$udtTimeiMonth      = date('m',$time);
	$udtTimeiDay        = date('d',$time);
	$udtTimedHours      = date('H',$time);
	$udtTimedMinutes    = date('i',$time);
	$udtTimedSeconds    = date('s',$time);

	$udtLocationdLongitude=$lng;
	$udtLocationdLatitude=$lat;
	$pi =3.14159265358979323846;
	$twopi=(2*$pi);
	$rad=($pi/180);
	$dEarthMeanRadius=6371.01;	// In km
	$dAstronomicalUnit=149597890;	// In km
	$dDecimalHours = $udtTimedHours + ($udtTimedMinutes + $udtTimedSeconds / 60.0 ) / 60.0;
	// Calculate current Julian Day not use of trunc since Javascript doesn't support div for integters like C++
	$liAux1 = trunc(($udtTimeiMonth-14)/12);
	$liAux2 = trunc((1461*($udtTimeiYear + 4800 + $liAux1))/4) + trunc((367*($udtTimeiMonth - 2-12*$liAux1))/12)- trunc((3*trunc(($udtTimeiYear + 4900 + $liAux1)/100))/4)+$udtTimeiDay-32075;
	$dJulianDate=($liAux2)-0.5+$dDecimalHours/24.0;
	$dElapsedJulianDays = $dJulianDate-2451545.0;
	$dOmega=2.1429-0.0010394594*$dElapsedJulianDays;
	$dMeanLongitude = 4.8950630+ 0.017202791698*$dElapsedJulianDays; // Radians
	$dMeanAnomaly = 6.2400600+ 0.0172019699*$dElapsedJulianDays;
	$dEclipticLongitude = $dMeanLongitude + 0.03341607* sin($dMeanAnomaly ) + 0.00034894* sin( 2*$dMeanAnomaly )-0.0001134 -0.0000203* sin($dOmega);
	$dEclipticObliquity = 0.4090928 - 6.2140e-9* $dElapsedJulianDays +0.0000396*cos($dOmega);
	$dSin_EclipticLongitude= sin( $dEclipticLongitude );
	$dY = cos( $dEclipticObliquity ) * $dSin_EclipticLongitude;
	$dX = cos( $dEclipticLongitude );
	$dRightAscension = atan2( $dY,$dX );
	if( $dRightAscension < 0.0 ) $dRightAscension = $dRightAscension + $twopi;
	$dDeclination = asin( sin( $dEclipticObliquity )* $dSin_EclipticLongitude );
	$dGreenwichMeanSiderealTime = 6.6974243242 + 0.0657098283*$dElapsedJulianDays + $dDecimalHours;
	$dLocalMeanSiderealTime = ($dGreenwichMeanSiderealTime*15 + $udtLocationdLongitude)*$rad;
	$dHourAngle = $dLocalMeanSiderealTime - $dRightAscension;
	$dLatitudeInRadians = $udtLocationdLatitude*$rad;
	$dCos_Latitude = cos( $dLatitudeInRadians );
	$dSin_Latitude = sin( $dLatitudeInRadians );
	$dCos_HourAngle= cos( $dHourAngle );
	$udtSunCoordinatesdZenithAngle = (acos( $dCos_Latitude*$dCos_HourAngle*cos($dDeclination) + sin( $dDeclination )*$dSin_Latitude));
	$dY = -1* sin( $dHourAngle );
	$dX = tan( $dDeclination )*$dCos_Latitude - $dSin_Latitude*$dCos_HourAngle;
	$udtSunCoordinatesdAzimuth = atan2( $dY, $dX );
	if ( $udtSunCoordinatesdAzimuth < 0.0 ) {
		$udtSunCoordinatesdAzimuth = $udtSunCoordinatesdAzimuth + $twopi;
	}
	$udtSunCoordinatesdAzimuth = $udtSunCoordinatesdAzimuth/$rad;
	$dParallax=($dEarthMeanRadius/$dAstronomicalUnit)*sin($udtSunCoordinatesdZenithAngle);
	$udtSunCoordinatesdZenithAngle=($udtSunCoordinatesdZenithAngle + $dParallax)/$rad;

    $azimuth=FourDec($udtSunCoordinatesdAzimuth);
	$zenith=FourDec($udtSunCoordinatesdZenithAngle);
	$elevation=FourDec(90-$udtSunCoordinatesdZenithAngle);

    return array('lat'=>$lat,'lng'=>$lng,'time'=>$time,'azimuth'=>$azimuth,'zenith'=>$zenith,'elevation'=>$elevation);
}

function get_url_contents($url, $method='curl'){
//    _die($url.'</br>');
    switch($method){
        case'curl':
            $crl = curl_init();
            $timeout = 5;
            curl_setopt ($crl, CURLOPT_URL,$url);
            curl_setopt ($crl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt ($crl, CURLOPT_CONNECTTIMEOUT, $timeout);
            $ret = curl_exec($crl);
            curl_close($crl);
            return $ret;
            break;
        case 'file_get_contents':
            return file_get_contents($url);   
            break;
    }
}

function print_pre($obj){
	echo "<pre>";
	print_r($obj);
	echo "</pre>";
}
function microtime_float(){
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}
function _die($str=''){
    //die($str);
    echo $str.'</br>';
}

?>
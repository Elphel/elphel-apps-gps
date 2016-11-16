<?php
/*
 * FILE NAME  : read_imu_log.php
 * DESCRIPTION: parse imu log from Elphel 10393 (and 10353)
 * VERSION: 1.0
 * AUTHOR: Oleg K Dzhimiev <oleg@elphel.com>
 * LICENSE: AGPL, see http://www.gnu.org/licenses/agpl.txt
 * Copyright (C) 2016 Elphel, Inc.
 */
 
set_time_limit(0);

$CLI = (php_sapi_name()=="cli");

if ($CLI){
  $_SERVER['REMOTE_ADDR'] = "localhost";
  $_SERVER['SERVER_ADDR'] = "localhost";
  $_GET['format'] = "csv";
  $_GET['nth'] = 1;
  $_GET['limit'] = -1;
  
  $_GET['nogui'] = true;
 
  if (isset($argv[1])) $_GET['file'] = $argv[1];
  else{
    die(<<<TEXT
    
\033[91mERROR: Filename is not set.\033[0m 

Command line usage examples:
* Minimal:
    \033[1;37m~$ php thisscript.php logfile > logfile.csv\033[0m
* With a filter, IMU records only:
    \033[1;37m~~$ php thisscript.php logfile 0x010 > logfile.csv\033[0m
* With a filter, GPS NMEA GPRMC records ony:
    \033[1;37m~~$ php thisscript.php logfile 0x001 > logfile.csv\033[0m
* Filter bits:
    External trigger source:
      0x200
    Image records:
      0x100 channel 3
      0x080 channel 2
      0x040 channel 1
      0x020 channel 0
    IMU:
      0x010
    GPS records:
      0x008 - NMEA GPVTG
      0x004 - NMEA GPGSA
      0x002 - NMEA GPGGA (have coordinates)
      0x001 - NMEA GPRMC (have coordinates) 

    
TEXT
    );
  }
 
  $_GET['record'] = 0;
}

$thisname = basename($_SERVER['SCRIPT_NAME']);
$remoteaccess = ($_SERVER['REMOTE_ADDR']!=$_SERVER['SERVER_ADDR']);
$hardcodeddir = "logs";

if (isset($_GET['format'])){
  $format = $_GET['format'];
}else{
  $format = "html";
}

if (isset($_GET['nth'])){
  $nth = $_GET['nth']+0;
}else{
  $nth = 1;
}

if (isset($_GET['limit'])){
  $limit = $_GET['limit']+0;
}else{
  $limit = -1;
}

if (isset($_GET['nogui'])){
  $nogui = true;
  if ($format=="csv"){
    header("Content-Type: application/octet-stream");
    header('Content-Disposition: attachment; filename='.basename($_GET['file']).".".$format);
  }else{
    header("Content-Type: application/xml");
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<Document>\n";
  }
}else{
  //header("Content-Type: text/xml");
  $nogui = false;
}

if (isset($_GET['source'])){
  show_source($_SERVER['SCRIPT_FILENAME']);
  die(0);
}

if (isset($_GET['list'])){
  echo help();
  die(0);
}

if (isset($_GET['file'])){
  if ($remoteaccess){
    $file = $hardcodeddir."/".basename($_GET['file']);
  }else{
    $file = $_GET['file'];
  }

  if (is_file($file)){
    $numRecordsInFile=filesize($file)/64;
  }else{
    //for local access also check $hardcodeddir
    if (is_file($hardcodeddir."/".$file)){
      $file = $hardcodeddir."/".$file;
      $numRecordsInFile=filesize($file)/64;
    }else{
      echo "File not found";
      die(0);
    }
  }
  $init = true;
}else{
  $init = false;
}

if ($CLI){
  $_GET['nrecords'] = $numRecordsInFile;
  if (isset($argv[2])) $_GET['filter'] = $argv[2];
}

if (isset($_GET['record']))   $record = $_GET['record']+0;
else                          $record=0;

if ($record>($numRecordsInFile-1)) $record=$numRecordsInFile-1;

if (isset($_GET['nrecords'])) $nRecords = $_GET['nrecords']+0;
else{
  if ($numRecordsInFile>5000)
    $nRecords=5000;
  else
    $nRecords=$numRecordsInFile;
}

if ($nRecords>($numRecordsInFile-$record)) $nRecords= $numRecordsInFile-$record;

if ($limit>0) $nRecords= $numRecordsInFile;

if (isset($_GET['showall'])){
  $limit = $numRecordsInFile;
  $nRecords= $numRecordsInFile;
}

if (isset($_GET['filter'])) $filter= intval($_GET['filter'],0);
else                        $filter= 0x3ff;

/*
 1 - NMEA sentence 0 $GPRMC (log type 1)
 2 - NMEA sentence 1 $GPGGA (log type 1)
 4 - NMEA sentence 2 $GPGSA (log type 1)
 8 - NMEA sentence 3 $GPVTG (log type 1)
16 - IMU (type 0)
32 - SYNC (type 2)
64 - ODOMETER (type 3)
*/

if ($limit<0) $limit = $nRecords;

if(!$nogui){
  //list available files
  echo html();
  die(0);
}

//$timeShift = 21600; // 6 hrs
$timeShift = 0; // 0 hrs

$type=-1;
$gpsType=-1;

$filterhex = dechex($filter);
$sindex = $record;
$eindex = $record+$nRecords;

if ($format=="csv"){
  echo "Filename,$file\n";
  echo "Found Records,$numRecordsInFile\n";
  echo "Record filter,0x$filterhex\n";
  echo "Show limit, $limit\n";
  echo "Start index,$sindex\n";
  echo "End index,$eindex\n\n";
}else{
  echo <<<TEXT
<table>
<tr><td>Filename</td><td>$file</td></tr>
<tr><td>Found records</td><td>$numRecordsInFile</td></tr>
<tr><td>Filter</td><td>$filterhex</td></tr>
<tr><td>Show limit</td><td>$limit</td></tr>
<tr><td>Start index</td><td>$sindex</td></tr>
<tr><td>End index</td><td>$eindex</td></tr>
</table>
TEXT;
}

$log_file=fopen($file,'r');

$averageIMU=array(
    "number"=>0,
    "gyroX"=> 0,
    "gyroY"=> 0,
    "gyroZ"=> 0,
    "accelX"=>0,
    "accelY"=>0,
    "accelZ"=>0,
    "angleX"=>0,
    "angleY"=>0,
    "angleZ"=>0,
    "velocityX"=>0,
    "velocityY"=>0,
    "velocityZ"=>0,
    "temperature"=>0);

$imuFieldOrder = array(
    "gyroX",
    "gyroY",
    "gyroZ",
    "accelX",
    "accelY",
    "accelZ",
    "angleX",
    "angleY",
    "angleZ",
    "velocityX",
    "velocityY",
    "velocityZ",
    "temperature"
);
    
imuLogParse($log_file,$record,$nRecords,$filter);

die(0);

$gc = array(
  array(0,0,0,0),
  array(0,0,0,0),
  array(0,0,0,0),
  array(0,0,0,0)
);

function global_counters($i,$j){
  global $gc, $nth;
  
  if (($gc[$i][$j]%$nth)==0) {
    $gc[$i][$j] = 1;
    return true;
  }else{
    $gc[$i][$j]++;
    return false;
  }
}

function imuLogParse($handle,$record,$nSamples,$filter,$tryNumber=10000){

  global $timeShift;
  global $averageIMU;
  global $imuFieldOrder;
  global $format,$limit;
  
  global $gc;
    
  $gpsFilter = ($filter)&0xf;    //type=1
  $imuFilter = ($filter>>4)&0x1; //type=0
  $imgFilter = ($filter>>5)&0xf; //type=2
  $extFilter = ($filter>>9)&0x1; //type=3
  
  $typeFilter = (($gpsFilter!=0)?2:0) | (($imuFilter!=0)?1:0) | ((($imgFilter)!=0)?4:0) | ((($extFilter)!=0)?8:0);
  
  $skip_imu_cols = true;
  $imu_cols_csv = "";
  $imu_cols_html_header = "";
  $imu_cols_html = "";
  
  if ($imuFilter==0x1) {
    $skip_imu_cols = false;
    $imu_cols_csv_header = implode(",",$imuFieldOrder).",";
    for($i=0;$i<count($imuFieldOrder);$i++){
      $imu_cols_html .= "<td></td>\n";
      $imu_cols_html_header .= "<td align='left'><div style='width:170px'>{$imuFieldOrder[$i]}</div></td>\n";
      $imu_cols_csv .= ",";
    }
  }
  
  fseek($handle,64*$record,SEEK_SET);

  if ($format=="csv"){    
    echo "Index,Timestamp,Type,{$imu_cols_csv_header}GPS NMEA Type,GPS NMEA Sentence,Sensor Port,Master Timestamp,Master Timestamp,EXT\n";
  }else{
    echo <<<TEXT
<table>
<tr>
<td>Index</td>
<td align='center'>Timestamp</td>
<td align='center'><div style='width:50px;'>Type</div></td>
TEXT;
    echo $imu_cols_html_header;
    echo "<td align='center'>Sensor Port</td>\n";
    echo "<td align='center'><div style='width:180px;'>Master Timestamp</div></td>\n";
    echo "<td align='center'><div style='width:200px;'>Master Timestamp</div></td>\n";
    echo "<td align='center'><div style='width:100px;'>GPS NMEA Type</div></td>\n";
    echo "<td align='center'><div style='width:100px;'>GPS NMEA Sentence</div></td>\n";
    echo "<td>EXT</td>\n";
    echo "</tr>\n";
  }
  
  $gc = array(
    array(0,0,0,0),
    array(0,0,0,0),
    array(0,0,0,0),
    array(0,0,0,0)
  );
  
  for ($nSample=0;$nSample<$nSamples;$nSample++) {
  
    $sample=fread($handle,64);
    $arr32=unpack('L*',$sample);
    $time=(($arr32[1]&0xfffff)/1000000)+$arr32[2];
    
    $type=$arr32[1]>>24;
    
    $show_record = false;
    switch($type){
      case 0:
        if ($imuFilter!=0) $show_record = global_counters($type,0);
        break;
      case 1:
        if ((($gpsFilter>>($arr32[3]&0x3))&1)==1) $show_record = global_counters($type,$arr32[3]&0x3);
        break;
      case 2:
        $subchannel = ($arr32[3] >> 24);
        if ((($imgFilter>>$subchannel)&1)==1) $show_record = global_counters($type,$subchannel);
        break;
      case 3:
        if (($extFilter&1)==1) $show_record = global_counters($type,0);
        break;
    }
    
    if ($show_record) {
      
      $limit--;
      if ($limit==-1) break;
      //beginning
      if ($format=="csv"){
        printf("%d,%f,$type,",($record+$nSample),$time);
      }else{
        echo "<tr>\n";
        printf("<td>%d</td>\n<td>%f</td>\n<td align='center'>$type</td>\n",($record+$nSample),$time);
      }
      
      switch ($type) {
        // IMU record
        case 0:

          $imuSample=parseIMU($arr32);
          
          if ($format=="csv"){
            echo implode(",",$imuSample)."\n";
          }else{
            foreach($imuSample as $imus){
            //for($i=0;$i<count($imuSample);$i++){
              echo "<td>{$imus}</td>\n";
            }
            echo "</tr>\n";
          }
          
          /*
          echo " [angleX]=>".$imuSample["angleX"]."     [angleY]=>".$imuSample["angleY"]."     [angleZ]=>".$imuSample["angleZ"].
                             "     [gyroX] =>".$imuSample["gyroX"] ."      [gyroY]=>".$imuSample["gyroY"] ."      [gyroZ]=>".$imuSample["gyroZ"].
                             "     [accelX] =>".$imuSample["accelX"] ."      [accelY]=>".$imuSample["accelY"] ."      [accelZ]=>".$imuSample["accelZ"].
                             "     [velocityX] =>".$imuSample["velocityX"] ."      [velocityY]=>".$imuSample["velocityY"] ."      [velocityZ]=>".$imuSample["velocityZ"].
                             "     [temperature]=>".$imuSample["temperature"]."\n";
          */
          break;
          
        // GPS record
        case 1:
        
          $nmeaArray=parseGPS($sample);
          
          $nmeaString = '$'.implode(",",$nmeaArray);
          $nmeaType = $nmeaArray[0];
          
          if ($format=="csv"){
            echo "$imu_cols_csv,,,$nmeaType,\"".$nmeaString."\"\n";
          }else{
            echo "$imu_cols_html";
            //for img
            echo "<td></td>\n";
            echo "<td></td>\n";
            echo "<td></td>\n";
            echo "<td align='center'>$nmeaType</td>\n<td>$nmeaString</td>\n";
            echo "</tr>\n";
          }
          
          break;
          
        // Master (Sync) record
        case 2:
          $subchannel = ($arr32[3] >> 24);
          $masterTime=(($arr32[3]&0xfffff)/1000000)+$arr32[4];
          
          if ($format=="csv"){
            printf("$imu_cols_csv$subchannel,%f,".(gmdate(DATE_RFC850,$masterTime))."\n",$masterTime);
            //echo ",,,,,,,,,,,,,,,\"Subchannel: <b>".$subchannel."</b> MasterTimeStamp: <b>".($masterTime+$timeShift)."</b> TimeStamp: <b>".($time+$timeShift)."</b>   MasterTimeStamp(precise): 0x".dechex($arr32[4])."+".dechex($arr32[3])." TimeStamp(precise): 0x".dechex($arr32[2])."+".dechex($arr32[1])." $masterTime - ".gmdate(DATE_RFC850,$masterTime)." [local timestamp - ".gmdate(DATE_RFC850,$time)."]\"\n";
          }else{
            echo "$imu_cols_html";
            
            echo "<td align='center'>$subchannel</td>\n";
            printf("<td>%f</td>\n",$masterTime);
            echo "<td>".gmdate(DATE_RFC850,$masterTime)."</td>\n";
          }
          break;
          
        // Show hex data
        case 3: 
            $msg = "\"".implode(",",$arr32)."\"\n";
            
            if ($format=="csv"){
              echo "$imu_cols_csv,,,,,$msg";
            }else{
              echo "$imu_cols_html";
              echo "<td></td>\n";
              echo "<td></td>\n";
              echo "<td></td>\n";
              echo "<td></td>\n";
              echo "<td></td>\n";
              echo "<td>$msg</td>\n";
            }
            
          break;
      }
    }
  }
  if ($format!="csv"){
    echo "</table>\n";
    echo "</Document>\n";
  }
}

function parseGPS($sample){
  $sentences=array(
    0=>"GPRMC",
    1=>"GPGGA",
    2=>"GPGSA",
    3=>"GPVTG"
   );
  $formats=array(
    0=>str_split('nbnbnbnnnnb'),
    1=>str_split('nnbnbnnnnbnbbb'),
    2=>str_split('bnnnnnnnnnnnnnnnn'),
    3=>str_split('nbnbnbnb')
   );
  //print_r($formats);
  /*
    'R','M','C','n','b','n','b','n','b','n','n','n','n','b', 0,  0,  0,  0,  0,  0,  0,0,0,0,  0,0,0,0,  0,0,0,0,
    'G','G','A','n','n','b','n','b','n','n','n','n','b','n','b','b','b', 0,  0,  0,  0,0,0,0,  0,0,0,0,  0,0,0,0,
    'G','S','A','b','n','n','n','n','n','n','n','n','n','n','n','n','n','n','n','n', 0,0,0,0,  0,0,0,0,  0,0,0,0,
    'V','T','G','n','b','n','b','n','b','n','b', 0,  0,  0,  0,  0,  0,  0,  0,  0,  0,0,0,0,  0,0,0,0,  0,0,0,0,

  */
  $arr8= unpack('C*',$sample);
  $type=$arr8[4];
  $nibbleNumber=16; // starting with 0;
  $gps=getNibble($nibbleNumber++,$arr8);

  $rslt=array(0=>$sentences[$gps]);
  for ($i=0;$i<count($formats[$gps]);$i++){
    $rslt[$i+1]='';
    if ($formats[$gps][$i]=='n'){ //nibbles
      while (true) {
        $nibble=getNibble($nibbleNumber++,$arr8);
        if ($nibble==0xf) break;
        $rslt[$i+1].=chr($nibble+(($nibble>9)?0x20:0x30));
      }
    } else { // bytes
      do {
       $byte=getNibble($nibbleNumber++,$arr8);
       $byte+=(getNibble($nibbleNumber++,$arr8)<<4);
       if (($byte&0x7f)!=0x7f) $rslt[$i+1].=chr($byte&0x7f);
      } while (($byte&0x80)==0);
    }
  }
  return $rslt;
}

function getNibble($n,$arr8){
 return ($arr8[($n>>1)+1]>>(($n&1)?4:0))&0xf;
}

function parseIMU ($arr32){
  $gyroScale=0.013108/65536;      // deg/sec
  $accelScale=0.8192/65536;       // mg
  $angleScale= 0.005493/65536;    // degrees
  $velocityScale=0.0030518/65536; // m/sec
  //$angleScale= 0.005493;      //degrees
  //$velocityScale=0.0030518;   //m/sec
  $temperatureScale=0.00565;      // C/LSB
  $t=($arr32[15] & 0xffff);
  if ($t>=32768) $t-=65536;
  
  for ($i=3;$i<15;$i++) if ((($arr32[$i] & 0x80000000)!=0) && ($arr32[$i] > 0)) $arr32[$i]-=0x100000000;
  
  return array(
    "gyroX"=> $arr32[ 3]*$gyroScale,
    "gyroY"=> $arr32[ 4]*$gyroScale,
    "gyroZ"=> $arr32[ 5]*$gyroScale,
    "accelX"=>$arr32[ 6]*$accelScale,
    "accelY"=>$arr32[ 7]*$accelScale,
    "accelZ"=>$arr32[ 8]*$accelScale,
    "angleX"=>$arr32[ 9]*$angleScale,
    "angleY"=>$arr32[10]*$angleScale,
    "angleZ"=>$arr32[11]*$angleScale,
    "velocityX"=>$arr32[12]*$velocityScale,
    "velocityY"=>$arr32[13]*$velocityScale,
    "velocityZ"=>$arr32[14]*$velocityScale,
    "temperature"=>$t*$temperatureScale+25
  );
}

/*
function parseNMEA($sent,$data){

eg4. $GPRMC,hhmmss.ss,A,llll.ll,a,yyyyy.yy,a,x.x,x.x,ddmmyy,x.x,a*hh
1    = UTC of position fix
2    = Data status (V=navigation receiver warning)
3    = Latitude of fix
4    = N or S
5    = Longitude of fix
6    = E or W
7    = Speed over ground in knots
8    = Track made good in degrees True
9    = UT date
10   = Magnetic variation degrees (Easterly var. subtracts from true course)
11   = E or W
12   = Checksum

eg3. $GPGGA,hhmmss.ss,llll.ll,a,yyyyy.yy,a,x,xx,x.x,x.x,M,x.x,M,x.x,xxxx*hh
1    = UTC of Position
2    = Latitude
3    = N or S
4    = Longitude
5    = E or W
6    = GPS quality indicator (0=invalid; 1=GPS fix; 2=Diff. GPS fix)
7    = Number of satellites in use [not those in view]
8    = Horizontal dilution of position
9    = Antenna altitude above/below mean sea level (geoid)
10   = Meters  (Antenna height unit)
11   = Geoidal separation (Diff. between WGS-84 earth ellipsoid and
       mean sea level.  -=geoid is below WGS-84 ellipsoid)
12   = Meters  (Units of geoidal separation)
13   = Age in seconds since last update from diff. reference station
14   = Diff. reference station ID#
15   = Checksum

GPS Satellites in view

eg. $GPGSV,3,1,11,03,03,111,00,04,15,270,00,06,01,010,00,13,06,292,00*74
    $GPGSV,3,2,11,14,25,170,00,16,57,208,39,18,67,296,40,19,40,246,00*74
    $GPGSV,3,3,11,22,42,067,42,24,14,311,43,27,05,244,00,,,,*4D


    $GPGSV,1,1,13,02,02,213,,03,-3,000,,11,00,121,,14,13,172,05*67


1    = Total number of messages of this type in this cycle
2    = Message number
3    = Total number of SVs in view
4    = SV PRN number
5    = Elevation in degrees, 90 maximum
6    = Azimuth, degrees from true north, 000 to 359
7    = SNR, 00-99 dB (null when not tracking)
8-11 = Information about second SV, same as field 4-7
12-15= Information about third SV, same as field 4-7
16-19= Information about fourth SV, same as field 4-7

eg3. $GPVTG,t,T,,,s.ss,N,s.ss,K*hh
1    = Track made good
2    = Fixed text 'T' indicates that track made good is relative to true north
3    = not used
4    = not used
5    = Speed over ground in knots
6    = Fixed text 'N' indicates that speed over ground in in knots
7    = Speed over ground in kilometers/hour
8    = Fixed text 'K' indicates that speed over ground is in kilometers/hour
9    = Checksum

// IMU logged data:
    0x10, // x gyro low
    0x12, // x gyro high 0.013108 deg/sec
    0x14, // y gyro low
    0x16, // y gyro high 0.013108 deg/sec
    0x18, // z gyro low
    0x1a, // z gyro high 0.013108 deg/sec

    0x1c, // x accel low
    0x1e, // x accel high 0.8192 mg
    0x20, // y accel low
    0x22, // y accel high
    0x24, // z accel low
    0x26, // z accel high

    0x40, // x delta angle low
    0x42, // x delta angle high +/-179.9891, LSB=0.005493 degree
    0x44, // y delta angle low
    0x46, // y delta angle high +/-179.9891, LSB=0.005493 degree
    0x48, // z delta angle low
    0x4a, // z delta angle high +/-179.9891, LSB=0.005493 degree

    0x4c, // x delta velocity low
    0x4e, // x delta velocity high +/-99.998 m/sec, LSB=3.0518mm/sec
    0x50, // y delta velocity low
    0x52, // y delta velocity high +/-99.998 m/sec, LSB=3.0518mm/sec
    0x54, // z delta velocity low
    0x56, // z delta velocity high +/-99.998 m/sec, LSB=3.0518mm/sec

    0x0e, // temperature 25C+ 0.00565C/LSB
    0x70, // time m/s [13:8] - minutes, [5:0] seconds
    0x72, // time d/h [12:8] - day [5:0] - hours
    0x74,// time y/m  [14:8] year (from 2000), [3:0] - month

}
*/

function showlist(){

  global $hardcodeddir;
  global $remoteaccess;
 
  $local = true;
 
  if (isset($_GET['file'])) $file = $_GET['file'];
   
  if ($remoteaccess){
    $dir = $hardcodeddir;
  }else{
    if (!isset($file)||($file=="")){
      $dir = ".";
    }else{
      if (is_dir($file)){
        $dir = $file;
      }else{
        $path = pathinfo($file);
        if (!isset($path['dirname'])){
          $dir = $hardcodeddir;
        }else{
          $dir = $path['dirname'];
        }
      }
    }
  }
  
  $files = scandir($dir);
  
  if ($dir=="."||($remoteaccess)) $dirlink="";
  else                            $dirlink="$dir/";
  
  $res = "";
  
  foreach($files as $f){
    if (($f[0]!=".")&&(!is_dir($dir.$f))) {
      $res .= "<li><a href='#' class='filenamechanger'>$dirlink$f</a></li>\n";
    }
  }

  $base = substr($_SERVER['SCRIPT_NAME'],0,strrpos($_SERVER['SCRIPT_NAME'],"/")+1);
  $base = $_SERVER['SERVER_NAME'].$base;
  
  $res = "Log files list (<b><i>$base$dir</i></b>, click to select):<ul>$res</ul>";
  
  return $res;
  
}

function html(){

  global $file,$limit,$record,$nRecords,$filter,$nth;
  global $init;

  $ins_filter = array();
  
  if ($init) {
    $ins_file = $file;
    $ins_limit = $limit;
    $ins_rec = $record;
    $ins_nrec = $_GET['nrecords']+$record;
    for($i=0;$i<10;$i++){
      if (($filter>>$i)&1==1) $ins_filter[$i] = "checked";
    }
    $ins_nth = $nth;
  }else{
    $ins_file = "imu.log";
    $ins_limit = 500;
    $ins_rec = 0;
    $ins_nrec = 5000;
    for($i=0;$i<10;$i++){
      $ins_filter[$i] = "checked";
    }
    $ins_nth = 1;
  }
  
  $js = js();
  return <<<TEXT
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8"/>
    <meta name="author" content="?"/>
    <style>
    #results td{
      padding:0px 10px;
    }
    #results{
      font-size:14px;
    }
    
    #controls{
      background: rgba(100,200,100,0.5);
      padding: 10px;
    }
    </style>
    <script>$js</script>
  </head>
  <body onload='init()'>
    <table id='controls'>
    <tr>
      <td>
        <table>
        <tr>
          <td>Filename: <input type='text' id='file' value='$ins_file' style='width:400px;' onchange='getRqStr()'>&nbsp;<button onclick='getList()'>List log files</button>&nbsp;&nbsp;</td>
        </tr>
        <tr>
          <td colspan=''>Record filter:&nbsp;
            <span title='External device'>EXT
              <input id='filter_9' type='checkbox' {$ins_filter[9]} onchange='getRqStr()' title='External source'>
            </span>&nbsp;&nbsp;
            <span title='Image trigger signal'>IMG
              <input id='filter_8' type='checkbox' {$ins_filter[8]} onchange='getRqStr()' title='Sensor port 3'>
              <input id='filter_7' type='checkbox' {$ins_filter[7]} onchange='getRqStr()' title='Sensor port 2'>
              <input id='filter_6' type='checkbox' {$ins_filter[6]} onchange='getRqStr()' title='Sensor port 1'>
              <input id='filter_5' type='checkbox' {$ins_filter[5]} onchange='getRqStr()' title='Sensor port 0'>
            </span>&nbsp;&nbsp;
            <span title='IMU'>IMU
              <input id='filter_4' type='checkbox' {$ins_filter[4]} onchange='getRqStr()'>
            </span>&nbsp;&nbsp;
            <span title='GPS'>GPS
              <input id='filter_3' type='checkbox' {$ins_filter[3]} onchange='getRqStr()' title='NMEA GPVTG'>
              <input id='filter_2' type='checkbox' {$ins_filter[2]} onchange='getRqStr()' title='NMEA GPGSA'>
              <input id='filter_1' type='checkbox' {$ins_filter[1]} onchange='getRqStr()' title='NMEA GPGGA'>
              <input id='filter_0' type='checkbox' {$ins_filter[0]} onchange='getRqStr()' title='NMEA GPRMC'>
            </span>
          </td>
        </tr>
        <tr>
          <td>
            <table>
            <tr>
              <td></td>
              <td>Begin</td><td><input id='start' type='text' value='$ins_rec' style='width:100px;text-align:right;' onchange='getRqStr()'></td>
            </tr>
            <tr>
              <td></td>
              <td>End</td><td><input id='end' type='text' value='$ins_nrec' style='width:100px;text-align:right;' onchange='getRqStr()'></td>
            </tr>
            <tr>
              <td><input type='checkbox' id='show_limit_toggle' checked onchange='getRqStr()'></td>
              <td>Show limit</td><td><input id='limit' type='text' value='$ins_limit' style='width:100px;text-align:right;' onchange='getRqStr()'></td>
            </tr>
            <tr>
              <td><input type='checkbox' id='show_nth_toggle' checked onchange='getRqStr()'></td>
              <td>Show every</td><td><input id='nth' type='text' value='$ins_nth' style='width:100px;text-align:right;' onchange='getRqStr()'> <sup>th</sup> record</td>
            </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td><button onclick='show()'>Show records</button>&nbsp;&nbsp;<span id='csvlink'></span><span id='csvlink2'></span></td>
        </tr>
        </table>
      </td>
    </tr>
    </table>
    <div id='results'></div>
  </body>
</html>
TEXT;
}

function js(){
  global $thisname;
  global $init;

  if ($init) $insert = "show();";
  else       $insert = "";
  
  $help = help();
  
  return <<<TEXT
function init(){
  console.log("init");
  document.getElementById("results").innerHTML = $help;
  bindFilenameChangers();
  $insert
}

function show(){
  console.log("show");
  
  var rqstr = getRqStr();
  
  var request = new XMLHttpRequest();
  request.open('GET', rqstr+"&nogui", true);

  request.onreadystatechange = function() {
    if (this.readyState == 4 && this.status == 200) {      
      var resp = this.responseText;  
      clearInterval(loading_interval);
      document.getElementById("results").innerHTML = "<br/>"+resp;
    }
  };
  
  request.onerror = function() {
    // There was a connection error of some sort
  };
  
  loading_interval = setInterval(loading,500);
  request.send();
}

function getList(){

  var filename = document.getElementById("file").value;
  var rqstr = "$thisname?list&nogui&file="+filename;
  
  var request = new XMLHttpRequest();
  request.open('GET', rqstr, true);
  
  request.onreadystatechange = function() {
    if (this.readyState == 4 && this.status == 200) {      
      var resp = this.responseText;
      document.getElementById("results").innerHTML = "<br/><span style='font-size:1.0em'>"+resp+"</span>";
      bindFilenameChangers();
    }
  };
  
  request.onerror = function() {
    // There was a connection error of some sort
  };
  
  unbindFilenameChangers();
  request.send();
}

function getRqStr(){
  var filename = document.getElementById("file").value;
  
  var filter = 0;
  for(var i=0;i<10;i++){
    bit = (document.getElementById("filter_"+i).checked)?1:0;
    filter += (bit<<i);
  }
  
  var start = document.getElementById("start").value;
  var end = document.getElementById("end").value;
  
  var limit = "";
  
  if (document.getElementById("show_limit_toggle").checked){
    limit = "&limit="+document.getElementById("limit").value;
  }
  
  var nth = "";

  if (document.getElementById("show_nth_toggle").checked){
    nth = "&nth="+document.getElementById("nth").value;
  }
  
  var n = end - start;
  
  if (n<0) {
    console.log("Error: Begin > End");
    n = 1;
  }
  
  var rqstr = "$thisname?file="+filename+"&record="+start+"&nrecords="+n+"&filter="+filter+limit+nth;
  
  report("");
  setTimeout(function(){
    report("<a href='"+rqstr+"'>share</a>, Download: <a href='"+rqstr+"&nogui"+"&format=csv'>csv</a>, <a href='"+rqstr+"&nogui"+"&format=csv&showall'>full csv</a>");
  },100);
  
  return rqstr;
}

function bindFilenameChangers(){

  var elems = document.getElementsByClassName("filenamechanger");

  for(var i=0;i<elems.length;i++){
    elems[i].addEventListener("click", changeFilename);
  }  
}

function unbindFilenameChangers(){

  var elems = document.getElementsByClassName("filenamechanger");

  for(var i=0;i<elems.length;i++){
    elems[i].removeEventListener("click", changeFilename);
  }  
}

function changeFilename(){
  var file = this.innerHTML;
  var elem = document.getElementById("file");
  elem.value = file;

  ev = document.createEvent('Event');
  ev.initEvent('change', true, false);
  elem.dispatchEvent(ev);
}

function report(msg){
  document.getElementById("csvlink").innerHTML = msg;
}

var loading_interval;

function loading(){
  console.log("loading");
  var tmp = document.getElementById("csvlink2").innerHTML;
  if (tmp.length<2) tmp += ".";
  else              tmp = "";
  document.getElementById("csvlink2").innerHTML = tmp;
}

TEXT;
}

function help(){
  global $thisname;
  global $nogui;

  $logslist = showlist();
  
  $help = <<<TEXT
  <span style='font-size:1.2em;'>$logslist</span>
  <div style='font-size:1.2em;'>Source:
    <ul>
      <li><a href="?source" >This program source</a></li>
    </ul>
  </div>
  <div style='font-size:1.2em;'>Usage:
    <ul>
      <li>GUI:
        <ul>
          <li><b>Filename</b> - path to file:<br/>
          &nbsp;&nbsp;&nbsp;&nbsp;<i>remote != server address</i> - http://thisscriptrootpath/logs/filename<br/>
          &nbsp;&nbsp;&nbsp;&nbsp;<i>remote == server address</i> - any relative/absolute path
          </li>
          <li><b>Record filter checkboxes</b> - checked = show</li>
          <li><b>Begin</b> - offset, record index in log</li>
          <li><b>End</b> - offset, record index in log</li>
          <li><b>Show limit</b> - Number of filtered records to show, if enabled overrides <b>End</b></li>
          <li><button onclick='getList()'>List log files</button> :
            <ul>
              <li>remote access - list of files in <b>http://thisscriptrootpath/logs/</b></li>
              <li>local access - list of files in <b>http://thisscriptrootpath/</b></li>
            </ul>
            <button onclick='show()'>Show records</button> - after a file is selected.<br/>
            If a folder is selected - <button onclick='getList()'>List log files</button> again to scan inside.
          </li>
          <li><a href='#'>share</a> - open this page with the same parameters (auto <b>show records</b>)</li>
          <li><a href='#'>csv</a> - download csv: filter + limit</li>
          <li><a href='#'>csv full</a> - download csv: filter + no limit</li>
        </ul>
      </li>
      <br/>
      <li>URL (see <b>share</b>,<b>csv</b>,<b>csv full</b>):
        <ul>
          <li>
            <b>http://thisscriptrootpath/$thisname?file=..&record=..&nrecords=..&filter=..&limit=..&format=..</b><br/>
            &nbsp;&nbsp;<b>file</b> - with path<br/>
            &nbsp;&nbsp;<b>format</b> - accepts 'csv' or 'html'<br/>
            &nbsp;&nbsp;<b>limit</b> - limit the displayed records<br/>
            &nbsp;&nbsp;<b>record</b> - starting record index, default = 0<br/>
            &nbsp;&nbsp;<b>nrecords</b> - number of records to parse, default = 5000<br/>
            &nbsp;&nbsp;<b>filter</b> - filter out types of displayed records:<br/>
            &nbsp;&nbsp;&nbsp;&nbsp;0x200 - display external trigger records only<br/>
            &nbsp;&nbsp;&nbsp;&nbsp;0x100 - display image records, channel 3 only<br/>
            &nbsp;&nbsp;&nbsp;&nbsp;0x080 - display image records, channel 2 only<br/>
            &nbsp;&nbsp;&nbsp;&nbsp;0x040 - display image records, channel 1 only<br/>
            &nbsp;&nbsp;&nbsp;&nbsp;0x020 - display image records, channel 0 only<br/>
            &nbsp;&nbsp;&nbsp;&nbsp;0x010 - display imu records only<br/>
            &nbsp;&nbsp;for gps records:<br/>
            &nbsp;&nbsp;&nbsp;&nbsp;0x008 - display NMEA GPVTG records,<br/>
            &nbsp;&nbsp;&nbsp;&nbsp;0x004 - display NMEA GPGSA records<br/>
            &nbsp;&nbsp;&nbsp;&nbsp;0x002 - display NMEA GPGGA records (have coordinates)<br/>
            &nbsp;&nbsp;&nbsp;&nbsp;0x001 - display NMEA GPRMC records (have coordinates)<br/>            
            &nbsp;&nbsp;default = 0x7f (display everything)<br/>
          </li>
        </ul>
      </li>
      <li>Command line:
        <ul>
          <li><i>~$ php $thisname logfile [filter] > output.csv</i></li>
          <li>cli help message: <i>~$ php $thisname</i></li>
        </ul>
      </li>
    </ul>
    </div>
    <br/><br/><br/>
TEXT;

  if (!$nogui) $help = "`<br/>$help`";
  
  return $help;
}

?>

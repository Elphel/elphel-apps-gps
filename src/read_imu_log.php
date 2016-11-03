<?php
if (!isset($_GET['file'])){ 
  echo "<pre>\n";
  echo "?file=log_file_name&record=record_number&nrecords=number_of_records&filter=filter";
  echo "</pre>\n";
  exit (0);
}

//$timeShift = 21600; // 6 hrs
$timeShift = 0; // 6 hrs

$file=$_GET['file'];
$numRecordsInFile=filesize($file)/64;
$record=0;
$nRecords=1;
/*
 1 - NMEA sentence 0 $GPRMC (log type 1)
 2 - NMEA sentence 1 $GPGGA (log type 1)
 4 - NMEA sentence 2 $GPGSA (log type 1)
 8 - NMEA sentence 3 $GPVTG (log type 1)
16 - IMU (type 0)
32 - SYNC (type 2)
64 - ODOMETER (type 3)

*/
$filter=0x7f;

$type=-1;
$gpsType=-1;
if (isset($_GET['record']))   $record=    $_GET['record']+0;
if (isset($_GET['nrecords'])) $nRecords=  $_GET['nrecords']+0;
if (isset($_GET['filter']))   $filter=    $_GET['filter']+0;

if ($record>($numRecordsInFile-1)) $record=$numRecordsInFile-1;
if ($nRecords>($numRecordsInFile-$record)) $nRecords= $numRecordsInFile-$record;

  echo "<pre>\n";
  echo "Number of samples in file=$numRecordsInFile\n";
  echo "</pre>\n";


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

imuLogParse($log_file,$record,$nRecords,$filter);
exit(0);

function imuLogParse($handle,$record,$nSamples,$filter,$tryNumber=10000){

  global $timeShift;

  global $averageIMU;
  $showAll=($nSamples<=100000);
  $gpsFilter= $filter&0xf;
  $typeFilter=(($gpsFilter!=0)?2:0) | ((($filter&0x10)!=0)?1:0) | ((($filter&0x20)!=0)?4:0) | ((($filter&0x20)!=0)?8:0);
  fseek($handle,64*$record,SEEK_SET);
  echo "<pre>\n";
//  echo "filter=$filter, gpsFilter=$gpsFilter, typeFilter=$typeFilter, record=$record, nSamples=$nSamples\n";
  for ($nSample=0;$nSample<$nSamples;$nSample++) {
    $sample=fread($handle,64);
    $arr32=unpack('L*',$sample);
    $type=$arr32[1]>>24;
    $gps=($type==1)?($arr32[3]&0x3):0xf; // any if it is not GPS sample
    if ((((1<<$type) & $typeFilter)!=0) && (($type!=1)|| (((1<<$gps) & $gpsFilter)!=0))) {
      //$arr8=unpack('C*',$sample);
      $time=(($arr32[1]&0xfffff)/1000000)+$arr32[2];
  if ($showAll) printf("%8d %f: ",($record+$nSample),$time);
      switch ($type) {
	// IMU record
        case 0:
//          print_r($arr32);
//           if ($showAll) {
//             for ($i=2;$i<16;$i++) {
//               printf(" %9d",$arr32[$i+1]);
//             }
//             echo "\n";
//              printf("%8d %f: ",($record+$nSample),$time);
//             for ($i=2;$i<16;$i++) {
//               printf(" %9x",0xffffffff&$arr32[$i+1]);
//             }
//             echo "\n";
//           }
          $imuSample=parseIMU($arr32);
          
	  //if ($showAll) print_r($imuSample);
	  if ($showAll) echo " [angleX]=>".$imuSample["angleX"]."     [angleY]=>".$imuSample["angleY"]."     [angleZ]=>".$imuSample["angleZ"].
                             "     [gyroX] =>".$imuSample["gyroX"] ."      [gyroY]=>".$imuSample["gyroY"] ."      [gyroZ]=>".$imuSample["gyroZ"].
                             "     [accelX] =>".$imuSample["accelX"] ."      [accelY]=>".$imuSample["accelY"] ."      [accelZ]=>".$imuSample["accelZ"].
                             "     [velocityX] =>".$imuSample["velocityX"] ."      [velocityY]=>".$imuSample["velocityY"] ."      [velocityZ]=>".$imuSample["velocityZ"].
                             "     [temperature]=>".$imuSample["temperature"]."\n";

          $averageIMU["number"]++;
          foreach ($imuSample as $key=>$value) $averageIMU[$key]+=$value;

	  $avg_x = $averageIMU["angleX"]/$averageIMU["number"];
	  $avg_y = $averageIMU["angleY"]/$averageIMU["number"];
	  $avg_z = $averageIMU["angleZ"]/$averageIMU["number"];

	  //echo $avg_x." ".$avg_y." ".$avg_z."\n";
	  

          break;
	// GPS record
        case 1:
          $nmeaArray=parseGPS($sample);
          $nmeaString='$';
          for ($i=0; $i<count($nmeaArray);$i++) {
            $nmeaString.=$nmeaArray[$i];
            if ($i < (count($nmeaArray)-1))$nmeaString.=',';
          }
          echo "GPS (NMEA): ".$nmeaString."\n";
          break;
	// Master (Sync) record
        case 2:
          $masterTime=(($arr32[3]&0xfffff)/1000000)+$arr32[4];
          $subchannel = ($arr32[3] >> 24);
          echo "Subchannel: <b>".$subchannel."</b> MasterTimeStamp: <b>".($masterTime+$timeShift)."</b> TimeStamp: <b>".($time+$timeShift)."</b>   MasterTimeStamp(precise): 0x".dechex($arr32[4])."+".dechex($arr32[3])." TimeStamp(precise): 0x".dechex($arr32[2])."+".dechex($arr32[1])." $masterTime - ".gmdate(DATE_RFC850,$masterTime)." [local timestamp - ".gmdate(DATE_RFC850,$time)."]\n";
          break;
	// Show hex data
        case 3: print_r($arr32);
          break;
      }
    }
  }
  if ($averageIMU['number']>0) {
		foreach ( $averageIMU as $key => $value )
			if ($key != 'number')
				$averageIMU [$key] /= $averageIMU ['number'];
		print_r ( $averageIMU );
		echo "</pre>\n";
		echo "<table>\n";
		foreach ( $averageIMU as $key => $value )
			echo "<tr><td>$key</td><td>$value</td></tr>\n";
		echo "</table>\n";
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
//echo "gps=$gps\n";
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
function parseNMEA($sent,$data){
/*
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


*/
}
function parseIMU ($arr32){
  $gyroScale=0.013108/65536;       // deg/sec
  $accelScale=0.8192/65536;       // mg
  $angleScale= 0.005493/65536;    //degrees
  $velocityScale=0.0030518/65536; //m/sec
//  $angleScale= 0.005493;    //degrees
//  $velocityScale=0.0030518; //m/sec
  $temperatureScale=0.00565; // C/LSB
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
//    "temperature"=>$t
// date/time
  );
}
?>

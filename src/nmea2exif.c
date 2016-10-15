/*!***************************************************************************
*! FILE NAME  : nmea2exif.c
*! DESCRIPTION: converts GPS NMEA output (just $GPRMC, $GPGGA) to Exif 
*!              in Elphel cameras 
*! Copyright (C) 2009 Elphel, Inc.
*! -----------------------------------------------------------------------------**
*!  This program is free software: you can redistribute it and/or modify
*!  it under the terms of the GNU General Public License as published by
*!  the Free Software Foundation, either version 3 of the License, or
*!  (at your option) any later version.
*!
*!  This program is distributed in the hope that it will be useful,
*!  but WITHOUT ANY WARRANTY; without even the implied warranty of
*!  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*!  GNU General Public License for more details.
*!
*!  You should have received a copy of the GNU General Public License
*!  along with this program.  If not, see <http://www.gnu.org/licenses/>.
*! -----------------------------------------------------------------------------**
*!  $Log: nmea2exif.c,v $
*!  Revision 1.4  2011/01/15 23:22:46  elphel
*!  fixed reporting fraction of second in GPS timestamp
*!
*!  Revision 1.3  2009/02/19 09:09:30  elphel
*!  Bug fix in GPS seconds
*!
*!  Revision 1.2  2009/02/19 09:02:39  elphel
*!  added GPGGA
*!
*!  Revision 1.1  2009/02/19 08:24:07  elphel
*!  added nmea2exif that processes NMEA $GPRMC sentences and encodes them in Exif headers
*!
*!
*/
#include <sys/types.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <stdio.h>
#include <unistd.h>
#include <stdlib.h>
#include <string.h>
#include <termios.h>
#include <elphel/exifa.h>
#include <asm/byteorder.h> //endians
#include <elphel/x393_devices.h>
#include <elphel/c313a.h>

//#include "exifgen.h"

//#define EXIF_DEV "/dev/exif_meta"
#define PROGRAM_SERIAL 0
//#define D(x)
#define D(x) x

// TODO: use only existing sensor channels?
const char *exif_paths[SENSOR_PORTS] = {
        DEV393_PATH(DEV393_EXIF_META0),
        DEV393_PATH(DEV393_EXIF_META1),
        DEV393_PATH(DEV393_EXIF_META2),
        DEV393_PATH(DEV393_EXIF_META3)};




void exif_init_meta(struct meta_GPSInfo_t *meta) {
   meta->GPSLatitudeRef=       'N';
   meta->GPSLatitude_deg_nom=   __cpu_to_be32((int) 0);
   meta->GPSLatitude_deg_denom= __cpu_to_be32((int) 1);
   meta->GPSLatitude_min_nom=   __cpu_to_be32((int) 0);
   meta->GPSLatitude_min_denom= __cpu_to_be32((int) EXIF_GPS_MIN_DENOM);
   meta->GPSLongitudeRef=      'W';
   meta->GPSLongitude_deg_nom=  __cpu_to_be32((int) 0);
   meta->GPSLongitude_deg_denom=__cpu_to_be32((int) 1);
   meta->GPSLongitude_min_nom=  __cpu_to_be32((int) 0);
   meta->GPSLongitude_min_denom=__cpu_to_be32((int) EXIF_GPS_MIN_DENOM);
   meta->GPSAltitudeRef=        0; //byte, not ascii 0 - above sea level, 1 - below
   meta->GPSAltitude_nom=       __cpu_to_be32((int) 0);   //in meters
   meta->GPSAltitude_denom=     __cpu_to_be32((int) EXIF_GPS_METERS_DENOM);
   meta->GPSTimeStamp_hrs_nom=  __cpu_to_be32((int) 0);
   meta->GPSTimeStamp_hrs_denom=__cpu_to_be32((int) 1);
   meta->GPSTimeStamp_min_nom=  __cpu_to_be32((int) 0);
   meta->GPSTimeStamp_min_denom=__cpu_to_be32((int) 1);
   meta->GPSTimeStamp_sec_nom=  __cpu_to_be32((int) 0);
   meta->GPSMeasureMode=        '0';
   meta->GPSTimeStamp_sec_denom=__cpu_to_be32((int) EXIF_GPS_TIMESEC_DENOM);
// meta->GPSDateStamp[10]; //1 less than defined - no '\0';

}
/*
eg2. $GPRMC,225446,A,4916.45,N,12311.12,W,000.5,054.7,191194,020.3,E*68
           225446       Time of fix 22:54:46 UTC
           A            Navigation receiver warning A = OK, V = warning
           4916.45,N    Latitude 49 deg. 16.45 min North
           12311.12,W   Longitude 123 deg. 11.12 min West
           000.5        Speed over ground, Knots
           054.7        Course Made Good, True
           191194       Date of fix  19 November 1994
           020.3,E      Magnetic variation 20.3 deg East
           *68          mandatory checksum
*/
void process_GPRMC(char*response, struct meta_GPSInfo_t *meta) {
   char * cp;
   int h, m, s, isOK,idg,imn,mm,dd,yy;
   double lld;
   double ds;
               cp=strchr(response,',');
/// Read UTC time hhmmss
               if (cp) {
                  cp++;  /// first charater in time
//                  s=strtol(cp, &cp,10); ///cp now "," after time s=hhmmss
                  ds=strtod(cp, &cp); ///cp now "," after time s=hhmmss.sss
                  s=(int) ds;
                  ds-=s;
                  h=s/10000; s-=h*10000;
                  m=s/100; s-=m*100;
                  ds+=s;
//               D(printf ("%02d:%02d:%02d\n",h,m,s));
//               D(printf ("%02d:%02d:%05d (%f)\n",h,m,(int) (ds*EXIF_GPS_TIMESEC_DENOM+0.5), ds));
                  meta->GPSTimeStamp_hrs_nom=  __cpu_to_be32((int) h);
                  meta->GPSTimeStamp_min_nom=  __cpu_to_be32((int) m);
                  meta->GPSTimeStamp_sec_nom=  __cpu_to_be32((int) (ds*EXIF_GPS_TIMESEC_DENOM+0.5));
                  cp=strchr(cp,',');
               }
///Read is OK use as fix/no fix?
               isOK=0;
               if (cp) {
                  cp++;  /// first charater
                  isOK=(cp[0]=='A')?1:0;
                  meta->GPSMeasureMode= (isOK)?'3':'2'; /// '0' is not allowed
                  cp=strchr(cp,',');
               }
/// Read latitude
               if (cp) {
                  cp++;  /// first character
                  lld=strtod(cp, &cp); ///cp now ","  dddmm.mmmmm
                  idg=lld/100; 
                  lld-=100*idg;
                  imn=lld*EXIF_GPS_MIN_DENOM+0.5;
                  meta->GPSLatitude_deg_nom=   __cpu_to_be32(idg);
                  meta->GPSLatitude_min_nom=   __cpu_to_be32(imn);
                  cp=strchr(cp,',');
               }
/// Read latitude reference (N/S)
               if (cp) {
                  cp++;  /// first character
                  if (cp[0]=='S') meta->GPSLatitudeRef='S';
                  else            meta->GPSLatitudeRef='N';
                  cp=strchr(cp,',');
               }
/// Read longitude
               if (cp) {
                  cp++;  /// first character
                  lld=strtod(cp, &cp); ///cp now ","  dddmm.mmmmm
                  idg=lld/100; 
                  lld-=100*idg;
                  imn=lld*EXIF_GPS_MIN_DENOM+0.5;
                  meta->GPSLongitude_deg_nom=   __cpu_to_be32(idg);
                  meta->GPSLongitude_min_nom=   __cpu_to_be32(imn);
                  cp=strchr(cp,',');
               }
/// Read longitude reference (E/W)
               if (cp) {
                  cp++;  /// first character
                  if (cp[0]=='W') meta->GPSLongitudeRef='W';
                  else            meta->GPSLongitudeRef='E';
                  cp=strchr(cp,',');
               }
/// Skip Speed, Course, 
               if (cp) {
                  cp++;  /// first character
                  cp=strchr(cp,',');
               }
               if (cp) {
                  cp++;  /// first character
                  cp=strchr(cp,',');
               }
/// Read date DDMMYY
               if (cp) {
                  cp++;  /// first character in time
                  yy=strtol(cp, &cp,10); ///cp now ","yy=DDMMYY
                  dd=yy/10000; yy-=dd*10000;
                  mm=yy/100; yy-=mm*100;
                  sprintf((char *) (meta->GPSDateStamp), "20%02d:%02d:%02d", yy, mm,dd);
                  cp=strchr(cp,',');
               }
/// skip the rest - magnetic variation and it's direction (E/W)
}

/*
$GPGGA

Global Positioning System Fix Data

Name 	Example Data 	Description
Sentence Identifier 	$GPGGA 	Global Positioning System Fix Data
Time 	170834 	17:08:34 Z
Latitude 	4124.8963, N 	41d 24.8963' N or 41d 24' 54" N
Longitude 	08151.6838, W 	81d 51.6838' W or 81d 51' 41" W
Fix Quality:
- 0 = Invalid
- 1 = GPS fix
- 2 = DGPS fix 	1 	Data is from a GPS fix
Number of Satellites 	05 	5 Satellites are in view
Horizontal Dilution of Precision (HDOP) 	1.5 	Relative accuracy of horizontal position
Altitude 	280.2, M 	280.2 meters above mean sea level
Height of geoid above WGS84 ellipsoid 	-34.0, M 	-34.0 meters
Time since last DGPS update 	blank 	No last update
DGPS reference station id 	blank 	No station id
Checksum 	*75 	Used by program to check for transmission errors
---
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



*/
void process_GPGGA(char*response, struct meta_GPSInfo_t *meta) {
   char * cp;
   int h, m, s, isOK,idg,imn;
   double lld;
   double ds;

               cp=strchr(response,',');
/// Read UTC time hhmmss
               if (cp) {
                  cp++;  /// first character in time
//                  s=strtol(cp, &cp,10); ///cp now "," after time s=hhmmss
                  ds=strtod(cp, &cp); ///cp now "," after time s=hhmmss.sss
                  s=(int) ds;
                  ds-=s;
                  h=s/10000; s-=h*10000;
                  m=s/100; s-=m*100;
                  ds+=s;
                  meta->GPSTimeStamp_hrs_nom=  __cpu_to_be32((int) h);
                  meta->GPSTimeStamp_min_nom=  __cpu_to_be32((int) m);
                  meta->GPSTimeStamp_sec_nom=  __cpu_to_be32((int) (ds*EXIF_GPS_TIMESEC_DENOM+0.5));
                  cp=strchr(cp,',');
               }
/// Read latitude
               if (cp) {
                  cp++;  /// first character
                  lld=strtod(cp, &cp); ///cp now ","  dddmm.mmmmm
                  idg=lld/100; 
                  lld-=100*idg;
                  imn=lld*EXIF_GPS_MIN_DENOM+0.5;
                  meta->GPSLatitude_deg_nom=   __cpu_to_be32(idg);
                  meta->GPSLatitude_min_nom=   __cpu_to_be32(imn);
                  cp=strchr(cp,',');
               }
/// Read latitude reference (N/S)
               if (cp) {
                  cp++;  /// first character
                  if (cp[0]=='S') meta->GPSLatitudeRef='S';
                  else            meta->GPSLatitudeRef='N';
                  cp=strchr(cp,',');
               }
/// Read longitude
               if (cp) {
                  cp++;  /// first character
                  lld=strtod(cp, &cp); ///cp now ","  dddmm.mmmmm
                  idg=lld/100; 
                  lld-=100*idg;
                  imn=lld*EXIF_GPS_MIN_DENOM+0.5;
                  meta->GPSLongitude_deg_nom=   __cpu_to_be32(idg);
                  meta->GPSLongitude_min_nom=   __cpu_to_be32(imn);
                  cp=strchr(cp,',');
               }
/// Read longitude reference (E/W)
               if (cp) {
                  cp++;  /// first character
                  if (cp[0]=='W') meta->GPSLongitudeRef='W';
                  else            meta->GPSLongitudeRef='E';
                  cp=strchr(cp,',');
               }
///6    = GPS quality indicator (0=invalid; 1=GPS fix; 2=Diff. GPS fix)
               isOK=0;
               if (cp) {
                  cp++;  /// first character
                  isOK=(cp[0]=='0')?0:1;
                  meta->GPSMeasureMode= (isOK)?'3':'2'; /// '0' is not allowed
                  cp=strchr(cp,',');
               }
/*
7    = Number of satellites in use [not those in view]
8    = Horizontal dilution of position
9    = Antenna altitude above/below mean sea level (geoid)

*/
/// Skip 7  = Number of satellites in use [not those in view]
               if (cp) {
                  cp++;  /// first character
                  cp=strchr(cp,',');
               }
/// Skip 8    = Horizontal dilution of position
               if (cp) {
                  cp++;  /// first character
                  cp=strchr(cp,',');
               }

/// Read 9    = Antenna altitude above/below mean sea level (geoid)
               if (cp) {
                  cp++;  /// first character
                  idg=EXIF_GPS_METERS_DENOM*strtod(cp, &cp)+0.5; ///cp now ","  +/-mm.mmmmm
                  if (idg <0) {
                    idg=-idg;
                    meta->GPSAltitudeRef=1;
                  } else {
                    meta->GPSAltitudeRef=0;
                  }
                  meta->GPSAltitude_nom=       __cpu_to_be32(idg);   /// in meters
                  cp=strchr(cp,',');
               }
/// Skip 10   = Meters  (Antenna height unit) 'M'
               if (cp) {
                  cp++;  /// first character
                  cp=strchr(cp,',');
               }
/// Skip 11   = Geoidal separation (Diff. between WGS-84 earth ellipsoid and mean sea level.  -=geoid is below WGS-84 ellipsoid)
               if (cp) {
                  cp++;  /// first character
                  cp=strchr(cp,',');
               }
/// Skip 12   = Meters  (Antenna height unit) 'M'
               if (cp) {
                  cp++;  /// first character
                  cp=strchr(cp,',');
               }
/// skip the rest:
/// Skip 13   = Age in seconds since last update from diff. reference station
/// Skip 14   = Diff. reference station ID#
}





int main(int argc, char *argv[]) {
   FILE *fgps;
   int i,chn, good_chns;
   int fd_exif[SENSOR_PORTS];
   char response[256];
   int  response_len;
   char * cp, *cp_cs;
   unsigned char cs_calc, cs_read;
   struct meta_GPSInfo_t meta;
#if PROGRAM_SERIAL
   int fd_gps;
   struct termios termio;
#endif
   exif_init_meta(&meta);
   if (argc < 2) {
      printf("Usage: %s GPS_device\n", argv[0]);
     exit (-1);
   }
   good_chns=0;
   for (chn =0; chn < SENSOR_PORTS; chn++){
       if (exif_paths[chn]) { // make them null if inactive
           fd_exif[chn] = open(exif_paths[chn], O_RDWR);
           if (fd_exif[chn] < 0) {
               fprintf(stderr,"Can not open device file %s\n",exif_paths[chn]);
           }
       } else good_chns++;
   }
   if (!good_chns){
       fprintf(stderr,"Could not open any of EXIF channel device files, aborting\n");
       exit (-1);
   }
#if PROGRAM_SERIAL
   fd_gps = open(argv[1], O_RDWR);
   if (fd_gps == -1) {
      perror("Cannot open gps device");
      for (chn =0; chn < SENSOR_PORTS; chn++) if (fd_exif[chn]>=0){
          close(fd_exif[chn]);
      }
     exit (-1);
   }
   tcgetattr(fd_gps, &termio);
///   exec ("stty -F $ser_port onlcr -echo speed 19200");
//int cfsetispeed(struct termios *termios_p, speed_t speed);
//int cfsetospeed(struct termios *termios_p, speed_t speed);
//B19200
///TODO: use xml configuration file for different devices. Or just move it out of this program?
   cfsetospeed(&termio, B19200);
   tcsetattr(fd_gps, TCIOFLUSH, &termio);
#endif

#if PROGRAM_SERIAL
   fgps=fopen(argv[1], "r+"); // probably "r" is enough - we do not want to control it here
#else
   fgps=fopen(argv[1], "r");
#endif
   if (!fgps) {
      perror("Cannot open gps device!");
     exit (-1);
   }

   
   while (1) {
     fgets(response,sizeof(response),fgps);
     if ((cp=strchr(response,'\n'))) cp[0]='\0';
     if ((cp=strchr(response,'\r'))) cp[0]='\0';
//     D(printf("response: >>%s<<\n",response));
///verify NMEA $...*HH
     response_len=strlen(response);
     if (response_len>0) {
       if (response_len>4) {
         if ((cp_cs=strchr(response,'*'))) {
           cp_cs[0]='\0';
           cp_cs++;
           cs_read= strtol(cp_cs, NULL, 16);
           cs_calc=0;
           for (i=1; response[i]; i++) cs_calc^=response[i];
//   unsigned char cs_calc, cs_read;
           if (cs_calc==cs_read ) {
//             D(printf ("Got NMEA string >>%s<<\n",response+1));
/// Is it $GPRMC string?
//             if (!strncmp(response+1,"GPRMC", sizeof("GPRMC"))) {
             if (!memcmp(response+1,"GPRMC", sizeof("GPRMC")-1)) {
//               D(printf ("Got GPRMC\n"));
               D(printf ("Got NMEA string >>%s<<\n",response+1));
               process_GPRMC(response, &meta);
               for (chn =0; chn < SENSOR_PORTS; chn++) if (fd_exif[chn]>=0){
                   lseek (fd_exif[chn],Exif_GPSInfo_GPSLatitudeRef,SEEK_END); /// position file pointer at the beginning of the data field for GPSLatitudeRef
                   write (fd_exif[chn], &meta, sizeof(meta));
               }
             } else if (!memcmp(response+1,"GPGGA", sizeof("GPGGA")-1)) {
//               D(printf ("Got GPRMC\n"));
               D(printf ("Got NMEA string >>%s<<\n",response+1));
               process_GPGGA(response, &meta);
               for (chn =0; chn < SENSOR_PORTS; chn++) if (fd_exif[chn]>=0){
                   lseek (fd_exif[chn],Exif_GPSInfo_GPSLatitudeRef,SEEK_END); /// position file pointer at the beginning of the data field for GPSLatitudeRef
                   write (fd_exif[chn], &meta, sizeof(meta));
               }

             } else {
//               D(printf ("Got other NMEA string >>%s<<\n",response+1));
             }
           } else D(printf("Checksum mismatch: read 0x%x, calculated 0x%x\n",(int)cs_read,(int)cs_calc));
         } else D(printf("No '*' in response, ignoring\n"));
      } else D(printf("response to short (%d), ignoring\n",response_len));
    }
   }
return 0;
}

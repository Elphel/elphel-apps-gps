/*
exifgen.c
   Garmin protocol to Exif GPS data conevrter
   exif_getutc() function is based on nmea_getutc(), found in
   nmeagen.c:
	Garmin protocol to NMEA 0183 converter
	Copyright (C) 2004 Manuel Kasper <mk@neon1.net>.
	All rights reserved.

*/

#include "garmin.h"
#include "nmeagen.h"
#include "exifgen.h"

#include <math.h>
#include <stdio.h>
#include <string.h>

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

void exif_getutc(D800_Pvt_Data_Type *pvt,  struct meta_GPSInfo_t *meta) { // char *utctime, char *utcdate) {
   int      tmp = 0, dtmp=0;
   int      frac_sec;
   /* UTC time of position fix 
      Reminder:
         pvt->tow = seconds (including fractions) since the start of the week.
         pvt->wn_days = days since 31-DEC-1989 for the start of the current week
           (neither is adjusted for leap seconds)
         pvt->leap_scnds = leap second adjustment required.
   */
   tmp = (pvt->tow + 0.5/EXIF_GPS_TIMESEC_DENOM);
   frac_sec = ((pvt->tow + 0.5/EXIF_GPS_TIMESEC_DENOM) - tmp)*EXIF_GPS_TIMESEC_DENOM +0.5;

   dtmp = pvt->wn_days;

   /* 
      If the result is 604800, it's really the first sample
      of the new week, so zero out tmp and increment dtmp 
      by a week ( 7 days ).
   */
   if (tmp >= 604800)   
   {
      dtmp += 7;
      tmp = 0;
   }
   /*
      At this point we have tmp = seconds since the start
      of the week, and dtmp = the first day of the week.
      We now need to correct for leap seconds.  This may actually
      result in reversing the previous adjustment but the code
      required to combine the two operations wouldn't be clear.
   */
   tmp -= pvt->leap_scnds;
   if (tmp < 0)
   {
      tmp+= 604800;
      dtmp -= 7;
   }

   /*
      Now we have tmp = seconds since the start if the week, 
      and dtmp = the first day of the week, all corrected for
      rounding and leap seconds.

      We now convert dtmp to today's day number and tmp to
      seconds since midnignt. 
   */

   dtmp += (tmp / 86400);
   tmp %= 86400;
// calculate time
   int h, m, s;
   h = tmp / 3600;
   m = (tmp - h*3600) / 60;
   s = ((tmp - h*3600 - m*60) * EXIF_GPS_TIMESEC_DENOM)+frac_sec;

   meta->GPSTimeStamp_hrs_nom=  __cpu_to_be32((int) h);
   meta->GPSTimeStamp_min_nom=  __cpu_to_be32((int) m);
   meta->GPSTimeStamp_sec_nom=  __cpu_to_be32((int) s);

      /* Garmin format: number of days since December 31, 1989 */
   unsigned long jd = dtmp + 2447892;
   unsigned long w, x, a, b, c, d, e, f;
   unsigned long day, month, year;

   w = (unsigned long)((jd - 1867216.25)/36524.25);
   x = w/4;
   a = jd + 1 + w - x;
   b = a + 1524;
   c = (unsigned long)((b - 122.1)/365.25);
   d = (unsigned long)(365.25 * c);
   e = (unsigned long)((b-d)/30.6001);
   f = (unsigned long)(30.6001 * e);

   day = b - d - f;
   month = e - 1;
   if (month > 12)                month -= 12;
   year = c - 4716;
   if (month == 1 || month == 2)  year++;
//   year -= 2000;
   sprintf(meta->GPSDateStamp, "%04ld:%02ld:%02ld", year, month,day);
}


int exif_gen(D800_Pvt_Data_Type *pvt, cpo_sat_data *sat, struct meta_GPSInfo_t *meta) {
   int deg,m_scaled;
   exif_getutc( pvt, meta);
   meta->GPSMeasureMode= (pvt->fix >= 4)?'3':'2'; // '0' is not allowed
   /// latitude degrees, minutes/10000
   m_scaled=rad2deg(pvt->lat)*60*EXIF_GPS_MIN_DENOM +0.5;
   meta->GPSLatitudeRef= (m_scaled >=0)?'N':'S';
   if (m_scaled<0) m_scaled=-m_scaled;
   deg     = m_scaled/(60*EXIF_GPS_MIN_DENOM);
   m_scaled -= deg*(60*EXIF_GPS_MIN_DENOM);
   meta->GPSLatitude_deg_nom=   __cpu_to_be32(deg);
   meta->GPSLatitude_min_nom=   __cpu_to_be32(m_scaled);
   /// longitude degrees, minutes/10000
   m_scaled=rad2deg(pvt->lon)*60*EXIF_GPS_MIN_DENOM +0.5;
   meta->GPSLongitudeRef= (m_scaled >=0)?'E':'W';
   if (m_scaled<0) m_scaled=-m_scaled;
   deg     = m_scaled/(60*EXIF_GPS_MIN_DENOM);
   m_scaled -= deg*(60*EXIF_GPS_MIN_DENOM);
   meta->GPSLongitude_deg_nom=   __cpu_to_be32(deg);
   meta->GPSLongitude_min_nom=   __cpu_to_be32(m_scaled);
   ///altitude
   m_scaled= (pvt->msl_hght + pvt->alt)*EXIF_GPS_METERS_DENOM +0.5;
   meta->GPSAltitudeRef= (m_scaled >=0)? 0 : 1;
   if (m_scaled<0) m_scaled=-m_scaled;
   meta->GPSAltitude_nom=       __cpu_to_be32(m_scaled);   //in meters
   return 0;
}


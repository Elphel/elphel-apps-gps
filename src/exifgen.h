/*
exifgen.h
*/
#ifndef EXIFGEN_H
#define EXIFGEN_H
//#include <asm/elphel/exifa.h>
#include <elphel/exifa.h>
#include <asm/byteorder.h> //endians

#include "garmin.h"

#define KNOTS_TO_KMH	1.852
#define G_PI			3.14159265358979324
#define rad2deg(x)		((x) * 180.0 / G_PI)
#define EXIF_GPS_MIN_DENOM     10000 //4 digits after decimal point
#define EXIF_GPS_METERS_DENOM     10 //1 digit after decimal point
#define EXIF_GPS_TIMESEC_DENOM  1000 //3 digits after decimal point
void exif_init_meta(struct meta_GPSInfo_t *meta);
int exif_gen(D800_Pvt_Data_Type *pvt, cpo_sat_data *sat, struct meta_GPSInfo_t *meta);

#endif

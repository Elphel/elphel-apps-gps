/*!***************************************************************************
*! FILE NAME  : log_imu.c
*! DESCRIPTION: Read IMU data, copy to stdout
*! Copyright (C) 2011 Elphel, Inc.
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
*!  $Log: log_imu.c,v $
*!  Revision 1.4  2012/04/14 05:51:19  elphel
*!  lseek->lseek64
*!
*!  Revision 1.3  2012/04/14 03:53:09  elphel
*!  Added parameter to specify how much ahead of the current write pointer to open /dev/imu
*!
*!  Revision 1.2  2012/04/13 00:49:59  dzhimiev
*!  1. added 'starting index' to logger
*!
*!  Revision 1.1  2012/04/12 00:18:38  elphel
*!  simple log program for IMU/GPS/camera output
*!
*/
#define _LARGEFILE64_SOURCE
#define _FILE_OFFSET_BITS 64
#include <sys/types.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <stdio.h>
#include <unistd.h>
#include <stdlib.h>
#include <string.h>
#include <termios.h>
#include <errno.h>
#include <elphel/exifa.h>
#include <signal.h>
#include <asm/byteorder.h> //endians

//#include <elphel/c313a.h>
#include <elphel/x393_devices.h>


//#include "exifgen.h"

//#define EXIF_DEV "/dev/imu"
#define D(x) x
int fd_imu=-1;
int fd_out=-1;
char outFileName [256];

const char dev_path[]=DEV393_PATH(DEV393_LOGGER);

void signalHandler (int signum){
  printf ("Received signal %d\n",signum);
  if (fd_imu>=0) {
     printf ("Closing %s\n",dev_path);
     close (fd_imu);
     fd_imu=-1;
  }
  if (fd_out>=0) {
     printf ("Closing output file %s\n",outFileName);
     close (fd_out);
     fd_out=-1;
  }
  exit(0);
}

int main(int argc, char *argv[]) {
  const char usage[]=   "Usage:\n%s outfile [start_file_index [number_of_records_per_file] [rewind_by_samples]]\n\n"
                        "Copy IMU data to the output files, start new file after i/o error or specified number_of_records_per_file (64 bytes each)\n"
                        "rewind_by_samples - start reading specified number of samples earlier than the last recorded (~2500 samples/sec,\n"
                        " each sample size is 64-byte, rewind is limited by the 1MB buffer).\n"
                        "Five-digit file number will be added to the specified output file name.\n\n";

   int numFile=1;
   int rewindSamples=2500; //~1 sec back
   int buf_records=64;
   char buffer[buf_records*64];
   if (argc < 2) {
     printf (usage,argv[0]);
     return 0;
   }
   int numRecords=1000000; // 1 million records (64MB, ~400 seconds)
   if (argc >2) numFile=       strtol(argv[2], NULL, 10);   
   if (argc >3) numRecords=    strtol(argv[3], NULL, 10);
   if (argc >4) rewindSamples= strtol(argv[4], NULL, 10);
   strncpy(outFileName,argv[1],249);
   outFileName[249]='\0';
   char * suffixP=&outFileName[strlen(outFileName)];
   int readRecords;
   off_t position;
   signal (SIGINT,signalHandler);
   signal (SIGHUP,signalHandler);
   signal (SIGTERM,signalHandler);
   while (1) {
      if ((fd_imu = open(dev_path, O_RDONLY))<0)  {printf("error opening %s\n",dev_path); return -1;}
      position= lseek64(fd_imu,0,SEEK_CUR);
      if (position == (off_t) -1) {
        printf("Error in lseek %s, returned %d, errno=%d - %s, position=0x%llx\n",dev_path,(int) position,errno,strerror(errno), (long long) position);
      } else {
        printf("Opened %s at position 0x%llx\n",dev_path, (long long) position);
        int thisRewind=(position<(64*rewindSamples))?position:(64*rewindSamples);
        position= lseek64(fd_imu,-thisRewind,SEEK_CUR);
        printf("Rewind back by %d to 0x%llx\r\n",thisRewind, (long long) position);
      }
      int imu_OK=1;
      while (imu_OK) { // will break on error
         sprintf(suffixP,"-%05d",numFile);
         fd_out=open(outFileName,O_WRONLY | O_CREAT, 0777);
         if (fd_out<0)  {printf("error opening %s for writing\n",outFileName); return -1;}
         int records_left;
         for (records_left=numRecords;records_left>0;records_left-=readRecords) {
           readRecords=(records_left>buf_records)?buf_records:records_left;
           int readBytes=64*readRecords;
           int gotBytes=0;
           int bp;
           for (bp=0;bp<readBytes; bp+=gotBytes) {
             gotBytes=read (fd_imu,&buffer[bp], readBytes-bp);
             if (gotBytes<0) {
               printf("Error reading %s, returned %d, errno=%d - %s, position=0x%llx\r\n", dev_path, gotBytes,errno,strerror(errno), (long long) lseek(fd_imu,0,SEEK_CUR));
               imu_OK=0;
               break;
             }
           }
           if (!imu_OK) break;
           write(fd_out,buffer,readBytes);
         }
         close (fd_out); // error or end of file
         fd_out=-1;
         numFile++;
         if (!imu_OK) break;
      }
      close (fd_imu); // after errors
      fd_imu=-1;

   }
   return 0;
}

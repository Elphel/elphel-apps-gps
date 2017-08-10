/*
 Garmin USB data source for Elphel Model 353 camera.
 Modified from
	Garmin USB NMEA converter
	Copyright (C) 2004 Manuel Kasper <mk@neon1.net>.
	All rights reserved.

	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:

	1. Redistributions of source code must retain the above copyright notice,
	   this list of conditions and the following disclaimer.

	2. Redistributions in binary form must reproduce the above copyright
	   notice, this list of conditions and the following disclaimer in the
	   documentation and/or other materials provided with the distribution.

	THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
	AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
	AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
	OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
	SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
	INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
	CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
	ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
	POSSIBILITY OF SUCH DAMAGE.
*/
#include <sys/types.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <stdio.h>
#include <unistd.h>
#include <stdlib.h>
#include <string.h>
#include <termios.h>
#include "garmin.h"
#include "nmeagen.h"
#include "exifgen.h"
//#include <asm/elphel/exifa.h>
#include <elphel/x393_devices.h>
#include <elphel/c313a.h>

#define GARMIN_HEADER_SIZE  12
#define GARMIN_MAX_PKTSIZE  512
#define PKTBUF_SIZE         4097
#define D(x)
//#define D(x) x
#define D1(x)
//#define D1(x) x

#pragma pack(push, 1)
typedef struct {
  u_int8_t    mPacketType;
  u_int8_t    mReserved1;
  u_int16_t   mReserved2;
  u_int16_t   mPacketId;
  u_int16_t   mReserved3;
  u_int32_t   mDataSize;
  u_int8_t    mData[1];
} Packet_t; /// 13 bytes
#pragma pack(pop)

//#define EXIF_DEV "/dev/exif_meta"
const char *exif_paths[SENSOR_PORTS] = {
        DEV393_PATH(DEV393_EXIF_META0),
        DEV393_PATH(DEV393_EXIF_META1),
        DEV393_PATH(DEV393_EXIF_META2),
        DEV393_PATH(DEV393_EXIF_META3)};

int gps_fd;
char pktbuf[PKTBUF_SIZE];
int pktbuf_head = 0, pktbuf_tail = 0;

D800_Pvt_Data_Type    lastpvt;         /// 64 bytes
cpo_sat_data          lastsatdata[12]; /// 84 bytes
int satdata_valid = 0;

/**
 * @brief return currenf used buffer size
 * @return current buffer size
 */
int pktbuf_size() {
  return (PKTBUF_SIZE - pktbuf_head + pktbuf_tail) % PKTBUF_SIZE;
}

/**
 * @brief Read byte from buffer, keep buffer pointers
 * @param buf buffer to read data to (will be limited to buffer data if requested more)
 * @param n   number of bytes to read
 * @return number of bytes actually read
 */
int pktbuf_peek(char *buf, int n) {

  int i;
  int mypktbuf_head = pktbuf_head;

  for (i = 0; (i < n) && (mypktbuf_head != pktbuf_tail); i++) {
    buf[i] = pktbuf[mypktbuf_head];
    mypktbuf_head++;
    if (mypktbuf_head == PKTBUF_SIZE){
      mypktbuf_head = 0;
    }
  }

  return i;

}

/**
 * @brief Read byte from buffer, advance buffer head
 * @param buf buffer to read data to (will be limited to buffer data if requested more)
 * @param n   number of bytes to read
 * @return number of bytes actually read
 */
int pktbuf_deq(char *buf, int n) {

  int i;

  //fprintf(stderr, "pktbuf_deq:pktbuf_size()=%d\n",pktbuf_size());
  for (i = 0; (i < n) && (pktbuf_head != pktbuf_tail); i++) {
    buf[i] = pktbuf[pktbuf_head];
    pktbuf_head++;
    if (pktbuf_head == PKTBUF_SIZE){
      pktbuf_head = 0;
    }
  }

  return i;

}

/**
 * @brief Put bytes to buffer, advance buffer tail
 * @param buf buffer to write data from
 * @param n   number of bytes to enqueue
 * @return number of bytes actually written
 */
int pktbuf_enq(char *buf, int n) {

  int i;

  //fprintf(stderr, "pktbuf_enq:pktbuf_size()=%d\n",pktbuf_size());
  if (pktbuf_size() + n >= PKTBUF_SIZE){
    return 0;
  }

  for (i = 0; i < n; i++) {
    pktbuf[pktbuf_tail] = buf[i];
    pktbuf_tail++;
    if (pktbuf_tail == PKTBUF_SIZE){
      pktbuf_tail = 0;
    }
  }

  return i;

}

/**
 * @brief Send packet to GPS
 * @param pack packet data, variable size
 * @return  0 - OK, 1 - error
 */
int sendpacket(Packet_t *pack) {

  int nwr;

  nwr = write(gps_fd, pack, GARMIN_HEADER_SIZE + pack->mDataSize);
  if (nwr == -1) {
    perror("GPS write error");
    return 1;
  }
  return 0;

}

/**
 * @brief Receive packet from the GPS. Wait if not yet available
 * @param  none
 * @return  pointer to a packet or NULL if error (buffer overflow)
 */
Packet_t* recvpacket(void) {

  Packet_t *pkt;
  char tmp[64];
  int nr;

  D1(fprintf(stderr, "+1"));
  pkt = (Packet_t*)malloc(GARMIN_MAX_PKTSIZE); /// 512 bytes
  if (pkt == NULL) {
    perror("malloc failed");
    return NULL;
  }

chkbuf:
  /* complete packet in buffer? */
  if (pktbuf_size() >= GARMIN_HEADER_SIZE) { /// 12 bytes

    Packet_t bufpkt;

    pktbuf_peek((char*)&bufpkt, GARMIN_HEADER_SIZE);
    // should a packet be checked for consistency? at least that the pktlen<= GARMIN_MAX_PKTSIZE?

    int pktlen = GARMIN_HEADER_SIZE + bufpkt.mDataSize;
    if (pktbuf_size() >= pktlen) {
      pktbuf_deq((char*)pkt, pktlen);
      return pkt;
    }

  }

  /* not enough data - read some */
  nr = read(gps_fd, tmp, 64);
  if (nr == -1) {
    perror("GPS read error");
    D1(fprintf(stderr, "-1"));
    free(pkt);
    return NULL;
  }

  if (pktbuf_enq(tmp, nr) == 0) {
    fprintf(stderr, "Input buffer full!\n");
    //got here during overnight run - gets stuck in the loop
    // probably - lost sync, need restart
    D1(fprintf(stderr, "-2"));
    free(pkt);
    return NULL;
  }

  goto chkbuf;

}

/*
  garmin_pvton()
  turn on position records

  receiver measurement records could also be enabled with
  command 110 (instead of 49), but we don't need them at present
*/

void garmin_pvton(void) {

  D1(fprintf(stderr, "+2"));
  Packet_t *pvtpack = (Packet_t*)malloc(14);

  pvtpack->mPacketType = 20;
  pvtpack->mReserved1 = 0;
  pvtpack->mReserved2 = 0;
  pvtpack->mPacketId = 10;
  pvtpack->mReserved3 = 0;
  pvtpack->mDataSize = 2;
  pvtpack->mData[0] = 49;
  pvtpack->mData[1] = 0;

  sendpacket(pvtpack);

}

void garmin_privcmd(int fd) {

  u_int32_t privcmd[4];
  privcmd[0] = 0x01106E4B;
  privcmd[1] = 2;
  privcmd[2] = 4;
  privcmd[3] = 0;
  write(fd, privcmd, 16);

}

int main(int argc, char *argv[]) {

  //int fd_exif;
  int chn, good_chns;
  int fd_exif[SENSOR_PORTS];

  struct meta_GPSInfo_t meta;
  //char nmeabuf[256];
  //u_int32_t privcmd[4];
  //FILE *nmeaout;

  struct termios termio;

  if (argc < 2) {
    printf("Usage: %s gpsdev\n", argv[0]);
    return 1;
  }

  gps_fd = open(argv[1], O_RDWR);
  if (gps_fd == -1) {
    perror("Cannot open GPS device");
    return 1;
  }

  tcgetattr(gps_fd, &termio);
  cfmakeraw(&termio);
  tcsetattr(gps_fd, TCIOFLUSH, &termio);
  garmin_privcmd(gps_fd);
  garmin_pvton();
  pktbuf_head = 0;
  pktbuf_tail = 0;

  good_chns=0;
  for (chn =0; chn < SENSOR_PORTS; chn++){
    if (exif_paths[chn]) { // make them null if inactive
      fd_exif[chn] = open(exif_paths[chn], O_RDWR);
      if (fd_exif[chn] < 0) {
        fprintf(stderr,"Can not open device file %s\n",exif_paths[chn]);
      } else {
        good_chns++;
      }
    }
  }
  if (!good_chns){
    fprintf(stderr,"Could not open any of EXIF channel device files, aborting\n");
    close(gps_fd);
    exit(-1);
  }

  /*
  fd_exif = open(EXIF_DEV, O_RDWR);
  if (fd_exif<=0) {
    fprintf(stderr,"Can not open device file "EXIF_DEV);
    close(gps_fd);
    exit (-1);
  }
  */

  exif_init_meta(&meta);

  while (1) {

    Packet_t *pkt = recvpacket();

    if (pkt) {

      D(printf("Packet ID: %d, head=%d, tail=%d\n", pkt->mPacketId, pktbuf_head, pktbuf_tail));

      if (pkt->mPacketId == Pid_Pvt_Data) {
        memcpy(&lastpvt, pkt->mData, sizeof(lastpvt));

        if (exif_gen(&lastpvt, satdata_valid ? lastsatdata : NULL, &meta) >=0) {

          for (chn =0; chn < SENSOR_PORTS; chn++) {
            if (fd_exif[chn]>=0){
              // position file pointer at the beginning of the data field for GPSLatitudeRef
              lseek (fd_exif[chn],Exif_GPSInfo_GPSLatitudeRef,SEEK_END);
              write(fd_exif[chn], &meta, sizeof(meta));
            }
          }

        }

      } else if (pkt->mPacketId == Pid_SatData_Record) {
        memcpy(lastsatdata, pkt->mData, sizeof(lastsatdata));
        satdata_valid = 1;
      }

      D1(fprintf(stderr, "-3"));
      free(pkt);

    } else { // lost GPS/sync, trying to restart GPS/buffer
      fprintf(stderr,"Lost GPS or sync, trying to restart communication and packet buffer. (pktbuf_head=%d,pktbuf_tail=%d)\n",pktbuf_head,pktbuf_tail);
      tcsetattr(gps_fd, TCIOFLUSH, &termio);
      garmin_privcmd(gps_fd);
      garmin_pvton();
      pktbuf_head = 0;
      pktbuf_tail = 0;
    }
  }
  close(gps_fd);
}

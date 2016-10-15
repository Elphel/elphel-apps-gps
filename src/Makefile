
WWW_PAGES = /www/pages
BINDIR    = /usr/bin/
INSTMODE  = 0755
INSTDOCS =  0644
INSTOWNER = root
INSTGROUP = root

PROGS     = nmea2exif log_imu
SRCS =      nmea2exif.c log_imu.c
OBJS =      nmea2exif.o
PHPSCRIPTS= logger_launcher.php imu_setup.php start_gps_compass.php

CFLAGS   += -Wall -I$(STAGING_DIR_HOST)/usr/include-uapi
LDLIBS   += -lm
 
all: $(PROGS)

log_imu: log_imu.o
	$(CC) $(LDFLAGS) $^ $(LDLIBS) -o $@

nmea2exif: nmea2exif.o
	$(CC) $(LDFLAGS) $^ $(LDLIBS) -o $@

install:	$(PROGS)
	$(INSTALL) $(OWN) -d $(DESTDIR)
	$(INSTALL) -d $(DESTDIR)$(BINDIR)
	$(INSTALL) -d $(DESTDIR)$(WWW_PAGES)
	$(INSTALL) -m $(INSTMODE) -o $(INSTOWNER) -g $(INSTGROUP) $(PROGS) $(DESTDIR)$(BINDIR)
	$(INSTALL) -o $(INSTOWNER) -m $(INSTDOCS) $(PHPSCRIPTS)  $(DESTDIR)$(WWW_PAGES)

clean:
	rm -rf $(PROGS) *.o *~
depend: .depend

.depend: $(SRCS)
	rm -f ./.depend
	$(CC) $(CFLAGS) -MM $^ > ./.depend;

include .depend

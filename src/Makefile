#TODO: pass $(LN) from bitbake 
LN = ln
WWW_PAGES = /www/pages/
BINDIR    = /usr/bin/
VARHTML   = /var/volatile/html/
CONFDIR   = /etc/elphel393/
INSTMODE  = 0755
INSTDOCS =  0644
INSTOWNER = root
INSTGROUP = root

PROGS     = nmea2exif log_imu
SRCS =      nmea2exif.c log_imu.c
OBJS =      nmea2exif.o
PHPSCRIPTS= start_gps_compass.php
PHPWEB=     logger_launcher.php imu_setup.php read_imu_log.php

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
	$(INSTALL) -m $(INSTMODE) -o $(INSTOWNER) -g $(INSTGROUP) $(PHPSCRIPTS) $(DESTDIR)$(BINDIR)
	$(INSTALL) -o $(INSTOWNER) -m $(INSTDOCS) $(PHPWEB)  $(DESTDIR)$(WWW_PAGES)
	$(INSTALL) $(OWN)-d $(DESTDIR)$(VARHTML)
	$(LN) -sf $(VARHTML) $(DESTDIR)$(WWW_PAGES)var
	$(INSTALL) $(OWN)-d $(DESTDIR)$(CONFDIR)
	$(LN) -sf $(CONFDIR) $(DESTDIR)$(WWW_PAGES)etc
	

clean:
	rm -rf $(PROGS) *.o *~
depend: .depend

.depend: $(SRCS)
	rm -f ./.depend
	$(CC) $(CFLAGS) -MM $^ > ./.depend;

include .depend

#!/bin/sh
set -e
TARGET=`pwd`
cd /tmp
rm -f GeoLiteCity.dat.gz GeoLiteCity.dat
wget http://geolite.maxmind.com/download/geoip/database/GeoLiteCity.dat.gz
gunzip GeoLiteCity.dat.gz
mv -f GeoLiteCity.dat "${TARGET}"

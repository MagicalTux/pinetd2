#!/bin/bash

PHP_VERSION=php-5.3.27

PHP_ARCHIVE="$PHP_VERSION".tar.bz2
BUILD_ROOT=`pwd`

if [ ! -f "$PHP_ARCHIVE" ]; then
	wget -O "$PHP_ARCHIVE" "http://php.net/get/$PHP_ARCHIVE/from/this/mirror"
fi

if [ ! -d "$PHP_VERSION" ]; then
	echo -n "Extracting $PHP_VERSION ..."
	tar xjf "$PHP_ARCHIVE"
	echo "done"
	echo "Patching fread()..."
	cd "$PHP_VERSION"
	patch -p0 <<EOF
Index: main/streams/streams.c
===================================================================
--- main/streams/streams.c	(revision 295195)
+++ main/streams/streams.c	(working copy)
@@ -592,6 +592,10 @@
 			size -= toread;
 			buf += toread;
 			didread += toread;
+
+			/* avoid trying to read if we already have data to pass */
+			if (stream->wrapper != &php_plain_files_wrapper)
+				break;
 		}
 
 		/* ignore eof here; the underlying state might have changed */
EOF
	cd ..
fi

cd "$PHP_VERSION"

NEED_AUTOCONF=no

if [ ! -d "ext/proctitle" ]; then
	echo -n "Getting proctitle..."
	# get
	svn co -q http://svn.php.net/repository/pecl/proctitle/trunk/ ext/proctitle
	echo "done"
	NEED_AUTOCONF=yes
fi

if [ ! -d "ext/mailparse" ]; then
	echo -n "Getting mailparse..."
	# get
	svn co -q http://svn.php.net/repository/pecl/mailparse/trunk/ ext/mailparse
	echo "done"
	NEED_AUTOCONF=yes
fi

if [ ! -d "ext/uuid" ]; then
	echo -n "Getting uuid..."
	svn co -q http://svn.php.net/repository/pecl/uuid/trunk/ ext/uuid
	sed -i 's/php_version.h/stdio.h/;s/#error/\/\/#error/' ext/uuid/config.m4
	echo "done"
	NEED_AUTOCONF=yes
fi

if [ "$NEED_AUTOCONF" = "yes" ]; then
	echo -n "Running buildconf..."
	./buildconf --force >/dev/null 2>&1
	autoconf2.13
	autoheader2.13
	autoconf2.13
	echo "done"
fi

echo -n "Cleaning..."
make distclean >/dev/null 2>&1
echo "done"

echo -n "Checking for MySQL..."

MYSQLI_PATH=`PATH="/usr/local/mysql/bin/:$PATH" /usr/bin/which 2>/dev/null mysql_config`

if [ x"$MYSQLI_PATH" == x ]; then
	echo "Not found, falling back to mysqlnd"
	MYSQLI_PATH="mysqlnd"
	MYSQLI_DIR="mysqlnd"
else
	MYSQLI_DIR=`dirname "$MYSQLI_PATH"`
	MYSQLI_DIR=`dirname "$MYSQLI_DIR"`

	echo "Found "`"$MYSQLI_PATH" --version`" in $MYSQLI_DIR"
fi

echo -n "Configuring..."

./configure >configure.log 2>&1 --prefix=/usr/local --without-pear --disable-cgi --enable-sigchild \
 --enable-dba --enable-ftp --enable-mbstring \
 --enable-pcntl --disable-session --enable-sockets --enable-sysvmsg --enable-sysvsem --enable-sysvshm \
 --with-gd --with-jpeg-dir=/usr/lib --with-png-dir --with-zlib --enable-gd-native-ttf --with-kerberos \
 --with-mysql="$MYSQLI_DIR" --with-mysqli="$MYSQLI_PATH" --with-mhash --with-config-file-path="$BUILD_ROOT" \
 --enable-libxml --enable-dom --enable-xml --enable-xmlreader --enable-xmlwriter --with-openssl=/usr \
 --with-curl=/usr --with-curlwrappers --with-bz2 --with-uuid --enable-wddx --enable-intl --with-libdir=/lib/x86_64-linux-gnu \
 --with-imap=/usr --with-imap-ssl --enable-proctitle --enable-mailparse --enable-soap --with-mcrypt --with-gmp

if [ x"$?" != x"0" ]; then
	echo "FAILED"
	tail configure.log
	exit 1
fi

echo "done"

echo -n "Compiling..."

# quick fix for C++
sed -i 's/EXTRA_LIBS = .*/\0 -lstdc++/' Makefile

make >make.log 2>&1
if [ x"$?" != x"0" ]; then
	echo "FAILED"
	cat make.log | grep error | tail
	exit 1
fi

echo "done"

cd ..
ln -snf "$BUILD_ROOT/$PHP_VERSION/sapi/cli/php"

./php -v




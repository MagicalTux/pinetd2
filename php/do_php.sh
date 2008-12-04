#!/bin/sh

PHP_VERSION=php-5.3.0alpha3

PHP_ARCHIVE="$PHP_VERSION".tar.bz2
BUILD_ROOT=`pwd`

if [ ! -f "$PHP_ARCHIVE" ]; then
	wget http://beta.magicaltux.net/"$PHP_ARCHIVE"
fi

if [ ! -d "$PHP_VERSION" ]; then
	echo -n "Extracting $PHP_VERSION ..."
	tar xjf "$PHP_ARCHIVE"
	echo "done"
fi

cd "$PHP_VERSION"

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
 --with-gd --with-jpeg-dir=/usr/lib --with-png-dir --with-zlib --enable-gd-native-ttf --enable-dbase \
 --with-mysql="$MYSQLI_DIR" --with-mysqli="$MYSQLI_PATH" --with-mhash --with-config-file-path="$BUILD_ROOT" \
 --enable-libxml --enable-dom --enable-xml --enable-xmlreader --enable-xmlwriter --with-openssl=/usr \
 --with-imap=/usr --with-imap-ssl

if [ x"$?" != x"0" ]; then
	echo "FAILED"
	tail configure.log
	exit 1
fi

echo "done"

echo -n "Compiling..."

# determine amount of CPU
MAKEOPTS=-j2
if [ -r /proc/cpuinfo ]; then
	NCPU=`grep -c ^processor /proc/cpuinfo`
	NPROCESS=$[ $NCPU * 2 ]
	if [ $NPROCESS -ge 2 ]; then
		NPROCESS=$[ $NPROCESS - 1 ]
	fi
	if [ $NPROCESS -le 1 ]; then
		NPROCESS=1
	fi
	MAKEOPTS="-j$NPROCESS"
fi

make $MAKEOPTS >make.log 2>&1
if [ x"$?" != x"0" ]; then
	echo "FAILED"
	tail make.log
	exit 1
fi

echo "done"

cd ..
ln -snf "$BUILD_ROOT/$PHP_VERSION/sapi/cli/php"

./php -v




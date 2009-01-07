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

NEED_AUTOCONF=no

if [ ! -d "ext/runkit" ]; then
	echo -n "Getting runkit..."
	# login to anoncvs
	if [ -f ~/.cvspass ]; then
		if [ `grep -c ':pserver:cvsread@cvs.php.net:2401/repository' ~/.cvspass 2>/dev/null` -eq 0 ]; then
			echo "/1 :pserver:cvsread@cvs.php.net:2401/repository A" >>~/.cvspass
		fi
	else
		echo "/1 :pserver:cvsread@cvs.php.net:2401/repository A" >>~/.cvspass
	fi
	# get runkit
	cvs -Q -d :pserver:cvsread@cvs.php.net:2401/repository checkout pecl/runkit
	# I hate cvs
	mv pecl/runkit ext/runkit
	rm -fr pecl
	# fix ZVAL_REFCOUNT which became Z_REFCOUNT_P in 5.3.0 (maybe?)
	sed -i 's/ZVAL_REFCOUNT/Z_REFCOUNT_P/' ext/runkit/runkit.c
	sed -i 's/member->refcount = 1/Z_SET_REFCOUNT_P(member, 1)/' ext/runkit/runkit_sandbox_parent.c
	sed -i 's/(pzv)->refcount = 1/Z_SET_REFCOUNT_P(pzv, 1)/' ext/runkit/php_runkit.h
	sed -i 's/(pzv)->is_ref = 0/Z_UNSET_ISREF_P(pzv)/' ext/runkit/php_runkit.h
	# fix for change in sapi header_handler
	sed -i 's/php_runkit_sandbox_sapi_header_handler(sapi_header_struct \*sapi_header,sapi_headers_struct/php_runkit_sandbox_sapi_header_handler(sapi_header_struct *sapi_header,sapi_header_op_enum op,sapi_headers_struct/' ext/runkit/runkit_sandbox.c
	sed -i 's/php_runkit_sandbox_original_sapi.header_handler(sapi_header, sapi_headers/php_runkit_sandbox_original_sapi.header_handler(sapi_header, op, sapi_headers/' ext/runkit/runkit_sandbox.c
	for foo in ext/runkit/runkit_sandbox.c ext/runkit/runkit.c ext/runkit/runkit_sandbox_parent.c ext/runkit/runkit_constants.c ext/runkit/runkit_import.c ext/runkit/runkit_props.c; do
		sed -i -r -e 's/([a-z_]+)->refcount = ([^;]+)/Z_SET_REFCOUNT_P(\1, \2)/' "$foo"
		sed -i -r -e 's/([a-z_]+)\.refcount = ([^;]+)/Z_SET_REFCOUNT(\1, \2)/' "$foo"
		sed -i -r -e 's/([a-z_]+)->refcount--/Z_SET_REFCOUNT_P(\1, Z_REFCOUNT_P(\1) - 1)/' "$foo"
		sed -i -r -e 's/([a-z_]+)->refcount\+\+/Z_SET_REFCOUNT_P(\1, Z_REFCOUNT_P(\1) - 1)/' "$foo"
		sed -i -r -e 's/([a-z_]+)->is_ref = 0/Z_UNSET_ISREF_P(\1)/' "$foo"
		sed -i -r -e 's/([a-z_]+)\.is_ref = 0/Z_UNSET_ISREF(\1)/' "$foo"
		sed -i -r -e 's/([a-z_]+)->is_ref/Z_ISREF_P(\1)/' "$foo"
		sed -i -r -e 's/zend_is_callable\(([^)]*)\)/zend_is_callable(\1 TSRMLS_CC)/' "$foo"
		sed -i -r -e 's/zend_file_handle_dtor\(([^)]*)\)/zend_file_handle_dtor(\1 TSRMLS_CC)/' "$foo"
		# for stuff like if ((*symtable)->refcount > 1 && ...
		sed -i -r -e 's/\(\*([a-z_]+)\)->refcount\+\+/Z_SET_REFCOUNT_PP(\1, Z_REFCOUNT_PP(\1) + 1)/' "$foo"
		sed -i -r -e 's/\(\*([a-z_]+)\)->is_ref = 1/Z_SET_ISREF_PP(\1)/' "$foo"
		sed -i -r -e 's/\(\*([a-z_]+)\)->refcount/Z_REFCOUNT_PP(\1)/' "$foo"
		sed -i -r -e 's/\(\*([a-z_]+)\)->is_ref/Z_ISREF_PP(\1)/' "$foo"
		sed -i 's/ZVAL_ADDREF/Z_ADDREF/' "$foo"
	done
	echo "done"
	NEED_AUTOCONF=yes
fi

if [ ! -d "ext/ares" ]; then
	echo -n "Getting ares..."
	# login to anoncvs
	if [ -f ~/.cvspass ]; then
		if [ `grep -c ':pserver:cvsread@cvs.php.net:2401/repository' ~/.cvspass 2>/dev/null` -eq 0 ]; then
			echo "/1 :pserver:cvsread@cvs.php.net:2401/repository A" >>~/.cvspass
		fi
	else
		echo "/1 :pserver:cvsread@cvs.php.net:2401/repository A" >>~/.cvspass
	fi
	# get ares
	cvs -Q -d :pserver:cvsread@cvs.php.net:2401/repository checkout pecl/ares
	# I hate cvs
	mv pecl/ares ext/ares
	rm -fr pecl
	# Fix .cvsignore file in ares to *not* include config*
	cat ext/ares/.cvsignore | grep -v 'config\*' >ext/ares/.cvsignore~
	mv -f ext/ares/.cvsignore~ ext/ares/.cvsignore
	# Fix some other things
	for foo in ext/ares/*.c; do
		sed -i -r -e 's/zend_is_callable\(([^)]*)\)/zend_is_callable(\1 TSRMLS_CC)/' "$foo"
	done
	echo "done"
	NEED_AUTOCONF=yes
fi

if [ "$NEED_AUTOCONF" = "yes" ]; then
	echo -n "Running buildconf..."
	autoconf-2.13
	autoheader-2.13
	autoconf-2.13
	./buildconf --force >/dev/null 2>&1
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
 --with-gd --with-jpeg-dir=/usr/lib --with-png-dir --with-zlib --enable-gd-native-ttf \
 --with-mysql="$MYSQLI_DIR" --with-mysqli="$MYSQLI_PATH" --with-mhash --with-config-file-path="$BUILD_ROOT" \
 --enable-libxml --enable-dom --enable-xml --enable-xmlreader --enable-xmlwriter --with-openssl=/usr \
 --with-imap=/usr --with-imap-ssl --enable-maintainer-zts --enable-runkit --enable-runkit-sandbox --with-ares

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
	cat make.log | grep error | tail
	exit 1
fi

echo "done"

cd ..
ln -snf "$BUILD_ROOT/$PHP_VERSION/sapi/cli/php"

./php -v




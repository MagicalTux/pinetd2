#!/bin/sh

PHP_VERSION=php-5.3.2

PHP_ARCHIVE="$PHP_VERSION".tar.bz2
BUILD_ROOT=`pwd`

if [ ! -f "$PHP_ARCHIVE" ]; then
	wget http://beta.magicaltux.net/"$PHP_ARCHIVE"
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
	echo "Patching SQLite3..."
	patch -p0 <<EOF
Index: ext/sqlite3/sqlite3.c
===================================================================
--- ext/sqlite3/sqlite3.c	(révision 296050)
+++ ext/sqlite3/sqlite3.c	(copie de travail)
@@ -292,6 +292,32 @@
 }
 /* }}} */
 
+/* {{{ proto bool SQLite3::busyTimeout(int msecs)
+   Sets a busy handler that will sleep until database is not locked or timeout is reached. Passing a value less than or equal to zero turns off all busy handlers. */
+PHP_METHOD(sqlite3, busyTimeout)
+{
+	php_sqlite3_db_object *db_obj;
+	zval *object = getThis();
+	long ms;
+	int return_code;
+	db_obj = (php_sqlite3_db_object *)zend_object_store_get_object(object TSRMLS_CC);
+
+	SQLITE3_CHECK_INITIALIZED(db_obj, db_obj->initialised, SQLite3)
+
+	if (FAILURE == zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "l", &ms)) {
+		return;
+	}
+
+	return_code = sqlite3_busy_timeout(db_obj->db, ms);
+	if (return_code != SQLITE_OK) {
+		php_sqlite3_error(db_obj, "Unable to set busy timeout: %d, %s", return_code, sqlite3_errmsg(db_obj->db));
+		RETURN_FALSE;
+	}
+
+	RETURN_TRUE;
+}
+/* }}} */
+
 #ifndef SQLITE_OMIT_LOAD_EXTENSION
 /* {{{ proto bool SQLite3::loadExtension(String Shared Library)
    Attempts to load an SQLite extension library. */
@@ -1646,6 +1672,10 @@
 	ZEND_ARG_INFO(0, encryption_key)
 ZEND_END_ARG_INFO()
 
+ZEND_BEGIN_ARG_INFO(arginfo_sqlite3_busytimeout, 0)
+	ZEND_ARG_INFO(0, ms)
+ZEND_END_ARG_INFO()
+
 #ifndef SQLITE_OMIT_LOAD_EXTENSION
 ZEND_BEGIN_ARG_INFO(arginfo_sqlite3_loadextension, 0)
 	ZEND_ARG_INFO(0, shared_library)
@@ -1730,6 +1760,7 @@
 	PHP_ME(sqlite3,		lastInsertRowID,	arginfo_sqlite3_void, ZEND_ACC_PUBLIC)
 	PHP_ME(sqlite3,		lastErrorCode,		arginfo_sqlite3_void, ZEND_ACC_PUBLIC)
 	PHP_ME(sqlite3,		lastErrorMsg,		arginfo_sqlite3_void, ZEND_ACC_PUBLIC)
+	PHP_ME(sqlite3,		busyTimeout,		arginfo_sqlite3_busytimeout, ZEND_ACC_PUBLIC)
 #ifndef SQLITE_OMIT_LOAD_EXTENSION
 	PHP_ME(sqlite3,		loadExtension,		arginfo_sqlite3_loadextension, ZEND_ACC_PUBLIC)
 #endif
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
	autoconf-2.13
	autoheader-2.13
	autoconf-2.13
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
 --with-curl=/usr --with-curlwrappers --with-bz2 --with-uuid --enable-wddx --enable-intl \
 --with-imap=/usr --with-imap-ssl --enable-proctitle --enable-mailparse --enable-soap --with-mcrypt --with-gmp

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




#!/bin/sh
exec ./php/php -d date.timezone=`date +%Z` code/root.php "$@"

#!/bin/sh
exec ./php/php -d date.timezone=Europe/Paris code/root.php "$@"

#!/bin/sh
# $Id$
# Autogen self-signed certificate
# Details found there :
# http://sial.org/howto/openssl/self-signed/

CERTIFICATE="newkey"
ENCRYPT="no"

OPENSSL=`which openssl 2>/dev/null`
if [ x"$OPENSSL" = x ]; then
	echo "Openssl needed. Please install it!"
	exit 1
fi

echo
echo '*****'
echo "** When asked for Common Name, enter the server's name"
echo '*****'
echo

"$OPENSSL" req -new -out "$CERTIFICATE.req.pem" -newkey rsa:2048 -keyout "$CERTIFICATE.key.pem" -nodes
chmod 0400 "$CERTIFICATE.req.pem"

"$OPENSSL" req -x509 -days 365 -in "$CERTIFICATE.req.pem" -key "$CERTIFICATE.key.pem" -out "$CERTIFICATE.cert.pem"
"$OPENSSL" x509 -subject -dates -fingerprint -noout -in "$CERTIFICATE.cert.pem"

chmod o-rwx "$CERTIFICATE.req.pem" "$CERTIFICATE.key.pem" "$CERTIFICATE.cert.pem"
chmod g-rwx "$CERTIFICATE.req.pem" "$CERTIFICATE.key.pem" "$CERTIFICATE.cert.pem"

if [ x"$ENCRYPT" != x"no" ]; then
	"$OPENSSL" rsa -in "$CERTIFICATE.key.pem" -out "$CERTIFICATE.key.c.pem" -des3
	if [ -f "$CERTIFICATE.key.c.pem" ]; then
		cat >"$CERTIFICATE.key.pem" "$CERTIFICATE.key.c.pem"
		rm -f "$CERTIFICATE.key.c.pem"
	fi
fi

cat "$CERTIFICATE.cert.pem" "$CERTIFICATE.key.pem" >"$CERTIFICATE.pem"
chmod 0400 "$CERTIFICATE.pem"


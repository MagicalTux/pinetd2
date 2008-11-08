#!/bin/sh
# This file will generate a local SSL 768bits certificate for use in
# local domains.

OPENSSL=`which openssl 2>/dev/null`
if [ x"$OPENSSL" = x ]; then
	echo "Openssl needed. Please install it!"
	exit 1
fi

if [ ! -f domainkey.private ]; then
	# Generate key
	"$OPENSSL" genrsa -out domainkey.private 768
fi

# Get corresponding public key
PUBLIC=`"$OPENSSL" rsa -in domainkey.private -pubout -outform PEM`

# cleanup output to make it suitable for DNS records

PUBLIC=`echo "$PUBLIC" | sed -e 's/-----.*-----//'`
PUBLIC_OUT=""
for foo in $PUBLIC; do PUBLIC_OUT="$PUBLIC_OUT$foo"; done

echo
echo "*****"
echo "** Domain key ready. Add the following line to your DNS zones files"
echo "** to enable it. Note that everyone will have to use this server"
echo "** as a SMTP in order to have the mails accepted."
echo "** Once you are sure that the DomainKeys are working as expected, you"
echo "** can remove the t=y in order to go in production mode."
echo "*****"
echo "_domainkey.yourdomain.com.	IN	TXT	\"k=rsa\; t=y\; p=$PUBLIC_OUT\;\""

# o=- : All emails sent by this domain should be signed
# o=~ : We may receive non-signed emails. Checking the domain key is meaningless




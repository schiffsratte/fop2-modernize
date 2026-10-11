#!/bin/bash
if [ -f /etc/httpd/conf.d/ssl.conf ];
then
        CONFFILE=/etc/httpd/conf.d/ssl.conf
elif [ -f /etc/apache2/sites-available/vitalpbx.conf ];
then
        CONFFILE=/etc/apache2/sites-available/vitalpbx.conf
elif [ -f /etc/apache2/sites-available/sangoma-ssl.conf ];
then
        CONFFILE=/etc/apache2/sites-available/sangoma-ssl.conf
else
        echo "No conf file found"
        exit;
fi
CERT=$(grep "SSLCertificateFile /" $CONFFILE | grep -o " /.*" | head -n 1 | sed 's/ //g')
KEY=$(grep "SSLCertificateKeyFile /" $CONFFILE | grep -o " /.*" | head -n 1 | sed 's/ //g')

if [ "$CERT" != '' ]; then
    sed -i "/^ssl_certificate_file/c\ssl_certificate_file=$CERT" /usr/local/fop2/fop2.cfg
    sed -i "/^ssl_certificate_key_file/c\ssl_certificate_key_file=$KEY" /usr/local/fop2/fop2.cfg
    echo "Certificate config done!"
    service fop2 restart
else
    echo "No SSL Certificates for Apache found"
fi

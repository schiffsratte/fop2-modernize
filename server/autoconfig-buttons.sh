#!/bin/bash

if [ -e /etc/freepbx.conf ]; then

DBNAME=`cat /etc/freepbx.conf | sed 's/ //g' | grep -v AMPDBNAMECDR | grep AMPDBNAME | tail -n 1 | cut -d= -f2 `
DBUSER=`cat /etc/freepbx.conf | sed 's/ //g' | grep AMPDBUSER | tail -n 1 | cut -d= -f2 `
DBPASS=`cat /etc/freepbx.conf | sed 's/ //g' | grep AMPDBPASS | tail -n 1 | cut -d= -f2- `
DBHOST=`cat /etc/freepbx.conf | sed 's/ //g' | grep AMPDBHOST | cut -d= -f2 | tail -n1`
DBNAME=${DBNAME:1}
DBNAME=${DBNAME%??}
DBUSER=${DBUSER:1}
DBUSER=${DBUSER%??}
DBPASS=${DBPASS:1}
DBPASS=${DBPASS%??}
DBHOST=${DBHOST:1}
DBHOST=${DBHOST%??}

elif [ -e /etc/issabelpbx.conf ]; then

DBNAME=`cat /etc/issabelpbx.conf | sed 's/ //g' | grep -v AMPDBNAMECDR | grep AMPDBNAME | tail -n 1 | cut -d= -f2 `
DBUSER=`cat /etc/issabelpbx.conf | sed 's/ //g' | grep AMPDBUSER | tail -n 1 | cut -d= -f2 `
DBPASS=`cat /etc/issabelpbx.conf | sed 's/ //g' | grep AMPDBPASS | tail -n 1 | cut -d= -f2- `
DBHOST=`cat /etc/issabelpbx.conf | sed 's/ //g' | grep AMPDBHOST | cut -d= -f2 | tail -n1`
DBNAME=${DBNAME:1}
DBNAME=${DBNAME%??}
DBUSER=${DBUSER:1}
DBUSER=${DBUSER%??}
DBPASS=${DBPASS:1}
DBPASS=${DBPASS%??}
DBHOST=${DBHOST:1}
DBHOST=${DBHOST%??}
if [ "$DBHOST" = "" ];then
DBHOST=localhost
fi

elif [ -e /etc/amportal.conf ]; then
DBNAME=`cat /etc/amportal.conf | sed 's/ //g' | grep ^AMPDBNAME= | cut -d= -f2 | tail -n1`
DBUSER=`cat /etc/amportal.conf | sed 's/ //g' | grep ^AMPDBUSER= | cut -d= -f2 | tail -n1`
DBPASSLINE=`cat /etc/amportal.conf | grep ^AMPDBPASS= | tail -n1`
DBSTRIP=`echo $DBPASSLINE | cut -d= -f1`
DBPASS=`echo $DBPASSLINE | sed "s/$DBSTRIP=//g"`
DBHOST=`cat /etc/amportal.conf | sed 's/ //g' | grep ^AMPDBHOST= | cut -d= -f2 | tail -n1`

elif [ -e /var/thirdlane_load/pbxportal-ast.sysconfig ]; then
DBNAME='pbxconf'
DBHOST='localhost'
DBUSER=`cat /var/thirdlane_load/pbxportal-ast.sysconfig | sed 's/ //g' | grep ^DBUSER | cut -d= -f2 | tail -n1`
DBPASSLINE=`cat /var/thirdlane_load/pbxportal-ast.sysconfig | grep ^DBPASS | tail -n1`
DBSTRIP=`echo $DBPASSLINE | cut -d= -f1`
DBPASS=`echo $DBPASSLINE | sed "s/$DBSTRIP=//g"`

elif [ -e /opt/pbxware/pw/etc/pbxware/pbxware.ini  ]; then

DBNAME='pbxware'
DBHOST=`cat /opt/pbxware/pw/etc/pbxware/pbxware.ini | sed 's/ //g' | grep ^pw_mysql_host | cut -d= -f2 | tail -n1`
DBUSER=`cat /opt/pbxware/pw/etc/pbxware/pbxware.ini | sed 's/ //g' | grep ^pw_mysql_username | cut -d= -f2 | tail -n1`
DBPASSLINE=`cat /opt/pbxware/pw/etc/pbxware/pbxware.ini | grep ^pw_mysql_password | tail -n1`
DBSTRIP=`echo $DBPASSLINE | cut -d= -f1`
DBPASS=`echo $DBPASSLINE | sed "s/$DBSTRIP=//g"`

elif [ -e /etc/kamailio/kamailio-mhomed-elastix.cfg ]; then

DBNAME='elxpbx'
DBHOST='localhost'
DBPASS=`grep mysqlroot /etc/elastix.conf | cut -d= -f2- | tail -n1`
DBUSER='root'

elif [ -e /etc/ombutel/ombutel.conf ]; then

DBNAME=fop2
DBUSER=root
DBHOST=localhost
DBPASS=
PLUGINDIR=/usr/share/fop2/plugins

elif [ -d /etc/vitalpbx ]; then

DBNAME=ombutel
DBUSER=vitalpbx
DBHOST=localhost
DBPASS=vitalpbx
PLUGINDIR=/var/www/html/fop2/admin/plugins

elif [ -e /etc/asterisk/snep/snep-features.conf ]; then

DBNAME=snep
DBUSER=snep
DBPASS=sneppass
DBHOST=localhost

elif [ -e /var/www/html/fop2/admin/config.php ]; then

DBNAME=`cat /var/www/html/fop2/admin/config.php | sed 's/ //g' | grep ^\\$DBNAME | cut -d= -f2 | tail -n1`
DBUSER=`cat /var/www/html/fop2/admin/config.php | sed 's/ //g' | grep ^\\$DBUSER | cut -d= -f2 | tail -n1`
DBPASSLINE=`cat /var/www/html/fop2/admin/config.php | grep ^\\$DBPASS | tail -n1`
DBSTRIP=`echo $DBPASSLINE | cut -d= -f1`
DBPASS=`echo $DBPASSLINE | sed "s/$DBSTRIP=[ ]*//g"`
DBHOST=`cat /var/www/html/fop2/admin/config.php | sed 's/ //g' | grep ^\\$DBHOST | cut -d= -f2 | tail -n1`
DBNAME=${DBNAME:1}
DBNAME=${DBNAME%??}
DBUSER=${DBUSER:1}
DBUSER=${DBUSER%??}
DBPASS=${DBPASS:1}
DBPASS=${DBPASS%??}
DBHOST=${DBHOST:1}
DBHOST=${DBHOST%??}

elif [ -e /var/www/fop2/admin/config.php ]; then

DBNAME=`cat /var/www/fop2/admin/config.php | sed 's/ //g' | grep ^\\$DBNAME | cut -d= -f2 | tail -n1`
DBUSER=`cat /var/www/fop2/admin/config.php | sed 's/ //g' | grep ^\\$DBUSER | cut -d= -f2 | tail -n1`
DBPASSLINE=`cat /var/www/fop2/admin/config.php | grep ^\\$DBPASS | tail -n1`
DBSTRIP=`echo $DBPASSLINE | cut -d= -f1`
DBPASS=`echo $DBPASSLINE | sed "s/$DBSTRIP=//g"`
DBHOST=`cat /var/www/fop2/admin/config.php | sed 's/ //g' | grep ^\\$DBHOST | cut -d= -f2 | tail -n1`
DBNAME=${DBNAME:1}
DBNAME=${DBNAME%??}
DBUSER=${DBUSER:1}
DBUSER=${DBUSER%??}
DBPASS=${DBPASS:1}
DBPASS=${DBPASS%??}
DBHOST=${DBHOST:1}
DBHOST=${DBHOST%??}

else
DBHOST='none'
fi

if [ "x$DBPASS" != "x" ]; then
DBPASS="-p${DBPASS}"
fi

if [ -d /var/www/html/admin/modules/fop2admin/plugins ]; then
PLUGINDIR=/var/www/html/admin/modules/fop2admin/plugins
fi

if [ -d /var/www/html/fop2/admin/plugins ]; then
PLUGINDIR=/var/www/html/fop2/admin/plugins
fi

if [ -d /var/www/fop2/admin/plugins ]; then
PLUGINDIR=/var/www/fop2/admin/plugins
fi

hash_insert () {
local name=$1 key=$2 val=$3
eval __hash_${name}_${key}=$val
}

hash_get () {
local name=$1 key=$2
eval "v=__hash_${name}_${key}"
eval "$3=\$$v"
}

FOP2ADMIN=0
# Only check for table presence if mysql connection is ok
mysql -u $DBUSER $DBPASS -h $DBHOST $DBNAME -e "SELECT now()" &>/dev/null
if [ $? == 0 ]; then
# Verify if the fop2admin table exists for fop2manager/fop2admin
while read line; do
let FOP2ADMIN=FOP2ADMIN+1
done < <( mysql -NB -u $DBUSER $DBPASS -h $DBHOST $DBNAME -e "SHOW tables FROM \`$DBNAME\` LIKE 'fop2users'";  )
else
echo "# Problem connecting to mysql"
echo
fi

if [ "$FOP2ADMIN" -gt 0 ]; then

# Fields we want to query
WANTED_FIELDS="queuechannel originatechannel customastdb spyoptions external tags extenvoicemail queuecontext autoanswerheader originatevariables accountcode server cssclass sip_username sip_password";

for A in `find $PLUGINDIR -name plugin.xml`
do
AFIELD=`grep -oPm1 "(?<=<custombuttonfields>)[^<]+" $A`
if [ "$AFIELD" != "" ]; then
MOREFIELDS="$MOREFIELDS $AFIELD"
fi
done

WANTED_FIELDS="$WANTED_FIELDS $MOREFIELDS"

eval `mysql -E -u $DBUSER $DBPASS -h $DBHOST $DBNAME -e "DESC fop2buttons" | grep Field | sed 's/Field: //g' | while read LINEA; do echo "hash_insert existentFields $LINEA '1';"; done;`

EXISTING_FIELDS=""
for A in $WANTED_FIELDS
do
hash_get existentFields $A tiene
if [ "$tiene" = "1" ]; then
EXISTING_FIELDS="${EXISTING_FIELDS}$A,"
fi
done

FINAL_FIELDS=${EXISTING_FIELDS%?};

hash_get existentFields sortorder tiene
if [ "$tiene" = "1" ]; then
    ORDER="type,sortorder,(exten+0)"
else
    ORDER="type,(exten+0)"
fi

GRPFIELD=""
if [ "$2" != "" ]; then
    GRPFIELD="'$2' AS \`group\`,"
fi

if [ "X$1" == "X" ] || [ "X$1" == "XGENERAL" ]; then
MAINQUERY="SET NAMES utf8; SELECT device AS channel,type,IFNULL(subquery.phonepro,'') as phonepro,if(type<>'trunk',exten,'') AS extension, label, mailbox, context, privacy,\`group\`,IF(type='trunk',IF(email<>'',concat('splitme-',email),''),email) as email, channel as extrachannel, $FINAL_FIELDS FROM fop2buttons LEFT JOIN (SELECT exten AS ext,'yes' AS phonepro FROM fop2UserPlugin WHERE id_plugin='phonepro') AS subquery ON fop2buttons.exten=subquery.ext WHERE exclude=0 ORDER BY $ORDER"
else
MAINQUERY="SET NAMES utf8; SELECT device AS channel,type,IFNULL(subquery.phonepro,'') as phonepro,if(type<>'trunk',exten,'') AS extension, label, mailbox, fop2buttons.context, privacy,\`group\`,IF(type='trunk',IF(email<>'',concat('splitme-',email),''),email) as email, channel as extrachannel,$GRPFIELD $FINAL_FIELDS FROM fop2buttons LEFT JOIN fop2contexts ON context_id=fop2contexts.id LEFT JOIN (SELECT exten AS ext,'yes' AS phonepro,context_id as pctxid FROM fop2UserPlugin WHERE id_plugin='phonepro') AS subquery ON fop2buttons.exten=subquery.ext AND fop2buttons.context_id=pctxid WHERE fop2buttons.exclude=0 and fop2contexts.context='$1' ORDER BY $ORDER"
fi

mysql -EB -u $DBUSER $DBPASS -h $DBHOST $DBNAME -e "$MAINQUERY" | sed -e '/\*\*\*\*/d' -e 's/: /=/' -e '/.*=$/d' | while read LINEA
do
echo $LINEA | sed -e '/NULL/d' -e 's/^channel=\(.*\)/\n[\1]/g' -e 's/^extrachannel/channel/g'
echo $LINEA | grep -qi "^email=splitme"
if [ $? = 0 ]; then
RANGE=`echo $LINEA | sed -e 's/^email=splitme-//g' -e 's/-/ /g'`
for ZAPNUM in `seq $RANGE`
do
echo "channel=DAHDI/$ZAPNUM"
echo "channel=DAHDI/i$ZAPNUM"
echo "channel=ZAP/$ZAPNUM"
done
fi
done

if [ -f custom_buttons.sh ]; then
    source custom_buttons.sh
fi

echo
# buttons custom is read now by FOP2 Manager and content inserted into FOP2 Manager tables 
#if [ -f /usr/local/fop2/buttons_custom.cfg ]; then
#cat /usr/local/fop2/buttons_custom.cfg
#fi

else
echo "! Cannot connect to FOP2 Manager database"
fi

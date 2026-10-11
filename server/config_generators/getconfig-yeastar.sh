#!/bin/bash

ASTCLI=/usr/local/fop2/astcli

while getopts u:s:h: option
do
    case "${option}"
        in
        u) USER=${OPTARG};;
        s) SECRET=${OPTARG};;
        h) HOST=${OPTARG};;
    esac
done

# Extensions
for A in `$ASTCLI -u $USER -s $SECRET -h $HOST "pjsip show endpoints" | grep Endpoint: | grep -v Chan | awk '{ print $2}' | cut -d/ -f1 | grep -v trunk `
do
    label=$($ASTCLI -u $USER -s $SECRET -h $HOST "pjsip show endpoint $A" | grep CALLERNAME | cut -d: -f 2 | sed 's/^[ \t]*//;s/[ \t]*$//')
    echo "[PJSIP/$A]"
    echo "type=extension"
    echo "extension=$A"
    echo "context=DLPN_DialPlan$A"
    echo "label=$label"
    echo "mailbox=$A@default"
    echo "autoanswerheader=__SIPADDHEADER51=Call-Info=answer-after=0.001"
    echo
done

#Conferences
for A in `./astcli -u $USER -s $SECRET -h $HOST "dialplan show conferences" | grep '=>' | awk '{print $1}' | sed 's/[^0-9]*//g'`
do
    echo "[CONFERENCE/$A]"
    echo "type=conference"
    echo "extension=$A"
    echo "context=conferences"
    echo "label=Conference $A"
    echo
done

# Queues
for A in `./astcli -u $USER -s $SECRET -h $HOST "dialplan show queues" | grep '=>' | grep -v \* | awk '{print $1}' | sed 's/[^0-9]*//g'`
do
    if [ "$A" != "" ]; then
        echo "[QUEUE/queue_$A]"
        echo "type=queue"
        echo "extension=$A"
        echo "context=queues"
        echo "label=Queue $A"
        echo
    fi
done


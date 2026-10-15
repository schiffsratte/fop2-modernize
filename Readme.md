# FOP2 compatibility fixes for modern systems

This repository contains a few compatibility fixes for running the
legacy FOP2 server and web interface on current Debian/FreePBX
installations.
All files are from fop2 version 2.31.50 for Debian as my 
current license only works with this version.

It may not be necessary for more recent versions.
You should check journalctrl if you see any segfault like this:

``` text
kernel: fop2_server[1182905]: segfault at 7f374f0013a8 ip 00007f374ed5eef9
sp 00007ffc0a7f5460 error 4 in libmysqlclient.so.15[7f374ed00000+1b4000] likely
on CPU 1 (core 1, socket 0)
```
It is always the same error, the numbers never change.

## FOP2 server crash with modern MariaDB

FOP2 ships with an old MySQL client library (`libmysqlclient.so.15`,
MySQL 5.0.x).

On current MariaDB installations the bundled client may crash while
parsing the system charset definitions:

``` text
fop2_server: segfault ... in libmysqlclient.so.15
```

The crash occurs during `mysql_real_connect()` while the old client
parses `/usr/share/mysql/charsets/Index.xml`.

Modern MariaDB versions contain collation IDs greater than 255 (for
example `latin2_general_nopad_ci`, ID 1033), which the legacy MySQL
client does not handle correctly. This can result in an invalid access
to its internal `all_charsets[]` table and a segmentation fault.

### Workaround

FOP2 is given its own compatible charset directory instead of modifying
the system-wide MariaDB files.

A systemd override can isolate the workaround to FOP2:

``` ini
[Service]
Environment="MYSQL_HOME=/usr/local/fop2/mysql-config"
BindReadOnlyPaths=/usr/local/fop2/mysql-charsets:/usr/share/mysql/charsets
```

The private `Index.xml` has unsupported high-numbered collations
removed.

This leaves the MariaDB installation and its original charset files
untouched.

> Do not replace `libmysqlclient.so.15` with a symlink to a current
> `libmysqlclient`/MariaDB library. They are not ABI-compatible.

## PHP 8.2 compatibility

The FOP2 web interface PHP files have also been cleaned up for PHP 8.2.

Deprecated PHP constructs which generated warnings on current
FreePBX/Debian installations were updated without intentionally changing
the functionality of the FOP2 interface.

## Tested environment

-   Debian 12
-   MariaDB 10.11
-   PHP 8.2
-   FOP2 with bundled `libmysqlclient.so.15`
-   Freepbx 17

## Disclaimer
    This script is provided as-is. The author do not take responsibility for any data loss
    or system issues that may arise from using this script. Use it at your own risk.

## License
  My modifications do not change the program's license in any form.
  Go to https://www.fop2.com/ for details or buy a license here :  https://www.fop2.com/buy.php
  

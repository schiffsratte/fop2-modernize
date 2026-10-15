#!/usr/bin/perl

use strict;
use warnings;

use DBI;
use File::Basename qw(basename);
use File::Path qw(make_path);
use File::Copy qw(move);

my $DESTFOLDER = "/var/spool/asterisk/monitor";
my $LOGFILE    = "/var/log/asterisk/recording_fop2026.log";

sub logmsg {
    my ($msg) = @_;
    my $ts = scalar localtime();

    if (open my $fh, '>>', $LOGFILE) {
        print $fh "$ts $msg\n";
        close $fh;
    }
}

sub fail {
    my ($msg) = @_;
    logmsg("ERROR: $msg");
    die "$msg\n";
}

# ------------------------------------------------------------
# Arguments from current FOP2:
#
#   ARGV[0] = completed mixed WAV
#   ARGV[1] = Asterisk uniqueid
# ------------------------------------------------------------

@ARGV >= 2
    or fail("Usage: recording_fop2026.pl recording.wav uniqueid");

my ($source, $uniqueid) = @ARGV;

-f $source
    or fail("Recording does not exist: $source");

$uniqueid =~ /^[A-Za-z0-9_.:-]+$/
    or fail("Invalid uniqueid: $uniqueid");

my $basename = basename($source);

# Expected current filename:
#
# fop2-509-509-20261014-120757-1791972469.156-GENERAL.wav
#
# Groups:
#   owner   = 509
#   target  = 509
#   date    = 20261014
#   time    = 120757
#   uid     = 1791972469.156
#   context = GENERAL

my ($owner, $target, $datestr, $timestr, $file_uid, $context);

if ($basename =~
    /^fop2-([^-]+)-([^-]+)-(\d{8})-(\d{6})-([^-]+)-(.+)\.wav$/i)
{
    ($owner, $target, $datestr, $timestr, $file_uid, $context)
        = ($1, $2, $3, $4, $5, $6);
}
else {
    fail("Unexpected FOP2 filename: $basename");
}

# The explicitly supplied FOP2/Asterisk uniqueid is authoritative.
if ($file_uid ne $uniqueid) {
    logmsg(
        "WARNING: filename uniqueid '$file_uid' differs from argument '$uniqueid'"
    );
}

my ($year, $month, $day) =
    $datestr =~ /^(\d{4})(\d{2})(\d{2})$/;

my ($hour, $minute, $second) =
    $timestr =~ /^(\d{2})(\d{2})(\d{2})$/;

defined $year && defined $hour
    or fail("Invalid date/time in filename: $basename");

my $datetime =
    "$year-$month-$day $hour:$minute:$second";

my $destdir =
    "$DESTFOLDER/$year/$month/$day";

my $destination =
    "$destdir/$basename";

# ------------------------------------------------------------
# Determine duration.
# Use soxi if available. It handles modern WAV layouts much more
# robustly than manually parsing fixed WAV header offsets.
# ------------------------------------------------------------

my $duration = 0;

my $soxi = `command -v soxi 2>/dev/null`;
chomp $soxi;

if ($soxi && -x $soxi) {
    my $seconds = `$soxi -D "$source" 2>/dev/null`;
    chomp $seconds;

    if ($seconds =~ /^\d+(?:\.\d+)?$/) {
        $duration = int($seconds + 0.5);
    }
}

logmsg(
    "START source='$source' uniqueid='$uniqueid' owner='$owner' " .
    "target='$target' context='$context' duration=$duration"
);

# ------------------------------------------------------------
# FreePBX database credentials
# ------------------------------------------------------------

my $freepbx_conf = "/etc/freepbx.conf";

-f $freepbx_conf
    or fail("$freepbx_conf not found");

my %db;

open my $cfh, '<', $freepbx_conf
    or fail("Cannot open $freepbx_conf: $!");

while (my $line = <$cfh>) {
    if ($line =~ /^\$amp_conf\["(AMPDB(?:USER|PASS|HOST|NAME))"\]\s*=\s*"([^"]*)"/) {
        $db{$1} = $2;
    }
}

close $cfh;

for my $key (qw(AMPDBUSER AMPDBPASS AMPDBHOST AMPDBNAME)) {
    defined $db{$key}
        or fail("$key not found in $freepbx_conf");
}

my $dsn =
        "DBI:MariaDB:database=$db{AMPDBNAME};host=$db{AMPDBHOST}";
        
my $dbh = DBI->connect(
    $dsn,
    $db{AMPDBUSER},
    $db{AMPDBPASS},
    {
        RaiseError         => 1,
        PrintError         => 0,
        AutoCommit         => 1,
    }
) or fail("Database connection failed");

# ------------------------------------------------------------
# We identify a recording by uniqueid + final filename.
#
# Multiple recordings may legitimately share the same Asterisk
# uniqueid (e.g. automatic MixMonitor + FOP2 on-demand).
# ------------------------------------------------------------

my $check = $dbh->prepare(q{
    SELECT id
      FROM fop2recordings
     WHERE uniqueid = ?
     LIMIT 1
});

$check->execute($uniqueid);

if (my ($existing_id) = $check->fetchrow_array) {
    if (-f $source) {
    logmsg(
        "SKIP already indexed id=$existing_id and delete '$source'"
    );
        unlink $source
            or fail("Already indexed, but cannot delete redundant recording '$source': $!");
    }
    $dbh->disconnect;
    exit 0;
}

# ------------------------------------------------------------
# Move recording into FreePBX date directory
# ------------------------------------------------------------

if (!-d $destdir) {
    make_path($destdir, { mode => 0775 })
        or fail("Cannot create directory: $destdir");
}

if (-e $destination) {
    fail("Destination already exists but is not indexed: $destination");
}

move($source, $destination)
    or fail("Cannot move '$source' to '$destination': $!");

# ------------------------------------------------------------
# Insert into FOP2 recording index
# ------------------------------------------------------------

my $insert = $dbh->prepare(q{
    INSERT INTO fop2recordings
        (
            uniqueid,
            datetime,
            ownerextension,
            targetextension,
            filename,
            duration,
            context
        )
    VALUES (?, ?, ?, ?, ?, ?, ?)
});

eval {
    $insert->execute(
        $uniqueid,
        $datetime,
        $owner,
        $target,
        $destination,
        $duration,
        $context
    );
};

if ($@) {
    my $error = $@;

    # Do not lose the recording if the database insert fails.
    if (-e $destination && !-e $source) {
        if (move($destination, $source)) {
            logmsg("ROLLBACK moved recording back to '$source'");
        }
        else {
            logmsg(
                "ERROR: DB insert failed AND recording could not be moved back"
            );
        }
    }

    $dbh->disconnect;
    fail("Database insert failed: $error");
}


$dbh->disconnect;

logmsg(
    "OK uniqueid='$uniqueid' destination='$destination'"
);

exit 0;

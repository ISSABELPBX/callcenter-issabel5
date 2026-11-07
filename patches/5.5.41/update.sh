#!/bin/bash
# Database and configuration changes for the 5.5.41 update.
#
# Widens the call_center columns that hold Asterisk channel names, trunk dial
# strings and caller IDs. With STRICT_TRANS_TABLES (the MariaDB 10.2+ default,
# Issabel 5) a value longer than its column aborts the whole statement instead
# of being truncated as on MariaDB 5.5: an Asterisk 20 channel such as
# PJSIP/<trunk>-0000001a or Local/...;1 does not fit current_calls.Channel
# varchar(32), a custom trunk template does not fit trunk varchar(50), and a
# caller ID longer than 15 characters makes the incoming call's call_entry
# INSERT fail.
#
# Each column is widened only when it is narrower than the target, so a column
# an admin already widened further is never shrunk. The widened column gets
# the NULL/NOT NULL and DEFAULT that setup/call_center.sql defines for it. Some
# boxes have a nullable call_entry.trunk (the 2025-01-21 widening to
# varchar(50) left out NOT NULL); before a NOT NULL target is applied, its NULL
# rows are set to '', which is what the dialer writes, otherwise strict mode
# would fail the ALTER on every run.
#
# Each ALTER copies its table (utf8mb4 lengths cross the 255-byte prefix, so
# MariaDB cannot change the type in place), and dialer writes to that table
# wait meanwhile. On a box with a large call history, run the update off-hours.
#
# The MySQL root password is read from /etc/issabel.conf at run time and
# travels in MYSQL_PWD only - never on a command line, never in a log line,
# never printed. mysql is called as -u root with no host and no protocol
# switch, so the connection is the local socket.
#
# Idempotent: a column already at or above its target length is left alone,
# so a re-applied version changes nothing.
#
# The module files of this version are plain copies under files/.
#
# An update.sh is mandatory in every patches/<version>/ directory: see
# patches/README.md.

V=5.5.41

PW="$(sed -n 's/^mysqlrootpwd=//p' /etc/issabel.conf | tr -d '\r')"
if [ -z "$PW" ]; then
    echo "$V: cannot read mysqlrootpwd from /etc/issabel.conf" >&2
    echo "Es: no se puede leer mysqlrootpwd de /etc/issabel.conf" >&2
    exit 1
fi

mysql_run() { MYSQL_PWD="$PW" mysql -u root "$@"; }

# table column length definition-after-the-type (as in setup/call_center.sql)
COLUMNS="
calls trunk 255 NULL
call_entry trunk 255 NOT NULL
call_progress_log trunk 255 NULL
current_call_entry ChannelClient 255 NULL
current_calls ChannelClient 255 NULL
current_calls Channel 255 NOT NULL DEFAULT ''
call_entry callerid 80 NOT NULL
current_call_entry callerid 80 NOT NULL
"

echo "$COLUMNS" | while read -r TABLE COLUMN LEN REST; do
    [ -n "$TABLE" ] || continue
    CUR="$(mysql_run -N -B -e "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='call_center' AND TABLE_NAME='$TABLE' AND COLUMN_NAME='$COLUMN'")"
    if [ $? -ne 0 ]; then
        echo "$V: could not read the length of call_center.$TABLE.$COLUMN" >&2
        echo "Es: no se pudo leer la longitud de call_center.$TABLE.$COLUMN" >&2
        exit 1
    fi
    if [ -z "$CUR" ]; then
        echo "$V: call_center.$TABLE.$COLUMN not found - skipped | Es: call_center.$TABLE.$COLUMN no existe - se omite"
        continue
    fi
    if [ "$CUR" -ge "$LEN" ]; then
        echo "$V: call_center.$TABLE.$COLUMN is already varchar($CUR) | Es: call_center.$TABLE.$COLUMN ya es varchar($CUR)"
        continue
    fi
    case "$REST" in
    "NOT NULL"*)
        if ! mysql_run call_center -e "UPDATE \`$TABLE\` SET \`$COLUMN\` = '' WHERE \`$COLUMN\` IS NULL"; then
            echo "$V: could not clear NULL values in call_center.$TABLE.$COLUMN" >&2
            echo "Es: no se pudieron limpiar los valores NULL de call_center.$TABLE.$COLUMN" >&2
            exit 1
        fi
        ;;
    esac
    if ! mysql_run call_center -e "ALTER TABLE \`$TABLE\` MODIFY \`$COLUMN\` varchar($LEN) $REST"; then
        echo "$V: could not widen call_center.$TABLE.$COLUMN to varchar($LEN)" >&2
        echo "Es: no se pudo ampliar call_center.$TABLE.$COLUMN a varchar($LEN)" >&2
        exit 1
    fi
    echo "$V: call_center.$TABLE.$COLUMN widened from varchar($CUR) to varchar($LEN) | Es: call_center.$TABLE.$COLUMN ampliado de varchar($CUR) a varchar($LEN)"
done || exit 1

# Closing assertion: every column must now be at least its target length.
# Failing here stops the whole update (the updater resumes on a re-run from
# this version).
SHORT="$(mysql_run -N -B -e "SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='call_center' AND ((COLUMN_NAME='trunk' AND TABLE_NAME IN ('calls','call_entry','call_progress_log') AND CHARACTER_MAXIMUM_LENGTH < 255) OR (COLUMN_NAME IN ('Channel','ChannelClient') AND TABLE_NAME IN ('current_calls','current_call_entry') AND CHARACTER_MAXIMUM_LENGTH < 255) OR (COLUMN_NAME='callerid' AND TABLE_NAME IN ('call_entry','current_call_entry') AND CHARACTER_MAXIMUM_LENGTH < 80))")"
if [ -n "$SHORT" ]; then
    echo "$V: columns still too narrow: $SHORT" >&2
    echo "Es: columnas aún demasiado cortas: $SHORT" >&2
    exit 1
fi

exit 0

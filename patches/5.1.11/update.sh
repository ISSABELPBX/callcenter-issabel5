#!/bin/bash
# Database and configuration changes for the 5.1.11 update.
#
# Creates campaign.id_url2/id_url3 and campaign_entry.id_url2/id_url3 with
# their indexes and named foreign keys, on boxes installed before those
# columns entered setup/call_center.sql. The columns, indexes and constraint
# names are exactly the schema's, so a box the old page-load migrator already
# altered - it created the same names - is left untouched, and so is a re-run
# of this script: a column that exists is only reported.
#
# The MySQL root password is read from /etc/issabel.conf at run time and
# carried in MYSQL_PWD only: never an argument, never printed. An unreadable
# answer from the database aborts the update - it must never pass for "the
# column already exists".
#
# An update.sh is mandatory in every patches/<version>/ directory: see
# patches/README.md.

PW="$(sed -n 's/^mysqlrootpwd=//p' /etc/issabel.conf | tr -d '\r')"
if [ -z "$PW" ]; then
    echo "5.1.11: cannot read mysqlrootpwd from /etc/issabel.conf" >&2
    echo "Es: no se puede leer mysqlrootpwd de /etc/issabel.conf" >&2
    exit 1
fi

mysql_run() { MYSQL_PWD="$PW" mysql -u root "$@"; }

# col_exists <WHERE fragment on one column>: prints INFORMATION_SCHEMA's
# COUNT (0 or 1) for that column, or exits when the answer is not readable.
col_exists() {
    local n
    n="$(mysql_run -N -B -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE $1;")" || {
        echo "5.1.11: could not read INFORMATION_SCHEMA ($1)" >&2
        echo "Es: no se pudo leer INFORMATION_SCHEMA ($1)" >&2
        exit 1
    }
    case "$n" in
        0|1) printf '%s' "$n" ;;
        *)
            echo "5.1.11: unexpected answer from INFORMATION_SCHEMA: '$n'" >&2
            echo "Es: respuesta inesperada de INFORMATION_SCHEMA: '$n'" >&2
            exit 1 ;;
    esac
}

# alter <table.column> <ALTER clause>: applies the clause or fails the update.
alter() {
    mysql_run call_center -e "ALTER TABLE $2" || {
        echo "5.1.11: ALTER failed for $1" >&2
        echo "Es: falló el ALTER de $1" >&2
        exit 1
    }
    echo "5.1.11: added $1 | Es: agregado $1"
}

# --- campaign.id_url2 -------------------------------------------------------
n="$(col_exists "TABLE_SCHEMA = 'call_center' AND TABLE_NAME = 'campaign' AND COLUMN_NAME = 'id_url2'")" || exit 1
if [ "$n" = "0" ]; then
    alter campaign.id_url2 "campaign ADD COLUMN id_url2 int unsigned, ADD INDEX id_url2 (id_url2), ADD CONSTRAINT campaign_ibfk_2 FOREIGN KEY (id_url2) REFERENCES campaign_external_url (id)"
else
    echo "5.1.11: campaign.id_url2 already present | Es: campaign.id_url2 ya existe"
fi

# --- campaign.id_url3 -------------------------------------------------------
n="$(col_exists "TABLE_SCHEMA = 'call_center' AND TABLE_NAME = 'campaign' AND COLUMN_NAME = 'id_url3'")" || exit 1
if [ "$n" = "0" ]; then
    alter campaign.id_url3 "campaign ADD COLUMN id_url3 int unsigned, ADD INDEX id_url3 (id_url3), ADD CONSTRAINT campaign_ibfk_3 FOREIGN KEY (id_url3) REFERENCES campaign_external_url (id)"
else
    echo "5.1.11: campaign.id_url3 already present | Es: campaign.id_url3 ya existe"
fi

# --- campaign_entry.id_url2 -------------------------------------------------
n="$(col_exists "TABLE_SCHEMA = 'call_center' AND TABLE_NAME = 'campaign_entry' AND COLUMN_NAME = 'id_url2'")" || exit 1
if [ "$n" = "0" ]; then
    alter campaign_entry.id_url2 "campaign_entry ADD COLUMN id_url2 int unsigned, ADD INDEX id_url2 (id_url2), ADD CONSTRAINT campaign_entry_ibfk_4 FOREIGN KEY (id_url2) REFERENCES campaign_external_url (id)"
else
    echo "5.1.11: campaign_entry.id_url2 already present | Es: campaign_entry.id_url2 ya existe"
fi

# --- campaign_entry.id_url3 -------------------------------------------------
n="$(col_exists "TABLE_SCHEMA = 'call_center' AND TABLE_NAME = 'campaign_entry' AND COLUMN_NAME = 'id_url3'")" || exit 1
if [ "$n" = "0" ]; then
    alter campaign_entry.id_url3 "campaign_entry ADD COLUMN id_url3 int unsigned, ADD INDEX id_url3 (id_url3), ADD CONSTRAINT campaign_entry_ibfk_5 FOREIGN KEY (id_url3) REFERENCES campaign_external_url (id)"
else
    echo "5.1.11: campaign_entry.id_url3 already present | Es: campaign_entry.id_url3 ya existe"
fi

exit 0

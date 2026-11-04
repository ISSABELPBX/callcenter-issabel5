#!/bin/bash
# Database and configuration changes for the 5.5.40 update.
#
# The module's database user becomes its own callcenter@localhost, with the
# password the DSNs and dialerd.conf carry. The stock asterisk MySQL account
# is never touched: it keeps whatever grants and password it has, and the
# call_center grant older installs gave it stays (unused, and not ours to
# take back) - a GRANT ... IDENTIFIED BY on that shared name would also reset
# its password, which is why the module stops using it.
#
# The MySQL root password is read from /etc/issabel.conf at run time and
# travels in MYSQL_PWD only - never on a command line, never in a log line,
# never printed. mysql is called as -u root with no host and no protocol
# switch, so the connection is the local socket.
#
# Idempotent: every statement is CREATE USER IF NOT EXISTS / ALTER USER /
# GRANT with fixed values, so a re-applied version changes nothing.
#
# An update.sh is mandatory in every patches/<version>/ directory: see
# patches/README.md.

V=5.5.40

PW="$(sed -n 's/^mysqlrootpwd=//p' /etc/issabel.conf | tr -d '\r')"
if [ -z "$PW" ]; then
    echo "$V: cannot read mysqlrootpwd from /etc/issabel.conf" >&2
    echo "Es: no se puede leer mysqlrootpwd de /etc/issabel.conf" >&2
    exit 1
fi

mysql_run() { MYSQL_PWD="$PW" mysql -u root "$@"; }

# The dedicated account: created if missing, its password re-pinned (an admin
# may have changed it), granted on call_center only.
mysql_run -e "CREATE USER IF NOT EXISTS 'callcenter'@'localhost' IDENTIFIED BY 'callcenter'; ALTER USER 'callcenter'@'localhost' IDENTIFIED BY 'callcenter'; GRANT ALL ON call_center.* TO 'callcenter'@'localhost'; FLUSH PRIVILEGES;"
if [ $? -ne 0 ]; then
    echo "$V: could not create or grant the callcenter@localhost database user" >&2
    echo "Es: no se pudo crear ni conceder permisos al usuario de base de datos callcenter@localhost" >&2
    exit 1
fi
echo "$V: database user callcenter@localhost granted on call_center | Es: usuario de base de datos callcenter@localhost con permisos sobre call_center"

# dialerd.conf: the two keys are migrated in place. The file is the box's own
# and may carry local settings (campaign_workers), so it is never overwritten
# whole - the plain copy under files/ is the module configs only.
DIALERD_CONF=/opt/issabel/dialer/dialerd.conf
if [ ! -f "$DIALERD_CONF" ]; then
    echo "$V: no dialerd.conf at $DIALERD_CONF - nothing to migrate" >&2
    echo "Es: no hay dialerd.conf en $DIALERD_CONF - nada que migrar" >&2
    exit 1
fi
sed -i -e 's/^dbuser=.*/dbuser=callcenter/' -e 's/^dbpass=.*/dbpass=callcenter/' "$DIALERD_CONF"
echo "$V: dialerd.conf now uses the callcenter database user | Es: dialerd.conf ahora usa el usuario de base de datos callcenter"

# Closing assertion: the grant the dialer and the web modules depend on must
# be there. Failing here stops the whole update (the updater resumes on a
# re-run from this version).
LEFT="$(mysql_run -N -B -e "SHOW GRANTS FOR 'callcenter'@'localhost'" 2>/dev/null | grep -c 'ALL PRIVILEGES ON `call_center`')"
if [ "$LEFT" -lt 1 ]; then
    echo "$V: callcenter@localhost does not hold the call_center grant" >&2
    echo "Es: callcenter@localhost no tiene los permisos sobre call_center" >&2
    exit 1
fi

exit 0

#!/bin/bash
# Database and configuration changes for the 5.3.33 update.
#
# The agentconsole ECCP client is given a random password where it still
# carries the well-known shipped default MD5('agentconsole'), and is created
# (also random) where the row is missing - so no install keeps or opens the
# default. A password an admin set in Call Center -> ECCP Users is a
# different hash, matches neither arm, and is never touched. Nobody needs
# the plaintext: the agent console and the agents pages read the hash from
# the table and send it, and Request_login accepts the hash itself.
#
# The new hash is generated with PHP's CSPRNG and reaches the server only on
# stdin - never on a command line, never in a log line, never printed. The
# MySQL root password is read from /etc/issabel.conf at run time and travels
# in MYSQL_PWD only. mysql is called as -u root with no host and no
# protocol switch, so the connection is the local socket.
#
# Idempotent: a re-run finds no default row left and changes nothing, so a
# re-applied version is harmless. Duplicate agentconsole rows (the old
# lazy-insert race) all still at the default are replaced with the one hash.
#
# An update.sh is mandatory in every patches/<version>/ directory: see
# patches/README.md.

V=5.3.33

PW="$(sed -n 's/^mysqlrootpwd=//p' /etc/issabel.conf | tr -d '\r')"
if [ -z "$PW" ]; then
    echo "$V: cannot read mysqlrootpwd from /etc/issabel.conf" >&2
    echo "Es: no se puede leer mysqlrootpwd de /etc/issabel.conf" >&2
    exit 1
fi

mysql_run() { MYSQL_PWD="$PW" mysql -u root "$@"; }

# One random 32-hex md5-shaped value drives both statements. random_bytes is
# PHP's CSPRNG; the value is validated because everything downstream assumes
# the shape, and it is never echoed anywhere.
NEWHASH="$(php -r 'echo md5(random_bytes(32));' 2>/dev/null)"
if ! printf '%s' "$NEWHASH" | grep -Eq '^[0-9a-f]{32}$'; then
    echo "$V: could not generate a random password hash" >&2
    echo "Es: no se pudo generar un hash de contrasena aleatorio" >&2
    exit 1
fi

# Read-only counts decide which outcome to report. No secret is involved, so
# -e is fine here; the hash-bearing statements below go on stdin instead.
N_DEFAULT="$(mysql_run -N -B call_center -e "SELECT COUNT(*) FROM eccp_authorized_clients WHERE username = 'agentconsole' AND md5_password = MD5('agentconsole')")"
if [ -z "$N_DEFAULT" ]; then
    echo "$V: could not read eccp_authorized_clients" >&2
    echo "Es: no se pudo leer eccp_authorized_clients" >&2
    exit 1
fi
N_ROW="$(mysql_run -N -B call_center -e "SELECT COUNT(*) FROM eccp_authorized_clients WHERE username = 'agentconsole'")"
if [ -z "$N_ROW" ]; then
    echo "$V: could not read eccp_authorized_clients" >&2
    echo "Es: no se pudo leer eccp_authorized_clients" >&2
    exit 1
fi

if [ "$N_DEFAULT" -gt 0 ]; then
    echo "$V: replacing the agentconsole ECCP password, still the shipped default, with a random one | Es: se reemplaza la contrasena ECCP de agentconsole, todavia la de fabrica, por una aleatoria"
    echo "$V: an external client configured with the old default must be given a password set in Call Center -> ECCP Users | Es: un cliente externo configurado con la de fabrica debe recibir una contrasena fijada en Call Center -> ECCP Users"
elif [ "$N_ROW" -eq 0 ]; then
    echo "$V: creating the agentconsole ECCP client with a random password | Es: se crea el cliente ECCP agentconsole con una contrasena aleatoria"
else
    echo "$V: the agentconsole ECCP password is not the shipped default - left untouched | Es: la contrasena ECCP de agentconsole no es la de fabrica - no se toca"
fi

# The two arms of the policy, aimed at the local server on stdin so the new
# hash never appears in an argument list. The WHERE clauses make a re-run a
# no-op.
printf "UPDATE eccp_authorized_clients SET md5_password = '%s' WHERE username = 'agentconsole' AND md5_password = MD5('agentconsole');\n" \
    "$NEWHASH" \
    | mysql_run call_center
if [ "${PIPESTATUS[1]}" -ne 0 ]; then
    echo "$V: the agentconsole password replacement failed" >&2
    echo "Es: fallo el reemplazo de la contrasena de agentconsole" >&2
    exit 1
fi
printf "INSERT INTO eccp_authorized_clients (username, md5_password) SELECT 'agentconsole', '%s' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eccp_authorized_clients WHERE username = 'agentconsole');\n" \
    "$NEWHASH" \
    | mysql_run call_center
if [ "${PIPESTATUS[1]}" -ne 0 ]; then
    echo "$V: creating the agentconsole ECCP client failed" >&2
    echo "Es: fallo la creacion del cliente ECCP agentconsole" >&2
    exit 1
fi

# Closing assertion: nothing called agentconsole may still answer to the
# shipped default. Failing here stops the whole update (the updater resumes
# on a re-run from this version).
LEFT="$(mysql_run -N -B call_center -e "SELECT COUNT(*) FROM eccp_authorized_clients WHERE username = 'agentconsole' AND md5_password = MD5('agentconsole')")"
if [ "$LEFT" != "0" ]; then
    echo "$V: $LEFT agentconsole row(s) still carry the shipped default password" >&2
    echo "Es: $LEFT fila(s) de agentconsole siguen con la contrasena de fabrica" >&2
    exit 1
fi

exit 0

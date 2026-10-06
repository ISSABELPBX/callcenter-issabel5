#!/bin/bash

RED='\033[0;31m'
NC='\033[0m' # No Color

usage() {
    cat <<EOF
Issabel Call Center uninstaller

Usage: $(basename "$0") [options]

Options:
  -h, --help   Show this help and exit

Run as root. Removes the Issabel Call Center dialer and its web modules, then
asks whether to drop the call_center MySQL database. Answer 'n' to keep your
agents, campaigns, calls, forms, breaks and reports so a later installation can
reuse them; 'y' starts from an empty database.
EOF
}

# Parse arguments before anything is stopped or deleted below.
while [ $# -gt 0 ]; do
    case "$1" in
        -h|--help) usage; exit 0 ;;
        *)
            echo -e "${RED}Error: unknown option '$1'${NC}" >&2
            echo "Run 'bash $0 --help' for usage." >&2
            exit 2
            ;;
    esac
    shift
done


#stop service and disable it
systemctl stop issabeldialer 2>/dev/null || true
systemctl disable issabeldialer 2>/dev/null || true
systemctl daemon-reload 2>/dev/null || true

#remove folder and files
rm -rf /var/www/html/modules/{agent_break,agent_console,agent_journey,agents,break_administrator,callcenter_config}
rm -rf /var/www/html/modules/{calls_detail,calls_per_agent,calls_per_hour,campaign_in,campaign_monitoring,campaign_out}
rm -rf /var/www/html/modules/{cb_extensions,client,dont_call_list,eccp_users,external_url,form_designer,form_list}
rm -rf /var/www/html/modules/{graphic_calls,hold_time,ingoings_calls_success,login_logout,queues}
rm -rf /var/www/html/modules/{rep_agent_information,rep_agents_monitoring,rep_incoming_calls_monitoring}
rm -rf /var/www/html/modules/{rep_incoming_campaigns_panel,rep_outgoing_campaigns_panel,reports_break,rep_trunks_used_per_hour}

#remove ECCP TLS certificate (inlined rather than calling eccp-cert.sh, which
#lives inside the dialer directory removed just below)
rm -f /etc/issabel/dialer/eccp.pem /etc/issabel/dialer/eccp.key
rmdir /etc/issabel/dialer 2>/dev/null
rmdir /etc/issabel 2>/dev/null

#remove dialer
rm -rf /opt/issabel/dialer
rm -f /etc/systemd/system/issabeldialer.service
rm -f /etc/rc.d/init.d/issabeldialer
rm -rf /etc/logrotate.d/issabeldialer
rm -rf /etc/logrotate.d/callcenter-modules
rm -rf /var/log/callcenter-module
rm -rf /usr/bin/issabel-callcenter-local-dnc
rm -rf /usr/share/issabel/module_installer/callcenter/

#remove dashboard ProcessesStatus applet patches
DASHBOARD_DIR="/var/www/html/modules/dashboard/applets/ProcessesStatus"
DASHBOARD_INDEX="$DASHBOARD_DIR/index.php"

if [ -f "$DASHBOARD_INDEX" ]; then
    # Remove Dialer icon mapping
    sed -i "/'Dialer'.*=>.*'icon_headphones.png'/d" "$DASHBOARD_INDEX"
    # Remove Dialer service mapping
    sed -i "/'Dialer'.*=>.*'issabeldialer'/d" "$DASHBOARD_INDEX"
    # Remove Dialer status detection lines
    sed -i '/\$arrSERVICES\["Dialer"\]/d' "$DASHBOARD_INDEX"
    # Remove the icon file
    rm -f "$DASHBOARD_DIR/images/icon_headphones.png"
    echo "Removed dashboard ProcessesStatus patches"
fi

#remove menu
issabel-menuremove call_center

#remove call center contexts from extensions_custom.conf
EXTENSIONS_FILE="/etc/asterisk/extensions_custom.conf"
if [ -f "$EXTENSIONS_FILE" ]; then
    if grep -q "; BEGIN ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE" "$EXTENSIONS_FILE"; then
        sed -i '/^; BEGIN ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE$/,/^; END ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE$/d' "$EXTENSIONS_FILE"
        echo "Removed call center contexts from $EXTENSIONS_FILE"
        # Reload Asterisk dialplan
        asterisk -rx "dialplan reload" 2>/dev/null || true
    fi
fi

#remove the call center parking lot from res_parking_custom_general.conf
PARKING_FILE="/etc/asterisk/res_parking_custom_general.conf"
if [ -f "$PARKING_FILE" ]; then
    if grep -q "; BEGIN ISSABEL CALL-CENTER PARKING LOT DO NOT REMOVE THIS LINE" "$PARKING_FILE"; then
        sed -i '/^; BEGIN ISSABEL CALL-CENTER PARKING LOT DO NOT REMOVE THIS LINE$/,/^; END ISSABEL CALL-CENTER PARKING LOT DO NOT REMOVE THIS LINE$/d' "$PARKING_FILE"
        echo "Removed call center parking lot from $PARKING_FILE"
        # Reload parking so the callcenter_hold lot goes away
        asterisk -rx "module reload res_parking" 2>/dev/null || true
    fi
fi

#remove database
echo ""
read -p "Do you want to delete the call_center database? (y/n): " DELETE_DB
if [ "$DELETE_DB" = "y" ] || [ "$DELETE_DB" = "Y" ]; then
    MYSQL_ROOT_PWD=$(grep '^mysqlrootpwd=' /etc/issabel.conf | cut -d'=' -f2)
    if [ -n "$MYSQL_ROOT_PWD" ]; then
        # MYSQL_PWD, never -p<password>: the password must not appear in the
        # process list (ps) while the drop runs.
        MYSQL_PWD="$MYSQL_ROOT_PWD" mysql -u root -e "DROP DATABASE IF EXISTS call_center;" 2>/dev/null
        if [ $? -eq 0 ]; then
            echo "Database call_center deleted successfully."
        else
            echo "Error deleting database. You can delete it manually."
        fi
    else
        echo "Could not read MySQL root password"
        echo "You can delete the database manually by dropping call_center database"
    fi
    unset MYSQL_ROOT_PWD
else
    echo "Database call_center was not deleted."
fi

echo "Call Center Module removed successfully"

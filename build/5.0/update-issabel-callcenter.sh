#!/bin/bash

# In-place updater for the Issabel Call Center module.
#
# NOTE: the updater OVERWRITES the files it updates - any customizations in
# those files are lost. It asks for 'yes' or 'Y' before changing anything;
# anything else (or end of input) aborts with nothing changed.
#
# It applies, in ascending order, every version under patches/ newer than the
# installed one: first that version's update.sh (database and configuration
# changes), then the files under files/ copied over as-is, and then it writes
# the version to the deployed stamp. A failure leaves the stamp at the last
# version that completed, so a re-run resumes from there.
#
# The call-centre dialer (issabeldialer) is restarted at the end when files
# were copied, and Asterisk is reloaded (core reload), never restarted.

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1090
source "$SCRIPT_DIR/callcenter-common.sh"

STAMP_DIR=/usr/share/issabel/module_installer/callcenter
UPLOG_DIR=/var/log/issabel
UPLOG=$UPLOG_DIR/callcenter-update.log
# The first release that deploys a VERSION stamp and has a complete patch
# chain behind it: an installation older than this cannot be patched.
PATCH_FLOOR=5.1.1

usage() {
    cat <<EOF
Issabel Call Center in-place updater

Usage: $(basename "$0") [options]

Options:
  -h, --help    Show this help and exit
  --dry-run     Print the plan and the note, change nothing, exit 0

Must be run as root, from a full checkout of the repository: the updater
needs menu.xml, VERSION and patches/ two levels above this script.

It applies every version newer than the installed one from patches/<v>/:
that version's update.sh (database and configuration changes) and then the
files under files/, overwritten as-is.

NOTE: the updater OVERWRITES the files it updates - any customizations in
those files are lost. It asks for yes or Y before changing anything;
anything else (or end of input) aborts with nothing changed.

The call-centre dialer (issabeldialer) is restarted at the end when files
were copied, and Asterisk is reloaded, not restarted.

Every run is appended to /var/log/issabel/callcenter-update.log.
EOF
}

# Parse arguments before the root check so --help works for any user.
DRY_RUN=false
while [ $# -gt 0 ]; do
    case "$1" in
        -h|--help)  usage; exit 0 ;;
        --dry-run)  DRY_RUN=true ;;
        *)
            echo -e "${RED}Error: unknown option '$1'${NC}" >&2
            echo "Run 'bash $0 --help' for usage." >&2
            exit 2
            ;;
    esac
    shift
done

if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}Error: this updater must be run as root.${NC}"
    exit 1
fi

# log <english> <spanish>: print the English line to the terminal and append
# "<timestamp> <english> | <spanish>" to the updater's log file.
log() {
    echo "$1"
    mkdir -p "$UPLOG_DIR" 2>/dev/null || true
    echo "$(date '+%Y-%m-%d %H:%M:%S') $1 | $2" >> "$UPLOG" 2>/dev/null || true
}

# A failed file step (mkdir, cp or chown) stops the update, exactly like a
# failed update.sh: that version's stamp is never written, so the stamp stays
# at the last version that completed and a re-run resumes from there.
# $1 = what failed, $2 = the version, $3 = the repo-relative file.
fail_file_step() {
    local stamp_now
    stamp_now="$(cc_read_version "$STAMP_DIR" || echo '?')"
    echo -e "${RED}Error: $1 for patches/$2/files/$3; the update stops here.${NC}"
    echo -e "${YELLOW}The stamp is left at the last version that completed; a re-run resumes from there.${NC}"
    log "failed: $1 for patches/$2/files/$3; stamp stays at $stamp_now" \
        "fallo: $1 en patches/$2/files/$3; el sello queda en $stamp_now"
    exit 1
}

# Resolve and validate the checkout this script lives in.
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
if [ ! -f "$REPO_ROOT/menu.xml" ] || [ ! -f "$REPO_ROOT/VERSION" ] || \
   [ ! -d "$REPO_ROOT/patches" ]; then
    echo -e "${RED}Error: $REPO_ROOT is not a full checkout (menu.xml, VERSION and patches/ expected).${NC}"
    echo "Run the updater from a checkout of the repository:"
    echo "    git clone <repository> && bash build/5.0/update-issabel-callcenter.sh"
    exit 1
fi
REPO_VERSION="$(cc_read_version "$REPO_ROOT")" || REPO_VERSION=''
if ! cc_version_valid "$REPO_VERSION"; then
    echo -e "${RED}Error: the checkout at $REPO_ROOT has no readable VERSION.${NC}"
    exit 1
fi

log "start: update-issabel-callcenter checkout=$REPO_VERSION dry-run=$DRY_RUN" \
    "inicio: update-issabel-callcenter checkout=$REPO_VERSION dry-run=$DRY_RUN"

# The installer's installation markers.
FOUND_MARKERS=""
[ -d /opt/issabel/dialer ] && FOUND_MARKERS="${FOUND_MARKERS}  - /opt/issabel/dialer\n"
[ -f /etc/systemd/system/issabeldialer.service ] && FOUND_MARKERS="${FOUND_MARKERS}  - /etc/systemd/system/issabeldialer.service\n"
[ -f /etc/rc.d/init.d/issabeldialer ] && FOUND_MARKERS="${FOUND_MARKERS}  - /etc/rc.d/init.d/issabeldialer\n"
if rpm -q issabel-callcenter &> /dev/null; then
    FOUND_MARKERS="${FOUND_MARKERS}  - RPM package: $(rpm -q issabel-callcenter)\n"
fi
if [ -z "$FOUND_MARKERS" ]; then
    echo -e "${RED}Error: no Issabel CallCenter installation found on this system.${NC}"
    echo "Install it first:  bash build/5.0/install-issabel-callcenter.sh -l"
    log "refused: no installation found" \
        "rechazado: no se encontró ninguna instalación"
    exit 1
fi

# Prefer the deployed VERSION stamp; fall back to line 1 of the legacy
# deployed CHANGELOG (installations made before VERSION was introduced).
INSTALLED_VERSION="$(cc_read_version "$STAMP_DIR")" || INSTALLED_VERSION=''
if [ -z "$INSTALLED_VERSION" ] && [ -f "$STAMP_DIR/CHANGELOG" ]; then
    INSTALLED_VERSION=$(head -1 "$STAMP_DIR/CHANGELOG" 2>/dev/null | tr -d '\r')
fi

if ! cc_version_valid "$INSTALLED_VERSION"; then
    echo -e "${RED}Error: cannot determine the installed version of the Issabel CallCenter dialer.${NC}"
    echo -e "${YELLOW}Installed version: ${INSTALLED_VERSION:-unknown}${NC}"
    echo -e "${YELLOW}This updater:      ${REPO_VERSION}${NC}"
    echo
    echo "Detected:"
    echo -e "$FOUND_MARKERS"
    echo -e "${YELLOW}Only an installation with a readable version can be updated in place.${NC}"
    echo "Remove the existing installation first, then run the installer again:"
    echo
    echo "    bash ${SCRIPT_DIR}/remove-issabel-callcenter.sh"
    echo
    echo "The removal script asks whether to delete the call_center database."
    echo "Answer 'n' to KEEP your existing data (agents, campaigns, calls, forms,"
    echo "break definitions and reports); the new installation will reuse it."
    log "refused: installed version unreadable (${INSTALLED_VERSION:-unknown})" \
        "rechazado: la versión instalada no es legible (${INSTALLED_VERSION:-unknown})"
    exit 1
fi
if cc_version_lt "$INSTALLED_VERSION" "$PATCH_FLOOR"; then
    echo -e "${RED}Error: the installed version ${INSTALLED_VERSION} is older than ${PATCH_FLOOR} and cannot be updated in place.${NC}"
    echo
    echo "Detected:"
    echo -e "$FOUND_MARKERS"
    echo -e "${YELLOW}Remove the existing installation first, then run the installer again:${NC}"
    echo
    echo "    bash ${SCRIPT_DIR}/remove-issabel-callcenter.sh"
    echo
    echo "The removal script asks whether to delete the call_center database."
    echo "Answer 'n' to KEEP your existing data (agents, campaigns, calls, forms,"
    echo "break definitions and reports); the new installation will reuse it."
    log "refused: installed version $INSTALLED_VERSION below floor $PATCH_FLOOR" \
        "rechazado: la versión instalada $INSTALLED_VERSION es anterior a $PATCH_FLOOR"
    exit 1
fi

if [ "$INSTALLED_VERSION" = "$REPO_VERSION" ]; then
    echo -e "${GREEN}Already at ${INSTALLED_VERSION}; nothing to do.${NC}"
    log "nothing to do: already at $INSTALLED_VERSION" \
        "nada que hacer: ya está en $INSTALLED_VERSION"
    exit 0
fi
if ! cc_version_lt "$INSTALLED_VERSION" "$REPO_VERSION"; then
    echo -e "${YELLOW}Installed ${INSTALLED_VERSION} is newer than this checkout (${REPO_VERSION}); nothing to do.${NC}"
    log "nothing to do: installed $INSTALLED_VERSION newer than checkout $REPO_VERSION" \
        "nada que hacer: la versión instalada $INSTALLED_VERSION es más reciente que el checkout $REPO_VERSION"
    exit 0
fi

# The plan: every version with installed < v <= checkout, ascending.
PLAN_LIST="$(cc_patch_list "$REPO_ROOT/patches" "$INSTALLED_VERSION" "$REPO_VERSION")"

# Validate the whole plan before printing it or touching anything: every
# version dir must carry update.sh (it is mandatory), and every file under
# files/ must map to a box destination.
while IFS= read -r v; do
    [ -n "$v" ] || continue
    pdir="$REPO_ROOT/patches/$v"
    if [ ! -f "$pdir/update.sh" ]; then
        echo -e "${RED}Error: patches/$v/update.sh is missing (it is mandatory in every version directory).${NC}"
        log "refused: patches/$v/update.sh missing" \
            "rechazado: falta patches/$v/update.sh"
        exit 1
    fi
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        if [ -z "$(cc_dest_for "$f")" ]; then
            echo -e "${RED}Error: patches/$v/files/$f has no box destination (it does not ship).${NC}"
            log "refused: patches/$v/files/$f does not map to a destination" \
                "rechazado: patches/$v/files/$f no corresponde a ningún destino"
            exit 1
        fi
    done < <(cc_patch_files "$pdir")
done < <(printf '%s\n' "$PLAN_LIST")

# Print the plan.
echo -e "${GREEN}Installed version: ${INSTALLED_VERSION}${NC}"
echo -e "${GREEN}This checkout:     ${REPO_VERSION}${NC}"
echo
if [ -z "$PLAN_LIST" ]; then
    echo -e "${YELLOW}No patches to apply: the versions between changed nothing on the box.${NC}"
    echo -e "${YELLOW}Only the installed-version stamp is written.${NC}"
    echo
else
    while IFS= read -r v; do
        [ -n "$v" ] || continue
        pdir="$REPO_ROOT/patches/$v"
        nfiles="$(cc_patch_files "$pdir" | wc -l)"
        # No colour on the plan lines: they are machine-parsed ("^Patch <v>:")
        echo "Patch ${v}: ${nfiles} file(s), update.sh"
        while IFS= read -r f; do
            [ -n "$f" ] || continue
            echo "    $f -> $(cc_dest_for "$f")"
        done < <(cc_patch_files "$pdir")
    done < <(printf '%s\n' "$PLAN_LIST")
    echo
fi

echo -e "${YELLOW}NOTE: the updater OVERWRITES the files listed above - any customizations in those files are lost.${NC}"
echo -e "${YELLOW}The call-centre dialer (issabeldialer) is restarted at the end, and Asterisk is reloaded, not restarted.${NC}"
echo

if [ "$DRY_RUN" = true ]; then
    echo -e "${GREEN}Dry run: nothing was changed.${NC}"
    log "dry run: $INSTALLED_VERSION -> $REPO_VERSION, $(printf '%s\n' "$PLAN_LIST" | grep -c . ) patch(es) planned; nothing changed" \
        "dry run: $INSTALLED_VERSION -> $REPO_VERSION, $(printf '%s\n' "$PLAN_LIST" | grep -c . ) parche(s) planificado(s); nada cambió"
    exit 0
fi

read -r -p "Type yes or Y to continue: " ANSWER
if [ "$ANSWER" != "yes" ] && [ "$ANSWER" != "Y" ]; then
    echo -e "${RED}Aborted; nothing was changed.${NC}"
    log "aborted: operator did not confirm (answer '${ANSWER:-<none>}'); nothing was changed" \
        "abortado: el operador no confirmó (respuesta '${ANSWER:-<none>}'); nada fue cambiado"
    exit 1
fi

log "update: $INSTALLED_VERSION -> $REPO_VERSION starting" \
    "actualización: comenzando $INSTALLED_VERSION -> $REPO_VERSION"

# Apply each version in order: update.sh first, then the files, then the
# stamp. A failure stops here with the stamp at the last version completed.
ANY_FILES_COPIED=false
while IFS= read -r v; do
    [ -n "$v" ] || continue
    pdir="$REPO_ROOT/patches/$v"
    echo
    echo -e "${GREEN}Applying patch ${v}...${NC}"

    if ! ( cd "$REPO_ROOT" && CC_PATCH_VERSION="$v" CC_PATCH_DIR="$pdir" \
           CC_REPO_ROOT="$REPO_ROOT" bash "$pdir/update.sh" ); then
        echo -e "${RED}Error: patches/$v/update.sh failed; the update stops here.${NC}"
        echo -e "${YELLOW}The stamp is left at the last version that completed; a re-run resumes from there.${NC}"
        log "failed: patches/$v/update.sh exited non-zero; stamp stays at $(cc_read_version "$STAMP_DIR" || echo '?')" \
            "fallo: patches/$v/update.sh terminó con error; el sello queda en $(cc_read_version "$STAMP_DIR" || echo '?')"
        exit 1
    fi
    log "patch $v: update.sh ok" "parche $v: update.sh ok"

    while IFS= read -r f; do
        [ -n "$f" ] || continue
        dest="$(cc_dest_for "$f")"
        mkdir -p "$(dirname "$dest")" \
            || fail_file_step "cannot create directory $(dirname "$dest")" "$v" "$f"
        /bin/cp -pf "$pdir/files/$f" "$dest" \
            || fail_file_step "cannot copy to $dest" "$v" "$f"
        case "$dest" in
            /var/www/html/modules/*|/opt/issabel/dialer/*|/usr/share/issabel/module_installer/callcenter/*)
                chown asterisk:asterisk "$dest" \
                    || fail_file_step "cannot set owner on $dest" "$v" "$f" ;;
        esac
        case "$dest" in
            /opt/issabel/dialer/dialerd) chmod 755 "$dest" ;;
            /opt/issabel/dialer/*.sh)    chmod 755 "$dest" ;;
        esac
        case "$f" in
            setup/dialer_process/issabeldialer.service)
                systemctl daemon-reload ;;
            menu.xml)
                issabel-menumerge "$dest" ;;
            setup/issabel-sse.conf)
                [ -f /etc/rocky-release ] && systemctl reload httpd 2>/dev/null || true ;;
        esac
        log "patch $v: copied $f -> $dest" "parche $v: copiado $f -> $dest"
        ANY_FILES_COPIED=true
    done < <(cc_patch_files "$pdir")

    printf '%s\n' "$v" > "$STAMP_DIR/VERSION"
    log "patch $v: stamp written" "parche $v: sello escrito"
done < <(printf '%s\n' "$PLAN_LIST")

# The box now matches the checkout even when the versions in between carried
# no patch directory.
printf '%s\n' "$REPO_VERSION" > "$STAMP_DIR/VERSION"
log "stamp written: $REPO_VERSION" "sello escrito: $REPO_VERSION"

if [ "$ANY_FILES_COPIED" = true ]; then
    echo
    echo -e "${GREEN}Restarting issabeldialer service...${NC}"
    systemctl restart issabeldialer
    # The update is only useful if the dialer actually came back: a service
    # that fails to start leaves the box at the new version but out of
    # service, which must be said, not passed over silently.
    if ! systemctl is-active --quiet issabeldialer; then
        echo -e "${RED}Error: the files and the version stamp were updated, but the issabeldialer service did not start.${NC}"
        echo -e "${YELLOW}Check why it failed with:  journalctl -u issabeldialer${NC}"
        log "failed: issabeldialer not active after restart; files and stamp updated to $REPO_VERSION" \
            "fallo: issabeldialer no está activo tras el reinicio; archivos y sello actualizados a $REPO_VERSION"
        exit 1
    fi
    asterisk -rx 'core reload' 2>/dev/null || true
else
    echo
    echo -e "${GREEN}No files were copied; services are not restarted.${NC}"
fi

echo
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}Issabel CallCenter updated to ${REPO_VERSION}${NC}"
echo -e "${GREEN}============================================${NC}"
log "end: updated to $REPO_VERSION" "fin: actualizado a $REPO_VERSION"
exit 0

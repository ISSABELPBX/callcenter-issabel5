#!/bin/bash

# Keep each file under patches/ only in the newest version that ships it.
#
# The updater applies every version newer than the installed one, oldest
# first, so of two copies of one repo path the newer one always lands last
# and decides what the box ends with. Any box that would apply the older copy
# also applies the newer one, so the older copy is never what a box keeps:
# it is removed here. A version directory left with no file keeps its
# update.sh, which is mandatory, and loses its empty files/.
#
#   bash build/5.0/prune-patches.sh            remove the older copies
#   bash build/5.0/prune-patches.sh --check    list them, change nothing;
#                                              exit 1 when there is any
#
# A repo tool: it never ships to the box. Every version directory counts,
# including one for a release not yet stamped in VERSION.

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1090
source "$SCRIPT_DIR/callcenter-common.sh"

CHECK=false
case "${1:-}" in
    '')         ;;
    --check)    CHECK=true ;;
    -h|--help)  sed -n '3,17p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)
        echo -e "${RED}Error: unknown option '$1'${NC}" >&2
        exit 2
        ;;
esac

REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
PATCHES="$REPO_ROOT/patches"
if [ ! -d "$PATCHES" ]; then
    echo -e "${RED}Error: $PATCHES not found.${NC}" >&2
    exit 1
fi

# Every version directory, newest first.
VERSIONS="$(for d in "$PATCHES"/*/; do
    [ -d "$d" ] || continue
    v="$(basename "$d")"
    if cc_version_valid "$v"; then printf '%s\n' "$v"; fi
done | LC_ALL=C sort -t. -k1,1nr -k2,2nr -k3,3nr -k4,4nr)"

# Repo paths already seen in a newer version, and where.
declare -A NEWEST
N=0
while IFS= read -r v; do
    [ -n "$v" ] || continue
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        if [ -z "${NEWEST[$f]:-}" ]; then
            NEWEST[$f]="$v"
            continue
        fi
        N=$((N + 1))
        if [ "$CHECK" = true ]; then
            echo "older copy: patches/$v/files/$f (newest in ${NEWEST[$f]})"
        else
            /bin/rm -f "$PATCHES/$v/files/$f" || exit 1
            echo "removed:    patches/$v/files/$f (newest in ${NEWEST[$f]})"
        fi
    done < <(cc_patch_files "$PATCHES/$v")
    if [ "$CHECK" = false ] && [ -d "$PATCHES/$v/files" ]; then
        find "$PATCHES/$v/files" -depth -type d -empty -delete || exit 1
    fi
done < <(printf '%s\n' "$VERSIONS")

if [ "$CHECK" = true ]; then
    if [ "$N" -gt 0 ]; then
        echo -e "${RED}$N older cop$([ "$N" -eq 1 ] && echo y || echo ies) under patches/; run: bash build/5.0/prune-patches.sh${NC}"
        exit 1
    fi
    echo -e "${GREEN}patches/: every file is in the newest version that ships it${NC}"
else
    echo -e "${GREEN}patches/: $N older cop$([ "$N" -eq 1 ] && echo y || echo ies) removed${NC}"
fi
exit 0

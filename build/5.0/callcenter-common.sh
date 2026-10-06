#!/bin/bash
# callcenter-common.sh - shared pure functions for the Call Center installer
# and updater (build/5.0/install-issabel-callcenter.sh and
# build/5.0/update-issabel-callcenter.sh).
#
# Sourcing this file must have NO side effects: it only defines functions, so
# a test can source it and exercise them against a scratch tree.

# cc_read_version <dir>
# First line of <dir>/VERSION with \r and blanks stripped; return 1 when the
# file is absent or the result is empty.
cc_read_version() {
    local f="$1/VERSION" v
    [ -f "$f" ] || return 1
    v="$(head -1 "$f" 2>/dev/null | tr -d '\r' | tr -d '[:space:]')"
    [ -n "$v" ] || return 1
    printf '%s' "$v"
}

# cc_version_valid <v>
# True when <v> looks like a release number: dotted numeric fields with an
# optional numeric suffix (5.1.3, 5.1, 5.0.0-10). Anything else - prose such
# as a stray CHANGELOG line, or "unknown" - is rejected.
cc_version_valid() {
    [ "$#" -ge 1 ] || return 1
    printf '%s' "$1" | grep -Eq '^[0-9]+(\.[0-9]+){1,3}(-[0-9]+)?$'
}

# cc_version_lt <a> <b>
# True iff release a is older than release b. Any "-N" suffix is dropped,
# dot fields are compared numerically (so 5.1.9 < 5.1.10), and a missing
# field counts as 0 (so 5.1 < 5.1.1).
cc_version_lt() {
    local a="${1%%-*}" b="${2%%-*}" i x y
    for i in 1 2 3 4; do
        x="$(printf '%s' "$a" | cut -d. -f$i)"; x="${x:-0}"
        y="$(printf '%s' "$b" | cut -d. -f$i)"; y="${y:-0}"
        if [ "$x" -ne "$y" ]; then [ "$x" -lt "$y" ]; return; fi
    done
    return 1
}

# cc_patch_list <patches_dir> <installed> <repo>
# The names of the subdirectories of <patches_dir> that are valid versions
# with installed < v <= repo, one per line, ascending. Non-version entries
# (a README, a work-in-progress directory of the next release) are ignored.
cc_patch_list() {
    local dir="$1" installed="$2" repo="$3" v
    [ -d "$dir" ] || return 0
    for v in "$dir"/*/; do
        [ -d "$v" ] || continue
        v="$(basename "$v")"
        cc_version_valid "$v" || continue
        cc_version_lt "$installed" "$v" || continue
        cc_version_lt "$repo" "$v" && continue
        printf '%s\n' "$v"
    done | LC_ALL=C sort -t. -k1,1n -k2,2n -k3,3n -k4,4n
}

# cc_patch_files <version_dir>
# The repo-relative paths of the regular files under <version_dir>/files/,
# LC_ALL=C sorted, one per line. Empty when files/ (or the dir) is missing.
# update.sh lives at the version dir root and is never listed here.
cc_patch_files() {
    [ -d "$1/files" ] || return 0
    ( cd "$1/files" 2>/dev/null && find . -type f | sed 's|^\./||' ) | LC_ALL=C sort
}

# cc_dest_for <repo path>
# The box destination a repo-relative path ships to, or return 1 with no
# output when the path does not ship (docs, build/, patches/, VERSION - the
# version stamp is written by the updater, never copied). The mapping is the
# installer's own copy layout.
cc_dest_for() {
    local p="$1"
    case "$p" in
        modules/*)
            printf '/var/www/html/modules/%s\n' "${p#modules/}" ;;
        setup/dialer_process/dialer/*)
            printf '/opt/issabel/dialer/%s\n' "${p#setup/dialer_process/dialer/}" ;;
        setup/dialer_process/issabeldialer.service)
            printf '/etc/systemd/system/issabeldialer.service\n' ;;
        setup/issabeldialer.logrotate)
            printf '/etc/logrotate.d/issabeldialer\n' ;;
        setup/callcenter-modules.logrotate)
            printf '/etc/logrotate.d/callcenter-modules\n' ;;
        setup/issabel-sse.conf)
            printf '/etc/httpd/conf.d/issabel-sse.conf\n' ;;
        setup/usr/bin/*)
            printf '/usr/bin/%s\n' "${p#setup/usr/bin/}" ;;
        setup/*)
            printf '/usr/share/issabel/module_installer/callcenter/setup/%s\n' "${p#setup/}" ;;
        menu.xml)
            printf '/usr/share/issabel/module_installer/callcenter/menu.xml\n' ;;
        CHANGELOG_OLD.md)
            printf '/usr/share/issabel/module_installer/callcenter/CHANGELOG_OLD.md\n' ;;
        *)
            return 1 ;;
    esac
}

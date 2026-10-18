#!/bin/bash
# build-rpm.sh - build the issabel-callcenter5 RPM from this checkout, one
# command. This is the entry point a CI job calls on a version bump.
#
#   bash build/5.0/build-rpm.sh
#
# The package name is issabel-callcenter5 on purpose: it is neither an
# alternative nor an update for issabel-callcenter, the previous package.
# The Version is the WHOLE VERSION string (5.1.15) - the single source of
# truth; the spec carries no version literal. The Release is a rebuild
# counter, separate from VERSION: 1 for the first build of a version, then
# 2, 3, ... for rebuilds of the same one, plus the OS tag the build host
# adds. Not every VERSION bump needs an RPM: the CI job that will call this
# builds one only on a major release, and the counter is what tells two
# builds of that release apart.
# Environment overrides:
#   CC_RPM_TOPDIR    where to build (default <repo>/build/rpm, gitignored);
#                    SOURCES, BUILD and RPMS land under it
#   CC_RPM_RELEASE   the rebuild counter (default 1, digits only)
#
# The tarball is staged from the checkout with /bin/cp -a, never git archive:
# git normalises directory modes to 0775, and those modes would reach the
# package's directories through the buildroot.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/callcenter-common.sh"

REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
for f in menu.xml VERSION modules setup; do
    [ -e "$REPO_ROOT/$f" ] || {
        echo "Error: $REPO_ROOT is not a full checkout (no $f)." >&2
        exit 1
    }
done

V="$(cc_read_version "$REPO_ROOT")" || V=''
if ! cc_version_valid "$V"; then
    echo "Error: no readable release number in $REPO_ROOT/VERSION (${V:-none})." >&2
    exit 1
fi
case "$V" in
    *-*)
        echo "Error: $REPO_ROOT/VERSION ($V) carries a '-'; an rpm Version field must not." >&2
        exit 1 ;;
esac

# The rebuild counter: digits only, so it can never look like part of the
# version or smuggle a dash into the Release field.
R="${CC_RPM_RELEASE:-1}"
case "$R" in
    *[!0-9]*)
        echo "Error: CC_RPM_RELEASE must be a plain number (the rebuild counter), got '$R'." >&2
        exit 1 ;;
esac

cc_version="$V"
cc_release="$R"

DATE="$(LC_ALL=C date +'%a %b %d %Y')"
TOPDIR="${CC_RPM_TOPDIR:-$REPO_ROOT/build/rpm}"
mkdir -p "$TOPDIR"/BUILD "$TOPDIR"/SOURCES "$TOPDIR"/SPECS "$TOPDIR"/RPMS "$TOPDIR"/SRPMS

# Stage exactly what ships: the web modules, setup/, menu.xml and the release
# number. CHANGELOG_OLD.md is deliberately NOT staged: the changelog points
# to the repository, and it is the shell installer - not the RPM - that
# deploys it. Nothing else from the checkout reaches the tarball. The
# staging directory and the tarball carry the full VERSION.
STAGE_PARENT="$TOPDIR/stage"
STAGE="$STAGE_PARENT/issabel-callcenter5_${V}"
/bin/rm -rf "$STAGE_PARENT"
mkdir -p "$STAGE"
/bin/cp -a "$REPO_ROOT/modules" "$REPO_ROOT/setup" "$REPO_ROOT/menu.xml" \
          "$REPO_ROOT/VERSION" "$STAGE/"

TARBALL="$TOPDIR/SOURCES/callcenter5_${V}.tgz"
tar -C "$STAGE_PARENT" -czf "$TARBALL" "issabel-callcenter5_${V}"

rpmbuild -bb \
    --define "_topdir $TOPDIR" \
    --define "cc_version $cc_version" \
    --define "cc_release $cc_release" \
    --define "cc_date $DATE" \
    "$SCRIPT_DIR/issabel-callcenter.spec"

# Exactly one noarch RPM, named after the version just built.
RPM_BUILT="$(ls "$TOPDIR"/RPMS/noarch/issabel-callcenter5-${cc_version}-${cc_release}*.noarch.rpm)"
echo "RPM: $RPM_BUILT"

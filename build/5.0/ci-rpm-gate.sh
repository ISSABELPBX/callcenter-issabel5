#!/bin/bash
# ci-rpm-gate.sh - decide whether CI builds the issabel-callcenter-ce RPM for a
# push, from the VERSION before and after it.
#
#   bash build/5.0/ci-rpm-gate.sh <old VERSION or ''> <new VERSION>
#
# Contract, which the workflow depends on line for line:
#   - stdout: exactly one line, "build=true" or "build=false".
#   - stderr: the reason for the decision.
#   - exit 0 whenever it decided - "cannot tell" is a decision not to build.
#     Any other exit is an error, and the workflow fails the run red rather
#     than skipping a major release with a green run.
#
# A major release is a bump outside the last field, 5.1.16 -> 5.2.17. Every
# ordinary change bumps only the last digit - the repository's convention -
# and must not build. An empty or malformed <old> is "cannot tell" -> no
# build; so is a <new> that is not a release number (cc_version_valid's
# rule). Only the dotted fields before the last are compared, after
# dropping any "-N" suffix: 5.1.9 and 5.1.10 both reduce to "5.1" (no
# build), 5.1.16 -> 5.2.17 reduce differently (build). VERSION here is
# always x.y.z; the rule is mechanical for other shapes.
#
# Pure bash on purpose: the workflow runs this on ubuntu-latest, whose awk
# is mawk - no gawk-only features (no NF--). ${v%.*} strips the last dotted
# field; ${v%%-*} drops a "-N" suffix.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/callcenter-common.sh"

say() { printf '%s\n' "$1" >&2; }    # the reason goes to stderr

[ "$#" -eq 2 ] || { say "usage: $0 <old VERSION or ''> <new VERSION>"; exit 2; }
old="$1" new="$2"

# The version minus its last dotted field, any "-N" suffix dropped: what a
# bump must leave unchanged for this not to be a major release.
major_part() {
    local v="${1%%-*}"
    printf '%s' "${v%.*}"
}

if [ -z "$new" ] || ! cc_version_valid "$new"; then
    say "no RPM build: the VERSION to build (${new:-none}) is not a release number."
    echo "build=false"
    exit 0
fi
if [ -z "$old" ]; then
    say "no RPM build: no previous VERSION to compare (first push, or an unreadable one)."
    echo "build=false"
    exit 0
fi
if ! cc_version_valid "$old"; then
    say "no RPM build: the previous VERSION ($old) is not a release number."
    echo "build=false"
    exit 0
fi
if [ "$(major_part "$old")" = "$(major_part "$new")" ]; then
    say "no RPM build: $old -> $new is not a major release (only the last field changed)."
    echo "build=false"
    exit 0
fi
say "RPM build: $old -> $new is a major release."
echo "build=true"
exit 0

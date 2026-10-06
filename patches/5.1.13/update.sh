#!/bin/bash
# Database and configuration changes for the 5.1.13 update.
#
# No-op: 5.1.13 changes one dialer class, AMIEventProcess.class.php, a plain
# copy under files/. Nothing to migrate, remove or reconfigure; the files are
# laid down by the updater, and its single issabeldialer restart loads the
# new class.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

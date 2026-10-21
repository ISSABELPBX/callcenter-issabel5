#!/bin/bash
# Database and configuration changes for the 5.2.21 update.
#
# No-op: 5.2.21 changes one dialer file, QueueShadow.class.php, a plain
# copy under files/. Nothing to migrate, remove or reconfigure; the file is
# laid down by the updater, and its single issabeldialer restart loads it.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

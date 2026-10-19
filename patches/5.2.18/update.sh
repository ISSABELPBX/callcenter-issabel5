#!/bin/bash
# Database and configuration changes for the 5.2.18 update.
#
# No-op: 5.2.18 changes two dialer files, Llamada.class.php and
# ECCPHelper.lib.php, plain copies under files/. Nothing to migrate, remove or
# reconfigure; the files are laid down by the updater, and its single
# issabeldialer restart loads them.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

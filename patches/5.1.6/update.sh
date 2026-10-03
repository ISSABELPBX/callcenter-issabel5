#!/bin/bash
# Database and configuration changes for the 5.1.6 update.
#
# No-op: 5.1.6 changed dialer code (dialerd, HubProcess.class.php) and the
# systemd unit (KillMode=mixed). The unit is a file copy like the others: the
# updater runs systemctl daemon-reload when it copies it and restarts
# issabeldialer at the end, so there is nothing to do here.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

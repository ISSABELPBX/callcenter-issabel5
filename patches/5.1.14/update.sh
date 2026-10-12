#!/bin/bash
# Database and configuration changes for the 5.1.14 update.
#
# No-op: 5.1.14 removes campaign monitoring's "Phone Off" label and red row,
# and the queue_status field the dialer sent for it. Every change is a plain
# copy under files/ (the campaign_monitoring module and its lang files, the
# agent console class, the dialer's ECCPConn.class.php and the protocol spec).
# Nothing to migrate, remove or reconfigure; the updater's single
# issabeldialer restart loads the new class.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

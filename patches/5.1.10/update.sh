#!/bin/bash
# Database and configuration changes for the 5.1.10 update.
#
# No-op: 5.1.10 changed code files only, all of them plain copies under
# files/ (the campaign_monitoring module - including the retired, now inert
# libs/api.php stub -, the agent console class, the dialer's ECCPConn.class.php
# and the protocol spec). Nothing to migrate, remove or reconfigure.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

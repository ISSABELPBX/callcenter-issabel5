#!/bin/bash
# Database and configuration changes for the 5.1.7 update.
#
# No-op: 5.1.7 changed dialer code only (AMIClientConn.class.php, the quoted
# AstDB value). The changed file is a plain copy under files/, and the updater
# restarts issabeldialer at the end, so there is nothing to do here.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

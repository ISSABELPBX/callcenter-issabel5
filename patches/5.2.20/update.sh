#!/bin/bash
# Database and configuration changes for the 5.2.20 update.
#
# No-op: 5.2.20 changes one web-module file,
# paloSantoCallsDetail.class.php (the Calls Detail report's sort order), a
# plain copy under files/. Nothing to migrate, remove or reconfigure, and no
# service to restart: web PHP is read per request.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

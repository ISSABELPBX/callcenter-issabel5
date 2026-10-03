#!/bin/bash
# Database and configuration changes for the 5.1.3 update.
#
# No-op: 5.1.3 changed only dialer code (the AMI client's reentrancy guard,
# reply pairing by ActionID, and bounded waits) - no database schema and no
# configuration, so there is nothing to do here.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

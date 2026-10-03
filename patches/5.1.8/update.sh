#!/bin/bash
# Database and configuration changes for the 5.1.8 update.
#
# No-op: 5.1.8 changed the agent console only (modules/agent_console/
# index.php). The changed file is a plain copy under files/, so there is
# nothing to do here.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

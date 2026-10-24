#!/bin/bash
# Database and configuration changes for the 5.3.29 update.
#
# No-op: 5.3.29 changes one file, the dialer's AMIEventProcess.class.php
# (the agent-list refresh copies a same-id status change onto the known
# agent), a plain copy under files/. Nothing to migrate, remove or
# reconfigure; the file is laid down by the updater, and its single
# issabeldialer restart loads it.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

#!/bin/bash
# Database and configuration changes for the 5.3.26 update.
#
# No-op: 5.3.26 changes three files, the dialer's AMIEventProcess.class.php
# (the agent-list refresh copies a same-id rename onto the known agent), the
# Agents page's Agentes.class.php and the Callback Extensions page's
# Agentes.class.php (each an ECCP refreshagents notify after a successful
# save), plain copies under files/. Nothing to migrate, remove or
# reconfigure; the files are laid down by the updater, and its single
# issabeldialer restart loads them.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

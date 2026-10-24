#!/bin/bash
# Database and configuration changes for the 5.3.28 update.
#
# No-op: 5.3.28 changes two files in the agent console, its index.php (the
# shift window comes from the agent's saved filter in the session, the
# window's two ends travel with every shift value, and the break and hold
# queries clamp their rows into the window) and its javascript.js (the
# counters render the server value plus only the wall time inside the
# window), plain copies under files/. Nothing to migrate, remove or
# reconfigure; the files are laid down by the updater, and its single
# issabeldialer restart loads them.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

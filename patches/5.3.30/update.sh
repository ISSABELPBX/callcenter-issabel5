#!/bin/bash
# Database and configuration changes for the 5.3.30 update.
#
# No-op: 5.3.30 changes sixteen web-module files, all plain copies under
# files/ - Agents Monitoring's index.php and javascript.js (the server owns
# and clamps the shift window; the browser adds only in-window time), the
# two campaign panels' index.php (the overnight rule names the shift in
# progress) and javascript.js plus the agent console's index.php and
# javascript.js (the server-built shift label), and the four modules'
# lang/en.lang and lang/es.lang (the Today/Yesterday/Tomorrow day words).
# Nothing to migrate, remove or reconfigure; the files are laid down by the
# updater, and its single issabeldialer restart loads them.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

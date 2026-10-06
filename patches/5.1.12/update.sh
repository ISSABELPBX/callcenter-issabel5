#!/bin/bash
# Database and configuration changes for the 5.1.12 update.
#
# No schema change and no shipped file content change: 5.1.12 hardened the
# install and remove scripts (the root password reaches mysql only through
# the MYSQL_PWD environment, a failed database step aborts the install,
# setup/installer.php's exit code covers every step) and made the repository
# record setup/dialer_process/dialer/dialerd as executable. The installer and
# this updater already chmod the dialer after copying it, so an installed box
# needs nothing for that - this one idempotent line converges any box whose
# dialerd nevertheless lost its executable bit (a hand copy, a rescue
# restore), which would otherwise stop issabeldialer from starting.
#
# An update.sh is mandatory in every patches/<version>/ directory: see
# patches/README.md.

[ -f /opt/issabel/dialer/dialerd ] && [ ! -x /opt/issabel/dialer/dialerd ] && chmod 755 /opt/issabel/dialer/dialerd

exit 0

#!/bin/bash
# Database and configuration changes for the 5.3.32 update.
#
# No-op: 5.3.32 changes four files, plain copies under files/ - the dialer's
# ECCPConn (a form answer is measured in characters with mb_strlen() against
# the 250-character column, and the DATE check's date-time alternative has
# its backslash back, so yyyy-mm-dd hh:mm:ss is accepted as its 406 message
# always said and a value such as 2026-03-10 dd:30:00 is not), the Calls
# Detail class (the recording path is read with substr() instead of the
# curly-brace offset, deprecated on PHP 7.4 and an error on PHP 8), and the
# Call Center Configuration class, page and six lang files (a failed
# valor_config read returns FALSE with errMsg and the page shows the error
# with no form, instead of offering defaults whose Save would overwrite the
# real settings). Nothing to migrate, remove or reconfigure; the files are
# laid down by the updater, and its single issabeldialer restart loads the
# dialer file.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

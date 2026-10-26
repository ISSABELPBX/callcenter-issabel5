#!/bin/bash
# Database and configuration changes for the 5.4.36 update.
#
# No-op: 5.4.36 carries a dialer file only, a plain copy under files/ - the
# dialer's CampaignProcess (a third cleanup pass in the 3-second cycle
# settles a scheduled call that was never dialed and whose dialing window
# has fully passed, closing it as Failure with the reason "Scheduled call
# expired" instead of leaving it without a status forever, where it never
# reached the campaign's CSV download). Nothing to migrate, remove or
# reconfigure; the file is laid down by the updater, and its single
# issabeldialer restart loads it.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

#!/bin/bash
# Database and configuration changes for the 5.4.35 update.
#
# No-op: 5.4.35 carries a dialer file only, a plain copy under files/ - the
# dialer's CampaignProcess (the campaign finish check counts a row only when
# the dialer can still dial it, now or later, so a callback whose window
# passed no longer keeps a campaign Active forever, and no finish point marks
# a campaign Finished while its last calls are still dialing). Nothing to
# migrate, remove or reconfigure; the file is laid down by the updater, and
# its single issabeldialer restart loads it.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

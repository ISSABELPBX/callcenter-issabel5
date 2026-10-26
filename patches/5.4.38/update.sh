#!/bin/bash
# Database and configuration changes for the 5.4.38 update.
#
# No-op: 5.4.38 carries code files only, plain copies under files/ - the two
# campaign classes (outgoing and incoming) and the dialer's CampaignProcess.
# Both campaign deletes now clear the campaign's stale current_calls /
# current_call_entry rows before deleting its calls, and the orphan sweeper
# that force-closes connected calls without an end_time also deletes their
# current_calls rows, so deleting a campaign no longer fails on foreign key
# 1451 and a missed Hangup no longer leaves a row behind until the next
# dialer restart. Nothing to migrate, remove or reconfigure - no schema
# change - and the files are laid down by the updater, whose single
# issabeldialer restart loads them.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

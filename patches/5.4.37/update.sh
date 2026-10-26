#!/bin/bash
# Database and configuration changes for the 5.4.37 update.
#
# No-op: 5.4.37 carries dialer files only, plain copies under files/ - the
# dialer's TuberiaMensaje (an RPC wait gives up when SIGTERM, SIGINT or
# SIGQUIT is pending, so a task whose peer was stopped exits instead of
# spinning on an answer that never comes) and the dialer's CampaignProcess
# (a cycle that gets no answer from AMIEventProcess to its call list places
# no call, instead of marking rows Placing that nothing dials). Nothing to
# migrate, remove or reconfigure; the files are laid down by the updater,
# and its single issabeldialer restart loads them.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

#!/bin/bash
# Database and configuration changes for the 5.3.34 update.
#
# No-op: 5.3.34 changes two dialer files, plain copies under files/ -
# CampaignProcess (a due same-agent scheduled call is read and placed before
# the no-agent exit, so reserving its agent no longer stalls the callback
# until logoff; the reservation happens in a pre-pass before any free-agent
# list is taken, reserved agents are dropped from every campaign's free list,
# the agent budget no longer subtracts scheduled calls, and a per-cycle sweep
# releases reservations whose scheduled call can no longer be placed) and
# AMIEventProcess (the agentesReservados RPC that sweep asks for the reserved
# idle agents). Nothing to migrate, remove or reconfigure; the files are laid
# down by the updater, and its single issabeldialer restart loads them.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

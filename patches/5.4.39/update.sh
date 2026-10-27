#!/bin/bash
# Database and configuration changes for the 5.4.39 update.
#
# No-op: 5.4.39 carries code files only, plain copies under files/ - the
# dialer's AMIEventProcess, Agente and ECCPConn classes. An agent's session
# ending while they have a client's call now ends that call by its real
# channel: a dead Agent-type login channel is no longer taken for a failed
# login, the attended-transfer consultation handler ends the client's call
# (a callback agent hanging up on purpose after the colleague answered still
# completes the transfer, a lost phone - RTP timeout, cause 44 - does not),
# a forced logoff no longer hangs up the agent's logical name ("No such
# channel") and hangs up the client first, and a repeat Agentlogoff can no
# longer log an already-logged-off agent off twice. Nothing to migrate,
# remove or reconfigure - no schema change - and the files are laid down by
# the updater, whose single issabeldialer restart loads them.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

#!/bin/bash
# Database and configuration changes for the 5.3.31 update.
#
# No-op: 5.3.31 changes four dialer classes (Llamada, ListaLlamadas,
# AMIEventProcess and SQLWorkerProcess - an incoming call abandoned before
# its call_entry insert id returns, such as a Leave Empty strict failover,
# is closed as abandonada with its progress notice and recording link once
# the late id arrives), plain copies under files/. Nothing to migrate,
# remove or reconfigure; the files are laid down by the updater, and its
# single issabeldialer restart loads them.
#
# An update.sh is mandatory in every patches/<version>/ directory, even a
# no-op like this one: see patches/README.md.

exit 0

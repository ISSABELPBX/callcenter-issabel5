# Spec for the Issabel Call Center community module (callcenter-issabel5),
# packaged as issabel-callcenter-ce (Community Edition) - deliberately NOT
# issabel-callcenter, the previous package, still offered by the Issabel 5
# repos: this package is neither an alternative nor an update for it,
# replaces nothing (no Obsoletes), and rpm's own file-conflict check already
# stops a co-install, so it needs no Conflicts either.
#
# Built only by build/5.0/build-rpm.sh, which defines cc_version as the
# WHOLE VERSION string (the single source of truth; no version literal
# appears anywhere in this file, %changelog included), cc_release as the
# rebuild counter (CC_RPM_RELEASE, default 1) and cc_date for the changelog
# entry. The payload is exactly the install scripts' own mapping
# (cc_dest_for() in build/5.0/callcenter-common.sh) plus the
# /usr/share/issabel/module_installer/callcenter/ archive copy the shell
# installer deploys, MINUS CHANGELOG_OLD.md: the frozen changelog points at
# the repository, so the RPM does not package it (the shell installer still
# deploys it). %post mirrors install-issabel-callcenter.sh, except for the
# dashboard ProcessesStatus applet: that belongs to issabel-system, which
# ships the Dialer row and its icon itself, so this package neither carries
# icon_headphones.png nor touches the applet on install or erase.
#
# The module_installer directory is unversioned (callcenter, not
# callcenter_<version>) on purpose: cc_read_version() reads
# /usr/share/issabel/module_installer/callcenter/VERSION, and a versioned
# directory would make every later script-driven update refuse.

%{!?cc_version:%{error:cc_version is not defined - build with build/5.0/build-rpm.sh}}
%{!?cc_release:%{error:cc_release is not defined - build with build/5.0/build-rpm.sh}}
%{!?cc_date:%{error:cc_date is not defined - build with build/5.0/build-rpm.sh}}

Summary: Issabel v5 Call Center Community Edition
Name:    issabel-callcenter-ce
Version: %{cc_version}
Release: %{cc_release}%{?dist}
License: GPLv2+
URL:     https://github.com/ISSABELPBX/callcenter-issabel5
Source0: issabel-callcenter-ce_%{cc_version}.tgz
BuildArch: noarch

Requires(pre): issabel-framework >= 5.0.0-1
Requires: issabelPBX
Requires: php-cli
Requires: php-mbstring
Requires: mariadb
Requires: openssl

%description
Issabel Call Center module for Issabel 5: predictive dialer, agent console,
campaigns and reports. Runs the issabeldialer daemon (ECCP over TLS) against
Asterisk 13, 16, 18 or 20.

On a box with the previous call centre package, issabel-callcenter, still
offered by the Issabel 5 repos, installing this package refuses: remove it
first (rpm -e issabel-callcenter, or dnf remove issabel-callcenter).
Removing it keeps the call_center database, and installing
issabel-callcenter-ce afterwards migrates that database to a v5-compatible
schema.

%prep
%setup -q -n issabel-callcenter-ce_%{cc_version}

%install
rm -rf $RPM_BUILD_ROOT

# The web modules (a glob: it expands against the buildroot, so it owns
# exactly our module trees and never the shared modules/ folder, and a new
# module needs no spec edit).
mkdir -p $RPM_BUILD_ROOT/var/www/html/modules
cp -a modules/* $RPM_BUILD_ROOT/var/www/html/modules/

# The dialer.
mkdir -p $RPM_BUILD_ROOT/opt/issabel
cp -a setup/dialer_process/dialer $RPM_BUILD_ROOT/opt/issabel/
chmod 0755 $RPM_BUILD_ROOT/opt/issabel/dialer/dialerd

# Unit, logrotate, Apache SSE config, Do-Not-Call uploader.
install -m 0644 -D setup/dialer_process/issabeldialer.service \
    $RPM_BUILD_ROOT/etc/systemd/system/issabeldialer.service
install -m 0644 -D setup/issabeldialer.logrotate \
    $RPM_BUILD_ROOT/etc/logrotate.d/issabeldialer
install -m 0644 -D setup/callcenter-modules.logrotate \
    $RPM_BUILD_ROOT/etc/logrotate.d/callcenter-modules
install -m 0644 -D setup/issabel-sse.conf \
    $RPM_BUILD_ROOT/etc/httpd/conf.d/issabel-sse.conf
install -m 0755 -D setup/usr/bin/issabel-callcenter-local-dnc \
    $RPM_BUILD_ROOT/usr/bin/issabel-callcenter-local-dnc

# The module_installer archive copy: what install-issabel-callcenter.sh
# deploys there and what cc_read_version() reads. CHANGELOG_OLD.md is not
# packaged (see the header): nothing on a box reads it, and the changelog
# points at the repository. Neither is icon_headphones.png: issabel-system
# owns the dashboard icon.
mkdir -p $RPM_BUILD_ROOT/usr/share/issabel/module_installer/callcenter
cp -a setup menu.xml VERSION \
    $RPM_BUILD_ROOT/usr/share/issabel/module_installer/callcenter/
rm -f $RPM_BUILD_ROOT/usr/share/issabel/module_installer/callcenter/setup/icon_headphones.png

# Log directory for the web modules (see callcenter-modules.logrotate).
mkdir -p $RPM_BUILD_ROOT/var/log/callcenter-module

%pretrans
# The previous call centre package, issabel-callcenter (still offered by the
# Issabel 5 repos), owns the same module paths this package installs, so rpm
# would abort the install on a file-conflict list without ever running %pre.
# %pretrans is the one place a scriptlet runs in that situation: rpm 4.14
# runs it before fingerprinting and the file-conflict checks
# (lib/transaction.c: "Run %pretrans scripts ... This is evil as it runs
# before fingerprinting and problem checking"), and a non-zero exit stops
# the install before any file is touched. Deliberately no Conflicts: tag: it
# would give rpm a one-line refusal in both rpm and dnf but stop even this
# scriptlet from running, losing the message below. Read-only rpm query;
# state-based, so it is the same no-op on install, upgrade and reinstall of
# this package.
if rpm -q issabel-callcenter >/dev/null 2>&1; then
    echo "Error: the previous call centre package is installed on this system ($(rpm -q issabel-callcenter))." >&2
    echo "Remove it before installing issabel-callcenter-ce:" >&2
    echo "    rpm -e issabel-callcenter        (or: dnf remove issabel-callcenter)" >&2
    echo "Removing it keeps the call_center database." >&2
    echo "Installing issabel-callcenter-ce afterwards migrates that database to a v5-compatible schema." >&2
    exit 1
fi
exit 0

%pre
# The Asterisk gate from build/5.0/install-issabel-callcenter.sh:301-316,
# detected here with `asterisk -V`: the binary answers with the daemon
# stopped (mid-boot, maintenance window), where the installer has to ask the
# running daemon instead. Same accepted set, same refusal.
ASTERISK_VERSION=$(asterisk -V 2>/dev/null | awk '{print $2}' | cut -d. -f 1)
if [ -z "$ASTERISK_VERSION" ]; then
    echo "Error: Cannot detect Asterisk version. Is Asterisk installed?" >&2
    exit 1
fi
case "$ASTERISK_VERSION" in
    13|16|18|20) ;;
    *)
        echo "Error: Issabel CallCenter requires Asterisk 13, 16, 18 or 20." >&2
        echo "Detected Asterisk version: $ASTERISK_VERSION" >&2
        echo "Installation aborted - nothing was installed or modified." >&2
        exit 1
        ;;
esac

# A box runs the community module or Call Center Pro, never both: Pro claims
# the same 31 module folders, and installing over it would destroy it.
if [ -d /opt/ccpro/dialer ]; then
    echo "Error: Call Center Pro is installed on this system (/opt/ccpro/dialer)." >&2
    echo "A box runs one call-centre product or the other. Remove Pro first." >&2
    exit 1
fi
exit 0

%post
# Everything a file copy cannot do, mirroring
# build/5.0/install-issabel-callcenter.sh:329-474. Idempotent, so it runs
# unchanged on install and on upgrade. The installer's dashboard applet
# patch (lines 335-368) is left out on purpose: see the header.

# ECCP TLS certificate (installer lines 399-410): the port 20005 listener is
# TLS-only and the dialer runs as asterisk, so it needs its own readable pair.
if ! bash /opt/issabel/dialer/eccp-cert.sh install; then
    echo "Error: could not install the ECCP TLS certificate." >&2
    echo "The dialer will refuse to start its ECCP listener without it." >&2
    exit 1
fi

# Menu (installer line 423), then the Apache reload the installer does after
# laying issabel-sse.conf (installer lines 427-428: copy, then
# `systemctl reload httpd`); the RPM lays the file as payload, so only the
# reload belongs here. Reload, never restart.
issabel-menumerge /usr/share/issabel/module_installer/callcenter/menu.xml
systemctl reload httpd 2>/dev/null || true

# Database, dialplan contexts, parking lot, agents.conf (installer
# lines 431-447). installer.php hardcodes /tmp/new_module/callcenter and is
# idempotent, so an upgrade re-runs it safely.
mkdir -p /tmp/new_module/callcenter
/bin/cp -rf /usr/share/issabel/module_installer/callcenter/* /tmp/new_module/callcenter/
chown -R asterisk.asterisk /tmp/new_module/callcenter
if ! php /tmp/new_module/callcenter/setup/installer.php; then
    /bin/rm -rf /tmp/new_module
    echo "Error: the database installer failed - see the ERR lines above." >&2
    echo "Installation aborted; the module is not completely installed." >&2
    exit 1
fi
/bin/rm -rf /tmp/new_module

# The dialer needs a shell for the asterisk user (installer lines 449-458).
usermod -s /bin/bash asterisk 2>/dev/null || true

# Enable and start the service (installer lines 460-471).
systemctl daemon-reload
systemctl enable issabeldialer
if systemctl is-active --quiet issabeldialer; then
    systemctl restart issabeldialer
else
    systemctl start issabeldialer
fi

# Apply the dialplan and agents.conf installer.php wrote. Reload, never
# restart (installer line 474).
asterisk -rx 'core reload' 2>/dev/null || true
exit 0

%preun
if [ "$1" -eq 0 ]; then    # erase, not upgrade
    systemctl stop issabeldialer 2>/dev/null || true
    systemctl disable issabeldialer 2>/dev/null || true
fi
exit 0

%postun
# On erase, mirror remove-issabel-callcenter.sh's non-interactive clean-up.
# The call_center database is deliberately kept.
if [ "$1" -eq 0 ]; then
    if [ -x /usr/bin/issabel-menuremove ]; then
        issabel-menuremove call_center
    fi
    /bin/rm -f /etc/issabel/dialer/eccp.pem /etc/issabel/dialer/eccp.key
    rmdir /etc/issabel/dialer 2>/dev/null || true

    # rpm has removed its own files from /opt/issabel/dialer; what is left is
    # the dialer's runtime dialerd.log* and dialerd.pid, which the shell
    # remover also clears (remove-issabel-callcenter.sh:56).
    /bin/rm -rf /opt/issabel/dialer
    # /opt/issabel itself only when now empty: stock keeps issabel-updater.
    rmdir /opt/issabel 2>/dev/null || true

    EXTENSIONS_FILE="/etc/asterisk/extensions_custom.conf"
    if [ -f "$EXTENSIONS_FILE" ] && grep -q "; BEGIN ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE" "$EXTENSIONS_FILE"; then
        sed -i '/^; BEGIN ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE$/,/^; END ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE$/d' "$EXTENSIONS_FILE"
        asterisk -rx "dialplan reload" 2>/dev/null || true
    fi
    PARKING_FILE="/etc/asterisk/res_parking_custom_general.conf"
    if [ -f "$PARKING_FILE" ] && grep -q "; BEGIN ISSABEL CALL-CENTER PARKING LOT DO NOT REMOVE THIS LINE" "$PARKING_FILE"; then
        sed -i '/^; BEGIN ISSABEL CALL-CENTER PARKING LOT DO NOT REMOVE THIS LINE$/,/^; END ISSABEL CALL-CENTER PARKING LOT DO NOT REMOVE THIS LINE$/d' "$PARKING_FILE"
        asterisk -rx "module reload res_parking" 2>/dev/null || true
    fi
    systemctl reload httpd 2>/dev/null || true
    echo "The call_center database was kept; drop it manually if you want it gone."
fi
systemctl daemon-reload 2>/dev/null || true
exit 0

%files
# The web modules and the dialer, owned by asterisk as the shell installer
# leaves them. The glob owns exactly our module trees - never the shared
# /var/www/html/modules folder, which every Issabel module package owns -
# so rpm -e removes exactly our own.
%defattr(-,asterisk,asterisk,-)
/var/www/html/modules/*
/opt/issabel/dialer

# Everything else, root-owned. %dir /opt/issabel: no package owns it on a
# stock box, so this RPM creates it and must remove it again on erase.
%defattr(-,root,root,-)
%dir /opt/issabel
/etc/systemd/system/issabeldialer.service
/etc/logrotate.d/issabeldialer
/etc/logrotate.d/callcenter-modules
%config(noreplace) /etc/httpd/conf.d/issabel-sse.conf
%attr(0755,root,root) /usr/bin/issabel-callcenter-local-dnc
/usr/share/issabel/module_installer/callcenter
%dir %attr(0750,asterisk,asterisk) /var/log/callcenter-module

%changelog
* %{cc_date} Issabel CallCenter community <https://t.me/IssabelPBXip> - %{cc_version}-%{cc_release}
- See CHANGES.md in the callcenter-issabel5 repository.

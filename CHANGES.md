# Issabel Call Center - Changes

Newest version first. Each point starts with its type.

History, frozen and not added to: `CHANGES_PRE.md` (numbered pre-release
entries) and `CHANGELOG_OLD.md` (release notes up to 5.0.0-10).

---

## 5.1.14

- Removed: campaign monitoring's "Phone Off" label and red row, which also marked healthy logged-in Agent-type agents; a phone that stops answering logs its agent out since 5.1.13
- Removed: the getcampaignstatus queue_status field 5.1.10 added for that label, from the dialer, the console class and the ECCP spec

## 5.1.13

- Bug fix: the dialer logs out an agent whose extension stops answering qualify (PeerStatus Unreachable), not only when it unregisters — a PJSIP agent too, whose endpoint never reports Unregistered; a call in progress is hung up, as on an unregister

## 5.1.12

- Bug fix: the remover no longer puts the MySQL root password on the mysql command line, where ps showed it while the database drop ran
- Bug fix: a fresh install no longer puts the MySQL root password on the mysql command line while creating the database
- Bug fix: the installer aborts when the database installer fails, instead of printing "installation complete!" over a half-built installation
- Bug fix: the database installer's exit code reflects every step, and a successful run without a staged module no longer reports failure
- Bug fix: a database that cannot be created or loaded stops the schema steps, leaving one error that names the cause instead of dozens
- Bug fix: dialerd is executable in git, so a clone cannot ship a dialer that will not start; this version's update restores the bit on a box that lost it

## 5.1.11

- Bug fix: campaign pages no longer change the database schema on page load, and no longer put the MySQL root password on the mysql command line; the installer and this version's update now create the second and third external-URL columns
- Bug fix: a new incoming campaign keeps its second and third external URLs, which creating it silently dropped
- Bug fix: saving an incoming campaign no longer prints its SQL statement into the page
- Bug fix: incoming and outgoing campaign forms reject a malformed external-URL id instead of casting it to a number

## 5.1.10

- Bug fix: campaign monitoring's directly-served libs/api.php — reachable without a session and shell-injectable through the queue parameter — is retired; it now answers HTTP 410 and its data comes from an authenticated module action
- New feature: getAgentLastCalls action and PaloSantoConsola::leerUltimasLlamadasAgentes() serve each agent's last call with one fixed SQL per campaign type, bound by ID only, on the module's own DSN
- Improve: "Phone Off" comes from the dialer's per-agent queue_status over ECCP (now documented in the protocol spec), labelled with _tr() and coloured by number gated on the agent's raw status, instead of parsing `asterisk -rx 'queue show'` once at page load

## 5.1.9

- Bug fix: a console action sent in the instant after the agent session ends is answered as a failure the console shows, instead of a success message or silence
- Bug fix: the console stops polling once it starts leaving for the login form, so the logged-out answers no longer restart that page's load

## 5.1.8

- Bug fix: hanging up the agent's phone mid-call returns the agent console to the login form, instead of leaving it polling the server about 12 times a second; the request after a session ends now gets the logged-out event, not a redirect the page cannot follow

## 5.1.7

- **Bug fix**: a break whose name contains a space now writes its `PAUSECUSTOM` entry to AstDB: the value is sent to Asterisk's `database put` as one quoted argument, with `\` and `"` escaped, instead of being rejected for too many arguments.
- **Improve**: the installer accepts Asterisk 20 (13, 16, 18 or 20); the call-centre suite ran on Asterisk 20.12 with no Asterisk-20-specific failure.

## 5.1.6

- **Bug fix**: dialer tasks act on every signal they get: after a log rotation, CampaignProcess and AMIEventProcess no longer keep writing to the rotated `dialerd.log` and holding it open after it is deleted.
- **Bug fix**: `issabeldialer.service` uses `KillMode=mixed`, so a stop signals only dialerd and every task is told to finish (agents logged out) before it is signalled.
- **Bug fix**: HubProcess no longer spins at full CPU while it waits for its tasks at shutdown; a stop takes under a second instead of several, with no re-sent signals.

## 5.1.5

- **Bug fix**: patch 5.1.2 no longer overwrites 6 files a 5.1.1 box already has (calls_detail, Agente, AMIClientConn, AMIEventProcess); it now starts from the 5.1.1 RC release.

## 5.1.4

- **New feature**: the installer updates an older installation in place instead of refusing it; an equal or newer installation reports nothing to do.
- **New feature**: `build/5.0/update-issabel-callcenter.sh` applies every newer version from `patches/` in order, warns that updated files are overwritten and customizations lost, and requires `yes` or `Y`; a failed step resumes on re-run; logs to `/var/log/issabel/callcenter-update.log`.
- **New feature**: `patches/<version>/` holds an `update.sh` for database and configuration changes and a `files/` tree copied over as-is; backfilled for 5.1.2 and 5.1.3.

## 5.1.3

- **Bug fix**: the AMI client's guard against a synchronous call inside another never worked (its counter started as FALSE, which ++ does not change). Nested calls swapped their replies and could freeze the dialer; the guard now refuses them and logs it.
- **Bug fix**: the PAUSECUSTOM cleanup at agent pause and logoff, and the queue-membership refresh, are sent asynchronously, so they no longer run as nested synchronous calls.
- **Bug fix**: AMI replies are paired to their own request by ActionID.
- **Bug fix**: a synchronous AMI call gives up after 10 s with an error and a log line instead of waiting forever, and the requests queued behind it are still sent.
- **Bug fix**: dialer shutdown waits at most 10 s for tasks to confirm, and kills a task still alive 10 s after SIGTERM, so a stuck task no longer runs into systemd's stop timeout.
- **Bug fix**: an AMI pairing error message printed a literal '.__METHOD__.' instead of the method name.

## 5.1.2

- **Bug fix**: Predictor's 10 s AMI enumeration timeout no longer crashes CampaignProcess with an uncaught PHP Error; the timeout warning now reaches dialerd.log.
- **Improve**: HubProcess and dialerd also catch PHP Errors, so a failing task is logged and ends through its cleanup instead of dying as an uncaught fatal.

## 5.1.1

- **New feature**: `VERSION` file at the repo root is the single source of truth for the release number.
- **New feature**: The installer reads `VERSION` and deploys it, so a later install reports the installed version.
- **New feature**: TLS encryption for the ECCP protocol; port 20005 is now TLS-only.
- **New feature**: PJSIP trunks accepted by outgoing campaigns.
- **New feature**: Dedicated `callcenter_hold` parking lot.
- **Bug fix**: Outgoing campaign call no longer ends in the dialer the instant it connects when queue recording is off.
- **Bug fix**: Calls Detail recordings list expands again; the module no longer loads its own JS twice.
- **Bug fix**: Calls Detail lists a transferred call's recordings oldest first, so the visible one is the start of the call.
- **Bug fix**: Attended transfer no longer strands the caller on hold or leaves the console with dead buttons.
- **Bug fix**: Beep on end of hold now plays consistently.
- **Bug fix**: Scheduled-call agent reservation crashed on PHP 7.4.
- **Bug fix**: Scheduled call with "same agent" now reaches Agent-type agents instead of giving the customer a busy tone.
- **Bug fix**: Device-type defects on PJSIP and IAX2.
- **Bug fix**: Callback agents added to a queue while logged in stayed unusable until re-login.
- **Improve**: ECCP XML hardening - escaping helper, serialization fail-safe, explicit database charset.
- **Improve**: Installer requires Asterisk 18 and aborts before writing anything.
- **Improve**: Installer help documents the ECCP certificate variables.
- **Removed**: All RPM spec files and the legacy `build/2.5` and `build/4.0` trees; `build/5.0/install-issabel-callcenter.sh` is the only supported install path.
- **Removed**: Dead static queue member warning and the dead legacy call-placement path in `CampaignProcess`.

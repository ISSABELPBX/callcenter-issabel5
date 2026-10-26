# Issabel Call Center - Changes

Newest version first. Each point starts with its type.

History, frozen and not added to: `CHANGES_PRE.md` (numbered pre-release
entries) and `CHANGELOG_OLD.md` (release notes up to 5.0.0-10).

---

## 5.4.38

- Bug fix: deleting an outgoing campaign removes its calls' current_calls rows first, so a stale row no longer fails the delete with foreign key error 1451
- Bug fix: deleting an incoming campaign removes its calls' current_call_entry rows first, for the same reason
- Bug fix: the dialer's sweeper that closes orphaned connected calls also deletes their current_calls rows, so a missed Hangup no longer leaves one behind until the next dialer restart

## 5.4.37

- Bug fix: a dialer task waiting for an answer from a task that was stopped now gives up when it is told to stop, so it exits and is restarted instead of hanging
- Bug fix: a cycle that gets no answer from AMIEventProcess to its call list places no call, instead of marking rows Placing that nothing dials

## 5.4.36

- Bug fix: a scheduled call whose window passes before it is dialed is closed as Failure with the reason "Scheduled call expired" within one 3-second cycle, instead of staying without a status forever and missing from the campaign's CSV download

## 5.4.35

- Bug fix: campaign finish ignores rows the dialer can never dial (a callback window that passed, an agent row with no window), so the campaign reaches Finished instead of staying Active until its end date
- Bug fix: a campaign is no longer marked Finished while its last calls are still dialing, so a failed call with retries left is retried
- Bug fix: the campaign list's Pending Calls counts only calls the dialer can still make, so a Finished campaign shows 0 instead of an expired callback or a DNC number

## 5.3.34

- Bug fix: a due same-agent callback is placed even in a cycle that allocated no agents, instead of pausing its agent until logoff
- Bug fix: no ordinary call is placed for an agent reserved for a callback, and a free agent is no longer idled beside a due callback
- Bug fix: a reservation whose callback can no longer be placed is released in the same cycle, not at logoff

## 5.3.33

- Bug fix: the agentconsole ECCP client no longer ships the well-known default password - fresh installs and updates create or leave a random one, and a password an admin set is never overwritten
- Improve: the 44 eccp-examples take their ECCP credentials from ECCP_SERVER/ECCP_USER/ECCP_PASS instead of a hardcoded default, and refuse to run without a password

## 5.3.32

- Bug fix: a form answer is measured in characters, not bytes - an Arabic answer over 125 characters was refused as too large though its column holds 250 characters
- Bug fix: a Date form field accepts yyyy-mm-dd hh:mm:ss as its error message always said, and no longer stores a value such as 2026-03-10 dd:30:00 - the date-time check was missing one backslash
- Bug fix: Calls Detail no longer reads the recording path with a curly-brace string offset, deprecated on PHP 7.4 and an error on PHP 8
- Bug fix: Call Center Configuration shows a database error and no form when its settings cannot be read, instead of a form of defaults whose Save would overwrite the real settings

## 5.3.31

- Bug fix: an incoming call that leaves its queue before its call_entry row is back from the database (Leave Empty strict with a failover queue) is now recorded abandonada, with its end time, wait, progress entry and recording, instead of staying en-cola for good

## 5.3.30

- Bug fix: Agents Monitoring's login, talk, break and hold columns stop at the shift end and read 00:00:00 before the shift starts, instead of counting on
- Bug fix: a break, a reload or another agent's status change no longer puts the whole day's totals into Agents Monitoring's shift columns or sets them back - the shift filter is kept in the session
- Bug fix: an overnight shift on Agents Monitoring and both campaign monitoring panels is the shift in progress once its start hour has passed, as on the agent console
- Bug fix: the shift label beside the bar names the right days on the agent console and the three monitoring pages, built by the server and translated

## 5.3.29

- Bug fix: a deactivated agent no longer shows as active in the dialer's agent list until a restart - the agent-list refresh copies a same-id status change onto the known agent

## 5.3.28

- Bug fix: the agent console's shift counters stop at the shift end and read 00:00:00 before the shift starts, instead of counting on outside the applied shift
- Bug fix: a break, hold or call no longer resets the agent console's shift counters to the whole day's totals - the agent's shift filter is kept in the session, also after a reload
- Bug fix: the shift's break and hold totals count only the part inside the shift, including a break or hold still running
- Bug fix: an overnight shift (From after To) shows the shift in progress, not last night's

## 5.3.27

- Bug fix: predictive mode no longer counts a queue member whose phone is ringing as a free agent - a campaign could place an extra call for a callback agent while a queue call or any other call was ringing them, though the queue will not ring a ringing member and nothing subtracted the call already ringing them

## 5.3.26

- Bug fix: a renamed agent no longer keeps its old name in the dialer until a restart - the agent-list refresh copies the database name onto a known agent, so QueueAdd, queue_log and the queue member list follow the rename
- Improve: the Agents and Callback Extensions pages ask the dialer to re-read its agent list right after saving an agent, instead of waiting for its 5-minute timer

## 5.3.25

- Bug fix: a queue member on a live call counts as busy and about to free even when Asterisk has left its device state at RINGING after the answer - predictive campaigns had counted that agent free and predicted no call for it; a member that is only ringing still counts free, and one whose call has ended (device NOT_INUSE) is free even if its AgentComplete was lost

## 5.3.24

- Bug fix: the RPM no longer carries icon_headphones.png or patches the dashboard ProcessesStatus applet on install or erase; both belong to issabel-system

## 5.2.23

- Improve: the RPM package is renamed issabel-callcenter-ce (was issabel-callcenter5), Summary "Issabel v5 Call Center Community Edition"; the build, the CI artifact and the Release asset carry the new name

## 5.2.22

- Improve: a patch file lives only in the newest version that ships it; `build/5.0/prune-patches.sh` deletes older copies (17 removed), `--check` lists them

## 5.2.21

- Bug fix: the dialer's queue refresh (every 5 minutes, on an ECCP refreshagents and on an Asterisk reload) no longer wipes the call start of every busy queue member, which made predictive campaigns stop counting busy agents as about to free until their next call

## 5.2.20

- Bug fix: the Calls Detail report sorts every call by when it entered the queue (datetime_entry_queue; an outbound call that never reached the queue by when it was dialled), newest first - abandoned and failed calls, which have no start time, fell below every answered call in phone-number order

## 5.2.19

- Bug fix: a queue's predicted slots are split between the outgoing campaigns sharing it, by the idle agents each holds, with leftovers rotating - they all went to the first campaign in list order, every cycle in which several campaigns held an idle agent; waiting callers are now subtracted once per queue

## 5.2.18

- Bug fix: a refused Originate records its Failure in call_progress_log - the failure notice carried no status and a raw epoch timestamp, so MariaDB rejected the row and it was lost
- Bug fix: a database data error (SQLSTATE class 22: bad date, value too long, out of range) is logged and dropped at once, instead of retried 50 times with every dialer database write waiting about 10 minutes behind it
- Bug fix: a call the dialer cannot place for want of an AMI connection ends as a Failure, like a refused Originate, instead of crashing AMIEventProcess and losing its agents and calls

## 5.2.17

- New feature: a GitHub Actions workflow (.github/workflows/build-rpm.yml) builds the issabel-callcenter5 RPM on a major release - a VERSION bump outside the last field (5.1.16 -> 5.2.17) - through primasoft-dev/rpm-package-action on Rocky 8, uploads it as an artifact and attaches it to the v<VERSION> GitHub Release; workflow_dispatch builds any version by hand, with the rebuild counter as input
- New feature: build/5.0/ci-rpm-gate.sh decides from the VERSION before and after a push whether it is a major release - one line on stdout, the reason on stderr, exit 0 whenever it decided; an ordinary last-digit bump builds nothing

## 5.1.16

- Improve: the issabel-callcenter5 RPM's Version is the whole VERSION and its Release a rebuild counter (CC_RPM_RELEASE, default 1), e.g. issabel-callcenter5-5.1.16-1.el8
- Removed: CHANGELOG_OLD.md from the RPM; the package changelog points to the repository, and the shell installer still deploys it

## 5.1.15

- New feature: an issabel-callcenter5 RPM, built in one command by build/5.0/build-rpm.sh from build/5.0/issabel-callcenter.spec; VERSION 5.1.x becomes Version 5, Release 1.x.el8, and an erase keeps the call_center database
- New feature: the issabel-callcenter5 RPM refuses to install over the previous issabel-callcenter package, and says to remove it first: that keeps the call_center database, which the install then migrates to a v5-compatible schema
- New feature: the install, update and remove scripts refuse on a box installed from the issabel-callcenter5 RPM, and name the rpm command to use
- Bug fix: the installer no longer overwrites, and the remover no longer deletes, the dashboard's icon_headphones.png and its icon mapping, which issabel-system ships

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

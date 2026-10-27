# TODO - Call Center Issabel 5

Unresolved issues for the call center module. Items are sorted by urgency (Critical → High → Medium → Low). Within each urgency level, untouched items appear first, followed by partially solved items.

**Last Reviewed**: 2026-10-25

`Change #N` references point to the numbered entries in `CHANGES_PRE.md`.

---

## Low

### Campaign List Needs a 'Retries Left' Column

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: 2026-09-22
* **Location**: `paloSantoCampaignCC.class.php:88-90`, `campaign_out/index.php:195`, `campaign_out/index.php:211-213`, `campaign_out/lang/*.lang`
* **Description**: The campaign list's `Pending Calls` column counts only never-dialled rows the dialer can still dial (since 5.4.35, by `_checkCampaignDataExhausted()`'s rule, `paloSantoCampaignCC::getCampaigns()`), but the dialer will not mark a campaign `Finish` until every non-`Success` row has also used up its retries (`_checkCampaignDataExhausted()`). The visible column is therefore not the one that governs the status, and a campaign reading `Pending Calls = 0` sits at `Active` with no on-screen explanation. Seen on the client box on 2026-09-22: campaigns 6, 7 and 8 (`ADSL - Q3'26 - VF/ET/OR`) each showed `Pending Calls = 0` at status `Active` while still holding 1507, 1250 and 456 retryable rows against a retry limit of 5 -- correct dialer behaviour, invisible in the GUI.
* **Implementation**: Add a `Retries Left` column that **counts the remaining numbers that still have retries available -- one row per phone number still callable -- not the sum of retries remaining and not the retries already consumed**. For campaign 6 above the column reads `1507`. Deliberately reuse the dialer's own exhaustion predicate -- the window/agent rule `_checkCampaignDataExhausted()` counts with, current date and time bound from PHP as the repo requires, never a SQL date function -- so the column reaches 0 exactly when the dialer becomes entitled to set `Finish`: `(SELECT COUNT(*) FROM calls WHERE id_campaign = c.id AND (status IS NULL OR status NOT IN ('Success','Placing','Ringing','OnQueue','OnHold')) AND retries < c.retries AND dnc = 0 AND ((date_init IS NULL AND date_end IS NULL AND time_init IS NULL AND time_end IS NULL AND agent IS NULL) OR (date_init IS NOT NULL AND date_end IS NOT NULL AND time_init IS NOT NULL AND time_end IS NOT NULL AND (date_end > ? OR (date_end = ? AND time_end >= ?))))) AS retries_left`, the three placeholders bound to PHP's `date('Y-m-d')` twice and `date('H:i:s')` once. Then add the cell to the `$arrData[]` row and the header to `setColumns()`, keeping both arrays in the same order; the label goes through `_tr()` with entries in every `lang/<lang>.lang` (en, es, fr, ru, cn, tr, fa).
* **Open decision**: The predicate above includes never-originated rows, so `Retries Left` is a superset of `Pending Calls` (on the client box campaign 9 showed 13578 pending against 15039 retries-left). Confirm whether that overlap is wanted, or whether never-originated rows should be excluded so the two columns are disjoint -- excluding them breaks the "reaches 0 when the dialer finishes it" property, so the overlap is probably the right call.
* **Status**: Untouched

### Transfer Bridge Reshuffle Can Finalize a Live Call

* **Type**: Bug
* **Urgency**: Low
* **Date Added**: 2026-10-27
* **Location**: `AMIEventProcess.class.php` (`msg_Link`, the "transferred to an unmonitored extension" detection); `Llamada.class.php` (`llamadaFinalizaSeguimiento`, which clears the agent's call reference)
* **Description**: During an attended-transfer consultation, `msg_Link`'s transfer detection can read a bridge reshuffle (a `msg_BridgeEnter` pairing) as a transfer to an unmonitored extension, and finalize the call. The misfire logs only DEBUG lines, so it is invisible on a dialer running at INFO: the agent silently loses the call while the client is still up. Seen once in eleven runs (2026-10-27): the session-end path then found no client channel to hang up and the call record closed with the client still bridged to the colleague. The session-end path no longer depends on that reference: the held client's channel is saved at the consultation start and used when the call or its channel is unknown. Proposed fix: skip that finalize while a consultation flag is set for the call's agent; check the console's Complete transfer first, because it may rely on this same detection.
* **Status**: Untouched

### A Task Killed Mid-RPC Without a Stop Signal Still Hangs Its Caller

* **Type**: Bug
* **Urgency**: Low
* **Date Added**: 2026-10-26
* **Location**: `setup/dialer_process/dialer/TuberiaMensaje.class.php` (`RPC()`, the wait loop); `setup/dialer_process/dialer/HubServer.class.php` (`rutearMensaje()`, which drops a request to an absent task)
* **Description**: The caller of `TuberiaMensaje::RPC()` waits until its peer answers or the hub link closes, and since 5.4.37 also stops waiting on a pending SIGTERM/SIGINT/SIGQUIT. A peer that dies without the caller being signalled - SIGKILL, or a crash while the hub's restart is suppressed - still leaves it waiting, and the hub restarts only tasks that exited, so the caller is never replaced. A time limit alone is wrong: `AMIEventProcess_ejecutarOriginate` can rightly take 30 s to answer, and a late answer arriving after a bounded wait would be read by the next RPC as its own. The fix is request ids on RPC answers.
* **Status**: Untouched

### Failed Calls Redialled With No Pause

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: 2026-10-26
* **Location**: `CampaignProcess.class.php:1446-1470` (the dial query), `CampaignProcess.class.php:1543-1548` (placement stamps `datetime_originate` and `retries + 1`), the campaign form in `campaign_out`
* **Description**: A failed call (busy, congestion, no answer - any failure) is offered again in the next cycle, with no wait. The dial query orders rows by `retries` (after `dummy_priority`), so in a campaign with many numbers a failed row already waits until every row with fewer attempts has been dialled: in effect it is retried at the end of the pass. But nothing spaces the attempts on one row in time. When few rows are left - near the end of any campaign, or in a campaign with one number - a failed number is dialled again seconds after its failure, until its retries are used up. Seen in a lab test on 2026-10-26 with a one-number campaign: congestion at 14:37:03, dialled again at 14:37:05. A number that was just busy or unanswered is likely to be so again seconds later, so those retries are spent for nothing.
* **Recommendation**: Failed calls should be retried at the end of the campaign, not again straight away. Keep the `retries` ordering, and add a minimum interval between attempts on the same row: a row with `retries > 0` is offered only when its last attempt (`datetime_originate`) is at least N minutes old, the cutoff computed in PHP and bound into both branches of the dial query. N is a per-campaign setting on the campaign form, with a default to be decided. `_checkCampaignDataExhausted()` must keep counting such a row as dialable, so a campaign waiting out the interval stays Active; its rule has no time test on retries, so it already does.
* **Status**: Untouched

### A Trunk's Hangup Is Sometimes Never Matched to Its Call

* **Type**: Investigation
* **Urgency**: Low
* **Date Added**: 2026-10-26
* **Location**: `AMIEventProcess.class.php` (`msg_Hangup`, the "Local channel optimized out of the bridge, call continues on …, ignoring hangup" branch)
* **Description**: Sometimes a trunk channel's Hangup is never matched to its call: seen on a GSM-gateway trunk whose call went through a Local channel that was optimised away. The dialer saw the Local legs hang up and ignored them (that branch assumes the real trunk channel is still up), but the trunk channel itself was already gone - an AMI hangup request for it answered "No such channel" seconds later, and no Hangup event for it was ever processed. The call then stays `Success` with no `end_time` until the 2-hour orphan sweeper (`_cleanOrphanedConnectedCalls()`) force-closes it. Since 5.4.37 that sweeper also deletes the call's `current_calls` row, so the leftover is bounded - but the call's true end time and duration are still lost. Next step: capture AMI events on a trunk that shows this, to learn whether the Hangup never reaches the dialer or arrives renamed by a Local-channel masquerade.
* **Status**: Untouched

### Exact Predictive Count for Ringing Callback Agents

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: 2026-10-24
* **Location**: `QueueShadow.class.php:264-265` (`msg_AgentCalled`), `CampaignProcess.class.php` (boost and eligibility)
* **Description**: Per review cycle the dialer places, for one campaign, `N = F + max(0, P - W) - D`, and `N = 0` when `F = 0` - where F is the idle agents allocated to the campaign (`AGENTES_LIBRES_LISTA`), P the busy agents the Erlang model expects to free within the answer window (`AGENTES_POR_DESOCUPAR`), W the callers waiting in the queue (`CLIENTES_ESPERA`; a caller stays counted until bridged, so a caller whose call is ringing an agent is in W), D the calls originated and not yet answered (pending originate), and R the queue members being rung with a waiting caller right now. The right count is `F + P - (W - R) - D`: each of the R ringing members is already matched to one of the W waiting callers. Since 5.3.27 a ringing member is not in F, but its caller is still subtracted inside W, so with busy agents about to free (P > 0) the dialer places up to R calls too few, and with F = 0 the eligibility gate places none at all even when P > 0 (before 5.3.27 a ringing member counted as idle kept the campaign eligible - two errors cancelling). Worked example: F = 1, R = 2, W = 2, P = 2 - the right count is 3, pre-5.3.27 gave 3 by cancellation, 5.3.27 gives 1. The exact version: `N = F + max(0, P - (W - Rq)) - D`, with the gate open while the campaign's queue has Rq > 0, where Rq counts members the QUEUE is ringing with a waiting caller (from AgentCalled until AgentConnect, AgentRingNoAnswer or the caller leaving, per queue; `QueueShadow::msg_AgentCalled` is a stub today) - not device RINGING, because a phone ringing with a personal call serves no waiting caller. Agent-type members never read RINGING (app_agent_pool publishes only UNAVAILABLE, NOT_INUSE and INUSE), so R is always 0 for them and their count is unchanged; a callback agent with an auto-answer phone rings for milliseconds against the 3 s cycle, so R is almost always 0 there too. Only callback agents who answer by hand are affected, and the error is on the safe side: too few calls, never abandoned ones.
* **Status**: Untouched

### ECCP User IP Restriction

* **Type**: Security
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `ECCPConn.class.php:382-384` (`Request_login`), `eccp_authorized_clients` (`call_center.sql`), `modules/eccp_users/`
* **Description**: Let each ECCP user log in only from the addresses listed for it: `agentconsole` only from `127.0.0.1`/`::1`, a CRM's user only from the CRM server. Today `Request_login` checks the username and password alone, from any address. With the random password ("Default ECCP Console Password"), a leaked or default credential is then useless from anywhere else, and "ECCP Listen Address" matters less. Replaces the pre-2011 "ECCP Client Authorization" (which agents each ECCP user may serve), dropped 2026-10-25: every built-in console shares the one user `agentconsole`, so a per-user agent list restricted nothing, and each agent action is already checked against that agent's own `eccp_password` hash (`_hashValidoAgenteECCP()`).
* **Implementation**: (1) An `allowed_ips` column in `eccp_authorized_clients`, empty meaning any address, so existing users keep working. (2) The client's address reaches the worker the way `appcookie` and `usuarioeccp` already do, in the per-connection `connvars` (`ECCPProxyConn.class.php:99-102`), and `Request_login` refuses an address not on the list. (3) The field on the ECCP Users page, labels through `_tr()`. (4) The schema change ships as idempotent SQL in the release's `update.sh`, and in `call_center.sql`/`installer.php` for fresh installs. (5) Fresh installs and updates set `agentconsole` to `127.0.0.1,::1`.
* **Status**: Untouched

### ECCP Listen Address

* **Type**: Security
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `ECCPProcess.class.php:132`, `dialerd.conf` (`[eccp]`)
* **Description**: The dialer always listens on `0.0.0.0:20005`. That is on purpose, because ECCP is also for external applications (`ECCP_Protocol.md`; `ECCP_CERT_MODE=generate-san` exists for remote clients), but the built-in consoles need only `localhost`. Make it a setting in `dialerd.conf`'s `[eccp]` section: local only (`127.0.0.1`) or public (`0.0.0.0`). An update must keep a box's own value. **Open decision**: the default. Local only is safer; public keeps existing remote clients working.
* **Status**: Untouched

### Schedule Call to Different Campaign

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: 2026-03-09
* **Location**: `ECCPConn.class.php:3221`
* **Description**: Allow scheduled callbacks to be sent to a different campaign than the original call. Currently, scheduled callbacks use the same campaign as the original call (`id_campaign` is hardcoded from original call).
* **Status**: Untouched

### Configurable Scheduled-Call Reservation Window

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-10-25
* **Location**: `CampaignProcess.class.php:1692-1696` (`_getSegundosReserva()`)
* **Description**: `_getSegundosReserva()` (reservation seconds) returns a fixed 30: how far ahead of a scheduled call's `time_init` the dialer reserves that call's agent. `_listarAgentesAgendadosReserva()` picks the agents whose scheduled call is due now or within the window, the reservation pauses them in every queue so no other call reaches them, and `_verificarFinLlamadasAgendables()` lifts it once the agent has no due or upcoming scheduled call. The in-code TODO asks for it to be configurable in the DB or per campaign. This is not the dial timeout, which this entry wrongly named until 2026-10-25: that one is already the "Per-call dial timeout" setting in Call Center Config (`dialer_timeout_originate`, global). At 0 the dialer leaves `Timeout` out of the Originate (`AMIClientConn` drops a NULL optional field), and Asterisk then uses its own default of 30000 ms (`int to = 30000` in `manager.c` `action_originate()`, 18.19.0 debugsource). The two 30s are unrelated: changing one does not move the other.
* **Status**: Untouched

### Agent Status i18n

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `rep_agents_monitoring/themes/default/js/javascript.js:386`
* **Description**: Internationalize LOGOUT status label.
* **Status**: Untouched

### Transfer Agent Attribution

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: 2026-03-08 (Change #44)
* **Date Updated**: 2026-03-09
* **Location**: Reports and queries; the in-code TODO at `AMIEventProcess.class.php:3142-3144`
* **Description**: When a call is transferred between agents, `id_agent` in `calls`/`call_entry` is overwritten to the target agent. However, complete agent history IS preserved in `call_progress_log` with timeline tracking. The `transfer` column contains the target agent's extension number (not ID). Most reports only query `calls.id_agent` which shows the final agent; need to update reports to expose multi-agent call history from `call_progress_log`.
* **Status**: Partially Solved (data exists in `call_progress_log`, UI needs improvement)

### Hold Timeout Countdown

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-08-30
* **Location**: `AMIEventProcess.class.php:4562-4563`, `agent_console/`
* **Description**: When agent puts call on hold, call is parked in Asterisk with a configurable timeout (900 seconds, the `parkingtime` of the `callcenter_hold` lot in `/etc/asterisk/res_parking_custom_general.conf`; Change #69). The `ParkedCall` AMI event includes `Timeout` parameter (seconds until auto-return) but this is NOT displayed to agents, so an agent has no warning that a held call is about to come back. Add a countdown timer showing the time REMAINING until the call returns. Implementation requires: store the parking timeout in the `Llamada` object, include `parking_timeout` in the ECCP agent status XML, add a JavaScript countdown, and add the UI element. The original request also asked for an on-hold status with a distinct colour in the status bar and an elapsed-hold counter; both were delivered by Change #61, which leaves only the countdown. Note that since Change #69 the timeout no longer returns the caller to the agent — `park-return-routing` has no entry for the 70xxx slots, so Asterisk hangs the parked channel up and the dialer finalizes the call — so the countdown is a warning that the call is about to *end*.
* **Status**: Partially Solved

### Overcommit Answer-Ratio Window

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-10-25 (design decided)
* **Location**: `CampaignProcess.class.php:1335-1384` (the overcommit block in `_processCampaignWithAllocation()`); the new setting in `ConfigDB.class.php` (definition and default, beside `overcommit`), `callcenter_config/index.php` (key map, default, form field), `paloSantoConfiguration.class.php` (default) and `callcenter_config/lang/*.lang`; `DIALER_LOGIC.md` "Enable Overcommit of Outgoing Calls"
* **Today**: With overcommit on ("Enable overcommit of outgoing calls", `dialer.overcommit`, off by default), every review cycle (3 s, `INTERVALO_REVISION_CAMPANIAS`) counts the campaign's calls whose dial attempt ended in the last 30 minutes: `fecha_llamada` is stamped when Asterisk's Originate result arrives (`Llamada.class.php:796`), and `Placing` rows are left out. It counts numbers, not attempts: a retried number goes back to `Placing` with `fecha_llamada` cleared (`CampaignProcess.class.php:1508`) and drops out until its retry ends. With at least 10 counted calls, at least one of them answered, the ratio is answered ÷ counted. Answered is every status except `Failure` (never connected: busy, unreachable, rejected, or not answered within the dial timeout) and `NoAnswer` (connected, but hung up before reaching the queue), so `Ringing`, `OnQueue`, `Success`, `OnHold`, `ShortCall` and `Abandoned` all count. The calls to place are divided by `max(ratio, 0.20)` and capped at the effective channel limit. There is no start delay: the 30 minutes, hardcoded, is only how far back each calculation looks. The original TODO asked whether that window could be auto-calculated; its comment went with the dead `_actualizarLlamadasCampania()` in `e5285df` (2026-08-30), and the live copy from the 3-pass rewrite (`7818debd`, 2026-03-12) never carried it.
* **Change**: Take the ratio from **the newest 100 calls that finished in the last X minutes**.
  - **X** is a new setting on Call Center Config, next to "Enable overcommit of outgoing calls": minutes, default 30 (today's value), capped in the form (240, say) so it cannot reach back into the previous day's session. It is stored in `valor_config` like `dialer.overcommit`, with its label through `_tr()` in every `lang/*.lang`. A box without the row uses the default, so the update needs no SQL.
  - **100** is fixed in the code, a literal in the SQL (a subquery ordered by `fecha_llamada DESC`). A ratio from n calls has a margin of about ±2·√(p(1−p)/n), which depends on n alone: at a 30% answer rate, ±29 points from 10 calls, ±13 from 50, ±9 from 100, ±5 from 400. Around 100 is where more calls stop helping much, the same for every campaign, so there is nothing per box to tune.
  - **Start unchanged**: at least 10 counted calls in the sample, with no time delay.
  - **Recalculated every cycle** (3 s), as today, and the calls to place with it. The cutoff time is computed in PHP, as now.
* **Why both numbers**: The minutes keep the sample fresh, from the same time of day. As long as X is shorter than the overnight pause, nothing from yesterday is used: a 9:00-18:00 campaign starts each morning without overcommit and takes its first ratio from that morning's first 10 calls, never from yesterday's closing pace. The 100 calls keep a busy campaign quick to follow a sudden rise in the answer rate (a trunk back after an outage, retries giving way to fresh numbers). That is the dangerous direction: a 30-minute window holds the old, low ratio for up to 30 minutes, the dialer over-dials, and answered customers find no agent and are abandoned. A campaign finishing 100 calls in 5 minutes clears it in 5. A sudden drop errs on the safe side (fewer calls, idle agents). A slow campaign that finishes fewer than 100 calls in X minutes behaves exactly as today.
* **Rejected**: (1) Starting each morning from yesterday's ratio at the same hour: it brings yesterday back (another part of the list, where today's retries answer less than yesterday's fresh numbers, and maybe another weekday) for more code, and this morning's calls are better data after a few minutes. Accepted cost: the first ratio each morning comes from 10 calls, as today. (2) Holding the ratio for 10 minutes between recalculations: it would lock in the roughest figure, the first from 10 calls, and make over-dialing after a sudden rise last longer.
* **Status**: Untouched

### New Queue Appearance

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `rep_incoming_calls_monitoring/themes/default/js/javascript.js:106`
* **Description**: Handle dynamic queue appearance in monitoring. Currently "no se maneja todavia aparicion de nueva cola" (new queue appearance not handled yet).
* **Status**: Untouched

### New Agent in Queue

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `rep_agents_monitoring/themes/default/js/javascript.js:439`
* **Description**: Handle agents appearing in new queues dynamically. Currently "no se maneja todavia aparicion de agente en nueva cola" (new agent in queue not handled yet).
* **Status**: Untouched

### Configuration Consolidation

* **Type**: Refactor
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `ECCPConn.class.php:1525-1528`, `CampaignProcess.class.php:2078-2083`
* **Description**: Single configuration definition for FreePBX config file opening. Duplicate TODO exists in both files. Both copies also carry a second TODO: whether the `/etc/amportal.conf` path is worth a setting in the dialer config.
* **Status**: Untouched

### Monitoring Directory

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `SQLWorkerProcess.class.php:1127-1129`
* **Also Affects**: `paloSantoCallsDetail.class.php:562-564`, `paloSantoMonitoring.class.php:26`, `monitoring/configs/default.conf.php:28`, `HardDrives/index.php:163`
* **Description**: Recording directory hardcoded to `/var/spool/asterisk/monitor/`. FreePBX allows customization via `ASTSPOOLDIR` and `MIXMON_DIR` settings in `issabelpbx_settings` table or `/etc/asterisk/asterisk.conf`. Dialer already connects to FreePBX DB via `_abrirConexionFreePBX()`.
* **Solution Options**: (1) Query `issabelpbx_settings` table for `ASTSPOOLDIR`/`MIXMON_DIR`, (2) Parse `/etc/asterisk/asterisk.conf` for `astspooldir`, (3) Add to dialer config file.
* **Impact**: Low - most installations use default path. Only affects custom configurations.
* **Status**: Untouched

### Form Field Length

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `ECCPConn.class.php:1023-1024`
* **Description**: Allow specifying input field length. Currently hardcoded to 250.
* **Status**: Untouched

### Form Designer Default Value Support

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: 2011-02-02
* **Date Updated**: 2026-03-09
* **Location**: `modules/form_designer/`; the in-code TODO at `ECCPConn.class.php:1045-1052`, which still says agent_console lacks the support
* **Description**: Form support for default/initial values. ECCP protocol and agent_console already support `default_value` - it is properly sent and used. The form designer web interface lacks a UI field to set default values for TEXT/TEXTAREA/DATE field types (only LIST type has configurable options). The `form_field.value` column stores defaults for non-LIST types but no way to set it via web UI.
* **Status**: Partially Implemented (backend/agent working, UI missing)

### Database Field Length

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `ECCPConn.class.php:1323-1324`
* **Description**: Extract max field length from database schema. Currently hardcoded to 250.
* **Status**: Untouched

### Incoming Call Dynamic Attributes

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-03-09
* **Location**: `ECCPHelper.lib.php:183-185`, `leerAtributosContacto()`
* **Description**: Architectural asymmetry between outgoing and incoming call attribute handling. Outgoing calls support dynamic custom attributes via `call_attribute` table (arbitrary `columna`/`value` pairs). Incoming calls are limited to hardcoded `contact` table fields (`name`, `apellido`, `telefono`, `cedula_ruc`, `origen`). No `call_entry_attribute` table exists. ECCP protocol sends `call_attributes` identically for both types, but incoming calls cannot receive custom attributes. Implementation would require: create `call_entry_attribute` table, modify `leerAtributosContacto()`, add ECCP write methods, update incoming campaign UI.
* **Status**: Untouched

### Agent List Dropdown

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `rep_agent_information/index.php:115,348-354`
* **Description**: Agent filter field is currently a TEXT input requiring manual entry of agent number. No validation that the agent belongs to the selected queue. Agent-queue membership is managed at Asterisk level (not in database), making real-time membership lookup challenging.
* **Solutions**:
  - **Historical (Recommended)**: Query `call_entry` for agents who have taken calls in the selected queue - simple but shows all historical agents, not current membership
  - **ECCP**: Use dialer's `getagentqueues` for real-time membership - requires running dialer
  - **Config**: Parse `/etc/asterisk/queues_additional.conf` - real-time but complex
* **Status**: Untouched

### Recording File Convention

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-03-09
* **Location**: `paloSantoCallsDetail.class.php:569`
* **Description**: Recording filenames follow Asterisk/FreePBX convention (e.g., `q-502-0100100100-20260306-133601-uniqueid.wav` for incoming, `out-src-dst-date-time-uniqueid.wav` for outgoing). The `getRecordingFilePath()` function returns `basename()` as display name (second array element). TODO requests campaign-friendly naming (e.g., `SalesCampaign-JohnDoe-20260306.wav`) but lacks specification. Implementation requires: (1) Define campaign filename template, (2) Dialplan changes for Asterisk filename generation OR post-recording rename with DB tracking, (3) Add campaign context to retrieval queries.
* **Status**: Untouched

### Pagination Limit Configuration

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-03-09
* **Location**: `calls_detail/index.php:73`
* **Description**: Calls Detail report has hardcoded pagination limit of 50 records per page (`$limit = 50;`). No centralized configuration exists for pagination limits across Call Center modules - each uses its own hardcoded value (campaign_in: 50, external_url: 15, etc.). Users cannot adjust page size. Implementation options: (1) Global constant in config file, (2) Per-user session preference, (3) Database configuration in `valor_config` table, (4) FreePBX settings table integration.
* **Status**: Untouched

### Report i18n

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-03-09
* **Location**: `rep_agent_information/index.php:104-109`
* **Description**: Hardcoded English error message when no incoming queues configured. Uses PHP heredoc instead of `_tr()` function and Smarty template. Text: "No queues have been defined for incoming calls..." with hardcoded menu links. Module already has i18n infrastructure (`lang/*.lang` files) but this message was missed. Fix requires: (1) Add translation keys to all lang files (en, es, fr, fa, ru), (2) Replace heredoc with `_tr()` calls, (3) Optionally move to template for better separation. Only displays in misconfigured systems (no queues defined).
* **Status**: Untouched

### Follow-up Termination

* **Type**: Investigation
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `AMIEventProcess.class.php:4238-4246`
* **Description**: Behavior only adequate under certain queue conditions. Documents limitation with outgoing campaign queues as destinations. Works in practice.
* **Status**: Untouched

### Duplicate Extension Listing

* **Type**: Refactor
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `paloSantoConsola.class.php:242-243`, `ECCPConn.class.php:1595` (`_listarExtensiones()`)
* **Description**: Identical SQL query (`SELECT user AS extension, dial from devices ORDER BY user`) exists in two places for listing SIP/IAX extensions. Web module uses `paloDB` (mysqli) with `die()` on error; dialer uses PDO with logging. Used by: agent login dropdown (web), ECCP login validation (dialer).
* **Challenges**: Different DB layers, different error strategies (per-request vs daemon), no shared library path between web modules and dialer.
* **Recommendation**: Accept as intentional duplication - appropriate for different runtime contexts. Consider removing TODO comment and documenting rationale in code.
* **Status**: Untouched

### Campaign Contacts SQL

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-03-09
* **Location**: `paloSantoIncomingCampaign.class.php:488-489`
* **Description**: Placeholder for future "contacts per campaign" feature in incoming campaigns. **Current architecture**: Incoming campaigns use **global** `contact` table shared across all campaigns (lookup by `call_entry.id_contact`). Outgoing campaigns have campaign-specific contacts via `calls` table. **TODO intent**: If incoming campaigns were given their own contact lists (like outgoing), the `delete_campaign()` function would need SQL to delete campaign-specific contacts (e.g., `DELETE FROM campaign_contact_entry WHERE id_campaign = ?`). **Note**: This is a design note for hypothetical future enhancement, not an active bug. Current global contact model works but has limitations: same phone number cannot have different attributes per campaign, no data isolation between campaigns.
* **Status**: Untouched

### Customer Name Taken From Attribute 1

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `agent_console/index.php:1109-1115` (the call's information panel), `agent_console/index.php:2247-2253` (the `agentlinked` update)
* **Description**: For an outgoing call the console shows the customer's name from `call_attributes[1]`, the first column of the uploaded list, or "(unavailable)" when it has none. A list whose first column is not the name shows the wrong text under "Names". The in-code TODO, inherited from the old call center, asks for a way to build the name from any combination of columns, for example a per-campaign choice of the name column(s).
* **Status**: Untouched

### Break Button Waits for the Status Check

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `agent_console/themes/default/js/javascript.js:762`, `:785` (`do_break()`, `do_unbreak()`)
* **Description**: After Take Break or End Break succeeds, the console does not change its state itself: it leaves that to the next periodic agent-status check. The in-code TODO asks for `agentbreakenter` and `agentbreakexit` events, so the status bar changes as soon as the break starts or ends.
* **Status**: Untouched

### Console Timer Comments

* **Type**: Cleanup
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `agent_console/index.php:815-816`, `agent_console/index.php:881`
* **Description**: Two in-code TODOs ask for timers the code seems to have already. The break timer "must hold the break time accumulated since login, summing break starts and ends in a session variable": it adds `$_SESSION['callcenter']['break_acumulado']` (break accumulated, `:808`), which is summed at each break's end (`:831`, `:1825`). The call timer "must hold the time elapsed in the call": it counts from the call's `linkstart` (`:870`). Check both on a box (a second break after a first; a call after a transfer or a hold), then remove the comments or record what is still wrong.
* **Status**: Untouched

### Campaign Monitoring: Hold Start and Scheduled-Call Pause

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `campaign_monitoring/index.php:625`, `:627`, `:642` (the `pausestart`/`pauseend` handlers), `:875`, `:880` (`formatoAgente()`)
* **Description**: For an agent on hold the page shows "Hold" with no "since" time (`-`): the `pausestart` handler sets only `onhold`, not when the hold began, and `pauseend` has nothing to clear. The pause the dialer puts an agent in while reserving them for a scheduled call ("Configurable Scheduled-Call Reservation Window") is not shown at all: neither handler has a case for it, and `formatoAgente()` has no label for it.
* **Status**: Untouched

### Outgoing Campaigns Outside Their Schedule Are Not Purged

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `AMIEventProcess.class.php:695-696` (`_nuevasCampanias()`)
* **Description**: The in-code TODO asks to drop outgoing campaigns that are outside their daily schedule from `_campaniasSalientes` (outgoing campaigns): the method only adds new ones. What keeping them costs (memory, per-cycle work, stale state at the next day's start) has not been measured.
* **Status**: Untouched

### QueueMemberStatus Ignores the Paused Flag

* **Type**: Investigation
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `AMIEventProcess.class.php:4191-4192` (`msg_QueueMemberStatus()`)
* **Description**: The handler passes only the queue and the status to `Agente::actualizarEstadoEnCola()` (update status in queue); the event's `Paused` field is ignored there, although `QueueShadow` records it for the same member (`QueueShadow.class.php:190`). Decide whether the agent object needs it, for example to notice a pause made outside the dialer.
* **Status**: Untouched

### Hold End Restates the Current-Calls Update

* **Type**: Refactor
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `SQLWorkerProcess.class.php:1332-1333` (`_marcarFinalHold()`), `SQLWorkerProcess.class.php:1040` (`_sqlupdatecurrentcalls()`)
* **Description**: `_marcarFinalHold()` (mark end of hold) writes `current_calls`/`current_call_entry` and the call's status with its own SQL, which the in-code TODO notes is what `_sqlupdatecurrentcalls()` already does. Have it call that method.
* **Status**: Untouched

### Two Sources for the Agent's Remote Channel

* **Type**: Refactor
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `ECCPConn.class.php:2743-2746`
* **Description**: The agent status reply reports `remote_channel` (`callchannel` when flattened) from `Agente::resumenSeguimiento()`'s `clientchannel`. The in-code TODO notes that it is the same value as `actualchannel` in `Llamada::resumenLlamada()`. A note on the duplication; nothing is wrong today.
* **Status**: Untouched

### QueueShadow Ring Events Do Nothing

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `QueueShadow.class.php:264-265` (`msg_AgentCalled`), `QueueShadow.class.php:325-326` (`msg_AgentDump`)
* **Description**: Both handlers only mark the queue as sending `eventwhencalled` events; the in-code TODOs ask what else they could do. `AgentCalled` is the start of the ring count that "Exact Predictive Count for Ringing Callback Agents" needs; `AgentDump` (the caller hung up while a member was ringing) is one of the events that ends it.
* **Status**: Untouched

### Script Editor Escape Function

* **Type**: Investigation
* **Urgency**: Low
* **Date Added**: 2026-10-25
* **Location**: `campaign_out/index.php:852` and `campaign_in/index.php:641` (`adaptar_formato_rte()`, two identical copies)
* **Description**: The function prepares a campaign's script text for loading into the rich-text editor: it turns curly quotes into straight ones, single quotes into `&#39;`, and line breaks into spaces. The in-code TODO asks to check what escaping is actually needed. Check it against the editor the pages load today, and keep one copy.
* **Status**: Untouched

### Attended Transfer to the Agent's Own Extension

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: 2026-10-27
* **Location**: `setup/dialer_process/dialer/ECCPConn.class.php:3400-3404` (`Request_agentauth_atxfercall()`)
* **Description**: The attended-transfer request checks only that the target is digits, so it accepts the agent's own extension: for an Agent-type login, the extension the agent logged in from, whose phone carries the session call (the request already reads its `login_channel`, `:3421-3432`); for a callback login, the agent's own extension. A transfer to the phone that is already on the call has no use, and what it does has not been measured. Refuse it before any `Redirect`, with a failure the console shows.
* **Status**: Untouched

### Disconnection Reason in the Reports

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: 2026-10-27
* **Location**: `setup/call_center.sql` (`audit`, `call_entry`, `calls`), `modules/login_logout`, `modules/calls_detail`
* **Description**: The reports do not say why an agent's session or a call ended. A session row (`audit`) has only its start and end times, an incoming call row (`call_entry`) only its status, and an outgoing call row (`calls`) has `failure_cause` only for a call that never connected. The dialer knows why it ends a session: the agent's own logout, a logoff from the console or by a supervisor, the end of an Agent-type agent's session call, a phone that unregistered or stopped answering qualify, a removal from all the agent's queues, a dialer stop or restart. A call has its own reasons: which side hung up, Asterisk's hangup cause (a BYE is 16, a lost phone's RTP timeout 44), or the dialer ending it at a session end. Record the reason with the row and show it in Login Logout and Calls Detail. Seen on a lab box on 2026-10-27: the dialer logged an agent off, mid-call, because its phone's registration had lapsed for ten seconds, and nothing on screen said why.
* **Status**: Untouched

---

## Deferred

The following items are deferred due to being third-party libraries or acceptable technical debt:

* **Call Info Duplication** (`ECCPConn.class.php:2611-2616`) - Backwards compatibility requirement
* **jQuery Layout Plugin (v1.4.0)** - All 15 items deferred; recommendation is to replace with modern alternative
* **Handlebars Compilation** (`handlebars-1.3.0.js:375`) - Use newer Handlebars if needed

---

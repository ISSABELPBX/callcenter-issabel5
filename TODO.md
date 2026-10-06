# TODO - Call Center Issabel 5

Unresolved issues for the call center module. Items are sorted by urgency (Critical → High → Medium → Low). Within each urgency level, untouched items appear first, followed by partially solved items.

**Last Reviewed**: 2026-08-29

`Change #N` references point to the numbered entries in `CHANGES_PRE.md`.

---

## High

### ECCP Client Authorization

* **Type**: Feature
* **Urgency**: High
* **Date Added**: Original (pre-2011)
* **Location**: `ECCPConn.class.php:344-347`
* **Description**: Implement `eccp_authorized_clients` table for agent authorization. The table exists and is used for authorization lookup, but could be expanded for IP/client-based authorization.
* **Status**: Partially Solved

---

## Medium

### RINGING-as-Free Analysis

* **Type**: Investigation
* **Urgency**: Medium
* **Date Added**: 2026-03-06 (Change #36)
* **Location**: `QueueShadow.class.php`, `Predictor.class.php`
* **Description**: Even in predictive mode, counting RINGING agents as "free" may cause over-placement. Analysis needed to determine if this behavior is optimal.
* **Status**: Untouched

### Attended-Transfer Hold Has No Orphan Check

* **Type**: Bug
* **Urgency**: Medium
* **Date Added**: 2026-08-30 (Change #70)
* **Location**: `extensions_custom.conf:33`, `setup/installer.php:357`
* **Description**: `[atxfer-hold]` is a bare `MusicOnHold(,1800)` with no check that the agent who put the caller there still exists, so any path that ever leaves a caller in it means up to 30 minutes of music with nobody on the other end. Change #70 removed the one known such path (the `Bridge()` thread race) and added a `SoftHangup` backstop inside `[atxfer-rebridge]`, but the context itself is still a dead end: the residual exposure is a caller orphaned during the ~2 s reconnect window, or by any future path. The `1800` is also now out of step with the 900 s hold cap the `callcenter_hold` parking lot got in Change #69. Proposed fix: have the dialer `SetVar` the agent's channel name onto the client channel at both `Redirect` sites in `ECCPConn::Request_agentauth_atxfercall()`, then run the hold as chunked `MusicOnHold` slices that bail out via `CHANNEL_EXISTS()` once that channel is gone. `res_musiconhold` restores the saved position for `mode=files` classes, so chunking does not restart the music. Deferred from Change #70 to keep that fix to a single file.
* **Status**: Untouched

### Campaign Finish Check Disagrees With the Dial Query

* **Type**: Bug
* **Urgency**: Medium
* **Date Added**: 2026-09-22
* **Location**: `CampaignProcess.class.php:1194-1220`, `CampaignProcess.class.php:2061-2087`
* **Description**: `_checkCampaignDataExhausted()` decides a campaign is exhausted by counting rows matching `(status IS NULL OR status NOT IN ("Success","Placing","Ringing","OnQueue","OnHold")) AND retries < campaign.retries AND dnc = 0`. The dial query that actually places the calls adds two restrictions the count does not: `agent IS NULL`, and either all four of `date_init`/`date_end`/`time_init`/`time_end` NULL (branch 2) or a full current-window match (branch 1). Any row that is counted but not dialable blocks the campaign from ever reaching `T`: it is never selected for dialing, so its `retries` never increments, so it stays below the limit permanently and the campaign stays `Active` forever. Two shapes trigger it -- a row with a partially-set window (e.g. `date_init` set but `time_init` NULL, matching neither branch), and a row with a non-NULL `agent` that `_actualizarLlamadasAgendables()` never picks up. Verified latent on the client box on 2026-09-22 (0 such rows across all 8 campaigns), so this is a trap waiting on the first partial-window or orphaned-agent row, not an active fault.
* **Recommendation**: Have the two sites share one predicate instead of restating it, so the finish check can never count a row the dialer will not dial.
* **Status**: Untouched

### Campaign List Needs a 'Retries Left' Column

* **Type**: Feature
* **Urgency**: Medium
* **Date Added**: 2026-09-22
* **Location**: `paloSantoCampaignCC.class.php:88-90`, `campaign_out/index.php:195`, `campaign_out/index.php:211-213`, `campaign_out/lang/*.lang`
* **Description**: The campaign list's `Pending Calls` column counts only never-originated rows (`status IS NULL`, `paloSantoCampaignCC.class.php:90`), but the dialer will not mark a campaign `Finish` until every non-`Success` row has also used up its retries (`_checkCampaignDataExhausted()`). The visible column is therefore not the one that governs the status, and a campaign reading `Pending Calls = 0` sits at `Active` with no on-screen explanation. Seen on the client box on 2026-09-22: campaigns 6, 7 and 8 (`ADSL - Q3'26 - VF/ET/OR`) each showed `Pending Calls = 0` at status `Active` while still holding 1507, 1250 and 456 retryable rows against a retry limit of 5 -- correct dialer behaviour, invisible in the GUI.
* **Implementation**: Add a `Retries Left` column that **counts the remaining numbers that still have retries available -- one row per phone number still callable -- not the sum of retries remaining and not the retries already consumed**. For campaign 6 above the column reads `1507`. Deliberately reuse the dialer's own exhaustion predicate so the column reaches 0 exactly when the dialer becomes entitled to set `Finish` -- as a `getCampaigns()` subquery: `(SELECT COUNT(*) FROM calls WHERE id_campaign = c.id AND (status IS NULL OR status NOT IN ('Success','Placing','Ringing','OnQueue','OnHold')) AND retries < c.retries AND dnc = 0) AS retries_left`. Then add the cell to the `$arrData[]` row and the header to `setColumns()`, keeping both arrays in the same order; the label goes through `_tr()` with entries in every `lang/<lang>.lang` (en, es, fr, ru, cn, tr, fa).
* **Open decision**: The predicate above includes never-originated rows, so `Retries Left` is a superset of `Pending Calls` (on the client box campaign 9 showed 13578 pending against 15039 retries-left). Confirm whether that overlap is wanted, or whether never-originated rows should be excluded so the two columns are disjoint -- excluding them breaks the "reaches 0 when the dialer finishes it" property, so the overlap is probably the right call.
* **Status**: Untouched

### Hold Timeout Countdown

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-08-30
* **Location**: `AMIEventProcess.class.php:4014-4015`, `agent_console/`
* **Description**: When agent puts call on hold, call is parked in Asterisk with a configurable timeout (900 seconds, the `parkingtime` of the `callcenter_hold` lot in `/etc/asterisk/res_parking_custom_general.conf`; Change #69). The `ParkedCall` AMI event includes `Timeout` parameter (seconds until auto-return) but this is NOT displayed to agents, so an agent has no warning that a held call is about to come back. Add a countdown timer showing the time REMAINING until the call returns. Implementation requires: store the parking timeout in the `Llamada` object, include `parking_timeout` in the ECCP agent status XML, add a JavaScript countdown, and add the UI element. The original request also asked for an on-hold status with a distinct colour in the status bar and an elapsed-hold counter; both were delivered by Change #61, which leaves only the countdown. Note that since Change #69 the timeout no longer returns the caller to the agent — `park-return-routing` has no entry for the 70xxx slots, so Asterisk hangs the parked channel up and the dialer finalizes the call — so the countdown is a warning that the call is about to *end*.
* **Status**: Partially Solved

---

## Low

### Schedule Call to Different Campaign

* **Type**: Feature
* **Urgency**: Medium
* **Date Added**: 2026-03-09
* **Location**: `ECCPConn.class.php:3079`
* **Description**: Allow scheduled callbacks to be sent to a different campaign than the original call. Currently, scheduled callbacks use the same campaign as the original call (`id_campaign` is hardcoded from original call).
* **Status**: Untouched

### Configurable Dial Timeout

* **Type**: Feature
* **Urgency**: Medium
* **Date Added**: Original (pre-2011)
* **Location**: `CampaignProcess.class.php:1891-1892`
* **Description**: Make timeout (30 sec) configurable per campaign. Currently hardcoded in `_getSegundosReserva()`.
* **Status**: Untouched

### Agent Status i18n

* **Type**: Improvement
* **Urgency**: Medium
* **Date Added**: Original (pre-2011)
* **Location**: `rep_agents_monitoring/js/javascript.js:234`
* **Description**: Internationalize LOGOUT status label.
* **Status**: Untouched

### Transfer Agent Attribution

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: 2026-03-08 (Change #44)
* **Date Updated**: 2026-03-09
* **Location**: Reports and queries
* **Description**: When a call is transferred between agents, `id_agent` in `calls`/`call_entry` is overwritten to the target agent. However, complete agent history IS preserved in `call_progress_log` with timeline tracking. The `transfer` column contains the target agent's extension number (not ID). Most reports only query `calls.id_agent` which shows the final agent; need to update reports to expose multi-agent call history from `call_progress_log`.
* **Status**: Partially Solved (data exists in `call_progress_log`, UI needs improvement)

### Auto-calculate History Window

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `CampaignProcess.class.php:1477-1478`
* **Description**: Auto-calculate history window (30 min hardcoded).
* **Status**: Untouched

### New Queue Appearance

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `rep_incoming_calls_monitoring/js/javascript.js:106`
* **Description**: Handle dynamic queue appearance in monitoring. Currently "no se maneja todavia aparicion de nueva cola" (new queue appearance not handled yet).
* **Status**: Untouched

### New Agent in Queue

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `rep_agents_monitoring/js/javascript.js:341`
* **Description**: Handle agents appearing in new queues dynamically. Currently "no se maneja todavia aparicion de agente en nueva cola" (new agent in queue not handled yet).
* **Status**: Untouched

### Configuration Consolidation

* **Type**: Refactor
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `ECCPConn.class.php:1492-1495`, `CampaignProcess.class.php:2240-2241`
* **Description**: Single configuration definition for FreePBX config file opening. Duplicate TODO exists in both files.
* **Status**: Untouched

### Monitoring Directory

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `SQLWorkerProcess.class.php:900-902`
* **Also Affects**: `paloSantoCallsDetail.class.php:535`, `paloSantoMonitoring.class.php:26`, `monitoring/configs/default.conf.php:28`, `HardDrives/index.php:163`
* **Description**: Recording directory hardcoded to `/var/spool/asterisk/monitor/`. FreePBX allows customization via `ASTSPOOLDIR` and `MIXMON_DIR` settings in `issabelpbx_settings` table or `/etc/asterisk/asterisk.conf`. Dialer already connects to FreePBX DB via `_abrirConexionFreePBX()`.
* **Solution Options**: (1) Query `issabelpbx_settings` table for `ASTSPOOLDIR`/`MIXMON_DIR`, (2) Parse `/etc/asterisk/asterisk.conf` for `astspooldir`, (3) Add to dialer config file.
* **Impact**: Low - most installations use default path. Only affects custom configurations.
* **Status**: Untouched

### Form Field Length

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `ECCPConn.class.php:990-991`
* **Description**: Allow specifying input field length. Currently hardcoded to 250.
* **Status**: Untouched

### Form Designer Default Value Support

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: 2011-02-02
* **Date Updated**: 2026-03-09
* **Location**: `modules/form_designer/`
* **Description**: Form support for default/initial values. ECCP protocol and agent_console already support `default_value` - it is properly sent and used. The form designer web interface lacks a UI field to set default values for TEXT/TEXTAREA/DATE field types (only LIST type has configurable options). The `form_field.value` column stores defaults for non-LIST types but no way to set it via web UI.
* **Status**: Partially Implemented (backend/agent working, UI missing)

### Database Field Length

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `ECCPConn.class.php:1290-1291`
* **Description**: Extract max field length from database schema. Currently hardcoded to 250.
* **Status**: Untouched

### Incoming Call Dynamic Attributes

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-03-09
* **Location**: `ECCPHelper.lib.php:121-123`, `leerAtributosContacto()`
* **Description**: Architectural asymmetry between outgoing and incoming call attribute handling. Outgoing calls support dynamic custom attributes via `call_attribute` table (arbitrary `columna`/`value` pairs). Incoming calls are limited to hardcoded `contact` table fields (`name`, `apellido`, `telefono`, `cedula_ruc`, `origen`). No `call_entry_attribute` table exists. ECCP protocol sends `call_attributes` identically for both types, but incoming calls cannot receive custom attributes. Implementation would require: create `call_entry_attribute` table, modify `leerAtributosContacto()`, add ECCP write methods, update incoming campaign UI.
* **Status**: Untouched

### Contact Formatting

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `agent_console/index.php:1060-1062, 2030-2032`
* **Description**: Proper formatting for contact calls with arbitrary attributes.
* **Status**: Untouched

### Campaign Form Label

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `agent_console/index.php:1409`
* **Description**: Assign label from campaign form. Currently hardcoded to empty string.
* **Status**: Untouched

### Agent List Dropdown

* **Type**: Improvement
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `rep_agent_information/index.php:111,348-354`
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
* **Location**: `paloSantoCallsDetail.class.php:522-542`
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

### Query Error Display

* **Type**: Investigation
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `paloSantoConfiguration.class.php:68`
* **Description**: Determine what to display if query fails.
* **Status**: Untouched

### Follow-up Termination

* **Type**: Investigation
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `AMIEventProcess.class.php:3714-3719`
* **Description**: Behavior only adequate under certain queue conditions. Documents limitation with outgoing campaign queues as destinations. Works in practice.
* **Status**: Untouched

### Duplicate Extension Listing

* **Type**: Refactor
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `paloSantoConsola.class.php:240-254`, `ECCPConn.class.php:1562-1578`
* **Description**: Identical SQL query (`SELECT user AS extension, dial from devices ORDER BY user`) exists in two places for listing SIP/IAX extensions. Web module uses `paloDB` (mysqli) with `die()` on error; dialer uses PDO with logging. Used by: agent login dropdown (web), ECCP login validation (dialer).
* **Challenges**: Different DB layers, different error strategies (per-request vs daemon), no shared library path between web modules and dialer.
* **Recommendation**: Accept as intentional duplication - appropriate for different runtime contexts. Consider removing TODO comment and documenting rationale in code.
* **Status**: Untouched

### Campaign Contacts SQL

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-03-09
* **Location**: `paloSantoIncomingCampaign.class.php:468-469`
* **Description**: Placeholder for future "contacts per campaign" feature in incoming campaigns. **Current architecture**: Incoming campaigns use **global** `contact` table shared across all campaigns (lookup by `call_entry.id_contact`). Outgoing campaigns have campaign-specific contacts via `calls` table. **TODO intent**: If incoming campaigns were given their own contact lists (like outgoing), the `delete_campaign()` function would need SQL to delete campaign-specific contacts (e.g., `DELETE FROM campaign_contact_entry WHERE id_campaign = ?`). **Note**: This is a design note for hypothetical future enhancement, not an active bug. Current global contact model works but has limitations: same phone number cannot have different attributes per campaign, no data isolation between campaigns.
* **Status**: Untouched

### ECCP Proxy

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `ECCPProxyConn.class.php:102-111`
* **Description**: TODO proposes dedicated DB worker for AMI events. Current architecture: `ECCPWorkerProcess` pool handles both client ECCP requests and DB writes from `AMIEventProcess`. Hypothetical concern: busy pool could delay time-sensitive events or affect ordering. System has run since pre-2011 without issues; minimum 2 workers in pool. Low priority - no evidence of actual problems.
* **Recommendation**: Remove TODO if monitoring confirms no latency issues. If needed, create separate `EventDBWorkerProcess` class.
* **Status**: Untouched

### Queue List in Monitoring

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Location**: `paloSantoConsola.class.php:1140-1143`, `SQLWorkerProcess.class.php:969`, `ECCPProxyConn.class.php:266-277`
* **Description**: `agentloggedout` event only contains agent channel, not the queues they were logged into. Limits usefulness for monitoring consoles tracking queue membership changes. Compare with `queuemembership` event which includes `<queues>` list. Agent's queues available via `_listarTotalColasTrabajoAgente()` at logout time.
* **Implementation**: (1) Get queue list in `SQLWorkerProcess._AgentLogoff()` before clearing state, (2) Pass queues with `AgentLogoff` event, (3) Update `ECCPProxyConn.notificarEvento_AgentLogoff()` to include queues in XML, (4) Handle queues in `paloSantoConsola` `agentloggedout` case.
* **Status**: Untouched

### Monitoring Implementation

* **Type**: Feature
* **Urgency**: Low
* **Date Added**: Original (pre-2011)
* **Date Updated**: 2026-03-09
* **Location**: `paloSantoConsola.class.php:1132-1143`
* **Description**: ECCP login/logout events not parsed for monitoring console. **Issue**: The dialer sends `agentloggedin`, `agentfailedlogin`, and `agentloggedout` events via ECCP, but `paloSantoConsola::esperarEventoSesionActiva()` returns **empty events** (just `break`). **Impact**: Monitoring modules (`rep_agents_monitoring`, `rep_incoming_calls_monitoring`) cannot show real-time login/logout/fail events; rely on periodic `getstatus` polling instead. **Two instances**: (1) `agentloggedin` (line 1132-1135) - should return agent_number, queues[], session_start, (2) `agentfailedlogin` (line 1136-1139) - should return agent_number, reason. Also `agentloggedout` (line 1140-1143) partially implemented but missing queue list. **Implementation requires**: Parse event XML from `$evt`, populate `$evento` array with fields, verify ECCP server sends data.
* **Status**: Untouched

### Mid-Call Logoff Hangup Target

* **Type**: Bug
* **Urgency**: Low
* **Date Added**: 2026-10-03
* **Location**: `setup/dialer_process/dialer/AMIEventProcess.class.php` (`_ejecutarLogoffAgente`, the `Hangup($llamada->agentchannel)` call)
* **Description**: For an Agent-type login, `agentchannel` is the device name `Agent/NNNN`, never a channel. So every logoff with a call still up logs "No such channel" and spends one synchronous AMI round trip; the fallback below it finalises the call. `actualAgentChannel` holds the real channel (`Llamada.class.php`). But hanging that up would make a mid-call logoff, from the console button or a supervisor, drop the caller: decide that behaviour first.
* **Status**: Untouched

---

## Deferred

The following items are deferred due to being third-party libraries or acceptable technical debt:

* **Call Info Duplication** (`ECCPConn.class.php:2611-2616`) - Backwards compatibility requirement
* **jQuery Layout Plugin (v1.4.0)** - All 15 items deferred; recommendation is to replace with modern alternative
* **Handlebars Compilation** (`handlebars-1.3.0.js:375`) - Use newer Handlebars if needed

---

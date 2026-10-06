<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 0.5                                                  |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | The contents of this file are subject to the General Public License  |
  | (GPL) Version 2 (the "License"); you may not use this file except in |
  | compliance with the License. You may obtain a copy of the License at |
  | http://www.opensource.org/licenses/gpl-license.php                   |
  |                                                                      |
  | Software distributed under the License is distributed on an "AS IS"  |
  | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
  | the License for the specific language governing rights and           |
  | limitations under the License.                                       |
  +----------------------------------------------------------------------+
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
  $Id:  $ */
$DocumentRoot = "/var/www/html";

require_once("$DocumentRoot/libs/paloSantoInstaller.class.php");
require_once("$DocumentRoot/libs/paloSantoDB.class.php");

$tmpDir = '/tmp/new_module/callcenter';  # in this folder the load module extract the package content
#generar el archivo db de campañas // EN: Generate campaign db file
$return=1;
$path_script_db="$tmpDir/setup/call_center.sql";
$datos_conexion['user']     = "asterisk";
$datos_conexion['password'] = "asterisk";
$datos_conexion['locate']   = "";
$oInstaller = new Installer();

if (file_exists($path_script_db))
{
    //STEP 1: Create database call_center
    $return=0;
    $return=$oInstaller->createNewDatabaseMySQL($path_script_db,"call_center",$datos_conexion);

    // STEP 1.1: Ensure asterisk user has permissions on call_center database
    $pDBRoot = new paloDB('mysql://root:'.MYSQL_ROOT_PASSWORD.'@localhost/mysql');
    $pDBRoot->genQuery("GRANT ALL ON call_center.* TO asterisk@localhost IDENTIFIED BY 'asterisk'");
    $pDBRoot->genQuery("FLUSH PRIVILEGES");
    $pDBRoot->disconnect();
    fputs(STDERR, "INFO: Granted permissions to asterisk@localhost on call_center database | Es: Permisos concedidos a asterisk@localhost en base de datos call_center\n");

    $pDB = new paloDB ('mysql://root:'.MYSQL_ROOT_PASSWORD.'@localhost/call_center');
    quitarColumnaSiExiste($pDB, 'call_center', 'agent', 'queue');
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'dnc',
        "ADD COLUMN dnc int(1) NOT NULL DEFAULT '0'");
    crearColumnaSiNoExiste($pDB, 'call_center', 'call_entry',
        'id_campaign',
        "ADD COLUMN id_campaign  int unsigned, ADD FOREIGN KEY (id_campaign) REFERENCES campaign_entry (id)");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'date_init',
        "ADD COLUMN date_init  date, ADD COLUMN date_end  date, ADD COLUMN time_init  time, ADD COLUMN time_end  time");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'agent',
        "ADD COLUMN agent varchar(32)");
    crearColumnaSiNoExiste($pDB, 'call_center', 'call_entry',
        'trunk',
        "ADD COLUMN trunk varchar(50) NOT NULL");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'failure_cause',
        "ADD COLUMN failure_cause int(10) unsigned default null, ADD COLUMN failure_cause_txt varchar(32) default null");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'datetime_originate',
        "ADD COLUMN datetime_originate datetime default NULL");
    crearColumnaSiNoExiste($pDB, 'call_center', 'agent',
        'eccp_password',
        "ADD COLUMN eccp_password varchar(128) default NULL");
    crearColumnaSiNoExiste($pDB, 'call_center', 'campaign',
        'id_url',
        "ADD COLUMN id_url int unsigned, ADD FOREIGN KEY (id_url) REFERENCES campaign_external_url (id)");
    crearColumnaSiNoExiste($pDB, 'call_center', 'campaign_entry',
        'id_url',
        "ADD COLUMN id_url int unsigned, ADD FOREIGN KEY (id_url) REFERENCES campaign_external_url (id)");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'trunk',
        "ADD COLUMN trunk varchar(50)");
    crearColumnaSiNoExiste($pDB, 'call_center', 'agent',
        'type',
        "ADD COLUMN type enum('Agent','SIP','PJSIP','IAX2') DEFAULT 'Agent' NOT NULL AFTER id");
    // Ensure PJSIP is in the enum for existing installations
    $pDB->genQuery("ALTER TABLE agent MODIFY type enum('Agent','SIP','PJSIP','IAX2') DEFAULT 'Agent' NOT NULL");
    crearColumnaSiNoExiste($pDB, 'call_center', 'calls',
        'scheduled',
        "ALTER TABLE calls ADD COLUMN scheduled BOOLEAN NOT NULL DEFAULT 0");
    crearColumnaSiNoExiste($pDB, 'call_center', 'audit',
        'login_extension',
        "ADD COLUMN login_extension varchar(20) default NULL");

    crearIndiceSiNoExiste($pDB, 'call_center', 'audit',
        'agent_break_datetime',
        "ADD KEY agent_break_datetime (id_agent, id_break, datetime_init)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'calls',
        'datetime_init',
        "ADD KEY datetime_init (start_time)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'calls',
        'datetime_entry_queue',
        "ADD KEY datetime_entry_queue (start_time)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'call_entry',
        'datetime_init',
        "ADD KEY datetime_init (datetime_init)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'call_entry',
        'datetime_entry_queue',
        "ADD KEY datetime_entry_queue (datetime_init)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'dont_call',
        'callerid',
        "ADD KEY callerid (caller_id)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'agent',
        'agent_type',
        "ADD KEY `agent_type` (`estatus`,`type`,`number`)");
    crearIndiceSiNoExiste($pDB, 'call_center', 'calls',
        'campaign_date_schedule',
        "ADD KEY `campaign_date_schedule` (`id_campaign`, `date_init`, `date_end`, `time_init`, `time_end`)");

    // Actualizar longitud de campos trunk y ChannelClient a 50 caracteres
    // EN: Update length of trunk and ChannelClient fields to 50 characters
    actualizarLongitudCampo($pDB, 'call_center', 'call_entry', 'trunk', 50);
    actualizarLongitudCampo($pDB, 'call_center', 'call_progress_log', 'trunk', 50);
    actualizarLongitudCampo($pDB, 'call_center', 'calls', 'trunk', 50);
    actualizarLongitudCampo($pDB, 'call_center', 'campaign', 'trunk', 50);
    actualizarLongitudCampo($pDB, 'call_center', 'current_call_entry', 'ChannelClient', 50);
    actualizarLongitudCampo($pDB, 'call_center', 'current_calls', 'ChannelClient', 50);

    /* Fijar el charset por omision de la base de datos misma. El CREATE
     * DATABASE de paloSantoInstaller::createNewDatabaseMySQL() no lleva
     * charset, asi que la base hereda el del servidor (latin1 en una
     * instalacion tipica) aunque todas sus tablas sean utf8mb4. Cualquier
     * CREATE TABLE futuro sin charset explicito heredaria latin1. */
    /* Set the default charset of the database itself. The CREATE DATABASE in
     * paloSantoInstaller::createNewDatabaseMySQL() carries no charset, so the
     * database inherits the server default (latin1 on a typical install) even
     * though all of its tables are utf8mb4. Any future CREATE TABLE without an
     * explicit charset would inherit latin1. */
    $pDB->genQuery('ALTER DATABASE call_center CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    fputs(STDERR, "INFO: call_center database default charset set to utf8mb4 | Es: charset por omision de la base call_center fijado a utf8mb4\n");

    // Convertir todas las tablas a utf8mb4 para soporte completo de Unicode
    // EN: Convert all tables to utf8mb4 for full Unicode support
    convertirCharsetUtf8mb4($pDB, 'call_center');

    // Asegurarse de que todo agente tiene una contraseña de ECCP
    // EN: Ensure that every agent has an ECCP password
    $pDB->genQuery('UPDATE agent SET eccp_password = SHA1(CONCAT(NOW(), RAND(), number)) WHERE eccp_password IS NULL');

    $pDB->disconnect();
}

// Detect Asterisk major version for conditional installation
$output = shell_exec('asterisk -rx "core show version" 2>/dev/null');
$astMajor = 18; // default
if (preg_match('/Asterisk (\d+)/', $output, $m)) {
    $astMajor = (int)$m[1];
}
fputs(STDERR, "INFO: Detected Asterisk $astMajor for installer decisions\n");

instalarContextosEspeciales($astMajor);
instalarLoteParqueoCallCenter();

if ($astMajor >= 12) {
    // app_agent_pool: install [agent-defaults] template and convert agents to section format
    instalarAgentDefaultsTemplate();
    convertirAgentsConf($astMajor);
} else {
    // chan_agent: convert agents to legacy format with passwords from database
    fputs(STDERR, "INFO: Skipping [agent-defaults] template (chan_agent on Asterisk $astMajor)\n");
    convertirAgentsConf($astMajor);
}

exit($return);

function quitarColumnaSiExiste($pDB, $sDatabase, $sTabla, $sColumna)
{
    $sPeticionSQL = <<<EXISTE_COLUMNA
SELECT COUNT(*)
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
EXISTE_COLUMNA;
    $r = $pDB->getFirstRowQuery($sPeticionSQL, FALSE, array($sDatabase, $sTabla, $sColumna));
    if (!is_array($r)) {
        fputs(STDERR, "ERR: al verificar tabla $sTabla.$sColumna - ".$pDB->errMsg." | EN: ERR: al verificar tabla $sTabla.$sColumna\n");
        return;
    }
    if ($r[0] > 0) {
        fputs(STDERR, "INFO: Se encuentra $sTabla.$sColumna en base de datos $sDatabase, se ejecuta: | EN: INFO: Found $sTabla.$sColumna in database $sDatabase, executing:\n");
        $sql = "ALTER TABLE $sTabla DROP COLUMN $sColumna";
        fputs(STDERR, "\t$sql\n");
        $r = $pDB->genQuery($sql);
        if (!$r) fputs(STDERR, "ERR: ".$pDB->errMsg."\n");
    } else {
        fputs(STDERR, "INFO: No existe $sTabla.$sColumna en base de datos $sDatabase. No se hace nada. | EN: INFO: $sTabla.$sColumna does not exist in database $sDatabase. Nothing done.\n");
    }
}

function crearColumnaSiNoExiste($pDB, $sDatabase, $sTabla, $sColumna, $sColumnaDef)
{
    $sPeticionSQL = <<<EXISTE_COLUMNA
SELECT COUNT(*)
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
EXISTE_COLUMNA;
    $r = $pDB->getFirstRowQuery($sPeticionSQL, FALSE, array($sDatabase, $sTabla, $sColumna));
    if (!is_array($r)) {
        fputs(STDERR, "ERR: al verificar tabla $sTabla.$sColumna - ".$pDB->errMsg." | EN: ERR: al verificar tabla $sTabla.$sColumna\n");
        return;
    }
    if ($r[0] <= 0) {
        fputs(STDERR, "INFO: No se encuentra $sTabla.$sColumna en base de datos $sDatabase, se ejecuta: | EN: INFO: $sTabla.$sColumna not found in database $sDatabase, executing:\n");
        $sql = "ALTER TABLE $sTabla $sColumnaDef";
        fputs(STDERR, "\t$sql\n");
        $r = $pDB->genQuery($sql);
        if (!$r) fputs(STDERR, "ERR: ".$pDB->errMsg."\n");
    } else {
        fputs(STDERR, "INFO: Ya existe $sTabla.$sColumna en base de datos $sDatabase. | EN: INFO: $sTabla.$sColumna already exists in database $sDatabase.\n");
    }
}

function crearIndiceSiNoExiste($pDB, $sDatabase, $sTabla, $sIndice, $sIndiceDef)
{
    $sPeticionSQL = <<<EXISTE_INDICE
SELECT COUNT(*)
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
EXISTE_INDICE;
    $r = $pDB->getFirstRowQuery($sPeticionSQL, FALSE, array($sDatabase, $sTabla, $sIndice));
    if (!is_array($r)) {
        fputs(STDERR, "ERR: al verificar tabla $sTabla.$sIndice - ".$pDB->errMsg." | EN: ERR: al verificar índice $sTabla.$sIndice\n");
        return;
    }
    if ($r[0] <= 0) {
        fputs(STDERR, "INFO: No se encuentra $sTabla.$sIndice en base de datos $sDatabase, se ejecuta: | EN: INFO: $sTabla.$sIndice not found in database $sDatabase, executing:\n");
        $sql = "ALTER TABLE $sTabla $sIndiceDef";
        fputs(STDERR, "\t$sql\n");
        $r = $pDB->genQuery($sql);
        if (!$r) fputs(STDERR, "ERR: ".$pDB->errMsg."\n");
    } else {
        fputs(STDERR, "INFO: Ya existe $sTabla.$sIndice en base de datos $sDatabase. | EN: INFO: $sTabla.$sIndice already exists in database $sDatabase.\n");
    }
}

function actualizarLongitudCampo($pDB, $sDatabase, $sTabla, $sColumna, $iNuevaLongitud)
{
    // Verificar longitud actual de la columna
    // EN: Verify current length of column
    $sPeticionSQL = <<<VERIFICAR_LONGITUD
SELECT CHARACTER_MAXIMUM_LENGTH
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
VERIFICAR_LONGITUD;
    $r = $pDB->getFirstRowQuery($sPeticionSQL, FALSE, array($sDatabase, $sTabla, $sColumna));
    if (!is_array($r)) {
        fputs(STDERR, "ERR: al verificar longitud de $sTabla.$sColumna - ".$pDB->errMsg." | EN: ERR: al verificar longitud de $sTabla.$sColumna\n");
        return;
    }
    if (isset($r[0]) && $r[0] < $iNuevaLongitud) {
        $tipo = $pDB->getFirstRowQuery(
            "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            FALSE,
            array($sDatabase, $sTabla, $sColumna)
        );
        $nulo = $pDB->getFirstRowQuery(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            FALSE,
            array($sDatabase, $sTabla, $sColumna)
        );
        $nullClause = (is_array($nulo) && strtoupper($nulo[0]) == 'YES') ? 'NULL' : 'NOT NULL';
        $sql = "ALTER TABLE $sTabla MODIFY COLUMN $sColumna " . $tipo[0] . "($iNuevaLongitud) $nullClause";
        fputs(STDERR, "INFO: Actualizando longitud de $sTabla.$sColumna a $iNuevaLongitud caracteres | EN: INFO: Updating length of $sTabla.$sColumna to $iNuevaLongitud characters\n");
        fputs(STDERR, "\t$sql\n");
        $r = $pDB->genQuery($sql);
        if (!$r) fputs(STDERR, "ERR: ".$pDB->errMsg."\n");
    } else {
        fputs(STDERR, "INFO: La longitud de $sTabla.$sColumna ya es adecuada o no existe. | EN: INFO: The length of $sTabla.$sColumna is already adequate or does not exist.\n");
    }
}

/**
 * Procedimiento que instala algunos contextos especiales requeridos para algunas
 * funcionalidades del CallCenter.
 *
 * EN: Function that installs some special contexts required for some
 * CallCenter functionalities.
 */
function instalarContextosEspeciales($astMajor = 18)
{
	$sArchivo = '/etc/asterisk/extensions_custom.conf';
    $sInicioContenido = "; BEGIN ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE\n";
    $sFinalContenido =  "; END ISSABEL CALL-CENTER CONTEXTS DO NOT REMOVE THIS LINE\n";

    // Replaces the explanatory comments that stripDialplanComments() removes,
    // so the box still says where the contexts are documented.
    $sReferenciaContenido =
        "; Generated by the Call Center installer - changes here are lost on reinstall.\n".
        "; The contexts are documented in setup/installer.php in the module source.\n";

    // Cargar el archivo, notando el inicio y el final del área de contextos de callcenter
    // EN: Load the file, noting the start and end of the callcenter contexts area
    $bEncontradoInicio = $bEncontradoFinal = FALSE;
    $contenido = array();
    foreach (file($sArchivo) as $sLinea) {
    	if ($sLinea == $sInicioContenido) {
    		$bEncontradoInicio = TRUE;
        } elseif ($sLinea == $sFinalContenido) {
            $bEncontradoFinal = TRUE;
    	} elseif (!$bEncontradoInicio || $bEncontradoFinal) {
            if (substr($sLinea, strlen($sLinea) - 1) != "\n")
                $sLinea .= "\n";
    	    $contenido[] = $sLinea;
    	}
    }
    if ($bEncontradoInicio xor $bEncontradoFinal) {
    	fputs(STDERR, "ERR: no se puede localizar correctamente segmento de contextos de Call Center | EN: ERR: cannot correctly locate Call Center contexts segment\n");
    } else {
    	$contenido[] = $sInicioContenido;
    	$contenido[] = $sReferenciaContenido;

        // [llamada_agendada] - Scheduled callback context (works on all versions)
        $sContextos = '
; Scheduled call context for callback campaigns
[llamada_agendada]
exten => _X.,1,NoOP("Issabel CallCenter: AGENTCHANNEL=${AGENTCHANNEL}")
exten => _X.,n,NoOP("Issabel CallCenter: QUEUE_MONITOR_FORMAT=${QUEUE_MONITOR_FORMAT}")
exten => _X.,n,GotoIf($["${QUEUE_MONITOR_FORMAT}" = ""]?skiprecord)
exten => _X.,n,Set(CALLFILENAME=${STRFTIME(${EPOCH},,%Y%m%d-%H%M%S)}-${UNIQUEID})
exten => _X.,n,MixMonitor(${MIXMON_DIR}${CALLFILENAME}.${MIXMON_FORMAT},,${MIXMON_POST})
exten => _X.,n,Set(CDR(userfield)=audio:${CALLFILENAME}.${MIXMON_FORMAT})
exten => _X.,n(skiprecord),Dial(${AGENTCHANNEL},300,tw)
exten => h,1,Macro(hangupcall,)
';

        // app_agent_pool contexts (Asterisk 12+):
        // [agent-login]: login via context (no Asterisk password prompt)
        // [atxfer-complete]: re-enter AgentLogin after attended transfer
        // [agents]: AgentRequest() for incoming queue calls
        // On Asterisk 11 (chan_agent), login is done via direct Originate to
        // the AgentLogin application which prompts for the agents.conf password
        if ($astMajor >= 12) {
            $sContextos .= '
; app_agent_pool contexts (Asterisk 12+)
; Agent type login, request, and attended transfer contexts
[agent-login]
exten => _X.,1,NoOp(Issabel CallCenter: Agent Login for ${EXTEN})
 same => n,AgentLogin(${EXTEN})
 same => n,Macro(hangupcall,)

[atxfer-complete]
exten => _X.,1,NoOp(Issabel CallCenter: Attended transfer completion - agent ${EXTEN} re-entering AgentLogin)
 same => n,AgentLogin(${EXTEN},s)
 same => n,Macro(hangupcall,)

[agents]
exten => _X.,1,NoOp(Issabel CallCenter: Connecting to Agent ${EXTEN})
 same => n,AgentRequest(${EXTEN})
 same => n,Macro(hangupcall,)

; Attended transfer contexts for Agent type (app_agent_pool)
; Used when DTMF hooks are lost after Local channel optimization
[atxfer-hold]
exten => s,1,NoOp(Issabel CallCenter: Attended Transfer - Caller on hold)
 same => n,Answer()
 same => n,MusicOnHold(,1800)
 same => n,NoOp(Issabel CallCenter: Attended transfer hold expired - releasing caller)
 same => n,Hangup()

; Reconnect the current channel with the held caller channel given in ARG1.
;   ARG1: held caller channel (${ATXFER_HELD_CHAN})
;   ARG2: "yes" when that caller is parked because the agent pressed Hold during
;         the consultation (Agent type only) - see the giveup branch below.
;
; An attended transfer starts with ONE AMI Redirect carrying an ExtraChannel, and
; Asterisk performs an independent async goto on each channel: the agent and the
; caller then run in separate PBX threads with nothing synchronising them. When
; the consultation Dial() fails synchronously inside the channel driver (an
; unregistered chan_sip peer allocates no channel and sends no packet) the agent
; thread can reach this reconnect before the caller has even left the previous
; bridge, and Bridge() then returns BRIDGERESULT=FAILURE - a completely silent
; outcome that left the caller alone on the MusicOnHold of [atxfer-hold].
;
; The caller only has to run three dialplan priorities to become bridgeable, so
; retrying over a bounded window closes the race. SUCCESS, NONEXISTENT (the
; caller has genuinely gone) and LOOP are final and return at once; only FAILURE
; is retried.
[atxfer-rebridge]
exten => s,1,NoOp(Issabel CallCenter: reconnecting ${CHANNEL} with held caller ${ARG1})
 same => n,Set(ATXFER_REBRIDGE_TRIES=0)
 same => n,GotoIf($["${ARG1}" = ""]?nochan)
 same => n(try),Set(ATXFER_REBRIDGE_TRIES=$[${ATXFER_REBRIDGE_TRIES} + 1])
 same => n,Bridge(${ARG1})
 same => n,GotoIf($["${BRIDGERESULT}" != "FAILURE"]?done)
 same => n,GotoIf($[${ATXFER_REBRIDGE_TRIES} >= 20]?giveup)
 same => n,Wait(0.1)
 same => n,Goto(try)
; Never force-release a parked caller (the agent pressed Hold during the
; consultation): [atxfer-unhold] retrieves them from parking with Bridge(), and
; the callcenter_hold lot caps them with its own parkingtime, so a Bridge()
; failure here is an expected outcome and not a stranding.
 same => n(giveup),GotoIf($["${ARG2}" = "yes"]?done)
 same => n,Log(WARNING,Issabel CallCenter: no se pudo reconectar ${CHANNEL} con el cliente retenido ${ARG1} tras ${ATXFER_REBRIDGE_TRIES} intentos - se libera al cliente | EN: could not reconnect ${CHANNEL} with held caller ${ARG1} after ${ATXFER_REBRIDGE_TRIES} attempts - releasing the caller)
 same => n,SoftHangup(${ARG1})
 same => n,Goto(done)
 same => n(nochan),Log(WARNING,Issabel CallCenter: no hay canal de cliente retenido que reconectar | EN: no held caller channel to reconnect with)
 same => n(done),NoOp(Issabel CallCenter: reconnect finished BRIDGERESULT=${BRIDGERESULT} attempts=${ATXFER_REBRIDGE_TRIES})
 same => n,Return()

[atxfer-consult]
exten => _X.,1,NoOp(Issabel CallCenter: Attended Transfer - Consulting ${EXTEN})
 same => n,Set(__ATXFER_HELD_CHAN=${ATXFER_HELD_CHAN})
 same => n,Set(AGENT_NUM=${ATXFER_AGENT_NUM})
 same => n,Dial(Local/${EXTEN}@from-internal/n,120,gF(atxfer-bridge^s^1)U(atxfer-consult-answered^${AGENT_NUM}))
 same => n,NoOp(Issabel CallCenter: Consultation ended DIALSTATUS=${DIALSTATUS} - reconnecting with caller)
 same => n,UserEvent(ConsultationEnd,Agent: Agent/${AGENT_NUM},Status: ${DIALSTATUS})
 same => n,Gosub(atxfer-rebridge,s,1(${ATXFER_HELD_CHAN},${ATXFER_ON_HOLD}))
 same => n,GotoIf($["${ATXFER_ON_HOLD}" = "yes"]?holdwait)
 same => n,Goto(atxfer-complete,${AGENT_NUM},1)
 same => n(holdwait),Set(ATXFER_ON_HOLD=)
 same => n,UserEvent(AtxferHoldWait,Agent: Agent/${AGENT_NUM})
 same => n,Wait(900)                        ; must track the callcenter_hold parkingtime
 same => n,Goto(atxfer-complete,${AGENT_NUM},1)

[atxfer-consult-answered]
exten => s,1,NoOp(Issabel CallCenter: Attended transfer consult answered by colleague for Agent/${ARG1})
 same => n,UserEvent(ConsultationAnswered,Agent: Agent/${ARG1},Channel: ${CHANNEL})
 same => n,Return()

[atxfer-unhold]
exten => s,1,NoOp(Issabel CallCenter: Agent retrieving call from hold via Bridge)
 same => n,Bridge(${ATXFER_PARKED_CHAN})
 same => n,GotoIf($["${ATXFER_ON_HOLD}" = "yes"]?holdwait)
 same => n,Goto(atxfer-complete,${AGENT_NUM},1)
 same => n(holdwait),Set(ATXFER_ON_HOLD=)
 same => n,UserEvent(AtxferHoldWait,Agent: Agent/${AGENT_NUM})
 same => n,Wait(900)                        ; must track the callcenter_hold parkingtime
 same => n,Goto(atxfer-unhold,s,1)

[atxfer-cancel-consult]
exten => s,1,NoOp(Issabel CallCenter: Cancelling consultation - reconnecting agent to caller)
 same => n,UserEvent(ConsultationEnd,Agent: Agent/${ATXFER_AGENT_NUM})
 same => n,Gosub(atxfer-rebridge,s,1(${ATXFER_HELD_CHAN},${ATXFER_ON_HOLD}))
 same => n,GotoIf($["${ATXFER_ON_HOLD}" = "yes"]?holdwait)
 same => n,Goto(atxfer-complete,${ATXFER_AGENT_NUM},1)
 same => n(holdwait),Set(ATXFER_ON_HOLD=)
 same => n,UserEvent(AtxferHoldWait,Agent: Agent/${ATXFER_AGENT_NUM})
 same => n,Wait(900)                        ; must track the callcenter_hold parkingtime
 same => n,Goto(atxfer-cancel-consult,s,1)

[atxfer-bridge]
exten => s,1,NoOp(Issabel CallCenter: Transfer complete - bridging target with held caller)
 same => n,Bridge(${ATXFER_HELD_CHAN})
 same => n,Hangup()

; Attended transfer contexts for callback type logins (SIP/IAX2/PJSIP)
; Same Redirect-based flow as the Agent type above, but the agent has no
; AgentLogin session to re-enter, so the consult leg just hangs up when the
; reconnected conversation ends. ATXFER_AGENT_ID carries the full agent id
; (e.g. SIP/1002) because the dialer keys its consultation state on that,
; not on a bare agent number. The device is dialled directly to avoid the
; 20-second busy tone from from-internal failure handling; busy and DND are
; checked by the dialer before the consultation is ever placed.
[cbxfer-consult]
exten => _X.,1,NoOp(Issabel CallCenter: Callback attended transfer - consulting ${EXTEN})
 same => n,Set(__ATXFER_HELD_CHAN=${ATXFER_HELD_CHAN})
 same => n,Set(AGENT_ID=${ATXFER_AGENT_ID})
 same => n,Set(CLEAN_EXTEN=${FILTER(0123456789,${EXTEN})})
 same => n,ExecIf($["${CLEAN_EXTEN}" = ""]?Set(CLEAN_EXTEN=${EXTEN}))
 same => n,Set(DIAL_DEVICE=${DB(DEVICE/${CLEAN_EXTEN}/dial)})
 same => n,ExecIf($["${DIAL_DEVICE:0:5}" = "PJSIP"]?Set(DIAL_DEVICE=${PJSIP_DIAL_CONTACTS(${CLEAN_EXTEN})}))
 same => n,ExecIf($["${DIAL_DEVICE}" = ""]?Set(DIAL_DEVICE=Local/${CLEAN_EXTEN}@from-internal/n))
 same => n,NoOp(Issabel CallCenter: Callback consult dial: ${DIAL_DEVICE})
 same => n,Dial(${DIAL_DEVICE},120,gF(atxfer-bridge^s^1)U(cbxfer-consult-answered^${AGENT_ID}))
 same => n,NoOp(Issabel CallCenter: Callback consultation ended DIALSTATUS=${DIALSTATUS} - reconnecting with caller)
 same => n,UserEvent(ConsultationEnd,Agent: ${AGENT_ID},Status: ${DIALSTATUS})
 same => n,Gosub(atxfer-rebridge,s,1(${ATXFER_HELD_CHAN}))
 same => n,Hangup()

[cbxfer-consult-answered]
exten => s,1,NoOp(Issabel CallCenter: Callback consult answered by colleague for ${ARG1})
 same => n,UserEvent(ConsultationAnswered,Agent: ${ARG1},Channel: ${CHANNEL})
 same => n,Return()

[cbxfer-cancel-consult]
exten => s,1,NoOp(Issabel CallCenter: Cancelling callback consultation - reconnecting agent to caller)
 same => n,UserEvent(ConsultationEnd,Agent: ${ATXFER_AGENT_ID})
 same => n,Gosub(atxfer-rebridge,s,1(${ATXFER_HELD_CHAN}))
 same => n,Hangup()

[cbxfer-done]
exten => s,1,NoOp(Issabel CallCenter: Callback consultation terminated - releasing agent channel)
 same => n,Hangup()
';
        } else {
            fputs(STDERR, "INFO: Skipping app_agent_pool contexts (chan_agent on Asterisk $astMajor)\n");

            // Legacy callback attended transfer target context, used only by the
            // native Atxfer fallback on Asterisk 11/13. Asterisk 12+ uses the
            // Redirect-based [cbxfer-*] contexts above instead.
            $sContextos .= '
; Attended transfer context for callback agents (SIP/IAX2/PJSIP) - Asterisk 11/13
; Dials device directly to avoid busy tone delay from from-internal failure handling
[cbext-atxfer]
exten => _X.,1,NoOp(Issabel CallCenter: Callback attended transfer routing for ${EXTEN})
 same => n,Set(CLEAN_EXTEN=${FILTER(0123456789,${EXTEN})})
 same => n,ExecIf($["${CLEAN_EXTEN}" = ""]?Set(CLEAN_EXTEN=${EXTEN}))
 same => n,Set(DIAL_DEVICE=${DB(DEVICE/${CLEAN_EXTEN}/dial)})
 same => n,GotoIf($["${DIAL_DEVICE}" != ""]?direct)
 same => n(fallback),NoOp(Issabel CallCenter: No device found for ${CLEAN_EXTEN} - routing via from-internal)
 same => n,Dial(Local/${CLEAN_EXTEN}@from-internal/n,120)
 same => n,Hangup()
 same => n(direct),NoOp(Issabel CallCenter: Direct device dial: ${DIAL_DEVICE})
 same => n,GotoIf($["${DIAL_DEVICE:0:5}" = "PJSIP"]?pjsip)
 same => n,Dial(${DIAL_DEVICE},120)
 same => n,Hangup()
 same => n(pjsip),Set(PJSIP_CONTACTS=${PJSIP_DIAL_CONTACTS(${CLEAN_EXTEN})})
 same => n,ExecIf($["${PJSIP_CONTACTS}" = ""]?Set(PJSIP_CONTACTS=${DIAL_DEVICE}))
 same => n,NoOp(Issabel CallCenter: PJSIP dial: ${PJSIP_CONTACTS})
 same => n,Dial(${PJSIP_CONTACTS},120)
 same => n,Hangup()
';
        }

        $contenido[] = stripDialplanComments($sContextos);
        $contenido[] = $sFinalContenido;
        file_put_contents($sArchivo, $contenido);
        chown($sArchivo, 'asterisk'); chgrp($sArchivo, 'asterisk');
    }
}

/**
 * Remove the explanatory comments from a dialplan block before it is written to
 * /etc/asterisk.
 *
 * The comments in $sContextos document why each context is shaped the way it is:
 * the async goto race behind [atxfer-rebridge], why a consult leg dials the
 * device directly instead of going through from-internal, and so on. They are
 * worth keeping for future work, so they stay here in the installer - the single
 * source of truth for that dialplan - and are simply not copied onto the box,
 * where they only make extensions_custom.conf long to read.
 *
 * Only whole-line comments are dropped. A trailing comment on a dialplan line is
 * left alone: it is short, and removing it would mean parsing the line. Runs of
 * blank lines are collapsed to one so the contexts stay visually separated.
 */
function stripDialplanComments($sBloque)
{
    $arrLineas = array();
    foreach (explode("\n", $sBloque) as $sLinea) {
        if (preg_match('/^\s*;/', $sLinea)) continue;
        if (trim($sLinea) === '' && (count($arrLineas) == 0 || trim(end($arrLineas)) === ''))
            continue;
        $arrLineas[] = $sLinea;
    }
    while (count($arrLineas) > 0 && trim(end($arrLineas)) === '') array_pop($arrLineas);
    return implode("\n", $arrLineas)."\n";
}

/**
 * Procedimiento que instala el lote de parqueo dedicado del Call Center.
 *
 * EN: Function that installs the Call Center's dedicated parking lot.
 *
 * La funcion Hold del agente parquea el canal del cliente. El lote "default" del
 * PBX lo genera IssabelPBX con courtesytone=beep y parkedplay=both, de modo que
 * tanto el agente como el cliente escuchaban un beep en cada End Hold, mientras
 * que un hold tomado tras una transferencia atendida fallida se recupera con
 * Bridge() y era silencioso. Un lote propio sin courtesytone deja el ciclo de
 * hold silencioso en ambos caminos y no toca el lote del PBX.
 *
 * EN: The agent Hold feature parks the customer's channel. The PBX "default" lot
 * is generated by IssabelPBX with courtesytone=beep and parkedplay=both, so both
 * the agent and the customer heard a beep on every End Hold, while a hold taken
 * after a failed attended transfer is resumed with Bridge() and stayed silent. A
 * dedicated lot with no courtesytone makes the hold cycle silent either way and
 * leaves the PBX lot untouched.
 */
function instalarLoteParqueoCallCenter()
{
    $sArchivo = '/etc/asterisk/res_parking_custom_general.conf';
    $sInicioContenido = "; BEGIN ISSABEL CALL-CENTER PARKING LOT DO NOT REMOVE THIS LINE\n";
    $sFinalContenido =  "; END ISSABEL CALL-CENTER PARKING LOT DO NOT REMOVE THIS LINE\n";

    // Cargar el archivo, notando el inicio y el final del area del lote de parqueo
    // EN: Load the file, noting the start and end of the parking lot area
    $bEncontradoInicio = $bEncontradoFinal = FALSE;
    $contenido = array();
    $arrLineas = file_exists($sArchivo) ? file($sArchivo) : array();
    foreach ($arrLineas as $sLinea) {
        if ($sLinea == $sInicioContenido) {
            $bEncontradoInicio = TRUE;
        } elseif ($sLinea == $sFinalContenido) {
            $bEncontradoFinal = TRUE;
        } elseif (!$bEncontradoInicio || $bEncontradoFinal) {
            if (substr($sLinea, strlen($sLinea) - 1) != "\n")
                $sLinea .= "\n";
            $contenido[] = $sLinea;
        }
    }
    if ($bEncontradoInicio xor $bEncontradoFinal) {
        fputs(STDERR, "ERR: no se puede localizar correctamente segmento de lote de parqueo de Call Center | EN: ERR: cannot correctly locate Call Center parking lot segment\n");
        return;
    }

    $sLote = <<<'PARKINGLOT'
;
; Dedicated parking lot for the Call Center agent Hold feature.
;
; The agent Hold button parks the customer's channel; End Hold retrieves it.
; The PBX "default" lot sets courtesytone=beep with parkedplay=both, so both the
; agent and the customer heard a beep on every End Hold - while a hold taken
; after a failed/cancelled attended transfer is resumed with Bridge() instead of
; ParkedCall() and was therefore silent. This lot has no courtesytone, so the
; hold cycle is silent either way, and the PBX "default" lot keeps its beep for
; ordinary *7000 parking.
;
; It shares the "parkedcalls" context with the default lot: Asterisk only
; forbids overlapping generated *extensions*, and 70000/70001-70100 does not
; overlap 7000/7001-7010. Sharing the context means 70001-70100 are already
; reachable from from-internal, so the dialer needs no new dialplan.
;
[callcenter_hold]
parkext = 70000
parkext_exclusive = yes
parkpos = 70001-70100
context = parkedcalls
parkingtime = 900
comebacktoorigin = no
findslot = first
parkedcalltransfers = caller
parkedcallreparking = caller
parkedmusicclass = default
; courtesytone deliberately unset -> Asterisk default is "no tone", so neither
;   the agent nor the customer hears a beep when a held call is retrieved.
; parkedmusicclass mirrors the PBX lot. It is only a fallback: a class set on
;   the channel itself wins, so a queue caller keeps the queue's MOH (verified -
;   the parked leg reports "class 'Primasoft'", not "default"). Naming it here
;   rather than leaving it blank guarantees MOH for a channel that carries no
;   class of its own, e.g. an outbound campaign call.
PARKINGLOT;

    $contenido[] = $sInicioContenido;
    $contenido[] = $sLote."\n";
    $contenido[] = $sFinalContenido;
    file_put_contents($sArchivo, $contenido);
    chown($sArchivo, 'asterisk'); chgrp($sArchivo, 'asterisk');
    fputs(STDERR, "INFO: lote de parqueo callcenter_hold instalado en $sArchivo | EN: INFO: callcenter_hold parking lot installed in $sArchivo\n");
}

/**
 * Create the [agent-defaults] template in agents.conf for app_agent_pool (Asterisk 12+).
 * This template is inherited by all agent definitions.
 */
function instalarAgentDefaultsTemplate()
{
    $sArchivo = '/etc/asterisk/agents.conf';
    $sTemplate = "[agent-defaults](!)\n" .
                 "musiconhold=default\n" .
                 "ackcall=no\n" .
                 "autologoff=0\n" .
                 "wrapuptime=0\n\n";

    // EN: Check if file exists and if template already exists
    // Verificar si el archivo existe y si la plantilla ya existe
    if (file_exists($sArchivo)) {
        $contenido = file_get_contents($sArchivo);
        if (strpos($contenido, '[agent-defaults](!)') !== false) {
            fputs(STDERR, "INFO: [agent-defaults] template already exists in agents.conf | EN: INFO: plantilla [agent-defaults] ya existe en agents.conf\n");
            return;
        }
        // EN: Append template at the end
        // Agregar plantilla al final
        file_put_contents($sArchivo, $contenido . $sTemplate);
    } else {
        // EN: Create new file with template
        // Crear nuevo archivo con plantilla
        file_put_contents($sArchivo, $sTemplate);
    }
    chown($sArchivo, 'asterisk');
    chgrp($sArchivo, 'asterisk');
    fputs(STDERR, "INFO: Created [agent-defaults] template in agents.conf | Es: INFO: Plantilla [agent-defaults] creada en agents.conf\n");
}

/**
 * Convert existing agents.conf entries to the correct format for the detected
 * Asterisk version. Handles upgrade (Ast 11->13/18) and downgrade scenarios.
 *
 * chan_agent (Ast 11):       agent => number,password,name
 * app_agent_pool (Ast 12+): [number](agent-defaults)\nfullname=name
 *
 * Reads agent passwords from the call_center database to populate the
 * chan_agent format, since app_agent_pool entries do not store passwords.
 */
function convertirAgentsConf($astMajor)
{
    $sArchivo = '/etc/asterisk/agents.conf';
    if (!file_exists($sArchivo)) return;

    $contenido = file($sArchivo);
    if (!is_array($contenido)) return;

    $bUsaChanAgent = ($astMajor < 12);

    // Collect existing agents from the file in both formats
    $agentesEncontrados = array(); // number => name
    $formatoActual = 'unknown';
    $currentAgentId = NULL;
    $currentAgentName = '';
    $bTieneFormatoChanAgent = FALSE;
    $bTieneFormatoAppAgentPool = FALSE;

    foreach ($contenido as $sLinea) {
        $sLinea = trim($sLinea);
        // app_agent_pool format: [number](agent-defaults)
        if (preg_match('/^\[(\d+)\](\(agent-defaults\))?$/', $sLinea, $regs)) {
            if ($currentAgentId !== NULL) {
                $agentesEncontrados[$currentAgentId] = $currentAgentName;
            }
            $currentAgentId = $regs[1];
            $currentAgentName = '';
            $bTieneFormatoAppAgentPool = TRUE;
            continue;
        }
        if ($currentAgentId !== NULL && preg_match('/^fullname\s*=\s*(.*)$/', $sLinea, $regs)) {
            $currentAgentName = $regs[1];
            continue;
        }
        if ($currentAgentId !== NULL && preg_match('/^\[/', $sLinea)) {
            $agentesEncontrados[$currentAgentId] = $currentAgentName;
            $currentAgentId = NULL;
        }
        // chan_agent format: agent => number,password,name
        if (preg_match('/^agent\s*=>\s*(\d+),([^,]*),(.*)$/', $sLinea, $regs)) {
            $agentesEncontrados[$regs[1]] = $regs[3];
            $bTieneFormatoChanAgent = TRUE;
        }
    }
    if ($currentAgentId !== NULL) {
        $agentesEncontrados[$currentAgentId] = $currentAgentName;
    }

    if (count($agentesEncontrados) == 0) {
        fputs(STDERR, "INFO: No agent entries found in agents.conf, nothing to convert\n");
        return;
    }

    // Check if conversion is needed
    if ($bUsaChanAgent && !$bTieneFormatoAppAgentPool) {
        fputs(STDERR, "INFO: agents.conf already in chan_agent format, no conversion needed\n");
        return;
    }
    if (!$bUsaChanAgent && !$bTieneFormatoChanAgent) {
        fputs(STDERR, "INFO: agents.conf already in app_agent_pool format, no conversion needed\n");
        return;
    }

    fputs(STDERR, "INFO: Converting agents.conf entries to ".
        ($bUsaChanAgent ? 'chan_agent' : 'app_agent_pool')." format\n");

    // For chan_agent format, we need passwords from the database
    $agentPasswords = array();
    if ($bUsaChanAgent) {
        try {
            $pDB = new paloDB('mysql://root:'.MYSQL_ROOT_PASSWORD.'@localhost/call_center');
            $result = $pDB->fetchTable("SELECT number, password FROM agent WHERE estatus = 'A'", TRUE);
            if (is_array($result)) {
                foreach ($result as $row) {
                    $agentPasswords[$row['number']] = $row['password'];
                }
            }
            $pDB->disconnect();
        } catch (Exception $e) {
            fputs(STDERR, "WARN: Cannot read agent passwords from database: ".$e->getMessage()."\n");
        }
    }

    // Rebuild agents.conf: keep header/comments/general/template, replace agent entries
    $contenidoNuevo = array();
    $bEnSeccionAgente = FALSE;
    $bYaAgregados = FALSE;

    foreach ($contenido as $sLinea) {
        $sLineaTrim = trim($sLinea);

        // Skip existing agent entries (both formats)
        if (preg_match('/^\[(\d+)\](\(agent-defaults\))?$/', $sLineaTrim)) {
            $bEnSeccionAgente = TRUE;
            continue;
        }
        if ($bEnSeccionAgente) {
            if (preg_match('/^\[/', $sLineaTrim)) {
                $bEnSeccionAgente = FALSE;
                // Fall through to process this line normally
            } else {
                continue; // Skip lines within agent section
            }
        }
        if (preg_match('/^agent\s*=>\s*\d+,/', $sLineaTrim)) {
            continue; // Skip chan_agent format lines
        }

        $contenidoNuevo[] = $sLinea;
    }

    // Append all agents in the correct format at the end
    foreach ($agentesEncontrados as $number => $name) {
        if ($bUsaChanAgent) {
            $pass = isset($agentPasswords[$number]) ? $agentPasswords[$number] : '';
            $contenidoNuevo[] = "agent => {$number},{$pass},{$name}\n";
            fputs(STDERR, "INFO:   Converted agent {$number} to chan_agent format\n");
        } else {
            $contenidoNuevo[] = "\n[{$number}](agent-defaults)\n";
            $contenidoNuevo[] = "fullname={$name}\n";
            fputs(STDERR, "INFO:   Converted agent {$number} to app_agent_pool format\n");
        }
    }

    $hArchivo = fopen($sArchivo, 'w');
    if (!$hArchivo) {
        fputs(STDERR, "ERR: Cannot write agents.conf\n");
        return;
    }
    foreach ($contenidoNuevo as $sLinea) fwrite($hArchivo, $sLinea);
    fclose($hArchivo);
    chown($sArchivo, 'asterisk');
    chgrp($sArchivo, 'asterisk');
    fputs(STDERR, "INFO: agents.conf conversion complete (".count($agentesEncontrados)." agents)\n");
}

function convertirCharsetUtf8mb4($pDB, $sDatabase)
{
    // Buscar tablas que no usen utf8mb4_general_ci
    // EN: Find tables that don't use utf8mb4_general_ci
    $sPeticionSQL = <<<SQL_CHARSET
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_COLLATION != 'utf8mb4_general_ci'
SQL_CHARSET;
    $result = $pDB->fetchTable($sPeticionSQL, TRUE, array($sDatabase));
    if (!is_array($result) || count($result) == 0) {
        fputs(STDERR, "INFO: All tables already use utf8mb4_general_ci charset. | Es: Todas las tablas ya usan charset utf8mb4_general_ci.\n");
        return;
    }

    foreach ($result as $row) {
        $sTabla = $row['TABLE_NAME'];
        $sql = "ALTER TABLE `$sTabla` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
        fputs(STDERR, "INFO: Converting $sTabla to utf8mb4 charset | Es: Convirtiendo $sTabla a charset utf8mb4\n");
        fputs(STDERR, "\t$sql\n");
        $r = $pDB->genQuery($sql);
        if (!$r) fputs(STDERR, "ERR: ".$pDB->errMsg."\n");
    }
    fputs(STDERR, "INFO: utf8mb4 charset conversion complete (".count($result)." tables converted) | Es: Conversión de charset utf8mb4 completada (".count($result)." tablas convertidas)\n");
}
?>

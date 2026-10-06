<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  Encoding: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 1.2-2                                               |
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
  $Id: DialerProcess.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

// Número mínimo de muestras para poder confiar en predicciones de marcador
// Minimum number of samples to trust dialer predictions
define('MIN_MUESTRAS', 10);
define('INTERVALO_REVISION_CAMPANIAS', 3);

class CampaignProcess extends TuberiaProcess
{
    private $DEBUG = FALSE; // VERDADERO si se activa la depuración
                           // TRUE if debugging is enabled

    private $_log;      // Log abierto por framework de demonio
                        // Log opened by daemon framework
    private $_dsn;      // Cadena que representa el DSN, estilo PDO
                        // String representing the DSN, PDO style
    private $_db;       // Conexión a la base de datos, PDO
                        // Database connection, PDO
    private $_ami = NULL;       // Conexión AMI a Asterisk
                                // AMI connection to Asterisk
    private $_configDB; // Objeto de configuración desde la base de datos
                        // Configuration object from the database

    // Contadores para actividades ejecutadas regularmente
    // Counters for regularly executed activities
    private $_iTimestampUltimaRevisionCampanias = 0;    // Última revisión de campañas
                                                        // Last campaign review
    private $_iTimestampUltimaRevisionConfig = 0;       // Última revisión de configuración
                                                        // Last configuration review

    // Lista de campañas y colas que ya fueron avisadas a AMIEventProcess
    // List of campaigns and queues already notified to AMIEventProcess
    private $_campaniasAvisadas = array(
        'incoming'          =>  array(),
        'outgoing'          =>  array(),
        'incoming_id_queue' =>  array(),
    );

    // VERDADERO si existe tabla asterisk.trunks y se deben buscar troncales allí
    // TRUE if asterisk.trunks table exists and trunks must be searched there
    private $_existeTrunksFPBX = FALSE;

    /* Caché de información que fue leída para las troncales directas usadas en
     * marcación de campañas salientes desde la base de datos de FreePBX.
     * Cache of information read for direct trunks used in
     * outgoing campaign dialing from the FreePBX database.
     */
    private $_plantillasMarcado;

    // Estimación de la versión de Asterisk que se usa
    // Estimate of the Asterisk version being used
    private $_asteriskVersion = array(1, 4, 0, 0);
    private $_compat = NULL; // AsteriskCompat instance for version-aware behavior

    /* Fecha/hora de arranque de esta instancia de Asterisk, según CoreStatus.
     * Se usa para detectar reinicios de Asterisk entre reconexiones AMI.
     * Startup date/time of this Asterisk instance, per CoreStatus. Used to
     * detect Asterisk restarts across AMI reconnections. */
    private $_asteriskStartTime = NULL;

    /* VERDADERO si se detectó que Asterisk fue reiniciado y todavía no se ha
     * procesado la resincronización correspondiente.
     * TRUE if an Asterisk restart was detected and the corresponding
     * resynchronization has not been processed yet. */
    private $_bReinicioAsterisk = FALSE;

    /* VERDADERO si al momento de verificar actividad en tubería, no habían
     * mensajes pendientes. Sólo cuando se esté ocioso se intentarán verificar
     * nuevas llamadas de la campaña.
     * TRUE if at the time of checking pipe activity there were no pending
     * messages. Only when idle will new campaign calls be verified.
     */
    private $_ociosoSinEventos = TRUE;

    /* Si se setea a VERDADERO, el programa intenta finalizar y no deben
     * colocarse nuevas llamadas.
     * If set to TRUE, the program attempts to finalize and no new calls
     * should be placed.
     */
    private $_finalizandoPrograma = FALSE;

    /* N-way rotation tracking (persistent across cycles)
     * Seguimiento de rotación N-vías (persistente entre ciclos) */
    private $_agentRotation = array();  // [agent => ['key'=>'campaignIds', 'campaigns'=>[A,B,C], 'index'=>0]]

    /* Campaign intentions for current cycle (Pass 1)
     * Intenciones de campaña para el ciclo actual (Paso 1) */
    private $_campaignIntentions = array();  // [campaign_id => [agent1, agent2, ...]]

    /* Allocated agents after rotation resolution
     * Agentes asignados después de la resolución de rotación */
    private $_allocatedAgents = array();  // [campaign_id => [agent1, agent2, ...]]

    /* Campaign max_canales limits for current cycle
     * Límites max_canales de campaña para el ciclo actual */
    private $_campaignMaxCanales = array();  // [campaign_id => effective_max_canales]

    /* Raw max_canales per campaign (not reduced by active calls), used for rotation
     * max_canales crudo por campaña (no reducido por llamadas activas), usado para rotación */
    private $_campaignRawMaxCanales = array();  // [campaign_id => raw_max_canales]

    /* Predictive slots claimed per queue this cycle (prevents double-counting across shared queues)
     * Slots predictivos reclamados por cola en este ciclo (previene doble conteo en colas compartidas) */
    private $_predictiveSlotsUsed = array();  // [queue => int]

    public function inicioPostDemonio($infoConfig, &$oMainLog)
    {
    	$this->_log = $oMainLog;
        $this->_multiplex = new MultiplexServer(NULL, $this->_log);
        $this->_tuberia->registrarMultiplexHijo($this->_multiplex);
        $this->_tuberia->setLog($this->_log);

        // Interpretar la configuración del demonio
        // Interpret daemon configuration
        $this->_dsn = $this->_interpretarConfiguracion($infoConfig);
        if (!$this->_iniciarConexionDB()) return FALSE;

        // Leer el resto de la configuración desde la base de datos
        // Read the rest of the configuration from the database
        try {
            $this->_configDB = new ConfigDB($this->_db, $this->_log);
        } catch (PDOException $e) {
            $this->_log->output("FATAL: no se puede leer configuración DB - ".$e->getMessage()." | EN: cannot read DB configuration - ".$e->getMessage());
            return FALSE;
        }

        // Recuperarse de cualquier fin anormal anterior
        // Recover from any previous abnormal termination
        try {
            $this->_db->query('DELETE FROM current_calls WHERE 1');
            $this->_db->query('DELETE FROM current_call_entry WHERE 1');
            $this->_db->query("UPDATE call_entry SET status = 'fin-monitoreo' WHERE datetime_end IS NULL AND status <> 'fin-monitoreo'");
        } catch (PDOException $e) {
            $this->_log->output("FATAL: error al limpiar tablas current_calls - ".$e->getMessage()." | EN: error when cleaning current_calls tables - ".$e->getMessage());
        	return FALSE;
        }

        // Clean up orphaned Placing calls from previous abnormal termination
        // Limpiar llamadas Placing huérfanas de una terminación anormal anterior
        try {
            $sCleanup = "UPDATE calls SET status = 'Failure', failure_cause = 0, "
                . "failure_cause_txt = 'Orphaned call at startup' "
                . "WHERE status IN ('Placing', 'Ringing', 'OnQueue')";
            $iCleaned = $this->_db->exec($sCleanup);
            if ($iCleaned > 0) {
                $this->_log->output("INFO: cleaned $iCleaned orphaned Placing/Ringing/OnQueue calls at startup | "
                    . "limpiadas $iCleaned llamadas Placing/Ringing/OnQueue huérfanas al inicio");
            }
        } catch (PDOException $e) {
            $this->_log->output("ERR: error cleaning orphaned Placing/Ringing/OnQueue calls - ".$e->getMessage()
                ." | error limpiando llamadas Placing/Ringing/OnQueue huérfanas - ".$e->getMessage());
        }

        // Clean up orphaned connected calls (OnHold/Success) from previous abnormal termination
        // Limpiar llamadas conectadas huérfanas (OnHold/Success) de una terminación anormal anterior
        try {
            $sStartupTime = date('Y-m-d H:i:s');
            $sCleanup = "UPDATE calls SET status = 'Hangup', end_time = CASE "
                . "WHEN start_time IS NOT NULL THEN start_time "
                . "ELSE ? END "
                . "WHERE (status = 'OnHold' OR (status = 'Success' AND end_time IS NULL))";
            $sth = $this->_db->prepare($sCleanup);
            $sth->execute(array($sStartupTime));
            $iCleaned = $sth->rowCount();
            if ($iCleaned > 0) {
                $this->_log->output("INFO: cleaned $iCleaned orphaned OnHold/Success calls at startup | "
                    . "limpiadas $iCleaned llamadas OnHold/Success huérfanas al inicio");
            }
        } catch (PDOException $e) {
            $this->_log->output("ERR: error cleaning orphaned OnHold/Success calls - ".$e->getMessage()
                ." | error limpiando llamadas OnHold/Success huérfanas - ".$e->getMessage());
        }

        // Detectar capacidades de FreePBX y de call_center
        // Detect FreePBX and call_center capabilities
        $this->_detectarTablaTrunksFPBX();

        // Iniciar la conexión Asterisk
        // Start Asterisk connection
        if (!$this->_iniciarConexionAMI()) return FALSE;

        // Registro de manejadores de eventos desde AMIEventProcess
        // Register event handlers from AMIEventProcess
        foreach (array('verificarFinLlamadasAgendables',) as $k)
            $this->_tuberia->registrarManejador('AMIEventProcess', $k, array($this, "msg_$k"));

        // Registro de manejadores de eventos desde HubProcess
        // Register event handlers from HubProcess
        $this->_tuberia->registrarManejador('HubProcess', 'finalizando', array($this, "msg_finalizando"));

        $this->DEBUG = $this->_configDB->dialer_debug;
        return TRUE;
    }

    private function _interpretarConfiguracion($infoConfig)
    {
        $dbHost = 'localhost';
        $dbUser = 'asterisk';
        $dbPass = 'asterisk';
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbhost'])) {
            $dbHost = $infoConfig['database']['dbhost'];
            $this->_log->output('Usando host de base de datos: '.$dbHost.' | EN: Using database host: '.$dbHost);
        } else {
            $this->_log->output('Usando host (por omisión) de base de datos: '.$dbHost.' | EN: Using (default) database host: '.$dbHost);
        }
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbuser']))
            $dbUser = $infoConfig['database']['dbuser'];
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbpass']))
            $dbPass = $infoConfig['database']['dbpass'];

        return array("mysql:host=$dbHost;dbname=call_center;charset=utf8mb4", $dbUser, $dbPass);
    }

    private function _iniciarConexionDB()
    {
    	try {
    		$this->_db = new PDO($this->_dsn[0], $this->_dsn[1], $this->_dsn[2]);
            $this->_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->_db->setAttribute(PDO::ATTR_EMULATE_PREPARES, FALSE);
            return TRUE;
    	} catch (PDOException $e) {
            $this->_db = NULL;
            $this->_log->output("FATAL: no se puede conectar a DB - ".$e->getMessage()." | EN: cannot connect to DB - ".$e->getMessage());
    		return FALSE;
    	}
    }

    /**
     * Procedimiento que detecta la existencia de la tabla asterisk.trunks. Si
     * existe, la información de troncales está almacenada allí, y no en la
     * tabla globals. Esto se cumple en versiones recientes de FreePBX.
     * Procedure that detects the existence of the asterisk.trunks table. If
     * it exists, the trunk information is stored there, not in the globals
     * table. This is true in recent versions of FreePBX.
     *
     * @return void
     */
    private function _detectarTablaTrunksFPBX()
    {
        $dbConn = $this->_abrirConexionFreePBX();
        if (is_null($dbConn)) return;

        try {
        	$recordset = $dbConn->prepare("SHOW TABLES LIKE 'trunks'");
            $recordset->execute();
            $item = $recordset->fetch(PDO::FETCH_COLUMN, 0);
            $recordset->closeCursor();
            if ($item != 'trunks') {
                // Probablemente error de que asterisk.trunks no existe
                // Probably error that asterisk.trunks does not exist
            	$this->_log->output("INFO: tabla asterisk.trunks no existe, se asume FreePBX viejo. | EN: asterisk.trunks table does not exist, assuming old FreePBX.");
            } else {
                // asterisk.trunks existe
                // asterisk.trunks exists
                $this->_log->output("INFO: tabla asterisk.trunks sí existe, se asume FreePBX reciente. | EN: asterisk.trunks table does exist, assuming recent FreePBX.");
                $this->_existeTrunksFPBX = TRUE;
            }
        } catch (PDOException $e) {
        	$this->_log->output("ERR: al consultar tabla de troncales: ".implode(' - ', $e->errorInfo)." | EN: querying trunk table: ".implode(' - ', $e->errorInfo));
        }
        $dbConn = NULL;
    }

    public function procedimientoDemonio()
    {
        // Verificar posible desconexión de la base de datos
        // Verify possible database disconnection
        if (is_null($this->_db)) {
            $this->_log->output('INFO: intentando volver a abrir conexión a DB... | EN: trying to reopen DB connection...');
            if (!$this->_iniciarConexionDB()) {
                $this->_log->output('ERR: no se puede restaurar conexión a DB, se espera... | EN: cannot restore DB connection, waiting...');
                usleep(5000000);
            } else {
                $this->_log->output('INFO: conexión a DB restaurada, se reinicia operación normal. | EN: DB connection restored, resuming normal operation.');
                $this->_configDB->setDBConn($this->_db);
            }
        }

        // Verificar si la conexión AMI sigue siendo válida
        // Verify if the AMI connection is still valid
        if (!is_null($this->_ami) && is_null($this->_ami->sKey)) $this->_ami = NULL;
        if (is_null($this->_ami) && !$this->_finalizandoPrograma) {
            if (!$this->_iniciarConexionAMI()) {
                $this->_log->output('ERR: no se puede restaurar conexión a Asterisk, se espera... | EN: cannot restore Asterisk connection, waiting...');
                if (!is_null($this->_db)) {
                    if ($this->_multiplex->procesarPaquetes())
                        $this->_multiplex->procesarActividad(0);
                    else $this->_multiplex->procesarActividad(5);
                } else {
                    usleep(5000000);
                }
            } else {
                $this->_log->output('INFO: conexión a Asterisk restaurada, se reinicia operación normal. | EN: Asterisk connection restored, resuming normal operation.');

                if ($this->_bReinicioAsterisk) {
                    $this->_bReinicioAsterisk = FALSE;

                    $this->_log->output('WARN: Asterisk fue reiniciado, se fuerza limpieza inmediata de '.
                        'llamadas huérfanas y se solicita verificación de agentes logoneados. | '.
                        'EN: Asterisk was restarted, forcing immediate orphaned-call cleanup and '.
                        'requesting a login verification for logged-in agents.');

                    // Todas las llamadas Placing/Ringing/Success(sin end_time)/OnHold son
                    // huérfanas con certeza: Asterisk acaba de reiniciarse y no puede tener
                    // memoria de ellas, así que se limpian de inmediato sin esperar los
                    // timeouts normales (5 minutos / 2 horas).
                    // All Placing/Ringing/Success(no end_time)/OnHold calls are certainly
                    // orphaned: Asterisk just restarted and cannot have any memory of them,
                    // so they are cleaned immediately instead of waiting for the normal
                    // timeouts (5 minutes / 2 hours).
                    $this->_cleanOrphanedPlacingCalls(0);
                    $this->_cleanOrphanedConnectedCalls(0);

                    // Forzar una verificación fresca de qué agentes siguen realmente
                    // logoneados; el estado en DB puede seguir marcándolos como
                    // logoneados de antes del reinicio.
                    // Force a fresh check of which agents are actually still logged in;
                    // DB state may still show them logged in from before the restart.
                    $this->_tuberia->msg_SQLWorkerProcess_requerir_nuevaListaAgentes();
                }
            }
        }

        // Actualizar la generación de llamadas para las campañas
        // Update call generation for campaigns
        if (!is_null($this->_db)) {
            try {
                if (!$this->_finalizandoPrograma) {
                    // Verificar si se ha cambiado la configuración
                    // Verify if the configuration has been changed
                    $this->_verificarCambioConfiguracion();

                    if ($this->_ociosoSinEventos) {
                        if (!is_null($this->_ami)) $this->_actualizarCampanias();
                    }
                }

                // Rutear todos los mensajes pendientes entre tareas
                // Route all pending messages between tasks
                $this->_ociosoSinEventos = !$this->_multiplex->procesarPaquetes();
                $this->_multiplex->procesarActividad($this->_ociosoSinEventos ? 1 : 0);
            } catch (PDOException $e) {
                $this->_log->output('ERR: '.__METHOD__.
                    ': no se puede realizar operación de base de datos: '.
                    implode(' - ', $e->errorInfo).' | EN: cannot perform database operation: '.
                    implode(' - ', $e->errorInfo));
                $this->_log->output("ERR: traza de pila: \n".$e->getTraceAsString()." | EN: stack trace: \n".$e->getTraceAsString());
                if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 2006) {
                    // Códigos correspondientes a pérdida de conexión de base de datos
                    // Codes corresponding to database connection loss
                    $this->_log->output('WARN: '.__METHOD__.
                        ': conexión a DB parece ser inválida, se cierra... | EN: DB connection appears invalid, closing...');
                    $this->_db = NULL;
                }
            }
        }

    	return TRUE;
    }

    public function limpiezaDemonio($signum)
    {
        // Mandar a cerrar todas las conexiones activas
        // Order to close all active connections
        $this->_multiplex->finalizarServidor();

        // Desconectarse de la base de datos
        // Disconnect from the database
        $this->_configDB = NULL;
    	if (!is_null($this->_db)) {
            $this->_log->output('INFO: desconectando de la base de datos... | EN: disconnecting from database...');
    		$this->_db = NULL;
    	}
    }

    private function _iniciarConexionAMI()
    {
        if (!is_null($this->_ami)) {
            $this->_log->output('INFO: Desconectando de sesión previa de Asterisk... | EN: Disconnecting from previous Asterisk session...');
            $this->_ami->disconnect();
            $this->_ami = NULL;
        }
        $astman = new AMIClientConn($this->_multiplex, $this->_log);

        $this->_log->output('INFO: Iniciando sesión de control de Asterisk... | EN: Starting Asterisk control session...');
        if (!$astman->connect(
                $this->_configDB->asterisk_asthost,
                $this->_configDB->asterisk_astuser,
                $this->_configDB->asterisk_astpass)) {
            $this->_log->output("FATAL: no se puede conectar a Asterisk Manager | EN: cannot connect to Asterisk Manager");
            return FALSE;
        } else {
            // Averiguar la versión de Asterisk que se usa
            // Find out the Asterisk version being used
            $this->_asteriskVersion = array(1, 4, 0, 0);
            $r = $astman->CoreSettings(); // Sólo disponible en Asterisk >= 1.6.0
                                          // Only available in Asterisk >= 1.6.0
            if ($r['Response'] == 'Success' && isset($r['AsteriskVersion'])) {
                $this->_asteriskVersion = explode('.', $r['AsteriskVersion']);
                $this->_log->output("INFO: CoreSettings reporta Asterisk ".implode('.', $this->_asteriskVersion)." | EN: CoreSettings reports Asterisk ".implode('.', $this->_asteriskVersion));
            } else {
                $this->_log->output("INFO: no hay soporte CoreSettings en Asterisk Manager, se asume Asterisk 1.4.x. | EN: no CoreSettings support in Asterisk Manager, assuming Asterisk 1.4.x.");
            }
            $this->_compat = new AsteriskCompat($this->_asteriskVersion);

            /* Ejecutar el comando CoreStatus para obtener la fecha de arranque de
             * Asterisk. Si se tiene una fecha previa distinta a la obtenida aquí,
             * se concluye que Asterisk ha sido reiniciado. Durante el inicio
             * temprano de Asterisk, la fecha de inicio todavía no está lista y
             * se reportará como 1969-12-31 o similar. Se debe de repetir la llamada
             * hasta que reporte una fecha válida.
             * Execute CoreStatus command to get Asterisk startup date.
             * If a previous different date is obtained than what we have here,
             * it is concluded that Asterisk has been restarted. During early
             * Asterisk startup, the start date is not yet ready and
             * will be reported as 1969-12-31 or similar. The call must be repeated
             * until it reports a valid date. */
            $sFechaInicio = ''; $bFechaValida = FALSE;
            do {
                $r = $astman->CoreStatus();
                if (isset($r['Response']) && $r['Response'] == 'Success') {
                    $sFechaInicio = $r['CoreStartupDate'].' '.$r['CoreStartupTime'];
                    $this->_log->output('INFO: esta instancia de Asterisk arrancó en: '.$sFechaInicio.' | EN: this Asterisk instance started at: '.$sFechaInicio);
                } else {
                    $this->_log->output('INFO: esta versión de Asterisk no soporta CoreStatus | EN: this Asterisk version does not support CoreStatus');
                    break;
                }
                $regs = NULL;
                if (preg_match('/^(\d+)/', $sFechaInicio, $regs) && (int)$regs[1] <= 1970) {
                    $this->_log->output('INFO: fecha de inicio de Asterisk no está lista, se espera | EN: Asterisk start date not ready yet, waiting');
                    usleep(1 * 1000000);
                } else {
                    $bFechaValida = TRUE;
                }
            } while (!$bFechaValida);

            if (is_null($this->_asteriskStartTime)) {
                $this->_asteriskStartTime = $sFechaInicio;
            } elseif ($this->_asteriskStartTime != $sFechaInicio) {
                $this->_log->output('INFO: esta instancia de Asterisk ha sido reiniciada, se eliminará información obsoleta... | EN: this Asterisk instance has been restarted, removing obsolete information...');
                $this->_asteriskStartTime = $sFechaInicio;
                $this->_bReinicioAsterisk = TRUE;
            }

            /* CampaignProcess no tiene manejadores de eventos AMI. Aunque el
             * objeto Predictor hace uso de eventos para recoger el resultado
             * de QueueStatus, tales eventos caen fuera del filtro manipulado
             * por Events(), y por lo tanto siempre se emiten.
             * CampaignProcess has no AMI event handlers. Although the Predictor
             * object uses events to collect the QueueStatus result, such events
             * fall outside the filter manipulated by Events(), and therefore
             * are always emitted. */
            $astman->Events('off');

            $this->_ami = $astman;
            return TRUE;
        }
    }

    private function _verificarCambioConfiguracion()
    {
        $iTimestamp = time();
        if ($iTimestamp - $this->_iTimestampUltimaRevisionConfig > 3) {
            $this->_configDB->leerConfiguracionDesdeDB();
            $listaVarCambiadas = $this->_configDB->listaVarCambiadas();
            if (count($listaVarCambiadas) > 0) {
                if (in_array('dialer_debug', $listaVarCambiadas))
                    $this->DEBUG = $this->_configDB->dialer_debug;
                $this->_configDB->limpiarCambios();
            }
            $this->_iTimestampUltimaRevisionConfig = $iTimestamp;
        }
    }

    private function _actualizarCampanias()
    {
        // Revisar las campañas cada 3 segundos
        // Review campaigns every 3 seconds
        $iTimestamp = time();
        if ($iTimestamp - $this->_iTimestampUltimaRevisionCampanias >= INTERVALO_REVISION_CAMPANIAS) {

            $this->_log->output("DEBUG: ".__METHOD__." === CAMPAIGN REVIEW CYCLE STARTED === | === CICLO DE REVISION DE CAMPAÑAS INICIADO ===");

            /* Se actualiza timestamp de revisión aquí por si no se puede
             * actualizar más tarde debido a una excepción de DB.
             * Review timestamp is updated here in case it cannot be updated
             * later due to a DB exception. */
            $this->_iTimestampUltimaRevisionCampanias = $iTimestamp;

            // Clean up orphaned Placing calls (timeout-based)
            // Limpiar llamadas Placing huérfanas (basado en timeout)
            $this->_cleanOrphanedPlacingCalls();

            // Clean up orphaned connected calls (timeout-based)
            // Limpiar llamadas conectadas huérfanas (basado en timeout)
            $this->_cleanOrphanedConnectedCalls();

            $sFecha = date('Y-m-d', $iTimestamp);
            $sHora = date('H:i:s', $iTimestamp);
            $listaCampanias = array(
                'incoming'  =>  array(),
                'outgoing'  =>  array(),
            );

            // Desactivar todas las campañas que sigan activas y que hayan superado
            // la fecha final de duración de campaña
            // Deactivate all campaigns still active that have exceeded the campaign end date
            $sPeticionDesactivarCaducas = <<<PETICION_DESACTIVAR_CADUCAS
UPDATE campaign SET estatus = "I" WHERE datetime_end < ? AND estatus = "A"
PETICION_DESACTIVAR_CADUCAS;
            $sth = $this->_db->prepare($sPeticionDesactivarCaducas);
            $sth->execute(array($sFecha));

            // Leer la lista de campañas salientes que entran en actividad ahora
            // Read the list of outgoing campaigns that are becoming active now
            $sPeticionCampanias = <<<PETICION_CAMPANIAS_SALIENTES
SELECT id, name, trunk, context, queue, max_canales, num_completadas,
    promedio, desviacion, retries, datetime_init, datetime_end, daytime_init,
    daytime_end
FROM campaign
WHERE datetime_init <= ? AND datetime_end >= ? AND estatus = "A"
    AND (
            (daytime_init < daytime_end AND daytime_init <= ? AND daytime_end >= ?)
        OR  (daytime_init > daytime_end AND (daytime_init <= ? OR daytime_end >= ?)
    )
)
PETICION_CAMPANIAS_SALIENTES;
            $recordset = $this->_db->prepare($sPeticionCampanias);
            $recordset->execute(array($sFecha, $sFecha, $sHora, $sHora, $sHora, $sHora));
            $recordset->setFetchMode(PDO::FETCH_ASSOC);
            foreach ($recordset as $tupla) {
            	$listaCampanias['outgoing'][] = $tupla;
            }

            // Log loaded outgoing campaigns
            $outgoingIds = array();
            foreach ($listaCampanias['outgoing'] as $c) {
                $outgoingIds[] = $c['id'].'('.$c['name'].'/q'.$c['queue'].')';
            }
            $this->_log->output("DEBUG: ".__METHOD__." Loaded ".count($listaCampanias['outgoing'])." outgoing campaigns: [".implode(', ', $outgoingIds)."] | Cargadas ".count($listaCampanias['outgoing'])." campañas salientes: [".implode(', ', $outgoingIds)."]");

            // Desactivar todas las campañas que sigan activas y que hayan superado
            // la fecha final de duración de campaña
            // Deactivate all campaigns still active that have exceeded the campaign end date
            $sPeticionDesactivarCaducas = <<<PETICION_DESACTIVAR_CADUCAS
UPDATE campaign_entry SET estatus = "I" WHERE datetime_end < ? AND estatus = "A"
PETICION_DESACTIVAR_CADUCAS;
            $sth = $this->_db->prepare($sPeticionDesactivarCaducas);
            $sth->execute(array($sFecha));

            // Leer la lista de campañas entrantes que entran en actividad ahora
            // Read the list of incoming campaigns that are becoming active now
            $sPeticionCampanias = <<<PETICION_CAMPANIAS_ENTRANTES
SELECT c.id, c.name, c.id_queue_call_entry, q.queue, c.datetime_init, c.datetime_end, c.daytime_init,
    c.daytime_end
FROM campaign_entry c, queue_call_entry q
WHERE q.id = c.id_queue_call_entry AND c.datetime_init <= ?
    AND c.datetime_end >= ? AND c.estatus = "A"
    AND (
            (c.daytime_init < c.daytime_end AND c.daytime_init <= ? AND c.daytime_end > ?)
        OR  (c.daytime_init > c.daytime_end AND (? < c.daytime_init OR c.daytime_end < ?)
    )
)
PETICION_CAMPANIAS_ENTRANTES;
            $recordset = $this->_db->prepare($sPeticionCampanias);
            $recordset->execute(array($sFecha, $sFecha, $sHora, $sHora, $sHora, $sHora));
            $recordset->setFetchMode(PDO::FETCH_ASSOC);
            foreach ($recordset as $tupla) {
                $listaCampanias['incoming'][] = $tupla;
            }

            // Construir lista de campañas y colas que no han sido todavía avisadas
            // Build list of campaigns and queues not yet notified
            $listaCampaniasAvisar = array(
                'incoming'              =>  array(),    // Nuevas campañas entrantes
                                                        // New incoming campaigns
                'outgoing'              =>  array(),    // Nuevas campañas salientes
                                                        // New outgoing campaigns
                'incoming_queue_new'    =>  array(),    // Nuevas colas definidas como entrantes
                                                        // New queues defined as incoming
                'incoming_queue_old'    =>  array(),    // Colas que ya no están definidas como entrantes
                                                        // Queues no longer defined as incoming
            );
            foreach ($listaCampanias as $t => $l) {
                $listaIdx = array();
                foreach ($l as $tupla) {
                    $listaIdx[] = $tupla['id'];
                	if (!in_array($tupla['id'], $this->_campaniasAvisadas[$t])) {
                		$listaCampaniasAvisar[$t][$tupla['id']] = $tupla;
                	}
                }
                $this->_campaniasAvisadas[$t] = $listaIdx;
            }

            // Leer la lista de colas entrantes que pueden o no tener una campaña
            // Read the list of incoming queues that may or may not have a campaign
            $listaColasActivas = array();
            $listaColasInactivas = array();
            foreach (
                $this->_db->query('SELECT id, queue, estatus FROM queue_call_entry')
                as $tupla) {
                if ($tupla['estatus'] == 'A') {
                    $listaColasActivas[$tupla['id']] = array(
                        'id'    =>  $tupla['id'],
                        'queue' =>  $tupla['queue'],
                    );
                } else {
                    $listaColasInactivas[$tupla['id']] = array(
                        'id'    =>  $tupla['id'],
                        'queue' =>  $tupla['queue'],
                    );
                }
            }
            $listaIdColas = array_keys($listaColasActivas);
            foreach (array_diff($listaIdColas, $this->_campaniasAvisadas['incoming_id_queue']) as $id)
                $listaCampaniasAvisar['incoming_queue_new'][$id] = $listaColasActivas[$id];
            foreach (array_diff($this->_campaniasAvisadas['incoming_id_queue'], $listaIdColas) as $id) {
                if (isset($listaColasInactivas[$id])) {
                    $listaCampaniasAvisar['incoming_queue_old'][$id] = $listaColasInactivas[$id];
                } else {
                    $this->_log->output("WARN: ".__METHOD__." no se encuentra queue_call_entry(id=$id) | EN: queue_call_entry(id=$id) not found");
                }
            }
            if (count($listaCampaniasAvisar['incoming_queue_new']) != 0 ||
                count($listaCampaniasAvisar['incoming_queue_old']) != 0)
                $this->_campaniasAvisadas['incoming_id_queue'] = $listaIdColas;

            // Mandar a avisar a AMIEventProcess sobre las campañas y colas activas
            if (!(count($listaCampaniasAvisar['incoming']) == 0 &&
                count($listaCampaniasAvisar['outgoing']) == 0 &&
                count($listaCampaniasAvisar['incoming_queue_new']) == 0 &&
                count($listaCampaniasAvisar['incoming_queue_old']) == 0))
                $this->_tuberia->AMIEventProcess_nuevasCampanias($listaCampaniasAvisar);

            /* Se actualiza timestamp de revisión aquí por si no se puede
             * actualizar más tarde debido a una excepción de DB.
             * Review timestamp is updated here in case it cannot be updated
             * later due to a DB exception. */
            $this->_iTimestampUltimaRevisionCampanias = time();

            // ============================================================
            // PASS 1: Collect campaign intentions (which agents they want)
            // PASO 1: Recopilar intenciones de campaña (qué agentes quieren)
            // ============================================================
            $this->_campaignIntentions = array();
            $this->_campaignMaxCanales = array();
            $this->_campaignRawMaxCanales = array();
            $this->_predictiveSlotsUsed = array();

            foreach ($listaCampanias['outgoing'] as $campaignData) {
                // Store max_canales for this campaign / Guardar max_canales para esta campaña
                $maxCanales = $campaignData['max_canales'];
                if (is_null($maxCanales) || $maxCanales <= 0) {
                    $maxCanales = PHP_INT_MAX;  // No limit / Sin límite
                }

                // Count active calls for this campaign (Placing, Ringing, OnQueue, OnHold, Connected)
                // Contar llamadas activas para esta campaña
                $activeCalls = $this->_countActiveCalls($campaignData['id']);

                // Calculate effective max_canales (subtract active calls)
                // Calcular max_canales efectivo (restar llamadas activas)
                $effectiveMaxCanales = $maxCanales;
                if ($maxCanales < PHP_INT_MAX) {
                    $effectiveMaxCanales = max(0, $maxCanales - $activeCalls);
                }
                $this->_campaignMaxCanales[$campaignData['id']] = $effectiveMaxCanales;
                $this->_campaignRawMaxCanales[$campaignData['id']] = $maxCanales;

                $queueInfo = $this->_tuberia->AMIEventProcess_infoPrediccionCola($campaignData['queue'], $this->_configDB->dialer_predictivo);
                if (is_null($queueInfo)) {
                    $oPredictor = new Predictor($this->_ami, $this->_log);
                    if ($oPredictor->examinarColas(array($campaignData['queue']))) {
                        $queueInfo = $oPredictor->infoPrediccionCola($campaignData['queue'], $this->_configDB->dialer_predictivo);
                    }
                }

                // === TIMESTAMP TRACKER: Campaign Agent Allocation ===
                $fCampMicrotime = microtime(TRUE);
                $fCampTime = date('Y-m-d H:i:s.', (int)$fCampMicrotime) . sprintf('%03d', ($fCampMicrotime - (int)$fCampMicrotime) * 1000);

                if (!is_null($queueInfo) && isset($queueInfo['AGENTES_LIBRES_LISTA'])) {
                    $normalizedAgents = array();
                    foreach ($queueInfo['AGENTES_LIBRES_LISTA'] as $agentInterface) {
                        $normalizedAgent = $agentInterface;
                        if (!is_null($this->_compat)) {
                            $tmp = $this->_compat->normalizeAgentFromInterface($agentInterface);
                            if (!is_null($tmp)) $normalizedAgent = $tmp;
                        }
                        $normalizedAgents[] = $normalizedAgent;
                    }
                    $this->_campaignIntentions[$campaignData['id']] = $normalizedAgents;

                    // === TIMESTAMP TRACKER: Campaign Allocation Summary ===
                    $this->_log->output("TIMING: ".__METHOD__.": [CAMPAIGN_ALLOC] CampaignID={$campaignData['id']}, Queue={$campaignData['queue']}, Type=outgoing, free_agents=".count($normalizedAgents).", agents=[".implode(',', $normalizedAgents)."], microtime=$fCampMicrotime, time=$fCampTime | ES: Asignación de campaña");

                    if ($this->DEBUG) {
                        $this->_log->output("DEBUG: ".__METHOD__.
                            " (campaign {$campaignData['id']} queue {$campaignData['queue']}) ".
                            "Pass 1: wants agents [".implode(',', $normalizedAgents)."], max_canales=$maxCanales, active_calls=$activeCalls, effective_max=$effectiveMaxCanales | ".
                            "(campaña {$campaignData['id']} cola {$campaignData['queue']}) ".
                            "Paso 1: quiere agentes [".implode(',', $normalizedAgents)."], max_canales=$maxCanales, llamadas_activas=$activeCalls, max_efectivo=$effectiveMaxCanales");
                    }
                }
            }

            // Log Pass 1 summary
            $this->_log->output("DEBUG: ".__METHOD__." Pass 1 COMPLETE: ".count($this->_campaignIntentions)." campaigns with intentions, effective_max=".json_encode($this->_campaignMaxCanales).", raw_max=".json_encode($this->_campaignRawMaxCanales)." | Paso 1 COMPLETO: ".count($this->_campaignIntentions)." campañas con intenciones");
            foreach ($this->_campaignIntentions as $cid => $agents) {
                $this->_log->output("DEBUG: ".__METHOD__." Pass 1 intentions: campaign $cid wants ".count($agents)." agents: [".implode(',', $agents)."], raw_max=".$this->_campaignRawMaxCanales[$cid].", effective_max=".$this->_campaignMaxCanales[$cid]." | Paso 1 intenciones: campaña $cid quiere ".count($agents)." agentes");
            }

            // ============================================================
            // ALLOCATE: Resolve shared agents using N-way rotation
            // ASIGNAR: Resolver agentes compartidos usando rotación N-vías
            // ============================================================
            $this->_allocatedAgents = $this->_resolveAgentRotation($this->_campaignIntentions, $this->_campaignRawMaxCanales);

            // Log allocation results
            $this->_log->output("DEBUG: ".__METHOD__." ALLOCATION COMPLETE | ASIGNACION COMPLETA:");
            foreach ($this->_allocatedAgents as $cid => $agents) {
                $this->_log->output("DEBUG: ".__METHOD__." Allocated: campaign $cid gets ".count($agents)." agents: [".implode(',', $agents)."] | Asignado: campaña $cid obtiene ".count($agents)." agentes: [".implode(',', $agents)."]");
            }

            // ============================================================
            // PASS 2: Process campaigns with allocated agents
            // PASO 2: Procesar campañas con agentes asignados
            // ============================================================
            foreach ($listaCampanias['outgoing'] as $tuplaCampania) {
                /* Se debe crear el predictor para cada campaña porque la
                 * generación de llamadas toma tiempo debido a las consultas a
                 * la base de datos, y para cuando pasa a la siguiente campaña
                 * que usa esa cola, la información podría estar obsoleta.
                 * The predictor must be created for each campaign because call
                 * generation takes time due to database queries, and by the
                 * time it moves to the next campaign using that queue, the
                 * information may be obsolete. */
                $oPredictor = new Predictor($this->_ami, $this->_log);
                $this->_processCampaignWithAllocation($tuplaCampania, $oPredictor);

                /* Debido a las consultas a la base de datos realizadas para
                 * generar las llamadas a la campaña, es posible que se acumulen
                 * eventos pendientes de AMIEventProcess. Se despachan algunos
                 * eventos aquí para paliar la acumulación.
                 * Due to the database queries performed to generate campaign
                 * calls, AMIEventProcess pending events may accumulate. Some
                 * events are dispatched here to alleviate the accumulation. */
                $this->_ociosoSinEventos = !$this->_multiplex->procesarPaquetes();
                $this->_multiplex->procesarActividad(0);

                /* Se actualiza timestamp de revisión aquí por si no se puede
                 * actualizar más tarde debido a una excepción de DB.
                 * Review timestamp is updated here in case it cannot be updated
                 * later due to a DB exception. */
                $this->_iTimestampUltimaRevisionCampanias = time();
            }
        }
    }

    /**
     * Resolve agent allocation using N-way rotation with max_canales awareness.
     * Each shared agent rotates among campaigns that want it and have capacity.
     *
     * Resolver asignación de agentes usando rotación N-vías con conocimiento de max_canales.
     * Cada agente compartido rota entre campañas que lo quieren y tienen capacidad.
     *
     * @param array $intentions [campaign_id => [agents...]]
     * @param array $maxCanales [campaign_id => max_canales_limit]
     * @return array [campaign_id => [allocated_agents...]]
     */
    private function _resolveAgentRotation($intentions, $maxCanales = array())
    {
        $this->_log->output("DEBUG: ".__METHOD__." === ROTATION START === Input: ".count($intentions)." campaigns, maxCanales=".json_encode($maxCanales)." | === INICIO ROTACION === Entrada: ".count($intentions)." campañas");

        $allocated = array();
        $allocationCount = array();  // Track how many agents allocated to each campaign
        $agentToCampaigns = array();  // [agent => [campaign_ids...]]

        // Build reverse map: which campaigns want each agent
        // Construir mapa inverso: qué campañas quieren cada agente
        foreach ($intentions as $campaignId => $agents) {
            $allocated[$campaignId] = array();
            $allocationCount[$campaignId] = 0;
            foreach ($agents as $agent) {
                if (!isset($agentToCampaigns[$agent])) {
                    $agentToCampaigns[$agent] = array();
                }
                $agentToCampaigns[$agent][] = $campaignId;
            }
        }

        // Log agent-to-campaigns map
        $sharedAgents = array();
        $uniqueAgents = array();
        foreach ($agentToCampaigns as $agent => $campaigns) {
            if (count($campaigns) > 1) {
                $sharedAgents[$agent] = $campaigns;
            } else {
                $uniqueAgents[$agent] = $campaigns[0];
            }
        }
        $this->_log->output("DEBUG: ".__METHOD__." Agent map: ".count($uniqueAgents)." unique agents, ".count($sharedAgents)." shared agents | Mapa agentes: ".count($uniqueAgents)." únicos, ".count($sharedAgents)." compartidos");
        if (count($sharedAgents) > 0) {
            foreach ($sharedAgents as $agent => $campaigns) {
                $this->_log->output("DEBUG: ".__METHOD__." Shared agent $agent wanted by campaigns: [".implode(',', $campaigns)."] | Agente compartido $agent querido por campañas: [".implode(',', $campaigns)."]");
            }
        }

        // Allocate each agent / Asignar cada agente
        foreach ($agentToCampaigns as $agent => $campaigns) {
            if (count($campaigns) == 1) {
                // Only one campaign wants this agent - assign if has capacity
                // Solo una campaña quiere este agente - asignar si tiene capacidad
                $campaignId = $campaigns[0];
                $limit = isset($maxCanales[$campaignId]) ? $maxCanales[$campaignId] : PHP_INT_MAX;

                if ($allocationCount[$campaignId] < $limit) {
                    $allocated[$campaignId][] = $agent;
                    $allocationCount[$campaignId]++;
                } else {
                    if ($this->DEBUG) {
                        $this->_log->output("DEBUG: ".__METHOD__.
                            " agent $agent: campaign $campaignId reached max_canales=$limit, agent not allocated | ".
                            "agente $agent: campaña $campaignId alcanzó max_canales=$limit, agente no asignado");
                    }
                }
            } else {
                // Multiple campaigns want this agent - use rotation with max_canales awareness
                // Múltiples campañas quieren este agente - usar rotación con conocimiento de max_canales
                $winningCampaign = $this->_getRotationWinnerWithCapacity($agent, $campaigns, $maxCanales, $allocationCount);

                if (!is_null($winningCampaign)) {
                    $allocated[$winningCampaign][] = $agent;
                    $allocationCount[$winningCampaign]++;

                    if ($this->DEBUG) {
                        $this->_log->output("DEBUG: ".__METHOD__.
                            " agent $agent shared by campaigns [".implode(',', $campaigns).
                            "] -> assigned to campaign $winningCampaign (rotation) | ".
                            "agente $agent compartido por campañas [".implode(',', $campaigns).
                            "] -> asignado a campaña $winningCampaign (rotación)");
                    }
                } else {
                    if ($this->DEBUG) {
                        $this->_log->output("DEBUG: ".__METHOD__.
                            " agent $agent shared by campaigns [".implode(',', $campaigns).
                            "] -> all campaigns at max_canales, agent not allocated | ".
                            "agente $agent compartido por campañas [".implode(',', $campaigns).
                            "] -> todas las campañas en max_canales, agente no asignado");
                    }
                }
            }
        }

        return $allocated;
    }

    /**
     * Get the winning campaign for a shared agent using N-way rotation,
     * respecting max_canales limits. If the rotation winner is at capacity,
     * try the next campaign in rotation order.
     *
     * Obtener la campaña ganadora para un agente compartido usando rotación N-vías,
     * respetando los límites de max_canales. Si el ganador de rotación está al límite,
     * intentar con la siguiente campaña en orden de rotación.
     *
     * @param string $agent The agent identifier
     * @param array $campaigns List of campaign IDs that want this agent
     * @param array $maxCanales [campaign_id => max_canales_limit]
     * @param array $allocationCount [campaign_id => current_allocation_count]
     * @return int|null The campaign ID that wins, or null if all at capacity
     */
    private function _getRotationWinnerWithCapacity($agent, $campaigns, $maxCanales, $allocationCount)
    {
        sort($campaigns);  // Ensure consistent order / Asegurar orden consistente
        $campaignsKey = implode(',', $campaigns);
        $numCampaigns = count($campaigns);

        // Initialize or update rotation tracking for this agent
        // Inicializar o actualizar seguimiento de rotación para este agente
        if (!isset($this->_agentRotation[$agent]) ||
            $this->_agentRotation[$agent]['key'] !== $campaignsKey) {
            // New agent or campaign set changed - initialize rotation
            // Nuevo agente o conjunto de campañas cambió - inicializar rotación
            $this->_agentRotation[$agent] = array(
                'key' => $campaignsKey,
                'campaigns' => $campaigns,
                'index' => 0,
            );
        }

        $rotation = &$this->_agentRotation[$agent];
        $startIndex = $rotation['index'];
        $winner = null;

        $this->_log->output("DEBUG: ".__METHOD__." agent $agent: rotation index=$startIndex, campaigns=[".implode(',', $campaigns)."] | agente $agent: índice rotación=$startIndex, campañas=[".implode(',', $campaigns)."]");

        // Try each campaign in rotation order until we find one with capacity
        // Intentar cada campaña en orden de rotación hasta encontrar una con capacidad
        for ($i = 0; $i < $numCampaigns; $i++) {
            $winnerIndex = ($startIndex + $i) % $numCampaigns;
            $candidateCampaign = $rotation['campaigns'][$winnerIndex];

            $limit = isset($maxCanales[$candidateCampaign]) ? $maxCanales[$candidateCampaign] : PHP_INT_MAX;
            $currentCount = isset($allocationCount[$candidateCampaign]) ? $allocationCount[$candidateCampaign] : 0;

            if ($currentCount < $limit) {
                // This campaign has capacity / Esta campaña tiene capacidad
                $winner = $candidateCampaign;

                if ($this->DEBUG && $i > 0) {
                    // Log that we skipped some campaigns due to max_canales
                    $this->_log->output("DEBUG: ".__METHOD__.
                        " agent $agent: skipped $i campaign(s) at max_canales, assigned to campaign $winner | ".
                        "agente $agent: saltó $i campaña(s) en max_canales, asignado a campaña $winner");
                }
                break;
            }
        }

        // Advance rotation for next cycle (always advance, even if no capacity found)
        // Avanzar rotación para próximo ciclo (siempre avanzar, aunque no se encuentre capacidad)
        $rotation['index']++;

        if ($this->DEBUG) {
            $winnerStr = is_null($winner) ? 'none' : $winner;
            $this->_log->output("DEBUG: ".__METHOD__.
                " agent $agent rotation: index={$rotation['index']}, ".
                "campaigns=[".implode(',', $campaigns)."], winner=$winnerStr | ".
                "agente $agent rotación: índice={$rotation['index']}, ".
                "campañas=[".implode(',', $campaigns)."], ganador=$winnerStr");
        }

        return $winner;
    }

    /**
     * Process campaign calls using pre-allocated agents.
     * Procesar llamadas de campaña usando agentes pre-asignados.
     *
     * @param array $infoCampania Campaign information
     * @param Predictor $oPredictor Predictor instance
     * @return bool TRUE if calls were placed, FALSE otherwise
     */
    private function _processCampaignWithAllocation($infoCampania, $oPredictor)
    {
        $campaignId = $infoCampania['id'];
        $this->_log->output("DEBUG: ".__METHOD__." === Processing campaign $campaignId ({$infoCampania['name']}) queue {$infoCampania['queue']} === | === Procesando campaña $campaignId ({$infoCampania['name']}) cola {$infoCampania['queue']} ===");

        $iTimeoutOriginate = $this->_configDB->dialer_timeout_originate;
        if (is_null($iTimeoutOriginate) || $iTimeoutOriginate <= 0)
            $iTimeoutOriginate = NULL;
        else $iTimeoutOriginate *= 1000; // convertir a milisegundos / convert to milliseconds

        // Get allocated agents for this campaign
        // Obtener agentes asignados para esta campaña
        $allocatedAgents = isset($this->_allocatedAgents[$campaignId])
            ? $this->_allocatedAgents[$campaignId]
            : array();

        $numAllocatedAgents = count($allocatedAgents);
        $this->_log->output("DEBUG: ".__METHOD__." campaign $campaignId: allocated $numAllocatedAgents agents: [".implode(',', $allocatedAgents)."] | campaña $campaignId: $numAllocatedAgents agentes asignados: [".implode(',', $allocatedAgents)."]");

        if ($numAllocatedAgents == 0) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.
                    " (campaign $campaignId queue {$infoCampania['queue']}) no agents allocated this cycle | ".
                    "(campaña $campaignId cola {$infoCampania['queue']}) ningún agente asignado en este ciclo");
            }
            // Even with no agents, check if data is exhausted to mark campaign as finished
            // Aunque no haya agentes, verificar si los datos se agotaron para marcar campaña como finalizada
            $this->_checkCampaignDataExhausted($campaignId, $infoCampania);
            return FALSE;
        }

        // Build trunk dial pattern / Construir patrón de marcado de troncal
        $datosTrunk = $this->_construirPlantillaMarcado($infoCampania['trunk']);
        if (is_null($datosTrunk)) {
            $this->_log->output("ERR: no se puede construir plantilla de marcado a partir de trunk '{$infoCampania['trunk']}'! | EN: cannot build dial template from trunk '{$infoCampania['trunk']}'!");
            return FALSE;
        }

        // Get schedulable calls / Obtener llamadas programables
        $listaLlamadasAgendadas = $this->_actualizarLlamadasAgendables($infoCampania, $datosTrunk);

        // Retrieve effective max_canales computed in Pass 1 (already accounts for active calls)
        // Obtener max_canales efectivo calculado en Paso 1 (ya toma en cuenta llamadas activas)
        $effectiveMaxCanales = isset($this->_campaignMaxCanales[$campaignId])
            ? $this->_campaignMaxCanales[$campaignId]
            : (is_null($infoCampania['max_canales']) || $infoCampania['max_canales'] <= 0
                ? PHP_INT_MAX : $infoCampania['max_canales']);

        // Calculate how many calls to place (limited by allocated agents)
        // Calcular cuántas llamadas colocar (limitado por agentes asignados)
        $iNumLlamadasColocar = $numAllocatedAgents;

        // Predictive dialer boost: anticipate agents about to become free
        // Impulso del marcador predictivo: anticipar agentes que se van a desocupar
        $iPredictiveBoost = 0;
        if ($this->_configDB->dialer_predictivo && $numAllocatedAgents > 0) {
            $infoCola = $this->_tuberia->AMIEventProcess_infoPrediccionCola(
                $infoCampania['queue'], $this->_configDB->dialer_predictivo);
            if (is_null($infoCola)) {
                if ($oPredictor->examinarColas(array($infoCampania['queue']))) {
                    $infoCola = $oPredictor->infoPrediccionCola(
                        $infoCampania['queue'], $this->_configDB->dialer_predictivo);
                }
            }

            if (!is_null($infoCola)) {
                // Apply Erlang prediction if enough completed calls for statistical confidence
                // Aplicar predicción Erlang si hay suficientes llamadas completadas para confianza estadística
                $resumenPrediccion = ($infoCampania['num_completadas'] >= MIN_MUESTRAS)
                    ? $oPredictor->predecirNumeroLlamadas($infoCola,
                        $this->_configDB->dialer_qos,
                        $infoCampania['promedio'],
                        $this->_leerTiempoContestar($campaignId))
                    : $oPredictor->predecirNumeroLlamadas($infoCola);

                // Calculate available predictive slots for this queue
                // Calcular slots predictivos disponibles para esta cola
                $sQueue = $infoCampania['queue'];
                $predictiveAgents = $resumenPrediccion['AGENTES_POR_DESOCUPAR'];
                $waitingClients = $resumenPrediccion['CLIENTES_ESPERA'];
                $alreadyClaimed = isset($this->_predictiveSlotsUsed[$sQueue])
                    ? $this->_predictiveSlotsUsed[$sQueue] : 0;

                $iPredictiveBoost = $predictiveAgents - $waitingClients - $alreadyClaimed;
                if ($iPredictiveBoost < 0) $iPredictiveBoost = 0;

                // Claim these slots so other campaigns sharing this queue don't double-count
                // Reclamar estos slots para que otras campañas en la misma cola no los cuenten doble
                if (!isset($this->_predictiveSlotsUsed[$sQueue])) {
                    $this->_predictiveSlotsUsed[$sQueue] = 0;
                }
                $this->_predictiveSlotsUsed[$sQueue] += $iPredictiveBoost;

                $iNumLlamadasColocar += $iPredictiveBoost;

                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__.
                        " (campaign $campaignId queue $sQueue) ".
                        "predictive boost: agents_about_to_free=$predictiveAgents, ".
                        "waiting_clients=$waitingClients, already_claimed=$alreadyClaimed, ".
                        "boost=$iPredictiveBoost, total_calls=$iNumLlamadasColocar | ".
                        "(campaña $campaignId cola $sQueue) ".
                        "impulso predictivo: agentes_por_desocupar=$predictiveAgents, ".
                        "clientes_espera=$waitingClients, ya_reclamados=$alreadyClaimed, ".
                        "impulso=$iPredictiveBoost, total_llamadas=$iNumLlamadasColocar");
                }
            }
        }

        // ---- TWO-BUDGET CALL LIMITING ----
        // Budget 1 (Agent): How many agents are truly available (not already receiving a call)
        // Presupuesto 1 (Agentes): Cuántos agentes están realmente disponibles (no recibiendo ya una llamada)
        // Calls pending OriginateResponse are in "Placing" status in DB, but agents still show
        // as NOT_INUSE until the call reaches the queue. We must subtract these pending calls
        // from the agent count to avoid placing calls for agents already being dialed.
        // Las llamadas pendientes de OriginateResponse están en estado "Placing" en la BD, pero
        // los agentes aún muestran NOT_INUSE hasta que la llamada llega a la cola. Debemos restar
        // estas llamadas pendientes del conteo de agentes para evitar marcar a agentes que ya están siendo llamados.
        $iPendingOriginate = $this->_contarLlamadasEsperandoRespuesta($infoCampania['queue']);
        $iScheduledThisCycle = count($listaLlamadasAgendadas);
        $iAgentBudget = $iNumLlamadasColocar - $iPendingOriginate - $iScheduledThisCycle;
        if ($iAgentBudget < 0) $iAgentBudget = 0;

        // Budget 2 (Channel): How many more channels can this campaign use
        // Presupuesto 2 (Canales): Cuántos canales más puede usar esta campaña
        // effective_max_canales = max_canales - active_calls (computed in Pass 1)
        // Subtract scheduled calls placed this cycle (not yet in _countActiveCalls)
        // effective_max_canales = max_canales - llamadas_activas (calculado en Paso 1)
        // Restar llamadas agendadas colocadas este ciclo (aún no en _countActiveCalls)
        $iChannelBudget = $effectiveMaxCanales;
        if ($effectiveMaxCanales < PHP_INT_MAX) {
            $iChannelBudget = $effectiveMaxCanales - $iScheduledThisCycle;
            if ($iChannelBudget < 0) $iChannelBudget = 0;
        }

        // Final call count = minimum of both budgets
        // Conteo final de llamadas = mínimo de ambos presupuestos
        $iNumLlamadasColocar = min($iAgentBudget, $iChannelBudget);

        $this->_log->output("DEBUG: ".__METHOD__.
            " (campaign $campaignId queue {$infoCampania['queue']}) ".
            "TWO-BUDGET: allocated=$numAllocatedAgents [".implode(',', $allocatedAgents)."], ".
            "predictive_boost=$iPredictiveBoost, ".
            "pending_originate=$iPendingOriginate, scheduled_this_cycle=$iScheduledThisCycle, ".
            "agent_budget=$iAgentBudget, channel_budget=$iChannelBudget, ".
            "effective_max=$effectiveMaxCanales, ".
            "calls_to_place=$iNumLlamadasColocar | ".
            "(campaña $campaignId cola {$infoCampania['queue']}) ".
            "DOS-PRESUPUESTOS: asignados=$numAllocatedAgents, ".
            "impulso_predictivo=$iPredictiveBoost, ".
            "pendientes_originate=$iPendingOriginate, agendadas_ciclo=$iScheduledThisCycle, ".
            "presupuesto_agentes=$iAgentBudget, presupuesto_canales=$iChannelBudget, ".
            "max_efectivo=$effectiveMaxCanales, ".
            "llamadas_a_colocar=$iNumLlamadasColocar");

        if (count($listaLlamadasAgendadas) <= 0 && $iNumLlamadasColocar <= 0) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__." (campaign $campaignId queue ".
                    "{$infoCampania['queue']}) no free agents or calls to place! | ".
                    "(campaña $campaignId cola {$infoCampania['queue']}) sin agentes libres ni llamadas a colocar!");
            }
            // Even with no agents to place calls, check if data is exhausted
            // Aunque no haya agentes para colocar llamadas, verificar si los datos se agotaron
            $this->_checkCampaignDataExhausted($campaignId, $infoCampania);
            return FALSE;
        }

        // Dialer overcommit logic (to compensate for failed calls)
        // Lógica de sobre-colocación del marcador (para compensar llamadas fallidas)
        if ($iNumLlamadasColocar > 0 && $this->_configDB->dialer_overcommit) {
            $iVentanaHistoria = 60 * 30; // 30 minutes window
            $sPeticionASR =
                'SELECT COUNT(*) AS total, SUM(IF(status = "Failure" OR status = "NoAnswer", 0, 1)) AS exito ' .
                'FROM calls ' .
                'WHERE id_campaign = ? AND status IS NOT NULL ' .
                    'AND status <> "Placing" ' .
                    'AND fecha_llamada IS NOT NULL ' .
                    'AND fecha_llamada >= ?';
            $recordset = $this->_db->prepare($sPeticionASR);
            $recordset->execute(array($campaignId, date('Y-m-d H:i:s', time() - $iVentanaHistoria)));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();

            if ($tupla['total'] >= 10 && $tupla['exito'] > 0) {
                $ASR = $tupla['exito'] / $tupla['total'];
                $ASR_safe = $ASR;
                if ($ASR_safe < 0.20) $ASR_safe = 0.20;
                $iNumLlamadasColocar = (int)round($iNumLlamadasColocar / $ASR_safe);

                // Re-cap by max_canales after overcommit to respect trunk capacity
                // Re-limitar por max_canales después de sobre-colocación para respetar capacidad del trunk
                if ($effectiveMaxCanales < PHP_INT_MAX
                    && $iNumLlamadasColocar > $effectiveMaxCanales) {
                    $iBeforeRecap = $iNumLlamadasColocar;
                    $iNumLlamadasColocar = $effectiveMaxCanales;
                    if ($this->DEBUG) {
                        $this->_log->output(
                            "DEBUG: (campaign $campaignId queue {$infoCampania['queue']}) "
                            ."overcommit capped from $iBeforeRecap to $effectiveMaxCanales "
                            ."(effective max_canales, active calls subtracted) | "
                            ."(campaña $campaignId cola {$infoCampania['queue']}) "
                            ."sobre-colocación limitada de $iBeforeRecap a $effectiveMaxCanales "
                            ."(max_canales efectivo, llamadas activas restadas)");
                    }
                }

                if ($this->DEBUG) {
                    $this->_log->output(
                        "DEBUG: (campaign $campaignId queue {$infoCampania['queue']}) ".
                        "in the last $iVentanaHistoria sec. " .
                        "{$tupla['exito']} of {$tupla['total']} placed calls succeeded (ASR=".(sprintf('%.2f', $ASR * 100))."%). Placing " .
                        "$iNumLlamadasColocar to compensate. | ".
                        "(campaña $campaignId cola {$infoCampania['queue']}) ".
                        "en los últimos $iVentanaHistoria seg. tuvieron éxito " .
                        "{$tupla['exito']} de {$tupla['total']} llamadas colocadas (ASR=".(sprintf('%.2f', $ASR * 100))."%). Se colocan " .
                        "$iNumLlamadasColocar para compensar.");
                }
            }
        }

        // Read calls to place / Leer llamadas a colocar
        $listaLlamadas = $listaLlamadasAgendadas;
        $iNumTotalLlamadas = count($listaLlamadas);
        $this->_log->output("DEBUG: ".__METHOD__." campaign $campaignId: FINAL iNumLlamadasColocar=$iNumLlamadasColocar, scheduled_calls=".count($listaLlamadasAgendadas)." | campaña $campaignId: FINAL llamadasAColocar=$iNumLlamadasColocar, llamadas_programadas=".count($listaLlamadasAgendadas));
        if ($iNumLlamadasColocar > 0) {
            $sFechaSys = date('Y-m-d');
            $sHoraSys = date('H:i:s');
            $sPeticionLlamadas = <<<PETICION_LLAMADAS
(SELECT 1 AS dummy_priority, id_campaign, id, phone, date_init AS dummy_date_init,
    time_init AS dummy_time_init, date_end AS dummy_date_end,
    time_end AS dummy_time_end, retries
FROM calls
WHERE id_campaign = ?
    AND (status IS NULL
        OR status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND retries < ?
    AND dnc = 0
    AND (? BETWEEN date_init AND date_end AND ? BETWEEN time_init AND time_end)
    AND agent IS NULL)
UNION
(SELECT 2 AS dummy_priority, id_campaign, id, phone, date_init AS dummy_date_init,
    time_init AS dummy_time_init, date_end AS dummy_date_end,
    time_end AS dummy_time_end, retries
FROM calls
WHERE id_campaign = ?
    AND (status IS NULL
        OR status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND retries < ?
    AND dnc = 0
    AND date_init IS NULL AND date_end IS NULL AND time_init IS NULL AND time_end IS NULL
    AND agent IS NULL)
ORDER BY dummy_priority, retries, dummy_date_end, dummy_time_end, dummy_date_init, dummy_time_init, id
LIMIT 0,?
PETICION_LLAMADAS;
            $recordset = $this->_db->prepare($sPeticionLlamadas);
            $recordset->execute(array(
                $campaignId,
                $infoCampania['retries'],
                $sFechaSys, $sHoraSys,
                $campaignId,
                $infoCampania['retries'],
                $iNumLlamadasColocar));
            $recordset->setFetchMode(PDO::FETCH_ASSOC);
            $pid = posix_getpid();
            foreach ($recordset as $tupla) {
                $iNumTotalLlamadas++;
                $sKey = sprintf('%d-%d-%d', $pid, $campaignId, $tupla['id']);
                $sCanalTrunk = str_replace('$OUTNUM$', $tupla['phone'], $datosTrunk['TRUNK']);

                if (!isset($listaLlamadas[$tupla['phone']])) {
                    $tupla['actionid'] = $sKey;
                    $tupla['dialstring'] = $sCanalTrunk;
                    $tupla['agent'] = NULL;
                    $listaLlamadas[$tupla['phone']] = $tupla;
                } else {
                    $this->_log->output("INFO: se ignora llamada $sKey con DialString ".
                        "$sCanalTrunk - mismo DialString usado por llamada a punto de originar. | EN: call $sKey with DialString ".
                        "$sCanalTrunk ignored - same DialString used by call about to originate.");
                }
            }
        }

        if ($iNumTotalLlamadas <= 0) {
            // Check if there are scheduled calls outside the time window
            $sPeticionTotal =
                'SELECT COUNT(*) AS N FROM calls '.
                'WHERE id_campaign = ? '.
                    'AND (status IS NULL OR status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold")) '.
                    'AND retries < ? '.
                    'AND dnc = 0';
            $recordset = $this->_db->prepare($sPeticionTotal);
            $recordset->execute(array($campaignId, $infoCampania['retries']));
            $iNumTotal = $recordset->fetch(PDO::FETCH_COLUMN, 0);
            $recordset->closeCursor();
            if (!is_null($iNumTotal) && $iNumTotal > 0) {
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__." (campaign $campaignId ".
                        "queue {$infoCampania['queue']}) no calls to ".
                        "place; $iNumTotal scheduled calls but outside ".
                        "time range. | (campaña $campaignId ".
                        "cola {$infoCampania['queue']}) no hay llamadas a ".
                        "colocar; $iNumTotal llamadas agendadas pero fuera de ".
                        "horario.");
                }
                return FALSE;
            }
        }

        // Check DNC list / Verificar lista DNC
        $recordset = $this->_db->prepare(
            'SELECT COUNT(*) FROM dont_call WHERE caller_id = ? AND status = "A"');
        $sth = $this->_db->prepare(
            'UPDATE calls SET dnc = 1 WHERE id_campaign = ? AND id = ?');
        foreach (array_keys($listaLlamadas) as $k) {
            $recordset->execute(array($k));
            $iNumDNC = $recordset->fetch(PDO::FETCH_COLUMN, 0);
            $recordset->closeCursor();
            if ($iNumDNC > 0) {
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__." (campaign $campaignId ".
                        "número $k encontrado en lista DNC, no se marcará. | EN: number $k found in DNC list, will not be dialed.");
                }
                $sth->execute(array($campaignId, $listaLlamadas[$k]['id']));
                unset($listaLlamadas[$k]);
            }
        }

        // Send to AMIEventProcess for monitoring / Enviar a AMIEventProcess para monitoreo
        if (count($listaLlamadas) > 0) {
            $listaKeyRepetidos = $this->_tuberia->AMIEventProcess_nuevasLlamadasMarcar($listaLlamadas);
            foreach ($listaKeyRepetidos as $k) {
                $sKey = $listaLlamadas[$k]['actionid'];
                $sCanalTrunk = $listaLlamadas[$k]['dialstring'];
                $this->_log->output("INFO: se ignora llamada $sKey con DialString ".
                    "$sCanalTrunk - mismo DialString usado por llamada monitoreada. | EN: call $sKey with DialString ".
                    "$sCanalTrunk ignored - same DialString used by monitored call.");
                unset($listaLlamadas[$k]);
            }
        }

        // Prepared statements for call placement
        $sPeticionLlamadaColocada = <<<SQL_LLAMADA_COLOCADA
UPDATE calls SET status = 'Placing', datetime_originate = ?, fecha_llamada = NULL,
    datetime_entry_queue = NULL, start_time = NULL, end_time = NULL,
    duration_wait = NULL, duration = NULL, failure_cause = NULL,
    failure_cause_txt = NULL, uniqueid = NULL, id_agent = NULL,
    retries = retries + 1
WHERE id_campaign = ? AND id = ?
SQL_LLAMADA_COLOCADA;
        $sth_placing = $this->_db->prepare($sPeticionLlamadaColocada);

        // Generate calls / Generar llamadas
        $queue_monitor_format = NULL;
        while (count($listaLlamadas) > 0) {
            $tupla = array_shift($listaLlamadas);

            $listaVars = array(
                'ID_CAMPAIGN'   =>  $campaignId,
                'ID_CALL'       =>  $tupla['id'],
                'NUMBER'        =>  $tupla['phone'],
                'QUEUE'         =>  $infoCampania['queue'],
                'CONTEXT'       =>  $infoCampania['context'],
            );
            if (!is_null($tupla['agent'])) {
                /* calls.agent guarda el canal lógico del agente: Agent/XXXX para
                 * agentes de tipo Agent, SIP/XXX o PJSIP/XXX para los de tipo
                 * callback. El contexto llamada_agendada marca este valor tal
                 * cual, y chan_agent ya no existe desde Asterisk 12, así que
                 * Agent/XXXX debe traducirse a la interfaz de app_agent_pool.
                 * calls.agent stores the agent's logical channel: Agent/XXXX for
                 * Agent type agents, SIP/XXX or PJSIP/XXX for callback type. The
                 * llamada_agendada context dials this value as-is, and chan_agent
                 * no longer exists since Asterisk 12, so Agent/XXXX must be
                 * translated to the app_agent_pool interface. */
                $sCanalMarcarAgente = $tupla['agent'];
                if (!is_null($this->_compat) &&
                    preg_match('|^Agent/(\d+)$|', $sCanalMarcarAgente, $regsAgente))
                    $sCanalMarcarAgente = $this->_compat->getAgentQueueInterface($regsAgente[1]);
                $listaVars['AGENTCHANNEL'] = $sCanalMarcarAgente;
                if (is_null($queue_monitor_format))
                    $queue_monitor_format = $this->_formatoGrabacionCola($infoCampania['queue']);
                $listaVars['QUEUE_MONITOR_FORMAT'] = $queue_monitor_format;
            }
            $sCadenaVar = $this->_construirCadenaVariables($listaVars);
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__." generating call | generando llamada\n".
                    "\tKey/Clave....... {$tupla['actionid']}\n" .
                    "\tAgent/Agente...... ".(is_null($tupla['agent']) ? '(none/ninguno)' : $tupla['agent'].
                        ($tupla['agent'] == $listaVars['AGENTCHANNEL'] ? '' : ' -> '.$listaVars['AGENTCHANNEL']))."\n" .
                    "\tDestination/Destino..... {$tupla['phone']}\n" .
                    "\tQueue/Cola........ {$infoCampania['queue']}\n" .
                    "\tContext/Contexto.... {$infoCampania['context']}\n" .
                    "\tContext Var/Var. Contexto $sCadenaVar\n" .
                    "\tTrunk....... ".(is_null($infoCampania['trunk']) ? '(by dial plan/por plan de marcado)' : $infoCampania['trunk'])."\n" .
                    "\tTemplate/Plantilla... ".$datosTrunk['TRUNK']."\n" .
                    "\tCaller ID... ".(isset($datosTrunk['CID']) ? $datosTrunk['CID'] : "(not defined/no definido)")."\n".
                    "\tDial string/Cadena de marcado... {$tupla['dialstring']}\n".
                    "\tDial timeout/Timeout marcado..... ".(is_null($iTimeoutOriginate) ? '(default/por omisión)' : $iTimeoutOriginate.' ms.'));
            }

            try {
                $this->_db->beginTransaction();

                $iTimestampInicioOriginate = time();
                $sth_placing->execute(array(
                    date('Y-m-d H:i:s', $iTimestampInicioOriginate),
                    $campaignId,
                    $tupla['id']
                ));

                $this->_db->commit();
            } catch (PDOException $e) {
                if (!is_null($this->_db)) {
                    $this->_db->rollBack();
                }

                $this->_log->output('WARN: '.__METHOD__.' aborting '.
                    count($listaLlamadas).' undialed calls due to DB exception... | abortando '.
                    count($listaLlamadas).' llamadas sin marcar debido a excepción de DB...');
                $llamadasAbortar = array($tupla['actionid']);
                foreach ($listaLlamadas as $t) $llamadasAbortar[] = $t['actionid'];
                $this->_tuberia->msg_AMIEventProcess_abortarNuevasLlamadasMarcar($llamadasAbortar);

                throw $e;
            }

            // Execute the call through AMIEventProcess
            $this->_tuberia->AMIEventProcess_ejecutarOriginate(
                $tupla['actionid'], $iTimeoutOriginate, $iTimestampInicioOriginate,
                (is_null($tupla['agent']) ? $infoCampania['context'] : 'llamada_agendada'),
                (isset($datosTrunk['CID']) ? $datosTrunk['CID'] : $tupla['phone']),
                $sCadenaVar, (is_null($tupla['retries']) ? 0 : $tupla['retries']) + 1,
                $infoCampania['trunk']);
        }

        // Mark campaign as finished if no more calls
        if ($iNumLlamadasColocar > 0 && $iNumTotalLlamadas <= 0) {
            $this->_log->output('INFO: marking campaign as finished: '.$campaignId.' | marcando campaña como finalizada: '.$campaignId);
            $sth = $this->_db->prepare('UPDATE campaign SET estatus = "T" WHERE id = ?');
            $sth->execute(array($campaignId));
        }

        return TRUE;
    }

    /* Leer el formato de grabación de la cola indicada por el parámetro, la cual
     * está indicada en queues_additional.conf
     * Read the recording format of the queue indicated by the parameter, which
     * is indicated in queues_additional.conf */
    private function _formatoGrabacionCola($sCola)
    {
    	$sColaActual = NULL;
        foreach (file('/etc/asterisk/queues_additional.conf') as $s) {
    		$regs = NULL;
            if (preg_match('/^\[(\d+)\]/', $s, $regs)) {
    			$sColaActual = $regs[1];
    		} elseif ($sColaActual == $sCola && preg_match('/^monitor-format=(\w+)/', $s, $regs)) {
    			return $regs[1];
    		}
    	}
        return NULL;
    }

    private function _actualizarLlamadasAgendables($infoCampania, $datosTrunk)
    {
        $listaAgentesAgendados = $this->_listarAgentesAgendadosReserva($infoCampania['id']);
        if (count($listaAgentesAgendados) <= 0) return array();

        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.': lista de agentes con llamadas agendadas | EN: list of agents with scheduled calls: '.
                print_r($listaAgentesAgendados, 1));
        }
        $resultado = $this->_tuberia->AMIEventProcess_agentesAgendables($listaAgentesAgendados);
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.': resultado de agentesAgendables | EN: result of agentesAgendables: '.
                print_r($resultado, 1));
        }

        // Leer una llamada para cada agente que se puede usar en agendamiento
        // Read one call for each agent that can be used in scheduling
        $listaLlamadas = array();
        $pid = posix_getpid();
        foreach ($resultado as $sAgente) {
            $tupla = $this->_listarLlamadasAgendables($infoCampania['id'], $sAgente);
            if (is_array($tupla)) {
                $tupla['actionid'] = sprintf('%d-%d-%d', $pid, $infoCampania['id'], $tupla['id']);
                $tupla['dialstring'] = str_replace('$OUTNUM$', $tupla['phone'], $datosTrunk['TRUNK']);

                /* Para poder monitorear el evento Onnewchannel, se depende de la
                 * cadena de marcado para identificar cuál de todos los eventos es
                 * el correcto. Si una llamada generada produce la misma cadena de
                 * marcado que una que ya se monitorea, o que otra en la misma
                 * lista, ocurrirán confusiones entre los eventos. Se filtran las
                 * llamadas que tengan cadenas de marcado repetidas.
                 * To monitor the Onnewchannel event, we depend on the dial string
                 * to identify which of all events is the correct one. If a generated
                 * call produces the same dial string as one already being monitored,
                 * or another in the same list, confusion will occur between events.
                 * Calls with repeated dial strings are filtered out. */

                if (!isset($listaLlamadas[$tupla['phone']])) {
                    // Llamada no repetida, se procesa normalmente
                    // Non-repeated call, processed normally
                    $listaLlamadas[$tupla['phone']] = $tupla;
                } else {
                    // Se ha encontrado en la lectura un número de teléfono repetido
                    // A repeated phone number was found in the reading
                    $this->_log->output("INFO: se ignora llamada {$tupla['actionid']} ".
                        "con DialString {$tupla['dialstring']} - mismo DialString ".
                        "usado por llamada a punto de originar. | EN: ignoring call {$tupla['actionid']} ".
                        "with DialString {$tupla['dialstring']} - same DialString ".
                        "used by call about to originate.");
                }
            } else {
                $this->_log->output('WARN: '.__METHOD__.': no se encontró '.
                    'llamada agendada esperada para agente: '.$sAgente.' | EN: expected scheduled call not found for agent: '.$sAgente);
            }
        }

        return $listaLlamadas;
    }

    /**
     * Procedimiento para obtener el número de segundos de reserva de una campaña
     * Procedure to obtain the number of reservation seconds for a campaign
     */
    private function _getSegundosReserva($idCampaign)
    {
        return 30;  // TODO: volver configurable en DB o por campaña
                    // TODO: make configurable in DB or per campaign
    }

    /**
     * Función para listar todos los agentes que tengan al menos una llamada
     * agendada, ahora, o en los siguientes RESERVA segundos, donde RESERVA se
     * reporta por getSegundosReserva().
     * Function to list all agents that have at least one scheduled call, now,
     * or in the next RESERVA seconds, where RESERVA is reported by
     * getSegundosReserva().
     *
     * @return array    Lista de agentes
     *                  List of agents
     */
    private function _listarAgentesAgendadosReserva($id_campania)
    {
        $listaAgentes = array();
        $iSegReserva = $this->_getSegundosReserva($id_campania);
        $sFechaSys = date('Y-m-d');
        $iTimestamp = time();
        $sHoraInicio = date('H:i:s', $iTimestamp);
        $sHoraFinal = date('H:i:s', $iTimestamp + $iSegReserva);

        // Listar todos los agentes que tienen alguna llamada agendada dentro del horario
        // List all agents that have a scheduled call within the time range
        $sPeticionAgentesAgendados = <<<PETICION_AGENTES_AGENDADOS
SELECT DISTINCT agent FROM calls, campaign
WHERE calls.id_campaign = ?
    AND (calls.status IS NULL OR calls.status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND calls.dnc = 0
    AND calls.date_init <= ? AND calls.date_end >= ? AND calls.time_init <= ? AND calls.time_end >= ?
    AND calls.retries < campaign.retries
    AND calls.id_campaign = campaign.id
    AND calls.agent IS NOT NULL
PETICION_AGENTES_AGENDADOS;
        $recordset = $this->_db->prepare($sPeticionAgentesAgendados);
        $recordset->execute(array(
            $id_campania,
            $sFechaSys,
            $sFechaSys,
            $sHoraFinal,
            $sHoraInicio));
        $listaAgentes = $recordset->fetchAll(PDO::FETCH_COLUMN, 0);
        return $listaAgentes;
    }

    /**
     * Función para contar todas las llamadas agendadas para el agente indicado,
     * clasificadas en llamadas agendables AHORA, y llamadas que caen en RESERVA.
     * Function to count all calls scheduled for the indicated agent, classified
     * into schedulable calls NOW, and calls that fall into RESERVE.
     *
     * @return array Tupla de la forma array(AHORA => x, RESERVA => y)
     *               Tuple of the form array(NOW => x, RESERVE => y)
     */
    private function _contarLlamadasAgendablesReserva($id_campania, $sAgent)
    {
        $cuentaLlamadas = array('AHORA' => 0, 'RESERVA' => 0);
        $iSegReserva = $this->_getSegundosReserva($id_campania);
        $sFechaSys = date('Y-m-d');
        $iTimestamp = time();
        $sHoraInicio = date('H:i:s', $iTimestamp);
        $sHoraFinal = date('H:i:s', $iTimestamp + $iSegReserva);

    $sPeticionLlamadasAgente = <<<PETICION_LLAMADAS_AGENTE
SELECT COUNT(*) AS TOTAL, SUM(IF(calls.time_init > ?, 1, 0)) AS RESERVA
FROM calls, campaign
WHERE calls.id_campaign = ?
    AND calls.agent = ?
    AND (calls.status IS NULL OR calls.status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND calls.dnc = 0
    AND calls.date_init <= ? AND calls.date_end >= ? AND calls.time_init <= ? AND calls.time_end >= ?
    AND calls.retries < campaign.retries
    AND calls.id_campaign = campaign.id
PETICION_LLAMADAS_AGENTE;
        $recordset = $this->_db->prepare($sPeticionLlamadasAgente);
        $recordset->execute(array(
                $sHoraInicio,
                $id_campania,
                $sAgent,
                $sFechaSys,
                $sFechaSys,
                $sHoraFinal,
                $sHoraInicio));
        $tupla = $recordset->fetch(PDO::FETCH_NUM);
        $recordset->closeCursor();
        $cuentaLlamadas['RESERVA'] = $tupla[1];
        $cuentaLlamadas['AHORA'] = $tupla[0] - $cuentaLlamadas['RESERVA'];
        return $cuentaLlamadas;
    }

    /**
     * Procedimiento para listar la primera llamada agendable para la campaña y el
     * agente indicados.
     * Procedure to list the first schedulable call for the indicated campaign
     * and agent.
     */
    private function _listarLlamadasAgendables($id_campania, $sAgente)
    {
        $sFechaSys = date('Y-m-d');
        $sHoraSys = date('H:i:s');

        $sPeticionLlamadasAgente = <<<PETICION_LLAMADAS_AGENTE
SELECT calls.id_campaign, calls.id, calls.phone, calls.agent, calls.retries
FROM calls, campaign
WHERE calls.id_campaign = ?
    AND calls.agent = ?
    AND (calls.status IS NULL OR calls.status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold"))
    AND calls.dnc = 0
    AND calls.date_init <= ? AND calls.date_end >= ? AND calls.time_init <= ? AND calls.time_end >= ?
    AND calls.retries < campaign.retries
    AND calls.id_campaign = campaign.id
ORDER BY calls.retries, calls.date_end, calls.time_end, calls.date_init, calls.time_init
LIMIT 0,1
PETICION_LLAMADAS_AGENTE;
        $recordset = $this->_db->prepare($sPeticionLlamadasAgente);
        $recordset->execute(array(
            $id_campania,
            $sAgente,
            $sFechaSys,
            $sFechaSys,
            $sHoraSys,
            $sHoraSys));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        return $tupla;
    }

    /**
     * Procedimiento que construye una plantilla de marcado a partir de una
     * definición de trunk. Una plantilla de marcado es una cadena de texto de
     * la forma 'blablabla$OUTNUM$blabla' donde $OUTNUM$ es el lugar en que
     * debe constar el número saliente que va a marcarse. Por ejemplo, para
     * trunks de canales ZAP, la plantilla debe ser algo como Zap/g0/$OUTNUM$
     * Procedure that builds a dialing template from a trunk definition. A dialing
     * template is a text string of the form 'blablabla$OUTNUM$blabla' where
     * $OUTNUM$ is the place where the outgoing number to be dialed must appear.
     * For example, for ZAP channel trunks, the template should be something like
     * Zap/g0/$OUTNUM$
     *
     * @param   string  $sTrunk     Patrón que define el trunk a usar por la campaña
     *                             Pattern that defines the trunk to be used by the campaign
     *
     * @return  mixed   La cadena de plantilla de marcado, o NULL en error
     *                  The dial template string, or NULL on error
     */
    private function _construirPlantillaMarcado($sTrunk)
    {
        if (is_null($sTrunk)) {
            // La campaña requiere marcar por plan de marcado FreePBX
            // The campaign requires dialing through FreePBX dial plan
            return array('TRUNK' => 'Local/$OUTNUM$@from-internal');
        } elseif (stripos($sTrunk, '$OUTNUM$') !== FALSE) {
            // Este es un trunk personalizado que provee $OUTNUM$ ya preparado
            // This is a custom trunk that provides $OUTNUM$ already prepared
            return array('TRUNK' => $sTrunk);
        } elseif (strpos($sTrunk, 'SIP/') === 0
            || stripos($sTrunk, 'PJSIP/') === 0
            || stripos($sTrunk, 'Zap/') === 0
            || stripos($sTrunk, 'DAHDI/') === 0
            || strpos($sTrunk,  'IAX/') === 0
            || strpos($sTrunk, 'IAX2/') === 0) {
            /* Este es un trunk Zap, SIP, PJSIP o IAX. Se debe concatenar el
             * prefijo de marcado (si existe), y a continuación el número a marcar.
             * PJSIP debe comprobarse por separado: 'PJSIP/' no empieza por 'SIP/'
             * (strpos devuelve 2, no 0), asi que sin esta linea getTrunks() ofrece
             * el trunk en la GUI pero la campaña lo rechaza como tipo desconocido
             * y reintenta en bucle sin llegar a marcar nunca.
             * _leerPropiedadesTrunk() ya resuelve PJSIP: pasa la tecnologia a
             * minusculas y consulta asterisk.trunks con tech='pjsip'. */
            /* This is a Zap, SIP, PJSIP or IAX trunk. The dialing prefix must be
             * concatenated (if it exists), followed by the number to dial.
             * PJSIP has to be checked separately: 'PJSIP/' does not start with
             * 'SIP/' (strpos returns 2, not 0), so without this line getTrunks()
             * offers the trunk in the GUI but the campaign rejects it as an
             * unknown type and retries in a loop without ever dialling.
             * _leerPropiedadesTrunk() already resolves PJSIP: it lowercases the
             * technology and queries asterisk.trunks with tech='pjsip'. */
            $infoTrunk = $this->_leerPropiedadesTrunk($sTrunk);
            if (is_null($infoTrunk)) return NULL;

            $sPrefijo = isset($infoTrunk['PREFIX']) ? $infoTrunk['PREFIX'] : '';
            if (stripos($sTrunk, 'PJSIP/') === 0) {
                /* chan_pjsip no acepta la forma TECH/troncal/numero de chan_sip.
                 * Su sintaxis es PJSIP/<usuario>@<endpoint>, y es exactamente lo
                 * que hace issabelPBX en macro-dialout-trunk: arma primero
                 * DIALSTR=<trunk>/<OUTNUM> y luego, si empieza por PJSIP, lo
                 * reescribe con Set(DIALSTR=PJSIP/${OUTNUM}@${PJ}).
                 * Con la forma de chan_sip el Originate falla de inmediato con
                 * Response=Failure, Reason=0 y Uniqueid=<unknown>, porque
                 * Asterisk rechaza la cadena de canal antes de crear el canal. */
                /* chan_pjsip does not accept chan_sip's TECH/trunk/number form.
                 * Its syntax is PJSIP/<user>@<endpoint>, which is exactly what
                 * issabelPBX does in macro-dialout-trunk: it first builds
                 * DIALSTR=<trunk>/<OUTNUM> and then, if it starts with PJSIP,
                 * rewrites it with Set(DIALSTR=PJSIP/${OUTNUM}@${PJ}).
                 * With the chan_sip form the Originate fails immediately with
                 * Response=Failure, Reason=0 and Uniqueid=<unknown>, because
                 * Asterisk rejects the channel string before creating a channel. */
                list($sTecnologia, $sEndpoint) = explode('/', $sTrunk, 2);

                // PJSIP/<PREFIX>$OUTNUM$@ENDPOINT
                $sPlantilla = $sTecnologia.'/'.$sPrefijo.'$OUTNUM$@'.$sEndpoint;
            } else {
                // SIP/TRUNKLABEL/<PREFIX>$OUTNUM$
                $sPlantilla = $sTrunk.'/'.$sPrefijo.'$OUTNUM$';
            }

            // Agregar información de Caller ID, si está disponible
            // Add Caller ID information, if available
            $plantilla = array('TRUNK' => $sPlantilla);
            if (isset($infoTrunk['CID']) && trim($infoTrunk['CID']) != '')
                $plantilla['CID'] = $infoTrunk['CID'];
            return $plantilla;
        } else {
            $this->_log->output("ERR: trunk '$sTrunk' es un tipo de trunk desconocido. Actualice su versión de CallCenter. | EN: trunk '$sTrunk' is an unknown trunk type. Update your CallCenter version.");
            return NULL;
        }
    }

    /**
     * Procedimiento que lee las propiedades del trunk indicado a partir de la
     * base de datos de FreePBX. Este procedimiento puede tomar algo de tiempo,
     * porque se requiere la información de /etc/amportal.conf para obtener las
     * credenciales para conectarse a la base de datos.
     * Procedure that reads the properties of the indicated trunk from the FreePBX
     * database. This procedure may take some time because it requires information
     * from /etc/amportal.conf to obtain credentials to connect to the database.
     *
     * @param   string  $sTrunk     Trunk sobre la cual leer información de DB
     *                             Trunk from which to read DB information
     *
     * @return  mixed   NULL en caso de error, o arreglo de propiedades
     *                  NULL on error, or array of properties
     */
    private function _leerPropiedadesTrunk($sTrunk)
    {
        /* Para evitar excesivas conexiones, se mantiene un cache de la información leída
         * acerca de un trunk durante los últimos 30 segundos.
         * To avoid excessive connections, a cache of the information read about a trunk
         * is maintained for the last 30 seconds.
         */
        if (isset($this->_plantillasMarcado[$sTrunk])) {
            if (time() - $this->_plantillasMarcado[$sTrunk]['TIMESTAMP'] >= 30)
                unset($this->_plantillasMarcado[$sTrunk]);
        }
        if (isset($this->_plantillasMarcado[$sTrunk])) {
            return $this->_plantillasMarcado[$sTrunk]['PROPIEDADES'];
        }

        $dbConn = $this->_abrirConexionFreePBX();
        if (is_null($dbConn)) return NULL;

        $infoTrunk = NULL;
        $sTrunkConsulta = $sTrunk;

        try {
            if ($this->_existeTrunksFPBX) {
                /* Consulta directa de las opciones del trunk indicado. Se debe
                 * separar la tecnología del nombre de la troncal, y consultar en
                 * campos separados en la tabla asterisk.trunks
                 * Direct query of the options of the indicated trunk. The technology
                 * must be separated from the trunk name, and queried in separate
                 * fields in the asterisk.trunks table. */
                $camposTrunk = explode('/', $sTrunkConsulta, 2);
                if (count($camposTrunk) < 2) {
                    $this->_log->output("ERR: trunk '$sTrunkConsulta' no se puede interpretar, se espera formato TECH/CHANNELID | EN: trunk '$sTrunkConsulta' cannot be interpreted, TECH/CHANNELID format expected");
                    $dbConn = NULL;
                    return NULL;
                }

                // Formas posibles de localizar la información deseada de troncales
                // Possible ways to locate the desired trunk information
                $listaIntentos = array(
                    array(
                        'tech'      => strtolower($camposTrunk[0]),
                        'channelid' => $camposTrunk[1]
                    ),
                );
                if ($listaIntentos[0]['tech'] == 'dahdi') {
                    $listaIntentos[] = array(
                        'tech'      => 'zap',
                        'channelid' => $camposTrunk[1]
                    );
                }
                $sPeticionSQL =
                    'SELECT outcid AS CID, dialoutprefix AS PREFIX '.
                    'FROM trunks WHERE tech = ? AND channelid = ?';
                $recordset = $dbConn->prepare($sPeticionSQL);
                foreach ($listaIntentos as $tuplaIntento) {
                    $recordset->execute(array($tuplaIntento['tech'], $tuplaIntento['channelid']));
                    $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
                    $recordset->closeCursor();
                    if ($tupla) {
                        $infoTrunk = array();
                        if ($tupla['CID'] != '') $infoTrunk['CID'] = $tupla['CID'];
                        if ($tupla['PREFIX'] != '') $infoTrunk['PREFIX'] = $tupla['PREFIX'];
                        $this->_plantillasMarcado[$sTrunk] = array(
                            'TIMESTAMP'     =>  time(),
                            'PROPIEDADES'   =>  $infoTrunk,
                        );
                        break;
                    }
                }
            } else {
                /* Buscar cuál de las opciones describe el trunk indicado. En FreePBX,
                 * la información de los trunks está guardada en la tabla 'globals',
                 * donde globals.value tiene el nombre del trunk buscado, y
                 * globals.variable es de la forma OUT_NNNNN. El valor de NNN se usa
                 * para consultar el resto de las variables
                 * Find which of the options describes the indicated trunk. In FreePBX,
                 * trunk information is stored in the 'globals' table, where
                 * globals.value has the name of the sought trunk, and globals.variable
                 * is of the form OUT_NNNNN. The value of NNN is used to query the
                 * rest of the variables.
                 */
                $recordset = $dbConn->prepare("SELECT variable FROM globals WHERE value = ? AND variable LIKE 'OUT_%'");
                $recordset->execute(array($sTrunkConsulta));
                $sVariable = $recordset->fetch(PDO::FETCH_COLUMN, 0);
                $recordset->closeCursor();
                if (!$sVariable && strpos($sTrunkConsulta, 'DAHDI') !== 0) {
                    $this->_log->output("ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX (1) - trunk no se encuentra! | EN: querying trunk information '$sTrunkConsulta' in FreePBX (1) - trunk not found!");
                    $dbConn = NULL;
                    return NULL;
                }

                if (!$sVariable && strpos($sTrunkConsulta, 'DAHDI') === 0) {
                    /* Podría ocurrir que esta versión de FreePBX todavía guarda la
                     * información sobre troncales DAHDI bajo nombres ZAP. Para
                     * encontrarla, se requiere de transformación antes de la consulta.
                     * It may happen that this version of FreePBX still stores DAHDI
                     * trunk information under ZAP names. To find it, transformation
                     * is required before the query.
                     */
                    $sTrunkConsulta = str_replace('DAHDI', 'ZAP', $sTrunk);
                    $recordset->execute(array($sTrunkConsulta));
                    $sVariable = $recordset->fetch(PDO::FETCH_COLUMN, 0);
                    $recordset->closeCursor();
                    if (!$sVariable) {
                        $this->_log->output("ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX (1) - trunk no se encuentra! | EN: querying trunk information '$sTrunkConsulta' in FreePBX (1) - trunk not found!");
                        $dbConn = NULL;
                        return NULL;
                    }
                }

                $regs = NULL;
                if (!preg_match('/^OUT_([[:digit:]]+)$/', $sVariable, $regs)) {
                    $this->_log->output("ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX (1) - se esperaba OUT_NNN pero se encuentra $sVariable - versión incompatible de FreePBX? | EN: querying trunk information '$sTrunkConsulta' in FreePBX (1) - expected OUT_NNN but found $sVariable - incompatible FreePBX version?");
                } else {
                    $iNumTrunk = $regs[1];

                    // Consultar todas las variables asociadas al trunk
                    // Query all variables associated with the trunk
                    $sPeticionSQL = 'SELECT variable, value FROM globals WHERE variable LIKE ?';
                    $recordset = $dbConn->prepare($sPeticionSQL);
                    $recordset->execute(array('OUT%_'.$iNumTrunk));
                    $recordset->setFetchMode(PDO::FETCH_ASSOC);
                    $infoTrunk = array();
                    $sRegExp = '/^OUT(.+)_'.$iNumTrunk.'$/';
                    foreach ($recordset as $tupla) {
                        $regs = NULL;
                        if (preg_match($sRegExp, $tupla['variable'], $regs)) {
                            $sValor = trim($tupla['value']);
                            if ($sValor != '') $infoTrunk[$regs[1]] = $sValor;
                        }
                    }
                    $this->_plantillasMarcado[$sTrunk] = array(
                        'TIMESTAMP'     =>  time(),
                        'PROPIEDADES'   =>  $infoTrunk,
                    );
                }
            }
        } catch (PDOException $e) {
        	$this->_log->output(
                "ERR: al consultar información de trunk '$sTrunkConsulta' en FreePBX - ".
                implode(' - ', $e->errorInfo)." | EN: querying trunk information '$sTrunkConsulta' in FreePBX - ".
                implode(' - ', $e->errorInfo));
        }

        $dbConn = NULL;
        return $infoTrunk;
    }

    // TODO: encontrar manera elegante de tener una sola definición
    // TODO: find an elegant way to have a single definition
    private function _abrirConexionFreePBX()
    {
        $sNombreConfig = '/etc/amportal.conf';  // TODO: vale la pena poner esto en config?
                                                // TODO: is it worth putting this in config?

        // De algunas pruebas se desprende que parse_ini_file no puede parsear
        // /etc/amportal.conf, de forma que se debe abrir directamente.
        // Some tests show that parse_ini_file cannot parse /etc/amportal.conf,
        // so it must be opened directly.
        $dbParams = array();
        $hConfig = fopen($sNombreConfig, 'r');
        if (!$hConfig) {
            $this->_log->output('ERR: no se puede abrir archivo '.$sNombreConfig.' para lectura de parámetros FreePBX. | EN: cannot open file '.$sNombreConfig.' for FreePBX parameter reading.');
            return NULL;
        }
        while (!feof($hConfig)) {
            $sLinea = fgets($hConfig);
            if ($sLinea === FALSE) break;
            $sLinea = trim($sLinea);
            if ($sLinea == '') continue;
            if ($sLinea[0] == '#') continue;

            $regs = NULL;
            if (preg_match('/^([[:alpha:]]+)[[:space:]]*=[[:space:]]*(.*)$/', $sLinea, $regs)) switch ($regs[1]) {
            case 'AMPDBHOST':
            case 'AMPDBUSER':
            case 'AMPDBENGINE':
            case 'AMPDBPASS':
                $dbParams[$regs[1]] = $regs[2];
                break;
            }
        }
        fclose($hConfig); unset($hConfig);

        // Abrir la conexión a la base de datos, si se tienen todos los parámetros
        // Open database connection if all parameters are available
        if (count($dbParams) < 4) {
            $this->_log->output('ERR: archivo '.$sNombreConfig.
                ' de parámetros FreePBX no tiene todos los parámetros requeridos para conexión. | EN: file '.$sNombreConfig.
                ' of FreePBX parameters does not have all required parameters for connection.');
            return NULL;
        }
        if ($dbParams['AMPDBENGINE'] != 'mysql' && $dbParams['AMPDBENGINE'] != 'mysqli') {
            $this->_log->output('ERR: archivo '.$sNombreConfig.
                ' de parámetros FreePBX especifica AMPDBENGINE='.$dbParams['AMPDBENGINE'].
                ' que no ha sido probado. | EN: file '.$sNombreConfig.
                ' of FreePBX parameters specifies AMPDBENGINE='.$dbParams['AMPDBENGINE'].
                ' which has not been tested.');
            return NULL;
        }
        try {
            $dbConn = new PDO("mysql:host={$dbParams['AMPDBHOST']};dbname=asterisk;charset=utf8mb4",
                $dbParams['AMPDBUSER'], $dbParams['AMPDBPASS']);
            $dbConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $dbConn->setAttribute(PDO::ATTR_EMULATE_PREPARES, FALSE);
            return $dbConn;
        } catch (PDOException $e) {
            $this->_log->output("ERR: no se puede conectar a DB de FreePBX - ".
                $e->getMessage()." | EN: cannot connect to FreePBX DB - ".
                $e->getMessage());
            return NULL;
        }
    }

    private function _leerTiempoContestar($idCampaign)
    {
    	return $this->_tuberia->AMIEventProcess_leerTiempoContestar($idCampaign);
    }

    /* Contar el número de llamadas que se colocaron en la cola $queue y que han
     * sido originadas, pero todavía esperan respuesta
     * Count the number of calls placed in queue $queue that have been originated
     * but are still waiting for response */
    private function _contarLlamadasEsperandoRespuesta($queue)
    {
        return $this->_tuberia->AMIEventProcess_contarLlamadasEsperandoRespuesta($queue);
    }

    /**
     * Count active calls for a campaign (Placing, Ringing, OnQueue, OnHold).
     * Used to calculate effective max_canales for allocation.
     *
     * Contar llamadas activas para una campaña (Placing, Ringing, OnQueue, OnHold).
     * Usado para calcular max_canales efectivo para asignación.
     *
     * @param int $campaignId The campaign ID
     * @return int Number of active calls
     */
    /**
     * @param int $iPlacingTimeout Seconds a Placing/Ringing call may be idle
     *   before being considered orphaned. Pass 0 (e.g. right after a detected
     *   Asterisk restart) to flush every such call immediately, since none of
     *   them can possibly still be in progress.
     */
    private function _cleanOrphanedPlacingCalls($iPlacingTimeout = 300)
    {
        // Timeout for Placing calls: 5 minutes (300 seconds) by default.
        // Calls stuck in Placing for longer are considered orphaned
        // Timeout para llamadas Placing: 5 minutos (300 segundos) por omisión.
        // Las llamadas en Placing por más tiempo se consideran huérfanas
        $sThreshold = date('Y-m-d H:i:s', time() - $iPlacingTimeout);

        $sPeticion = "UPDATE calls SET status = 'Failure', failure_cause = 0, "
            . "failure_cause_txt = 'Orphaned call - timeout' "
            . "WHERE status IN ('Placing', 'Ringing') AND datetime_originate < ?";
        $sth = $this->_db->prepare($sPeticion);
        $sth->execute(array($sThreshold));
        $iCleaned = $sth->rowCount();

        if ($iCleaned > 0) {
            $this->_log->output("WARN: cleaned $iCleaned orphaned Placing/Ringing calls "
                . "(older than $iPlacingTimeout seconds) | "
                . "limpiadas $iCleaned llamadas Placing/Ringing huérfanas "
                . "(más antiguas de $iPlacingTimeout segundos)");
        }
    }

    /**
     * Clean up orphaned connected calls that never got end_time set.
     * These are calls that were connected but the hangup event was missed.
     *
     * Limpiar llamadas conectadas huérfanas que nunca recibieron end_time.
     * Son llamadas que fueron conectadas pero el evento Hangup se perdió.
     */
    /**
     * @param int $iConnectedTimeout Seconds a connected call may be idle
     *   before being considered orphaned. Pass 0 (e.g. right after a detected
     *   Asterisk restart) to flush every such call immediately, since none of
     *   them can possibly still be connected.
     */
    private function _cleanOrphanedConnectedCalls($iConnectedTimeout = 7200)
    {
        // Connected calls older than 2 hours (default) with no end_time are definitely orphaned
        // Llamadas conectadas de más de 2 horas (por omisión) sin end_time son definitivamente huérfanas
        $sThreshold = date('Y-m-d H:i:s', time() - $iConnectedTimeout);

        $sPeticion = "UPDATE calls SET status = 'Hangup', end_time = start_time "
            . "WHERE (status = 'Success' OR status = 'OnHold') "
            . "AND end_time IS NULL "
            . "AND start_time IS NOT NULL "
            . "AND start_time < ?";
        $sth = $this->_db->prepare($sPeticion);
        $sth->execute(array($sThreshold));
        $iCleaned = $sth->rowCount();

        if ($iCleaned > 0) {
            $this->_log->output("WARN: cleaned $iCleaned orphaned connected calls "
                . "(older than " . ($iConnectedTimeout / 3600) . " hours) | "
                . "limpiadas $iCleaned llamadas conectadas huérfanas "
                . "(más antiguas de " . ($iConnectedTimeout / 3600) . " horas)");
        }
    }

    private function _countActiveCalls($campaignId)
    {
        $sPeticion = 'SELECT COUNT(*) FROM calls WHERE id_campaign = ? AND (status IN ("Placing", "Ringing", "OnQueue", "OnHold") OR (status = "Success" AND end_time IS NULL))';
        $recordset = $this->_db->prepare($sPeticion);
        $recordset->execute(array($campaignId));
        $count = $recordset->fetch(PDO::FETCH_COLUMN, 0);
        $recordset->closeCursor();
        $result = is_null($count) ? 0 : (int)$count;

        if ($this->DEBUG && $result > 0) {
            $this->_log->output("DEBUG: ".__METHOD__.
                " campaign $campaignId has $result active calls (Placing/Ringing/OnQueue/OnHold/Connected) | ".
                "campaña $campaignId tiene $result llamadas activas (Placing/Ringing/OnQueue/OnHold/Conectada)");
        }

        return $result;
    }

    /**
     * Check if campaign has exhausted all callable data and mark it as finished.
     * This runs independently of agent availability to prevent campaigns staying
     * active when data runs out while no agents are free.
     *
     * Verificar si la campaña agotó todos los datos marcables y marcarla como finalizada.
     * Esto se ejecuta independientemente de la disponibilidad de agentes para evitar que
     * las campañas permanezcan activas cuando los datos se agotan sin agentes libres.
     */
    private function _checkCampaignDataExhausted($campaignId, $infoCampania)
    {
        // Check if there are any active calls still in progress
        // Verificar si hay llamadas activas aún en progreso
        $activeCalls = $this->_countActiveCalls($campaignId);
        if ($activeCalls > 0) {
            return; // Still have calls in progress, don't mark as finished
        }

        // Check if there are any remaining callable records (regardless of time window)
        // Verificar si quedan registros marcables (sin importar ventana de tiempo)
        $sPeticion =
            'SELECT COUNT(*) FROM calls '.
            'WHERE id_campaign = ? '.
                'AND (status IS NULL OR status NOT IN ("Success", "Placing", "Ringing", "OnQueue", "OnHold")) '.
                'AND retries < ? '.
                'AND dnc = 0';
        $recordset = $this->_db->prepare($sPeticion);
        $recordset->execute(array($campaignId, $infoCampania['retries']));
        $iNumRemaining = $recordset->fetch(PDO::FETCH_COLUMN, 0);
        $recordset->closeCursor();

        if (is_null($iNumRemaining) || $iNumRemaining <= 0) {
            $this->_log->output('INFO: campaign data exhausted, marking as finished: '.$campaignId.
                ' | datos de campaña agotados, marcando como finalizada: '.$campaignId);
            $sth = $this->_db->prepare('UPDATE campaign SET estatus = "T" WHERE id = ? AND estatus = "A"');
            $sth->execute(array($campaignId));
        }
    }

    // Construir la cadena de variables, con separador adecuado según versión Asterisk
    // Build variable string with appropriate separator according to Asterisk version
    private function _construirCadenaVariables($listaVar)
    {
        // "ID_CAMPAIGN={$infoCampania->id}|ID_CALL={$tupla->id}|NUMBER={$tupla->phone}|QUEUE={$infoCampania->queue}|CONTEXT={$infoCampania->context}",
        $lista = array();
        foreach ($listaVar as $sKey => $sVal) {
            $lista[] = "{$sKey}={$sVal}";
        }
        return $this->_construirListaParametros($lista);
    }

    private function _construirListaParametros($listaVar)
    {
        $sSeparador = !is_null($this->_compat)
            ? $this->_compat->getVariableSeparator()
            : ',';
        return implode($sSeparador, $listaVar);
    }

    /**************************************************************************/

    public function msg_verificarFinLlamadasAgendables($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_verificarFinLlamadasAgendables'), $datos);
    }

    public function msg_finalizando($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        $this->_log->output('INFO: recibido mensaje de finalización, se detienen campañas... | EN: received shutdown message, stopping campaigns...');
        $this->_tuberia->msg_HubProcess_finalizacionTerminada();
        $this->_finalizandoPrograma = TRUE;
    }

    /**************************************************************************/

    private function _verificarFinLlamadasAgendables($sAgente, $id_campania)
    {
        $l = $this->_contarLlamadasAgendablesReserva($id_campania, $sAgente);
        if ($l['AHORA'] == 0 && $l['RESERVA'] == 0) {
            /* Por ahora el agente ya no tiene llamadas agendables y se debe
             * reducir la cuenta de pausas del agente. Si la cuenta es 1,
             * entonces se debe quitar la pausa real.
             * For now the agent no longer has schedulable calls and the agent's
             * pause count must be reduced. If the count is 1, then the real pause
             * must be removed. */
            if ($this->DEBUG) {
                $this->_log->output('DEBUG: '.__METHOD__.': el siguiente agente '.
                    'no tiene más llamadas agendadas: '.$sAgente.' | EN: the following agent '.
                    'has no more scheduled calls: '.$sAgente);
            }
            $this->_tuberia->msg_AMIEventProcess_quitarReservaAgente($sAgente);
        }
    }
}
?>

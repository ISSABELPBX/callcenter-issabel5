<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  Encoding: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 4.0                                                  |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2019 Issabel Foundation                                |
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
  $Id: AMIEventProcess.class.php, Thu 21 Nov 2019 03:39:13 PM EST, nicolas@issabel.com
*/
class AMIEventProcess extends TuberiaProcess
{
    private $DEBUG = FALSE; // VERDADERO si se activa la depuración
    // TRUE if debugging is enabled

    private $_log;              // Log abierto por framework de demonio
    // Log opened by daemon framework
    private $_config = NULL;    // Configuración informada por CampaignProcess
    // Configuration provided by CampaignProcess
    private $_alarma_faltaconfig = NULL;    // Alarma en caso de que no se envíe config
    // Alarm in case configuration is not sent
    private $_ami = NULL;       // Conexión AMI a Asterisk
    // AMI connection to Asterisk

    private $_listaAgentes;                // Lista de agentes ECCP usados
    // List of ECCP agents used
    private $_campaniasSalientes = array();     // Campañas salientes activas, por ID
    // Active outgoing campaigns, by ID
    private $_colasEntrantes = array();         // Info de colas entrantes, que puede incluir una campaña entrante
    // Incoming queue info, which may include an incoming campaign
    private $_listaLlamadas;
    private $_agentesEnAtxferComplete = array(); // Agents completing attended transfer (suppress Agentlogoff)
    private $_agentesEnConsultation = array();   // Agents in attended transfer consultation (callback type)
    private $_agentesConsultaContestada = array(); // Agent/NNNN => array('channel' => colleague's real channel, 'timestamp' => ...), set once the consult leg answers
    private $_ultimaConsultaFallida = array();   // Agent/NNNN => DIALSTATUS of the last consultation that failed (BUSY/NOANSWER/...)
    private $_consultaTerminadaEn = array();     // Agent/NNNN => microtime of the last ConsultationEnd, to reject a late "consultation started" mark
    private $_agentesEnTransferPendiente = array(); // Agents with pending blind transfer (prevent double transfer)

    // Estimación de la versión de Asterisk que se usa
    // Estimation of the Asterisk version being used
    private $_asteriskVersion = array(1, 4, 0, 0);
    private $_compat = NULL; // AsteriskCompat instance for version-aware behavior

    // Fecha y hora de inicio de Asterisk, para detectar reinicios
    // Date and time of Asterisk start, to detect restarts
    private $_asteriskStartTime = NULL;
    private $_bReinicioAsterisk = FALSE;

    private $_finalizandoPrograma = FALSE;
    private $_finalizacionConfirmada = FALSE;

    // Contadores para actividades ejecutadas regularmente
    // Counters for regularly executed activities
    private $_iTimestampVerificacionLlamadasViejas = 0; // Última verificación de llamadas viejas
    // Last verification of old calls

    // Estado de agente, por agente y luego por cola, inicializado por
    // QueueMember y actualizado en QueueMemberStatus y QueueMemberAdded
    // Agent state, by agent and then by queue, initialized by
    // QueueMember and updated in QueueMemberStatus and QueueMemberAdded
    private $_tmp_estadoAgenteCola = NULL;
    private $_tmp_actionid_queuestatus = NULL;

    /* Se setea a TRUE si se recibe nuevaListaAgentes de CampaignProcess cuando
     * la conexión AMI no está disponible, lo cual puede ocurrir si
     * SQLWorkerProcess termina de iniciarse antes que AMIEventProcess, o si
     * se pierde la conexión a Asterisk. */
    /* Set to TRUE if nuevaListaAgentes is received from CampaignProcess when
     * AMI connection is not available, which can occur if
     * SQLWorkerProcess finishes starting before AMIEventProcess, or if
     * the connection to Asterisk is lost. */
    private $_pendiente_QueueStatus = NULL;

    private $_tmp_actionid_agents = NULL;
    private $_tmp_estadoLoginAgente = NULL;

    // Lista de alarmas
    // List of alarms
    private $_nalarma = 0;
    private $_alarmas = array();

    private $_queueshadow = NULL;
    private $_saved_bridge_unique = array();
    private $_saved_bridge_channel = array();

    public function inicioPostDemonio($infoConfig, &$oMainLog)
    {
        $this->_log = $oMainLog;
        $this->_multiplex = new MultiplexServer(NULL, $this->_log);
        $this->_tuberia->registrarMultiplexHijo($this->_multiplex);
        $this->_tuberia->setLog($this->_log);
        $this->_listaLlamadas = new ListaLlamadas($this->_tuberia, $this->_log);
        $this->_listaAgentes = new ListaAgentes($this->_tuberia, $this->_log);

        // Registro de manejadores de eventos desde CampaignProcess
        // Register event handlers from CampaignProcess
        foreach (array('quitarReservaAgente', 'abortarNuevasLlamadasMarcar') as $k)
            $this->_tuberia->registrarManejador('CampaignProcess', $k, array($this, "msg_$k"));
        foreach (array('nuevasCampanias',
            'leerTiempoContestar', 'nuevasLlamadasMarcar',
            'contarLlamadasEsperandoRespuesta', 'agentesAgendables',
            'infoPrediccionCola', 'ejecutarOriginate') as $k)
            $this->_tuberia->registrarManejador('CampaignProcess', $k, array($this, "rpc_$k"));

        // Registro de manejadores de eventos desde SQLWorkerProcess
        // Register event handlers from SQLWorkerProcess
        foreach (array('actualizarConfig', 'nuevaListaAgentes', 'idnewcall',
            'idcurrentcall', 'idNuevaSesionAgente', ) as $k)
            $this->_tuberia->registrarManejador('SQLWorkerProcess', $k, array($this, "msg_$k"));
        foreach (array('informarCredencialesAsterisk', ) as $k)
            $this->_tuberia->registrarManejador('SQLWorkerProcess', $k, array($this, "rpc_$k"));

        // Registro de manejadores de eventos desde ECCPWorkerProcess
        // Register event handlers from ECCPWorkerProcess
        foreach (array('quitarBreakAgente',
            'llamadaSilenciada', 'llamadaSinSilencio', 'finalizarTransferencia',
            'marcarConsultationIniciada',
            'liberarReservaTransferencia') as $k)
            $this->_tuberia->registrarManejador('*', $k, array($this, "msg_$k"));
        foreach (array('prepararAtxferComplete',
            'agregarIntentoLoginAgente', 'infoSeguimientoAgente',
            'reportarInfoLlamadaAtendida', 'reportarInfoLlamadasCampania',
            'cancelarIntentoLoginAgente', 'reportarInfoLlamadasColaEntrante',
            'pingAgente', 'dumpstatus', 'listarTotalColasTrabajoAgente',
            'infoSeguimientoAgentesCola', 'reportarInfoLlamadaAgendada',
            'iniciarBreakAgente', 'iniciarHoldAgente',
            'esAgenteEnAtxferComplete',
            'esAgenteEnConsultation',
            'infoConsultaContestada',
            'reservarAgenteParaTransferencia') as $k)
            $this->_tuberia->registrarManejador('*', $k, array($this, "rpc_$k"));

        // Registro de manejadores de eventos desde HubProcess
        // Register event handlers from HubProcess
        $this->_tuberia->registrarManejador('HubProcess', 'finalizando', array($this, "msg_finalizando"));

        $this->_queueshadow = new QueueShadow($this->_log);

        return TRUE;
    }

    public function procedimientoDemonio()
    {
        // Verificar si la conexión AMI sigue siendo válida
        // Verify if the AMI connection is still valid
        if (!is_null($this->_config)) {
            if (!is_null($this->_alarma_faltaconfig)) {
                $this->_cancelarAlarma($this->_alarma_faltaconfig);
                $this->_alarma_faltaconfig = NULL;
            }
            if (!is_null($this->_ami) && is_null($this->_ami->sKey)) $this->_ami = NULL;
            if (is_null($this->_ami) && !$this->_finalizandoPrograma) {
                if (!$this->_iniciarConexionAMI()) {
                    $this->_log->output('ERR: no se puede restaurar conexión a Asterisk, se espera... | EN: cannot restore Asterisk connection, waiting...');
                    if ($this->_multiplex->procesarPaquetes())
                        $this->_multiplex->procesarActividad(0);
                    else $this->_multiplex->procesarActividad(5);
                } else {
                    $this->_log->output('INFO: conexión a Asterisk restaurada, se reinicia operación normal. | EN: Asterisk connection restored, resuming normal operation.');
                }
            }
        } else {
            if (is_null($this->_alarma_faltaconfig)) {
                $this->_alarma_faltaconfig = $this->_agregarAlarma(3, array($this, '_cb_faltaConfig'), array());
            }
        }

        // Verificar si existen peticiones QueueStatus pendientes
        // Verify if there are pending QueueStatus requests
        if (!is_null($this->_ami) && !is_null($this->_pendiente_QueueStatus) && !$this->_finalizandoPrograma) {
            if (is_null($this->_tmp_actionid_queuestatus)) {
                $this->_log->output("INFO: conexión AMI disponible, se ejecuta consulta QueueStatus retrasada... | EN: AMI connection available, executing delayed QueueStatus query...");
                $this->_iniciarQueueStatus($this->_pendiente_QueueStatus);
                $this->_pendiente_QueueStatus = NULL;
            } else {
                $this->_log->output("INFO: conexión AMI disponible, QueueStatus en progreso, se olvida consulta QueueStatus retrasada... | EN: AMI connection available, QueueStatus in progress, forgetting delayed QueueStatus query...");
                $this->_pendiente_QueueStatus = NULL;
            }
        }

        // Verificar si se ha reiniciado Asterisk en medio de procesamiento
        // Verify if Asterisk has restarted during processing
        if (!is_null($this->_ami) && $this->_bReinicioAsterisk) {
        	$this->_bReinicioAsterisk = FALSE;

            // Cerrar todas las llamadas
            // Close all calls
            $listaLlamadas = array();
            foreach ($this->_listaLlamadas as $llamada) {
                $listaLlamadas[] = $llamada;
            }
            foreach ($listaLlamadas as $llamada) {
                $this->_procesarLlamadaColgada($llamada, array(
                    'local_timestamp_received'  =>  microtime(TRUE),
                     'Uniqueid'                 =>  $llamada->Uniqueid,
                     'Channel'                  =>  $llamada->channel,
                     'Cause'                    =>  NULL,
                     'Cause-txt'                =>  NULL,
                ));
            }

            // Desconectar a todos los agentes
            // Disconnect all agents
            foreach ($this->_listaAgentes as $a) {
                if (!is_null($a->id_sesion)) {
                    $a->terminarLoginAgente($this->_ami,
                        microtime(TRUE));
                }
            }

            $this->_tuberia->msg_SQLWorkerProcess_requerir_nuevaListaAgentes();
        }

        // Rutear todos los mensajes pendientes entre tareas
        // Route all pending messages between tasks
        if ($this->_multiplex->procesarPaquetes())
            $this->_multiplex->procesarActividad(0);
        else $this->_multiplex->procesarActividad(1);

        // Verificar timeouts de callbacks en espera
        // Verify waiting callback timeouts
        $this->_ejecutarAlarmas();

        $this->_limpiarLlamadasViejasEspera();
        $this->_limpiarAgentesTimeout();

    	return TRUE;
    }

    public function limpiezaDemonio($signum)
    {

        // Mandar a cerrar todas las conexiones activas
        // Order to close all active connections
        $this->_multiplex->finalizarServidor();
    }

    /**************************************************************************/

    private function _cb_faltaConfig()
    {
        if (is_null($this->_config)) {
            $this->_log->output('WARN: no se dispone de credenciales para conexión a Asterisk, se piden a SQLWorkerProcess y espera... | EN: no credentials available for Asterisk connection, requesting from SQLWorkerProcess and waiting...');
            $this->_tuberia->msg_SQLWorkerProcess_requerir_credencialesAsterisk();
            $this->_alarma_faltaconfig = $this->_agregarAlarma(3, array($this, '_cb_faltaConfig'), array());
        }
    }

    private function _iniciarConexionAMI()
    {
        if (!is_null($this->_ami)) {
            $this->_log->output('INFO: Desconectando de sesión previa de Asterisk... | EN: Disconnecting from previous Asterisk session...');
            $this->_ami->disconnect();
            $this->_ami = NULL;
        }
        if (!is_null($this->_tmp_actionid_queuestatus)) {
            $this->_log->output('WARN: se desecha enumeración de colas/agentes en progreso por cierre de conexión AMI... | EN: discarding in-progress queue/agent enumeration due to AMI connection closure...');
            $this->_tmp_actionid_queuestatus = NULL;
            $this->_tmp_estadoAgenteCola = NULL;
            $this->_tmp_actionid_agents = NULL;
            $this->_tmp_estadoLoginAgente = NULL;
        }
        $astman = new AMIClientConn($this->_multiplex, $this->_log);
        //$this->_momentoUltimaConnAsterisk = time();

        $this->_log->output('INFO: Iniciando sesión de control de Asterisk... | EN: Starting Asterisk control session...');
        if (!$astman->connect(
                $this->_config['asterisk']['asthost'],
                $this->_config['asterisk']['astuser'],
                $this->_config['asterisk']['astpass'])) {
            $this->_log->output("FATAL: no se puede conectar a Asterisk Manager | EN: cannot connect to Asterisk Manager");
            return FALSE;
        } else {
            // Averiguar la versión de Asterisk que se usa
            // Find out which Asterisk version is being used
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
            $this->_log->output('INFO: Modo de compatibilidad Asterisk: '.
                ($this->_compat->hasAppAgentPool() ? 'app_agent_pool' : 'chan_agent').
                ' (versión '.$this->_compat->getVersionString().')'.
                ' | EN: Asterisk compatibility mode: '.
                ($this->_compat->hasAppAgentPool() ? 'app_agent_pool' : 'chan_agent').
                ' (version '.$this->_compat->getVersionString().')');

            /* Ejecutar el comando CoreStatus para obtener la fecha de arranque de
             * Asterisk. Si se tiene una fecha previa distinta a la obtenida aquí,
             * se concluye que Asterisk ha sido reiniciado. Durante el inicio
             * temprano de Asterisk, la fecha de inicio todavía no está lista y
             * se reportará como 1969-12-31 o similar. Se debe de repetir la llamada
             * hasta que reporte una fecha válida. */
            /* Execute CoreStatus command to get Asterisk startup date.
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
                $this->_bReinicioAsterisk = TRUE;
            }

            // Los siguientes eventos de alta frecuencia no son de interés
            // The following high frequency events are not of interest
            foreach (array('Newexten', 'RTCPSent', 'RTCPReceived') as $k)
                $astman->Filter('!Event: '.$k);

            // Instalación de los manejadores de eventos
            // Installation of event handlers
            foreach (array('Newchannel', 'Dial', 'OriginateResponse', 'Join',
                'Link', 'Hangup', 'Agentlogin', 'Agentlogoff',
                'PeerStatus', 'QueueMemberAdded','QueueMemberRemoved','VarSet',
                'QueueMemberStatus', 'QueueParams', 'QueueMember', 'QueueEntry',
                'QueueStatusComplete', 'Leave', 'Reload', 'Agents', 'AgentsComplete',
                'AgentCalled', 'AgentDump', 'AgentConnect', 'AgentComplete',
                'QueueMemberPaused', 'ParkedCall', /*'ParkedCallTimeOut',*/
                'ParkedCallGiveUp', 'QueueCallerAbandon', 'BridgeEnter',
                'UserEvent'
            ) as $k)
                $astman->add_event_handler($k, array($this, "msg_$k"));

            $astman->add_event_handler('Bridge', array($this, "msg_Link")); // Visto en Asterisk 1.6.2.x
            // Seen in Asterisk 1.6.2.x
            $astman->add_event_handler('DialBegin', array($this, "msg_Dial"));
            $astman->add_event_handler('QueueCallerJoin', array($this, "msg_Join"));
            // Asterisk 12+ renamed QueueMemberPaused to QueueMemberPause
            $astman->add_event_handler('QueueMemberPause', array($this, "msg_QueueMemberPaused"));
            $astman->add_event_handler('QueueCallerLeave', array($this, "msg_Leave")); 

            if ($this->DEBUG && $this->_config['dialer']['allevents'])
                $astman->add_event_handler('*', array($this, 'msg_Default'));

            $this->_ami = $astman;
            return TRUE;
        }
    }

    /**
     * Current attended-transfer consultation state for an agent, as a single
     * value the agent console can reconcile against on every status poll:
     * 'none', 'ringing' (colleague being called) or 'answered' (colleague
     * picked up, so the transfer can now be completed).
     *
     * The ConsultationStart/Answered/End events are only delivered to ECCP
     * clients that happen to be connected at that instant, so a console whose
     * long poll is between requests can miss one and be left showing a stale
     * "Cancel transfer" button until the page is reloaded. Reporting the state
     * here lets the console self-heal, the same way it already reconciles the
     * break and hold states it can miss events for.
     */
    private function _estadoConsultaAgente($sAgente, $a)
    {
        /* Una consulta no puede sobrevivir a la llamada desde la que se
         * inició, así que si el agente ya no tiene llamada activa cualquier
         * bandera que quede es obsoleta y se reporta 'none'. Se conserva como
         * red de seguridad: si alguna vía de limpieza fallara, sin esta
         * comprobación la consola quedaría resincronizando a "ringing"
         * indefinidamente y el botón se atascaría incluso al recargar. */
        /* EN: A consultation cannot outlive the call it was started from, so if
         * the agent no longer has an active call any leftover flag is stale and
         * 'none' is reported. Kept as a safety net: should any cleanup path
         * ever fail, without this check the console would keep resyncing to
         * "ringing" forever and the button would stick even across reloads. */
        if (is_null($a) || is_null($a->llamada)) return 'none';
        if (isset($this->_agentesConsultaContestada[$sAgente])) return 'answered';
        if (isset($this->_agentesEnConsultation[$sAgente])) return 'ringing';
        return 'none';
    }

    private function _infoSeguimientoAgente($sAgente)
    {
        if (is_array($sAgente)) {
            $is = array();
            foreach ($sAgente as $s) {
                $a = $this->_listaAgentes->buscar('agentchannel', $s);
                $is[$s] = (is_null($a)) ? NULL : $a->resumenSeguimiento();
                if (!is_null($is[$s])) $is[$s]['consultation'] = $this->_estadoConsultaAgente($s, $a);
            }
            return $is;
        } else {
            $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
            if (is_null($a)) return NULL;
            $info = $a->resumenSeguimiento();
            $info['consultation'] = $this->_estadoConsultaAgente($sAgente, $a);
            $info['consultation_reason'] = isset($this->_ultimaConsultaFallida[$sAgente])
                ? $this->_ultimaConsultaFallida[$sAgente] : '';
            return $info;
        }
    }

    private function _infoSeguimientoAgentesCola($queue, $agentsexclude = array())
    {
        $is_online = array();
        $is_offline = array();
        foreach ($this->_listaAgentes as $a) {
            if (!in_array($a->channel, $agentsexclude) &&
                (in_array($queue, $a->colas_actuales) || in_array($queue, $a->colas_dinamicas)) ) {
                // Separate logged-out agents to display them at the bottom
                if ($a->estado_consola == 'logged-out') {
                    $is_offline[$a->channel] = $a->resumenSeguimiento();
                } else {
                    $is_online[$a->channel] = $a->resumenSeguimiento();
                }
            }
        }
        // Return online agents first, then offline agents at the bottom
        return array_merge($is_online, $is_offline);
    }

    // Listar todas las colas de trabajo (las estáticas y dinámicas) para los agentes indicados
    // List all work queues (static and dynamic) for the specified agents
    private function _listarTotalColasTrabajoAgente($ks)
    {
        $queuelist = array();
        foreach ($ks as $s) {
            $a = $this->_listaAgentes->buscar('agentchannel', $s);
            if (!is_null($a)) {
                $queuelist[$s] = array($a->colas_actuales, $a->colas_dinamicas, $a->colas_penalty);
            }
        }

        return $queuelist;
    }

    private function _agregarIntentoLoginAgente($sAgente, $sExtension, $iTimeout)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            $a->max_inactivo = $iTimeout;
            $a->iniciarLoginAgente($sExtension);
        }
        return !is_null($a);
    }

    private function _cancelarIntentoLoginAgente($sAgente)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) $a->respuestaLoginAgente('Failure', NULL, NULL);
        return !is_null($a);
    }

    private function _idNuevaSesionAgente($sAgente, $id_sesion)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            if (!is_null($a->id_sesion) && $a->id_sesion != $id_sesion) {
                $this->_log->output('ERR: '.__METHOD__." - posible carrera, ".
                    "id_sesion ya asignado para $sAgente, se pierde anterior. ".
                    "ID anterior={$a->id_sesion} ID nuevo={$id_sesion}".
                    " | EN: possible race condition, id_sesion already assigned for $sAgente, losing previous. ".
                    "Previous ID={$a->id_sesion} New ID={$id_sesion}");
            }
            $a->id_sesion = $id_sesion;
        }
    }

    private function _quitarBreakAgente($sAgente)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            if (!is_null($a->id_break)) {
                $a->clearBreak($this->_ami);
            }
        }
    }

    private function _quitarReservaAgente($sAgente)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            $a->clearReserved($this->_ami);
        }
    }

    private function _reportarInfoLlamadaAtendida($sAgente)
    {
        if (is_array($sAgente)) {
            $il = array();
            foreach ($sAgente as $s) {
                $a = $this->_listaAgentes->buscar('agentchannel', $s);
                $il[$s] = (is_null($a) || is_null($a->llamada))
                    ? NULL
                    : $a->llamada->resumenLlamada();
            }
            return $il;
        } else {
            $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
            if (is_null($a) || is_null($a->llamada)) return NULL;
            return $a->llamada->resumenLlamada();
        }
    }

    private function _reportarInfoLlamadaAgendada($sAgente)
    {
        if (is_array($sAgente)) {
            $il = array();
            foreach ($sAgente as $s) {
                $a = $this->_listaAgentes->buscar('agentchannel', $s);
                $il[$s] = (is_null($a) || is_null($a->llamada_agendada))
                    ? NULL
                    : $a->llamada_agendada->resumenLlamada();
            }
            return $il;
        } else {
            $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
            if (is_null($a) || is_null($a->llamada_agendada)) return NULL;
            return $a->llamada_agendada->resumenLlamada();
        }
    }

    /**
     * Procedimiento que reporta la información sobre todas las llamadas que
     * pertenecen a la campaña indicada por $idCampania.
     */
    /**
     * Procedure that reports information on all calls that
     * belong to the campaign indicated by $idCampania.
     */
    private function _reportarInfoLlamadasCampania($sTipoCampania, $idCampania)
    {
        // Información sobre llamadas que ya están conectadas
        // Information on calls that are already connected
        $estadoCola = array();
        $llamadasPendientes = array();
        foreach ($this->_listaLlamadas as $llamada) {
            if (!is_null($llamada->campania) &&
                $llamada->campania->tipo_campania == $sTipoCampania &&
                $llamada->campania->id == $idCampania) {
                $this->_agregarInfoLlamadaCampania($llamada, $estadoCola, $llamadasPendientes);
            }
        }
        ksort($estadoCola);
        return array(
            'queuestatus'   =>  $estadoCola,
            'activecalls'   =>  $llamadasPendientes,
        );
    }

    /**
     * Procedimiento que reporta la información sobre todas las llamadas que
     * pertenecen a la cola entrante indicada por $sCola y que no pertenecen a
     * una campaña entrante específica.
     */
    /**
     * Procedure that reports information on all calls that
     * belong to the incoming queue indicated by $sCola and that do not belong to
     * a specific incoming campaign.
     */
    private function _reportarInfoLlamadasColaEntrante($sCola)
    {
        // Información sobre llamadas que ya están conectadas
        // Information on calls that are already connected
        $estadoCola = array();
        $llamadasPendientes = array();
        if (isset($this->_colasEntrantes[$sCola])) {
            foreach ($this->_listaLlamadas as $llamada) {
                if (is_null($llamada->campania) &&
                    $llamada->id_queue_call_entry == $this->_colasEntrantes[$sCola]['id_queue_call_entry']) {
                    $this->_agregarInfoLlamadaCampania($llamada, $estadoCola, $llamadasPendientes);
                }
            }
        }
        ksort($estadoCola);
        return array(
            'queuestatus'   =>  $estadoCola,
            'activecalls'   =>  $llamadasPendientes,
        );
    }

    private function _agregarInfoLlamadaCampania($llamada, &$estadoCola, &$llamadasPendientes)
    {
        if (!is_null($llamada->agente)) {
            $a = $llamada->agente;
            $sAgente = $a->channel;
            assert($llamada->agente === $a);
            assert($llamada === $a->llamada);
            $estadoCola[$sAgente] = $a->resumenSeguimientoLlamada();
        } elseif (in_array($llamada->status, array('Placing', 'Dialing', 'Ringing', 'OnQueue'))) {
            $llamadasPendientes[] = $llamada->resumenLlamada();
        }
    }

    private function _manejarLlamadaEspecialECCP($params)
    {
    	$sKey = $params['ActionID'];

        // Se revisa si esta es una de las llamadas para logonear un agente estático
        // Check if this is one of the calls to login a static agent
        $listaECCP = explode(':', $sKey);
        if ($listaECCP[0] == 'ECCP' /*&& $listaECCP[2] == posix_getpid()*/) {
            switch ($listaECCP[3]) {
            case 'AgentLogin':
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: AgentLogin({$listaECCP[4]}) detectado | EN: AgentLogin({$listaECCP[4]}) detected");
                }
                $a = $this->_listaAgentes->buscar('agentchannel', $listaECCP[4]);
                if (is_null($a)) {
                    $this->_log->output("ERR: ".__METHOD__.": no se ha ".
                        "cargado información de agente {$listaECCP[4]} | EN: agent information not loaded for {$listaECCP[4]}");
                    $this->_tuberia->msg_SQLWorkerProcess_requerir_nuevaListaAgentes();
                } else {
                    $a->respuestaLoginAgente(
                        $params['Response'], $params['Uniqueid'], $params['Channel']);
                    if ($params['Response'] == 'Success') {
                        if ($this->DEBUG) {
                            $this->_log->output("DEBUG: AgentLogin({$listaECCP[4]}) ".
                                "llamada contestada, esperando clave de agente... | EN: call answered, waiting for agent password...");
                        }
                    } else {
                        if ($this->DEBUG) {
                            $this->_log->output("DEBUG: AgentLogin({$listaECCP[4]}) ".
                                "llamada ha fallado. | EN: call has failed.");
                        }
                    }
                }
                return TRUE;
            case 'RedirectFromHold':
                /* Por ahora se ignora el OriginateResponse resultante del Originate
                 * para regresar de HOLD */
                /* For now, the resulting OriginateResponse from Originate
                 * to return from HOLD is ignored */
                return TRUE;
            case 'QueueMemberAdded':
                /* Nada que hacer */
                /* Nothing to do */
                $this->_log->output("DEBUG: ".__METHOD__.": QueueMemberAdded detectado | EN: QueueMemberAdded detected");
                return TRUE;
            default:
                $this->_log->output("ERR: ".__METHOD__.": no se ha implementado soporte ECCP para: {$sKey} | EN: ECCP support not implemented for: {$sKey}");
                return TRUE;
            }
        }
        return FALSE;   // Llamada NO es una llamada especial ECCP
        // Call is NOT a special ECCP call
    }

    private function _manejarHangupAgentLoginFallido($params)
    {
        $a = $this->_listaAgentes->buscar('uniqueidlogin', $params['Uniqueid']);
        if (is_null($a)) return FALSE;
        $a->respuestaLoginAgente('Failure', NULL, NULL);
        if ($this->DEBUG) {
            $this->_log->output("DEBUG: AgentLogin({$a->channel}) cuelga antes de ".
                "introducir contraseña | EN: hangs up before entering password");
        }
        return TRUE;
    }

    private function _nuevasCampanias($listaCampaniasAvisar)
    {
        // TODO: purgar campañas salientes fuera de horario
        // TODO: purge outgoing campaigns outside schedule
        // Nuevas campañas salientes
        // New outgoing campaigns
        foreach ($listaCampaniasAvisar['outgoing'] as $id => $tupla) {
            if (!isset($this->_campaniasSalientes[$id])) {
                $this->_campaniasSalientes[$id] = new Campania($this->_tuberia, $this->_log);
                $this->_campaniasSalientes[$id]->tiempoContestarOmision(
                    $this->_config['dialer']['tiempo_contestar']);
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': nueva campaña saliente: '.print_r($tupla, 1).' | EN: new outgoing campaign: ');
                }
            }
            $c = $this->_campaniasSalientes[$id];
            $c->tipo_campania = 'outgoing';
            $c->id = (int)$tupla['id'];
            $c->name = $tupla['name'];
            $c->queue = $tupla['queue'];
            $c->datetime_init = $tupla['datetime_init'];
            $c->datetime_end = $tupla['datetime_end'];
            $c->daytime_init = $tupla['daytime_init'];
            $c->daytime_end = $tupla['daytime_end'];
            $c->trunk = $tupla['trunk'];
            $c->context = $tupla['context'];
            $c->estadisticasIniciales($tupla['num_completadas'], $tupla['promedio'], $tupla['desviacion']);
        }

        // Purgar todas las campañas entrantes fuera de horario
        // Purge all incoming campaigns outside schedule
        $iTimestamp = time();
        $sFecha = date('Y-m-d', $iTimestamp);
        $sHora = date('H:i:s', $iTimestamp);
        foreach (array_keys($this->_colasEntrantes) as $queue) {
        	if (!is_null($this->_colasEntrantes[$queue]['campania'])) {
                $c = $this->_colasEntrantes[$queue]['campania'];
                if ($c->datetime_end < $sFecha)
                    $this->_colasEntrantes[$queue]['campania'] = NULL;
                elseif ($c->daytime_init <= $c->daytime_end && !($c->daytime_init <= $sHora && $sHora <= $c->daytime_end))
                    $this->_colasEntrantes[$queue]['campania'] = NULL;
                elseif ($c->daytime_init > $c->daytime_end && ($c->daytime_end < $sHora && $sHora < $c->daytime_init))
                    $this->_colasEntrantes[$queue]['campania'] = NULL;
                if ($this->DEBUG && is_null($this->_colasEntrantes[$queue]['campania'])) {
                	$this->_log->output('DEBUG: '.__METHOD__.': campaña entrante '.
                        'quitada por salir de horario: '.sprintf('%d %s', $c->id, $c->name).' | EN: incoming campaign removed for being outside schedule: '.sprintf('%d %s', $c->id, $c->name));
                }
            }
        }

        // Quitar las colas aisladas que no tengan asociada una campaña entrante
        // Remove isolated queues that don't have an associated incoming campaign
        foreach ($listaCampaniasAvisar['incoming_queue_old'] as $id => $tupla) {
        	if (isset($this->_colasEntrantes[$tupla['queue']])) {
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': cola entrante '.
                        'quitada: '.$tupla['queue'].' | EN: incoming queue removed: ');
                }
                unset($this->_colasEntrantes[$tupla['queue']]);
            }
        }

        // Crear nuevos registros para las nuevas colas aisladas
        // Create new records for new isolated queues
        foreach ($listaCampaniasAvisar['incoming_queue_new'] as $id => $tupla) {
            if (!isset($this->_colasEntrantes[$tupla['queue']]))
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': cola entrante '.
                        'agregada: '.$tupla['queue'].' | EN: incoming queue added: ');
                }
                $this->_colasEntrantes[$tupla['queue']] = array(
                    'id_queue_call_entry'   =>  $id,
                    'queue'                 =>  $tupla['queue'],
                    'campania'              =>  NULL,
                );
        }

        // Crear nuevas campañas que entran en servicio
        // Create new campaigns entering service
        foreach ($listaCampaniasAvisar['incoming'] as $id => $tupla) {
            if (!isset($this->_colasEntrantes[$tupla['queue']]))
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': cola entrante '.
                        'agregada: '.$tupla['queue'].' | EN: incoming queue added: ');
                }
                $this->_colasEntrantes[$tupla['queue']] = array(
                    'id_queue_call_entry'   =>  $tupla['id_queue_call_entry'],
                    'queue'                 =>  $tupla['queue'],
                    'campania'              =>  NULL,
                );
            if (is_null($this->_colasEntrantes[$tupla['queue']]['campania'])) {
            	$c = new Campania($this->_tuberia, $this->_log);
                $c->tipo_campania = 'incoming';
                $c->id = (int)$tupla['id'];
                $c->name = $tupla['name'];
                $c->queue = $tupla['queue'];
                $c->datetime_init = $tupla['datetime_init'];
                $c->datetime_end = $tupla['datetime_end'];
                $c->daytime_init = $tupla['daytime_init'];
                $c->daytime_end = $tupla['daytime_end'];
                $c->id_queue_call_entry = $tupla['id_queue_call_entry'];
                $this->_colasEntrantes[$tupla['queue']]['campania'] = $c;

                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': nueva campaña entrante: '.print_r($tupla, 1).' | EN: new incoming campaign: ');
                }
            }
        }

    	return TRUE;
    }

    private function _nuevasLlamadasMarcar($listaLlamadas)
    {
    	$listaKeyRepetidos = array();
        foreach ($listaLlamadas as $k => $tupla) {
    		$llamada = $this->_listaLlamadas->buscar('dialstring', $tupla['dialstring']);
            if (!is_null($llamada)) {
            	// Llamada monitoreada repetida en dialstring
                // Repeated monitored call in dialstring
                $listaKeyRepetidos[] = $k;
            } else {
                // Llamada nueva, se procede normalmente
                // New call, proceed normally

                // Identificar tipo de llamada nueva
                // Identify new call type
                $tipo_llamada = 'outgoing';
                $cl =& $this->_campaniasSalientes;

                if (!isset($cl[$tupla['id_campaign']])) {
                    $this->_log->output('ERR: '.__METHOD__.": no se encuentra ".
                        "campaña [{$tupla['id_campaign']}] requerida por llamada: ".
                        print_r($tupla, 1).' | EN: campaign not found ');
                } else {
                    $llamada = $this->_listaLlamadas->nuevaLlamada($tipo_llamada);
                    $llamada->id_llamada = $tupla['id'];
                    $llamada->phone = $tupla['phone'];
                    $llamada->actionid = $tupla['actionid'];
                    $llamada->dialstring = $tupla['dialstring'];
                    $llamada->campania = $cl[$tupla['id_campaign']];

                    if (!is_null($tupla['agent'])) {
                    	$a = $this->_listaAgentes->buscar('agentchannel', $tupla['agent']);
                        if (is_null($a)) {
                        	$this->_log->output('ERR: '.__METHOD__.": no se ".
                                "encuentra agente para llamada agendada: {$tupla['agent']} | EN: agent not found for scheduled call: {$tupla['agent']}");
                        } elseif (!$a->reservado) {
                            $this->_log->output('ERR: '.__METHOD__.": agente no ".
                                "fue reservado para llamada agendada: {$tupla['agent']} | EN: agent was not reserved for scheduled call: {$tupla['agent']}");
                        } else {
                        	$a->llamada_agendada = $llamada;
                            $llamada->agente_agendado = $a;
                        }
                    }
                }
            }
    	}
        return $listaKeyRepetidos;
    }

    private function _abortarNuevasLlamadasMarcar($llamadasAbortar)
    {
        foreach ($llamadasAbortar as $sActionID) {
            $llamada = $this->_listaLlamadas->buscar('actionid', $sActionID);
            if (is_null($llamada)) {
                $this->_log->output('ERR: '.__METHOD__." no se encuentra llamada con ".
                    "actionid=$sActionID para abortar intento de marcado | EN: call not found with actionid=$sActionID to abort dial attempt");
                continue;
            }

            // No se espera que el status sea no-NULL para llamada abortable
            // Status is not expected to be non-NULL for abortable call
            if (!is_null($llamada->status)) {
                $this->_log->output('ERR: '.__METHOD__." llamada con ".
                    "actionid=$sActionID ya inició marcado, no es abortable | EN: call with actionid=$sActionID already started dialing, not abortable");
                continue;
            }

            // Desconectar posible agente agendado de llamada
            // Disconnect possible scheduled agent from call
            if (!is_null($llamada->agente_agendado)) {
                $a = $llamada->agente_agendado;
                $llamada->agente_agendado = NULL;
                $a->llamada_agendada = NULL;

                /* La llamada abortada todavía está pendiente, así que no se
                 * debe de quitar la reservación del agente. */
                /* The aborted call is still pending, so the agent reservation
                 * should not be removed. */
            }

            $this->_listaLlamadas->remover($llamada);
        }
    }

    private function _ejecutarOriginate($sFuente, $sActionID, $iTimeoutOriginate,
        $iTimestampInicioOriginate, $sContext, $sCID, $sCadenaVar, $retry,
        $trunk, $precall_events = array())
    {
        $llamada = $this->_listaLlamadas->buscar('actionid', $sActionID);
        if (is_null($llamada)) {
            $this->_log->output('ERR: '.__METHOD__." no se encuentra llamada con ".
                "actionid=$sActionID para iniciar Originate | EN: call not found with actionid=$sActionID to initiate Originate");
            $this->_tuberia->enviarRespuesta($sFuente, FALSE);
            return;
        }

        // Luego de llamar a este método, el status debería haber cambiado a Placing
        // After calling this method, status should have changed to Placing
        $r = $llamada->marcarLlamada($this->_ami, $sFuente, $iTimeoutOriginate,
            $iTimestampInicioOriginate, $sContext, $sCID, $sCadenaVar, $retry,
            $trunk, $precall_events);
        if (!$r) $this->_tuberia->enviarRespuesta($sFuente, FALSE);
    }

    private function _idnewcall($tipo_llamada, $uniqueid, $id_call)
    {
    	if ($tipo_llamada == 'incoming') {
    		$llamada = $this->_listaLlamadas->buscar('uniqueid', $uniqueid);
            if (is_null($llamada)) {
            	$this->_log->output('ERR: '.__METHOD__." no se encuentra llamada con tipo=$tipo_llamada id=$id_call | EN: call not found with type=$tipo_llamada id=$id_call");
            } else {
            	$llamada->id_llamada = $id_call;
            }
    	} else {
    		$this->_log->output('ERR: '.__METHOD__." no se ha implementado llamada con tipo=$tipo_llamada id=$id_call | EN: call type not implemented with type=$tipo_llamada id=$id_call");
    	}
    }

    private function _idcurrentcall($tipo_llamada, $id_call, $id_current_call)
    {
    	$llamada = NULL;
        if ($tipo_llamada == 'outgoing')
            $llamada = $this->_listaLlamadas->buscar('id_llamada_saliente', $id_call);
        elseif ($tipo_llamada == 'incoming')
            $llamada = $this->_listaLlamadas->buscar('id_llamada_entrante', $id_call);
        if (is_null($llamada)) {
            if ($this->_listaLlamadas->remover_llamada_sin_idcurrentcall($tipo_llamada, $id_call)) {
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__." la llamada se cerró antes de conocer su id_current_call. | EN: call closed before knowing its id_current_call.");
                }
                $this->_tuberia->msg_SQLWorkerProcess_sqldeletecurrentcalls(array(
                    'tipo_llamada'  =>  $tipo_llamada,
                    'id'            =>  $id_current_call,
                ));
            } else {
        	    $this->_log->output('ERR: '.__METHOD__." no se encuentra llamada con tipo=$tipo_llamada id=$id_call | EN: call not found with type=$tipo_llamada id=$id_call");
            }
        } else {
        	$llamada->id_current_call = (int)$id_current_call;
        }
    }

    private function _pingAgente($sAgente)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            if ($this->DEBUG) {
                $this->_log->output('DEBUG: '.__METHOD__.': ping recibido/received: '.$sAgente);
            }
            $a->resetTimeout();
        }
        return !is_null($a);
    }

    private function _agentesAgendables($listaAgendables)
    {
        $ociosos = array();
        foreach ($this->_listaAgentes as $a) if ($a->estado_consola == 'logged-in') {
            $sAgente = $a->channel;
            if (in_array($sAgente, $listaAgendables)) {
                // Agente sí está agendado
                // Agent is indeed scheduled
                $a->setReserved($this->_ami);

                /* Un agente ocioso para agendamiento debe estar reservado, sin
                 * llamada activa, sin llamada agendada, y sin ninguna otra pausa.
                 */
                /* An idle agent for scheduling must be reserved, without
                 * active call, without scheduled call, and without any other pause.
                 */
                if ($a->reservado &&
                    is_null($a->llamada) &&
                    is_null($a->llamada_agendada) &&
                    $a->num_pausas == 1)
                    $ociosos[] = $sAgente;
            }
        }
        return $ociosos;
    }

    private function _actualizarConfig($k, $v)
    {
    	switch ($k) {
    	case 'asterisk_cred':
            $this->_log->output('INFO: actualizando credenciales de Asterisk... | EN: updating Asterisk credentials...');
            $this->_config['asterisk']['asthost'] = $v[0];
            $this->_config['asterisk']['astuser'] = $v[1];
            $this->_config['asterisk']['astpass'] = $v[2];
            $this->_iniciarConexionAMI();
            break;
        /*
        case 'asterisk_duracion_sesion':
            $this->_log->output('INFO: actualizando duración de sesión a '.$v.' | EN: updating session duration to '.$v);
            $this->_config['asterisk']['duracion_sesion'] = $v;
            break;
        */
        case 'dialer_llamada_corta':
            $this->_log->output('INFO: actualizando intervalo de llamada corta a '.$v.' | EN: updating short call interval to '.$v);
            $this->_config['dialer']['llamada_corta'] = $v;
            break;
        case 'dialer_tiempo_contestar':
            $this->_log->output('INFO: actualizando intervalo inicial de contestar a '.$v.' | EN: updating initial answer interval to '.$v);
            $this->_config['dialer']['tiempo_contestar'] = $v;
            foreach ($this->_campaniasSalientes as $c) {
            	$c->tiempoContestarOmision($v);
            }
            break;
        case 'dialer_debug':
            $this->_log->output('INFO: actualizando DEBUG... | EN: updating DEBUG...');
            $this->_config['dialer']['debug'] = $v;
            $this->DEBUG = $this->_config['dialer']['debug'];
            $this->_queueshadow->DEBUG = $this->DEBUG;
            break;
        case 'dialer_allevents':
            $this->_config['dialer']['allevents'] = $v;
            if (!is_null($this->_ami)) {
            	$this->_ami->remove_event_handler('*');
                if ($v) $this->_ami->add_event_handler('*', array($this, 'msg_Default'));
            }
            break;
        case 'dialer_relatedevents':
            $this->_config['dialer']['relatedevents'] = $v;
            break;
        default:
            $this->_log->output('WARN: '.__METHOD__.': se ignora clave de config no implementada: '.$k.' | EN: ignoring unimplemented config key: '.$k);
            break;
    	}
    }

    private function _limpiarLlamadasViejasEspera()
    {
    	$iTimestamp = time();
        if ($iTimestamp - $this->_iTimestampVerificacionLlamadasViejas > 30) {
            $listaLlamadasViejas = array();
            $listaLlamadasSinFailureCause = array();

            foreach ($this->_listaLlamadas as $llamada) {
                // Remover llamadas viejas luego de 5 * 60 segundos de espera sin respuesta
                // Remove old calls after 5 * 60 seconds of waiting without response
                if (!is_null($llamada->timestamp_originatestart) &&
                    is_null($llamada->timestamp_originateend) &&
                    $iTimestamp - $llamada->timestamp_originatestart > 5 * 60) {
                    $listaLlamadasViejas[] = $llamada;
                }

                // Remover llamadas fallidas luego de 60 segundos sin razón de hangup
                // Remove failed calls after 60 seconds without hangup reason
                if (!is_null($llamada->timestamp_originateend) &&
                    $llamada->status == 'Failure' &&
                    $iTimestamp - $llamada->timestamp_originatestart > 60) {
                    $listaLlamadasSinFailureCause[] = $llamada;
                }
            }

            foreach ($listaLlamadasViejas as $llamada) {
            	$iEspera = $iTimestamp - $llamada->timestamp_originatestart;
                $this->_log->output('ERR: '.__METHOD__.": llamada {$llamada->actionid} ".
                    "espera respuesta desde hace $iEspera segundos, se elimina. | EN: call {$llamada->actionid} has been waiting for response for $iEspera seconds, removing.");
                $llamada->llamadaFueOriginada($iTimestamp, NULL, NULL, 'Failure');
            }

            // Remover llamadas fallidas luego de 60 segundos sin razón de hangup
            // Remove failed calls after 60 seconds without hangup reason
            foreach ($listaLlamadasSinFailureCause as $llamada) {
                $iEspera = $iTimestamp - $llamada->timestamp_originateend;
                $this->_log->output('ERR: '.__METHOD__.": llamada {$llamada->actionid} ".
                    "espera causa de fallo desde hace $iEspera segundos, se elimina. | EN: call {$llamada->actionid} has been waiting for failure cause for $iEspera seconds, removing.");
                $this->_listaLlamadas->remover($llamada);
            }

            $this->_iTimestampVerificacionLlamadasViejas = $iTimestamp;
        }
    }

    private function _limpiarAgentesTimeout()
    {
        foreach ($this->_listaAgentes as $a) {
            if ($a->estado_consola == 'logged-in' && is_null($a->llamada) &&
                $a->num_pausas <= 0 && $a->timeout_inactivo) {

                $this->_log->output('INFO: deslogoneando a '.$a->channel.' debido a inactividad... | EN: logging out '.$a->channel.' due to inactivity...');
                $a->resetTimeout();
                $a->forzarLogoffAgente($this->_ami, $this->_log);
            }
            if (!is_null($a->logging_inicio) && time() - $a->logging_inicio > 5 * 60) {
                $this->_log->output('ERR: proceso de login trabado para '.$a->channel.', se indica fallo... | EN: login process stuck for '.$a->channel.', indicating failure...');
                $a->respuestaLoginAgente('Failure', NULL, NULL);
            }
        }
    }

    private function _verificarFinalizacionLlamadas()
    {
        if (!$this->_finalizacionConfirmada) {
            if (!is_null($this->_ami)) {
                foreach ($this->_listaAgentes as $a) {
                	if ($a->estado_consola != 'logged-out') return;
                }
                if ($this->_listaLlamadas->numLlamadas() > 0) return;
            }
            $this->_tuberia->msg_SQLWorkerProcess_finalsql();
            $this->_tuberia->msg_HubProcess_finalizacionTerminada();
            $this->_finalizacionConfirmada = TRUE;
        }
    }

    /* Deshacerse de todas las llamadas monitoreadas bajo la premisa de que
     * Asterisk se ha caído anormalmente y ya no está siguiendo llamadas */
    /* Get rid of all monitored calls on the premise that
     * Asterisk has crashed abnormally and is no longer tracking calls */
    private function _abortarTodasLasLlamadas()
    {
    	/* Copiar todas las llamadas a una lista temporal. Esto es necesario
         * para poder modificar la lista principal. */
        /* Copy all calls to a temporary list. This is necessary
         * to be able to modify the main list. */
        $listaLlamadasRemover = array();
        foreach ($this->_listaLlamadas as $llamada)
            $listaLlamadasRemover[] = $llamada;

        $this->_log->output("WARN: abortando todas las llamadas activas... | EN: aborting all active calls...");
        foreach ($listaLlamadasRemover as $llamada) {
        	if (is_null($llamada->status)) {
                // Llamada no ha sido iniciada todavía
                // Call has not been initiated yet
        		$this->_listaLlamadas->remover($llamada);
                if (!is_null($llamada->agente_agendado)) {
                    $a = $llamada->agente_agendado;
                    $llamada->agente_agendado = NULL;
                    $a->llamada_agendada = NULL;

                    /* No puedo verificar estado de reserva de agente porque
                     * se requiere de la conexión a Asterisk.*/
                    /* Cannot verify agent reservation status because
                     * connection to Asterisk is required. */
                }
        	} else switch ($llamada->status) {
        	case 'Placing':
                $llamada->llamadaFueOriginada(time(), NULL, NULL, 'Failure');
                break;
            case 'Ringing':
            case 'OnQueue':
            case 'Success':
            case 'OnHold':
                $llamada->llamadaFinalizaSeguimiento(time(),
                        $this->_config['dialer']['llamada_corta']);
                break;
            default:
                $this->_log->output("WARN: estado extraño {$llamada->status} al abortar llamada | EN: strange status {$llamada->status} when aborting call");
                $llamada->llamadaFinalizaSeguimiento(time(),
                        $this->_config['dialer']['llamada_corta']);
                break;
        	}
        }
    }

    private function _llamadaSilenciada($sAgente, $channel, $timeout = NULL)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (is_null($a)) {
            $this->_log->output("ERR: ".__METHOD__." no se encuentra agente para grabación silenciada ".$sAgente." | EN: agent not found for silent recording ".$sAgente);
            return;
        }
        if (is_null($a->llamada)) {
            $this->_log->output("ERR: ".__METHOD__." agente  ".$sAgente." no tiene llamada | EN: agent ".$sAgente." has no call");
            return;
        }

        $r = $a->llamada->agregarCanalSilenciado($channel);
        if ($r && !is_null($timeout)) {
            $this->_agregarAlarma($timeout, array($this, '_quitarSilencio'), array($a->llamada));
        }
    }

    private function _llamadaSinSilencio($sAgente)
    {
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (is_null($a)) {
            $this->_log->output("ERR: ".__METHOD__." no se encuentra agente para grabación silenciada ".$sAgente." | EN: agent not found for silent recording ".$sAgente);
            return;
        }
        if (is_null($a->llamada)) {
            $this->_log->output("ERR: ".__METHOD__." agente  ".$sAgente." no tiene llamada | EN: agent ".$sAgente." has no call");
            return;
        }

        $a->llamada->borrarCanalesSilenciados();
    }

    /**
     * Finalize a call after a blind transfer. This releases the agent
     * since the caller has been redirected to another destination.
     */
    private function _finalizarTransferencia($sAgente)
    {
        // === TIMESTAMP TRACKER: Finalize Transferencia Entry ===
        $fEntryMicrotime = microtime(TRUE);
        $fEntryTime = date('Y-m-d H:i:s.', (int)$fEntryMicrotime) . sprintf('%03d', ($fEntryMicrotime - (int)$fEntryMicrotime) * 1000);
        $this->_log->output("TIMING: ".__METHOD__.": [ENTER] Agent=$sAgente, microtime=$fEntryMicrotime, time=$fEntryTime | ES: Iniciando finalización de transferencia");

        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (is_null($a)) {
            $this->_log->output("ERR: ".__METHOD__." no se encuentra agente ".$sAgente." | EN: agent not found ".$sAgente);
            return;
        }
        if (is_null($a->llamada)) {
            $this->_log->output("WARN: ".__METHOD__." agente ".$sAgente." no tiene llamada activa | EN: agent ".$sAgente." has no active call");
            return;
        }

        $this->_log->output("INFO: ".__METHOD__." finalizando seguimiento de llamada transferida para agente ".$sAgente." | EN: ending tracking of transferred call for agent ".$sAgente);

        // If call was on hold, clear hold state first so holdexit event is sent
        // before AgentUnlinked. This ensures frontend properly resets hold state.
        if ($a->llamada->status == 'OnHold') {
            $this->_log->output("DEBUG: ".__METHOD__." call was on hold - clearing hold state before finalization");
            $a->llamada->atxfer_hold = FALSE;
            $a->llamada->llamadaRegresaHold($this->_ami, microtime(TRUE));
        }

        // === TIMESTAMP TRACKER: Before Transfer Release ===
        $fBeforeFinalize = microtime(TRUE);
        $this->_log->output("TIMING: ".__METHOD__.": [BEFORE_TRANSFER_RELEASE] Agent=$sAgente, has_call=".(is_null($a->llamada)?'NO':'YES').", call_uniqueid=".(is_null($a->llamada)?'NULL':$a->llamada->uniqueid).", microtime=$fBeforeFinalize | ES: Antes de liberar agente para transferencia");

        // Release source agent but keep call alive in _listaLlamadas
        // so msg_Link() can re-link it to the target agent
        $a->llamada->llamadaTransferidaDesdeAgente(microtime(TRUE));

        // === TIMESTAMP TRACKER: After Transfer Release ===
        $fAfterFinalize = microtime(TRUE);
        $fElapsed = ($fAfterFinalize - $fBeforeFinalize) * 1000;
        $this->_log->output("TIMING: ".__METHOD__.": [AFTER_TRANSFER_RELEASE] Agent=$sAgente, elapsed_ms=".round($fElapsed,3).", agent_call_null=".(is_null($a->llamada)?'YES':'NO').", microtime=$fAfterFinalize | ES: Después de liberar agente para transferencia");

        // Defensive cleanup: when this is reached via the web-console
        // "Complete Transfer" path (Agent-type attended transfer completing
        // via a dual Redirect rather than a real hangup), the dialplan's own
        // end-of-consultation UserEvent(ConsultationEnd,...) never runs -
        // clear any leftover consultation-tracking state here so the map
        // doesn't leak and the front-end's Hold/Transfer buttons re-enable.
        // No-op for blind-transfer/transfer-to-agent callers, which never
        // set these maps.
        if (isset($this->_agentesEnConsultation[$sAgente])
                || isset($this->_agentesEnAtxferComplete[$sAgente])
                || isset($this->_agentesConsultaContestada[$sAgente])) {
            unset($this->_agentesEnConsultation[$sAgente]);
            unset($this->_agentesEnAtxferComplete[$sAgente]);
            unset($this->_agentesConsultaContestada[$sAgente]);
            $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
                array('ConsultationEnd', array($sAgente))
            ));
        }

        // Clear transfer reservation if source agent had one pending
        // Limpiar reserva de transferencia si el agente origen tenía una pendiente
        foreach ($this->_agentesEnTransferPendiente as $sTarget => $info) {
            if ($info['source_agent'] == $sAgente) {
                $this->_cancelarAlarma($info['alarm_key']);
                unset($this->_agentesEnTransferPendiente[$sTarget]);
                $this->_log->output('INFO: '.__METHOD__.": Transfer reservation auto-cleared for target=$sTarget (source=$sAgente finalized) | ES: Reserva de transferencia auto-limpiada para destino=$sTarget (origen=$sAgente finalizado)");
                break;
            }
        }
    }

    private function _quitarSilencio($llamada)
    {
        if (count($llamada->mutedchannels) > 0) {
            foreach ($llamada->mutedchannels as $chan) {
                $this->_ami->asyncMixMonitorMute(
                    array($this, '_cb_MixMonitorMute'),
                    NULL,
                    $chan, false);
            }
            $llamada->borrarCanalesSilenciados();
        }
    }

    public function _cb_MixMonitorMute($r)
    {
        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: No se puede cambiar mute de la grabacion: '.$r['Message'].' | EN: cannot change recording mute: '.$r['Message']);
        }
    }

    /**************************************************************************/

    public function rpc_informarCredencialesAsterisk($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }

        if (!is_null($this->_alarma_faltaconfig)) {
            $this->_cancelarAlarma($this->_alarma_faltaconfig);
            $this->_alarma_faltaconfig = NULL;
        }

        $por_pedido = $datos[1];
        if ($por_pedido) {
            if (is_null($this->_config)) {
                $this->_config = $datos[0];
                $this->_log->output('INFO: recibidas credenciales AMI pedidas expresamente... | EN: received explicitly requested AMI credentials...');
                $bExito = $this->_iniciarConexionAMI();
            } else {
                $this->_log->output('INFO: IGNORANDO credenciales AMI pedidas expresamente, ya se tiene AMI. | EN: IGNORING explicitly requested AMI credentials, AMI already available.');
                $bExito = TRUE;
            }
        } else {
            $this->_config = $datos[0];
            $this->_log->output('INFO: recibidas credenciales iniciales AMI... | EN: received initial AMI credentials...');
            $bExito = $this->_iniciarConexionAMI();
        }

        $this->DEBUG = $this->_config['dialer']['debug'];
        $this->_queueshadow->DEBUG = $this->DEBUG;

        // Informar a la fuente que se ha terminado de procesar
        // Inform the source that processing has finished
        $this->_tuberia->enviarRespuesta($sFuente, $bExito);
    }

    public function rpc_leerTiempoContestar($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
    	$this->_tuberia->enviarRespuesta($sFuente,
            isset($this->_campaniasSalientes[$datos[0]])
            ? $this->_campaniasSalientes[$datos[0]]->leerTiempoContestar()
            : NULL);
    }

    public function rpc_contarLlamadasEsperandoRespuesta($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
    	$queue = $datos[0];
        $iNumEspera = 0;

        foreach ($this->_listaLlamadas as $llamada) {
        	if (!is_null($llamada->campania) &&
                $llamada->campania->queue == $queue &&
                $llamada->esperando_contestar) {
                $iNumEspera++;
                if ($this->DEBUG) {
                	$iEspera = time() - $llamada->timestamp_originatestart;
                    $this->_log->output("DEBUG: ".__METHOD__.": llamada {$llamada->actionid} ".
                        "espera respuesta desde hace $iEspera segundos. | EN: call {$llamada->actionid} has been waiting for response for $iEspera seconds.");
                }
            }
        }
        if ($this->DEBUG && $iNumEspera > 0) {
        	$this->_log->output("DEBUG: ".__METHOD__.": en campaña en cola $queue todavía ".
                "quedan $iNumEspera llamadas pendientes de OriginateResponse. | EN: in campaign for queue $queue there are still $iNumEspera calls pending OriginateResponse.");
        }
        $this->_tuberia->enviarRespuesta($sFuente, $iNumEspera);
    }

    public function rpc_nuevasCampanias($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
    	if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_nuevasCampanias'), $datos));
    }

    public function rpc_nuevasLlamadasMarcar($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_nuevasLlamadasMarcar'), $datos));
    }

    public function rpc_ejecutarOriginate($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }

        /* Se omite aquí la llamada a enviarRespuesta a propósito. La función
         * _ejecutarOriginate va a iniciar una llamada AMI asíncrona, y el
         * callback de esa llamada va a invocar enviarRespuesta. */
        /* The call to enviarRespuesta is intentionally omitted here. The
         * _ejecutarOriginate function will initiate an asynchronous AMI call,
         * and the callback for that call will invoke enviarRespuesta. */
        array_unshift($datos, $sFuente);
        call_user_func_array(array($this, '_ejecutarOriginate'), $datos);
    }

    public function rpc_agregarIntentoLoginAgente($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_agregarIntentoLoginAgente'), $datos));
    }

    public function rpc_cancelarIntentoLoginAgente($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_cancelarIntentoLoginAgente'), $datos));
    }

    public function rpc_infoSeguimientoAgente($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_infoSeguimientoAgente'), $datos));
    }

    public function rpc_infoSeguimientoAgentesCola($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_infoSeguimientoAgentesCola'), $datos));
    }

    public function rpc_reportarInfoLlamadaAtendida($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_reportarInfoLlamadaAtendida'), $datos));
    }

    public function rpc_reportarInfoLlamadaAgendada($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_reportarInfoLlamadaAgendada'), $datos));
    }

    public function rpc_reportarInfoLlamadasCampania($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_reportarInfoLlamadasCampania'), $datos));
    }

    public function rpc_agentesAgendables($sFuente, $sDestino, $sNombreMensaje,
        $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_agentesAgendables'), $datos));
    }

    public function rpc_reportarInfoLlamadasColaEntrante($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_reportarInfoLlamadasColaEntrante'), $datos));
    }

    public function rpc_pingAgente($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_pingAgente'), $datos));
    }

    public function rpc_dumpstatus($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_dumpstatus'), $datos));
    }

    public function rpc_listarTotalColasTrabajoAgente($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_listarTotalColasTrabajoAgente'), $datos));
    }

    public function rpc_iniciarBreakAgente($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }

        list($sAgente, $idBreak, $idAuditBreak, $nombrePausa) = $datos;
        $r = array(0, '');
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (is_null($a)) {
            $r = array(404, 'Agent not found or not logged in through ECCP');
        } elseif ($a->estado_consola != 'logged-in') {
            $r = array(417, 'Agent currently not logged in');
        } elseif (!is_null($a->id_break)) {
            $r = array(417, 'Agent already in break');
        } else {
            $a->setBreak($this->_ami, $idBreak, $idAuditBreak, $nombrePausa);
        }
        $this->_tuberia->enviarRespuesta($sFuente, $r);
    }

    public function rpc_iniciarHoldAgente($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }

        list($sAgente, $idHold, $idAuditHold, $timestamp) = $datos;
        $r = NULL;
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (is_null($a)) {
            $r = array(404, 'Agent not found or not logged in through ECCP');
        } elseif ($a->estado_consola != 'logged-in') {
            $r = array(417, 'Agent currently not logged in');
        } elseif (!is_null($a->id_hold)) {
            $r = array(417, 'Agent already in hold');
        } elseif (is_null($a->llamada)) {
            $r = array(417, 'Agent not in call');
        }
        if (!is_null($r)) {
            $this->_tuberia->enviarRespuesta($sFuente, $r);
            return;
        }

        // Debug: Log hold state before initiating
        if (!is_null($a) && !is_null($a->llamada)) {
            $this->_log->output("DEBUG_HOLD: [" . date('Y-m-d H:i:s.') . substr(microtime(), 2, 3) .
                "] Hold initiating - Agent={$sAgente} call actualchannel=" . $a->llamada->actualchannel .
                " status=" . $a->llamada->status .
                " request_hold=" . ($a->llamada->request_hold ? 'TRUE' : 'FALSE') .
                " park_exten=" . (isset($a->llamada->park_exten) ? $a->llamada->park_exten : 'NULL'));
        }

        // If agent is Agent type and in atxfer state, set ATXFER_ON_HOLD on
        // the agent's SIP channel so dialplan keeps agent in Wait() instead of AgentLogin
        $bIsAgent = ($a->type == 'Agent');
        $bIsAtxfer = isset($this->_agentesEnAtxferComplete[$sAgente]);
        $sLoginChan = $a->login_channel;
        if ($bIsAgent && $bIsAtxfer && !empty($sLoginChan)) {
            $this->_ami->SetVar($sLoginChan, 'ATXFER_ON_HOLD', 'yes');
            $a->llamada->atxfer_hold = TRUE;
            $this->_log->output("DEBUG_HOLD: Set ATXFER_ON_HOLD=yes on {$sLoginChan} and atxfer_hold flag (agent in atxfer state)");
        }

        $a->setHold($this->_ami, $idHold, $idAuditHold);
        $a->llamada->mandarLlamadaHold($this->_ami, $sFuente, $timestamp);

        /* En el caso de éxito dentro de mandarLlamadaHold, NO se envía la
         * respuesta de vuelta a $sFuente, sino que se espera a que se reciba
         * la respuesta de éxito de la llamada Park. */
        /* In case of success within mandarLlamadaHold, NO response is sent
         * back to $sFuente, instead we wait to receive the success response
         * from the Park call. */
    }

    public function rpc_infoPrediccionCola($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }

        list($queue, $predictive) = $datos;

        $this->_tuberia->enviarRespuesta($sFuente, $this->_queueshadow->infoPrediccionCola($queue, $predictive));
    }

    /**************************************************************************/

    public function msg_nuevaListaAgentes($sFuente, $sDestino, $sNombreMensaje,
        $iTimestamp, $datos)
    {
        if (!is_null($this->_tmp_actionid_queuestatus)) {
            $this->_log->output('WARN: '.__METHOD__.': se ignora nueva lista '.
                'de agentes porque ya hay una verificación de pertenencia a '.
                'colas en progreso: '.$this->_tmp_actionid_queuestatus.' | EN: ignoring new agent list because queue membership verification is already in progress: ');
            return;
        }
        if (is_null($this->_ami)) {
            $this->_log->output('WARN: '.__METHOD__.': no se dispone de conexión Asterisk, se ignora petición... | EN: no Asterisk connection available, ignoring request...');
            return;
        }

        list($total_agents, $queueflags) = $datos;

        $this->_ami->asyncCommand(
            array($this, '_cb_Command_DatabaseShow'),
            array($total_agents, $queueflags),
            'database show QPENALTY');
    }

    public function _cb_Command_DatabaseShow($r, $total_agents, $queueflags)
    {
        if (!isset($r['data'])) {
            $this->_log->output('ERR: '.__METHOD__.': fallo al ejecutar database show QPENALTY : '.
                print_r($r, TRUE).' | EN: failed to execute database show QPENALTY : ');
            return;
        }

        // Se arma mapa de miembros tal como aparecen en database --> channel
        // Build map of members as they appear in database --> channel
        /* La letra de prefijo debe coincidir con la convencion propia de
         * issabelPBX en admin/modules/queues/agi-bin/queue_devstate.agi
         * ($member_prefix): A=AGENT, S=SIP, P=PJSIP, X=IAX2, Z=ZAP, D=DAHDI.
         * Tomar $type[0] es incorrecto para IAX2: da 'I' en vez de 'X', asi
         * que el agente nunca coincide con la clave que escribe issabelPBX. */
        /* The prefix letter must match issabelPBX's own convention in
         * admin/modules/queues/agi-bin/queue_devstate.agi ($member_prefix):
         * A=AGENT, S=SIP, P=PJSIP, X=IAX2, Z=ZAP, D=DAHDI.
         * Taking $type[0] is wrong for IAX2: it yields 'I' instead of 'X', so
         * the agent never matches the key issabelPBX actually writes. */
        $prefijoTecnologia = array(
            'AGENT' => 'A', 'SIP' => 'S', 'PJSIP' => 'P',
            'IAX2'  => 'X', 'ZAP' => 'Z', 'DAHDI' => 'D',
        );
        $arrExt = array();
        foreach ($total_agents as $tupla) {
            $sTipo = strtoupper($tupla['type']);
            $sPrefijo = isset($prefijoTecnologia[$sTipo])
                ? $prefijoTecnologia[$sTipo] : substr($tupla['type'], 0, 1);
            $extension = $sPrefijo . $tupla['number'];
            $arrExt[$extension] = $tupla['type'].'/'.$tupla['number'];
        }

        $db_output = $this->_ami->parse_database_data($r['data']);
        $dynmembers = array();
        foreach (array_keys($db_output) as $k) {
            $regs = NULL;
            if (preg_match('|^/QPENALTY/(\d+)/agents/(\S+)$|', $k, $regs)) {
                if (isset($arrExt[$regs[2]])) {
                    $dynmembers[$arrExt[$regs[2]]][$regs[1]] = (int)$db_output[$k];
                }
            }
        }

        $this->_nuevaListaAgentes($total_agents, $dynmembers, $queueflags);
    }

    private function _nuevaListaAgentes($total_agents, $dyn_agents, $queueflags)
    {
        foreach ($total_agents as $tupla) {
            // id type number name estatus
            $sAgente = $tupla['type'].'/'.$tupla['number'];

            $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
            if (is_null($a)) {
                // Agente nuevo por registrar
                // New agent to register
                $a = $this->_listaAgentes->nuevoAgente($tupla['id'],
                    $tupla['number'], $tupla['name'], ($tupla['estatus'] == 'A'),
                    $tupla['type']);
                if (!is_null($this->_compat)) $a->setCompat($this->_compat);
            } elseif ($a->id_agent != $tupla['id']) {
                // Agente ha cambiado de ID de base de datos, y está deslogoneado
                // Agent has changed database ID and is logged out
                if ($a->estado_consola == 'logged-out') {
                    $this->_log->output("INFO: agente deslogoneado $sAgente cambió de ID de base de datos | EN: logged-out agent $sAgente changed database ID");
                    $a->id_agent = $tupla['id'];
                    $a->number = $tupla['number'];
                    $a->name = $tupla['name'];
                    $a->estatus = ($tupla['estatus'] == 'A');
                } else {
                    $this->_log->output("INFO: agente $sAgente cambió de ID de base de datos pero está ".
                        $a->estado_consola.' | EN: agent '.$sAgente.' changed database ID but is '.$a->estado_consola);
                }
            }

            // Iniciar pertenencia de agentes dinámicos
            // Initialize dynamic agent membership
            $dyn = array();
            if (isset($dyn_agents[$sAgente]))
                $dyn = $dyn_agents[$sAgente];
            if ($a->asignarColasDinamicas($dyn)) $a->nuevaMembresiaCola();
        }

        if (!is_null($this->_ami)) {
            if ($this->DEBUG) $this->_log->output("DEBUG: iniciando verificación de pertenencia a colas con QueueStatus... | EN: starting queue membership verification with QueueStatus...");
            $this->_iniciarQueueStatus($queueflags);
        } else {
            $this->_log->output("INFO: conexión AMI no disponible, se retrasa consulta QueueStatus... | EN: AMI connection not available, delaying QueueStatus query...");
            $this->_pendiente_QueueStatus = $queueflags;
        }

    }

    private function _iniciarQueueStatus($queueflags)
    {
        // Iniciar actualización del estado de las colas activas
        // Start update of active queue status
        $this->_tmp_actionid_queuestatus = 'QueueStatus-'.posix_getpid().'-'.time();
        $this->_tmp_estadoAgenteCola = array();

        $versionMinima = array(12, 0, 0);
        while (count($versionMinima) < count($this->_asteriskVersion))
            array_push($versionMinima, 0);
        while (count($versionMinima) > count($this->_asteriskVersion))
            array_push($this->_asteriskVersion, 0);
        $bEventosCola = ($this->_asteriskVersion >= $versionMinima);

        // Asumir para Asterisk 12 o superior que siempre se tiene eventos de cola
        // Assume for Asterisk 12 or higher that queue events are always available
        if ($bEventosCola) {
            foreach (array_keys($queueflags) as $k) {
                $queueflags[$k]['eventmemberstatus'] = TRUE;
                $queueflags[$k]['eventwhencalled'] = TRUE;
            }
        }

        /* Antes esto era una llamada síncrona a QueueStatus: puede correr
         * dentro del wait_response() de otra llamada (vía
         * _cb_Command_DatabaseShow -> _nuevaListaAgentes), donde el guard de
         * reentrada la rechazaría. El ActionID es el mismo de siempre y
         * QueueStatus_start se sigue llamando a continuación.
         * This used to be a synchronous QueueStatus call: it can run inside
         * another call's wait_response() (via _cb_Command_DatabaseShow ->
         * _nuevaListaAgentes), where the reentrancy guard would refuse it.
         * The ActionID is the same one as always, and QueueStatus_start is
         * still called right after it. */
        $this->_ami->asyncQueueStatus(
            array($this, '_cb_QueueStatus'),
            array(),
            NULL, $this->_tmp_actionid_queuestatus);
        $this->_queueshadow->QueueStatus_start($queueflags);

        // En msg_QueueStatusComplete se valida pertenencia a colas dinámicas
        // Dynamic queue membership is validated in msg_QueueStatusComplete
    }

    public function _cb_QueueStatus($r)
    {
        if (!isset($r['Response']) || $r['Response'] != 'Success') {
            $this->_log->output('ERR: '.__METHOD__.' falló al consultar QueueStatus: '.
                (isset($r['Message']) ? $r['Message'] : print_r($r, TRUE)).
                ' | EN: failed to query QueueStatus');
        }
    }

    private function _iniciarAgents()
    {
        if (is_null($this->_tmp_actionid_agents)) {
            $this->_tmp_actionid_agents = 'Agents-'.posix_getpid().'-'.time();
            $this->_tmp_estadoLoginAgente = array();
            $this->_ami->Agents($this->_tmp_actionid_agents);
        }
    }

    public function msg_idNuevaSesionAgente($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_idNuevaSesionAgente'), $datos);
    }

    public function msg_quitarBreakAgente($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_quitarBreakAgente'), $datos);
    }

    public function msg_quitarReservaAgente($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_quitarReservaAgente'), $datos);
    }

    public function msg_idnewcall($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_idnewcall'), $datos);
    }

    public function msg_idcurrentcall($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_idcurrentcall'), $datos);
    }

    public function msg_actualizarConfig($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_actualizarConfig'), $datos);
    }

    public function msg_llamadaSilenciada($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_llamadaSilenciada'), $datos);
    }

    public function msg_llamadaSinSilencio($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_llamadaSinSilencio'), $datos);
    }

    public function msg_finalizarTransferencia($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_finalizarTransferencia'), $datos);
    }

    /**
     * Set flag to suppress Agentlogoff during attended transfer completion.
     * When agent's channel is redirected to re-enter AgentLogin, Asterisk sends
     * Agentlogoff followed by Agentlogin. We need to ignore the Agentlogoff
     * to prevent the ECCP session from being closed.
     */
    public function rpc_prepararAtxferComplete($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        $this->_tuberia->enviarRespuesta($sFuente, call_user_func_array(
            array($this, '_prepararAtxferComplete'), $datos));
    }

    private function _prepararAtxferComplete($sAgente)
    {
        $this->_log->output('DEBUG: '.__METHOD__.' - Setting atxfer completion flag for '.$sAgente);
        $this->_agentesEnAtxferComplete[$sAgente] = time();
        return TRUE;
    }

    public function rpc_esAgenteEnAtxferComplete($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        list($sAgente) = $datos;
        $this->_tuberia->enviarRespuesta($sFuente,
            isset($this->_agentesEnAtxferComplete[$sAgente]));
    }

    public function rpc_esAgenteEnConsultation($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        list($sAgente) = $datos;
        $this->_tuberia->enviarRespuesta($sFuente,
            isset($this->_agentesEnConsultation[$sAgente]));
    }

    // Returns array('channel' => ..., 'timestamp' => ...) if the colleague
    // in this agent's attended-transfer consultation has answered, NULL otherwise.
    public function rpc_infoConsultaContestada($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        list($sAgente) = $datos;
        $this->_tuberia->enviarRespuesta($sFuente,
            isset($this->_agentesConsultaContestada[$sAgente])
                ? $this->_agentesConsultaContestada[$sAgente] : NULL);
    }

    public function msg_marcarConsultationIniciada($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        call_user_func_array(array($this, '_marcarConsultationIniciada'), $datos);
    }

    private function _marcarConsultationIniciada($sAgente, $fSolicitud = NULL)
    {
        /* Este mensaje es asíncrono y se envía justo antes del Redirect que
         * arranca el Dial() al colega. Si el colega está ocupado o rechaza al
         * instante, el UserEvent ConsultationEnd puede haberse procesado ya
         * cuando este mensaje llega. Poner la bandera ahora resucitaría una
         * consulta que ya terminó y dejaría el botón de la consola atascado
         * en "Cancelar transferencia" hasta que acabe la llamada. */
        /* EN: This message is async and is sent just before the Redirect that
         * starts the Dial() to the colleague. If the colleague is busy or
         * declines instantly, the ConsultationEnd UserEvent may already have
         * been processed by the time this arrives. Setting the flag now would
         * resurrect a consultation that already ended and wedge the console's
         * button on "Cancel transfer" until the call finishes. */
        if (!is_null($fSolicitud) && isset($this->_consultaTerminadaEn[$sAgente])
                && $this->_consultaTerminadaEn[$sAgente] > $fSolicitud) {
            $this->_log->output('INFO: '.__METHOD__.' - ignoring late consultation mark for '.
                $sAgente.': its ConsultationEnd was already processed'.
                ' (end='.$this->_consultaTerminadaEn[$sAgente].' request='.$fSolicitud.')'.
                ' | ES: se ignora marca tardía de consulta para '.$sAgente.
                ': su ConsultationEnd ya fue procesado');
            /* La consola ya deshabilitó Hold/Transferir al recibir la respuesta
             * de atxfercall, que se envía antes de saber si la consulta llegó a
             * existir. Como aquí no se va a emitir ConsultationStart, se
             * reemite ConsultationEnd con el motivo guardado: el
             * ConsultationEnd original se emitió mientras el navegador todavía
             * estaba esperando esa respuesta, así que suele perderse, y la
             * resincronización por estado no puede entregarlo porque cliente y
             * servidor coinciden en 'none'. Reemitir es inocuo si el primero
             * sí llegó: el aviso es un único banner que se reescribe. */
            /* EN: The console already disabled Hold/Transfer when it got the
             * atxfercall reply, which is sent before it is known whether the
             * consultation ever existed. Since ConsultationStart will not be
             * emitted here, re-emit ConsultationEnd with the stored reason: the
             * original one was emitted while the browser was still waiting for
             * that reply, so it is usually lost, and the state resync cannot
             * deliver it because client and server agree on 'none'. Re-emitting
             * is harmless if the first one did arrive: the notice is a single
             * banner whose text is simply rewritten. */
            $sDialStatus = isset($this->_ultimaConsultaFallida[$sAgente])
                ? $this->_ultimaConsultaFallida[$sAgente] : '';
            $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
                array('ConsultationEnd', array($sAgente, $sDialStatus))
            ));
            return;
        }
        unset($this->_consultaTerminadaEn[$sAgente]);

        $this->_log->output('DEBUG: '.__METHOD__.' - Marking consultation started for '.$sAgente);
        // A new attempt starts: forget why the previous one failed, so a stale
        // reason can never be reported against this consultation.
        unset($this->_ultimaConsultaFallida[$sAgente]);
        $this->_agentesEnConsultation[$sAgente] = time();
        // Emit event to front-end to disable Hold/Transfer buttons
        $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
            array('ConsultationStart', array($sAgente))
        ));
    }

    /**
     * Atomically validate target agent availability AND acquire transfer reservation.
     * This runs in AMIEventProcess (single process), so check-and-set is inherently atomic.
     *
     * Validar atómicamente la disponibilidad del agente destino Y adquirir reserva de transferencia.
     * Esto corre en AMIEventProcess (proceso único), así que verificar-y-establecer es inherentemente atómico.
     */
    private function _reservarAgenteParaTransferencia($sSourceAgent, $sTargetAgent)
    {
        $this->_log->output('INFO: '.__METHOD__.": Validating transfer reservation: source=$sSourceAgent, target=$sTargetAgent | ES: Validando reserva de transferencia: origen=$sSourceAgent, destino=$sTargetAgent");

        // 1. Find target agent
        $a = $this->_listaAgentes->buscar('agentchannel', $sTargetAgent);
        if (is_null($a)) {
            $this->_log->output('ERR: '.__METHOD__.": Target agent not found: $sTargetAgent | ES: Agente destino no encontrado: $sTargetAgent");
            return array('success' => false, 'error_code' => 404,
                'error_msg' => 'Target agent not found | Agente destino no encontrado',
                'status' => 'not_found');
        }

        // 2. Check if another transfer is already pending to this agent (RACE CONDITION PREVENTION)
        if (isset($this->_agentesEnTransferPendiente[$sTargetAgent])) {
            $pendingInfo = $this->_agentesEnTransferPendiente[$sTargetAgent];
            $this->_log->output('WARN: '.__METHOD__.": Transfer to $sTargetAgent already in progress from {$pendingInfo['source_agent']} (since ".date('H:i:s', $pendingInfo['timestamp']).") | ES: Transferencia a $sTargetAgent ya en progreso desde {$pendingInfo['source_agent']}");
            return array('success' => false, 'error_code' => 409,
                'error_msg' => 'Transfer to target agent already in progress | Transferencia al agente destino ya en progreso',
                'status' => 'transfer_pending');
        }

        // 3. Check agent is logged in
        if ($a->estado_consola != 'logged-in') {
            $this->_log->output('ERR: '.__METHOD__.": Target agent not logged in: $sTargetAgent (estado_consola={$a->estado_consola}) | ES: Agente destino no conectado: $sTargetAgent");
            return array('success' => false, 'error_code' => 417,
                'error_msg' => 'Target agent is not logged in | Agente destino no está conectado',
                'status' => 'offline');
        }

        // 4. Check agent is not on a call (dialer internal state)
        if (!is_null($a->llamada)) {
            $this->_log->output('ERR: '.__METHOD__.": Target agent is on call: $sTargetAgent | ES: Agente destino está en llamada: $sTargetAgent");
            return array('success' => false, 'error_code' => 417,
                'error_msg' => 'Target agent is busy | Agente destino está ocupado',
                'status' => 'oncall');
        }

        // 5. Check agent is not paused (break or hold)
        if ($a->num_pausas > 0) {
            $this->_log->output('ERR: '.__METHOD__.": Target agent is paused: $sTargetAgent (num_pausas={$a->num_pausas}) | ES: Agente destino está en pausa: $sTargetAgent");
            return array('success' => false, 'error_code' => 417,
                'error_msg' => 'Target agent is on pause | Agente destino está en pausa',
                'status' => 'paused');
        }

        // 6. Check Asterisk device state via cached queue_status
        $max_queue_status = AST_DEVICE_UNKNOWN;
        foreach ($a->colas_actuales as $queue) {
            $status = $a->estadoEnCola($queue);
            if ($status > $max_queue_status) $max_queue_status = $status;
        }
        $busyDeviceStates = array(AST_DEVICE_INUSE, AST_DEVICE_BUSY, AST_DEVICE_RINGING, AST_DEVICE_RINGINUSE, AST_DEVICE_ONHOLD);
        if (in_array($max_queue_status, $busyDeviceStates)) {
            $statusNames = array(
                AST_DEVICE_INUSE => 'INUSE', AST_DEVICE_BUSY => 'BUSY',
                AST_DEVICE_RINGING => 'RINGING', AST_DEVICE_RINGINUSE => 'RINGINUSE',
                AST_DEVICE_ONHOLD => 'ONHOLD'
            );
            $sStatusName = isset($statusNames[$max_queue_status]) ? $statusNames[$max_queue_status] : 'UNKNOWN_'.$max_queue_status;
            $this->_log->output('ERR: '.__METHOD__.": Target agent device is busy: $sTargetAgent (queue_status=$max_queue_status/$sStatusName) | ES: Dispositivo del agente destino ocupado: $sTargetAgent (estado_cola=$max_queue_status/$sStatusName)");
            return array('success' => false, 'error_code' => 417,
                'error_msg' => "Target agent device is busy | Es: Dispositivo del agente destino ocupado ",
                'status' => 'device_busy', 'queue_status' => $max_queue_status);
        }

        // 7. ALL CHECKS PASSED - Atomically set reservation with 30s timeout
        $sAlarmKey = $this->_agregarAlarma(30, array($this, '_timeoutReservaTransferencia'), array($sTargetAgent));
        $this->_agentesEnTransferPendiente[$sTargetAgent] = array(
            'timestamp'    => time(),
            'source_agent' => $sSourceAgent,
            'alarm_key'    => $sAlarmKey,
        );

        // Set transfer_pending flag on the source agent's call so msg_Hangup
        // uses llamadaTransferidaDesdeAgente() instead of full finalization.
        // This RPC is synchronous, so the flag is set BEFORE the Redirect
        // triggers any Hangup events from Asterisk.
        $aSource = $this->_listaAgentes->buscar('agentchannel', $sSourceAgent);
        if (!is_null($aSource) && !is_null($aSource->llamada)) {
            $aSource->llamada->transfer_pending = TRUE;
            $this->_log->output('INFO: '.__METHOD__.": transfer_pending flag SET on call uid=".
                $aSource->llamada->uniqueid." for source=$sSourceAgent".
                " | ES: bandera transfer_pending activada en llamada uid=".
                $aSource->llamada->uniqueid." para origen=$sSourceAgent");
        }

        $this->_log->output('INFO: '.__METHOD__.": Transfer reservation ACQUIRED for target=$sTargetAgent by source=$sSourceAgent (alarm=$sAlarmKey, queue_status=$max_queue_status) | ES: Reserva de transferencia ADQUIRIDA para destino=$sTargetAgent por origen=$sSourceAgent");

        return array('success' => true, 'status' => 'reserved');
    }

    /**
     * Safety-net timeout: clear stale transfer reservation after 30 seconds.
     * Tiempo límite de seguridad: limpiar reserva de transferencia obsoleta después de 30 segundos.
     */
    private function _timeoutReservaTransferencia($sTargetAgent)
    {
        if (isset($this->_agentesEnTransferPendiente[$sTargetAgent])) {
            $info = $this->_agentesEnTransferPendiente[$sTargetAgent];
            $this->_log->output('WARN: '.__METHOD__.": Transfer reservation EXPIRED for target=$sTargetAgent (source={$info['source_agent']}, age=".(time() - $info['timestamp'])."s) | ES: Reserva de transferencia EXPIRADA para destino=$sTargetAgent (origen={$info['source_agent']})");
            unset($this->_agentesEnTransferPendiente[$sTargetAgent]);
        }
    }

    /**
     * Explicitly release a transfer reservation (called when Redirect fails or ExtensionState rejects).
     * Liberar explícitamente una reserva de transferencia (llamado cuando Redirect falla o ExtensionState rechaza).
     */
    private function _liberarReservaTransferencia($sTargetAgent)
    {
        if (isset($this->_agentesEnTransferPendiente[$sTargetAgent])) {
            $info = $this->_agentesEnTransferPendiente[$sTargetAgent];
            $this->_cancelarAlarma($info['alarm_key']);
            unset($this->_agentesEnTransferPendiente[$sTargetAgent]);
            $this->_log->output('INFO: '.__METHOD__.": Transfer reservation RELEASED for target=$sTargetAgent (source={$info['source_agent']}) | ES: Reserva de transferencia LIBERADA para destino=$sTargetAgent (origen={$info['source_agent']})");
        } else {
            $this->_log->output('DEBUG: '.__METHOD__.": No transfer reservation found for target=$sTargetAgent (already cleared) | ES: No se encontró reserva de transferencia para destino=$sTargetAgent (ya limpiada)");
        }
    }

    /**
     * RPC wrapper for transfer reservation.
     * Validates all state atomically and acquires lock.
     */
    public function rpc_reservarAgenteParaTransferencia($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        list($sSourceAgent, $sTargetAgent) = $datos;
        $this->_tuberia->enviarRespuesta($sFuente,
            $this->_reservarAgenteParaTransferencia($sSourceAgent, $sTargetAgent));
    }

    /**
     * Message handler wrapper for releasing transfer reservation.
     * Called when transfer fails or needs to be aborted.
     */
    public function msg_liberarReservaTransferencia($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        list($sTargetAgent) = $datos;
        $this->_liberarReservaTransferencia($sTargetAgent);
    }

    public function msg_UserEvent($sEvent, $params, $sServer, $iPort)
    {
        if (!isset($params['UserEvent'])) return FALSE;

        if ($params['UserEvent'] == 'ConsultationAnswered' && isset($params['Agent'])) {
            $sAgente = trim($params['Agent']);
            $sColleagueChannel = isset($params['Channel']) ? trim($params['Channel']) : NULL;
            $this->_log->output('DEBUG: '.__METHOD__.": ConsultationAnswered UserEvent received for agent=$sAgente colleague_channel=$sColleagueChannel");
            $this->_agentesConsultaContestada[$sAgente] = array(
                'channel'   => $sColleagueChannel,
                'timestamp' => time(),
            );
            // Let the front-end know it can now offer to complete the transfer
            $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
                array('ConsultationAnswered', array($sAgente))
            ));
        }

        if ($params['UserEvent'] == 'ConsultationEnd' && isset($params['Agent'])) {
            $sAgente = trim($params['Agent']);
            $this->_log->output("DEBUG_HOLD: [" . date('Y-m-d H:i:s.') . substr(microtime(), 2, 3) .
                "] ConsultationEnd UserEvent received for agent=$sAgente" .
                " was_in_consultation=" . (isset($this->_agentesEnConsultation[$sAgente]) ? 'YES' : 'NO') .
                " was_in_atxfercomplete=" . (isset($this->_agentesEnAtxferComplete[$sAgente]) ? 'YES' : 'NO'));
            /* Este UserEvent sólo lo emiten los contextos de consulta de este
             * módulo, así que siempre corresponde a una consulta que este
             * proceso inició: se actúa incondicionalmente. La bandera
             * _agentesEnConsultation la pone un mensaje asíncrono
             * (marcarConsultationIniciada) enviado justo antes del Redirect
             * AMI, de modo que si el colega está ocupado o rechaza al
             * instante este evento puede llegar ANTES que ese mensaje. Si se
             * condicionara la limpieza a que la bandera ya estuviera puesta,
             * en esa carrera se perderían el motivo y el evento, y la bandera
             * que llega después no la limpiaría nadie: el botón "Cancelar
             * transferencia" se quedaría atascado hasta recargar la página.
             * La marca de tiempo de abajo cierra la otra mitad de la carrera
             * haciendo que el mensaje tardío se descarte. */
            /* EN: This UserEvent is only emitted by this module's own
             * consultation contexts, so it always refers to a consultation
             * this process started - act unconditionally. The
             * _agentesEnConsultation flag is set by an async message
             * (marcarConsultationIniciada) sent just before the AMI Redirect,
             * so when the colleague is busy or declines instantly this event
             * can arrive BEFORE that message. Gating the cleanup on the flag
             * already being set would lose both the reason and the event in
             * that race, and nothing would ever clear the flag that lands
             * afterwards: the console's "Cancel transfer" cue would stick
             * until the page is reloaded. The timestamp below closes the
             * other half of the race by making the late message a no-op. */
            $this->_consultaTerminadaEn[$sAgente] = microtime(TRUE);

            unset($this->_agentesEnConsultation[$sAgente]);
            unset($this->_agentesConsultaContestada[$sAgente]);
            // The consultation ended on its own (colleague busy/no-answer/
            // declined/hung-up-first) rather than via an explicit agent
            // completion - clear any transfer stamped at consult-start so
            // the agent's next (now perfectly normal) hangup isn't
            // misdetected as "transfer completed".
            $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
            if (!is_null($a)) $this->_limpiarTransferPendiente($a->llamada);
            // DIALSTATUS from the consult Dial(), if this UserEvent came
            // from the natural end of [atxfer-consult]/[cbxfer-consult]
            // (busy/no-answer/declined/colleague-hung-up-first) - lets the
            // console show the agent why the consultation ended. Empty for
            // any other path that fires ConsultationEnd (explicit cancel,
            // customer hangup, agent channel hangup, transfer completion),
            // none of which set this UserEvent header.
            $sDialStatus = isset($params['Status']) ? trim($params['Status']) : '';
            /* Recordar por qué falló la consulta. El evento que se emite
             * abajo sólo llega a los clientes ECCP conectados en ese
             * instante, y justo aquí suele haber una ráfaga de eventos que
             * hace que el long-poll de la consola se esté reconectando, así
             * que con frecuencia se pierde (el caso típico: colega
             * ocupado). Guardando el motivo, la resincronización por
             * estado de _infoSeguimientoAgente() puede entregarlo igual y
             * el aviso aparece siempre. */
            /* EN: Remember why the consultation failed. The event emitted
             * below only reaches ECCP clients connected at that instant,
             * and this moment usually carries a burst of events that leaves
             * the console long poll reconnecting, so it is frequently lost
             * (typical case: busy colleague). Storing the reason lets the
             * state resync in _infoSeguimientoAgente() deliver it anyway,
             * so the notice always appears. */
            if (in_array($sDialStatus, array('BUSY', 'NOANSWER', 'CONGESTION', 'CHANUNAVAIL'))) {
                $this->_ultimaConsultaFallida[$sAgente] = $sDialStatus;
            }
            $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
                array('ConsultationEnd', array($sAgente, $sDialStatus))
            ));
        }
        return FALSE;
    }

    /**
     * Clear a call's stale `transfer` DB column after an attended-transfer
     * consultation ends without an explicit completion (colleague busy/
     * no-answer/declined/hung-up-first). Without this, `transfer` stays
     * stamped with the colleague's extension from the moment the
     * consultation started, causing the agent's next (unrelated) hangup to
     * be misdetected as "transfer completed".
     */
    private function _limpiarTransferPendiente($llamada)
    {
        if (is_null($llamada) || is_null($llamada->id_llamada)) return;
        $this->_tuberia->msg_SQLWorkerProcess_sqlupdatecalls(array(
            'tipo_llamada' => $llamada->tipo_llamada,
            'id'           => $llamada->id_llamada,
            'transfer'     => '',
        ));
    }

    public function msg_abortarNuevasLlamadasMarcar($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' recibido/received: '.print_r($datos, 1));
        }
        call_user_func_array(array($this, '_abortarNuevasLlamadasMarcar'), $datos);
    }

    public function msg_finalizando($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        $this->_log->output('INFO: recibido mensaje de finalización, se desloguean agentes... | EN: received shutdown message, logging out agents...');
        $this->_finalizandoPrograma = TRUE;
        foreach ($this->_listaAgentes as $a) {
        	if ($a->estado_consola != 'logged-out') {
                if (!is_null($this->_ami)) {
                	if ($a->type == 'Agent') {
                        if (!is_null($this->_compat) && $this->_compat->hasChanAgent()) {
                            // chan_agent (Asterisk 11): use Agentlogoff
                            $this->_ami->Agentlogoff($a->number);
                        } else {
                            // app_agent_pool (Asterisk 12+): Hangup the AgentLogin channel
                            if (!is_null($a->login_channel)) {
                                $this->_ami->Hangup($a->login_channel);
                            }
                        }
                	} else {
                	    foreach ($a->colas_actuales as $q) $this->_ami->QueueRemove($q, $a->channel);
                    }
                }
            }
        }
        $this->_log->output('INFO: esperando a que finalicen todas las llamadas monitoreadas... | EN: waiting for all monitored calls to finish...');
        $this->_verificarFinalizacionLlamadas();
    }

    /**************************************************************************/

    public function msg_VarSet($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG && $this->_config['dialer']['relatedevents']) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }

        switch ($params['Variable']) {
        case 'MIXMONITOR_FILENAME':
            /*
Event: VarSet
Privilege: dialplan,all
Channel: SIP/5547741200-000193aa
Variable: MIXMONITOR_FILENAME
Value: /var/spool/asterisk/monitor/2015/04/21/out-5528733168-5528733168-20150421-134747-1429642067.241009.wav
Uniqueid: 1429642067.241008
             */
            $llamada = NULL;
            if (is_null($llamada)) foreach (array('channel', 'actualchannel') as $idx) {
                $llamada = $this->_listaLlamadas->buscar($idx, $params['Channel']);
                if (!is_null($llamada)) break;
            }
            if (is_null($llamada)) foreach (array('uniqueid', 'auxchannel') as $idx) {
                $llamada = $this->_listaLlamadas->buscar($idx, $params['Uniqueid']);
                if (!is_null($llamada)) break;
            }
            if (!is_null($llamada)) {
                $llamada->agregarArchivoGrabacion($params['Uniqueid'], $params['Channel'], $params['Value']);
                break;
            }
            break;
        default:
            return 'AMI_EVENT_DISCARD';
        }
    }

    public function msg_Default($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }
        return 'AMI_EVENT_DISCARD';
    }

    public function msg_Newchannel($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }
        $regs = NULL;
        if (isset($params['Channel']) &&
            preg_match('#^(Local/.+@[[:alnum:]-]+)-[\dabcdef]+(,|;)(1|2)$#', $params['Channel'], $regs)) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": se ha creado pata {$regs[3]} de llamada {$regs[1]} | EN: created leg {$regs[3]} of call {$regs[1]}");
            }
            $llamada = $this->_listaLlamadas->buscar('dialstring', $regs[1]);
            if (!is_null($llamada)) {
                if ($regs[3] == '1') {
                    // Pata 1, se requiere para los eventos Link/Join
                    $llamada->uniqueid = $params['Uniqueid'];
                    if ($this->DEBUG) {
                        $this->_log->output("DEBUG: ".__METHOD__.": Llamada localizada, Uniqueid={$params['Uniqueid']} | EN: Call located, Uniqueid={$params['Uniqueid']}");
                    }
                } elseif ($regs[3] == '2') {
                    /* Pata 2, se requiere para recuperar razón de llamada
                     * fallida, en caso de que se desconozca vía pata 1. Además
                     * permite reconocer canal físico real al recibir Link sobre
                     * pata auxiliar. */
                    $llamada->AuxChannels[$params['Uniqueid']] = array();
                    $llamada->registerAuxChannels();
                    if ($this->DEBUG) {
                        $this->_log->output("DEBUG: ".__METHOD__.": Llamada localizada canal auxiliar Uniqueid={$params['Uniqueid']} | EN: Call located auxiliary channel Uniqueid={$params['Uniqueid']}");
                    }
                }
            }
        }

        return FALSE;
    }

    public function msg_Dial($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }

        if (!isset($params['Channel'])) {
            return FALSE;
        }

        if (isset($params['SubEvent']) && $params['SubEvent'] == 'End') {
            return FALSE;
        }

        $srcUniqueId = $destUniqueID = NULL;
        if (isset($params['SrcUniqueID'])) {
            $srcUniqueId = $params['SrcUniqueID'];
        } elseif (isset($params['UniqueID'])) {
            $srcUniqueId = $params['UniqueID'];
        } elseif (isset($params['Uniqueid'])) {
            $srcUniqueId = $params['Uniqueid'];
        }

        if (isset($params['DestUniqueID'])) {
            $destUniqueID = $params['DestUniqueID'];
        } elseif (isset($params['DestUniqueid'])) {
            $destUniqueID = $params['DestUniqueid'];
        }

        if(!isset($params['Destination'])) {
            // Asterisk 13
            if(isset($params['DestChannel'])) {
                $params['Destination']=$params['DestChannel'];
            }
        }

        if (!is_null($srcUniqueId) && !is_null($destUniqueID)) {
            /* Si el SrcUniqueID es alguno de los Uniqueid monitoreados, se añade el
             * DestUniqueID correspondiente. Potencialmente esto permite también
             * trazar la troncal por la cual salió la llamada.
             */
            $llamada = $this->_listaLlamadas->buscar('uniqueid', $srcUniqueId);
            if (is_null($llamada))
                $llamada = $this->_listaLlamadas->buscar('auxchannel', $srcUniqueId);
            if (!is_null($llamada)) {
            	$llamada->AuxChannels[$destUniqueID]['Dial'] = $params;
                $llamada->registerAuxChannels();
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__.": encontrado canal auxiliar para llamada: {$llamada->actionid} | EN: found auxiliary channel for call: {$llamada->actionid}");
                }

                if (strpos($params['Destination'], 'Local/') !== 0) {
                    if (is_null($llamada->actualchannel)) {
                        // Primer Dial observado, se asigna directamente
                        $this->_asignarCanalRemotoReal($params, $llamada);
                    } elseif ($llamada->actualchannel != $params['Destination']) {

                        /* Es posible que el plan de marcado haya colgado por congestión
                         * al canal en $llamada->actualchannel y este Dial sea el
                         * siguiente intento usando una troncal distinta en la ruta
                         * saliente. Se verifica si el canal auxiliar ya tiene un
                         * Hangup registrado. */
                        $bCanalPrevioColgado = FALSE;
                        foreach ($llamada->AuxChannels as $uid => &$auxevents) {
                        	if (isset($auxevents['Dial']) &&
                                $auxevents['Dial']['Destination'] == $llamada->actualchannel &&
                                isset($auxevents['Hangup'])) {
                                $bCanalPrevioColgado = TRUE;
                                break;
                            }
                        }

                        if ($bCanalPrevioColgado) {
                        	if ($this->DEBUG) {
                        		$this->_log->output("DEBUG: ".__METHOD__.": canal ".
                                    "auxiliar previo para llamada {$llamada->actionid} ".
                                    "ha colgado, se renueva. | EN: previous auxiliary channel for call {$llamada->actionid} has hung up, renewing.");
                        	}
                            $this->_asignarCanalRemotoReal($params, $llamada);
                        } else {
                            $regs = NULL;
                            $sCanalPosibleAgente = NULL;
                            if (preg_match('|^(\w+/\w+)(\-\w+)?$|', $params['Destination'], $regs)) {
                            	$sCanalPosibleAgente = $regs[1];
                                $a = $this->_listaAgentes->buscar('agentchannel', $sCanalPosibleAgente);
                                if (!is_null($a) && $a->estado_consola == 'logged-in') {
                                	if ($this->DEBUG) {
                                		$this->_log->output('DEBUG: '.__METHOD__.': canal remoto es agente, se ignora. | EN: remote channel is agent, ignoring.');
                                	}
                                } else {
                                    $sCanalPosibleAgente = NULL;
                                }
                            }
                            if (is_null($sCanalPosibleAgente)) {
                                $this->_log->output('WARN: '.__METHOD__.': canal remoto en '.
                                    'conflicto, anterior '.$llamada->actualchannel.' nuevo '.
                                    $params['Destination'].' | EN: remote channel conflict, previous '.$llamada->actualchannel.' new '.$params['Destination']);
                            }
                        }
                    }
                }
            }
        }

        return FALSE;
    }

    private function _asignarCanalRemotoReal(&$params, $llamada)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                ': capturado canal remoto real/captured real remote channel: '.$params['Destination']);
        }
        $llamada->llamadaIniciaDial($params['local_timestamp_received'], $params['Destination']);
    }

    public function msg_OriginateResponse($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: => ".print_r($params, TRUE)
                );
        }

        // Todas las llamadas del dialer contienen un ActionID
        if (!isset($params['ActionID'])) return FALSE;

        // Verificar si esta es una llamada especial de ECCP
        if ($this->_manejarLlamadaEspecialECCP($params)) return FALSE;

        $llamada = $this->_listaLlamadas->buscar('actionid', $params['ActionID']);
        if (is_null($llamada)) return FALSE;

        $llamada->llamadaFueOriginada($params['local_timestamp_received'],
            $params['Uniqueid'], $params['Channel'], $params['Response']);

        $calleridnum = NULL;
        if (isset($params['CallerIDNum'])) {
            $calleridnum = in_array(trim($params['CallerIDNum']), array('', '<null>', '(null)'))
                ? '' : trim($params['CallerIDNum']);
        }

        // Si el estado de la llamada es Failure, el canal probablemente ya no
        // existe. Sólo intervenir si CallerIDNum no está seteado.
        // If the call status is Failure, the channel probably no longer
        // exists. Only intervene if CallerIDNum is not set.
        if ($params['Response'] != 'Failure' && empty($calleridnum)) {
            // Si la fuente de la llamada está en blanco, se asigna al número marcado
            // If the call source is blank, assign to the dialed number
            $r = $this->_ami->GetVar($params['Channel'], 'CALLERID(num)');
            if ($r['Response'] != 'Success') {
            	$this->_log->output('ERR: '.__METHOD__.
                    ': fallo en obtener CALLERID(num) para canal '.$params['Channel'].
                    ': '.$r['Response'].' - '.$r['Message'].
                    ' | EN: failed to get CALLERID(num) for channel '.$params['Channel'].
                    ': '.$r['Response'].' - '.$r['Message']);
            } else {
                $r['Value'] = in_array(trim($r['Value']), array('', '<null>', '(null)'))
                    ? '' : trim($r['Value']);
                if (empty($r['Value'])) {
                    $r = $this->_ami->SetVar($params['Channel'], 'CALLERID(num)', $llamada->phone);
                    if ($r['Response'] != 'Success') {
                        $this->_log->output('ERR: '.__METHOD__.
                            ': fallo en asignar CALLERID(num) para canal '.$params['Channel'].
                            ': '.$r['Response'].' - '.$r['Message'].
                            ' | EN: failed to set CALLERID(num) for channel '.$params['Channel'].
                            ': '.$r['Response'].' - '.$r['Message']);
                    }
                }
            }
        }
        return FALSE;
    }

    // Nueva función
    // New function
    public function msg_QueueMemberAdded($sEvent, $params, $sServer, $iPort)
    {

        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: recibido/received => ".print_r($params, TRUE)
                );
        }

        $params['Location'] = isset($params['Location'])?$params['Location']:$params['Interface'];  // Since Asterisk 13 we have Interface and no Location

        $this->_queueshadow->msg_QueueMemberAdded($params);

        $sAgente = $params['Location'];

        // Normalize agent queue interface to canonical Agent/XXXX for lookup
        if (!is_null($this->_compat)) {
            $sNormalized = $this->_compat->normalizeAgentFromInterface($sAgente);
            if (!is_null($sNormalized)) $sAgente = $sNormalized;
        }

        /* tomado de msg_agentLogin */
        /* taken from msg_agentLogin */
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);

        /* if (is_null($a) || $a->estado_consola == 'logged-out') { // Línea original */
        /* if (is_null($a) || $a->estado_consola == 'logged-out') { // Original line */
       if (is_null($a)) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": AgentLogin($sAgente) no iniciado por programa, no se hace nada. | EN: AgentLogin($sAgente) not started by program, doing nothing.");
                $this->_log->output("DEBUG: ".__METHOD__.": EXIT OnAgentlogin | EN: EXIT OnAgentlogin");
            }
            return FALSE;
        }

        $a->actualizarEstadoEnCola($params['Queue'], $params['Status']);

        /* El cambio de membresía sólo se reporta para agentes estáticos, porque
         * el de agentes dinámicos se reporta al refrescar membresía de agentes
         * con el mensaje desde CampaignProcess. */
        /* Membership change is only reported for static agents, because
         * dynamic agent membership is reported when refreshing agent membership
         * with the message from CampaignProcess. */
        if ($a->type == 'Agent') $a->nuevaMembresiaCola();

        if ($a->estado_consola != 'logged-in') {
            if (!is_null($a->extension)) {
                if (in_array($params['Queue'], $a->colas_dinamicas)) {
                    $a->completarLoginAgente($this->_ami);
                } else {
                    $this->_log->output('WARN: '.__METHOD__.': se ignora ingreso a '.
                        'cola '.$params['Queue'].' de '.$sAgente.
                        ' - cola no está en colas dinámicas.'.
                        ' | EN: ignoring queue join to '.$params['Queue'].' by '.$sAgente.
                        ' - queue is not in dynamic queues.');
                }
            } else {
                // $a->extension debió de setearse en $a->iniciarLoginAgente()
                // $a->extension should have been set in $a->iniciarLoginAgente()
                $this->_log->output('WARN: '.__METHOD__.': se ignora ingreso a '.
                    'cola '.$params['Queue'].' de '.$sAgente.
                    ' - no iniciado por requerimiento loginagente. | EN: ignoring queue join to '.$params['Queue'].' by '.$sAgente.' - not initiated by loginagente request.');
            }
        } else {
        	if ($this->DEBUG) {
        		$this->_log->output("DEBUG: ".__METHOD__.": AgentLogin($sAgente) duplicado (múltiples colas), ignorando | EN: duplicate AgentLogin($sAgente) (multiple queues), ignoring");
                $this->_log->output("DEBUG: ".__METHOD__.": EXIT OnAgentlogin | EN: EXIT OnAgentlogin");
        	}
        }
    }

    public function msg_QueueMemberRemoved($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: recibido/received => ".print_r($params, TRUE)
                );
        }

        $params['Location'] = isset($params['Location'])?$params['Location']:$params['Interface'];  // Since Asterisk 13 we have Interface and no Location

        $this->_queueshadow->msg_QueueMemberRemoved($params);

        $sAgente = $params['Location'];

        // Normalize agent queue interface to canonical Agent/XXXX for lookup
        if (!is_null($this->_compat)) {
            $sNormalized = $this->_compat->normalizeAgentFromInterface($sAgente);
            if (!is_null($sNormalized)) $sAgente = $sNormalized;
        }

        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);

        if (is_null($a)) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": AgentLogin({$sAgente}) no iniciado por programa, no se hace nada. | EN: AgentLogin({$sAgente}) not started by program, doing nothing.");
                $this->_log->output("DEBUG: ".__METHOD__.": EXIT OnAgentlogoff | EN: EXIT OnAgentlogoff");
            }
            return FALSE;
        }

        $a->quitarEstadoEnCola($params['Queue']);

        /* El cambio de membresía sólo se reporta para agentes estáticos, porque
         * el de agentes dinámicos se reporta al refrescar membresía de agentes
         * con el mensaje desde CampaignProcess. */
        if ($a->type == 'Agent') $a->nuevaMembresiaCola();

        if ($a->estado_consola == 'logged-in') {
            if ($a->type == 'Agent') {
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__.": QueueMemberRemoved({$params['Location']}) , ignorando... | EN: QueueMemberRemoved({$params['Location']}), ignoring...");
                }
            } elseif ($a->hayColasDinamicasLogoneadas()) {
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__.": QueueMemberRemoved({$params['Location']}) todavía quedan colas pendientes, ignorando... | EN: there are still queues pending, ignoring...");
                }
            } else {
                $this->_ejecutarLogoffAgente($params['Location'], $a,
                    $params['local_timestamp_received'], $params['Event']);
            }
        } else {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": QueueMemberRemoved({$params['Location']}) en estado no-logoneado, ignorando... | EN: QueueMemberRemoved({$params['Location']}) in non-logged-in state, ignoring...");
            }
        }

        if ($this->_finalizandoPrograma) $this->_verificarFinalizacionLlamadas();
        if ($this->DEBUG) {
            $this->_log->output("DEBUG: ".__METHOD__.": SALIDA QueueMemberRemoved | EN: EXIT QueueMemberRemoved");
        }
        return FALSE;
    }

    public function msg_Join($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: recibido/received => ".print_r($params, TRUE)
                );
        }

        $this->_queueshadow->msg_Join($params);

        $llamada = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid']);
        if (is_null($llamada) && isset($this->_colasEntrantes[$params['Queue']])) {
            // Llamada de campaña entrante
            // Incoming campaign call
            $llamada = $this->_listaLlamadas->nuevaLlamada('incoming');
            $llamada->uniqueid = $params['Uniqueid'];
            $llamada->id_queue_call_entry = $this->_colasEntrantes[$params['Queue']]['id_queue_call_entry'];
            if (isset($params['CallerIDNum'])) $llamada->phone = $params['CallerIDNum'];
            if (isset($params['CallerID'])) $llamada->phone = $params['CallerID'];
            $c = $this->_colasEntrantes[$params['Queue']]['campania'];
            if (!is_null($c) && $c->enHorarioVigencia($params['local_timestamp_received'])) {
                $llamada->campania = $c;
            }
        }
        if (!is_null($llamada)) {
            $llamada->llamadaEntraEnCola(
                $params['local_timestamp_received'],
                $params['Channel'],
                $params['Queue']);
            if ($llamada->tipo_llamada == 'incoming') {
                // Esto asume que toda llamada entrante se crea más arriba
                // This assumes that all incoming calls are created above
                $this->_ami->asyncGetVar(
                    array($this, '_cb_GetVar_MIXMONITOR_FILENAME'),
                    array($params['Channel'], $llamada),
                    $params['Channel'], 'MIXMONITOR_FILENAME');
            }
        }

        return FALSE;
    }

    // Callback con resultado del GetVar(MIXMONITOR_FILENAME)
    // Callback with result of GetVar(MIXMONITOR_FILENAME)
    public function _cb_GetVar_MIXMONITOR_FILENAME($r, $channel, $llamada)
    {
        if ($r['Response'] != 'Success') {
            if ($this->DEBUG) {
                $this->_log->output('DEBUG: '.__METHOD__.
                    ': fallo en obtener MIXMONITOR_FILENAME para canal '.$channel.
                    ': '.$r['Response'].' - '.$r['Message'].
                    ' | EN: failed to get MIXMONITOR_FILENAME for channel '.$channel.
                    ': '.$r['Response'].' - '.$r['Message']);
            }
        } else {
            $r['Value'] = trim($r['Value']);
            if (!empty($r['Value'])) {
                $llamada->agregarArchivoGrabacion($llamada->uniqueid, $channel, $r['Value']);
            }
        }
    }
    public function msg_BridgeDestroy($sEvent, $params, $sServer, $iPort)
    {
/*         global $saved_bridge_unique, $saved_bridge_channel;
        $bunique = $params['BridgeUniqueid'];
        unset($saved_bridge_unique[$bunique]);
        unset($saved_bridge_channel[$bunique]);
 */ 
        // Replace global usage with class properties
        $bunique = $params['BridgeUniqueid'];
        if (isset($this->_saved_bridge_unique[$bunique])) {
            unset($this->_saved_bridge_unique[$bunique]);
        }
        if (isset($this->_saved_bridge_channel[$bunique])) {
            unset($this->_saved_bridge_channel[$bunique]);
            unset($this->_saved_bridge_channel[$bunique.'_local']);
            unset($this->_saved_bridge_channel[$bunique.'_actual']);
        }
        $this->_log->output('DEBUG: '.__METHOD__. " Puente destruido/Bridge Destroy $bunique");

    }

    public function msg_BridgeEnter($sEvent, $params, $sServer, $iPort)
    {
// Replace global with class properties
        // global $saved_bridge_unique, $saved_bridge_channel; <--- REMOVE THIS

        // BridgeTechnology simple_bridge con BridgeNumChannels: 1...
        if($params['BridgeTechnology']<>'simple_bridge') {
            return false;
        }

        $bunique = $params['BridgeUniqueid'];

        // Handle Local/XXXX@agents;N channels from app_agent_pool (Asterisk 12+)
        // On chan_agent (Asterisk 11), channel is Agent/XXXX and this won't match
        $isLocalAgentChannel = false;
        $originalChannel = $params['Channel'];  // Save original BEFORE conversion
        if(preg_match("|Local/(\d+)@agents[;-].*|",$params['Channel'],$matches)) {
            $isLocalAgentChannel = true;
            $params['Channel']='Agent/'.$matches[1];
        }

        if($params['BridgeNumChannels']==1) {
            $this->_saved_bridge_unique[$bunique]  = $params['Uniqueid'];
            $this->_saved_bridge_channel[$bunique] = $params['Channel'];
            
            if ($isLocalAgentChannel) {
                $this->_saved_bridge_channel[$bunique.'_local'] = true;
                $this->_saved_bridge_channel[$bunique.'_actual'] = $originalChannel;
            }
            $this->_log->output('DEBUG: '.__METHOD__. " Entrada puente/Bridge Enter $bunique canales/channels 1, guardando datos/saving data");
        } else if ($params['BridgeNumChannels']==2) {
            $this->_log->output('DEBUG: '.__METHOD__. " Entrada puente/Bridge Enter $bunique canales/channels 2, construyendo enlace canal/constructing link channel ".$params['Channel']);
            
            if(isset($this->_saved_bridge_unique[$bunique])) {
                $params['Uniqueid1']=$params['Uniqueid'];
                $params['Channel1']=$params['Channel'];
                $params['Uniqueid2']=$this->_saved_bridge_unique[$bunique];
                $params['Channel2']=$this->_saved_bridge_channel[$bunique];

                // Pass actual channels for AMI operations
                if (isset($this->_saved_bridge_channel[$bunique.'_actual'])) {
                    $params['ActualChannel2'] = $this->_saved_bridge_channel[$bunique.'_actual'];
                }
                if ($isLocalAgentChannel) {
                    $params['ActualChannel1'] = $originalChannel;
                }

                $params['Event']='Bridge';
                $this->msg_Link("bridge", $params, $sServer, $iPort); 
            } else {
                $llamada = $this->_listaLlamadas->buscar('auxchannel', $params['Uniqueid']);
                $this->_log->output('DEBUG: '.__METHOD__. " Bridge Enter estaba destruido, busco auxchannel/was destroyed, searching auxchannel $llamada");
                $llamada->dump($this->_log);

                $llamada = $this->_listaLlamadas->buscar('channel', $params['Uniqueid']);
                $this->_log->output('DEBUG: '.__METHOD__. " Bridge Enter estaba destruido, busco channel/was destroyed, searching channel $llamada");
                $llamada->dump($this->_log);

                // $llamada->actualchannel = $sCanalCandidato

            }

        }
    }


    public function msg_Link($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: recibido/received => ".print_r($params, TRUE)
                );
        }

        // Asterisk 11 no emite evento Unlink sino Bridge con Bridgestate=Unlink
        if (isset($params['Bridgestate']) && $params['Bridgestate'] == 'Unlink')
            return FALSE;

        $llamada = NULL;

        // Recuperar el agente local y el canal remoto
        list($sAgentNum, $sAgentChannel, $sChannel, $sRemChannel) = $this->_identificarCanalAgenteLink($params);

        // Detect agent returning from attended transfer consultation (callback type).
        // When Channel1=Channel2, the agent re-entered the original bridge after
        // the consultation call was rejected/hung up. The llamada lookup by uniqueid
        // would fail here because both uniqueids are the agent's, not the call's.
        if ($params['Channel1'] == $params['Channel2'] && !is_null($sChannel)) {
            if (isset($this->_agentesEnConsultation[$sChannel])) {
                unset($this->_agentesEnConsultation[$sChannel]);
                $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
                    array('ConsultationEnd', array($sChannel))
                ));
                return FALSE;
            }
            return FALSE;
        }

        if (is_null($llamada)) $llamada = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid1']);
        if (is_null($llamada)) $llamada = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid2']);

        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.': call lookup result: '.
                'llamada='.(!is_null($llamada) ? 'FOUND(uid='.$llamada->uniqueid.')' : 'NULL').
                ' timestamp_link='.(!is_null($llamada) ? ($llamada->timestamp_link ?? 'NULL') : 'N/A').
                ' agente='.(!is_null($llamada) && !is_null($llamada->agente) ? $llamada->agente->channel : 'NULL').
                ' sChannel='.$sChannel.
                ' | ES: resultado de búsqueda de llamada');
        }

        if (!is_null($llamada) && !is_null($llamada->timestamp_link) &&
            !is_null($llamada->agente) && $llamada->agente->channel != $sChannel) {

            // For Agent type (app_agent_pool), Asterisk swaps the Local/XXXX@agents
            // channel with the agent's physical SIP extension in the bridge after
            // returning from hold. This bridge swap must not be treated as a transfer.
            if ($llamada->agente->extension == $sChannel) {
                // Check if call is returning from hold (via Bridge() in atxfer-unhold)
                if ($llamada->status == 'OnHold') {
                    $this->_log->output('DEBUG: '.__METHOD__.
                        ': Agent type hold recovery via bridge swap for agent '.
                        $llamada->agente->channel.', extension='.$sChannel);
                    // Pass NULL for agentchannel to preserve Agent/XXXX identifier.
                    // The physical SIP channel ($sChannel=SIP/101) must NOT replace
                    // _agentchannel (Agent/1001) - the hangup handler needs Agent/XXXX.
                    $llamada->llamadaRegresaHold($this->_ami,
                        $params['local_timestamp_received'], NULL,
                        ($llamada->uniqueid == $params['Uniqueid1']) ? $params['Uniqueid2'] : $params['Uniqueid1'],
                        $sAgentChannel);
                    return FALSE;
                }
                // Normal bridge swap - ignore
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.
                        ': ignoring app_agent_pool bridge swap for agent '.
                        $llamada->agente->channel.', extension='.$sChannel.
                        ' | EN: ignoring app_agent_pool bridge swap for agent '.
                        $llamada->agente->channel.', extension='.$sChannel);
                }
                return FALSE;
            }

            /* If the call has been previously linked, and now links
             * to a different channel than the original agent, it is assumed
             * to have been transferred to an unmonitored extension, and should
             * no longer be monitored. Since Asterisk does not execute a Hangup
             * in this case, it must be simulated.
             */
            $a = $this->_listaAgentes->buscar('agentchannel', $sChannel);
            if (!is_null($a)) {
            	$this->_log->output('WARN: '.__METHOD__.': se ha detectado '.
                    'transferencia a otro agente, pero seguimiento de llamada '.
                    'con múltiples agentes no está (todavía) implementado.'.
                    ' | EN: transfer to another agent detected, but call tracking '.
                    'with multiple agents is not (yet) implemented.');
            } elseif (!is_null($sAgentNum)) {
                $this->_log->output("ERR: ".__METHOD__.": no se ha ".
                    "cargado información de agente $sAgentNum".
                    " | EN: agent information not loaded for agent $sAgentNum");
                $this->_tuberia->msg_SQLWorkerProcess_requerir_nuevaListaAgentes();
            } else {
                if ($this->DEBUG) {
                	$this->_log->output('DEBUG: '.__METHOD__.': llamada '.
                        'transferida a extensión no monitoreada '.$sChannel.
                        ', se finaliza seguimiento...'.
                        ' | EN: call transferred to unmonitored extension '.$sChannel.
                        ', ending tracking...');
                }
            }
            $llamada->llamadaFinalizaSeguimiento(
                $params['local_timestamp_received'],
                $this->_config['dialer']['llamada_corta']);
            return FALSE;
        }

        // Detect agent returning from consultation to the original call.
        // After hold recovery, Channel1 != Channel2 (bridge saved the caller's
        // channel, not the agent's), so the Channel1==Channel2 check above
        // does not fire. Instead, detect by: call already linked to same agent
        // AND agent is in consultation AND call is NOT on hold.
        // OnHold check is critical: if agent had a stale consultation state
        // (e.g. from a previous successful transfer where ConsultationEnd UserEvent
        // never fired), hold recovery must take precedence over consultation detection.
        if (!is_null($llamada) && !is_null($llamada->timestamp_link) &&
            !is_null($llamada->agente) && $llamada->agente->channel == $sChannel &&
            !is_null($sChannel) && isset($this->_agentesEnConsultation[$sChannel]) &&
            $llamada->status != 'OnHold') {
            $this->_log->output("DEBUG_HOLD: [" . date('Y-m-d H:i:s.') . substr(microtime(), 2, 3) .
                "] ConsultationEnd via Link detected for agent=$sChannel" .
                " call actualchannel=" . $llamada->actualchannel .
                " call status=" . $llamada->status);
            if ($this->DEBUG) {
                $this->_log->output('DEBUG: '.__METHOD__.
                    ': agent '.$sChannel.' returned from consultation to original call'.
                    ' | EN: agent '.$sChannel.' returned from consultation to original call');
            }
            unset($this->_agentesEnConsultation[$sChannel]);
            $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
                array('ConsultationEnd', array($sChannel))
            ));
            return FALSE;
        }

        /* Se ha detectado llamada que regresa de hold. En el evento ParkedCall
         * se asignó el uniqueid nuevo. */
        /* A call returning from hold has been detected. In the ParkedCall event,
        * a new uniqueid was assigned. */
        if (!is_null($llamada) && $llamada->status == 'OnHold') {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": identificada llamada ".
                    "que regresa de HOLD {$llamada->actualchannel}, ".
                    "agentchannel={$sChannel} actualAgentChannel={$sAgentChannel} se quita estado OnHold...".
                    " | EN: identified call returning from HOLD {$llamada->actualchannel}, ".
                    "agentchannel={$sChannel} actualAgentChannel={$sAgentChannel} removing OnHold state...");
            }
            // Pass $sChannel (logical name: Agent/1001 or SIP/101) as agentchannel
            // and $sAgentChannel (actual channel: Local/1001@agents-xxx or SIP/101-xxx)
            // as actualAgentChannel, consistent with llamadaEnlazadaAgente()
            $llamada->llamadaRegresaHold($this->_ami,
                $params['local_timestamp_received'], $sChannel,
                ($llamada->uniqueid == $params['Uniqueid1']) ? $params['Uniqueid2'] : $params['Uniqueid1'],
                $sAgentChannel);
            return FALSE;
        }

        /* Si no se tiene clave, todavía puede ser llamada agendada que debe
         * buscarse por nombre de canal. También podría ser una llamada que
         * regresa de Hold, y que ha sido asignado un Uniqueid distinto. Para
         * distinguir los dos casos, se verifica el estado de Hold de la
         * llamada.
         */
        /* If there is no key, it could still be a scheduled call that must be 
        searched for by channel name. It could also be a call returning from Hold, 
        which has been assigned a different UniqueID. To distinguish between 
        the two cases, the call's Hold status is checked..
         */

        $sNuevo_Uniqueid = NULL;
        if (is_null($llamada)) {
            $llamada = $this->_listaLlamadas->buscar('actualchannel', $params["Channel1"]);
            if (!is_null($llamada)) $sNuevo_Uniqueid = $params["Uniqueid1"];
        }
        if (is_null($llamada)) {
            $llamada = $this->_listaLlamadas->buscar('actualchannel', $params["Channel2"]);
            if (!is_null($llamada)) $sNuevo_Uniqueid = $params["Uniqueid2"];
        }
        if (!is_null($sNuevo_Uniqueid) && $llamada->uniqueid != $sNuevo_Uniqueid) {
            if (!is_null($llamada->agente_agendado) && $llamada->agente_agendado->channel == $sChannel) {
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__.": identificada llamada agendada ".
                        "{$llamada->channel}, cambiado Uniqueid a {$sNuevo_Uniqueid}".
                        " | EN: identified scheduled call ".
                        "{$llamada->channel}, changed Uniqueid to {$sNuevo_Uniqueid}");
                }
                $llamada->uniqueid = $sNuevo_Uniqueid;
            } elseif (!is_null($llamada->timestamp_link) && is_null($llamada->agente)) {
                // Transfer scenario: outgoing calls use Local channels so the tracked
                // uniqueid differs from the trunk channel's uniqueid in the new bridge.
                // Accept the call and update uniqueid so transfer detection handles it.
                $this->_log->output('INFO: '.__METHOD__.': transfer - outgoing call found by '.
                    'actualchannel='.$llamada->actualchannel.', updating uniqueid from '.
                    $llamada->uniqueid.' to '.$sNuevo_Uniqueid.
                    ' | ES: transferencia - llamada saliente encontrada por '.
                    'actualchannel='.$llamada->actualchannel.', actualizando uniqueid de '.
                    $llamada->uniqueid.' a '.$sNuevo_Uniqueid);
                $llamada->uniqueid = $sNuevo_Uniqueid;
            } else {
                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: ".__METHOD__.": identificada ".
                        "llamada que comparte un actualchannel={$llamada->actualchannel} ".
                        "pero no regresa de HOLD ni es agendada, se ignora.".
                        " | EN: identified call sharing actualchannel={$llamada->actualchannel} ".
                        "but not returning from HOLD nor scheduled, ignoring.");
                }
            	$llamada = NULL;
            }
        }


        if (!is_null($llamada)) {
            // Se tiene la llamada principal monitoreada
            // The main call is being monitored
            if (!is_null($llamada->timestamp_link)) {
                if (is_null($llamada->agente)) {
                    // TRANSFER SCENARIO: call was linked before but source agent released
                    // by _finalizarTransferencia(). Reassign to the new target agent.
                    $this->_log->output('INFO: '.__METHOD__.': transfer detected - reassigning call '.
                        $llamada->uniqueid.' to agent '.$sChannel.
                        ' | ES: transferencia detectada - reasignando llamada '.
                        $llamada->uniqueid.' al agente '.$sChannel);

                    $a = $this->_listaAgentes->buscar('agentchannel', $sChannel);
                    if (is_null($a) || $a->estado_consola != 'logged-in') {
                        // Agent-type fallback: direct extension transfer shows SIP/XXX
                        // but agentchannel is Agent/XXXX, lookup by extension instead.
                        // Also fallback when agentchannel finds a logged-out agent
                        // (e.g. SIP/101 agent exists but Agent/1001 using SIP/101 is logged-in)
                        $a = $this->_listaAgentes->buscar('extension', $sChannel);
                    }
                    if (!is_null($a)) {
                        $sUniqueidAgente = ($llamada->uniqueid == $params['Uniqueid1'])
                            ? $params['Uniqueid2'] : $params['Uniqueid1'];

                        // Use $a->channel for agentchannel (e.g. Agent/1001) not $sChannel
                        // (e.g. SIP/101) to ensure downstream hangup handlers work correctly
                        $llamada->llamadaEnlazadaAgente(
                            $params['local_timestamp_received'], $a,
                            $sRemChannel, $sUniqueidAgente,
                            $a->channel, $sAgentChannel);

                        // Clear transfer_pending now that call is re-linked to target agent,
                        // so future hangups use normal finalization
                        $llamada->transfer_pending = FALSE;

                        $this->_log->output('INFO: '.__METHOD__.': call successfully reassigned to '.
                            $a->channel.' | ES: llamada reasignada exitosamente a '.$a->channel);

                        // TODO: Source agent attribution is lost - id_agent in calls/call_entry
                        // is overwritten to target agent. Consider adding a call_agent_history
                        // table to track all agents that handled a call for accurate reporting.

                        $this->_liberarReservaTransferencia($a->channel);
                    } else {
                        $this->_log->output('WARN: '.__METHOD__.': transfer target agent not found for channel '.
                            $sChannel.' | ES: agente destino no encontrado para canal '.$sChannel);
                    }
                    return FALSE;
                }
                // Normal multiple link - ignore | Múltiple link se ignora
                return FALSE;
            }

            $a = $this->_listaAgentes->buscar('agentchannel', $sChannel);
            if (is_null($a)) {
            	$this->_log->output("ERR: ".__METHOD__.": no se puede identificar agente ".
                    "asignado a llamada. Se dedujo que el canal de agente era $sChannel ".
                    "a partir de/from params=".print_r($params, 1).
                    "\nResumen de llamada asociada/Associated call summary: ".print_r($llamada->resumenLlamada(), 1).
                    " | EN: cannot identify agent assigned to call. Agent channel was deduced as $sChannel");
            } else {
                // For Agent type: $sChannel is Agent/1001 (for lookup/backward compat),
                // $sAgentChannel is the actual channel (Local/... after substitution in _identificarCanalAgenteLink)
                $llamada->llamadaEnlazadaAgente(
                    $params['local_timestamp_received'], $a, $sRemChannel,
                    ($llamada->uniqueid == $params['Uniqueid1']) ? $params['Uniqueid2'] : $params['Uniqueid1'],
                    $sChannel, $sAgentChannel);
                if (is_null($llamada->actualchannel)) {
                    if ($llamada->agente->type == 'Agent') {
                        $this->_iniciarAgents();
                    } else {
                        $this->_log->output('WARN: '.__METHOD__.
                            ' actualchannel no identificado, identificación no implementada para agente dinámico.'.
                            ' | EN: actualchannel not identified, identification not implemented for dynamic agent.'.
                            "\nResumen de llamada asociada/Associated call summary: ".print_r($llamada->resumenLlamada(), 1));
                    }
                }
            }
        } else {
            /* El Link de la pata auxiliar con otro canal puede indicar el
             * ActualChannel requerido para poder manipular la llamada. */
            /* The Link of the auxiliary leg with another channel can indicate 
            the ActualChannel required to be able to manipulate the call. 
            */

            $sCanalCandidato = NULL;
            if (is_null($llamada)) {
                $llamada = $this->_listaLlamadas->buscar('auxchannel', $params['Uniqueid1']);
                if (!is_null($llamada)) $sCanalCandidato = $params['Channel2'];
            }
            if (is_null($llamada)){
                $llamada = $this->_listaLlamadas->buscar('auxchannel', $params['Uniqueid2']);
                if (!is_null($llamada)) $sCanalCandidato = $params['Channel1'];
            }
            if (!is_null($llamada) && !is_null($sCanalCandidato) &&
                strpos($sCanalCandidato, 'Local/') !== 0) {
            	if (is_null($llamada->actualchannel)) {
                    $llamada->actualchannel = $sCanalCandidato;
                    if ($this->DEBUG) {
            			$this->_log->output('DEBUG: '.__METHOD__.
                            ': capturado canal remoto real/captured real remote channel: '.$sCanalCandidato);
            		}
            	} elseif ($llamada->actualchannel != $sCanalCandidato) {
                    if (is_null($llamada->timestamp_link)) {
                		$this->_log->output('WARN: '.__METHOD__.': canal remoto en '.
                            'conflicto, anterior '.$llamada->actualchannel.' nuevo '.
                            $sCanalCandidato.
                            ' | EN: remote channel conflict, previous '.$llamada->actualchannel.' new '.
                            $sCanalCandidato);
                    } else {
                        if ($this->DEBUG) {
                            $this->_log->output('DEBUG: '.__METHOD__.': canal remoto en '.
                                'conflicto, anterior '.$llamada->actualchannel.' nuevo '.
                                $sCanalCandidato.', se ignora por ser luego de Link.'.
                                ' | EN: remote channel conflict, previous '.$llamada->actualchannel.' new '.
                                $sCanalCandidato.', ignored because it is after Link.');
                        }
                    }
            	}
            }
        }

        return FALSE;
    }

    private function _identificarCanalAgenteLink(&$params)
    {
        $regs = NULL;

        // Se asume que el posible canal de agente es de la forma TECH/dddd
        // En particular, el regexp a continuación NO MATCHEA Local/xxx@from-internal
        // It is assumed that the possible agent channel is of the form TECH/dddd
        // In particular, the regexp below DOES NOT MATCH Local/xxx@from-internal
        $regexp_channel = '|^([[:alnum:]]+/(\d+))(\-\w+)?$|';
        // For app_agent_pool: match Local/XXXX@agents pattern
        $regexp_local_agent = '|^Local/(\d+)@agents(;\d)?$|';

        $r1 = NULL;
        if (preg_match($regexp_channel, $params['Channel1'], $regs)) {
            $r1 = $regs;
        } elseif (preg_match($regexp_local_agent, $params['Channel1'], $regs)) {
            // Convert Local/1001@agents to Agent/1001 format for lookup
            $r1 = array($params['Channel1'], 'Agent/'.$regs[1], $regs[1]);
        }
        $r2 = NULL;
        if (preg_match($regexp_channel, $params['Channel2'], $regs)) {
            $r2 = $regs;
        } elseif (preg_match($regexp_local_agent, $params['Channel2'], $regs)) {
            // Convert Local/1001@agents to Agent/1001 format for lookup
            $r2 = array($params['Channel2'], 'Agent/'.$regs[1], $regs[1]);
        }

        // Substitute actual channel for Agent type if passed in params (for AMI operations)
        if (!is_null($r1) && preg_match('|^Agent/\d+$|', $r1[0]) && isset($params['ActualChannel1'])) {
            $r1[0] = $params['ActualChannel1'];
        }
        if (!is_null($r2) && preg_match('|^Agent/\d+$|', $r2[0]) && isset($params['ActualChannel2'])) {
            $r2[0] = $params['ActualChannel2'];
        }

        // Casos fáciles de decidir
        // Easy cases to decide
        if (is_null($r1) && is_null($r2)) return array(NULL, NULL, NULL, NULL);
        if (is_null($r2)) return array($r1[2], $r1[0], $r1[1], $params['Channel2']);
        if (is_null($r1)) return array($r2[2], $r2[0], $r2[1], $params['Channel1']);

        /* Ambos lados parecen canales normales. Si uno de los dos no es un
         * agente conocido, es el canal remoto. */
        /* Both sides appear to be normal channels. If one of them is not a
         * known agent, it is the remote channel. */
        $a1 = $this->_listaAgentes->buscar('agentchannel', $r1[1]);
        $a2 = $this->_listaAgentes->buscar('agentchannel', $r2[1]);
        if (is_null($a1) && is_null($a2)) return array(NULL, NULL, NULL, NULL);
        if (is_null($a2)) return array($r1[2], $r1[0], $r1[1], $params['Channel2']);
        if (is_null($a1)) return array($r2[2], $r2[0], $r2[1], $params['Channel1']);

        /* Ambos lados son agentes conocidos. Si uno de los dos NO está logoneado,
         * está haciendo el papel de canal remoto. */
        /* Both sides are known agents. If one of them is NOT logged in,
         * it is acting as the remote channel. */
        if ($a1->estado_consola != 'logged-in' && $a2->estado_consola != 'logged-in')
            return array(NULL, NULL, NULL, NULL);
        if ($a2->estado_consola != 'logged-in')
            return array($r1[2], $r1[0], $r1[1], $params['Channel2']);
        if ($a1->estado_consola != 'logged-in')
            return array($r2[2], $r2[0], $r2[1], $params['Channel1']);

        /* Ambos lados son agentes logoneados (????). Se da preferencia al tipo
         * Agent. Si ambos son Agent (¿cómo se llamaron entre sí?) se da preferencia
         * al canal 1. */
        /* Both sides are logged-in agents (????). Preference is given to Agent
         * type. If both are Agent (how did they call each other?) preference is
         * given to channel 1. */
        $this->_log->output('WARN: '.__METHOD__.': llamada entre dos agentes logoneados '.
            $r1[1].' y '.$r2[1].
            ' | EN: call between two logged-in agents '.
            $r1[1].' and '.$r2[1]);
        if ($a1->type == 'Agent') return array($r1[2], $r1[0], $r1[1], $params['Channel2']);
        if ($a2->type == 'Agent') return array($r2[2], $r2[0], $r2[1], $params['Channel1']);

        /* Ambos son de tipo dinámico y logoneados. Se da preferencia al primero. */
        /* Both are dynamic and logged-in. Preference is given to the first. */
        return array($r1[2], $r1[0], $r1[1], $params['Channel2']);
    }

    public function msg_Hangup($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\n$sEvent: recibido/received => ".print_r($params, TRUE)
                );
        }

        if ($this->_manejarHangupAgentLoginFallido($params)) {
            if ($this->_finalizandoPrograma) $this->_verificarFinalizacionLlamadas();
            return FALSE;
        }

        if (strpos($params['Channel'], 'Local/')===0) {
            // Normally Local channel hangups are ignored because the real trunk
            // channel hangup handles cleanup. However, when the trunk fails
            // (e.g., CHANUNAVAIL) but the dialplan routes the call to a queue
            // where an agent answers, the Local channel hangup is the only
            // event that can finalize the call.
            $bLocalTracked = FALSE;
            if (!is_null($this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid']))) {
                $bLocalTracked = TRUE;
            } elseif (!is_null($this->_listaAgentes->buscar('uniqueidlink', $params['Uniqueid']))) {
                // Skip agent callback channels (Local/XXXX@agents) - these are internal
                // callbacks for Agent type logins that hang up after the agent answers.
                // Their hangup should NOT finalize the customer call.
                if (preg_match('/^Local\/\d+@agents/', $params['Channel'])) {
                    $this->_log->output('DEBUG: '.__METHOD__.
                        ': ignoring agent callback channel hangup | channel='.$params['Channel']);
                } else {
                    $bLocalTracked = TRUE;
                }
            } elseif (!is_null($this->_listaLlamadas->buscar('actualchannel', $params['Channel']))) {
                $bLocalTracked = TRUE;
            }
            if (!$bLocalTracked) {
                $this->_log->output('DEBUG: '.__METHOD__.': ignoro hangup local | EN: ignoring local hangup');
                return FALSE;
            }

            /* Asterisk saca el par de canales Local del puente en cuanto ambos
             * lados quedan enlazados ("bridge.c: Move-swap optimizing
             * Local/<num>@from-internal-XXXXXXXX;1 <-- SIP/<troncal>-YYYYYYYY").
             * Las dos patas cuelgan entonces con Cause 16 mientras el canal real
             * del troncal sigue conversando con el agente. En una llamada de
             * campaña saliente marcada por plan de marcado la pata ;1 lleva el
             * Uniqueid de la llamada misma, así que la comprobación de arriba la
             * reconoce y la llamada se daría por terminada estando todavía en
             * curso. Si la llamada está enlazada a un agente, no está en medio de
             * una transferencia, y tiene un canal real (no Local) distinto del que
             * cuelga, esto es la optimización y no el fin de la llamada: se ignora.
             * El Hangup del canal real la finaliza después, encontrado por el
             * índice actualchannel más abajo. */
            /* EN: Asterisk optimizes the Local channel pair out of the bridge as
             * soon as both sides are linked ("bridge.c: Move-swap optimizing
             * Local/<num>@from-internal-XXXXXXXX;1 <-- SIP/<trunk>-YYYYYYYY").
             * Both halves then hang up with Cause 16 while the real trunk channel
             * keeps talking to the agent. On an outgoing campaign call dialed
             * through the dialplan the ;1 half carries the call's own Uniqueid, so
             * the check above matches it and the call would be finalized while it
             * is still up. If the call is linked to an agent, is not in the middle
             * of a transfer, and has a real (non-Local) channel other than the one
             * hanging up, this is the optimization and not the end of the call:
             * ignore it. The real channel's own Hangup finalizes the call later,
             * matched by the actualchannel index below. */
            $llamadaLocal = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid']);
            if (!is_null($llamadaLocal)
                    && !$llamadaLocal->transfer_pending
                    && !is_null($llamadaLocal->agente)
                    && !is_null($llamadaLocal->timestamp_link)
                    && !is_null($llamadaLocal->actualchannel)
                    && strpos($llamadaLocal->actualchannel, 'Local/') !== 0
                    && $llamadaLocal->actualchannel != $params['Channel']) {
                $this->_log->output('DEBUG: '.__METHOD__.
                    ': Local channel optimized out of the bridge, call continues on '.
                    $llamadaLocal->actualchannel.', ignoring hangup'.
                    ' | uniqueid='.$params['Uniqueid'].' channel='.$params['Channel'].
                    ' | ES: canal Local optimizado fuera del puente, la llamada sigue en '.
                    $llamadaLocal->actualchannel.', se ignora el hangup');
                return FALSE;
            }
            $this->_log->output('DEBUG: '.__METHOD__.
                ': Local channel hangup matches tracked call, processing normally'.
                ' | uniqueid='.$params['Uniqueid'].' channel='.$params['Channel']);
        }

        $a = NULL;
        $llamada = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid']);
        if (is_null($llamada)) {
            /* Si la llamada ha sido transferida, la porción que está siguiendo
             * el marcador todavía está activa, pero transferida a otra extensión.
             * Sin embargo, el agente está ahora libre y recibirá otra llamada.
             * El hangup de aquí podría ser para la parte de la llamada del
             * agente. */
            /* If the call has been transferred, the portion that the dialer
             * is following is still active, but transferred to another extension.
             * However, the agent is now free and will receive another call.
             * The hangup here could be for the agent's portion of the call. */
            $a = $this->_listaAgentes->buscar('uniqueidlink', $params['Uniqueid']);
            if (!is_null($a) && !is_null($a->llamada)) {
                $llamada = $a->llamada;
            }
        }
        // For local extension calls, the actualchannel (e.g. SIP/103) may have a different
        // uniqueid than the call's tracked uniqueid. Search by actualchannel as fallback.
        if (is_null($llamada)) {
            $llamada = $this->_listaLlamadas->buscar('actualchannel', $params['Channel']);
        }

        // The agent's own login_channel just hung up for real while an
        // attended-transfer consultation was in progress for them (whether
        // still ringing, mid-conversation with the colleague, or completing
        // via the classic "hang up your phone" path). $a resolved above via
        // uniqueidlink is this agent - handle this before the generic
        // dispatch below, which would otherwise finalize the call while the
        // held customer channel is still alive in atxfer-hold's MOH loop.
        if (!is_null($a) && (isset($this->_agentesEnConsultation[$a->channel])
                || isset($this->_agentesEnAtxferComplete[$a->channel]))) {
            $this->_manejarHangupLoginChannelEnConsulta($a, $llamada, $params);
            if ($this->_finalizandoPrograma) $this->_verificarFinalizacionLlamadas();
            return FALSE;
        }

        if (!is_null($llamada)) {
            // Check if a blind transfer is in progress - if so, release source
            // agent but keep the call in _listaLlamadas for the target agent's
            // Link event to find and re-link.
            if ($llamada->transfer_pending) {
                if (!is_null($llamada->agente)) {
                    // Agent still linked - release with lightweight method
                    $this->_log->output('INFO: '.__METHOD__.': hangup during transfer - '.
                        'using lightweight release instead of full finalization. '.
                        'channel='.$params['Channel'].' uniqueid='.$params['Uniqueid'].
                        ' call_uid='.$llamada->uniqueid.
                        ' | ES: hangup durante transferencia - '.
                        'usando liberación ligera en vez de finalización completa');
                    $llamada->llamadaTransferidaDesdeAgente($params['local_timestamp_received']);
                } elseif ($params['Channel'] == $llamada->actualchannel) {
                    // Customer/trunk channel hung up - transfer failed, finalize
                    $this->_log->output('INFO: '.__METHOD__.': customer channel hangup during '.
                        'transfer - finalizing call. channel='.$params['Channel'].
                        ' call_uid='.$llamada->uniqueid.
                        ' | ES: hangup de canal del cliente durante transferencia - '.
                        'finalizando llamada');
                    $llamada->transfer_pending = FALSE;
                    $this->_procesarLlamadaColgada($llamada, $params);
                } else {
                    // Intermediate channel hangup (e.g. Local channel for outgoing calls)
                    // Ignore - call is waiting for re-link via msg_Link
                    $this->_log->output('INFO: '.__METHOD__.': intermediate channel hangup during '.
                        'transfer - ignoring. channel='.$params['Channel'].
                        ' call_uid='.$llamada->uniqueid.
                        ' | ES: hangup de canal intermedio durante transferencia - '.
                        'ignorando');
                }
            } else {
                $this->_procesarLlamadaColgada($llamada, $params);
            }
        } elseif (is_null($a)) {
            /* No se encuentra la llamada entre las monitoreadas. Puede ocurrir
             * que este sea el Hangup de un canal auxiliar que tiene información
             * de la falla de la llamada */
            /* The call is not found among monitored calls. This may be the
             * Hangup of an auxiliary channel that has information about the
             * call failure */
            $llamada = $this->_listaLlamadas->buscar('auxchannel', $params['Uniqueid']);
            if (!is_null($llamada)) {
                $llamada->AuxChannels[$params['Uniqueid']]['Hangup'] = $params;
                $llamada->registerAuxChannels();
                if (is_null($llamada->timestamp_link)) {
                    if ($this->DEBUG) {
                        $this->_log->output(
                            "DEBUG: ".__METHOD__.": Hangup de canal auxiliar de ".
                            "llamada por fallo de Originate para llamada ".
                            $llamada->uniqueid." canal auxiliar ".$params['Uniqueid'].
                            " | EN: Hangup of auxiliary channel of call due to Originate failure for call ".
                            $llamada->uniqueid." auxiliary channel ".$params['Uniqueid']);
                    }
                    $llamada->actualizarCausaFallo($params['Cause'], $params['Cause-txt']);
                }
            }
        }

        if ($this->_finalizandoPrograma) $this->_verificarFinalizacionLlamadas();
        return FALSE;
    }

    /* Procesamiento de llamada identificada: params requiere los elementos:
     * local_timestamp_received Uniqueid Channel Cause Cause-txt
     * Esta función también se invoca al cerrar todas las llamadas luego de
     * reiniciado Asterisk.
     */
    /* Processing of identified call: params requires the elements:
     * local_timestamp_received Uniqueid Channel Cause Cause-txt
     * This function is also invoked when closing all calls after
     * Asterisk restart. */
    private function _procesarLlamadaColgada($llamada, $params)
    {
        if (is_null($llamada->timestamp_link)) {
            /* Si se detecta el Hangup antes del OriginateResponse, se marca
             * la llamada como fallida y se deja de monitorear. */
            /* If Hangup is detected before OriginateResponse, the call is
             * marked as failed and monitoring stops. */
            $llamada->actualizarCausaFallo($params['Cause'], $params['Cause-txt']);
            $llamada->llamadaFinalizaSeguimiento(
                $params['local_timestamp_received'],
                $this->_config['dialer']['llamada_corta']);
        } else {
            if ($llamada->status == 'OnHold') {
                /* Un Hangup del canal del cliente estacionado mientras la llamada
                 * está OnHold significa que el cliente colgó realmente, aunque no
                 * haya llegado el evento ParkedCallGiveUp (p.ej. el retorno por
                 * timeout del parking marcó un destino inválido y Asterisk colgó
                 * el canal estacionado directamente, o el troncal envió BYE / cayó
                 * la red). Se finaliza la llamada igual que en msg_ParkedCallGiveUp;
                 * de lo contrario el registro queda atascado en current_call_entry /
                 * call_entry para siempre. Los Hangup de otros canales durante HOLD
                 * (pata del agente, canal auxiliar de park-dial fallido) se siguen
                 * ignorando. */
                /* A Hangup for the parked caller's own channel while the call is
                 * OnHold means the caller genuinely disconnected even though no
                 * ParkedCallGiveUp event arrived (e.g. the park timeout return
                 * dialed an invalid target and Asterisk hung up the parked channel
                 * directly, or the trunk sent BYE / the network dropped). Finalize
                 * the call as msg_ParkedCallGiveUp() does; otherwise the record
                 * stays stuck in current_call_entry / call_entry forever. Hangups
                 * for other channels during HOLD (agent leg, failed park-dial
                 * auxiliary channel) are still ignored. */
                if ($params['Channel'] == $llamada->actualchannel && !$llamada->atxfer_hold) {
                    $this->_log->output('INFO: '.__METHOD__.': cliente colgó mientras '.
                        'estaba en HOLD sin ParkedCallGiveUp, finalizando llamada | '.
                        'EN: caller hung up while OnHold with no ParkedCallGiveUp, '.
                        'finalizing call. channel='.$params['Channel'].
                        ' uniqueid='.$params['Uniqueid'].' call_uid='.$llamada->uniqueid);
                    /* Limpiar estado de hold (cierra el registro de auditoría de
                     * hold) y luego finalizar el seguimiento. | EN: clear hold state
                     * (closes the hold audit record) then finalize tracking. */
                    $llamada->llamadaRegresaHold($this->_ami, $params['local_timestamp_received']);
                    $llamada->llamadaFinalizaSeguimiento(
                        $params['local_timestamp_received'],
                        $this->_config['dialer']['llamada_corta']);
                } else if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': se ignora Hangup para llamada que se envía a HOLD. | EN: ignoring Hangup for call that is being sent to HOLD.');
                }
            } else {
                // If the agent is in attended transfer consultation, redirect
                // the agent to atxfer-complete to terminate the consultation
                // call automatically (customer hung up, no point continuing)
                $this->_terminarConsultaSiClienteCuelga($llamada);

                // Llamada ha sido enlazada al menos una vez
                // Call has been linked at least once
                $llamada->llamadaFinalizaSeguimiento(
                    $params['local_timestamp_received'],
                    $this->_config['dialer']['llamada_corta']);
            }
        }
    }

    /**
     * The agent's own channel just hung up for real while they were in an
     * attended-transfer consultation. For Agent type (app_agent_pool) that
     * is the login_channel; for callback type it is the device channel that
     * was Redirected into [cbxfer-consult]. This covers a genuine device
     * failure/disconnect, the classic "hang up your phone to complete the
     * transfer" mechanic, and the console's Complete Transfer button (which
     * redirects the agent's channel into [cbxfer-done] to hang up) - the
     * agent's own channel is truly gone in every case, so there is no
     * dialplan location left to run cleanup on it.
     *
     * If the colleague never answered, the held customer has nobody left to
     * talk to - hang them up and finalize the call normally. If the
     * colleague had already answered, they have been redirected into
     * atxfer-bridge and are (or are about to be) live with the customer, so
     * the customer must be left alone and the agent released without
     * finalizing: the call isn't marked "finished" while that conversation
     * is still happening, and finalizes normally once its real hangup
     * eventually arrives.
     */
    private function _manejarHangupLoginChannelEnConsulta($a, $llamada, $params)
    {
        $sAgente = $a->channel;
        $bAnswered = isset($this->_agentesConsultaContestada[$sAgente]);
        $this->_log->output('INFO: '.__METHOD__.": Agent $sAgente channel hung up during attended-transfer ".
            "consultation (answered=".($bAnswered ? 'yes' : 'no').") | EN/ES: agente $sAgente colgó su canal ".
            "durante consulta de transferencia atendida (contestada=".($bAnswered ? 'si' : 'no').")");

        // Clear consultation-tracking state up front, before any nested
        // processing (e.g. _procesarLlamadaColgada() below internally calls
        // _terminarConsultaSiClienteCuelga(), which only acts while these
        // are still set) - avoids a doomed Redirect attempt on this
        // already-dead channel and a duplicate ConsultationEnd emission.
        unset($this->_agentesEnConsultation[$sAgente]);
        unset($this->_agentesEnAtxferComplete[$sAgente]);
        unset($this->_agentesConsultaContestada[$sAgente]);

        if ($bAnswered) {
            /* El colega ya contestó: F() (o el Redirect doble de "Completar
             * transferencia") lo ha llevado a atxfer-bridge, donde queda
             * hablando con el cliente retenido. Colgar el canal del cliente
             * aquí destruiría precisamente la transferencia que se acaba de
             * completar, así que se deja vivo y sólo se libera al agente. La
             * llamada se finaliza sola, con su duración real, cuando esa
             * conversación termine de verdad. */
            /* EN: The colleague already answered: F() (or the "Complete
             * transfer" dual Redirect) has moved them into atxfer-bridge,
             * where they end up talking to the held customer. Hanging the
             * customer's channel up here would destroy the very transfer that
             * just completed, so leave it alive and only release the agent.
             * The call finalizes on its own, with its real duration, once
             * that conversation actually ends. */
            if (!is_null($llamada)) $llamada->llamadaTransferidaDesdeAgente($params['local_timestamp_received']);
        } else {
            // Colleague was still ringing - the customer is stuck in
            // atxfer-hold's MusicOnHold with nobody left to talk to.
            if (!is_null($llamada) && !empty($llamada->actualchannel)) {
                $this->_ami->Hangup($llamada->actualchannel);
            }
            if (!is_null($llamada)) $this->_procesarLlamadaColgada($llamada, $params);
        }

        $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
            array('ConsultationEnd', array($sAgente))
        ));

        /* Sólo para logins con canal de login (tipo Agent): ese canal está
         * muerto y no va a volver a entrar en AgentLogin, así que hay que
         * registrar el logoff real. Un agente callback NO tiene canal de
         * login: el canal que acaba de colgar era simplemente el de esta
         * llamada, y sigue conectado en la consola esperando la siguiente.
         * Desloguearlo aquí lo echaría de la consola sin motivo. */
        /* EN: Only for login-channel logins (Agent type): that channel is
         * dead and will never re-enter AgentLogin, so real logoff bookkeeping
         * has to run. A callback agent has NO login channel - the channel
         * that just hung up was merely this call's, and they are still
         * connected in the console waiting for the next one. Logging them off
         * here would kick them out of the console for no reason. */
        if (!empty($a->login_channel)) {
            $this->_ejecutarLogoffAgente($sAgente, $a, $params['local_timestamp_received'],
                'login_channel hangup during atxfer-consult');
        } else {
            $this->_log->output('DEBUG: '.__METHOD__.": $sAgente has no login_channel ".
                "(callback type) - console session preserved | ES: $sAgente no tiene canal de ".
                "login (tipo callback) - se conserva su sesión de consola");
        }
    }

    /**
     * When the customer hangs up during an attended transfer consultation,
     * terminate the consultation call so the agent is not left in the consult
     * dialplan with disabled buttons and no way to end it.
     *
     * Agent type (app_agent_pool) is redirected to atxfer-complete, which ends
     * the consultation and re-enters AgentLogin. A callback agent has no
     * AgentLogin session to return to, so its channel is redirected to
     * cbxfer-done, which simply hangs it up.
     */
    private function _terminarConsultaSiClienteCuelga($llamada)
    {
        if (is_null($llamada->agente)) return;

        $sAgente = $llamada->agente->channel;
        if (!isset($this->_agentesEnConsultation[$sAgente])) return;

        $bTipoAgent = ($llamada->agente->type == 'Agent');

        if ($bTipoAgent) {
            $sCanalAgente = $llamada->agente->login_channel;
            $sExten       = $llamada->agente->number;
            $sContexto    = 'atxfer-complete';
            if (empty($sCanalAgente)) {
                $this->_log->output('WARN: '.__METHOD__.': Agent '.$sAgente.
                    ' is in consultation but has no login_channel, cannot redirect'.
                    ' | EN: Agent '.$sAgente.' is in consultation but has no login_channel, cannot redirect');
                return;
            }
            // Set the atxfer completion flag to suppress Agentlogoff
            $this->_prepararAtxferComplete($sAgente);
        } else {
            /* Agente callback: el canal a sacar de la consulta es el de esta
             * llamada. Debe ser el canal real con identificador único; el
             * accesor cae a _agentchannel (p.ej. "SIP/1002" a secas), que
             * Asterisk no puede redirigir. */
            /* EN: Callback agent: the channel to pull out of the consultation
             * is this call's own. It must be the real channel with its unique
             * id; the accessor falls back to _agentchannel (a bare
             * "SIP/1002"), which Asterisk cannot redirect. */
            $sCanalAgente = $llamada->actualAgentChannel;
            $sExten       = 's';
            $sContexto    = 'cbxfer-done';
            if (empty($sCanalAgente) || strpos($sCanalAgente, '-') === FALSE) {
                $this->_log->output('WARN: '.__METHOD__.': callback agent '.$sAgente.
                    ' is in consultation but has no usable channel ('.
                    var_export($sCanalAgente, TRUE).'), cannot redirect'.
                    ' | ES: agente callback '.$sAgente.' está en consulta pero no tiene '.
                    'canal utilizable, no se puede redirigir');
                $sCanalAgente = NULL;
            }
        }

        $this->_log->output('INFO: '.__METHOD__.': Customer hung up while agent '.
            $sAgente.' is in consultation - redirecting '.$sCanalAgente.' to '.$sContexto.
            ' to end consulting call | ES: el cliente colgó mientras el agente '.
            $sAgente.' estaba en consulta - se redirige '.$sCanalAgente.' a '.$sContexto);

        $r = is_null($sCanalAgente)
            ? array('Response' => 'Failure', 'Message' => 'no usable agent channel')
            : $this->_ami->Redirect(
                $sCanalAgente,        // Channel: agent's login or call channel
                '',                   // ExtraChannel: not used
                $sExten,              // Exten: agent number, or 's'
                $sContexto,           // Context
                1                     // Priority
            );

        if ($r['Response'] == 'Success') {
            $this->_log->output('DEBUG: '.__METHOD__.': Redirect successful for '.$sAgente.
                ' | EN: Redirect successful for '.$sAgente);
        } else {
            $this->_log->output('WARN: '.__METHOD__.': Redirect failed for '.$sAgente.
                ': '.$r['Message'].' | EN: Redirect failed for '.$sAgente.': '.$r['Message']);
        }

        // Clear consultation state and emit ConsultationEnd
        unset($this->_agentesEnConsultation[$sAgente]);
        unset($this->_agentesConsultaContestada[$sAgente]);
        // Customer is gone and the call is about to be finalized as a normal
        // hangup - clear any transfer stamped at consult-start so reports
        // don't show this call as transferred to a colleague who never took it.
        $this->_limpiarTransferPendiente($llamada);
        $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
            array('ConsultationEnd', array($sAgente))
        ));
    }

    public function msg_Agentlogin($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        // Verificar que este evento corresponde a un Agentlogin iniciado por este programa
        // Verify that this event corresponds to an Agentlogin initiated by this program
        $sAgente = 'Agent/'.$params['Agent'];
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);

        // Check if this is a re-login after attended transfer completion
        $isAtxferRelogin = isset($this->_agentesEnAtxferComplete[$sAgente]);
        if ($isAtxferRelogin) {
            $this->_log->output('DEBUG: '.__METHOD__.': Agente '.$sAgente.
                ' re-ingresando AgentLogin después de completar transferencia atendida | EN: Agent '.$sAgente.
                ' re-entering AgentLogin after attended transfer completion');
            // Clear the atxfer flag
            unset($this->_agentesEnAtxferComplete[$sAgente]);
            // Clear stale consultation state - when the agent completes a transfer
            // by hanging up during Dial(), the ConsultationEnd UserEvent in the
            // dialplan never fires (agent channel is in hangup state). Clear it here.
            if (isset($this->_agentesEnConsultation[$sAgente])) {
                $this->_log->output('DEBUG: '.__METHOD__.': clearing stale consultation state for '.$sAgente.
                    ' after attended transfer completion');
                unset($this->_agentesEnConsultation[$sAgente]);
                unset($this->_agentesConsultaContestada[$sAgente]);
                $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
                    array('ConsultationEnd', array($sAgente))
                ));
            }
        }

        if (is_null($a) || $a->estado_consola == 'logged-out') {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": AgentLogin($sAgente) no iniciado por programa, no se hace nada. | EN: AgentLogin($sAgente) not initiated by program, no action taken.");
                $this->_log->output("DEBUG: ".__METHOD__.": SALIDA OnAgentlogin | EN: EXIT OnAgentlogin");
            }
            return FALSE;
        }
        // Capture the login channel from the Agentlogin event (app_agent_pool)
        // This is the actual channel running AgentLogin (e.g., SIP/101-00000xxx)
        $sLoginChannel = isset($params['Channel']) ? $params['Channel'] : NULL;
        $a->completarLoginAgente($this->_ami, $sLoginChannel);

        // For Agent type: emit queue membership event immediately after login
        // This ensures the agent appears in campaign monitoring right away
        // Agent may already be in queues (sessions persist across dialer restarts)
        if ($a->type == 'Agent') {
            $colas_act = $a->colas_actuales;
            $this->_log->output('INFO: agente '.$sAgente.' login - colas_actuales=['.implode(' ', $colas_act).'] | EN: agent '.$sAgente.' login - current_queues=['.implode(' ', $colas_act).']');

            // Emit queue membership event to notify campaign monitoring that agent is online
            if (count($colas_act) > 0) {
                $a->nuevaMembresiaCola();
            }
        }

        // If this was a re-login after atxfer, log success
        if ($isAtxferRelogin) {
            $this->_log->output('INFO: '.__METHOD__.': Agente '.$sAgente.
                ' re-logoneado exitosamente después de completar transferencia atendida. Sesión preservada. | EN: Agent '.$sAgente.
                ' successfully re-logged in after attended transfer completion. Session preserved.');
        }
    }

    public function msg_Agentlogoff($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        // Verificar que este evento corresponde a un Agentlogin iniciado por este programa
        // Verify that this event corresponds to an Agentlogin initiated by this program
        $sAgente = 'Agent/'.$params['Agent'];
        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (is_null($a)) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": AgentLogin($sAgente) no iniciado por programa, no se hace nada. | EN: AgentLogin($sAgente) not initiated by program, no action taken.");
                $this->_log->output("DEBUG: ".__METHOD__.": SALIDA OnAgentlogoff | EN: EXIT OnAgentlogoff");
            }
            return FALSE;
        }

        // Check if this agent is completing an attended transfer (app_agent_pool only)
        // If so, suppress the logoff - the agent will re-enter AgentLogin
        if (!is_null($this->_compat) && $this->_compat->hasAppAgentPool()
            && isset($this->_agentesEnAtxferComplete[$sAgente])) {
            $this->_log->output('DEBUG: '.__METHOD__.': SUPRIMIENDO Agentlogoff para '.$sAgente.
                ' - agente está completando transferencia atendida y re-ingresará AgentLogin | EN: SUPPRESSING Agentlogoff for '.$sAgente.
                ' - agent is completing attended transfer and will re-enter AgentLogin');
            // Keep the flag set - it will be cleared when Agentlogin fires
            return FALSE;
        }

        $this->_ejecutarLogoffAgente($sAgente, $a, $params['local_timestamp_received'], $params['Event']);

        if ($this->_finalizandoPrograma) $this->_verificarFinalizacionLlamadas();

        return FALSE;
    }

    public function msg_PeerStatus($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        if ($params['PeerStatus'] == 'Unregistered') {
            // Alguna extensión se ha desregistrado. Verificar si es un agente logoneado
            // Some extension has unregistered. Verify if it is a logged-in agent
            $a = $this->_listaAgentes->buscar('extension', $params['Peer']);
            if (!is_null($a)) {
                // La extensión usada para login se ha desregistrado - deslogonear al agente
                // The extension used for login has unregistered - log off the agent
                $this->_log->output('INFO: '.__METHOD__.' se detecta desregistro de '.
                    $params['Peer'].' - deslogoneando '.$a->channel.'... | EN: unregistration detected for '.
                    $params['Peer'].' - logging off '.$a->channel.'...');
                $a->forzarLogoffAgente($this->_ami, $this->_log);
            }
    	}
    }

    public function msg_QueueParams($sEvent, $params, $sServer, $iPort)
    {
        /*
        [Event] => QueueParams
        [Queue] => 8001
        [Max] => 0
        [Strategy] => ringall
        [Calls] => 0
        [Holdtime] => 0
        [TalkTime] => 0
        [Completed] => 0
        [Abandoned] => 0
        [ServiceLevel] => 60
        [ServicelevelPerf] => 0.0
        [Weight] => 0
        [ActionID] => QueueStatus-4899-1456607980
        */
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
            );
        }

        if (is_null($this->_tmp_actionid_queuestatus)) return;
        if ($params['ActionID'] != $this->_tmp_actionid_queuestatus) return;

        $this->_queueshadow->msg_QueueParams($params);
    }

    public function msg_QueueMember($sEvent, $params, $sServer, $iPort)
    {
        /*
        Event: QueueMember
        Queue: 8001
        Name: Agent/9000
        Location: Agent/9000
        StateInterface: Agent/9000
        Membership: static
        Penalty: 0
        CallsTaken: 0
        LastCall: 0
        Status: 5
        Paused: 0
        ActionID: gato
         */
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
            );
        }

        if (is_null($this->_tmp_actionid_queuestatus)) return;
        if ($params['ActionID'] != $this->_tmp_actionid_queuestatus) return;

        $params['Location'] = isset($params['Location'])?$params['Location']:$params['Interface'];  // Since Asterisk 13 we have Interface and no Location

        $this->_queueshadow->msg_QueueMember($params);

        /* Se debe usar Location porque Name puede ser el nombre amistoso */
        $this->_tmp_estadoAgenteCola[$params['Location']][$params['Queue']] = array(
            'Status'    =>  $params['Status'],
            'Paused'    =>  ($params['Paused'] != 0),
        );
    }

    public function msg_QueueEntry($sEvent, $params, $sServer, $iPort)
    {
        /*
         Event: QueueEntry
         Queue: 8000
         Position: 1
         Channel: SIP/1064-00000000
         Uniqueid: 1378401225.0
         CallerIDNum: 1064
         CallerIDName: Alex
         ConnectedLineNum: unknown
         ConnectedLineName: unknown
         Wait: 40
         */
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
            );
        }

        if (is_null($this->_tmp_actionid_queuestatus)) return;
        if ($params['ActionID'] != $this->_tmp_actionid_queuestatus) return;

        $this->_queueshadow->msg_QueueEntry($params);
    }

    public function msg_QueueStatusComplete($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
            );
        }

        if (is_null($this->_tmp_actionid_queuestatus)) return;
        if ($params['ActionID'] != $this->_tmp_actionid_queuestatus) return;

        /* Finalizó la enumeración. Ahora se puede actualizar el estado de los
         * agentes de forma atómica.
         */
        $this->_queueshadow->msg_QueueStatusComplete($params);
        $this->_tmp_actionid_queuestatus = NULL;
        foreach ($this->_tmp_estadoAgenteCola as $sAgente => $estadoCola) {

            // Normalize agent queue interface to canonical Agent/XXXX for lookup
            if (!is_null($this->_compat)) {
                $sNormalized = $this->_compat->normalizeAgentFromInterface($sAgente);
                if (!is_null($sNormalized)) $sAgente = $sNormalized;
            }

            $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
            if (!is_null($a)) {
                $this->_evaluarPertenenciaColas($a, $estadoCola);
            } else {
                if ($this->DEBUG) {
                    $this->_log->output('WARN: agente '.$sAgente.' no es un agente registrado en el callcenter, se ignora | EN: agent '.$sAgente.' is not a registered agent in the callcenter, ignoring');
                }
            }
        }

        /* Verificación de agentes que estén logoneados y deban tener colas,
         * pero no aparecen en la enumeración de miembros de colas. */
        /* Verification of agents that are logged in and should have queues,
         * but do not appear in the queue member enumeration. */
        foreach ($this->_listaAgentes as $a) {
            // Derive queue interface using compat (Agent/XXXX on Ast11, Local/XXXX@agents on Ast12+)
            $sInterface = $a->channel;
            if ($a->type == 'Agent' && !is_null($this->_compat)) {
                $sInterface = $this->_compat->getAgentQueueInterface($a->number);
            }
            if (!isset($this->_tmp_estadoAgenteCola[$sInterface])) {
                $this->_evaluarPertenenciaColas($a, array());
            }
        }

        if ($this->DEBUG) $this->_log->output("DEBUG: fin de verificación de pertenencia a colas con QueueStatus. | EN: end of queue membership verification with QueueStatus.");
        $this->_tmp_estadoAgenteCola = NULL;

        $this->_iniciarAgents();
    }

    private function _evaluarPertenenciaColas($a, $estadoCola)
    {
        // Separar Status y Paused
        // Separate Status and Paused
        $estadoCola_Status = array();
        $estadoCola_Paused = array();
        foreach ($estadoCola as $cola => $tupla) {
            $estadoCola_Status[$cola] = $tupla['Status'];
            $estadoCola_Paused[$cola] = $tupla['Paused'];
        }

        // Para agentes estáticos, cambio de membresía debe reportarse
        // For static agents, membership change must be reported
        $bCambioColas = $a->asignarEstadoEnColas($estadoCola_Status);
        if ($bCambioColas && $a->type == 'Agent') $a->nuevaMembresiaCola();
        $bAgentePausado = ($a->num_pausas > 0);

        $sAgente = $a->channel;
        $this->_log->output('INFO: evaluar pertenencia agente '.$sAgente.' tiene estado consola '.$a->estado_consola.' | EN: evaluate membership agent '.$sAgente.' has console state '.$a->estado_consola);

        if ($a->estado_consola == 'logged-in') {
            // Revisar y sincronizar estado de pausa en colas
            // Review and synchronize pause status in queues
            foreach ($estadoCola_Paused as $cola => $p) {
                if ($bAgentePausado && !$p) {
                    $this->_log->output('INFO: agente '.$sAgente.' debe estar pausado pero no está en pausa en cola '.$cola.' | EN: agent '.$sAgente.' should be paused but is not paused in queue '.$cola);
                    $a->asyncQueuePause($this->_ami, TRUE, $cola);
                } elseif (!$bAgentePausado && $p) {
                    $this->_log->output('INFO: agente '.$sAgente.' debe estar despausado pero está en pausa en cola '.$cola.' | EN: agent '.$sAgente.' should be unpaused but is paused in queue '.$cola);
                    $a->asyncQueuePause($this->_ami, FALSE, $cola);
                }
            }

            $diffcolas = $a->diferenciaColasDinamicas();
            if (is_array($diffcolas)) {

                // Colas a las que no pertenece y debería pertenecer
                // Queues to which it does not belong but should belong
                if (count($diffcolas[0]) > 0) {
                    $this->_log->output('INFO: agente '.$sAgente.' debe ser '.
                        'agregado a las colas ['.implode(' ', array_keys($diffcolas[0])).'] | EN: agent '.$sAgente.' must be added to queues ['.implode(' ', array_keys($diffcolas[0])).']');
                    foreach ($diffcolas[0] as $q => $p) {
                        if ($a->type == 'Agent' && !is_null($this->_compat)) {
                            $interface = $this->_compat->getAgentQueueInterface($a->number);
                            $stateInterface = $this->_compat->getAgentStateInterface($a->number);
                            $this->_ami->asyncQueueAdd(
                                array($this, '_cb_QueueAdd'),
                                NULL,
                                $q, $interface, $p, $a->name, $bAgentePausado, $stateInterface);
                        } else {
                            // SIP/IAX2/PJSIP: direct channel interface
                            $this->_ami->asyncQueueAdd(
                                array($this, '_cb_QueueAdd'),
                                NULL,
                                $q, $sAgente, $p, $a->name, $bAgentePausado);
                        }
                    }
                }

                // Colas a las que pertenece y no debe pertenecer
                // Queues to which it belongs and should not belong
                if (count($diffcolas[1]) > 0) {
                    $this->_log->output('INFO: agente '.$sAgente.' debe ser '.
                        'quitado de las colas ['.implode(' ', $diffcolas[1]).'] | EN: agent '.$sAgente.' must be removed from queues ['.implode(' ', $diffcolas[1]).']');
                    foreach ($diffcolas[1] as $q) {
                        if ($a->type == 'Agent' && !is_null($this->_compat)) {
                            $interface = $this->_compat->getAgentQueueInterface($a->number);
                            $this->_ami->asyncQueueRemove(
                                array($this, '_cb_QueueRemove'),
                                NULL,
                                $q, $interface);
                        } else {
                            $this->_ami->asyncQueueRemove(
                                array($this, '_cb_QueueRemove'),
                                NULL,
                                $q, $sAgente);
                        }
                    }
                }
            }
        } else {
            // El agente dinámico no debería estar metido en ninguna de las colas
            // The dynamic agent should not be in any of the queues
            if ($a->type != 'Agent') {
                $diffcolas = array_intersect($a->colas_actuales, $a->colas_dinamicas);
                if (count($diffcolas) > 0) {
                    $this->_log->output('INFO: agente DESLOGONEADO '.$sAgente.' debe ser '.
                        'quitado de las colas ['.implode(' ', $diffcolas).'] | EN: LOGGED-OUT agent '.$sAgente.' must be removed from queues ['.implode(' ', $diffcolas).']');
                    foreach ($diffcolas as $q) {
                        $this->_ami->asyncQueueRemove(
                            array($this, '_cb_QueueRemove'),
                            NULL,
                            $q, $sAgente);
                    }
                }
            }
        }
    }

    public function _cb_QueueAdd($r)
    {
        if ($r['Response'] != 'Success') {
            $this->_log->output("ERR: falla al agregar a cola: ".print_r($r, TRUE)." | EN: failed to add to queue: ".print_r($r, TRUE));
        }
    }

    public function _cb_QueueRemove($r)
    {
        if ($r['Response'] != 'Success') {
            $this->_log->output("ERR: falla al quitar de cola: ".print_r($r, TRUE)." | EN: failed to remove from queue: ".print_r($r, TRUE));
        }
    }

    // En Asterisk 11 e inferior, este evento se emite sólo si eventmemberstatus
    // In Asterisk 11 and lower, this event is only emitted if eventmemberstatus
    // está seteado en la cola respectiva.
    // is set in the respective queue.
    public function msg_QueueMemberStatus($sEvent, $params, $sServer, $iPort)
    {
        // === TIMESTAMP TRACKER: QueueMemberStatus Event Entry ===
        $fQmsMicrotime = microtime(TRUE);
        $fQmsTime = date('Y-m-d H:i:s.', (int)$fQmsMicrotime) . sprintf('%03d', ($fQmsMicrotime - (int)$fQmsMicrotime) * 1000);
        $sInterface = isset($params['Location']) ? $params['Location'] : (isset($params['Interface']) ? $params['Interface'] : 'UNKNOWN');
        $iStatus = isset($params['Status']) ? $params['Status'] : -1;
        $this->_log->output("TIMING: ".__METHOD__.": [QMS_EVENT] Interface=$sInterface, Queue=".$params['Queue'].", Status=$iStatus, microtime=$fQmsMicrotime, time=$fQmsTime | ES: Evento QueueMemberStatus recibido");

        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
            );
        }

        $params['Location'] = isset($params['Location'])?$params['Location']:$params['Interface'];  // Since Asterisk 13 we have Interface and no Location

        $this->_queueshadow->msg_QueueMemberStatus($params);

        $sAgente = $params['Location'];

        // Normalize agent queue interface to canonical Agent/XXXX for lookup
        if (!is_null($this->_compat)) {
            $sNormalized = $this->_compat->normalizeAgentFromInterface($sAgente);
            if (!is_null($sNormalized)) $sAgente = $sNormalized;
        }

        $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
        if (!is_null($a)) {
            // === TIMESTAMP TRACKER: Before updating agent queue status ===
            $bHasCall = !is_null($a->llamada);
            $this->_log->output("TIMING: ".__METHOD__.": [BEFORE_UPDATE] Agent=$sAgente, has_internal_call=".($bHasCall?'YES':'NO').", queue_status=$iStatus | ES: Antes de actualizar estado en cola");

            // TODO: existe $params['Paused'] que indica si está en pausa
            // TODO: there is $params['Paused'] that indicates if paused
            $a->actualizarEstadoEnCola($params['Queue'], $params['Status']);

            // === TIMESTAMP TRACKER: After updating agent queue status ===
            $fAfterUpdate = microtime(TRUE);
            $this->_log->output("TIMING: ".__METHOD__.": [AFTER_UPDATE] Agent=$sAgente, queue={$params['Queue']}, status=$iStatus, elapsed_ms=".round(($fAfterUpdate - $fQmsMicrotime) * 1000, 3)." | ES: Después de actualizar estado en cola");

            // If target agent has pending transfer reservation and device state resolved, clear it
            // Si el agente destino tiene reserva de transferencia pendiente y el estado del dispositivo se resolvió, limpiarla
            if (isset($this->_agentesEnTransferPendiente[$sAgente])) {
                if ($params['Status'] == AST_DEVICE_INUSE || $params['Status'] == AST_DEVICE_NOT_INUSE) {
                    $info = $this->_agentesEnTransferPendiente[$sAgente];
                    $this->_cancelarAlarma($info['alarm_key']);
                    unset($this->_agentesEnTransferPendiente[$sAgente]);
                    $this->_log->output('INFO: '.__METHOD__.": Transfer reservation cleared for target=$sAgente via QueueMemberStatus (Status={$params['Status']}) | ES: Reserva de transferencia limpiada para destino=$sAgente via QueueMemberStatus (Status={$params['Status']})");
                }
            }
        } else {
            if ($this->DEBUG) {
                $this->_log->output('WARN: agente '.$sAgente.' no es un agente registrado en el callcenter, se ignora | EN: agent '.$sAgente.' is not a registered agent in the callcenter, ignoring');
            }
        }
    }

    public function msg_QueueCallerAbandon($sEvent, $params, $sServer, $iPort)
    {
        /*
            [Event] => QueueCallerAbandon
            [Privilege] => agent,all
            [Queue] => 8010
            [Uniqueid] => 1459286416.10
            [Position] => 1
            [OriginalPosition] => 1
            [HoldTime] => 60
            [local_timestamp_received] => 1459286477.4821
         */
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        $llamada = $this->_listaLlamadas->buscar('uniqueid', $params['Uniqueid']);
        if (is_null($llamada)) return;

        /* TODO: el comportamiento de finalizar seguimiento sólo es adecuado si
         * no hay ninguna cola enlazada como destino en caso de fallo, o si la
         * cola enlazada corresponde a una campaña entrante. La asignación a otra
         * cola de campaña saliente NO ESTÁ SOPORTADA. */
        /* TODO: the behavior of ending tracking is only appropriate if
         * there is no linked queue as destination in case of failure, or if the
         * linked queue corresponds to an incoming campaign. Assignment to another
         * outgoing campaign queue IS NOT SUPPORTED. */
        $llamada->llamadaFinalizaSeguimiento(
            $params['local_timestamp_received'],
            $this->_config['dialer']['llamada_corta']);
    }

    public function msg_Leave($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
            );
        }

        if (!$this->_queueshadow->msg_Leave($params)) {
            $this->_log->output('ERR: número de llamadas en espera fuera de sincronía, se intenta refrescar... | EN: number of calls on hold out of sync, trying to refresh...');
            $this->_tuberia->msg_SQLWorkerProcess_requerir_nuevaListaAgentes();
        }
    }

    public function msg_Reload($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
            );
        }

        $this->_log->output('INFO: se ha recargado configuración de Asterisk, se refresca agentes... | EN: Asterisk configuration has been reloaded, refreshing agents...');
        $this->_tuberia->msg_SQLWorkerProcess_requerir_nuevaListaAgentes();
    }

    public function msg_Agents($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        if (is_null($this->_tmp_actionid_agents)) return;
        if ($params['ActionID'] != $this->_tmp_actionid_agents) return;

        $this->_tmp_estadoLoginAgente[$params['Agent']] = array(
            'Status'        =>  $params['Status'],
            'TalkingToChan' =>  isset($params['TalkingToChan']) ? $params['TalkingToChan'] : '',
        );
    }

    public function msg_AgentsComplete($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        if (is_null($this->_tmp_actionid_agents)) return;
        if ($params['ActionID'] != $this->_tmp_actionid_agents) return;

        foreach ($this->_tmp_estadoLoginAgente as $sAgentNum => $agentdata) {
            $sAgente = 'Agent/'.$sAgentNum;
            $sAgentStatus = $agentdata['Status'];
            $a = $this->_listaAgentes->buscar('agentchannel', $sAgente);
            if (!is_null($a)) {
                if ($sAgentStatus == 'AGENT_LOGGEDOFF') {
                    /* Según Asterisk, el agente está deslogoneado. Se verifica
                     * si también es así en el estado del objeto Agente. Si no,
                     * se lo manda a deslogonear.
                     *
                     * ATENCIÓN: el estado intermedio durante el cual se introduce
                     * la contraseña se ve como AGENT_LOGGEDOFF y no debe de
                     * tocarse.
                     */

                    if ($a->estado_consola == 'logged-in') {
                        if (isset($this->_agentesEnConsultation[$sAgente]) ||
                                isset($this->_agentesEnAtxferComplete[$sAgente]) ||
                                isset($this->_agentesConsultaContestada[$sAgente])) {
                            /* El agente está en medio de una transferencia atendida:
                             * su login_channel fue deliberadamente sacado (Redirect)
                             * del bridge con AgentLogin(), asi que Asterisk reporta
                             * AGENT_LOGGEDOFF de forma legitima pero temporal. No es
                             * un logoff real - no tocar (mismo espiritu que la
                             * excepcion de ingreso de clave, arriba). */
                            /* EN: The agent is in the middle of an attended-transfer
                             * consultation: its login_channel was deliberately
                             * Redirected out of the AgentLogin() bridge, so Asterisk
                             * legitimately but temporarily reports AGENT_LOGGEDOFF.
                             * This is not a real logoff - do not touch it (same spirit
                             * as the password-entry exception above). */
                            if ($this->DEBUG) {
                                $this->_log->output('DEBUG: '.__METHOD__.' agente '.$sAgente.
                                    ' reporta AGENT_LOGGEDOFF pero esta en transferencia'.
                                    ' atendida, se ignora. | EN: agent '.$sAgente.
                                    ' reports AGENT_LOGGEDOFF but is in an attended'.
                                    ' transfer, ignoring.');
                            }
                        } else {
                            $this->_log->output('WARN: '.__METHOD__.' agente '.$sAgente.
                                ' está logoneado en dialer pero en estado AGENT_LOGGEDOFF,'.
                                ' se deslogonea en dialer... | EN: agent '.$sAgente.
                                ' is logged in dialer but in state AGENT_LOGGEDOFF,'.
                                ' logging off in dialer...');
                            $this->_ejecutarLogoffAgente($sAgente, $a, $params['local_timestamp_received'], $params['Event']);
                        }
                    }
                } else {
                    /* Según Asterisk, el agente está logoneado. Se verifica si
                     * el estado de agente es logoneado, y si no, se lo
                     * deslogonea.
                     *
                     * ATENCIÓN: si el agente está logoneado, puede que el valor
                     * de estado_consola sea 'logging', el cual no debe de
                     * tocarse porque todavía no llega el evento Agentlogin.
                     * */
                    if ($a->estado_consola == 'logged-out') {
                        $this->_log->output('WARN: '.__METHOD__.' agente '.$sAgente.
                            ' está deslogoneado en dialer pero en estado '.$sAgentStatus.','.
                            ' se deslogonea en Asterisk... | EN: agent '.$sAgente.
                            ' is logged out in dialer but in state '.$sAgentStatus.','.
                            ' logging off in Asterisk...');
                        $a->forzarLogoffAgente($this->_ami, $this->_log);
                    } elseif ($a->estado_consola == 'logged-in' && $sAgentStatus == 'AGENT_ONCALL') {
                        if (is_null($a->llamada)) {
                            $this->_log->output('WARN: '.__METHOD__.' agente '.$sAgente.
                                ' en llamada con canal '.$agentdata['TalkingToChan'].
                                ' pero no hay (todavía) llamada monitoreada. | EN: agent '.$sAgente.
                                ' on call with channel '.$agentdata['TalkingToChan'].
                                ' but there is no (yet) monitored call.');
                        } else {
                            if ($this->DEBUG) {
                                if (!is_null($a->llamada->actualchannel)) {
                                    $this->_log->output('DEBUG: '.__METHOD__.': canal esperado '.
                                        $a->llamada->actualchannel.' real '.$agentdata['TalkingToChan'].
                                        ' | EN: expected channel '.$a->llamada->actualchannel.
                                        ' actual '.$agentdata['TalkingToChan']);
                                }
                            }
                            if (is_null($a->llamada->actualchannel) &&
                                strpos($agentdata['TalkingToChan'], 'Local/') === 0) {
                                $this->_log->output('WARN: '.__METHOD__.": el agente ".
                                    "$sAgente está hablando con canal ".$agentdata['TalkingToChan'].
                                    " según eventos Agents. | EN: agent ".
                                    "$sAgente is talking on channel ".$agentdata['TalkingToChan'].
                                    " according to Agents events.");
                            }
                            if (!is_null($a->llamada->actualchannel) &&
                                $a->llamada->actualchannel != $agentdata['TalkingToChan'] &&
                                !is_null($a->llamada->channel) &&
                                $a->llamada->channel != $agentdata['TalkingToChan']) {
                                $this->_log->output('WARN: '.__METHOD__.
                                    ': llamada con canal remoto recogido en Link auxiliar fue '.
                                    $a->llamada->actualchannel.' pero realmente es '.$agentdata['TalkingToChan'].
                                    ' | EN: call with remote channel picked up in auxiliary Link was '.
                                    $a->llamada->actualchannel.' but actually is '.$agentdata['TalkingToChan']);
                                $a->llamada->dump($this->_log);
                            }

                            /* Se asigna actualchannel si actualchannel es NULL o
                             * si el valor es distinto de channel. El estado en el
                             * que TalkingToChan es distinto de channel y actualchannel
                             * se avisa arriba. */
                            if (is_null($a->llamada->actualchannel) ||
                                (!is_null($a->llamada->channel) && $a->llamada->channel != $agentdata['TalkingToChan'])) {
                                $a->llamada->actualchannel = $agentdata['TalkingToChan'];
                            }
                        }
                    }
                }
            } else {
                if ($this->DEBUG) {
                    $this->_log->output('WARN: '.__METHOD__.' agente '.$sAgente.' no es un agente registrado en el callcenter, se ignora | EN: agent '.$sAgente.' is not a registered agent in the callcenter, ignoring');
                }
            }
        }

        $this->_tmp_estadoLoginAgente = NULL;
        $this->_tmp_actionid_agents = NULL;
    }

    public function msg_QueueMemberPaused($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        $this->_queueshadow->msg_QueueMemberPaused($params);
    }

    public function msg_AgentCalled($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        $this->_queueshadow->msg_AgentCalled($params);
    }

    public function msg_AgentDump($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        $this->_queueshadow->msg_AgentDump($params);
    }

    public function msg_AgentConnect($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        $this->_queueshadow->msg_AgentConnect($params);
    }

    public function msg_AgentComplete($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        $this->_queueshadow->msg_AgentComplete($params);
    }

    public function msg_ParkedCall($sEvent, $params, $sServer, $iPort)
    {
        // Debug: Log ParkedCall event entry
        $this->_log->output("DEBUG_HOLD: [" . date('Y-m-d H:i:s.') . substr(microtime(), 2, 3) .
            "] ParkedCall event received - ParkeeChannel=" .
            (isset($params['ParkeeChannel']) ? $params['ParkeeChannel'] :
             (isset($params['Channel']) ? $params['Channel'] : 'NULL')) .
            " ParkingSpace=" . (isset($params['ParkingSpace']) ? $params['ParkingSpace'] :
             (isset($params['Exten']) ? $params['Exten'] : 'NULL')));
/*
    [Event] => ParkedCall
    [Privilege] => call,all
    [Exten] => 71
    [Channel] => SIP/1065-00000007
    [Parkinglot] => default
    [From] => SIP/1064-00000008
    [Timeout] => 180
    [CallerIDNum] => 1065
    [CallerIDName] => WinXP
    [ConnectedLineNum] => 1064
    [ConnectedLineName] => Alex Villacis Lasso
    [Uniqueid] => 1459123412.11
    [local_timestamp_received] => 1459123412.7244
 */
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        // Asterisk ParkedCall event uses 'ParkeeChannel', not 'Channel'
        $sCanalLlamada = isset($params['ParkeeChannel']) ? $params['ParkeeChannel'] :
                         (isset($params['Channel']) ? $params['Channel'] : NULL);

        if (is_null($sCanalLlamada)) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": ParkeeChannel no encontrado en params | EN: ParkeeChannel not found in params");
            }
            return;
        }

        $llamada = $this->_listaLlamadas->buscar('actualchannel', $sCanalLlamada);
        if (is_null($llamada)) {
            $this->_log->output("DEBUG_HOLD: [" . date('Y-m-d H:i:s.') . substr(microtime(), 2, 3) .
                "] ParkedCall - Call NOT FOUND by actualchannel=$sCanalLlamada");
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: ".__METHOD__.": llamada no encontrada para canal: $sCanalLlamada | EN: call not found for channel: $sCanalLlamada");
            }
            return;
        }

        // Debug: Log call found in ParkedCall
        $this->_log->output("DEBUG_HOLD: [" . date('Y-m-d H:i:s.') . substr(microtime(), 2, 3) .
            "] ParkedCall - Found call! actualchannel={$llamada->actualchannel}" .
            " status={$llamada->status} request_hold=" . ($llamada->request_hold ? 'TRUE' : 'FALSE'));

        // Asterisk ParkedCall event uses ParkingSpace (not Exten) and ParkeeUniqueid (not Uniqueid)
        $parkingSpace = isset($params['ParkingSpace']) ? $params['ParkingSpace'] :
                       (isset($params['Exten']) ? $params['Exten'] : NULL);
        $parkeeUniqueid = isset($params['ParkeeUniqueid']) ? $params['ParkeeUniqueid'] :
                         (isset($params['Uniqueid']) ? $params['Uniqueid'] : NULL);

        if ($this->DEBUG) {
            $this->_log->output("DEBUG: ".__METHOD__.": identificada llamada ".
                "enviada a HOLD {$llamada->actualchannel} en parkinglot ".
                "$parkingSpace, cambiado Uniqueid a $parkeeUniqueid ".
                "| EN: identified call sent to HOLD {$llamada->actualchannel} in parkinglot ".
                "$parkingSpace, changed Uniqueid to $parkeeUniqueid ");
        }
        $llamada->llamadaEnviadaHold($parkingSpace, $parkeeUniqueid);

        // TODO: Timeout podría usarse para mostrar un cronómetro
        // TODO: Timeout could be used to show a timer
    }
/*
    public function msg_ParkedCallTimeOut($sEvent, $params, $sServer, $iPort)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }


    }
*/
    public function msg_ParkedCallGiveUp($sEvent, $params, $sServer, $iPort)
    {
/*
    [Event] => ParkedCallGiveUp
    [Privilege] => call,all
    [Exten] => 71
    [Channel] => SIP/1071-00000003
    [Parkinglot] => default
    [CallerIDNum] => 1071
    [CallerIDName] => A Cuenta SIP
    [ConnectedLineNum] => 1064
    [ConnectedLineName] => Alex
    [UniqueID] => 1459187104.6
    [local_timestamp_received] => 1459187117.4845
 */
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.
                "\nretraso/delay => ".(microtime(TRUE) - $params['local_timestamp_received']).
                "\nrecibido/received $sEvent: => ".print_r($params, TRUE)
                );
        }

        // AMI 13+ uses ParkeeUniqueid, older versions use UniqueID
        $uniqueid = isset($params['ParkeeUniqueid']) ? $params['ParkeeUniqueid'] : $params['UniqueID'];
        $llamada = $this->_listaLlamadas->buscar('uniqueid', $uniqueid);
        if (is_null($llamada)) return;

        if ($llamada->status == 'OnHold') {
            // Clear the hold state and close the hold audit record
            $llamada->llamadaRegresaHold($this->_ami, $params['local_timestamp_received']);

            if ($llamada->atxfer_hold) {
                // Bridge() in atxfer-unhold is retrieving the call from parking.
                // Do NOT finalize - the call is being reconnected, not hung up.
                $llamada->atxfer_hold = FALSE;
                $this->_log->output('DEBUG: '.__METHOD__.': atxfer hold recovery - skipping finalization (Bridge will reconnect)');
            } else {
                // Genuine caller hangup while on hold - finalize the call
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': llamada colgada mientras estaba en HOLD. | EN: call hung up while on HOLD.');
                }
                $llamada->llamadaFinalizaSeguimiento(
                    $params['local_timestamp_received'],
                    $this->_config['dialer']['llamada_corta']);
            }
        }
    }

    private function _ejecutarLogoffAgente($sAgente, $a, $timestamp, $evtname)
    {
        if (!is_null($a->llamada)) {
            $this->_log->output('WARN: agente '.$a->channel.' todavía tiene una '.
                'llamada al procesar '.$evtname.', se cierra... | EN: agent '.$a->channel.' still has a call when processing '.$evtname.', closing...');
            $llamada = $a->llamada;
            $r = $this->_ami->Hangup($llamada->agentchannel);
            if ($r['Response'] != 'Success') {
                $this->_log->output('ERR: No se puede colgar la llamada para '.$a->channel.
                    ' ('.$llamada->agentchannel.') - '.$r['Message'].' | EN: Cannot hang up call for '.$a->channel.' ('.$llamada->agentchannel.') - ');
                /* El comando Hangup fue rechazado (canal ya inexistente, nombre
                 * obsoleto, etc.), asi que nunca va a llegar el evento Hangup
                 * que limpiaria $a->llamada por la via normal. Sin esto el
                 * agente queda "oncall" indefinidamente y no puede volver a
                 * loguearse aunque el dialer ya lo dio de baja aqui. Se
                 * finaliza el seguimiento de la llamada explicitamente para
                 * evitar ese cuelgue. | EN: The Hangup command was rejected
                 * (channel already gone, stale name, etc.), so the Hangup
                 * event that would normally clear $a->llamada is never
                 * coming. Without this the agent stays "oncall" indefinitely
                 * and cannot log back in even though the dialer already
                 * logged it off here. Explicitly finalize the call tracking
                 * to avoid that lockup. */
                $this->_procesarLlamadaColgada($llamada, array(
                    'local_timestamp_received' => $timestamp,
                    'Cause'                    => '0',
                    'Cause-txt'                => 'Forced agent logoff ('.$evtname.')',
                    'Channel'                  => $llamada->actualchannel,
                    'Uniqueid'                 => $llamada->uniqueid,
                ));
            }
        }

        // El agente se va: ninguna bandera de consulta suya sigue teniendo
        // sentido, y así los mapas no crecen sin límite.
        // EN: the agent is leaving: none of its consultation flags mean
        // anything any more, and this keeps the maps from growing unbounded.
        unset($this->_agentesEnConsultation[$sAgente]);
        unset($this->_agentesEnAtxferComplete[$sAgente]);
        unset($this->_agentesConsultaContestada[$sAgente]);
        unset($this->_ultimaConsultaFallida[$sAgente]);
        unset($this->_consultaTerminadaEn[$sAgente]);

        $a->terminarLoginAgente($this->_ami, $timestamp);
    }

    private function _dumpstatus()
    {
        $this->_log->output('INFO: '.__METHOD__.' volcando status de seguimiento... | EN: dumping tracking status...');
        $this->_log->output("\n");

        $this->_log->output("Versión detectada de Asterisk............".implode('.', $this->_asteriskVersion).' | EN: Detected Asterisk version............'.implode('.', $this->_asteriskVersion));
        $this->_log->output("Timestamp de arranque de Asterisk........".$this->_asteriskStartTime.' | EN: Asterisk startup timestamp........'.$this->_asteriskStartTime);
        $this->_log->output("Última verificación de llamadas viejas...".date('Y-m-d H:i:s', $this->_iTimestampVerificacionLlamadasViejas).' | EN: Last verification of old calls...'.date('Y-m-d H:i:s', $this->_iTimestampVerificacionLlamadasViejas));

        $this->_log->output("\n\nLista de campañas salientes:\n | EN: List of outgoing campaigns:\n");
        foreach ($this->_campaniasSalientes as $c)
            $c->dump($this->_log);

        $this->_log->output("\n\nLista de colas entrantes:\n | EN: List of incoming queues:\n");
        foreach ($this->_colasEntrantes as $c) {
            $this->_log->output("queue:               ".$c['queue']);
            $this->_log->output("id_queue_call_entry: ".$c['id_queue_call_entry']);
            if (is_null($c['campania']))
                $this->_log->output("(sin campaña)\n | EN: (no campaign)\n");
            else $c['campania']->dump($this->_log);
        }

        $this->_log->output("\n\nLista de agentes:\n | EN: List of agents:\n");
        $this->_listaAgentes->dump($this->_log);

        $this->_log->output("\n\nLista de llamadas:\n | EN: List of calls:\n");
        $this->_listaLlamadas->dump($this->_log);

        $this->_log->output("\n\nLlamadas en espera en colas: | EN: Calls waiting in queues:");
        $llamadasEspera = $this->_queueshadow->llamadasEnEspera();
        foreach ($llamadasEspera as $q => $n) {
            $this->_log->output("\t$q.....$n");
        }

        $this->_log->output("\n\nCuenta de eventos recibidos: | EN: Count of received events:");
        $cuenta = $this->_ami->cuentaEventos;
        if (count($cuenta) > 0) {
            arsort($cuenta);
            $padlen = max(array_map("strlen", array_keys($cuenta)));
            foreach ($cuenta as $ev => $cnt)
                $this->_log->output("\t".str_pad($ev, $padlen, '.').'...'.sprintf("%6d", $cnt));
        }

        // Dump pending transfer reservations
        $this->_log->output('INFO: '.__METHOD__.' agentes con transferencia pendiente / agents with pending transfer: '.
            (count($this->_agentesEnTransferPendiente) > 0
                ? print_r($this->_agentesEnTransferPendiente, TRUE)
                : '(ninguno/none)'));

        $this->_log->output('INFO: '.__METHOD__.' fin de volcado status de seguimiento... | EN: end of tracking status dump...');
    }

    private function _agregarAlarma($timeout, $callback, $arglist)
    {
        $k = 'K_'.$this->_nalarma;
        $this->_nalarma++;
        $this->_alarmas[$k] = array(microtime(TRUE) + $timeout, $callback, $arglist);
        return $k;
    }

    private function _cancelarAlarma($k)
    {
        if (isset($this->_alarmas[$k])) unset($this->_alarmas[$k]);
    }

    private function _ejecutarAlarmas()
    {
        $ks = array_keys($this->_alarmas);
        $lanzadas = array();
        foreach ($ks as $k) {
            if ($this->_alarmas[$k][0] <= microtime(TRUE)) {
                $lanzadas[] = $k;
                call_user_func_array($this->_alarmas[$k][1], $this->_alarmas[$k][2]);
            }
        }
        foreach ($lanzadas as $k) unset($this->_alarmas[$k]);
    }
}
?>

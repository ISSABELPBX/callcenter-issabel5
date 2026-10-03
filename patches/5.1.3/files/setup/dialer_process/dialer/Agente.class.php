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
  $Id: Agente.class.php, Mon 25 Nov 2019 04:22:41 PM EST, nicolas@issabel.com
*/
define('AST_DEVICE_NOTINQUEUE', -1);
define('AST_DEVICE_UNKNOWN',    0);
define('AST_DEVICE_NOT_INUSE',  1);
define('AST_DEVICE_INUSE',      2);
define('AST_DEVICE_BUSY',       3);
define('AST_DEVICE_INVALID',    4);
define('AST_DEVICE_UNAVAILABLE',5);
define('AST_DEVICE_RINGING',    6);
define('AST_DEVICE_RINGINUSE',  7);
define('AST_DEVICE_ONHOLD',     8);

class Agente
{
    // Relaciones con otros objetos conocidos
    // Relationships with other known objects
    private $_log;
    private $_tuberia;
    private $_compat = NULL; // AsteriskCompat instance for version-aware behavior

    private $_listaAgentes;

    /* Referencia a la llamada atendida por el agente, o NULL si no atiende.
     * Para entrar y salir de hold se requiere [incoming/outgoing, canal cliente,
     * id call, id current call ]
     * Reference to the call attended by the agent, or NULL if not attending.
     * To enter and exit hold requires [incoming/outgoing, client channel,
     * id call, id current call ]*/
    private $_llamada = NULL;

    // ID en la base de datos del agente
    // ID in the database of the agent
    private $_id_agent = NULL;
    private $_name = NULL;
    private $_number = NULL;
    private $_estatus = NULL;
    private $_type = NULL;

    /*  Estado de la consola. Los valores posibles son
        logged-out  No hay agente logoneado
        logging     Agente intenta autenticarse con la llamada
        logged-in   Agente fue autenticado y está logoneado en consola
        Console state. Possible values are:
        logged-out  No agent logged in
        logging     Agent tries to authenticate with the call
        logged-in   Agent was authenticated and is logged in to console
     */
    private $_estado_consola = 'logged-out';

    /* El número de la extensión interna que se logonea al agente. En estado
       logout la extensión es NULL. Se supone que el canal debería contener
       como prefijo a esta cadena. Formato esperado SIP/1064.
       The number of the internal extension that logs into the agent. In logout
       state the extension is NULL. The channel is supposed to contain
       this string as a prefix. Expected format SIP/1064.
     */
    private $_extension = NULL;

    /* El ID de la sesión de auditoría iniciada para este agente */
    /* The ID of the audit session started for this agent */
    private $_id_sesion = NULL;

    /* El ID del break en que se encuentre el agente, o NULL si no está en break */
    /* The ID of the break where the agent is, or NULL if not on break */
    private $_id_break = NULL;

    /* El ID de la sesión de auditoría correspondiente al break del agente */
    /* The ID of the audit session corresponding to the agent's break */
    private $_id_audit_break = NULL;

    /* El ID del hold en que se encuentra el agente, o NULL si no está en hold */
    /* The ID of the hold where the agent is, or NULL if not on hold */
    private $_id_hold = NULL;

    /* El ID de la sesión de auditoría correspondiente al hold del agente */
    /* The ID of the audit session corresponding to the agent's hold */
    private $_id_audit_hold = NULL;

    /* El Uniqueid de la llamada que se usó para iniciar el login de agente */
    /* The Uniqueid of the call used to initiate the agent login */
    private $_Uniqueid = NULL;

    /* El canal que se usó para el login del agente */
    /* The channel that was used for the agent login */
    private $_login_channel = NULL;

    /* El Uniqueid de la llamada por parte del canal del agente que se contrapone
     * al Uniqueid de la llamada generada o recibida. Para llamadas salientes
     * es sólo informativo, pero es esencial registrarlo para llamadas entrantes.
     * Sólo este Uniqueid recibe un Hangup cuando una llamada es transferida.
     * The Uniqueid of the call on the agent channel side that opposes
     * the Uniqueid of the generated or received call. For outgoing calls
     * it is only informational, but it is essential to register it for incoming calls.
     * Only this Uniqueid receives a Hangup when a call is transferred.
     */
    private $_UniqueidAgente = NULL;

    /* VERDADERO si el agente ha sido reservado para agendamiento */
    /* TRUE if the agent has been reserved for scheduling */
    private $_reservado = FALSE;

    /* Cuenta de pausas del agente. El agente debe estar pausado si distinto de
     * cero. Por ahora se usa para break y para hold.
     * Count of agent pauses. The agent must be paused if different from
     * zero. Currently used for break and for hold. */
    private $_num_pausas = 0;

    /* Si no es NULL, máximo intervalo de inactividad, en segundos */
    /* If not NULL, maximum inactivity interval, in seconds */
    private $_max_inactivo = NULL;

    /* Timestamp de la última actividad del agente */
    /* Timestamp of the agent's last activity */
    private $_ultima_actividad;

    /* Estado del agente en todas las colas a la que pertenece */
    /* State of the agent in all queues to which it belongs */
    private $_estado_agente_colas = array();

    /* Sólo agentes dinámicos: lista de colas dinámicas a las que debe
     * pertenecer. Las claves son las colas y los valores son el valor de
     * penalty en cada cola.
     * Only dynamic agents: list of dynamic queues to which it must
     * belong. The keys are the queues and the values are the penalty
     * value in each queue. */
    private $_colas_dinamicas = array();

    var $llamada_agendada = NULL;

    // Timestamp de inicio de login, debe setearse a NULL al entrar a estado logged-in
    // Login start timestamp, must be set to NULL when entering logged-in state
    private $_logging_inicio = NULL;

    function __construct(ListaAgentes $lista, $idAgente, $iNumero, $sNombre,
        $bEstatus, $sType, $tuberia, $log)
    {
        $this->_listaAgentes = $lista;
        $this->_id_agent = (int)$idAgente;
        $this->_name = (string)$sNombre;
        $this->_estatus = (bool)$bEstatus;
        $this->_type = (string)$sType;
        $this->_tuberia = $tuberia;
        $this->_log = $log;
        $this->resetTimeout();

        // Se setea vía interfaz pública para invocar __set__()
        // Set via public interface to invoke __set__()
        $this->number = $iNumero;
    }

    public function setCompat($compat) { $this->_compat = $compat; }

    private function _nul($i) { return is_null($i) ? '(ninguno/none)' : "$i"; }

    public function dump($log)
    {
        $s = "----- AGENTE / AGENT -----\n";
        $s .= ("\tid_agent...........".$this->id_agent)."\n";
        $s .= ("\tname...............".$this->name)."\n";
        $s .= ("\testatus............".$this->estatus)."\n";
        $s .= ("\ttype...............".$this->type)."\n";
        $s .= ("\tnumber.............".$this->number)."\n";
        $s .= ("\tchannel............".$this->channel)."\n";
        if ($this->type != 'Agent')
            $s .= ("\tcolas dinámicas/dynamic queues....[".implode(', ', $this->colas_dinamicas))."]\n";
        $s .= ("\testado de colas/queue status....[".$this->_strestadocolas())."]\n";
        $s .= ("\testado_consola.....".$this->estado_consola)."\n";
        if (!is_null($this->logging_inicio))
            $s .= ("\tlogging_inicio.....".$this->logging_inicio)."\n";

        $s .= ("\tid_sesion..........".$this->_nul($this->id_sesion))."\n";
        $s .= ("\tid_break...........".$this->_nul($this->id_break))."\n";
        $s .= ("\tid_audit_break.....".$this->_nul($this->id_audit_break))."\n";
        $s .= ("\tid_hold............".$this->_nul($this->id_hold))."\n";
        $s .= ("\tid_audit_hold......".$this->_nul($this->id_audit_hold))."\n";
        $s .= ("\tUniqueid...........".$this->_nul($this->Uniqueid))."\n";
        $s .= ("\tUniqueidAgente.....".$this->_nul($this->UniqueidAgente))."\n";
        $s .= ("\tlogin_channel......".$this->_nul($this->login_channel))."\n";
        $s .= ("\textension..........".$this->_nul($this->extension))."\n";
        $s .= ("\tnum_pausas.........".$this->num_pausas)."\n";
        $s .= ("\ten_pausa/paused.....".($this->en_pausa ? 'SI/YES' : 'NO'))."\n";
        $s .= ("\treservado/reserved..".($this->reservado ? 'SI/YES' : 'NO'))."\n";
        $s .= ("\tmax_inactivo.......".$this->_nul($this->max_inactivo))."\n";
        $s .= ("\ttimeout_inactivo...".$this->timeout_inactivo)."\n";

        $s .= ("\tllamada/call........".(is_null($this->_llamada)
            ? '(ninguna/none)'
            : $this->_llamada->__toString()
            ))."\n";
        $s .= ("\tllamada_agendada/scheduled_call...".(is_null($this->llamada_agendada)
            ? '(ninguna/none)'
            : $this->llamada_agendada->__toString()
        ));
        $log->output($s);
    }

    public function __toString()
    {
        return 'ID='.$this->id_agent.
            ' type='.$this->type.
            ' number='.$this->_nul($this->number).
            ' '.$this->name;
    }

    private function _strestadocolas()
    {
        // El estado de la cola sólo es usable si eventmemberstatus está activo
        // para la cola en cuestión.
        // The queue state is only usable if eventmemberstatus is active
        // for the queue in question.
        /*
        $states = array(
            -1  =>  'Not in queue',    // Not in queue
            0   =>  "Unknown",         // Unknown
            1   =>  "Not in use",      // Not in use
            2   =>  "In use",          // In use
            3   =>  "Busy",            // Busy
            4   =>  "Invalid",         // Invalid
            5   =>  "Unavailable",     // Unavailable
            6   =>  "Ringing",         // Ringing
            7   =>  "Ring+Inuse",      // Ring+Inuse
            8   =>  "On Hold",         // On Hold
        );
        $s = array();
        foreach ($this->_estado_agente_colas as $q => $st)
            $s[] = "$q (".$states[$st].")";
        return implode(', ', $s);
        */
        return implode(', ', array_keys($this->_estado_agente_colas));
    }

    public function __get($s)
    {
        switch ($s) {
        case 'id_agent':        return $this->_id_agent;
        case 'number':          return $this->_number;
        case 'channel':         return is_null($this->_number) ? NULL : $this->_type.'/'.$this->_number;
        case 'type':            return $this->_type;
        case 'name':            return $this->_name;
        case 'estatus':         return $this->_estatus;
        case 'estado_consola':  return $this->_estado_consola;
        case 'logging_inicio':  return $this->_logging_inicio;
        case 'id_sesion':       return $this->_id_sesion;
        case 'id_break':        return $this->_id_break;
        case 'id_audit_break':  return $this->_id_audit_break;
        case 'id_hold':         return $this->_id_hold;
        case 'id_audit_hold':   return $this->_id_audit_hold;
        case 'Uniqueid':        return $this->_Uniqueid;
        case 'UniqueidAgente':  return $this->_UniqueidAgente;
        case 'llamada':         return $this->_llamada;
        case 'login_channel':   return $this->_login_channel;
        case 'extension':       return $this->_extension;
        case 'num_pausas':      return $this->_num_pausas;
        case 'en_pausa':        return ($this->_num_pausas > 0);
        case 'reservado':       return $this->_reservado;
        case 'max_inactivo':    return $this->_max_inactivo;
        case 'timeout_inactivo':return (!is_null($this->_max_inactivo) && (time() >= $this->_ultima_actividad + $this->_max_inactivo));
        case 'colas_dinamicas': return array_keys($this->_colas_dinamicas);
        case 'colas_actuales':  return array_keys($this->_estado_agente_colas);
        case 'colas_penalty':   return $this->_colas_dinamicas;
        case 'auditpauses':
            $pauses = array();
            if (!is_null($this->_id_audit_hold))
                $pauses['hold'] = $this->_id_audit_hold;
            if (!is_null($this->_id_audit_break))
                $pauses['break'] = $this->_id_audit_break;
            return $pauses;
        default:
            die(__METHOD__.' - propiedad no implementada: '.$s);
        }
    }

    public function __set($s, $v)
    {
        switch ($s) {
        case 'id_agent':        $this->_id_agent = (int)$v; break;
        case 'id_sesion':       $this->_id_sesion = is_null($v) ? NULL : (int)$v; break;
        case 'name':            $this->_name = (string)$v; break;
        case 'estatus':         $this->_estatus = (bool)$v; break;
        case 'max_inactivo':    $this->_max_inactivo = is_null($v) ? NULL : (int)$v; break;
        case 'number':
            if (ctype_digit("$v")) {
                $v = (string)$v;
                $sCanalViejo = $this->channel;
                $this->_number = $v;
                $sCanalNuevo = $this->channel;

                if (!is_null($sCanalViejo))
                    $this->_listaAgentes->removerIndice('agentchannel', $sCanalViejo);
                $this->_listaAgentes->agregarIndice('agentchannel', $sCanalNuevo, $this);
            }
            break;
        case 'UniqueidAgente':
            if ($v != $this->_UniqueidAgente) {
                if (!is_null($this->_UniqueidAgente))
                    $this->_listaAgentes->removerIndice('uniqueidlink', $this->_UniqueidAgente);
                $this->_UniqueidAgente = $v;
                if (!is_null($v))
                    $this->_listaAgentes->agregarIndice('uniqueidlink', $v, $this);
            }
            break;
        default:
            die(__METHOD__.' - propiedad no implementada: '.$s);
        }
    }

    public function resetTimeout() { $this->_ultima_actividad = time(); }

    public function setBreak($ami, $id_break, $id_audit_break, $nombre_pausa)
    {
        if (!is_null($id_break) && !is_null($id_audit_break)) {
            $this->_id_break = (int)$id_break;
            $this->_id_audit_break = (int)$id_audit_break;
            $this->_incrementarPausas($ami,$id_break, $nombre_pausa); 
        }
        $this->resetTimeout();
    }

    public function clearBreak($ami)
    {
        if (!is_null($this->_id_audit_break)) {
            $this->_id_break = NULL;
            $this->_id_audit_break = NULL;
            $this->_decrementarPausas($ami);
        }
        $this->resetTimeout();
    }

    public function setReserved($ami)
    {
        if (!$this->_reservado) {
            $this->_reservado = TRUE;
            $this->_incrementarPausas($ami, NULL, 'Reserved');
        }
        $this->resetTimeout();
    }

    public function clearReserved($ami)
    {
        if ($this->_reservado) {
            $this->_reservado = FALSE;
            $this->_decrementarPausas($ami);
        }

        // ATENCIÓN: la implementación anterior anulaba llamada_agendada siempre
        // ATTENTION: the previous implementation always canceled llamada_agendada
        if (!is_null($this->llamada_agendada)) {
            $this->llamada_agendada->agente_agendado = NULL;
            $this->llamada_agendada = NULL;
        }

        $this->resetTimeout();
    }

    public function setHold($ami, $id_hold, $id_audit_hold)
    {
        if (!is_null($id_hold) && !is_null($id_audit_hold)) {
            $this->_id_hold = (int)$id_hold;
            $this->_id_audit_hold = (int)$id_audit_hold;
            $this->_incrementarPausas($ami,$id_hold,'Hold');
            if (!is_null($this->_llamada))
                $this->_llamada->request_hold = TRUE;
        }
        $this->resetTimeout();
    }

    public function clearHold($ami)
    {
        if (!is_null($this->_id_audit_hold)) {
            $this->_id_hold = NULL;
            $this->_id_audit_hold = NULL;

            $this->_decrementarPausas($ami);
        }
        if (!is_null($this->_llamada))
            $this->_llamada->request_hold = FALSE;
        $this->resetTimeout();
    }

    private function _incrementarPausas($ami,$reason,$nombre_pausa)
    {
        $this->_num_pausas++;
        if ($this->_num_pausas == 1 && count($this->colas_actuales) > 0) {
            $this->asyncQueuePause($ami, TRUE, NULL, $nombre_pausa);
        }
    }

    private function _decrementarPausas($ami)
    {
        if ($this->_num_pausas > 0) {
            $this->_num_pausas--;
            if ($this->_num_pausas == 0 && count($this->colas_actuales) > 0) {
                $this->asyncQueuePause($ami, FALSE);
            }
        }
    }

    public function asyncQueuePause($ami, $nstate, $queue = NULL, $reason = '')
    {
        // For Agent type, derive the correct queue interface from AsteriskCompat
        $sInterface = $this->channel;
        if ($this->type == 'Agent' && !is_null($this->_compat)) {
            $sInterface = $this->_compat->getAgentQueueInterface($this->number);
        }
        $ami->asyncQueuePause(
            array($this, '_cb_QueuePause'),
            array($this->channel, $nstate, $reason, $ami),
            $queue, $sInterface, $nstate, $reason);
    }

    public function iniciarLoginAgente($sExtension)
    {
    	$this->_estado_consola = 'logged-out';
        if (!is_null($this->_Uniqueid))
            $this->_listaAgentes->removerIndice('uniqueidlogin', $this->_Uniqueid);
        $this->_Uniqueid = NULL;
        $this->_login_channel = NULL;
        $this->_extension = $sExtension;
        $this->_listaAgentes->agregarIndice('extension', $sExtension, $this);
        $this->_logging_inicio = time();
    }

    // Se llama en OriginateResponse exitoso, o en Hangup antes de completar login
    // Called on successful OriginateResponse, or on Hangup before completing login
    public function respuestaLoginAgente($response, $uniqueid, $channel)
    {
        if ($response == 'Success') {
            // El sistema espera ahora la contraseña del agente
            // The system now expects the agent password
            $this->_estado_consola = 'logging';
            $this->_Uniqueid = $uniqueid;
            $this->_listaAgentes->agregarIndice('uniqueidlogin', $uniqueid, $this);
            $this->_login_channel = $channel;
        } else {
            // Send failure event if login attempt was started (indicated by _logging_inicio being set)
            if (!is_null($this->_logging_inicio)) {
                $this->_tuberia->msg_SQLWorkerProcess_AgentLogin(
                    $this->channel,
                    microtime(TRUE),
                    NULL,
                    NULL);
            }

            // El agente no ha podido responder la llamada de login
            // The agent was unable to answer the login call
            $this->_estado_consola = 'logged-out';
            if (!is_null($this->_Uniqueid))
                $this->_listaAgentes->removerIndice('uniqueidlogin', $this->_Uniqueid);
            $this->_Uniqueid = NULL;
            $this->_login_channel = NULL;
            if (!is_null($this->_extension))
                $this->_listaAgentes->removerIndice('extension', $this->_extension);
            $this->_extension = NULL;
            $this->_logging_inicio = NULL;
        }
    }

    // Se llama en Agentlogin al confirmar que agente está logoneado
    // Called on Agentlogin when confirming that agent is logged in
    // $sLoginChannel: For app_agent_pool, the actual channel running AgentLogin (e.g., SIP/101-00000xxx)
    public function completarLoginAgente($ami, $sLoginChannel = NULL)
    {
        $this->_estado_consola = 'logged-in';
        $this->resetTimeout();
        $this->_logging_inicio = NULL;
        // Store the login channel for app_agent_pool logout (hangup this channel to logout)
        // Almacenar el canal de login para logout de app_agent_pool (colgar este canal para logout)
        if (!is_null($sLoginChannel)) {
            $this->_login_channel = $sLoginChannel;
        }
        $this->_tuberia->msg_SQLWorkerProcess_AgentLogin(
            $this->channel,
            microtime(TRUE),
            $this->id_agent,
            $this->_extension);
        if (count($this->_estado_agente_colas) > 0) {
            $this->asyncQueuePause($ami, FALSE);
        }
    }

    // Se llama en Agentlogoff
    // Called on Agentlogoff
    public function terminarLoginAgente($ami, $timestamp)
    {
        // Emitir AgentLogoff ANTES de limpiar pausas e ID de sesión
        // Emit AgentLogoff BEFORE clearing pauses and session ID
        $this->_tuberia->msg_SQLWorkerProcess_AgentLogoff(
            $this->channel,
            $timestamp,
            $this->id_agent,
            $this->id_sesion,
            $this->auditpauses);

        $this->clearBreak($ami);
        $this->clearHold($ami);
        $this->clearReserved($ami);
        $this->_estado_consola = 'logged-out';
        $this->_num_pausas = 0;
        if (!is_null($this->_Uniqueid))
            $this->_listaAgentes->removerIndice('uniqueidlogin', $this->_Uniqueid);
        $this->_Uniqueid = NULL;
        $this->_login_channel = NULL;
        if (!is_null($this->_extension))
            $this->_listaAgentes->removerIndice('extension', $this->_extension);
        $this->_extension = NULL;
        $this->_id_sesion = NULL;
        $this->resetTimeout();

        if (count($this->_estado_agente_colas) > 0) {
            $this->asyncQueuePause($ami, FALSE);
        }
    }

    public function resumenSeguimiento()
    {
        // Get highest queue status (6=RINGING is higher priority than 1=NOT_INUSE)
        // Obtener el estado de cola más alto (6=RINGING tiene mayor prioridad que 1=NOT_INUSE)
        $max_queue_status = AST_DEVICE_UNKNOWN;
        foreach ($this->_estado_agente_colas as $queue => $status) {
            if ($status > $max_queue_status) $max_queue_status = $status;
        }

        return array(
            'id_agent'          =>  $this->id_agent,
            'name'              =>  $this->name,
            'estado_consola'    =>  $this->estado_consola,
            'id_break'          =>  $this->id_break,
            'id_audit_break'    =>  $this->id_audit_break,
            'id_hold'           =>  $this->id_hold,
            'id_audit_hold'     =>  $this->id_audit_hold,
            'num_pausas'        =>  $this->num_pausas,
            'extension'         =>  $this->extension,
            'login_channel'     =>  $this->login_channel,
            'oncall'            =>  !is_null($this->llamada),
            'queue_status'      =>  $max_queue_status,
            'clientchannel'     =>  is_null($this->llamada) ? NULL : $this->llamada->actualchannel,
            'waitedcallinfo'    =>  ((!is_null($this->llamada_agendada))
                ? array(
                    'calltype'          =>  $this->llamada_agendada->tipo_llamada,
                    'campaign_id'       =>  $this->llamada_agendada->campania->id,
                    'callid'            =>  $this->llamada_agendada->id_llamada,
                    'status'            =>  $this->llamada_agendada->status,
                )
                : NULL),
        );
    }

    public function resumenSeguimientoLlamada()
    {
        $r = $this->resumenSeguimiento();
        if (!is_null($this->llamada)) $r['callinfo'] = $this->llamada->resumenLlamada();
        return $r;
    }

    public function asignarLlamadaAtendida($llamada, $uniqueid_agente)
    {
        $this->_llamada = $llamada;
        $this->llamada_agendada = NULL;
        $this->UniqueidAgente = $uniqueid_agente;
        $this->resetTimeout();
    }

    public function quitarLlamadaAtendida()
    {
        $this->_llamada = NULL;
        $this->llamada_agendada = NULL;
        $this->UniqueidAgente = NULL;
        $this->resetTimeout();
    }

    public function asignarEstadoEnColas($nuevoEstado)
    {
        $k_viejo = array_keys($this->_estado_agente_colas);
        $k_nuevo = array_keys($nuevoEstado);
        $colas_agregadas = array_diff($k_nuevo, $k_viejo);
        $colas_quitadas = array_diff($k_viejo, $k_nuevo);

        $this->_estado_agente_colas = $nuevoEstado;
        asort($this->_estado_agente_colas);

        return (count($colas_agregadas) > 0 || count($colas_quitadas) > 0);
    }

    public function actualizarEstadoEnCola($queue, $status)
    {
        $oldStatus = isset($this->_estado_agente_colas[$queue]) ? $this->_estado_agente_colas[$queue] : AST_DEVICE_UNKNOWN;
        $this->_estado_agente_colas[$queue] = $status;

        // Emit event when status changes to/from ringing (for real-time UI updates)
        // Only emit if agent is logged in and status actually changed
        // Emitir evento cuando el estado cambia a/de ringing (para actualizaciones UI en tiempo real)
        // Solo emitir si el agente está logoneado y el estado realmente cambió
        if ($this->estado_consola == 'logged-in' && $oldStatus != $status) {
            $isNowRinging = ($status == AST_DEVICE_RINGING || $status == AST_DEVICE_RINGINUSE);
            $wasRinging = ($oldStatus == AST_DEVICE_RINGING || $oldStatus == AST_DEVICE_RINGINUSE);

            if ($isNowRinging || $wasRinging) {
                // Determine the new agent status string
                // Determinar la nueva cadena de estado del agente
                $sNewStatus = $isNowRinging ? 'ringing' : 'online';
                $this->_tuberia->msg_SQLWorkerProcess_AgentStateChange(
                    $this->channel,
                    $sNewStatus,
                    $queue
                );
            }
        }
    }

    public function quitarEstadoEnCola($queue)
    {
        unset($this->_estado_agente_colas[$queue]);
    }

    // El estado de la cola sólo es usable si eventmemberstatus está activo
    // para la cola en cuestión. Excepto que siempre se sabe si está o no en cola.
    // The queue state is only usable if eventmemberstatus is active
    // for the queue in question. Except that it's always known whether it's in the queue or not.
    public function estadoEnCola($queue)
    {
        return isset($this->_estado_agente_colas[$queue]) ? $this->_estado_agente_colas[$queue] : AST_DEVICE_NOTINQUEUE;
    }

    public function asignarColasDinamicas($lista)
    {
        $colas = array_keys($lista);

        $colas_agregadas = array_diff($colas, $this->colas_dinamicas);
        $colas_quitadas = array_diff($this->colas_dinamicas, $colas);

        $this->_colas_dinamicas = $lista;
        ksort($this->_colas_dinamicas);

        return (count($colas_agregadas) > 0 || count($colas_quitadas) > 0);
    }

    public function diferenciaColasDinamicas()
    {
        if ($this->type == 'Agent') return NULL;
        $currcolas = $this->colas_actuales;
        $dyncolas = $this->colas_dinamicas;
        $r = array(
            array_diff($dyncolas, $currcolas), // colas a las cuales agregar agente / queues to which to add agent
            array_diff($currcolas, $dyncolas), // colas de las cuales quitar agente / queues from which to remove agent
        );
        $qp = array();
        foreach ($r[0] as $q) $qp[$q] = $this->_colas_dinamicas[$q];
        $r[0] = $qp;

        return $r;
    }

    public function hayColasDinamicasLogoneadas()
    {
        $currcolas = array_keys($this->_estado_agente_colas);
        return (count(array_intersect($currcolas, $this->colas_dinamicas)) > 0);
    }

    public function nuevaMembresiaCola()
    {
        $colasAtencion = ($this->type == 'Agent')
            ? $this->colas_actuales
            : $this->colas_dinamicas;
        $this->_tuberia->msg_SQLWorkerProcess_nuevaMembresiaCola(
            $this->channel,
            $this->resumenSeguimientoLlamada(),
            $colasAtencion);
    }

    /**
     * Initiate agent logoff from Asterisk's perspective
     * For chan_agent (Asterisk 11): Agentlogoff AMI command
     * For app_agent_pool (Asterisk 12+): Hangup the login channel
     * For dynamic agents (SIP/IAX2/PJSIP): QueueRemove from all queues
     */
    public function forzarLogoffAgente($ami, $log)
    {
        if ($this->type == 'Agent') {
            if (!is_null($this->_compat) && $this->_compat->hasChanAgent()) {
                // chan_agent (Asterisk 11): use Agentlogoff AMI command
                $ami->asyncAgentlogoff(
                    array($this, '_cb_Agentlogoff'),
                    array($log),
                    $this->number);
            } else {
                // app_agent_pool (Asterisk 12+): Hangup the AgentLogin channel
                if (!is_null($this->_login_channel)) {
                    $ami->asyncHangup(
                        array($this, '_cb_HangupLogoff'),
                        array($log),
                        $this->_login_channel);
                } elseif (!is_null($this->_extension)) {
                    $ami->asyncHangup(
                        array($this, '_cb_HangupLogoff'),
                        array($log),
                        $this->_extension);
                } else {
                    $this->_log->output('WARN: '.__METHOD__.': no hay canal para colgar para el agente '.$this->number.' | EN: no channel to hangup for agent '.$this->number);
                }
            }
        } else {
            // Dynamic agents (SIP/IAX2/PJSIP): remove from all queues
            foreach ($this->colas_actuales as $q) {
                $ami->asyncQueueRemove(
                    array($this, '_cb_QueueRemove'),
                    array($log, $q),
                    $q, $this->channel);
            }
        }
    }

    public function _cb_Agentlogoff($r, $log)
    {
        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: no se puede completar Agentlogoff('.$this->number.'): '.$r['Message'].' | EN: cannot complete Agentlogoff('.$this->number.'): '.$r['Message']);
        }
    }

    public function _cb_HangupLogoff($r, $log)
    {
        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: no se puede colgar canal de login del agente ('.$this->number.'): '.$r['Message'].' | EN: cannot hangup agent login channel ('.$this->number.'): '.$r['Message']);
        }
    }

    public function _cb_QueueRemove($r, $log, $q)
    {
        if ($r['Response'] != 'Success') {
            $this->_log->output("ERR: falla al quitar {$this->channel} de cola {$q}: ".print_r($r, TRUE)." | EN: failure to remove {$this->channel} from queue {$q}: ");
            // "ERR: failure to remove {$this->channel} from queue {$q}"
        }
    }

    public function _cb_QueuePause($r, $sAgente, $nstate, $reason, $ami)
    {
        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: '.__METHOD__.' (internal) no se puede '.
                ($nstate ? 'pausar' : 'despausar').' al agente '.$sAgente.': '.
                $sAgente.' - '.$r['Message'].' | EN: cannot '.($nstate ? 'pause' : 'unpause').' agent '.$sAgente.': ');
                // cannot pause/unpause agent
        } else {
            $partes = preg_split("/\//",$sAgente);
            $numAgente = $partes[1];
            $epoch = time();
            /* La escritura en AstDB es asíncrona: este callback puede correr
             * dentro del wait_response() de otra llamada síncrona (p.ej. el
             * Hangup del logoff), donde una llamada síncrona sería rechazada
             * por el guard de reentrada. La misma familia, clave y valor de
             * siempre, sólo que sin bloquear.
             * The AstDB write is asynchronous: this callback can run inside
             * another synchronous call's wait_response() (e.g. the logoff
             * Hangup), where a synchronous call would be refused by the
             * reentrancy guard. The same family, key and value as always,
             * only without blocking. */
            if($nstate==1) {
                $ami->database_put_async(
                    array($this, '_cb_DatabaseWrite'),
                    array($sAgente, TRUE),
                    'PAUSECUSTOM', 'AGENT/'.$numAgente, "$reason:$epoch");
            } else {
                $ami->database_del_async(
                    array($this, '_cb_DatabaseWrite'),
                    array($sAgente, FALSE),
                    'PAUSECUSTOM', 'AGENT/'.$numAgente);
            }
        }
    }

    public function _cb_DatabaseWrite($r, $sAgente, $nstate)
    {
        /* Success y Follows son las dos formas en que Asterisk responde un
         * comando completado (13/16 contestan Follows, 18 Success). No se
         * juzga el texto de salida: un database del de una clave que no
         * existe es normal tras un despausa sin escritura previa, y el código
         * de antes del fix tampoco lo revisaba.
         * Success and Follows are the two shapes in which Asterisk answers a
         * completed command (13/16 reply Follows, 18 Success). The output
         * text is not judged: a database del of a key that does not exist is
         * normal after an unpause with no earlier write, and the code before
         * the fix never checked it either. */
        if (!isset($r['Response']) ||
            !in_array($r['Response'], array('Success', 'Follows'))) {
            $this->_log->output('ERR: '.__METHOD__.' no se puede '.
                ($nstate ? 'escribir' : 'borrar').' PAUSECUSTOM para '.$sAgente.': '.
                (isset($r['Message']) ? $r['Message'] : '(sin respuesta)').
                ' | EN: cannot '.($nstate ? 'write' : 'delete').' PAUSECUSTOM for '.
                $sAgente.': '.(isset($r['Message']) ? $r['Message'] : '(no response)'));
        }
    }
}
?>

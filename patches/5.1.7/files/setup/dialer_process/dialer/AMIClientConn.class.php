<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  Encoding: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 1.2-2                                                |
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
  $Id: AMIClientConn.class.php, Thu 21 Nov 2019 12:55:55 PM EST, nicolas@issabel.com
*/
if(!class_exists('AGI')) {
    require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'phpagi.php');
}

define('AMI_PORT', 5038);

class AMIClientConn extends MultiplexConn
{
    private $oLogger;
    private $server;
    private $port;
    private $_listaEventos = array();   // Eventos pendientes por procesar
                                        // Pending events to be processed
    private $_response = NULL;          // Respuesta recibida del último comando
                                        // Response received from the last command

    public $cuentaEventos = array();    // Cuenta de eventos recibidos
                                        // Count of received events

    /* El siguiente miembro sólo se usa por los comandos database_* que evaluan
     * response[data] como una respuesta. En caso de error se devuelve NULL o
     * FALSE como corresponda, y el cliente debe examinar raw_response para
     * obtener más detalles.
     * The following member is only used by database_* commands that evaluate
     * response[data] as a response. In case of error, NULL or FALSE is returned
     * as appropriate, and the client must examine raw_response to get more details. */
    public $raw_response = NULL;

   /**
    * Event Handlers
    *
    * @access private
    * @var array
    */
    private $event_handlers;

    // Lista de peticiones AMI encoladas con su respectivo callback.
    // List of queued AMI requests with their respective callbacks
    private $_queue_requests = array();

    /* Era FALSE: los operadores ++ y -- de PHP no alteran un booleano, así que
     * el guard de reentrada de __call() jamás se disparaba y una llamada
     * síncrona anidada terminaba esperando dentro de otra espera.
     * Was FALSE: PHP's ++ and -- never change a boolean, so the reentrancy
     * guard in __call() never fired and a nested synchronous call ended up
     * waiting inside another wait. */
    private $_sync_wait = 0;

    // Secuencia para los ActionID generados cuando el llamante no da uno
    // Sequence for the ActionIDs generated when the caller gives none
    private $_nActionID = 0;

    // Límite de espera de una respuesta AMI síncrona, en segundos
    // Bound for waiting on a synchronous AMI reply, in seconds
    const AMI_RESPONSE_TIMEOUT = 10;

    // Definiciones de los comandos AMI conocidos
    // Definitions of known AMI commands
    private $_ami_cmds = array(
        'AbsoluteTimeout' =>
            array('Channel' => TRUE, 'Timeout' => TRUE),
        'AgentCallbackLogin' =>
            array('Agent' => TRUE, 'Exten' => TRUE, 'Context' => TRUE,
                'AckCall' => array('required' => FALSE, 'default' => 'true')),
        'AgentLogin' =>
            array('Agent'=> TRUE, 'Channel' => TRUE),
        // Agentlogoff: used by chan_agent (Asterisk 11), not available in app_agent_pool (12+)
        'Agentlogoff' =>
            array('Agent' => TRUE),
        'Agents' =>
            array('ActionID' => FALSE),
        'Atxfer' =>
            array('Channel' => TRUE, 'Exten' => TRUE, 'Context' => TRUE, 'Priority' => TRUE),
        'Bridge' =>
            array('Channel1' => TRUE, 'Channel2' => TRUE, 'Tone' => FALSE),
        'ChangeMonitor' =>
            array('Channel' => TRUE, 'File' => TRUE),
        'Command' =>
            array('Command' => TRUE, 'ActionID' => FALSE),
        'CoreSettings' =>
            array(),
        'CoreShowChannels' =>
            array('ActionID' => FALSE),
        'CoreStatus' =>
            array(),
        'DAHDIShowChannels' =>
            array('DAHDIChannel' => FALSE, 'ActionID' => FALSE),
        'Events' =>
            array('EventMask' => TRUE),
        'ExtensionState' =>
            array('Exten' => TRUE, 'Context' => TRUE, 'ActionID' => FALSE),
        'Filter' =>
            array('Filter' => TRUE, 'Operation' => array('required' => FALSE, 'default' => 'Add')),
        'GetVar' =>
            array('Channel' => TRUE, 'Variable' => TRUE, 'ActionID' => FALSE),
        'Hangup' =>
            array('Channel' => TRUE),
        //'Hold' =>
        //    array(),
        'IAXPeers' =>
            array(),
        'IAXpeerlist' =>
            array('ActionID' => FALSE),
        'ListCommands' =>
            array('ActionID' => FALSE),
        'Login' =>
            array('Username' => TRUE, 'Secret' => TRUE),
        'Logoff' =>
            array(),
        'MailboxCount' =>
            array('Mailbox' => TRUE, 'ActionID' => FALSE),
        'MailboxStatus' =>
            array('Mailbox' => TRUE, 'ActionID' => FALSE),
        'MeetmeList' =>
            array('Conference' => FALSE, 'ActionID' => FALSE),
        'MixMonitor' =>
            array('Channel' => TRUE, 'File' => FALSE, 'Options' => FALSE, 'ActionID' => FALSE),
        'MixMonitorMute' =>
            array('Channel' => TRUE,
                'State' => array('required' => TRUE, 'cast' => 'bool'),
                'Direction' => array('required' => FALSE, 'default' => 'read'),
                'ActionID' => FALSE),
        'Monitor' =>
            array('Channel' => TRUE, 'File' => FALSE, 'Format' => FALSE,
                'Mix' => array('depends' => 'File', 'required' => TRUE, 'default' => FALSE, 'cast' => 'bool'),
            ),
        'Originate' =>
            array('Channel' => TRUE, 'Exten' => FALSE, 'Context' => FALSE,
                'Priority' => FALSE, 'Application' => FALSE, 'Data' => FALSE,
                'Timeout' => FALSE, 'CallerID' => FALSE, 'Variable' => FALSE,
                'Account' => FALSE, 'Async' => array('required' => FALSE, 'cast' => 'bool'),
                'ActionID' => FALSE),
        'ParkedCalls' =>
            array('ActionID' => FALSE),
        'Parkinglots' =>
            array(), /* ActionID no está soportado para Parkinglots en 11.21.0
                      ActionID is not supported for Parkinglots in 11.21.0 */
        'Park' =>
            array('Channel' => TRUE, 'AnnounceChannel' => FALSE,
                'Timeout' => array('required' => FALSE, 'cast' => 'int'),
                'Parkinglot' => FALSE),
        'Ping' =>
            array(),
        'QueueAdd' =>
            array('Queue' => TRUE, 'Interface' => TRUE,
                'Penalty' => array('required' => FALSE, 'default' => 0, 'cast' => 'int'),
                'MemberName' => FALSE,
                'Paused' => array('required' => FALSE, 'default' => FALSE, 'cast' => 'bool'),
                'StateInterface' => FALSE),  // For app_agent_pool: Agent:XXXX device state
        'QueueRemove' =>
            array('Queue' => TRUE, 'Interface' => TRUE),
        'Queues' =>
            array(),
        'QueuePause' =>
            array('Queue' => FALSE, 'Interface' => TRUE,
                'Paused' => array('required' => FALSE, 'default' => TRUE, 'cast' => 'bool'),
                'Reason' => array('required' => FALSE, 'default' => '')
                ),
        'QueueStatus' =>
            array('Queue' => FALSE, 'ActionID' => FALSE),
        'Redirect' =>
            array('Channel' => TRUE, 'ExtraChannel' => FALSE, 'Exten' => TRUE,
                'Context' => TRUE, 'Priority' => TRUE,
                'ExtraExten' => FALSE, 'ExtraContext' => FALSE, 'ExtraPriority' => FALSE),
        'SetCDRUserField' =>
            array('UserField' => TRUE, 'Channel' => TRUE, 'Append' => FALSE),
        'SetVar' =>
            array('Channel' => TRUE, 'Variable' => TRUE, 'Value' => TRUE),
        'SIPnotify' =>
            array('Channel' => TRUE, 'Variable' => array('required' => TRUE, 'cast' => 'sipnotify'),
                'ActionID' => FALSE),
        'SIPPeers' =>
            array('ActionID' => FALSE),
        'Status' =>
            array('Channel' => TRUE, 'ActionID' => FALSE),
        'StopMixMonitor' =>
            array('Channel' => TRUE, 'MixMonitorID' => FALSE, 'ActionID' => FALSE),
        'StopMonitor' =>
            array('Channel' => TRUE),
        'ZapDialOffhook' =>
            array('ZapChannel' => TRUE, 'Number' => TRUE),
        'ZapDNDoff' =>
            array('ZapChannel' => TRUE),
        'ZapDNDon' =>
            array('ZapChannel' => TRUE),
        'ZapHangup' =>
            array('ZapChannel' => TRUE),
        'ZapTransfer' =>
            array('ZapChannel' => TRUE),
        'ZapShowChannels' =>
            array('ActionID' => FALSE),
    );

    function __construct($dialSrv, $oMainLog)
    {
        $this->oLogger = $oMainLog;
        $this->multiplexSrv = $dialSrv;
    }

    // Datos a mandar a escribir apenas se inicia la conexión
    // Data to write as soon as the connection is established
    function procesarInicial() {}

    // Separar flujo de datos en paquetes, devuelve número de bytes de paquetes aceptados
    // Separate data stream into packets, returns number of bytes of accepted packets
    function parsearPaquetes($sDatos)
    {
        $iLongInicial = strlen($sDatos);

        // Encontrar los paquetes y determinar longitud de búfer procesado
        // Find the packets and determine processed buffer length
        $listaPaquetes =& $this->encontrarPaquetes($sDatos);
        $iLongFinal = strlen($sDatos);

        /* Paquetes Event se van a la lista de eventos. El paquete Response se
         * guarda individualmente.
         * Event packets go to the event list. The Response packet is stored
         * individually. */
        $local_timestamp_received = NULL;
        foreach ($listaPaquetes as $paquete) {
            if (isset($paquete['Event'])) {
                $e = strtolower($paquete['Event']);
                if (!isset($this->cuentaEventos[$e]))
                    $this->cuentaEventos[$e] = 0;
                $this->cuentaEventos[$e]++;
                if (isset($this->event_handlers[$e]) || isset($this->event_handlers['*'])) {
                    if (is_null($local_timestamp_received))
                        $local_timestamp_received = microtime(TRUE);
                    $paquete['local_timestamp_received'] = $local_timestamp_received;
                    $this->_listaEventos[] = $paquete;
                }
            } elseif (isset($paquete['Response'])) {
                if (is_null($local_timestamp_received))
                    $local_timestamp_received = microtime(TRUE);
                $paquete['local_timestamp_received'] = $local_timestamp_received;
                $this->_listaEventos[] = $paquete;
            } else {
                $this->oLogger->output("ERR: el siguiente paquete no se reconoce como Event o Response: ".
                    print_r($paquete, 1));
            }
        }

        return $iLongInicial - $iLongFinal;
    }

    /**
     * Procedimiento que intenta descomponer el búfer de lectura indicado por $sDatos
     * en una secuencia de paquetes de AMI (Asterisk Manager Interface). La lista de
     * paquetes obtenida se devuelve como una lista. Además el búfer de lectura se
     * modifica para eliminar los datos que fueron ya procesados como parte de los
     * paquetes. Esta función sólo devuelve paquetes completos, y deja cualquier
     * fracción de paquetes incompletos en el búfer.
     * Procedure that attempts to decompose the read buffer indicated by $sDatos
     * into a sequence of AMI (Asterisk Manager Interface) packets. The list of
     * obtained packets is returned as a list. Additionally, the read buffer is
     * modified to remove data that has already been processed as part of the
     * packets. This function only returns complete packets, and leaves any
     * fraction of incomplete packets in the buffer.
     *
     * @param   string  $sDatos     Cadena de datos a procesar
     *                              Data string to process
     *
     * @return  array   Lista de paquetes que fueron extraídos del texto.
     *                  List of packets that were extracted from the text.
     */
    private function & encontrarPaquetes(&$sDatos)
    {
        $len = strlen($sDatos);
        $p_paquetes = 0;// offset de paquetes válidos
                        // valid packets offset
        $p1 = 0;        // offset de línea actual a procesar
                        // current line offset to process
        $p2 = FALSE;    // posición de siguiente \n o FALSE
                        // position of next \n or FALSE
        $bEsperando_END_COMMAND = FALSE;

        $listaPaquetes = array();
        $paquete = array();
        $bIncompleto = FALSE;
        while (!$bIncompleto && $p1 < $len) {
            $p2 = strpos($sDatos, "\r\n", $p1);
            $bIncompleto = ($p2 === FALSE);
            if (!$bIncompleto) {
                $s = substr($sDatos, $p1, $p2 - $p1);
                $p2 += 2; // saltar el \r\n
                          // skip the \r\n
                $a = strpos($s, ': ');
                $sClave = $sValor = NULL;
                $bProcesando_END_COMMAND = FALSE;
                if ($a) {
                    $sClave = substr($s, 0, $a);
                    $sValor = substr($s, $a + 2);
                    if (!$bEsperando_END_COMMAND && $sClave == 'Response' && $sValor == 'Follows') {
                        $bEsperando_END_COMMAND = TRUE;
                    } elseif ($sClave == 'Output') {
                        // Asterisk 16
			#$paquete['data'].=$sValor."\n";
                        $paquete['data'] = (isset($paquete['data']) ? $paquete['data'] : '') . $sValor . "\n";
                    } elseif ($bEsperando_END_COMMAND && !in_array($sClave, array('Privilege', 'ActionID'))) {
                        $sClave = $sValor = NULL;
                        $bProcesando_END_COMMAND = TRUE;
                    }
                } else {
                    if ($bEsperando_END_COMMAND)
                        $bProcesando_END_COMMAND = TRUE;
                }

                if ($bProcesando_END_COMMAND) {
                    $sCmdEnd = "--END COMMAND--\r\n";
                    $p2 = strpos($sDatos, $sCmdEnd, $p1);
                    if ($p2 === FALSE) {
                        $bIncompleto = TRUE;
                    } else {
                        $bEsperando_END_COMMAND = FALSE;
                        $paquete['data'] = substr($sDatos, $p1, $p2 - $p1);
                        $p2 += strlen($sCmdEnd);
                        $p1 = $p2;
                    }
                } elseif (!is_null($sClave)) {
                    $paquete[$sClave] = $sValor;
                    $p1 = $p2;
                } elseif ($s == '') {
                    // Se ha encontrado el final de un paquete
                    // End of a packet has been found
                    if (count($paquete)) $listaPaquetes[] = $paquete;
                    $p1 = $p2;
                    $p_paquetes = $p1;
                    $paquete = array();
                } else {
                    // Se ignora error de protocolo
                    // Protocol error is ignored
                    $p1 = $p2;
                }
            }
        }

        $sDatos = substr($sDatos, $p_paquetes);
        return $listaPaquetes;
    }

    // Procesar cierre de la conexión
    // Process connection closure
    function procesarCierre()
    {
        $this->oLogger->output("INFO: detectado cierre de conexión Asterisk.");
        $this->sKey = NULL;
    }

    // Preguntar si hay paquetes pendientes de procesar
    // Check if there are pending packets to process
    function hayPaquetes() { return (count($this->_listaEventos) > 0); }

    // Procesar un solo paquete de la cola de paquetes
    // Process a single packet from the packet queue
    function procesarPaquete()
    {
        // Intentar manejar paquetes hasta que uno sea aceptado
        // Try to handle packets until one is accepted
        $manejado = FALSE;
        while (count($this->_listaEventos) > 0 && !$manejado) {
            $paquete = array_shift($this->_listaEventos);
            $manejado = $this->process_event($paquete);
        }
    }

    /* Espera a lo más self::AMI_RESPONSE_TIMEOUT segundos la respuesta de la
     * petición cuyo ActionID se indica. Al expirar se abandona la petición: se
     * quita de la cola (enviada o no) para que su respuesta tardía se
     * descarte, se reanuda el envío si era la petición en vuelo, y se
     * devuelve un Error sintético en vez de esperar para siempre.
     * Waits at most self::AMI_RESPONSE_TIMEOUT seconds for the reply of the
     * request with the given ActionID. On expiry the request is abandoned:
     * removed from the queue (sent or not) so its late reply is dropped,
     * sending is resumed if it was the in-flight request, and a synthetic
     * Error is returned instead of waiting forever. */
    private function wait_response($actionid)
    {
        $tInicio = microtime(TRUE);
        while (!is_null($this->sKey) && is_null($this->_response)) {
            if ((microtime(TRUE) - $tInicio) >= self::AMI_RESPONSE_TIMEOUT) {
                $this->oLogger->output('ERR: AMIClientConn::wait_response timeout de '.
                    self::AMI_RESPONSE_TIMEOUT.'s esperando respuesta AMI, se abandona '.
                    'petición ActionID='.$actionid.' | EN: AMIClientConn::wait_response '.
                    'timeout of '.self::AMI_RESPONSE_TIMEOUT.'s waiting for AMI reply, '.
                    'abandoning request ActionID='.$actionid);
                $this->_quitarPeticion($actionid);
                return array('Response' => 'Error',
                    'Message' => '(internal) AMI response timeout');
            }
            $this->multiplexSrv->procesarActividad(1);

            /* Se requiere recorrer la lista de eventos recogiendo los
             * paquetes Response en el orden que fueron insertados, para
             * mantener el orden de procesamiento.
             * It is required to traverse the event list collecting the
             * Response packets in the order they were inserted, to
             * maintain the processing order. */
            $t = array();
            foreach ($this->_listaEventos as $paquete) {
                if (isset($paquete['Event'])) {
                    $t[] = $paquete;
                } else {
                    $this->process_event($paquete);
                }
            }
            $this->_listaEventos = $t;
        }
        if (!is_null($this->_response)) {
            $r = $this->_response;
            $this->_response = NULL;
            return $r;
        }
        if (is_null($this->sKey)) {
            $this->oLogger->output('ERR: '.__METHOD__.' conexión AMI cerrada mientras se esperaba respuesta.');
            return NULL;
        }
    }

    /* Quitar de la cola la petición con el ActionID dado, esté enviada o no.
     * Si era la petición en vuelo se reanuda el envío: el cliente es
     * stop-and-wait, y sin esto todo lo que quedó detrás seguiría sin
     * enviarse. Remove from the queue the request with the given ActionID,
     * sent or not. If it was the in-flight request, sending is resumed: the
     * client is stop-and-wait, and without this everything queued behind it
     * would stay unsent. */
    private function _quitarPeticion($actionid)
    {
        foreach ($this->_queue_requests as $i => $info) {
            if ($info[4] === $actionid) {
                $bEraEnVuelo = is_null($info[0]);
                array_splice($this->_queue_requests, $i, 1);
                if ($bEraEnVuelo) $this->_send_next_request();
                return;
            }
        }
    }

    function connect($server, $username, $secret)
    {
        // Determinar servidor y puerto a usar
        // Determine server and port to use
        $iPuerto = AMI_PORT;
        if(strpos($server, ':') !== false) {
            $c = explode(':', $server);
            $server = $c[0];
            $iPuerto = $c[1];
        }
        $this->server = $server;
        $this->port = $iPuerto;

        // Iniciar la conexión
        // Start the connection
        $errno = $errstr = NULL;
        $sUrlConexion = "tcp://$server:$iPuerto";
        $hConn = @stream_socket_client($sUrlConexion, $errno, $errstr);
        if (!$hConn) {
            $this->oLogger->output("ERR: no se puede conectar a puerto AMI en $sUrlConexion: ($errno) $errstr");
            return FALSE;
        }

        // Leer la cabecera de Asterisk
        // Read the Asterisk header
        $str = fgets($hConn);
        if ($str == false) {
            $this->oLogger->output("ERR: No se ha recibido la cabecera de Asterisk Manager");
            return false;
        }

        // Registrar el socket con el objeto de conexiones
        // Register the socket with the connection object
        $this->multiplexSrv->agregarNuevaConexion($this, $hConn);

        // Iniciar login con Asterisk
        // Start login with Asterisk
        $res = $this->Login($username, $secret);
        if($res['Response'] != 'Success') {
            $this->oLogger->output("ERR: Fallo en login de AMI.");
            $this->disconnect();
            return false;
        }
        return true;
    }

    function disconnect()
    {
        $this->Logoff();
        $this->multiplexSrv->marcarCerrado($this->sKey);
    }

    function finalizarConexion()
    {
        if (!is_null($this->sKey)) {
            $this->disconnect();
        }
    }

   // *********************************************************************************************************
   // **                       COMMANDS                                                                      **
   // *********************************************************************************************************

    private function _die_log($msg)
    {
        if (!is_null($this->oLogger))
            $this->oLogger->output('FATAL: '.$msg);
        die("$msg\n");
    }

    private function _ami_encode_param($v, $cast = NULL)
    {
        if (!is_null($cast)) switch ($cast) {
        case 'bool':
            if (!in_array($v, array('true', 'false')))
                $v = $v ? TRUE : FALSE;
            break;
        case 'int':
            $v = (int)$v;
            break;
        case 'sipnotify':
            if (is_array($v)) {
                /* Each key specifies a SIP header and is encoded as key=value. The
                 * 'Content' key is a special case in that it specifies the body of the
                 * SIP NOTIFY, and also that it must be encoded as multiple Content=
                 * tokens, one per line. The double-quote, backslash, bracket and
                 * parentheses characters are special and must be escaped. Additionally
                 * the comma is escaped if it appears in the value. */
                $vl = array();
                $escapelist = "[](),\"\\";
                foreach($v as $k => $v) {
                    if($k == 'Content') {
                        // This will cause \n between lines to be reassembled as \r\n
                        foreach(preg_split("/\r?\n/", $v) as $s)
                            $vl[] = addcslashes($k, $escapelist).'='.addcslashes($s, $escapelist);
                    }
                    else
                        $vl[] = addcslashes($k, $escapelist).'='.addcslashes($v, $escapelist);
                }
                $v = implode(',', $vl);
            }
            break;
        }
        if (is_bool($v)) return $v ? 'true' : 'false';
        return "$v";
    }

    public function __call($name, $args)
    {
        $async = FALSE;
        $callback = array($this, '_emulate_sync_response');
        $callback_params = array();
        if (strlen($name) > 5 && substr($name, 0, 5) == 'async') {
            $callback = array_shift($args);
            $callback_params = array_shift($args);
            if (is_null($callback_params)) $callback_params = array();
            $name = substr($name, 5);
            $async = TRUE;
        }

        if (!isset($this->_ami_cmds[$name]))
            $this->_die_log('Undefined AMI request: '.$name);

        if (!$async && $this->_sync_wait > 0) {
            if (!is_null($this->oLogger)) {
                $this->oLogger->output('WARN: '.__METHOD__.' rechaza comando síncrono '.
                    'reentrante '.$name.' porque ya hay una espera síncrona en curso '.
                    '| EN: refusing reentrant synchronous command '.$name.
                    ': another synchronous wait is in progress');
            }
            return array(
                'Response'  => 'Failure',
                'Message' => '(internal) Avoided reentrant synchronous command.',
            );
        }

        $i = 0; $parameters = array();
        foreach ($this->_ami_cmds[$name] as $k => $prop) {
            $required = FALSE;
            $default = NULL;
            $cast = NULL;
            $depends = NULL;
            if (is_bool($prop)) {
                $required = $prop;
            } elseif (is_array($prop)) {
                if (isset($prop['required'])) $required = $prop['required'];
                if (isset($prop['default'])) $default = $prop['default'];
                if (isset($prop['cast'])) $cast = $prop['cast'];
                if (isset($prop['depends'])) $depends = $prop['depends'];
            }

            if (is_null($depends) || isset($parameters[$depends])) {
                if ($i < count($args) && !is_null($args[$i]))
                    $parameters[$k] = $this->_ami_encode_param($args[$i], $cast);
                elseif (!is_null($default))
                    $parameters[$k] = $this->_ami_encode_param($default, $cast);
                elseif ($required)
                    $this->_die_log('AMI request '.$name.' requires value for '.$k);
            }
            $i++;
        }

        /* Toda petición lleva exactamente un ActionID: el del llamante si lo
         * dio, o uno generado con un prefijo que ningún llamante usa, para que
         * cada Response se aparee con su propia petición y no por orden de
         * cola. Nunca se añade una segunda cabecera.
         * Every request carries exactly one ActionID: the caller's own if it
         * gave one, or a generated one with a prefix no caller uses, so each
         * Response is paired with its own request and not by queue order. A
         * second header is never appended. */
        if (!isset($parameters['ActionID'])) {
            $this->_nActionID++;
            $parameters['ActionID'] = 'AMIW-'.posix_getpid().'-'.$this->_nActionID;
        }

        // Cadena de petición
        // Request string
        $req = "Action: $name\r\n";
        foreach($parameters as $var => $val) $req .= "$var: $val\r\n";
        $req .= "\r\n";

        $request_info = array($req, $callback, $callback_params, microtime(TRUE),
            $parameters['ActionID']);

        if (!$async) $this->_sync_wait++;
        if ($async) {
            // Poner la petición asíncrona al final de la cola
            // Put the asynchronous request at the end of the queue
            array_push($this->_queue_requests, $request_info);
        } else {
            // Poner la petición síncrona como primera de las NO enviadas
            // Put the synchronous request as first among the NOT sent
            $head_req = NULL;
            if (count($this->_queue_requests) > 0 && is_null($this->_queue_requests[0][0]))
                $head_req = array_shift($this->_queue_requests);
            array_unshift($this->_queue_requests, $request_info);
            if (!is_null($head_req))
                array_unshift($this->_queue_requests, $head_req);
            $head_req = NULL;
        }
        try {
            $r = $this->_send_next_request();
            $r = ($r && !$async) ? $this->wait_response($parameters['ActionID']) : NULL;
        } finally {
            /* try/finally: una excepción dentro de la espera no debe dejar el
             * contador elevado, o el guard rechazaría todo comando síncrono
             * para siempre. try/finally: an exception inside the wait must not
             * leave the counter raised, or the guard would refuse every
             * synchronous command forever. */
            if (!$async) $this->_sync_wait--;
        }
        return $r;
    }

    private function _emulate_sync_response($paquete)
    {
        if (!is_null($this->_response)) {
            $this->oLogger->output('ERR: '.__METHOD__.' segundo Response sobreescribe primer Response no procesado: '.
                print_r($this->_response, 1));
        }
        $this->_response = $paquete;
    }

    private function _send_next_request()
    {
        if (count($this->_queue_requests) <= 0) return TRUE;    // no hay más peticiones
                                                                // no more requests
        if (is_null($this->_queue_requests[0][0])) return TRUE; // petición en progreso
                                                                // request in progress
        if (is_null($this->sKey)) {
            if (!is_null($this->oLogger))
                $this->oLogger->output('ERR: '.__METHOD__.' conexión AMI cerrada mientras se enviaba petición.');
            return FALSE;
        }
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $this->_queue_requests[0][0]);
        $this->_queue_requests[0][0] = NULL;
        return TRUE;
    }

    // *********************************************************************************************************
    // **                       MISC                                                                          **
    // *********************************************************************************************************


   /**
    * Add event handler
    *
    * Known Events include ( http://www.voip-info.org/wiki-asterisk+manager+events )
    *   Link - Fired when two voice channels are linked together and voice data exchange commences.
    *   Unlink - Fired when a link between two voice channels is discontinued, for example, just before call completion.
    *   Newexten -
    *   Hangup -
    *   Newchannel -
    *   Newstate -
    *   Reload - Fired when the "RELOAD" console command is executed.
    *   Shutdown -
    *   ExtensionStatus -
    *   Rename -
    *   Newcallerid -
    *   Alarm -
    *   AlarmClear -
    *   Agentcallbacklogoff -
    *   Agentcallbacklogin -
    *   Agentlogoff -
    *   MeetmeJoin -
    *   MessageWaiting -
    *   join -
    *   leave -
    *   AgentCalled -
    *   ParkedCall - Fired after ParkedCalls
    *   Cdr -
    *   ParkedCallsComplete -
    *   QueueParams -
    *   QueueMember -
    *   QueueStatusEnd -
    *   Status -
    *   StatusComplete -
    *   ZapShowChannels - Fired after ZapShowChannels
    *   ZapShowChannelsComplete -
    *
    * @param string $event type or * for default handler
    * @param string $callback function
    * @return boolean sucess
    */
    function add_event_handler($event, $callback)
    {
      $event = strtolower($event);
      if(isset($this->event_handlers[$event]))
      {
        $this->oLogger->output("WARN: $event handler is already defined, not over-writing.");
        return false;
      }
      $this->event_handlers[$event] = $callback;
      return true;
    }

    function remove_event_handler($event)
    {
        $event = strtolower($event);
    	if (isset($this->event_handlers[$event])) {
    		unset($this->event_handlers[$event]);
    	}
    }

   /**
    * Process event
    *
    * @access private
    * @param array $parameters
    * @return mixed result of event handler or false if no handler was found
    */
    private function process_event($parameters)
    {
        $ret = false;
        $handler = '';

        if (isset($parameters['Event'])) {
            $e = strtolower($parameters['Event']);

            if (isset($this->event_handlers[$e]))
                $handler = $this->event_handlers[$e];
            elseif (isset($this->event_handlers['*']))
                $handler = $this->event_handlers['*'];
            $handler_params = array($e, $parameters, $this->server, $this->port);
        } elseif (isset($parameters['Response'])) {
            if (count($this->_queue_requests) <= 0) {
                if (!is_null($this->oLogger)) {
                    $this->oLogger->output('ERR: '.__METHOD__.' se pierde respuesta porque no hay callback encolado: '.
                        print_r($parameters, TRUE));
                }
                return FALSE;
            }

            /* Aparear por ActionID: la respuesta pertenece a la petición más
             * antigua de la cola con ese mismo ActionID. Una respuesta sin
             * ActionID (una versión que no lo repita) se aparea con la primera
             * petición de la cola, que en una conexión FIFO es la única
             * candidata. Nunca se acredita a una petición con ActionID
             * distinto. Pair by ActionID: the response belongs to the oldest
             * queued request carrying that same ActionID. A response without
             * an ActionID (a version that does not echo one) pairs with the
             * first queued request, the only candidate on a FIFO connection.
             * A response is never credited to a request with a different
             * ActionID. */
            $iIdx = 0;
            if (isset($parameters['ActionID'])) {
                $iIdx = NULL;
                foreach ($this->_queue_requests as $i => $info) {
                    if ($info[4] === $parameters['ActionID']) { $iIdx = $i; break; }
                }
                if (is_null($iIdx)) {
                    if (!is_null($this->oLogger)) {
                        $this->oLogger->output('ERR: '.__METHOD__.' se pierde respuesta con '.
                            'ActionID desconocido: '.print_r($parameters, TRUE).
                            ' | EN: dropping response with unknown ActionID');
                    }
                    return FALSE;
                }
            }
            $callback_info = array_splice($this->_queue_requests, $iIdx, 1);
            $callback_info = $callback_info[0];
            if (!is_null($callback_info[0])) {
                if (!is_null($this->oLogger))
                    $this->oLogger->output('ERR: '.__METHOD__.' petición head NO ha sido enviada: '.$callback_info[0]);
            }
            $handler = $callback_info[1];
            $handler_params = $callback_info[2];
            $parameters['local_timestamp_sent'] = $callback_info[3];
            array_unshift($handler_params, $parameters);

            $this->_send_next_request();
        }

        if (is_callable($handler)) {
            $ret = call_user_func_array($handler, $handler_params);
            $ret = ($ret !== 'AMI_EVENT_DISCARD');
        }
        return $ret;
    }

    function parse_database_data($data)
    {
        $data = explode("\n", $data);
        $db = array();

        foreach ($data as $line) {
            $temp = explode(":",$line);
            if (count($temp) >= 2) $db[ trim($temp[0]) ] = trim($temp[1]);
        }
        return $db;
    }

    /** Show all entries in the asterisk database
     * @return Array associative array of key=>value
     */
    function database_show($family = NULL, $keytree = NULL) {
        $c = 'database show';
        if (!is_null($family)) $c .= ' '.$family;
        if (!is_null($keytree)) $c .= ' '.$keytree;
        $r = $this->Command($c);

        $this->raw_response = NULL;
        if (!is_array($r) || !isset($r['data'])) {
            $this->raw_response = $r;
            return NULL;
        }

        return $this->parse_database_data($r['data']);
    }

    function database_showkey($key)
    {
        $r = $this->Command("database showkey $key");

        $this->raw_response = NULL;
        if (!is_array($r) || !isset($r['data'])) {
            $this->raw_response = $r;
            return NULL;
        }

        return $this->parse_database_data($r['data']);
    }

    /* Constructores compartidos del comando de escritura en AstDB: las formas
     * síncrona y asíncrona no pueden divergir. Shared builders of the AstDB
     * write command: the sync and async forms cannot drift apart. */
    private function _database_put_cmd($family, $key, $value)
    {
        /* El valor viaja como UN argumento citado: el CLI de Asterisk parte la
         * línea en espacios fuera de comillas (main/cli.c parse_args) y
         * "database put" acepta exactamente tres argumentos (main/db.c), así
         * que un valor con espacios - p.ej. un motivo de pausa que es el
         * nombre de una pausa con espacios - daría argumentos de más y no se
         * escribiría nada. La barra invertida se escapa junto con la comilla
         * en una sola pasada, en ese orden, para que el propio escape no sea
         * re-escapado; CR y LF no pueden viajar en una cabecera AMI y se
         * sustituyen por un espacio.
         * The value travels as ONE quoted argument: Asterisk's CLI splits the
         * line on spaces outside quotes (main/cli.c parse_args) and
         * "database put" takes exactly three arguments (main/db.c), so a value
         * with spaces - e.g. a pause reason that is the name of a break with
         * spaces - would add extra arguments and nothing would be written.
         * The backslash is escaped together with the double quote in one
         * pass, in that order, so the escaping itself is not re-escaped; CR
         * and LF cannot travel in an AMI header and are replaced by a
         * space. */
        $v = str_replace(array("\r", "\n"), ' ', "$value");
        $v = addcslashes($v, '\\"');
        return "database put ".str_replace(" ","/",$family)." ".str_replace(" ","/",$key)." \"$v\"";
    }

    private function _database_del_cmd($family, $key)
    {
        return "database del ".str_replace(" ","/",$family)." ".str_replace(" ","/",$key);
    }

    /** Async twin of database_put: same command string, queued without
     * waiting for the reply. */
    function database_put_async($callback, $callback_params, $family, $key, $value)
    {
        return $this->asyncCommand($callback, $callback_params,
            $this->_database_put_cmd($family, $key, $value));
    }

    /** Async twin of database_del: same command string, queued without
     * waiting for the reply. */
    function database_del_async($callback, $callback_params, $family, $key)
    {
        return $this->asyncCommand($callback, $callback_params,
            $this->_database_del_cmd($family, $key));
    }

    /** Add an entry to the asterisk database
     * @param string $family    The family name to use
     * @param string $key       The key name to use
     * @param mixed $value      The value to add
     * @return bool True if successful
     */
    function database_put($family, $key, $value) {
        $r = $this->Command($this->_database_put_cmd($family, $key, $value));

        $this->raw_response = NULL;
        if (!is_array($r) || !isset($r['data'])) {
            $this->raw_response = $r;
            return FALSE;
        }

        return (bool)strstr($r["data"], "success");
    }

    /** Get an entry from the asterisk database
     * @param string $family    The family name to use
     * @param string $key       The key name to use
     * @return mixed Value of the key, or false if error
     */
    function database_get($family, $key) {
        $r = $this->Command("database get ".str_replace(" ","/",$family)." ".str_replace(" ","/",$key));

        $this->raw_response = NULL;
        if (!is_array($r) || !isset($r['data'])) {
            $this->raw_response = $r;
            return FALSE;
        }

        $lineas = explode("\r\n", $r["data"]);
        while (count($lineas) > 0) {
            if (substr($lineas[0],0,6) == "Value:") {
                return trim(substr(join("\r\n", $lineas),6));
            }
            array_shift($lineas);
        }
        return false;
    }

    /** Delete an entry from the asterisk database
     * @param string $family    The family name to use
     * @param string $key       The key name to use
     * @return bool True if successful
     */
    function database_del($family, $key) {
        $r = $this->Command($this->_database_del_cmd($family, $key));

        $this->raw_response = NULL;
        if (!is_array($r) || !isset($r['data'])) {
            $this->raw_response = $r;
            return FALSE;
        }

        return (bool)strstr($r["data"], "removed");
    }
}
?>

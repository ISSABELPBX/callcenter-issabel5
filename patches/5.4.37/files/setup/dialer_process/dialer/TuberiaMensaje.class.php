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
  $Id: TuberiaMensaje.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */


/**
 * Esta clase implementa una comunicación interproceso bidireccional orientada
 * a mensajes usando stream_socket_pair y (un)serialize. Se soporta mensajes
 * asíncronos, y llamadas de procedimiento síncronas. Esta clase funciona en
 * cooperación con la clase HubServer.
 * This class implements a bidirectional message-oriented interprocess
 * communication using stream_socket_pair and (un)serialize. It supports
 * asynchronous messages and synchronous procedure calls. This class works
 * in cooperation with the HubServer class.
 */
class TuberiaMensaje extends MultiplexConn
{
    private $_nombreFuente;             // La fuente de mensajes que llegan
                                        // The source of incoming messages
    private $_socks = NULL;             // Par de sockets antes de fork()
                                        // Pair of sockets before fork()
    private $_listaEventos = array();   // Eventos pendientes por procesar
                                        // Events pending processing
    private $_respuesta = NULL;         // Respuesta recibida del último comando
                                        // Response received from the last command
    private $_hayRespuesta = FALSE;     // VERDADERO si hay respuesta
                                        // TRUE if there is a response
    private $_rutearRespuesta = FALSE;  // Si VERDADERO, rutear respuesta
                                        // If TRUE, route response
    private $_log = NULL;               // Log de depuración
                                        // Debug log

    // Manejadores de eventos: _manejadoresEventos[$fuente][$nombreMsg] = array($obj, $metodo)
    // Event handlers: _manejadoresEventos[$source][$msgName] = array($obj, $method)
    private $_manejadoresEventos = array();

    function __construct($sFuente)
    {
        $this->_nombreFuente = $sFuente;
        $this->_socks = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP);
    }

    function setLog($l) { $this->_log = $l; }

    // Registrar esta tubería a un MultiplexServer, lado padre
    // Register this pipe to a MultiplexServer, parent side
    function registrarMultiplexPadre($multiplexSrv)
    {
    	if (!is_null($this->_socks)) {
            fclose($this->_socks[1]);  // Se cierra el socket del hijo
                                        // Close the child socket
            $multiplexSrv->agregarNuevaConexion($this, $this->_socks[0]);
            $this->_socks = NULL;
            $this->_rutearRespuesta = TRUE;
        }
    }

    // Registrar esta tubería a un MultiplexServer, lado hijo
    // Register this pipe to a MultiplexServer, child side
    function registrarMultiplexHijo($multiplexSrv)
    {
        if (!is_null($this->_socks)) {
            fclose($this->_socks[0]);  // Se cierra el socket del padre
                                        // Close the parent socket
            $multiplexSrv->agregarNuevaConexion($this, $this->_socks[1]);
            $this->_socks = NULL;
        }
    }

    // Datos a mandar a escribir apenas se inicia la conexión
    // Data to send for writing as soon as the connection starts
    function procesarInicial() {}

    function finalizarConexion()
    {
        if (!is_null($this->sKey)) {
            $this->multiplexSrv->marcarCerrado($this->sKey);
        }
    }

    /**
     * Procedimiento que intenta descomponer el búfer de lectura indicado por
     * $sDatos en una secuencia de paquetes del protocolo interno de RPC. Cada
     * paquete del protocolo interno consiste de una cabecera de 4 bytes que
     * indica la longitud, seguida de los datos reales, serializados. La lista
     * de paquetes obtenida se devuelve como una lista. Además el búfer de
     * lectura se modifica para eliminar los datos que fueron ya procesados como
     * parte de los paquetes. Esta función sólo devuelve paquetes completos, y
     * deja cualquier fracción de paquetes incompletos en el búfer.
     * Procedure that attempts to break down the read buffer indicated by
     * $sDatos into a sequence of internal RPC protocol packets. Each internal
     * protocol packet consists of a 4-byte header indicating the length,
     * followed by the actual serialized data. The list of obtained packets is
     * returned as a list. Additionally, the read buffer is modified to remove
     * data that was already processed as part of the packets. This function
     * only returns complete packets and leaves any incomplete packet fragments
     * in the buffer.
     *
     * @param   string  $sDatos     Cadena de datos a procesar
     *                              Data string to process
     *
     * @return  array   Lista de paquetes que fueron extraídos del texto.
     *                  List of packets that were extracted from the text.
     */
    private function _encontrarPaquetes(&$sDatos)
    {
        $listaPaquetes = array();
    	$bHayDatos = TRUE;
        do {
        	$bHayDatos = (strlen($sDatos) >= 4);
            if ($bHayDatos) {
            	$l = unpack('Llongitud', $sDatos);
                $bHayDatos = ($l['longitud'] + 4 <= strlen($sDatos));
            }
            if ($bHayDatos) {
            	$l = unpack("Llongitud/a{$l['longitud']}data", $sDatos);
                $sDatos = substr($sDatos, $l['longitud'] + 4);
                $listaPaquetes[] = unserialize($l['data']);
            }
        } while ($bHayDatos);
        return $listaPaquetes;
    }

    // Generar un paquete serializado para RPC
    // Generate a serialized packet for RPC
    private function _generarPaquete(&$v)
    {
    	$s = serialize($v);
        return pack("La*", strlen($s), $s);
    }

    // Separar flujo de datos en paquetes, devuelve número de bytes de paquetes
    // aceptados.
    // Separate data stream into packets, returns number of bytes of accepted packets
    function parsearPaquetes($sDatos)
    {
        $iLongInicial = strlen($sDatos);

        // Encontrar los paquetes y determinar longitud de búfer procesado
        // Find packets and determine processed buffer length
        $listaPaquetes = $this->_encontrarPaquetes($sDatos);
        $iLongFinal = strlen($sDatos);
        foreach ($listaPaquetes as $paquete) {
/*
        	if (!is_null($this->_log))
                $this->_log->output('DEBUG: recibido paquete: '.print_r($paquete, 1));
*/
            if (!$this->_rutearRespuesta && $paquete['mensaje'] == '__RESPUESTA__') {
                $this->_respuesta = $paquete['datos'];
                $this->_hayRespuesta = TRUE;
            }
            else $this->_listaEventos[] = $paquete;
        }
        return $iLongInicial - $iLongFinal;
    }

    // Procesar cierre de la conexión
    // Process connection closure
    function procesarCierre()
    {
        $this->sKey = NULL;
    }

    // Preguntar si hay paquetes pendientes de procesar
    // Check if there are packets pending processing
    function hayPaquetes() { return (count($this->_listaEventos) > 0); }

    // Procesar un solo paquete de la cola de paquetes
    // Process a single packet from the packet queue
    function procesarPaquete()
    {
        $paquete = array_shift($this->_listaEventos);
        if (isset($paquete['fuente']) && isset($paquete['destino']) && 
            isset($paquete['mensaje'])) {
        	$sFuente = $paquete['fuente'];
            $sDestino = $paquete['destino'];
            $sNombreMensaje = $paquete['mensaje'];

            // Buscar el manejador
            // Search for the handler
            $hFuente = isset($this->_manejadoresEventos[$sFuente])
                ? $this->_manejadoresEventos[$sFuente]
                : NULL;
            $hCualquiera = isset($this->_manejadoresEventos['*'])
                ? $this->_manejadoresEventos['*']
                : NULL;
            // Fuente específica, mensaje específico
            // Specific source, specific message
            if (!is_null($hFuente) && isset($hFuente[$sNombreMensaje]))
                $handler = $hFuente[$sNombreMensaje];
            // Fuente cualquiera, mensaje específico
            // Any source, specific message
            elseif (!is_null($hCualquiera) && isset($hCualquiera[$sNombreMensaje]))
                $handler = $hCualquiera[$sNombreMensaje];
            // Fuente específica, cualquier mensaje
            // Specific source, any message
            elseif (!is_null($hFuente) && isset($hFuente['*']))
                $handler = $hFuente['*'];
            // Fuente cualquiera, cualquier mensaje
            // Any source, any message
            elseif (!is_null($hCualquiera) && isset($hCualquiera['*']))
                $handler = $hCualquiera['*'];
            else {
                if (!is_null($this->_log))
                    $this->_log->output("ERR: no se ha registrado manejador para $sNombreMensaje($sFuente-->$sDestino) | EN: no handler registered for $sNombreMensaje($sFuente-->$sDestino)");
                return;
            }

            if ((is_array($handler) && count($handler) >= 2 && is_object($handler[0]) && 
                method_exists($handler[0], $handler[1])) || (!is_array($handler) && function_exists($handler))) {
                call_user_func($handler, $sFuente, $sDestino, $sNombreMensaje, $paquete['timestamp'], $paquete['datos']);
            } else {
                if (!is_null($this->_log))
                    $this->_log->output("ERR: no se encuentra manejador registrado para $sNombreMensaje($sFuente-->$sDestino) | EN: registered handler not found for $sNombreMensaje($sFuente-->$sDestino)");
            }
        } else {
            if (!is_null($this->_log))
                $this->_log->output("ERR: se descarta paquete mal formado: ".print_r($paquete, 1)." | EN: discarding malformed packet: ");
        }
    }

    /* Registrar manejador para un mensaje que viene de una fuente determinada.
     * Para aceptar cualquier fuente, o cualquier mensaje, se debe especificar
     * un asterisco ('*')
     * Register handler for a message coming from a specific source.
     * To accept any source or any message, an asterisk ('*') must be specified */
    function registrarManejador($sFuente, $sMensaje, $callback)
    {
    	$this->_manejadoresEventos[$sFuente][$sMensaje] = $callback;
    }

    /* Enviar un mensaje a través del HubServer a un destino determinado.
     * Send a message through the HubServer to a specific destination. */
    function enviarMensajeDesdeFuente($sFuente, $sDestino, $sMensaje, $datos)
    {
        if (!is_null($this->sKey)) {
            $datosEnviar = array(
                'timestamp' =>  microtime(TRUE),
                'fuente'    =>  $sFuente,
                'destino'   =>  $sDestino,
                'mensaje'   =>  $sMensaje,
                'datos'     =>  $datos,
            );
            $req = $this->_generarPaquete($datosEnviar);
            $this->multiplexSrv->encolarDatosEscribir($this->sKey, $req);
        }
    }
    
    function enviarMensaje($sDestino, $sMensaje, $datos)
    {
    	$this->enviarMensajeDesdeFuente($this->_nombreFuente, $sDestino, $sMensaje, $datos);
    }

    // Enviar una respuesta a un RPC como un mensaje
    // Send a response to an RPC as a message
    function enviarRespuesta($sDestino, $datos)
    {
    	$this->enviarMensaje($sDestino, '__RESPUESTA__', $datos);
    }

    // Iniciar un RPC (procedimiento remoto) sobre el destino indicado
    // Initiate an RPC (remote procedure) on the indicated destination
    function RPC($sDestino, $sProc, $datos)
    {
        global $gsNombreSignal;

    	$this->enviarMensaje($sDestino, $sProc, $datos);
        while (!is_null($this->sKey) && !$this->_hayRespuesta) {
            $this->multiplexSrv->procesarActividad();

            /* El par puede haber dejado de existir a mitad de la llamada: el
             * hub descarta la solicitud para una tarea ausente y ninguna
             * respuesta llega jamás. La señal de parada sólo se atiende de
             * vuelta en el bucle de la tarea, al que esta espera nunca
             * regresa, así que una parada pendiente debe terminar la espera
             * aquí. SIGHUP (rotación de logs) no la termina.
             * The peer may be gone mid-call: the hub drops the request for an
             * absent task and no answer ever comes. A stop signal is only
             * acted on back in the task loop, which this wait never returns
             * to, so a pending stop must end the wait here. SIGHUP (log
             * rotation) does not end it. */
            pcntl_signal_dispatch();
            if (!is_null($gsNombreSignal) &&
                in_array($gsNombreSignal, array(SIGTERM, SIGINT, SIGQUIT))) {
                if (!is_null($this->_log)) {
                    $this->_log->output("INFO: se abandona la espera de $sProc($sDestino): ".
                        "señal $gsNombreSignal pendiente | EN: abandoning the wait for ".
                        "$sProc($sDestino): signal $gsNombreSignal pending");
                }
                return NULL;
            }
        }
        if ($this->_hayRespuesta) {
            $r = $this->_respuesta;
            $this->_respuesta = NULL;
            $this->_hayRespuesta = FALSE;
            return $r;
        }
        return NULL;
    }

    /*
     * Definición para envolver las llamadas a procedimientos y los envíos de
     * mensajes, como métodos del objeto. Los métodos que se mapean como RPC
     * tienen el patrón 'DESTINO_PROC'. Los métodos que se mapean como posteo
     * de mensajes asíncronos tienen el patrón 'msg_DESTINO_PROC'.
     * Definition to wrap procedure calls and message sends as object methods.
     * Methods that map as RPC have the pattern 'DESTINATION_PROC'. Methods
     * that map as asynchronous message posts have the pattern 'msg_DESTINATION_PROC'.
     */
    function __call($sMetodo, $args)
    {
    	if (substr($sMetodo, 0, 4) == 'msg_') {
            $l = explode('_', $sMetodo, 3);
            $this->enviarMensaje($l[1], $l[2], $args);
        } else {
            $l = explode('_', $sMetodo, 2);
            return $this->RPC($l[0], $l[1], $args);
        }
    }
}
?>
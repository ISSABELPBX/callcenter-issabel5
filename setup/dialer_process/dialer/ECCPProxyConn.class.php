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
  $Id: ECCPProxyConn.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

require_once 'ECCPHelper.lib.php';

class ECCPProxyConn extends MultiplexConn
{
    private $_log;
    private $_tuberia;
    private $_listaReq = array();    // Lista de requerimientos pendientes
                                    // List of pending requirements
    private $_parser = NULL;        // Parser expat para separar los paquetes
                                    // Expat parser to separate packets
    private $_iPosFinal = NULL;     // Posición de parser para el paquete parseado
                                    // Parser position for the parsed packet
    private $_sTipoDoc = NULL;      // Tipo de paquete. Sólo se acepta 'request'
                                    // Packet type. Only 'request' is accepted
    private $_bufferXML = '';       // Datos pendientes que no forman un paquete completo
                                    // Pending data that does not form a complete packet
    private $_iNestLevel = 0;       // Al llegar a cero, se tiene fin de paquete
                                    // When reaching zero, end of packet is reached

    // Estado de la conexión
    // Connection status
    private $_sUsuarioECCP  = NULL; // Nombre de usuario para cliente logoneado, o NULL si no logoneado
                                    // Username for logged in client, or NULL if not logged in
    private $_sAppCookie = NULL;    // Cadena a usar como cookie de la aplicación
                                    // String to use as application cookie
    private $_sAgenteFiltrado = NULL;   // Si != NULL, eventos sólo se despachan si el agente coincide con este valor
                                            // If != NULL, events are only dispatched if the agent matches this value
    private $_bProgresoLlamada = FALSE; // Si VERDADERO, cliente está interesado en eventos de progreso de llamada
                                         // If TRUE, client is interested in call progress events

    private $_bFinalizando = FALSE;

    function __construct($oMainLog, $tuberia)
    {
        $this->_log = $oMainLog;
        $this->_tuberia = $tuberia;
        $this->_resetParser();
    }

    // Datos a mandar a escribir apenas se inicia la conexión
    // Data to send for writing as soon as the connection starts
    function procesarInicial() {}

    // Separar flujo de datos en paquetes, devuelve número de bytes de paquetes aceptados
    // Separate data stream into packets, returns number of bytes of accepted packets
    function parsearPaquetes($sDatos)
    {
        $this->parsearPaquetesXML($sDatos);
        return strlen($sDatos);
    }

    // Procesar cierre de la conexión
    // Process connection closure
    function procesarCierre()
    {
        if (!is_null($this->_parser)) {
            xml_parser_free($this->_parser);
            $this->_parser = NULL;
        }
    }

    // Preguntar si hay paquetes pendientes de procesar
    // Check if there are packets pending processing
    function hayPaquetes() {
        return (count($this->_listaReq) > 0);
    }

    // Procesar un solo paquete de la cola de paquetes
    // Process a single packet from the packet queue
    function procesarPaquete()
    {
        $request = array_shift($this->_listaReq);
        if (isset($request['request'])) {

            $connvars = array(
                'appcookie'     =>  $this->_sAppCookie,
                'usuarioeccp'   =>  $this->_sUsuarioECCP,
            );
            $this->_tuberia->msg_ECCPWorkerProcess_eccprequest($this->sKey, $request, $connvars);
        } else {
            // Marcador de error, se cierra la conexión
            // Error marker, connection is closed
            $r = $this->_generarRespuestaFallo(400, 'Bad request');
            $s = $r->asXML();
            $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
            $this->multiplexSrv->marcarCerrado($this->sKey);
        }
    }

    function do_eccpresponse(&$s, &$nuevos_valores)
    {
        if (!is_null($nuevos_valores)) {
            foreach ($nuevos_valores as $k => $v) {
                if ($k == 'usuarioeccp')
                    $this->_sUsuarioECCP = $v;
                if ($k == 'appcookie')
                    $this->_sAppCookie = $v;
                if ($k == 'agentefiltrado')
                    $this->_sAgenteFiltrado = $v;
                if ($k == 'progresollamada')
                    $this->_bProgresoLlamada = $v;
                if ($k == 'finalizando')
                    $this->_bFinalizando = $v;
            }
        }

        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
        if ($this->_bFinalizando) $this->multiplexSrv->marcarCerrado($this->sKey);
    }

    // Función que construye una respuesta de petición incorrecta
    // Function that builds an incorrect request response
    private function _generarRespuestaFallo($iCodigo, $sMensaje, $idPeticion = NULL)
    {
        $x = new SimpleXMLElement("<response />");
        if (!is_null($idPeticion))
            $x->addAttribute("id", $idPeticion);
        $this->_agregarRespuestaFallo($x, $iCodigo, $sMensaje);
        return $x;
    }

    // Agregar etiqueta failure a la respuesta indicada
    // Add failure tag to the indicated response
    private function _agregarRespuestaFallo($x, $iCodigo, $sMensaje)
    {
        $failureTag = $x->addChild("failure");
        $failureTag->addChild("code", $iCodigo);
        $failureTag->addChild("message", xmlSafe($sMensaje));
    }

    // Procedimiento a llamar cuando se finaliza la conexión en cierre normal
    // del programa.
    // Procedure to call when terminating the connection in normal program shutdown
    function finalizarConexion()
    {
        // Mandar a cerrar la conexión en sí
        // Send command to close the connection itself
        $this->multiplexSrv->marcarCerrado($this->sKey);

        if (!is_null($this->_parser)) {
            xml_parser_free($this->_parser);
            $this->_parser = NULL;
        }
    }

    // Implementación de parser expat: inicio
    // Expat parser implementation: start

    // Parsear y separar tantos paquetes XML como sean posibles
    // Parse and separate as many XML packets as possible
    private function parsearPaquetesXML($data)
    {
        $this->_bufferXML .= $data;
        $r = xml_parse($this->_parser, $data);
        while (!is_null($this->_iPosFinal)) {
            if ($this->_sTipoDoc == 'request') {
                $this->_listaReq[] = array(
                    'request'   =>  substr($this->_bufferXML, 0, $this->_iPosFinal),
                    'received'  =>  microtime(TRUE),
                );
            } else {
                $this->_listaReq[] = array(
                    'errorcode'     =>  -1,
                    'errorstring'   =>  "Unrecognized packet type: {$this->_sTipoDoc}",
                    'errorline'     =>  xml_get_current_line_number($this->_parser),
                    'errorpos'      =>  xml_get_current_column_number($this->_parser),
                );
            }
            $this->_bufferXML = ltrim(substr($this->_bufferXML, $this->_iPosFinal));
            $this->_iPosFinal = NULL;
            $this->_resetParser();
            if ($this->_bufferXML != '')
                $r = xml_parse($this->_parser, $this->_bufferXML);
        }
        if (!$r) {
            $this->_listaReq[] = array(
                'errorcode'     =>  xml_get_error_code($this->_parser),
                'errorstring'   =>  xml_error_string(xml_get_error_code($this->_parser)),
                'errorline'     =>  xml_get_current_line_number($this->_parser),
                'errorpos'      =>  xml_get_current_column_number($this->_parser),
            );
        }
        return $r;
    }

    // Resetear el parseador, para iniciarlo, o luego de parsear un paquete
    // Reset the parser, to start it, or after parsing a packet
    private function _resetParser()
    {
        if (!is_null($this->_parser)) xml_parser_free($this->_parser);
        $this->_parser = xml_parser_create('UTF-8');
        xml_set_element_handler ($this->_parser,
            array($this, 'xmlStartHandler'),
            array($this, 'xmlEndHandler'));
        xml_parser_set_option($this->_parser, XML_OPTION_CASE_FOLDING, 0);
    }

    function xmlStartHandler($parser, $name, $attribs)
    {
        $this->_iNestLevel++;
    }

    function xmlEndHandler($parser, $name)
    {
        $this->_iNestLevel--;
        if ($this->_iNestLevel == 0) {
            $this->_iPosFinal = xml_get_current_byte_index($parser);
            $this->_sTipoDoc = $name;
        }
    }

    // Implementación de parser expat: final
    // Expat parser implementation: end

    /***************************** EVENTOS *****************************/
    /***************************** EVENTS *****************************/

    function notificarEvento_AgentLogin($sAgente, $bExitoLogin)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLoggedIn = $bExitoLogin
            ? $xml_response->addChild('agentloggedin')
            : $xml_response->addChild('agentfailedlogin');
        $xml_agentLoggedIn->addChild('agent', xmlSafe($sAgente));

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_AgentLogoff($sAgente)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLoggedIn = $xml_response->addChild('agentloggedout');
        $xml_agentLoggedIn->addChild('agent', xmlSafe($sAgente));

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_AgentLinked($sAgente, $sRemChannel, $infoLlamada)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLinked = $xml_response->addChild('agentlinked');
        $infoLlamada['agent_number'] = $sAgente;
        $infoLlamada['remote_channel'] = $sRemChannel;
        ECCPConn::construirRespuestaCallInfo($infoLlamada, $xml_agentLinked);

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_AgentUnlinked($sAgente, $infoLlamada)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLinked = $xml_response->addChild('agentunlinked');
        $infoLlamada['agent_number'] = $sAgente;
        foreach ($infoLlamada as $sKey => $valor) {
            if (!is_null($valor)) $xml_agentLinked->addChild($sKey, xmlSafe($valor));
        }

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_PauseStart($sAgente, $infoPausa)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLinked = $xml_response->addChild('pausestart');
        $infoPausa['agent_number'] = $sAgente;
        foreach ($infoPausa as $sKey => $valor) {
            if (!is_null($valor)) $xml_agentLinked->addChild($sKey, xmlSafe($valor));
        }

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_PauseEnd($sAgente, $infoPausa)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_agentLinked = $xml_response->addChild('pauseend');
        $infoPausa['agent_number'] = $sAgente;
        foreach ($infoPausa as $sKey => $valor) {
            if (!is_null($valor)) $xml_agentLinked->addChild($sKey, xmlSafe($valor));
        }

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_CallProgress($infoProgreso)
    {
    	if (is_null($this->_sUsuarioECCP)) return;
        if (!$this->_bProgresoLlamada) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_callProgress = $xml_response->addChild('callprogress');
        foreach ($infoProgreso as $sKey => $valor) {
            if (!is_null($valor)) $xml_callProgress->addChild($sKey, xmlSafe($valor));
        }

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_QueueMembership($sAgente, $infoSeguimiento, $listaColas)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_queueMembership = $xml_response->addChild('queuemembership');

        $xml_queueMembership->addChild('agent_number', xmlSafe($sAgente));
        ECCPConn::getcampaignstatus_setagent($xml_queueMembership, $infoSeguimiento);
        $xml_agentQueues = $xml_queueMembership->addChild('queues');
        foreach ($listaColas as $sCola) {
            $xml_agentQueues->addChild('queue', xmlSafe($sCola));
        }
        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_AgentStateChange($sAgente, $sNewStatus, $sQueue)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_stateChange = $xml_response->addChild('agentstatechange');

        $xml_stateChange->addChild('agent_number', xmlSafe($sAgente));
        $xml_stateChange->addChild('status', $sNewStatus);
        $xml_stateChange->addChild('queue', xmlSafe($sQueue));

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_RecordingMute($sAgente, $sTipoLlamada, $idCampaign, $idLlamada)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_recordingMute = $xml_response->addChild('recordingmute');

        $xml_recordingMute->addChild('agent_number', xmlSafe($sAgente));
        $xml_recordingMute->addChild('calltype', $sTipoLlamada);
        if (!is_null($idCampaign)) $xml_recordingMute->addChild('campaign_id', $idCampaign);
        $xml_recordingMute->addChild('call_id', $idLlamada);

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_RecordingUnmute($sAgente, $sTipoLlamada, $idCampaign, $idLlamada)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_recordingUnmute = $xml_response->addChild('recordingunmute');

        $xml_recordingUnmute->addChild('agent_number', xmlSafe($sAgente));
        $xml_recordingUnmute->addChild('calltype', $sTipoLlamada);
        if (!is_null($idCampaign)) $xml_recordingUnmute->addChild('campaign_id', $idCampaign);
        $xml_recordingUnmute->addChild('call_id', $idLlamada);

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_ScheduledCallStart($sAgente, $sTipoLlamada, $idCampaign, $idLlamada)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_scheduleCallStart = $xml_response->addChild('schedulecallstart');

        $xml_scheduleCallStart->addChild('agent_number', xmlSafe($sAgente));
        $xml_scheduleCallStart->addChild('calltype', $sTipoLlamada);
        if (!is_null($idCampaign)) $xml_scheduleCallStart->addChild('campaign_id', $idCampaign);
        $xml_scheduleCallStart->addChild('call_id', $idLlamada);

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_ScheduledCallFailed($sAgente, $sTipoLlamada, $idCampaign, $idLlamada)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_scheduleCallFailed = $xml_response->addChild('schedulecallfailed');

        $xml_scheduleCallFailed->addChild('agent_number', xmlSafe($sAgente));
        $xml_scheduleCallFailed->addChild('calltype', $sTipoLlamada);
        if (!is_null($idCampaign)) $xml_scheduleCallFailed->addChild('campaign_id', $idCampaign);
        $xml_scheduleCallFailed->addChild('call_id', $idLlamada);

        $s = $xml_response->asXML();
        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $s);
    }

    function notificarEvento_ConsultationStart($sAgente)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_consultStart = $xml_response->addChild('consultationstart');
        $xml_consultStart->addChild('agent_number', xmlSafe($sAgente));

        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $xml_response->asXML());
    }

    // $sReason: DIALSTATUS of the consult Dial() (BUSY/NOANSWER/CONGESTION/
    // CHANUNAVAIL/...) when the consultation ended on its own, empty for any
    // other way it can end (explicit cancel, completion, hangups).
    function notificarEvento_ConsultationEnd($sAgente, $sReason = '')
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_consultEnd = $xml_response->addChild('consultationend');
        $xml_consultEnd->addChild('agent_number', xmlSafe($sAgente));
        if ($sReason !== '') {
            $xml_consultEnd->addChild('reason', xmlSafe($sReason));
        }

        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $xml_response->asXML());
    }

    // The consultation's colleague has answered - the agent console can now
    // offer to complete the transfer (as opposed to only cancel it).
    function notificarEvento_ConsultationAnswered($sAgente)
    {
        if (is_null($this->_sUsuarioECCP)) return;
        if (!is_null($this->_sAgenteFiltrado) && $this->_sAgenteFiltrado != $sAgente) return;

        $xml_response = new SimpleXMLElement('<event />');
        $xml_consultAnswered = $xml_response->addChild('consultationanswered');
        $xml_consultAnswered->addChild('agent_number', xmlSafe($sAgente));

        $this->multiplexSrv->encolarDatosEscribir($this->sKey, $xml_response->asXML());
    }
}
?>
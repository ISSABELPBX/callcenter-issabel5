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

require_once 'ECCPHelper.lib.php';

class ECCPConn
{
    public $DEBUG = FALSE;

    private $_log;
    private $_ami;
    private $_astVersion;
    private $_compat = NULL; // AsteriskCompat instance for version-aware behavior
    private $_db;
    private $_tuberia;

    /* Lista de atributos de funciones (decorator). Actualmente se usa para
     * abstraer la autenticación sin tener que repetirla para cada función
     * que la requiera
     * List of function attributes (decorator). Currently used to abstract
     * authentication without having to repeat it for each function that
     * requires it */
    private $_peticionesAttr = array();

    function __construct($oMainLog, $tuberia)
    {
        $this->_log = $oMainLog;
        $this->_tuberia = $tuberia;

        // Recolectar atributos de los requerimientos
        // Collect attributes of requirements
        foreach (get_class_methods(get_class($this)) as $sMetodo) {
        	$regs = NULL;
            if (preg_match('/^Request_(.+)$/i', $sMetodo, $regs)) {
        		$sRequerimiento = $regs[1];
                $atributos = array(
                    'method'    => $sMetodo,
                    'eccpauth'  =>  FALSE,  // Método requiere autenticación ECCP
                                            // Method requires ECCP authentication
                    'agentauth' =>  FALSE,  // Método requiere auth ECCP y de agente
                                            // Method requires ECCP and agent authentication
                );
                foreach (array('eccpauth', 'agentauth') as $decorator) {
                    if (preg_match("/^(.*){$decorator}_(.+)$/", $sRequerimiento, $regs)) {
                    	$atributos[$decorator] = TRUE;
                        $sRequerimiento = $regs[1].$regs[2];
                    }
                }
                $this->_peticionesAttr[$sRequerimiento] = $atributos;
        	}
        }
    }

    function setAstConn($astConn, $astVersion)
    {
        $this->_ami = $astConn;
        $this->_astVersion = $astVersion;
        if (is_array($astVersion)) {
            $this->_compat = new AsteriskCompat($astVersion);
        } else {
            $this->_compat = NULL;
        }
    }

    function setDbConn($dbConn)
    {
        $this->_db = $dbConn;
    }

    public function do_eccprequest(&$request, &$connvars)
    {
        $response = NULL;

        $t = $request['received'];
        $request = simplexml_load_string($request['request']);
        $request->addAttribute('received', $t);

        $nuevos_valores = NULL;
        $eventos = NULL;

        // Petición es un request, procesar
        // Request is a request, process it
        if (count($request) != 1) {
            // La petición debe tener al menos un elemento hijo
            // The request must have at least one child element
            $response = $this->_generarRespuestaFallo(400, 'Bad request');
        } elseif (!isset($request['id'])) {
            // La petición debe tener un identificador
            // The request must have an identifier
            $response = $this->_generarRespuestaFallo(400, 'Bad request');
        } else {
            if (is_null($this->_db)) {
                // Todavía no se ha restaurado la conexión a la base de datos
                // Database connection has not been restored yet
                $response = $this->_generarRespuestaFallo(500, 'Server error - database failure');
            } else {
                if ($this->DEBUG) {
                    $iTimestampRecibido = (double)$request['received'];
                    $proc_start = microtime(TRUE);
                    $this->_log->output('DEBUG: '.__METHOD__.': retraso (sec) hasta procesar: '.($proc_start - $iTimestampRecibido).
                        ' | EN: DEBUG: '.__METHOD__.': delay (sec) until processing: '.($proc_start - $iTimestampRecibido));
                }

                // Se procede normalmente...
                // Proceed normally...
                $comando = NULL;
                foreach ($request->children() as $c) $comando = $c;

                // Hack para no agregar parámetro a todas las peticiones
                // Hack to avoid adding parameter to all requests
                if (!is_null($connvars['appcookie']))
                    $comando->addAttribute('appcookie', $connvars['appcookie']);

                $iTimestampInicio = microtime(TRUE);
                $sRequerimiento = (string)$comando->getName();
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.': procesando requerimiento '.$sRequerimiento.' params: '.print_r($comando, TRUE).
                        ' | EN: DEBUG: '.__METHOD__.': processing request '.$sRequerimiento.' params: '.print_r($comando, TRUE));
                }
                if (!isset($this->_peticionesAttr[$sRequerimiento])) {
                    $this->_log->output('ERR: (interno) no existe implementación para método: '.$sRequerimiento.' | EN: no implementation exists for method: '.$sRequerimiento);
                    $response = $this->_generarRespuestaFallo(501, 'Not Implemented');
                } else {
                    $sMetodoImplementacion = $this->_peticionesAttr[$sRequerimiento]['method'];

                    // Autenticación según las decoraciones de la petición
                    // Authentication according to request decorations

                    // Verificación de usuario ECCP válido
                    // Valid ECCP user verification
                    if (is_null($response) &&
                        ($this->_peticionesAttr[$sRequerimiento]['eccpauth'] ||
                            $this->_peticionesAttr[$sRequerimiento]['agentauth'])) {
                                if (is_null($connvars['usuarioeccp']))
                                    $response = $this->_generarRespuestaFallo(401, 'Unauthorized');
                    }
                    try {
                        // Verificación de que agente existe y tiene contraseña válida
                        // Verification that agent exists and has valid password
                        if (is_null($response) && $this->_peticionesAttr[$sRequerimiento]['agentauth']) {
                            // Verificar que agente está presente
                            // Verify that agent is present
                            if (!isset($comando->agent_number)) {
                                $response = $this->_generarRespuestaFallo(400, 'Bad request');
                            } else {
                                $sAgente = (string)$comando->agent_number;

                                $xml_response = new SimpleXMLElement('<response />');
                                $xml_reqresponse = $xml_response->addChild($sRequerimiento.'_response');

                                // El siguiente código asume formato Agent/9000
                                // The following code assumes format Agent/9000
                                if (is_null($this->_parseAgent($sAgente))) {
                                    $this->_agregarRespuestaFallo($xml_reqresponse, 417, 'Invalid agent number');
                                    $response = $xml_response;
                                } else {
                                    // Verificar que el agente sea válido en el sistema
                                    // Verify that the agent is valid in the system
                                    if (!$this->_existeAgente($sAgente)) {
                                        $this->_agregarRespuestaFallo($xml_reqresponse, 404, 'Specified agent not found');
                                        $response = $xml_response;
                                    } elseif (!$this->_hashValidoAgenteECCP($comando, $comando['appcookie'])) {
                                        $this->_agregarRespuestaFallo($xml_reqresponse, 401, 'Unauthorized agent');
                                        $response = $xml_response;
                                    }
                                }
                            }
                        }

                        // Verificaciones realizadas, ejecutar método
                        // Verifications completed, execute method
                        if (is_null($response)) {
                            $response = $this->$sMetodoImplementacion($comando);
                            if (is_array($response)) {
                                if (isset($response['nuevos_valores']))
                                    $nuevos_valores = $response['nuevos_valores'];
                                if (isset($response['eventos']))
                                    $eventos = $response['eventos'];
                                $response = $response['response'];
                            }
                        }
                    } catch (PDOException $e) {
                        $response = $this->_generarRespuestaFallo(503, 'Internal server error - database failure');
                        $this->_stdManejoExcepcionDB($e, 'no se puede realizar operación de base de datos');
                    }
                }

                $iTimestampFinal = microtime(TRUE);
                if ($this->DEBUG || (($iTimestampFinal - $iTimestampInicio) >= 1.0)) {
                    $this->_log->output('DEBUG: '.__METHOD__.': requerimiento '.$comando->getName().' procesado luego de (sec): '.($iTimestampFinal - $iTimestampInicio).
                        ' | EN: DEBUG: '.__METHOD__.': request '.$comando->getName().' processed after (sec): '.($iTimestampFinal - $iTimestampInicio));
                }
            }
            $response->addAttribute('id', (string)$request['id']);
        }

        $s = $response->asXML();

        if ($s === FALSE) {
            /* asXML() devuelve FALSE cuando libxml no puede serializar el arbol,
             * por ejemplo si un caracter ilegal en XML 1.0 se colo en algun valor.
             * Sin este respaldo el FALSE se concatena como cadena vacia en
             * MultiplexServer::encolarDatosEscribir(), no se escribe nada al
             * socket, y el cliente queda esperando una respuesta que nunca llega:
             * reintenta, reconecta, y cada peticion simultanea toma un
             * ECCPWorkerProcess nuevo con su propia conexion PDO, hasta agotar
             * max_connections. Siempre hay que devolver algo bien formado. */
            /* asXML() returns FALSE when libxml cannot serialize the tree, for
             * example if a character illegal in XML 1.0 slipped into some value.
             * Without this fallback the FALSE is concatenated as an empty string
             * in MultiplexServer::encolarDatosEscribir(), nothing is written to
             * the socket, and the client waits for a response that never arrives:
             * it retries, reconnects, and every simultaneous request takes a fresh
             * ECCPWorkerProcess with its own PDO connection, until max_connections
             * is exhausted. Something well-formed must always be returned. */
            $sNombrePeticion = (isset($comando) && !is_null($comando))
                ? $comando->getName() : 'desconocida/unknown';
            $this->_log->output('ERR: '.__METHOD__.': no se puede serializar a XML la'.
                ' respuesta a la peticion '.$sNombrePeticion.', se responde fallo generico.'.
                ' | EN: ERR: '.__METHOD__.': cannot serialize the response to request '.
                $sNombrePeticion.' as XML, replying with a generic failure.');

            $xFallo = $this->_generarRespuestaFallo(500,
                'Internal server error - response could not be serialized',
                isset($request['id']) ? (string)$request['id'] : NULL);
            $s = $xFallo->asXML();

            // Ultimo recurso: XML fijo, sin ningun dato variable que pueda fallar.
            // Last resort: fixed XML, with no variable data that could fail.
            if ($s === FALSE) {
                $s = '<?xml version="1.0"?>'."\n".
                     '<response><failure><code>500</code>'.
                     '<message>Internal server error</message></failure></response>'."\n";
            }
        }

        return array($s, $nuevos_valores, $eventos);
    }

    private function _stdManejoExcepcionDB($e, $s)
    {
        $this->_log->output('ERR: '.__METHOD__.": $s: ".implode(' - ', $e->errorInfo).' | EN: ERR: '.__METHOD__.": $s: ".implode(' - ', $e->errorInfo));
        $this->_log->output("ERR: traza de pila | EN: stack trace: \n".$e->getTraceAsString());
        if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 2006) {
            // Códigos correspondientes a pérdida de conexión de base de datos
            // Codes corresponding to database connection loss
            $this->_log->output('WARN: '.__METHOD__.': conexión a DB parece ser inválida, se cierra... | EN: WARN: '.__METHOD__.': DB connection appears to be invalid, closing...');
            $this->multiplexSrv->setDBConn(NULL);
        }
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

    private function _parseAgent($sAgente)
    {
        // Se puede expandir para acomodar más tecnologías
        // Can be expanded to accommodate more technologies
        $regexp = '#^(\w+)/(\w+)$#';
        $regs = NULL;
        return preg_match($regexp, $sAgente, $regs)
            ? array('type' => $regs[1], 'number' => $regs[2]) : NULL;
    }

    private function Request_getrequestlist($comando)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_getRequestListResponse = $xml_response->addChild('getrequestlist_response');

        $xml_requests = $xml_getRequestListResponse->addChild('requests');
        foreach (array_keys($this->_peticionesAttr) as $sPeticion)
            $xml_requests->addChild('request', $sPeticion);
        return $xml_response;
    }

    private function Request_eccpauth_filterbyagent($comando)
    {
        // Verificar que agente está presente
        // Verify that agent is present
        if (!isset($comando->agent_number))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_filterbyagentResponse = $xml_response->addChild('filterbyagent_response');

        // El siguiente código asume formato Agent/9000
        // The following code assumes format Agent/9000
        if ($sAgente == 'any') {
            $sAgente = NULL;
        } elseif (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_filterbyagentResponse, 417, 'Invalid agent number');
            return $xml_response;
        }

        $xml_filterbyagentResponse->addChild('success');

        return array(
            'response'          =>  $xml_response,
            'nuevos_valores'    =>  array(
                'agentefiltrado'    =>  $sAgente,
            ),
        );
    }

    /**
     * Procedimiento que implementa el login del cliente del protocolo. No se
     * debe mandar ningún evento ni obedecer ningún otro requerimiento hasta que
     * se haya usado este comando para logonearse exitosamente
     * Procedure that implements the protocol client login. No events must be
     * sent nor any other request obeyed until this command has been used to
     * successfully log in
     *
     * @param   object   $comando    Comando de login
     *      <login>
     *          <username>alice</username>
     *          <password>[md5hash]</password> <!-- md5hash es hash md5 de passwd -->
     *                                         <!-- md5hash is md5 hash of password -->
     *      </login>
     *
     * @return  object  Respuesta codificada como un SimpleXMLObject
     *                  Response encoded as a SimpleXMLObject
     *      <login_response>
     *          <success /> | <failure>mensaje</failure>
     *      </login_response>
     */
    private function Request_login($comando)
    {
        // Verificar que usuario y clave están presentes
        // Verify that username and password are present
        if (!isset($comando->username) || !isset($comando->password))
            return $this->_generarRespuestaFallo(400, 'Bad request');

        $xml_response = new SimpleXMLElement('<response />');
        $xml_loginResponse = $xml_response->addChild('login_response');

        /* La conexión ECCP va cifrada con TLS (ver ECCPProcess), así que la
         * clave ya no puede recogerse con un sniffer. Se sigue aceptando con o
         * sin hash md5 por compatibilidad con los clientes existentes.
         * The ECCP connection is TLS encrypted (see ECCPProcess), so the
         * password can no longer be captured with a sniffer. It is still
         * accepted with or without md5 hash for compatibility with existing
         * clients. */
        /* TODO: se puede almacenar cuál agente(s) está autorizado a atender en
         * la tabla eccp_authorized_clients
         * TODO: can store which agent(s) is authorized to attend in the
         * eccp_authorized_clients table */
        $sPeticionSQL =
            'SELECT COUNT(*) AS N FROM eccp_authorized_clients '.
            'WHERE username = ? AND (md5_password = ? OR md5_password = md5(?))';
        $paramSQL = array($comando->username, $comando->password, $comando->password);
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute($paramSQL);
        $tupla = $recordset->fetch(); $recordset->closeCursor();
        if ($tupla['N'] > 0) {
            // Usuario autorizado
            // Authorized user
            $xml_status = $xml_loginResponse->addChild('success');

            // Generar una cadena de hash para cookie de aplicación
            // Generate a hash string for application cookie
            $sAppCookie = md5(posix_getpid().time().mt_rand());
            $xml_loginResponse->addChild('app_cookie', $sAppCookie);
            return array(
                'response'          =>  $xml_response,
                'nuevos_valores'    =>  array(
                    'usuarioeccp'   =>  (string)$comando->username,
                    'appcookie'     =>  $sAppCookie,
                ),
            );
        } else {
            // Usuario no existe, o clave incorrecta
            // User does not exist, or incorrect password
            $this->_agregarRespuestaFallo($xml_loginResponse, 401, 'Invalid username or password');
            return $xml_response;
        }
    }

    /**
     * Procedimiento que implementa el logout del cliente del protocolo. Luego
     * de este requerimiento, se espera que se cierre la conexión.
     * Procedure that implements the protocol client logout. After this
     * request, the connection is expected to be closed.
     *
     * @param   object   $comando    Comando de logout
     *      <logout />
     *
     * @return  object  Respuesta codificada como un SimpleXMLObject
     *                  Response encoded as a SimpleXMLObject
     *      <logout_response />
     */
    private function Request_logout($comando)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_loginResponse = $xml_response->addChild('logout_response');
        $xml_status = $xml_loginResponse->addChild('success');
        return array(
            'response'          =>  $xml_response,
            'nuevos_valores'    =>  array(
                'usuarioeccp'   =>  NULL,
                'appcookie'     =>  NULL,
                'finalizando'   =>  TRUE,
            ),
        );
    }

    // Revisar si el comando indicado tiene un hash válido. El comando debe de
    // tener los campos agent_number y agent_hash
    // Check if the indicated command has a valid hash. The command must have
    // the agent_number and agent_hash fields
    private function _hashValidoAgenteECCP($comando, $appcookie)
    {
        if (!isset($comando->agent_number) || !isset($comando->agent_hash))
            return FALSE;
        $sAgente = (string)$comando->agent_number;
        $sHashCliente = (string)$comando->agent_hash;

        $recordset = $this->_db->prepare(
            'SELECT number, eccp_password FROM agent '.
            "WHERE estatus = 'A' AND CONCAT(type,'/',number) = ?");
        $recordset->execute(array($sAgente));
        $tuplaAgente = $recordset->fetch(); $recordset->closeCursor();
        if (!$tuplaAgente) {
            // Agente no se ha encontrado en la base de datos
            // Agent not found in database
            return FALSE;
        }
        $sClaveECCPAgente = $tuplaAgente['eccp_password'];

        // Para pruebas, se acepta a agente sin password
        // For testing, agent without password is accepted
        if (is_null($sClaveECCPAgente)) return TRUE;

        // Calcular el hash que debió haber enviado el cliente
        // Calculate the hash that the client should have sent
        $sHashEsperado = md5($appcookie.$sAgente.$sClaveECCPAgente);
        return ($sHashEsperado == $sHashCliente);
    }

    private function Request_eccpauth_getqueuescript($comando)
    {
        // Verificar que queue está presente
        // Verify that queue is present
        if (!isset($comando->queue))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $queue = (int)$comando->queue;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetQueueScriptResponse = $xml_response->addChild('getqueuescript_response');

        // Leer la información del script de la cola. El ORDER BY estatus hace
        // que se devuelva A y luego I.
        // Read the queue script information. ORDER BY status makes it return A then I.
        $recordset = $this->_db->prepare(
            'SELECT script FROM queue_call_entry '.
            'WHERE queue = ? ORDER BY estatus LIMIT 0,1');
        $recordset->execute(array($queue));
        $tupla = $recordset->fetch(); $recordset->closeCursor();
        if (!$tupla) {
            $this->_agregarRespuestaFallo($xml_GetQueueScriptResponse, 404, 'Queue not found in incoming queues');
            return $xml_response;
        }
        $xml_GetQueueScriptResponse->addChild('script', xmlSafe($tupla['script']));
        return $xml_response;
    }

    private function Request_eccpauth_getcampaignlist($comando)
    {
        // Tipo de campaña
        // Campaign type
        $sTipoCampania = NULL;
        if (isset($comando->campaign_type)) {
            $sTipoCampania = (string)$comando->campaign_type;
        }
        $listaTiposConocidos = array('incoming', 'outgoing');
        if (!is_null($sTipoCampania) && !in_array($sTipoCampania, $listaTiposConocidos))
            return $this->_generarRespuestaFallo(400, 'Bad request - invalid campaign type');
        if (!is_null($sTipoCampania))
            $listaTipos = array($sTipoCampania);
        else $listaTipos = $listaTiposConocidos;

        // Filtro por nombre
        // Filter by name
        $sNombreContiene = NULL;
        if (isset($comando->filtername)) {
            $sNombreContiene = (string)$comando->filtername;
        }

        // Filtro por status
        // Filter by status
        $sEstado = NULL;
        if (isset($comando->status)) {
            $sEstado = (string)$comando->status;
            $listaEstadosConocidos = array(
                'active'    =>  'A',
                'inactive'  =>  'I',
                'finished'  =>  'T');
            if (!in_array($sEstado, array_keys($listaEstadosConocidos)))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid status');
            $sEstado = $listaEstadosConocidos[$sEstado];
        }

        // Fechas de inicio y fin
        // Start and end dates
        $sFechaInicio = $sFechaFin = NULL;
        if (isset($comando->datetime_start)) {
            $sFechaInicio = (string)$comando->datetime_start;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaInicio))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid start date');
        }
        if (isset($comando->datetime_end)) {
            $sFechaFin = (string)$comando->datetime_end;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaFin))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid end date');
        }
        if (!is_null($sFechaInicio) && !is_null($sFechaFin) && $sFechaFin < $sFechaInicio) {
            $t = $sFechaInicio;
            $sFechaInicio = $sFechaFin;
            $sFechaFin = $t;
        }

        // Offset y límite
        // Offset and limit
        $iOffset = NULL; $iLimite = NULL;
        if (isset($comando->limit)) {
            $iLimite = (int)$comando->limit;
            $iOffset = 0;
        }
        if (isset($comando->offset)) $iOffset = (int)$comando->offset;
        if (!is_null($iOffset) && is_null($iLimite))
            return $this->_generarRespuestaFallo(400, 'Bad request - offset without limit');

        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetCampaignListResponse = $xml_response->addChild('getcampaignlist_response');

        $recordset = array();
        $listaSQL = array();
        $paramSQL = array();

        foreach ($listaTipos as $sTipo) {
            switch ($sTipo) {
            case 'incoming':
                $sPeticionSQL = "SELECT 'incoming' AS campaign_type, id, name, estatus AS status FROM campaign_entry";
                break;
            case 'outgoing':
                $sPeticionSQL = "SELECT 'outgoing' AS campaign_type, id, name, estatus AS status FROM campaign";
                break;
            }

            $listaWhere = array();
            if (!is_null($sNombreContiene)) {
                $listaWhere[] = 'name LIKE ?';
                $paramSQL[] = '%'.$sNombreContiene.'%';
            }
            if (!is_null($sEstado)) {
                $listaWhere[] = 'estatus = ?';
                $paramSQL[] = $sEstado;
            }
            if (!is_null($sFechaInicio)) {
                $listaWhere[] = 'datetime_init >= ?';
                $paramSQL[] = $sFechaInicio;
            }
            if (!is_null($sFechaFin)) {
                $listaWhere[] = 'datetime_init < ?';
                $paramSQL[] = $sFechaFin;
            }

            if (count($listaWhere) > 0) {
                $sPeticionSQL .= ' WHERE '.implode(' AND ', $listaWhere);
            }

            $listaSQL[] = $sPeticionSQL;
        }

        // Preparar UNION SQL
        if (count($listaSQL) > 0)
            $sPeticionSQL = '('.implode(') UNION (', $listaSQL).')';
        else $sPeticionSQL = $listaSQL[0];

        $sPeticionSQL .= ' ORDER BY campaign_type, id';
        if (!is_null($iLimite)) {
            $sPeticionSQL .= ' LIMIT ? OFFSET ?';
            $paramSQL[] = $iLimite;
            $paramSQL[] = $iOffset;
        }

        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute($paramSQL);

        $descEstados = array(
            'A' =>  'active',
            'I' =>  'inactive',
            'T' =>  'finished',
        );

        $xml_campaigns = $xml_GetCampaignListResponse->addChild('campaigns');
        foreach ($recordset as $tupla) {
            $xml_campaign = $xml_campaigns->addChild('campaign');
            $xml_campaign->addChild('id', $tupla['id']);
            $xml_campaign->addChild('type', $tupla['campaign_type']);
            $xml_campaign->addChild('name', xmlSafe($tupla['name']));
            $xml_campaign->addChild('status', $descEstados[$tupla['status']]);
        }

        return $xml_response;
    }

    private function Request_eccpauth_getincomingqueuelist($comando)
    {
        // Offset y límite
        // Offset and limit
        $iOffset = NULL; $iLimite = NULL;
        if (isset($comando->limit)) {
            $iLimite = (int)$comando->limit;
            $iOffset = 0;
        }
        if (isset($comando->offset)) $iOffset = (int)$comando->offset;
        if (!is_null($iOffset) && is_null($iLimite))
            return $this->_generarRespuestaFallo(400, 'Bad request - offset without limit');

        $xml_response = new SimpleXMLElement('<response />');
        $xml_ListResponse = $xml_response->addChild('getincomingqueuelist_response');

        $sPeticionSQL = 'SELECT id, queue, estatus FROM queue_call_entry ORDER BY id';
        $paramSQL = array();
        if (!is_null($iLimite)) {
            $sPeticionSQL .= ' LIMIT ? OFFSET ?';
            $paramSQL[] = $iLimite;
            $paramSQL[] = $iOffset;
        }

        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute($paramSQL);

        $descEstados = array(
            'A' =>  'active',
            'I' =>  'inactive',
            'T' =>  'finished',
        );

        $xml_queues = $xml_ListResponse->addChild('queues');
        foreach ($recordset as $tupla) {
            $xml_queue = $xml_queues->addChild('queue');
            $xml_queue->addChild('id', $tupla['id']);
            $xml_queue->addChild('queue', $tupla['queue']);
            $xml_queue->addChild('status', $descEstados[$tupla['estatus']]);
        }
        return $xml_response;
    }

    private function Request_eccpauth_getcampaignqueuewait($comando)
    {
        // Verificar que id y tipo está presente
        // Verify that id and type are present
        if (!isset($comando->campaign_id))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        if (!isset($comando->campaign_type))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idCampania = (int)$comando->campaign_id;
        $sTipoCampania = (string)$comando->campaign_type;

        // Elegir SQL a partir del tipo de campaña requerida
        // Choose SQL based on the required campaign type
        if ($sTipoCampania == 'incoming') {
            $sqlLlamadasExito = 'SELECT COUNT(*) AS N, duration_wait FROM call_entry WHERE id_campaign = ? AND (status = "activa" OR status = "terminada") GROUP BY duration_wait';
            $sqlLlamadasAbandonadas = 'SELECT COUNT(*) AS N FROM call_entry WHERE id_campaign = ? AND status = "abandonada"';
        } elseif ($sTipoCampania == 'outgoing') {
            $sqlLlamadasExito = 'SELECT COUNT(*) AS N, duration_wait FROM calls WHERE id_campaign = ? AND status = "Success" GROUP BY duration_wait';
            $sqlLlamadasAbandonadas = 'SELECT COUNT(*) AS N FROM calls WHERE id_campaign = ? AND status = "Abandoned"';
        } else {
            return $this->_generarRespuestaFallo(400, 'Bad request');
        }

        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetCampaignQueueWaitResponse = $xml_response->addChild('getcampaignqueuewait_response');

        $recordset = $this->_db->prepare($sqlLlamadasExito);
        $recordset->execute(array($idCampania));

        // División del histograma: tamaño de intervalos y límite máximo
        // Histogram division: interval size and maximum limit
        $iValorIntervalo = 5; $iMaxValor = 30;
        $histograma = array();
        for ($i = 0; $i <= $iMaxValor; $i += $iValorIntervalo) {
            $histograma[$i / $iValorIntervalo] = 0;
        }
        foreach ($recordset as $tupla) {
            $iPosHistograma = ($tupla['duration_wait'] >= $iMaxValor)
                ? count($histograma) - 1
                : (int)($tupla['duration_wait'] / $iValorIntervalo);
            $histograma[$iPosHistograma] += $tupla['N'];
        }

        $recordset = $this->_db->prepare($sqlLlamadasAbandonadas);
        $recordset->execute(array($idCampania));
        $tuplaAbandonadas = $recordset->fetch(); $recordset->closeCursor();

        // Construcción de la respuesta
        // Building the response
        $xml_histograma = $xml_GetCampaignQueueWaitResponse->addChild('histogram');
        foreach ($histograma as $iPosHistograma => $iCuentaHistograma) {
            $iValorInferior = $iPosHistograma * $iValorIntervalo;
            $iValorSuperior = $iValorInferior + $iValorIntervalo - 1;
            $xml_intervalo = $xml_histograma->addChild('interval');
            $xml_intervalo->addChild('lower', $iValorInferior);
            if ($iPosHistograma != count($histograma) - 1)
                $xml_intervalo->addChild('upper', $iValorSuperior);
            $xml_intervalo->addChild('count', $iCuentaHistograma);
        }
        $xml_GetCampaignQueueWaitResponse->addChild('abandoned', $tuplaAbandonadas['N']);

        return $xml_response;
    }

    /**
     * Procedimiento que implementa la lectura de la información estática de
     * una campaña entrante o saliente. Por información estática se entiende la
     * información que no cambia a medida que se progresa con las llamadas
     * asociadas a la campaña.
     * Procedure that implements reading the static information of an incoming
     * or outgoing campaign. Static information is understood as the information
     * that does not change as calls associated with the campaign progress.
     *
     * @param   object  $comando    Comando
     *      <getcampaigninfo>
     *          <campaign_type>outgoing|incoming</campaign_type> <!-- Opcional, por omisión es outgoing -->
     *                                                                <!-- Optional, defaults to outgoing -->
     *          <campaign_id>123</campaign_id>
     *      </getcampaigninfo>
     *
     * @return  object  Respuesta codificada como un SimpleXMLObject
     *                  Response encoded as a SimpleXMLObject
     *      <getcampaigninfo_response>
     *          <name>Nombre de la campaña</name>
     *          <type>incoming|outgoing</type>
     *          <startdate>yyyy-mm-dd</startdate>
     *          <enddate>yyyy-mm-dd</enddate>
     *          <working_time_starttime>hh:mm:ss</working_time_starttime>
     *          <working_time_endtime>hh:mm:ss</working_time_endtime>
     *          <queue>8000</queue>
     *          <retries>5</retries>                <!-- Sólo saliente -->
     *                                                <!-- Outgoing only -->
     *          <trunk>SIP/saliente</trunk>         <!-- Sólo saliente. Si no presente, se asume Local/xxx@from-internal -->
     *                                                <!-- Outgoing only. If not present, Local/xxx@from-internal is assumed -->
     *          <context>from-internal</context>    <!-- Sólo saliente -->
     *                                                <!-- Outgoing only -->
     *          <maxchan>32</maxchan>               <!-- Sólo saliente -->
     *                                                <!-- Outgoing only -->
     *          <status>active|inactive|complete</status>
     *          <script>Texto a usar como script de la campaña</script>
     *          <form id="2">...</form>
     *          <form id="3">...</form>
     *      </getcampaigninfo_response>
     */
    private function Request_eccpauth_getcampaigninfo($comando)
    {
        // Verificar que id y tipo está presente
        // Verify that id and type are present
        if (!isset($comando->campaign_id))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idCampania = (int)$comando->campaign_id;
        $sTipoCampania = 'outgoing';
        if (isset($comando->campaign_type)) {
            $sTipoCampania = (string)$comando->campaign_type;
        }

        if (!in_array($sTipoCampania, array('incoming', 'outgoing')))
            return $this->_generarRespuestaFallo(400, 'Bad request');

        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetCampaignInfoResponse = $xml_response->addChild('getcampaigninfo_response');

        switch ($sTipoCampania) {
        case 'outgoing':
            $sql_campania = <<<LEER_CAMPANIA
            SELECT 
                campaign.name,
                'outgoing' AS type,
                campaign.datetime_init AS startdate,
                campaign.datetime_end AS enddate,
                campaign.daytime_init AS working_time_starttime,
                campaign.daytime_end AS working_time_endtime,
                campaign.queue,
                campaign.retries,
                campaign.trunk,
                campaign.context,
                campaign.max_canales AS maxchan,
                campaign.estatus AS status,
                campaign.script,
                url1.urltemplate AS urltemplate,
                url1.description AS urldescription,
                url1.opentype AS urlopentype,
                url2.urltemplate AS urltemplate2,
                url2.description AS urldescription2,
                url2.opentype AS urlopentype2,
                url3.urltemplate AS urltemplate3,
                url3.description AS urldescription3,
                url3.opentype AS urlopentype3
            FROM 
                campaign
            LEFT JOIN 
                campaign_external_url url1 ON campaign.id_url = url1.id AND url1.active = 1
            LEFT JOIN 
                campaign_external_url url2 ON campaign.id_url2 = url2.id AND url2.active = 1
            LEFT JOIN 
                campaign_external_url url3 ON campaign.id_url3 = url3.id AND url3.active = 1
            WHERE 
                campaign.id = ?
            LEER_CAMPANIA;
            $sql_forms = 'SELECT DISTINCT id_form FROM campaign_form WHERE id_campaign = ?';
            break;
        case 'incoming':
        $sql_campania = <<<LEER_CAMPANIA
            SELECT 
                campaign_entry.name,
                'incoming' AS type,
                campaign_entry.datetime_init AS startdate,
                campaign_entry.datetime_end AS enddate,
                campaign_entry.daytime_init AS working_time_starttime,
                campaign_entry.daytime_end AS working_time_endtime,
                queue_call_entry.queue,
                campaign_entry.estatus AS status,
                campaign_entry.script,
                campaign_entry.id_form,
                url.urltemplate AS urltemplate,
                url.description AS urldescription,
                url.opentype AS urlopentype,
                url2.urltemplate AS urltemplate2,
                url2.description AS urldescription2,
                url2.opentype AS urlopentype2,
                url3.urltemplate AS urltemplate3,
                url3.description AS urldescription3,
                url3.opentype AS urlopentype3
            FROM 
                campaign_entry
            JOIN 
                queue_call_entry ON campaign_entry.id_queue_call_entry = queue_call_entry.id
            LEFT JOIN 
                campaign_external_url url ON campaign_entry.id_url = url.id AND url.active = 1
            LEFT JOIN 
                campaign_external_url url2 ON campaign_entry.id_url2 = url2.id AND url2.active = 1
            LEFT JOIN 
                campaign_external_url url3 ON campaign_entry.id_url3 = url3.id AND url3.active = 1
            WHERE 
                campaign_entry.id = ?
            LEER_CAMPANIA;
            $sql_forms = 'SELECT DISTINCT id_form FROM campaign_form_entry WHERE id_campaign = ?';
            break;
        }

        // Leer la información de la campaña
        // Read the campaign information
        $recordset = $this->_db->prepare($sql_campania);
        $recordset->execute(array($idCampania));
        $tuplaCampania = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        if (!$tuplaCampania) {
            $this->_agregarRespuestaFallo($xml_GetCampaignInfoResponse, 404, 'Campaign not found');
            return $xml_response;
        }

        // Leer la lista de formularios asociados a esta campaña
        // Read the list of forms associated with this campaign
        $recordset = $this->_db->prepare($sql_forms);
        $recordset->execute(array($idCampania));
        $idxForm = $recordset->fetchAll(PDO::FETCH_COLUMN, 0);

        // Se agrega posible formulario asociado en tabla campaign_entry
        // Possible associated form is added in campaign_entry table
        if (isset($tuplaCampania['id_form']) &&
            !is_null($tuplaCampania['id_form']) &&
            !in_array($tuplaCampania['id_form'], $idxForm))
            $idxForm[] = $tuplaCampania['id_form'];
        unset($tuplaCampania['id_form']);

        // Leer los campos asociados a cada formulario
        // Read the fields associated with each form
        $listaForm = $this->_leerCamposFormulario($idxForm);
        if (is_null($listaForm)) {
            $this->_agregarRespuestaFallo($xml_GetCampaignInfoResponse, 500, 'Cannot read campaign info (formfields)');
            return $xml_response;
        }
        $listaNombresForm = $this->_leerInfoFormulario($idxForm);
        if (is_null($listaNombresForm)) {
            $this->_agregarRespuestaFallo($xml_GetCampaignInfoResponse, 500, 'Cannot read campaign info (formnames)');
            return $xml_response;
        }

        // Construir la respuesta con la información del campo
        // Build the response with the field information
        $descEstados = array(
            'A' =>  'active',
            'I' =>  'inactive',
            'T' =>  'finished',
        );
        foreach ($tuplaCampania as $sKey => $sValor) {
            switch ($sKey) {
            case 'script':
                /* El control de edición en la creación/modificación del script
                 * manda a guardar texto con entidades de HTML a la base de
                 * datos. Para compatibilidad con campañas antiguas, se deshace
                 * la codificación de HTML aquí.
                 * The edit control in script creation/modification sends text
                 * with HTML entities to the database. For compatibility with
                 * old campaigns, HTML encoding is undone here. */
                $sValor = html_entity_decode($sValor, ENT_COMPAT, 'UTF-8');
                $xml_GetCampaignInfoResponse->addChild($sKey, xmlSafe($sValor));
                break;
            case 'status':
                $sValor = $descEstados[$sValor];
                $xml_GetCampaignInfoResponse->addChild($sKey, xmlSafe($sValor));
                break;
            case 'trunk':   // Sólo para campañas salientes
                                // Only for outgoing campaigns
                // Pasar al caso default si el valor no es nulo
                // Pass to default case if value is not null
                if (is_null($sValor)) break;
            default:
                $xml_GetCampaignInfoResponse->addChild($sKey, xmlSafe($sValor));
                break;
            }
        }

        // Construir la información de los formularios
        // Build the forms information
        $xml_Forms = $xml_GetCampaignInfoResponse->addChild('forms');
        foreach ($listaForm as $idForm => $listaCampos) {
            $this->_agregarCamposFormulario($xml_Forms, $idForm, $listaCampos, $listaNombresForm[$idForm]);
        }

        return $xml_response;
    }

    private function _leerInfoFormulario($idxForm)
    {
        $listaForm = array();
        foreach ($idxForm as $idForm) {
            $recordset = $this->_db->prepare(
                'SELECT id, nombre, descripcion, estatus FROM form WHERE id = ?');
            $recordset->execute(array($idForm));
            $r = $recordset->fetch(); $recordset->closeCursor();
            if ($r) {
                $listaForm[$idForm] = array(
                    'name'          =>  $r['nombre'],
                    'description'   =>  $r['descripcion'],
                    'status'        =>  $r['estatus'],
                );
            }
        }
        return $listaForm;
    }

    private function _leerCamposFormulario($idxForm)
    {
        $listaForm = array();
        foreach ($idxForm as $idForm) {
            $recordset = $this->_db->prepare(
                'SELECT id, etiqueta AS label, value, tipo AS type, orden AS `order` '.
                'FROM form_field WHERE id_form = ? ORDER BY `order`');
            $recordset->execute(array($idForm));
            $r = $recordset->fetchAll(PDO::FETCH_ASSOC);
            $recordset->closeCursor();
            if (count($r) > 0) {
                $listaForm[$idForm] = array();
                foreach ($r as $tuplaCampo)
                    $listaForm[$idForm][$tuplaCampo['id']] = $tuplaCampo;
            }
        }
        return $listaForm;
    }

    private function _agregarCamposFormulario(&$xml_GetCampaignInfoResponse, $idForm, &$listaCampos, &$nombresForm)
    {
        $xml_Form = $xml_GetCampaignInfoResponse->addChild('form');
        $xml_Form->addAttribute('id', $idForm);
        // Rodeo para bug PHP https://bugs.php.net/bug.php?id=41175
        if ($nombresForm['name'] != '')
            $xml_Form->addAttribute('name', $nombresForm['name']);
        if ($nombresForm['description'] != '')
            $xml_Form->addAttribute('description', $nombresForm['description']);
        $xml_Form->addAttribute('status', $nombresForm['status']);
        foreach ($listaCampos as $tuplaCampo) {
            $xml_Field = $xml_Form->addChild('field');
            $xml_Field->addAttribute('order', $tuplaCampo['order']);
            $xml_Field->addAttribute('id', $tuplaCampo['id']);
            $xml_Field->addChild('label', xmlSafe($tuplaCampo['label']));
            $xml_Field->addChild('type', xmlSafe($tuplaCampo['type']));

            // TODO: permitir especificar longitud de la entrada
            // TODO: allow specifying input length
            if (!in_array($tuplaCampo['type'], array('LABEL', 'DATE')))
                $xml_Field->addChild('maxsize', 250);

            if ($tuplaCampo['type'] == 'LIST') {
                // OJO: PRIMERA FORMA ANORMAL!!!
                // La implementación actual del código de formulario
                // agrega una coma de más al final de la lista
                // CAUTION: FIRST ABNORMAL FORM!!!
                // The current form code implementation adds an extra comma
                // at the end of the list
                if (strlen($tuplaCampo['value']) > 0 &&
                    substr($tuplaCampo['value'], strlen($tuplaCampo['value']) - 1, 1) == ',') {
                    $tuplaCampo['value'] = substr($tuplaCampo['value'], 0, strlen($tuplaCampo['value']) - 1);
                }
                $xml_Values = $xml_Field->addChild('options');
                foreach (explode(',', $tuplaCampo['value']) as $sValor) {
                    $xml_Values->addChild('value', xmlSafe($sValor));
                }
            } else {
                // Usar el valor 'value' como valor por omisión.
                // TODO: (2011-02-02) soporte de formulario para valor por
                // omisión todavía no está implementado en agent_console o en
                // definición de formulario en interfaz web
                // Use 'value' value as default value.
                // TODO: (2011-02-02) form support for default value is not yet
                // implemented in agent_console or in web interface form definition
                $sDefVal = trim($tuplaCampo['value']);
                if ($sDefVal != '')
                    $xml_Field->addChild('default_value', xmlSafe($sDefVal));
            }
        }
    }

    private function Request_eccpauth_getcallinfo($comando)
    {
        // Si no hay un tipo de campaña, se asume saliente
        // If no campaign type is present, outgoing is assumed
        $sTipoCampania = 'outgoing';
        if (isset($comando->campaign_type)) {
            $sTipoCampania = (string)$comando->campaign_type;
        }
        if (!in_array($sTipoCampania, array('incoming', 'outgoing')))
            return $this->_generarRespuestaFallo(400, 'Bad request');

        // El ID de campaña es opcional para campañas entrantes
        // Campaign ID is optional for incoming campaigns
        if (!isset($comando->campaign_id) && $sTipoCampania != 'incoming')
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idCampania = isset($comando->campaign_id) ? (int)$comando->campaign_id : NULL;

        // Verificar que id de llamada está presente
        // Verify that call ID is present
        if (!isset($comando->call_id))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idLlamada = (int)$comando->call_id;

        // Ejecutar la llamada y verificar la respuesta...
        // Execute the call and verify the response...
        $infoLlamada = leerInfoLlamada($this->_db, $sTipoCampania, $idCampania, $idLlamada);

        $xml_response = new SimpleXMLElement('<response />');
        $xml_GetCallInfoResponse = $xml_response->addChild('getcallinfo_response');
        if (is_null($infoLlamada)) {
            $this->_agregarRespuestaFallo($xml_GetCallInfoResponse, 500, 'Cannot read call info');
            return $xml_response;
        }
        if (count($infoLlamada) <= 0) {
            $this->_agregarRespuestaFallo($xml_GetCallInfoResponse, 404, 'Call not found');
            return $xml_response;
        }

        // Armar la respuesta XML
        // Build the XML response
        self::construirRespuestaCallInfo($infoLlamada, $xml_GetCallInfoResponse);
        return $xml_response;
    }

    // Compartido entre getcallinfo y evento agentlinked
    // Shared between getcallinfo and agentlinked event
    static function construirRespuestaCallInfo($infoLlamada, $xml_GetCallInfoResponse)
    {
        foreach ($infoLlamada as $sKey => $valor) {
            switch ($sKey) {
            case 'call_attributes':
                $xml_callAttrlist = $xml_GetCallInfoResponse->addChild($sKey);
                foreach ($valor as $tuplaAttr) {
                    $xml_callAttr = $xml_callAttrlist->addChild('attribute');
                    $xml_callAttr->addChild('label', xmlSafe($tuplaAttr['label']));
                    $xml_callAttr->addChild('value', xmlSafe($tuplaAttr['value']));
                    $xml_callAttr->addChild('order', xmlSafe($tuplaAttr['order']));
                }
                break;
            case 'matching_contacts':
                $xml_contacts = $xml_GetCallInfoResponse->addChild($sKey);
                foreach ($valor as $id_contact => $tuplaContact) {
                    $xml_callAttrlist = $xml_contacts->addChild('contact');
                    $xml_callAttrlist->addAttribute('id', $id_contact);
                    foreach ($tuplaContact as $tuplaAttr) {
                        $xml_callAttr = $xml_callAttrlist->addChild('attribute');
                        $xml_callAttr->addChild('label', xmlSafe($tuplaAttr['label']));
                        $xml_callAttr->addChild('value', xmlSafe($tuplaAttr['value']));
                        $xml_callAttr->addChild('order', xmlSafe($tuplaAttr['order']));
                    }
                }
                break;
            case 'call_survey':
                $xml_callFormlist = $xml_GetCallInfoResponse->addChild($sKey);
                foreach ($valor as $id_form => $valoresForm) {
                    $xml_callForm = $xml_callFormlist->addChild('form');
                    $xml_callForm->addAttribute('id', $id_form);
                    foreach ($valoresForm as $tuplaValor) {
                        $xml_callFormField = $xml_callForm->addChild('field');
                        $xml_callFormField->addAttribute('id', $tuplaValor['id']);
                        $xml_callFormField->addChild('label', xmlSafe($tuplaValor['label']));
                        $xml_callFormField->addChild('value', xmlSafe($tuplaValor['value']));
                    }
                }
                break;
            default:
                if (!is_null($valor)) $xml_GetCallInfoResponse->addChild($sKey, xmlSafe($valor));
                break;
            }
        }
    }

    private function _leerAgenteLlamada($sTipoCampania, $idLlamada)
    {
        switch ($sTipoCampania) {
        case 'incoming':
            $sPeticionSQL =
                'SELECT CONCAT(agent.type,"/",agent.number) AS agentchannel FROM call_entry, agent '.
                'WHERE call_entry.id_agent = agent.id AND call_entry.id = ?';
            break;
        case 'outgoing':
            $sPeticionSQL =
                'SELECT CONCAT(agent.type,"/",agent.number) AS agentchannel FROM calls, agent '.
                'WHERE calls.id_agent = agent.id AND calls.id = ?';
            break;
        default:
            return NULL;
        }
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idLlamada));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        return $tupla ? $tupla['agentchannel'] : NULL;
    }

    private function Request_agentauth_setcontact($comando)
    {
        // Verificar que id de llamada está presente
        // Verify that call ID is present
        if (!isset($comando->call_id))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idLlamada = (int)$comando->call_id;

        // Verificar que id de contacto está presente
        // Verify that contact ID is present
        if (!isset($comando->contact_id))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idContacto = (int)$comando->contact_id;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_setContactResponse = $xml_response->addChild('setcontact_response');

        $bExito = TRUE;

        // Verificar que existe realmente la llamada entrante
        // Verify that the incoming call actually exists
        if ($bExito) {
            $recordset = $this->_db->prepare(
                'SELECT COUNT(*) AS N FROM call_entry WHERE id = ?');
            $recordset->execute(array($idLlamada));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC); $recordset->closeCursor();
            if ($tupla['N'] < 1) {
                $this->_agregarRespuestaFallo($xml_setContactResponse, 404, 'Call ID not found');
                $bExito = FALSE;
            }
        }

        // Verificar que el agente declarado realmente atendió esta llamada
        // Verify that the declared agent actually attended this call
        if ($bExito) {
            $sAgenteLlamada = $this->_leerAgenteLlamada('incoming', $idLlamada);
            if (is_null($sAgenteLlamada) || $sAgenteLlamada != (string)$comando->agent_number) {
                $this->_agregarRespuestaFallo($xml_setContactResponse, 401, 'Unauthorized agent');
                $bExito = FALSE;
            }
        }

        // Verificar que existe realmente el contacto indicado
        // Verify that the indicated contact actually exists
        if ($bExito) {
            $recordset = $this->_db->prepare(
                'SELECT COUNT(*) AS N FROM contact WHERE id = ?');
            $recordset->execute(array($idContacto));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC); $recordset->closeCursor();
            if ($tupla['N'] < 1) {
                $this->_agregarRespuestaFallo($xml_setContactResponse, 404, 'Contact ID not found');
                $bExito = FALSE;
            }
        }

        if ($bExito) {
            $sth = $this->_db->prepare('UPDATE call_entry SET id_contact = ? WHERE id = ?');
            $sth->execute(array($idContacto, $idLlamada));
        }

        if ($bExito) {
            $xml_setContactResponse->addChild('success');
        }

        return $xml_response;
    }

    /*
    private function Request_eccpauth_dial($comando)
    {
        return $this->_generarRespuestaFallo(501, 'Not Implemented');
    }
    */

    private function Request_agentauth_saveformdata($comando)
    {
        // Si no hay un tipo de campaña, se asume saliente
        // If no campaign type is present, outgoing is assumed
        $sTipoCampania = 'outgoing';
        if (isset($comando->campaign_type)) {
            $sTipoCampania = (string)$comando->campaign_type;
        }
        if (!in_array($sTipoCampania, array('incoming', 'outgoing')))
            return $this->_generarRespuestaFallo(400, 'Bad request');

        // Verificar que id de llamada está presente
        // Verify that call ID is present
        if (!isset($comando->call_id))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idLlamada = (int)$comando->call_id;

        // Verificar que elemento forms está presente
        // Verify that forms element is present
        if (!isset($comando->forms))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $infoDatos = array();
        foreach ($comando->forms->form as $xml_form) {
            $idForm = (int)$xml_form['id'];

            // No se permiten IDs duplicados de formulario
            if (isset($infoDatos[$idForm]))
                return $this->_generarRespuestaFallo(400, 'Bad request');

            $infoDatos[$idForm] = array();
            foreach ($xml_form->field as $xml_field) {
                $idField = (int)$xml_field['id'];
                $infoDatos[$idForm][$idField] = (string)$xml_field;
            }
        }

        $xml_response = new SimpleXMLElement('<response />');
        $xml_saveFormDataResponse = $xml_response->addChild('saveformdata_response');

        // Verificar que el agente declarado realmente atendió esta llamada
        // Verify that the declared agent actually attended this call
        $sAgenteLlamada = $this->_leerAgenteLlamada($sTipoCampania, $idLlamada);
        if (is_null($sAgenteLlamada) || $sAgenteLlamada != (string)$comando->agent_number) {
            $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 401, 'Unauthorized agent');
            return $xml_response;
        }

        // Leer la información del formulario, para validación
        // Read the form information for validation
        $infoFormulario = $this->_leerCamposFormulario(array_keys($infoDatos));
        if (is_null($infoFormulario)) {
            $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 500, 'Cannot read form information');
        } else {
            $listaSQL = array();
            list($fdr_tabla, $fdr_campo) = nombresCamposFormulariosEstaticos($sTipoCampania);
            $recordset = $this->_db->prepare("SELECT COUNT(*) FROM $fdr_tabla WHERE $fdr_campo = ? AND id_form_field = ?");
            $sth_insert = $this->_db->prepare("INSERT INTO $fdr_tabla (value, $fdr_campo, id_form_field) VALUES (?, ?, ?)");
            $sth_update = $this->_db->prepare("UPDATE $fdr_tabla SET value = ? WHERE $fdr_campo = ? AND id_form_field = ?");


            /* Validación básica de los valores a guardar, combinada con
             * generación de las sentencias SQL para almacenar */
            $bDatosValidos = TRUE;
            foreach ($infoDatos as $idForm => $infoDatosForm) {
                foreach ($infoDatosForm as $idField => $sValor) {
                    if (!isset($infoFormulario[$idForm])) {
                        $bDatosValidos = FALSE;
                        $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 404, 'Form ID not found: '.$idForm);
                    } elseif (!isset($infoFormulario[$idForm][$idField])) {
                        $bDatosValidos = FALSE;
                        $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 404, 'Field ID not found in form: '.$idForm.' - '.$idField);
                    }
                    if (!$bDatosValidos) break;

                    $infoCampo = $infoFormulario[$idForm][$idField];
                    if ($infoCampo['type'] == 'LABEL') continue;

                    // TODO: extraer máxima longitud de base de datos
                    // TODO: extract maximum length from database
                    if (strlen($sValor) > 250) {
                        $bDatosValidos = FALSE;
                        $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 413, 'Form value too large: '.$idForm.' - '.$idField);

                    // Validar que el campo de fecha tenga valor correcto
                    // Validate that date field has correct value
                    } elseif ($infoCampo['type'] == 'DATE' &&
                        $sValor != '' && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $sValor) || preg_match('/^\d{4}-\d{2}-\d{2} d{2}:\d{2}:\d{2}$/', $sValor))) {
                        $bDatosValidos = FALSE;
                        $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 406,
                            'Date format not acceptable, must be yyyy-mm-dd or yyyy-mm-dd hh:mm:ss: '.$idForm.' - '.$idField);
                    } else {
                        if ($infoCampo['type'] == 'LIST') {
                            // OJO: PRIMERA FORMA ANORMAL!!!
                            // La implementación actual del código de formulario
                            // agrega una coma de más al final de la lista
                            if (strlen($infoCampo['value']) > 0 &&
                                substr($infoCampo['value'], strlen($infoCampo['value']) - 1, 1) == ',') {
                                $infoCampo['value'] = substr($infoCampo['value'], 0, strlen($infoCampo['value']) - 1);
                            }
                            if (!in_array($sValor, explode(',', $infoCampo['value']))) {
                                $bDatosValidos = FALSE;
                                $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 406,
                                    'Value not in list of accepted values: '.$idForm.' - '.$idField);
                            }
                        }
                    }
                    if (!$bDatosValidos) break;

                    // En este punto este valor es válido y se puede generar SQL
                    if (!$recordset->execute(array($idLlamada, $idField))) {
                        $bDatosValidos = FALSE;
                        $this->_agregarRespuestaFallo($xml_saveFormDataResponse, 500,
                            'Unable to check previous form value');
                    } else {
                    	$tupla = $recordset->fetch(PDO::FETCH_NUM); $recordset->closeCursor();
                        if ($tupla[0] <= 0) {
                        	$listaSQL[] = array($sth_insert, array($sValor, $idLlamada, $idField));
                        } else {
                        	$listaSQL[] = array($sth_update, array($sValor, $idLlamada, $idField));
                        }
                    }
                }
                if (!$bDatosValidos) break;
            }

            // Se procede a guardar los datos del formulario
            if ($bDatosValidos) {
                foreach ($listaSQL as $infoSQL) {
                    $infoSQL[0]->execute($infoSQL[1]);
                    $infoSQL[0]->closeCursor();
                }
            }

            if ($bDatosValidos) {
                $xml_saveFormDataResponse->addChild('success');
            }
        }

        return $xml_response;
    }

    private function Request_eccpauth_getpauses($comando)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_getPausesResponse = $xml_response->addChild('getpauses_response');

        $recordset = $this->_db->query(
            "SELECT id, name, status, tipo, description FROM break WHERE tipo = 'B' ORDER BY id");
        foreach ($recordset as $tupla) {
            $xml_pause = $xml_getPausesResponse->addChild('pause');
            $xml_pause->addAttribute('id', $tupla['id']);
            $xml_pause->addChild('name', xmlSafe($tupla['name']));
            $xml_pause->addChild('status', xmlSafe($tupla['status']));
            $xml_pause->addChild('type', xmlSafe($tupla['tipo']));
            $xml_pause->addChild('description', xmlSafe($tupla['description']));
        }

        return $xml_response;
    }

    /**
     * Procedimiento que implementa el login de un agente estático al estilo
     * Agent/9000. Para esta versión se asume que el agente está asociado a una
     * extensión telefónica, a la cual se mandará una llamada que conecta tal
     * extensión con la cola. El comando regresa inmediatamente. Luego el cliente
     * debe de esperar el evento LoginAgent que indica que se ha completado
     * exitosamente el login del agente, y que empezará a recibir llamadas de la
     * campaña asociada a las colas del agente.
     *
     * Implementación: las tareas a hacer para iniciar el login del agente son:
     * 1) Verificar si el agente existe en el sistema. Si no existe, se devuelve
     *    error sin hacer otra operación.
     * 2) Verificar si la extensión indicada es válida. Si no existe, se devuelve
     *    error sin hacer otra operación.
     * 3) Verificar si el agente ya está logoneado. Si ya está logoneado, entonces
     *    se debe verificar si está logoneado en la extensión indicada en el
     *    parámetro. Si es la misma extensión se devuelve éxito sin hacer nada
     *    más. Si no es la misma extensión, se devuelve error informando la
     *    situación.
     * 4) Para agente no logoneado, se inicia un Originate entre la extensión
     *    y el canal de Agent/XXXX. Como Action-Id, se indica la cadena
     *    "ECCP:1.0:<PID>:AgentLogin:<canaldeagente>"
     *    para distinguir este login de los logines a colas por otros motivos.
     * Para el resto del procesamiento se debe ver el método OnAgentlogin
     * en la clase DialerProcess.
     *
     * @param   object   $comando    Comando de login
     *      <loginagent>
     *          <agent_number>Agent/9000</agent_number>
     *          <password>xxx</password> <!-- se ignora en implementación actual -->
     *          <extension>1064</extension>
     *      </loginagent>
     *
     * @return  object  Respuesta codificada como un SimpleXMLObject
     *      <loginagent_response>
     *          <status>logged-out|logging|logged-in</status>
     *          <failure>mensaje</failure>
     *      </loginagent_response>
     */
    private function Request_eccpauth_loginagent($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que agente y extensión están presentes
        if (!isset($comando->agent_number) || !isset($comando->extension))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;
        $sExtension = (string)$comando->extension;
        $iTimeout = NULL;
        if (isset($comando->timeout)) {
            if (!preg_match('/^\d+/', (string)$comando->timeout))
                return $this->_generarRespuestaFallo(400, 'Bad request');
            $iTimeout = (int)$comando->timeout;
            if ($iTimeout <= 0)
                return $this->_generarRespuestaFallo(400, 'Bad request');
        }

        // Verificar que la extensión y el agente son válidos en el sistema
        $listaExtensiones = $this->_listarExtensiones();
        if (!is_array($listaExtensiones)) {
            return $this->Response_LoginAgentResponse('logged-out', 500, 'Failed to list extensions');
        }
        if (!$this->_existeAgente($sAgente)) {
            return $this->Response_LoginAgentResponse('logged-out', 404, 'Specified agent not found');
        } elseif (!in_array($sExtension, array_keys($listaExtensiones))) {
            return $this->Response_LoginAgentResponse('logged-out', 404, 'Specified extension not found');
        }

        // Verificar el hash del agente
        if (!$this->_hashValidoAgenteECCP($comando, $comando['appcookie'])) {
            return $this->Response_LoginAgentResponse('logged-out', 401, 'Unauthorized agent');
        }

        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
        	return $this->Response_LoginAgentResponse('logged-out', 404, 'Specified agent not found');
        }
        if (!is_null($infoSeguimiento['extension'])) {
            /* No se puede aceptar que el agente esté ya logoneado, incluso
             * con la extensión que se ha pedido, porque no se tiene la
             * información de estado del agente (Uniqueid, id_sesion, etc)
             * hasta que se implemente la recolección de tales variables
             * a partir de Asterisk y la base de datos call_center. La
             * excepción es si el programa ya hace seguimiento del agente
             * indicado. */
        	if ($infoSeguimiento['extension'] == $listaExtensiones[$sExtension]) {
                // Ya se ha iniciado el login del agente
                $sEstadoReportar = $infoSeguimiento['estado_consola'];
                if ($sEstadoReportar == 'logged-out') $sEstadoReportar = 'logging';
                return $this->Response_LoginAgentResponse($infoSeguimiento['estado_consola']);
        	} else {
                // Otra extensión ya ocupa el login del agente indicado
                return $this->Response_LoginAgentResponse('logged-out', 409,
                    'Specified agent already connected to extension: '.$infoSeguimiento['extension']);
        	}
        } else {
            // No hay canal de login. Se inicia login a través de Originate
            $r = $this->_loginAgente($listaExtensiones[$sExtension], $sAgente, $infoSeguimiento['name'], $iTimeout);
            return $r
                ? $this->Response_LoginAgentResponse('logging')
                : $this->Response_LoginAgentResponse('logged-out', 500,
                    'Failed to start login process on Asterisk');
        }
    }

    // Función que encapsula la generación de la respuesta
    private function Response_LoginAgentResponse($status, $iCodigo = NULL, $msg = NULL)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_loginAgentResponse = $xml_response->addChild('loginagent_response');

        $xml_loginAgentResponse->addChild('status', $status);
        if (!is_null($msg))
            $this->_agregarRespuestaFallo($xml_loginAgentResponse, $iCodigo, $msg);

        return $xml_response;
    }

    // TODO: encontrar manera elegante de tener una sola definición
    private function _abrirConexionFreePBX()
    {
        $sNombreConfig = '/etc/amportal.conf';  // TODO: vale la pena poner esto en config?

        // De algunas pruebas se desprende que parse_ini_file no puede parsear
        // /etc/amportal.conf, de forma que se debe abrir directamente.
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
        if (count($dbParams) < 4) {
            $this->_log->output('ERR: archivo '.$sNombreConfig.
                ' de parámetros FreePBX no tiene todos los parámetros requeridos para conexión.'.
                ' | EN: ERR: file '.$sNombreConfig.
                ' of FreePBX parameters does not have all required parameters for connection.');
            return NULL;
        }
        if ($dbParams['AMPDBENGINE'] != 'mysql' && $dbParams['AMPDBENGINE'] != 'mysqli') {
            $this->_log->output('ERR: archivo '.$sNombreConfig.
                ' de parámetros FreePBX especifica AMPDBENGINE='.$dbParams['AMPDBENGINE'].
                ' que no ha sido probado.'.
                ' | EN: ERR: file '.$sNombreConfig.
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
            $this->_log->output("ERR: no se puede conectar a DB de FreePBX - ".$e->getMessage().
                " | EN: ERR: cannot connect to FreePBX DB - ".$e->getMessage());
        	return NULL;
        }
    }

    /**
     * Método que lista todas las extensiones SIP e IAX que están definidas en
     * el sistema. Estas extensiones pueden ser usadas por el agente para
     * logonearse en el sistema. La lista se devuelve de la forma
     * (1000 => 'SIP/1000'), ...
     *
     * @return  mixed   La lista de extensiones.
     */
    private function _listarExtensiones()
    {
        $oDB = $this->_abrirConexionFreePBX();
        if (is_null($oDB)) return NULL;
        try {
            $sPeticion = 'SELECT user AS extension, dial from devices ORDER BY user';
            $recordset = $oDB->query($sPeticion);
            $listaExtensiones = array();
            foreach ($recordset as $tupla) {
                $listaExtensiones[$tupla['extension']] = $tupla['dial'];
            }
        } catch (PDOException $e) {
        	$this->_log->output('ERR: (interno) No se pueden listar extensiones - '.$e->getMessage().' | EN: ERR: (internal) Cannot list extensions - '.$e->getMessage());
        }
        $oDB = NULL;
        return $listaExtensiones;
    }

    /**
     * Método que lista todos los agentes registrados en la base de datos. La
     * lista se devuelve de la forma (9000 => 'Over 9000!!!'), ...
     *
     * @return  mixed   La lista de agentes activos
     */
    private function _listarAgentes()
    {
        $sPeticion = "SELECT type, number, name FROM agent WHERE estatus = 'A'";
        foreach ($this->_db->query($sPeticion) as $tupla) {
            $listaAgentes[$tupla['type'].'/'.$tupla['number']] = $tupla['number'].' - '.$tupla['name'];
        }
        return $listaAgentes;
    }

    private function _existeAgente($sAgente)
    {
        $agentFields = $this->_parseAgent($sAgente);
        if (is_null($agentFields)) return FALSE;
        $recordset = $this->_db->prepare('SELECT COUNT(*) AS n FROM agent WHERE estatus = ? AND type = ? AND number = ?');
        $recordset->execute(array('A', $agentFields['type'], $agentFields['number']));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        return ($tupla['n'] > 0);
    }

    /**
     * Método para iniciar el login del agente con la extensión y el número de
     * agente que se indican. Se asume que el agente es válido en el sistema.
     *
     * @param   string  Extensión que está usando el agente, como "SIP/1064"
     * @param   string  Cadena del agente que se está logoneando: "Agent/9000"
     * @param   string  Nombre del agente
     * @param   int     NULL si no aplica timeout, o máxima inactividad en segundos
     *
     * @return  VERDADERO en éxito, FALSE en error
     */
    private function _loginAgente($sExtension, $sAgente, $name, $iTimeout)
    {
        $r = NULL;
        $agentFields = $this->_parseAgent($sAgente);
        if ($agentFields['type'] == 'Agent') {
            $this->_tuberia->AMIEventProcess_agregarIntentoLoginAgente($sAgente, $sExtension, $iTimeout);
            if (!is_null($this->_compat) && $this->_compat->hasChanAgent()) {
                // chan_agent (Asterisk 11): Originate directly to AgentLogin application
                // Asterisk prompts for the password configured in agents.conf
                $r = $this->_ami->Originate(
                    $sExtension,                    // channel (SIP/1064)
                    NULL, NULL, NULL,               // exten, context, priority (not used)
                    'AgentLogin',                   // application
                    $agentFields['number'],         // data (agent number)
                    30000,                          // timeout in ms
                    $sAgente.' Login',              // CallerID
                    NULL, NULL,
                    TRUE,                           // async
                    'ECCP:1.0:'.posix_getpid().':AgentLogin:'.$sAgente
                    );
            } else {
                // app_agent_pool (Asterisk 12+): Originate call to agent-login context
                // The agent-login context runs AgentLogin() which does NOT prompt for password
                $r = $this->_ami->Originate(
                    $sExtension,                    // channel (SIP/1064)
                    $agentFields['number'],         // exten (agent number)
                    'agent-login',                  // context (runs AgentLogin app)
                    1,                              // priority
                    NULL,                           // application (use context instead)
                    NULL,                           // data
                    30000,                          // timeout in ms
                    $sAgente.' Login',              // CallerID
                    NULL, NULL,
                    TRUE,                           // async
                    'ECCP:1.0:'.posix_getpid().':AgentLogin:'.$sAgente
                    );
            }
            if ($r['Response'] != 'Success') {
                $this->_tuberia->AMIEventProcess_cancelarIntentoLoginAgente($sAgente);
                return FALSE;
            }
        } else {
            /*
             * Las colas dinámicas a las que debe pertenecer el agente las sabe
             * AMIEventProcess. Si pertenece a al menos una, se quita al agente
             * de todas las colas actuales, y a continuación se lo ingresa a
             * todas las colas dinámicas reportadas por AMIEventProcess.
             */
            $listaColas = $this->_tuberia->AMIEventProcess_listarTotalColasTrabajoAgente(array($sAgente));
            if (count($listaColas[$sAgente][1]) <= 0) {
                // Este agente no tiene colas asociadas
                $this->_log->output('WARN: agente dinámico '.$sAgente.' no es miembro dinámico de ninguna cola, no se puede realizar login. | EN: dynamic agent '.$sAgente.' is not dynamic member of any queue, cannot login.');
                $r = array(
                    'Response'  =>  'Error',
                    'Message'   =>  'Extension not a dynamic member of any queue.',
                );
            } else {
                $this->_tuberia->AMIEventProcess_agregarIntentoLoginAgente($sAgente, $sExtension, $iTimeout);

                $bIngresoCola = FALSE;
                if (count($listaColas[$sAgente][0]) > 0) {
                    $this->_log->output('WARN: '.__METHOD__.': agente '.$sAgente.
                        ' que intenta logonearse ya está en colas: ['.
                        implode(' ', $listaColas[$sAgente][0]).']'.
                        ' | EN: WARN: '.__METHOD__.': agent '.$sAgente.
                        ' attempting to login is already in queues: ['.
                        implode(' ', $listaColas[$sAgente][0]).']');
                }
                foreach ($listaColas[$sAgente][0] as $cola) {
                    // Lo saco de todas las colas ...
                    $r = $this->_ami->QueueRemove($cola, $sAgente);
                    if ($r['Response'] != 'Success') {
                        $this->_log->output('WARN: '.__METHOD__.': falla al quitar agente '.
                            $sAgente.' de cola '.$cola.': '.print_r($r, TRUE).
                            ' | EN: WARN: '.__METHOD__.': failure removing agent '.
                            $sAgente.' from queue '.$cola.': '.print_r($r, TRUE));
                    }
                }
                foreach ($listaColas[$sAgente][2] as $cola => $penalty) {
                    // Para volverlos a agregar aqui.
                    $r = $this->_ami->QueueAdd($cola, $sAgente, $penalty, $name);
                    if ($r['Response'] != 'Success') {
                        $this->_log->output('WARN: '.__METHOD__.': falla al ingresar agente '.
                            $sAgente.' a cola '.$cola.': '.print_r($r, TRUE).
                            ' | EN: WARN: '.__METHOD__.': failure adding agent '.
                            $sAgente.' to queue '.$cola.': '.print_r($r, TRUE));
                    } else $bIngresoCola = TRUE;
                }
                if (!$bIngresoCola) {
                    $this->_tuberia->AMIEventProcess_cancelarIntentoLoginAgente($sAgente);
                    return FALSE;
                }
            }
        }
        return TRUE;
    }

    /**
     * Procedimiento que implementa el logoff de un agente estático al estilo
     * Agent/9000.
     *
     * Implementación: las tareas a hacer para iniciar el login del agente son:
     * 1) Verificar si el agente existe en el sistema. Si no existe, se devuelve
     *    error sin hacer otra operación.
     * 2) El logoff sólo está implementado para agentes de tipo Agent/9000. Si
     *    se especifica otro tipo de agente, se rechaza con error de no
     *    implementado. De otro modo, se recoge el número de agente (9000)
     * 3) Se ejecuta el comando de AMI Agentlogoff() con el número de agente
     * Para el resto del procesamiento se debe ver el método OnAgentlogoff en
     * la clase DialerProcess.
     *
     * @param   object   $comando    Comando de logout
     *      <logoutagent>
     *          <agent_number>Agent/9000</agent_number>
     *      </logoutagent>
     *
     * @return  object  Respuesta codificada como un SimpleXMLObject
     *      <logoutagent_response>
     *          <status>logged-out</status>
     *          <failure>mensaje</failure>
     *      </logoutagent_response>
     */
    private function Request_eccpauth_logoutagent($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que agente está presentes
        if (!isset($comando->agent_number))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        /* Verificar que el agente sea válido en el sistema. Se duplica la
         * verificación de la decoración agentauth porque se debe de agregar
         * el estatus de agente 'logged-out'.
         */
        if (!$this->_existeAgente($sAgente)) {
            return $this->Response_LogoutAgentResponse('logged-out', 404, 'Specified agent not found');
        }

        // Verificar el hash del agente
        if (!$this->_hashValidoAgenteECCP($comando, $comando['appcookie'])) {
            return $this->Response_LogoutAgentResponse('logged-out', 401, 'Unauthorized agent');
        }

        // Canal que hizo el logoneo hacia la cola
        $infoAgente = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);

        $agentFields = $this->_parseAgent($sAgente);
        if ($agentFields['type'] == 'Agent') {
            if (!is_null($this->_compat) && $this->_compat->hasChanAgent()) {
                // chan_agent (Asterisk 11): use Agentlogoff AMI command
                $r = $this->_ami->Agentlogoff($agentFields['number']);
                // If agent hasn't entered password yet, Agentlogoff has no effect
                // so also hangup the channel directly
                if (!is_null($infoAgente) && $infoAgente['estado_consola'] == 'logging') {
                    $sCanalExt = $infoAgente['login_channel'];
                    if (is_null($sCanalExt)) $sCanalExt = $infoAgente['extension'];
                    if (!is_null($sCanalExt)) $this->_ami->Hangup($sCanalExt);
                }
            } else {
                // app_agent_pool (Asterisk 12+): Hangup the AgentLogin channel to logout
                $sCanalLogin = NULL;
                if (!is_null($infoAgente)) {
                    $sCanalLogin = $infoAgente['login_channel'];
                    if (is_null($sCanalLogin)) {
                        $sCanalLogin = $infoAgente['extension'];
                    }
                }
                if (!is_null($sCanalLogin)) {
                    $this->_ami->Hangup($sCanalLogin);
                } else {
                    $this->_log->output('WARN: '.__METHOD__.': no se encontró canal de login para agente '.$sAgente.' | EN: WARN: '.__METHOD__.': no login channel found for agent '.$sAgente);
                }
            }
        } else {
            // SIP/IAX2/PJSIP: Close client channel if connected
            if (!is_null($infoAgente) && !is_null($infoAgente['clientchannel'])) {
                $this->_ami->Hangup($infoAgente['clientchannel']);
            }

            // Remove from all queues
            $listaColas = $this->_tuberia->AMIEventProcess_listarTotalColasTrabajoAgente(array($sAgente));
            foreach ($listaColas[$sAgente][0] as $cola) {
                $r = $this->_ami->QueueRemove($cola, $sAgente);
            }
        }
        return $this->Response_LogoutAgentResponse('logged-out');
    }

    // Función que encapsula la generación de la respuesta
    private function Response_LogoutAgentResponse($status, $iCodigo = NULL, $msg = NULL)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_loginAgentResponse = $xml_response->addChild('logoutagent_response');

        $xml_loginAgentResponse->addChild('status', $status);
        if (!is_null($msg))
            $this->_agregarRespuestaFallo($xml_loginAgentResponse, $iCodigo, $msg);
        return $xml_response;
    }

    private function _marcarInicioBreakAgente($idAgente, $idBreak, $iTimestampInicio)
    {
        // Ingreso de sesión del agente
        $sTimeStamp = date('Y-m-d H:i:s', $iTimestampInicio);
        try {
            $sth = $this->_db->prepare(
                    'INSERT INTO audit (id_agent, id_break, datetime_init) VALUES (?, ?, ?)');
            $sth->execute(array($idAgente, $idBreak, $sTimeStamp));
            return $this->_db->lastInsertId();
        } catch (PDOException $e) {
            $this->_stdManejoExcepcionDB($e, 'no se puede registrar inicio de sesión de agente');
            return NULL;
        }
    }

    private function Request_agentauth_pauseagent($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        $sAgente = (string)$comando->agent_number;

        // Verificar que ID de break está presente
        if (!isset($comando->pause_type))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idBreak = (int)$comando->pause_type;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_pauseAgentResponse = $xml_response->addChild('pauseagent_response');

        // Verificar si la pausa indicada existe y está activa
        $recordset = $this->_db->prepare(
            'SELECT id, name FROM break WHERE tipo = "B" AND status = "A" AND id = ?');
        $recordset->execute(array($idBreak));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        if (!$tupla) {
            $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 404, 'Break ID not found or not active');
            return $xml_response;
        }

        // Verificar si el agente está siendo monitoreado y que no esté en pausa
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 404, 'Agent not found or not logged in through ECCP');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 417, 'Agent currently not logged in');
            return $xml_response;
        }
        if (!is_null($infoSeguimiento['id_break'])) {
            if ($infoSeguimiento['id_break'] != $idBreak) {
                // Agente ya estaba en otro break
                $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 417, 'Agent already in incompatible break');
            } else {
                // Agente ya estaba en el mismo break
                $xml_pauseAgentResponse->addChild('success');
            }
            return $xml_response;
        }

        // Se escribe el inicio provisional de la pausa en la base de datos
        $iTimestampInicioPausa = time();
        $idAuditBreak = $this->_marcarInicioBreakAgente(
            $infoSeguimiento['id_agent'], $idBreak, $iTimestampInicioPausa);
        if (is_null($idAuditBreak)) {
            $this->_agregarRespuestaFallo($xml_pauseAgentResponse, 500, 'Unable to start agent break');
            return $xml_response;
        }

        // Se comunica a AMIEventProcess la pausa elegida para que la inicie.
        // Esto puede fallar si el estado del agente ha cambiado.
        list($errcode, $errdesc) = $this->_tuberia->AMIEventProcess_iniciarBreakAgente(
            $sAgente, $idBreak, $idAuditBreak, $tupla['name']);
        if ($errcode != 0) {
            // Ha fallado el inicio de pausa, se deshace auditoría
            try {
                $sth = $this->_db->prepare('DELETE FROM audit WHERE id = ?');
                $sth->execute(array($idAuditBreak));
                $sth = NULL;
            } catch (PDOException $e) {
                $this->_stdManejoExcepcionDB($e, 'no se puede quitar auditoría provisional!');
            }
            $this->_agregarRespuestaFallo($xml_pauseAgentResponse, $errcode, $errdesc.' (collision)');
            return $xml_response;
        }

        $xml_pauseAgentResponse->addChild('success');
        return array(
            'response'  =>  $xml_response,
            'eventos'   =>  array(
                array('PauseStart', array($sAgente, array(
                    'pause_class'   =>  'break',
                    'pause_type'    =>  $idBreak,
                    'pause_name'    =>  $tupla['name'],
                    'pause_start'   =>  date('Y-m-d H:i:s', $iTimestampInicioPausa),
                ))),
            ),
        );
    }

    private function Request_agentauth_pingagent($comando)
    {
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_pingAgentResponse = $xml_response->addChild('pingagent_response');
        $r = $this->_tuberia->AMIEventProcess_pingAgente($sAgente);
        if (!$r)
    	   $this->_agregarRespuestaFallo($xml_pingAgentResponse, 404, 'Specified agent not found');
        else $xml_pingAgentResponse->addChild('success');
        return $xml_response;
    }

    private function Request_agentauth_unpauseagent($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_unpauseAgentResponse = $xml_response->addChild('unpauseagent_response');

        // Verificar si el agente está siendo monitoreado
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_unpauseAgentResponse, 404, 'Agent not found or not logged in through ECCP');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_unpauseAgentResponse, 417, 'Agent currently not logged in');
            return $xml_response;
        }
        if (is_null($infoSeguimiento['id_break'])) {
            // Si el agente no estaba en break, se devuelve éxito sin hacer nada
            $xml_unpauseAgentResponse->addChild('success');
        	return $xml_response;
        }

        $iTimestampFinalPausa = time();
        $this->_tuberia->msg_AMIEventProcess_quitarBreakAgente($sAgente);
        marcarFinalBreakAgente($this->_db,
            $infoSeguimiento['id_audit_break'], $iTimestampFinalPausa);

        $xml_unpauseAgentResponse->addChild('success');

        $ev = construirEventoPauseEnd($this->_db, $sAgente,
            $infoSeguimiento['id_audit_break'], 'break');

        return array(
            'response'  =>  $xml_response,
            'eventos'   =>  array($ev),
        );
    }

    /**
     * Procedimiento que implementa la verificación del estado de un agente
     * estático al estilo Agent/9000.
     *
     * @param   object   $comando    Comando
     *      <getagentstatus>
     *          <agent_number>Agent/9000</agent_number>
     *      </getagentstatus>
     *
     * @return  object  Respuesta codificada como un SimpleXMLObject
     *      <getagentstatus_response>
     *          <status>offline|online|oncall|paused</status>
     *          <channel>SIP/1064-000000001</channel>
     *          <extension>1064<extension/>
     *          <failure>mensaje</failure>
     *      </getagentstatus_response>
     */
    private function Request_eccpauth_getagentstatus($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        $iTimestampInicio = microtime(TRUE);

        // Verificar que agente está presentes
        if (!isset($comando->agent_number))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_getAgentStatusResponse = $xml_response->addChild('getagentstatus_response');

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $xml_getAgentStatusResponse->addChild('status', 'offline');
            $this->_agregarRespuestaFallo($xml_getAgentStatusResponse, 404, 'Invalid agent number');
            return $xml_response;
        }

        // Obtener la información del estado del agente según el marcador
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $xml_getAgentStatusResponse->addChild('status', 'offline');
            $this->_agregarRespuestaFallo($xml_getAgentStatusResponse, 404, 'Invalid agent number');
            return $xml_response;
        }

        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);

        $recordset_breakinfo = NULL;
        cargarInfoPausa($this->_db, $infoSeguimiento, $recordset_breakinfo);
        $this->_agregarAgentStatusInfo($xml_getAgentStatusResponse,
            $infoSeguimiento, $infoLlamada);

        return $xml_response;
    }

    private function Request_eccpauth_getmultipleagentstatus($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que agente está presente
        if (!isset($comando->agents))
            return $this->_generarRespuestaFallo(400, 'Bad request');

        $xml_response = new SimpleXMLElement('<response />');
        $xml_getAgentStatusResponse = $xml_response->addChild('getmultipleagentstatus_response');

        $agentlist = array();
        foreach ($comando->agents->agent_number as $agent_number) {
            $sAgente = (string)$agent_number;

            // El siguiente código asume formato Agent/9000
            if (is_null($this->_parseAgent($sAgente))) {
                $this->_agregarRespuestaFallo($xml_getAgentStatusResponse, 417, 'Invalid agent number');
                return $xml_response;
            }

            $agentlist[] = $sAgente;
        }

        // Verificar que todos los agentes existen en el sistema
        $listaAgentes = $this->_listarAgentes();
        $agentesExtras = array_diff($agentlist, array_keys($listaAgentes));
        if (count($agentesExtras) > 0) {
            $this->_agregarRespuestaFallo($xml_getAgentStatusResponse, 404, 'Specified agent not found');
            return $xml_response;
        }

        $is = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($agentlist);
        foreach ($is as $sAgente => $infoSeguimiento) {
            if (is_null($infoSeguimiento)) {
                $xml_getAgentStatusResponse->addChild('status', 'offline');
                $this->_agregarRespuestaFallo($xml_getAgentStatusResponse, 404, 'Invalid agent number');
                return $xml_response;
            }
        }
        $il = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($agentlist);

        $recordset_breakinfo = NULL;

        // Conversión a XML
        $xml_agents = $xml_getAgentStatusResponse->addChild('agents');
        foreach ($agentlist as $sAgente) {
            $xml_agent = $xml_agents->addChild('agent');
            $xml_agent->addChild('agent_number', xmlSafe($sAgente));

            $infoSeguimiento = $is[$sAgente];
            $infoLlamada = $il[$sAgente];

            cargarInfoPausa($this->_db, $infoSeguimiento, $recordset_breakinfo);
            $this->_agregarAgentStatusInfo($xml_agent, $infoSeguimiento,
                $infoLlamada);
        }

        return $xml_response;
    }

    private function _agregarAgentStatusInfo($xml_agent, &$infoSeguimiento,
        &$infoLlamada)
    {
        // Attended-transfer consultation state (none/ringing/answered) so the
        // console can reconcile a missed Consultation* event on its next poll.
        $xml_agent->addChild('consultation', isset($infoSeguimiento['consultation'])
            ? $infoSeguimiento['consultation'] : 'none');
        // DIALSTATUS of the last consultation that failed, so the console can
        // still tell the agent why even if the ConsultationEnd event was lost.
        if (!empty($infoSeguimiento['consultation_reason'])) {
            $xml_agent->addChild('consultation_reason', $infoSeguimiento['consultation_reason']);
        }

        list($sAgentStatus, $sExtension) = self::getcampaignstatus_setagent(
            $xml_agent, $infoSeguimiento, FALSE, $infoLlamada);

        if (!is_null($sAgentStatus)) {
            if ($sAgentStatus != 'offline' && is_null($sExtension)) {
                $this->_log->output("ERR: (interno) estado inconsistente de agente (status=$sAgentStatus extension=null) | EN: ERR: (internal) inconsistent agent state (status=$sAgentStatus extension=null)\n".
                    "\tinfoSeguimiento => ".print_r($infoSeguimiento, TRUE).
                    "\tinfoLlamada => ".print_r($infoLlamada, TRUE));
            }
        } else {
            $xml_agent->addChild('status', 'offline');
        }

    }

    private static function _agregarCallInfo($xml_callInfo, &$infoLlamada)
    {
        foreach (array('calltype', 'callid', 'campaign_id', 'queuenumber', 'callnumber') as $k) {
            if (!is_null($infoLlamada[$k])) $xml_callInfo->addChild($k, $infoLlamada[$k]);
        }
        $xml_callInfo->addChild('callstatus', $infoLlamada['status']);
        if (isset($infoLlamada['trunk']))
            $xml_callInfo->addChild('trunk', $infoLlamada['trunk']);

        $date_prefix = date('Y-m-d ');
        foreach (array('dialstart', 'dialend', 'queuestart', 'linkstart') as $k) {
            if (isset($infoLlamada[$k])) {
                $xml_callInfo->addChild($k, str_replace($date_prefix, '', $infoLlamada[$k]));
            }
        }
    }

    private function Request_agentauth_mixmonitormute($comando)
    {
       if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que agente está presente
        if (!isset($comando->agent_number))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_mixmonitormuteResponse = $xml_response->addChild('mixmonitormute_response');

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_mixmonitormuteResponse, 404, 'Invalid agent number');
            return $xml_response;
        }

        // Timeout luego del cual quitar el silencio de la llamada, en segundos
        $timeout = NULL;
        if (isset($comando->timeout)) {
            $timeout = (int)$comando->timeout;
            if ($timeout <= 0) {
                $this->_agregarRespuestaFallo($xml_mixmonitormuteResponse, 417, 'Invalid timeout');
                return $xml_response;
            }
        }

        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);

        if (is_null($infoLlamada) || is_null($infoLlamada['agentchannel'])) {
            $this->_agregarRespuestaFallo($xml_mixmonitormuteResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        $r = $this->_ami->MixMonitorMute($infoLlamada['channel'], true);
        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: No se puede callar la grabacion para '.$sAgente.
                ' ('.$infoLlamada['channel'].') - '.$r['Message'].
                ' | EN: ERR: Cannot mute recording for '.$sAgente.
                ' ('.$infoLlamada['channel'].') - '.$r['Message']);
            $this->_agregarRespuestaFallo($xml_mixmonitormuteResponse, 500, 'Cannot mute agent call');
            return $xml_response;
        }
        $this->_tuberia->msg_AMIEventProcess_llamadaSilenciada($sAgente, $infoLlamada['channel'], $timeout);

        $xml_mixmonitormuteResponse->addChild('success');
        return $xml_response;
    }

    private function Request_agentauth_mixmonitorunmute($comando)
    {
       if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que agente está presente
        if (!isset($comando->agent_number))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_mixmonitorunmuteResponse = $xml_response->addChild('mixmonitorunmute_response');

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_mixmonitorunmuteResponse, 404, 'Invalid agent number');
            return $xml_response;
        }

        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);

        if (is_null($infoLlamada) || is_null($infoLlamada['agentchannel'])) {
            $this->_agregarRespuestaFallo($xml_mixmonitorunmuteResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        $c = 0;
        foreach ($infoLlamada['mutedchannels'] as $chan) {
            $r = $this->_ami->MixMonitorMute($chan, false);
            if ($r['Response'] != 'Success') {
                $this->_log->output('ERR: No se puede restaurar la grabacion para '.$sAgente.
                    ' ('.$chan.') - '.$r['Message'].
                    ' | EN: ERR: Cannot restore recording for '.$sAgente.
                    ' ('.$chan.') - '.$r['Message']);
            } else {
                $c++;
            }
        }
        if ($c <= 0) {
            $this->_agregarRespuestaFallo($xml_mixmonitorunmuteResponse, 500, 'Cannot unmute agent call');
            return $xml_response;
        }
        $this->_tuberia->msg_AMIEventProcess_llamadaSinSilencio($sAgente);

        $xml_mixmonitorunmuteResponse->addChild('success');
        return $xml_response;
    }

    private function Request_agentauth_hangup($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que agente está presentes
        $sAgente = (string)$comando->agent_number;
        $hangchannel = NULL;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_hangupResponse = $xml_response->addChild('hangup_response');

        // El siguiente código asume formato Agent/9000
        $agentFields = $this->_parseAgent($sAgente);
        if (is_null($agentFields)) {
            $this->_agregarRespuestaFallo($xml_hangupResponse, 417, 'Invalid agent number');
            return $xml_response;
        }

        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (!is_null($infoLlamada)) {
            $hangchannel = $infoLlamada['agentchannel'];
            // For app_agent_pool (Agent type), Agent/XXXX is not a real channel.
            // Use actualchannel (caller's channel) to end the call.
            // Agent stays in AgentLogin session.
            if ($agentFields['type'] == 'Agent' && preg_match('|^Agent/\d+$|', $hangchannel)) {
                // Check if attended transfer is in progress
                $isAttendedTransfer = false;
                if (!is_null($infoLlamada['callid'])) {
                    $dbTable = ($infoLlamada['calltype'] == 'incoming') ? 'call_entry' : 'calls';
                    $sth = $this->_db->prepare("SELECT transfer FROM {$dbTable} WHERE id = ?");
                    $sth->execute(array($infoLlamada['callid']));
                    $transferDest = $sth->fetchColumn(0);
                    $sth->closeCursor();
                    $isAttendedTransfer = !empty($transferDest);
                }

                if ($isAttendedTransfer && !is_null($this->_compat) && $this->_compat->hasAppAgentPool()) {
                    // Get agent's login_channel
                    $infoAgente = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
                    if (is_null($infoAgente) || empty($infoAgente['login_channel'])) {
                        $this->_log->output('WARN: No se encontró login_channel para el agente, usando hangup normal | EN: No login_channel found for agent, falling back to normal hangup');
                        $hangchannel = $infoLlamada['actualchannel'];
                    } else {
                        $loginChannel = $infoAgente['login_channel'];
                        $agentNumber = substr($sAgente, strpos($sAgente, '/') + 1);

                        // Check if agent is currently in active consultation (Dial in progress)
                        $isInConsultation = $this->_tuberia->AMIEventProcess_esAgenteEnConsultation($sAgente);

                        if ($isInConsultation) {
                            // Consultation is still active (Dial() to the colleague hasn't
                            // ended). Distinguish "still ringing / never engaged" (Hangup
                            // means cancel) from "colleague has answered" (Hangup now
                            // means complete, per the ConsultationAnswered UserEvent).
                            $consultaContestada = $this->_tuberia->AMIEventProcess_infoConsultaContestada($sAgente);

                            if (!is_null($consultaContestada) && !empty($consultaContestada['channel'])) {
                                // ================================================================
                                // COMPLETE TRANSFER (colleague answered, agent hangs up while
                                // talking to them): dual-Redirect, mirroring the technique that
                                // started the consultation - move the colleague's channel into
                                // atxfer-bridge (to bridge with the held customer) and the
                                // agent's login_channel into atxfer-complete (to re-enter
                                // AgentLogin), simultaneously. The customer is NOT hung up here -
                                // they end up bridged with the colleague instead.
                                // ================================================================
                                $this->_log->output('DEBUG: ========== COMPLETAR TRANSFERENCIA (consulta contestada) | EN: COMPLETE TRANSFER (consultation answered) ==========');
                                $this->_log->output('DEBUG: Agente/Agent: '.$sAgente.', Destino/Target: '.$transferDest.', login_channel: '.$loginChannel.', colleague_channel: '.$consultaContestada['channel']);

                                $r = $this->_ami->Redirect(
                                    $loginChannel,                  // Channel: agent's login_channel
                                    $consultaContestada['channel'], // ExtraChannel: the colleague's real channel
                                    $agentNumber,                   // Exten: agent number
                                    'atxfer-complete',              // Context: re-enter AgentLogin
                                    1,                              // Priority
                                    's',                            // ExtraExten
                                    'atxfer-bridge',                // ExtraContext: bridge colleague with held customer
                                    1                               // ExtraPriority
                                );
                                $this->_log->output('DEBUG: Resultado de Redirect/Redirect result: '.print_r($r, true));

                                if ($r['Response'] == 'Success') {
                                    $this->_log->output('INFO: Transferencia completada (consulta contestada) - agente redirigido a atxfer-complete, colega a atxfer-bridge | EN: Transfer completed (consultation answered) - agent redirected to atxfer-complete, colleague to atxfer-bridge');
                                    $this->_tuberia->msg_AMIEventProcess_finalizarTransferencia($sAgente);
                                    $xml_hangupResponse->addChild('success');
                                    return $xml_response;
                                } else {
                                    $this->_log->output('WARN: Redirect falló: '.$r['Message'].', usando hangup normal | EN: Redirect failed: '.$r['Message'].', falling back to normal hangup');
                                    $hangchannel = $infoLlamada['actualchannel'];
                                }
                            } else {
                            // ================================================================
                            // CANCEL CONSULTATION: Agent is actively consulting but the
                            // colleague has not answered yet. Redirect to atxfer-cancel-consult
                            // which terminates the consulting call and Bridge()s the agent
                            // back to the customer. Call tracking is preserved (no
                            // _finalizarTransferencia).
                            // ================================================================
                            $this->_log->output('DEBUG: ========== CANCELAR CONSULTA | EN: CANCEL CONSULTATION ==========');
                            $this->_log->output('DEBUG: Agente/Agent: '.$sAgente.', Destino/Target: '.$transferDest.', login_channel: '.$loginChannel);

                            $r = $this->_ami->Redirect(
                                $loginChannel,              // Channel: agent's login_channel
                                '',                         // ExtraChannel: not used
                                's',                        // Exten
                                'atxfer-cancel-consult',    // Context: cancel and reconnect
                                1                           // Priority
                            );
                            $this->_log->output('DEBUG: Resultado de Redirect/Redirect result: '.print_r($r, true));

                            if ($r['Response'] == 'Success') {
                                $this->_log->output('INFO: Consulta cancelada - agente será reconectado al cliente | EN: Consultation cancelled - agent will be reconnected to customer');
                                // Clear the transfer record (transfer was cancelled)
                                if (!is_null($infoLlamada['callid'])) {
                                    $dbTable = ($infoLlamada['calltype'] == 'incoming') ? 'call_entry' : 'calls';
                                    $sth = $this->_db->prepare("UPDATE {$dbTable} SET transfer = '' WHERE id = ?");
                                    $sth->execute(array($infoLlamada['callid']));
                                    $sth->closeCursor();
                                }
                                $xml_hangupResponse->addChild('success');
                                return $xml_response;
                            } else {
                                $this->_log->output('WARN: Redirect falló: '.$r['Message'].', usando hangup normal | EN: Redirect failed: '.$r['Message'].', falling back to normal hangup');
                                $hangchannel = $infoLlamada['actualchannel'];
                            }
                            }
                        } else {
                            // ================================================================
                            // COMPLETE TRANSFER: Consultation already ended (colleague
                            // answered and agent returned, or colleague hung up). The
                            // transfer record exists but agent is no longer in Dial().
                            // Redirect to atxfer-complete to finalize and re-enter AgentLogin.
                            // ================================================================
                            $this->_log->output('DEBUG: ========== COMPLETAR TRANSFERENCIA | EN: COMPLETE TRANSFER ==========');
                            $this->_log->output('DEBUG: Agente/Agent: '.$sAgente.', Destino/Target: '.$transferDest.', login_channel: '.$loginChannel);

                            // Hang up customer channel (in atxfer-hold with MusicOnHold)
                            if (!empty($infoLlamada['actualchannel'])) {
                                $this->_log->output('DEBUG: Hanging up customer channel '.$infoLlamada['actualchannel'].' | EN: Hanging up customer channel '.$infoLlamada['actualchannel']);
                                $this->_ami->Hangup($infoLlamada['actualchannel']);
                            }

                            // Suppress Agentlogoff and redirect to atxfer-complete
                            $this->_tuberia->AMIEventProcess_prepararAtxferComplete($sAgente);
                            $r = $this->_ami->Redirect(
                                $loginChannel,        // Channel: agent's login_channel
                                '',                   // ExtraChannel: not used
                                $agentNumber,         // Exten: agent number
                                'atxfer-complete',    // Context: re-enter AgentLogin
                                1                     // Priority
                            );
                            $this->_log->output('DEBUG: Resultado de Redirect/Redirect result: '.print_r($r, true));

                            if ($r['Response'] == 'Success') {
                                $this->_log->output('INFO: Transferencia completada - agente redirigido a atxfer-complete | EN: Transfer completed - agent redirected to atxfer-complete');
                                $this->_tuberia->msg_AMIEventProcess_finalizarTransferencia($sAgente);
                                $xml_hangupResponse->addChild('success');
                                return $xml_response;
                            } else {
                                $this->_log->output('WARN: Redirect falló: '.$r['Message'].', usando hangup normal | EN: Redirect failed: '.$r['Message'].', falling back to normal hangup');
                                $hangchannel = $infoLlamada['actualchannel'];
                            }
                        }
                    }
                } else {
                    // Normal call: Hang up caller's channel, agent stays in AgentLogin
                    $hangchannel = $infoLlamada['actualchannel'];
                }
            }
            // For callback agents (SIP/IAX2/PJSIP type), agentchannel may be just the device name
            // (e.g., SIP/101) without unique call ID. Check if it has a hyphen, if not use actualchannel.
            elseif ($agentFields['type'] != 'Agent') {
                // Check if attended transfer is in progress for callback agents
                $isAttendedTransfer = false;
                if (!is_null($infoLlamada['callid'])) {
                    $dbTable = ($infoLlamada['calltype'] == 'incoming') ? 'call_entry' : 'calls';
                    $sth = $this->_db->prepare("SELECT transfer FROM {$dbTable} WHERE id = ?");
                    $sth->execute(array($infoLlamada['callid']));
                    $transferDest = $sth->fetchColumn(0);
                    $sth->closeCursor();
                    $isAttendedTransfer = !empty($transferDest);
                }

                if ($isAttendedTransfer) {
                    // ========================================================================
                    // CALLBACK AGENT ATTENDED TRANSFER
                    // Mirrors the Agent-type split above: while the colleague is
                    // still ringing, Hangup means CANCEL (reconnect the agent to
                    // the customer); once the colleague has answered it means
                    // COMPLETE (bridge colleague and customer, release the agent).
                    // The old code made no such distinction and always hung up
                    // the agent's original channel, which dropped the customer
                    // whenever the transfer was cancelled while ringing.
                    // ========================================================================
                    $this->_log->output('DEBUG: ========== INICIO DE TRANSFERENCIA ATENDIDA AGENTE CALLBACK | EN: CALLBACK AGENT ATTENDED TRANSFER START ==========');
                    $this->_log->output('DEBUG: Agente/Agent: '.$sAgente.', Destino de transferencia/Transfer Target: '.$transferDest);

                    $agentChannel = (isset($infoLlamada['actualAgentChannel'])
                        && !empty($infoLlamada['actualAgentChannel']))
                        ? $infoLlamada['actualAgentChannel'] : $hangchannel;

                    $isInConsultation = $this->_tuberia->AMIEventProcess_esAgenteEnConsultation($sAgente);
                    if ($isInConsultation && strpos($agentChannel, '-') !== FALSE) {
                        $consultaContestada = $this->_tuberia->AMIEventProcess_infoConsultaContestada($sAgente);

                        if (!is_null($consultaContestada) && !empty($consultaContestada['channel'])) {
                            // ------------------------------------------------------------
                            // COMPLETE: the colleague answered. Move both channels at once -
                            // the colleague into atxfer-bridge, where it bridges with the
                            // held customer, and the agent into cbxfer-done, which hangs it
                            // up. The agent channel's Hangup event is what releases the call
                            // from tracking (_manejarHangupLoginChannelEnConsulta with
                            // answered=yes); deliberately no finalizarTransferencia here, so
                            // there is exactly one owner of that release.
                            // ------------------------------------------------------------
                            $this->_log->output('INFO: '.__METHOD__.": COMPLETAR TRANSFERENCIA callback $sAgente".
                                ' colega/colleague='.$consultaContestada['channel'].' agente/agent='.$agentChannel.
                                ' | EN: COMPLETE callback attended transfer');
                            $r = $this->_ami->Redirect(
                                $consultaContestada['channel'],  // Channel: colleague
                                $agentChannel,                   // ExtraChannel: agent
                                's', 'atxfer-bridge', 1,         // colleague -> bridge with held customer
                                's', 'cbxfer-done', 1            // agent -> hang up
                            );
                            if ($r['Response'] == 'Success') {
                                $xml_hangupResponse->addChild('success');
                                return $xml_response;
                            }
                            $this->_log->output('ERR: '.__METHOD__.": Redirect to complete callback transfer failed".
                                ' for '.$sAgente.' - '.$r['Message'].' - falling back to plain hangup');
                        } else {
                            // ------------------------------------------------------------
                            // CANCEL: the colleague is still ringing. Redirect ONLY the
                            // agent's channel to cbxfer-cancel-consult, which ends the
                            // Dial(), emits ConsultationEnd and bridges the agent back to
                            // the held customer. Nothing is hung up - hanging up here is
                            // exactly what used to disconnect the customer.
                            // ------------------------------------------------------------
                            $this->_log->output('INFO: '.__METHOD__.": CANCELAR CONSULTA callback $sAgente".
                                ' agente/agent='.$agentChannel.' | EN: CANCEL callback consultation');
                            $r = $this->_ami->Redirect(
                                $agentChannel, '', 's', 'cbxfer-cancel-consult', 1);
                            if ($r['Response'] == 'Success') {
                                $xml_hangupResponse->addChild('success');
                                return $xml_response;
                            }
                            $this->_log->output('ERR: '.__METHOD__.": Redirect to cancel callback consultation failed".
                                ' for '.$sAgente.' - '.$r['Message'].' - falling back to plain hangup');
                        }
                    }

                    /* No hay consulta activa: la columna `transfer` quedó
                     * marcada de un intento anterior. Con la limpieza que hace
                     * ahora ConsultationEnd esto no debería ocurrir; se
                     * conserva como red de seguridad y se cuelga al cliente,
                     * que es el comportamiento normal de un hangup. */
                    /* EN: No live consultation: the `transfer` column is left
                     * over from an earlier attempt. With the cleanup
                     * ConsultationEnd now performs this should not happen; kept
                     * as a safety net, hanging up the customer, which is what a
                     * plain hangup does. */
                    $this->_log->output('WARN: '.__METHOD__.": callback agent $sAgente has transfer=$transferDest".
                        ' but no live consultation - treating as a normal hangup'.
                        " | ES: el agente callback $sAgente tiene transfer marcado pero no hay consulta activa");
                    $hangchannel = $infoLlamada['actualchannel'];
                } elseif (strpos($hangchannel, '-') === false) {
                    // No attended transfer - normal hangup, use actualchannel
                    $hangchannel = $infoLlamada['actualchannel'];
                }
            }
        }
        if (is_null($hangchannel)) {
            // Verificar si la llamada manual está en proceso de marcado
            $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAgendada($sAgente);
            if (!is_null($infoLlamada) && in_array($infoLlamada['status'], array('Placing', 'Ringing'))) {
                /* Para agentes estáticos, el canal de agente se puede usar
                 * directamente para abortar. Los agentes dinámicos requieren
                 * el canal. */
                $hangchannel = ($agentFields['type'] == 'Agent') ? $infoLlamada['actualchannel'] : $infoLlamada['channel'];
            }
        }

        if (is_null($hangchannel)) {
            $this->_agregarRespuestaFallo($xml_hangupResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Mandar a colgar la llamada
        $r = $this->_ami->Hangup($hangchannel);
        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: No se puede colgar la llamada para '.$sAgente.
                ' ('.$hangchannel.') - '.$r['Message'].
                ' | EN: ERR: Cannot hang up call for '.$sAgente.
                ' ('.$hangchannel.') - '.$r['Message']);
            $this->_agregarRespuestaFallo($xml_hangupResponse, 500, 'Cannot hangup agent call');
            return $xml_response;
        }

        $xml_hangupResponse->addChild('success');
        return $xml_response;
    }

    private function Request_eccpauth_getcampaignstatus($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que id y tipo está presente
        if (!isset($comando->campaign_id))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $idCampania = (int)$comando->campaign_id;
        $sTipoCampania = 'outgoing';
        if (isset($comando->campaign_type)) {
            $sTipoCampania = (string)$comando->campaign_type;
        }

        // Si hay fecha de inicio, verificar que sea correcta
        $sFechaInicio = NULL;
        if (isset($comando->datetime_start)) {
            $sFechaInicio = (string)$comando->datetime_start;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaInicio))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid start date');
            $sFechaInicio .= ' 00:00:00';
        }

        // Leer resumen de llamadas completadas desde la base de datos
        switch ($sTipoCampania) {
        case 'outgoing':
            $statusCampania_DB = $this->_leerResumenCampaniaSaliente($idCampania, $sFechaInicio);
            break;
        case 'incoming':
            $statusCampania_DB = $this->_leerResumenCampaniaEntrante($idCampania, $sFechaInicio);
            break;
        default:
            return $this->_generarRespuestaFallo(400, 'Bad request');
        }

        $xml_response = new SimpleXMLElement('<response />');
        $xml_statusresponse = $xml_response->addChild('getcampaignstatus_response');
        if (count($statusCampania_DB) <= 0) {
            $this->_agregarRespuestaFallo($xml_statusresponse, 404, 'Campaign not found');
            return $xml_response;
        }

        // Leer información de las llamadas en curso para la campaña
        $statusCampania_AMI = $this->_tuberia->AMIEventProcess_reportarInfoLlamadasCampania($sTipoCampania, $idCampania);

        $this->_getcampaignstatus_format($xml_statusresponse, $statusCampania_DB, $statusCampania_AMI);
        return $xml_response;
    }

    private function Request_eccpauth_getincomingqueuestatus($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que id y tipo está presente
        if (!isset($comando->queue))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sCola = (string)$comando->queue;

        // Si hay fecha de inicio, verificar que sea correcta
        $sFechaInicio = NULL;
        if (isset($comando->datetime_start)) {
            $sFechaInicio = (string)$comando->datetime_start;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaInicio))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid start date');
            $sFechaInicio .= ' 00:00:00';
        }

        // Leer resumen de llamadas completadas sin campaña desde la base de datos
        $statusCampania_DB = $this->_leerResumenColaEntrante($sCola, $sFechaInicio);

        $xml_response = new SimpleXMLElement('<response />');
        $xml_statusresponse = $xml_response->addChild('getincomingqueuestatus_response');
        if (count($statusCampania_DB) <= 0) {
            $this->_agregarRespuestaFallo($xml_statusresponse, 404, 'Queue not found');
            return $xml_response;
        }

        // Leer información de las llamadas en curso para la campaña
        $statusCampania_AMI = $this->_tuberia->AMIEventProcess_reportarInfoLlamadasColaEntrante($sCola);

        $this->_getcampaignstatus_format($xml_statusresponse, $statusCampania_DB, $statusCampania_AMI);
        return $xml_response;
    }

    private function _getcampaignstatus_format($xml_statusresponse, &$statusCampania_DB, &$statusCampania_AMI)
    {
        // Cuentas de estados de llamadas realizadas
        $xml_statusCount = $xml_statusresponse->addChild('statuscount');
        $xml_statusCount->addChild('total', array_sum($statusCampania_DB['status']));
        foreach ($statusCampania_DB['status'] as $statusKey => $statusCount)
            $xml_statusCount->addChild(strtolower($statusKey), $statusCount);

        $recordset_breakinfo = NULL;

        // Estado de los agentes
        $xml_agents = $xml_statusresponse->addChild('agents');
        foreach ($statusCampania_AMI['queuestatus'] as $sAgente => $infoAgente) {
            // Este código asume agentes de formato Agent/9000
            $xml_agent = $xml_agents->addChild('agent');
            $xml_agent->addChild('agentchannel', $sAgente);

            cargarInfoPausa($this->_db, $infoAgente, $recordset_breakinfo);
            self::getcampaignstatus_setagent($xml_agent, $infoAgente);
        }

        // Estado de los agentes logoneados en la cola, sin llamada en atención
        $infoAgentes = $this->_tuberia->AMIEventProcess_infoSeguimientoAgentesCola(
            $statusCampania_DB['queue'], array_keys($statusCampania_AMI['queuestatus']));
        foreach ($infoAgentes as $sAgente => $infoAgente) {
            $xml_agent = $xml_agents->addChild('agent');
            $xml_agent->addChild('agentchannel', $sAgente);

            cargarInfoPausa($this->_db, $infoAgente, $recordset_breakinfo);
            self::getcampaignstatus_setagent($xml_agent, $infoAgente);
        }

        // Estado de las llamadas pendientes de enlazar
        $xml_activecalls = $xml_statusresponse->addChild('activecalls');
        foreach ($statusCampania_AMI['activecalls'] as $infoLlamada) {
            $xml_activecall = $xml_activecalls->addChild('activecall');
            self::_agregarCallInfo($xml_activecall, $infoLlamada);
        }

        // Contadores para estadísticas
        $xml_stats = $xml_statusresponse->addChild('stats');
        foreach ($statusCampania_DB['stat'] as $statKey => $statCount)
            $xml_stats->addChild(strtolower($statKey), $statCount);
    }

    static function getcampaignstatus_setagent($xml_agent, $infoAgente, $flattened = TRUE, $infoLlamada = NULL)
    {
        // Canal que hizo el logoneo hacia la cola
        $sExtension = NULL;
        $sCanalExt = $infoAgente['login_channel'];
        if (is_null($sCanalExt)) $sCanalExt = $infoAgente['extension'];
        if (!is_null($sCanalExt)) {
            // Hay un canal de login. Se separa la extensión que hizo el login
            $sRegexp = "|^\w+/(\\d+)-?|"; $regs = NULL;
            if (preg_match($sRegexp, $sCanalExt, $regs)) {
                $sExtension = $regs[1];
            }
        }

        // Reportar los estados conocidos
        $sAgentStatus = NULL;
        if ($infoAgente['oncall']) {
            $sAgentStatus = 'oncall';
        } elseif ($infoAgente['num_pausas'] > 0) {
            $sAgentStatus = 'paused';
        } elseif ($infoAgente['estado_consola'] == 'logged-in') {
            // Check if agent is ringing (queue status = 6 = AST_DEVICE_RINGING)
            if (isset($infoAgente['queue_status']) && $infoAgente['queue_status'] == 6) {
                $sAgentStatus = 'ringing';
            } else {
                $sAgentStatus = 'online';
            }
        } else {
            $sAgentStatus = 'offline';
        }
        if (!is_null($sAgentStatus)) {
            $xml_agent->addChild('status', $sAgentStatus);
            if (!is_null($sCanalExt)) $xml_agent->addChild('channel', xmlSafe($sCanalExt));
            if (!is_null($sExtension)) $xml_agent->addChild('extension', $sExtension);
        }

        // Reportar el canal remoto al cual está conectado el agente
        if (isset($infoAgente['clientchannel'])) {
            /* TODO: si clientchannel está definido, es idéntico a actualchannel de
             * Llamada::resumenLlamada() pero también está disponible en
             * Agente::resumenSeguimiento().
             */
            $xml_agent->addChild(($flattened ? 'callchannel' : 'remote_channel'),
                xmlSafe($infoAgente['clientchannel']));
        }

        // Reportar la información de la llamada que el agente está esperando, si aplica
        if (!is_null($infoAgente['waitedcallinfo'])) {
            $xml_wci = $xml_agent->addChild('waitedcallinfo');
            foreach ($infoAgente['waitedcallinfo'] as $k => $v)
                $xml_wci->addChild($k, $v);
        }

        // Reportar el estado de hold, si aplica
        if ($infoAgente['estado_consola'] == 'logged-in') {
            $xml_agent->addChild('onhold', is_null($infoAgente['id_hold']) ? 0 : 1);
            // Inicio del hold en curso, cargado por cargarInfoPausa() desde la
            // fila de audit. Sin esto la consola sabe que el agente está en
            // hold pero no desde cuándo, y el cronómetro no sobrevive a un F5.
            // EN: start of the running hold, loaded by cargarInfoPausa() from
            // the audit row. Without it the console knows the agent is on hold
            // but not since when, and the timer cannot survive a refresh.
            if (isset($infoAgente['holdstart']))
                $xml_agent->addChild('holdstart',
                    str_replace(date('Y-m-d '), '', $infoAgente['holdstart']));
        }

        // Reportar los estados de break, si aplica
        if (!is_null($infoAgente['id_break'])) {
            $xml_pauseInfo = $flattened ? $xml_agent : $xml_agent->addChild('pauseinfo');
            $xml_pauseInfo->addChild('pauseid', $infoAgente['id_break']);
            if (isset($infoAgente['pausename']))
                $xml_pauseInfo->addChild('pausename', xmlSafe($infoAgente['pausename']));
            if (isset($infoAgente['pausestart']))
                $xml_pauseInfo->addChild('pausestart', str_replace(date('Y-m-d '), '', $infoAgente['pausestart']));
        }

        if ($flattened) {
            // FIXME: compatibilidad requiere mezclar campos de callinfo y agent
            if (isset($infoAgente['callinfo'])) {
                self::_agregarCallInfo($xml_agent, $infoAgente['callinfo']);
                $infoLlamada = $infoAgente['callinfo'];
            }
        } else {
            if (!is_null($infoLlamada)) {
                $xml_callInfo = $xml_agent->addChild('callinfo');
                self::_agregarCallInfo($xml_callInfo, $infoLlamada);
            }
        }

        return array($sAgentStatus, $sExtension);
    }

    /**
     * Método que devuelve un resumen de la información de una campaña saliente
     * para ser mostrada en la interfaz de monitoreo.
     *
     * @param   int     $idCampania     ID de la campaña a interrogar
     * @param   string  $sFechaInicio   Si no es NULL, fecha inicial para llamadas
     *                                  de campaña a considerar.
     *
     * @return  mixed   NULL en error, o información de la campaña
     */
    private function _leerResumenCampaniaSaliente($idCampania, $sFechaInicio = NULL)
    {
        // Leer la información en el propio registro de la campaña
        $sPeticionSQL = <<<LEER_RESUMEN_CAMPANIA
SELECT id, name, datetime_init, datetime_end, daytime_init, daytime_end,
    retries, trunk, queue, estatus
FROM campaign WHERE id = ?
LEER_RESUMEN_CAMPANIA;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        if (!$tupla) return array();

        // Leer la clasificación por estado de las llamadas de la campaña
        $sPeticionSQL = <<<CLASIFICAR_LLAMADAS
SELECT COUNT(*) AS n, status FROM calls
WHERE id_campaign = ? AND ((? IS NULL) OR (datetime_originate >= ?))
GROUP BY status
CLASIFICAR_LLAMADAS;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania, $sFechaInicio, $sFechaInicio));
        $recordset->setFetchMode(PDO::FETCH_ASSOC);
        $tupla['status'] = array(
            'Pending'   =>  0,  // Llamada no ha sido realizada todavía

            'Placing'   =>  0,  // Originate realizado, no se recibe OriginateResponse
            'Ringing'   =>  0,  // Se recibió OriginateResponse, no entra a cola
            'OnQueue'   =>  0,  // Entró a cola, no se asigna a agente todavía
            'Success'   =>  0,  // Conectada y asignada a un agente
            'OnHold'    =>  0,  // Llamada fue puesta en espera por agente
            'Failure'   =>  0,  // No se puede conectar llamada
            'ShortCall' =>  0,  // Llamada conectada pero duración es muy corta
            'NoAnswer'  =>  0,  // Llamada estaba Ringing pero no entró a cola
            'Abandoned' =>  0,  // Llamada estaba OnQueue pero no habían agentes
        );
        foreach ($recordset as $tuplaStatus) {
            if (is_null($tuplaStatus['status']))
                $tupla['status']['Pending'] = $tuplaStatus['n'];
            else $tupla['status'][$tuplaStatus['status']] = $tuplaStatus['n'];
        }

        // Leer estadísticas de la campaña
        $sPeticionSQL = <<<LEER_STATS_CAMPANIA
SELECT SUM(duration) AS total_sec, MAX(duration) AS max_duration FROM calls
WHERE id_campaign = ? AND status = 'Success' AND ((? IS NULL) OR (start_time >= ?)) AND end_time IS NOT NULL
LEER_STATS_CAMPANIA;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania, $sFechaInicio, $sFechaInicio));
        $recordset->setFetchMode(PDO::FETCH_ASSOC);
        $tupla['stat'] = array();
        foreach ($recordset as $tuplaStat) {
        	foreach ($tuplaStat as $k => $v) $tupla['stat'][$k] = is_null($v) ? 0 : (int)$v;
        }

        return $tupla;
    }

    private function _leerResumenCampaniaEntrante($idCampania, $sFechaInicio = NULL)
    {
        // Leer la información en el propio registro de la campaña
        $sPeticionSQL = <<<LEER_RESUMEN_CAMPANIA
SELECT ce.id, ce.name, ce.datetime_init, ce.datetime_end, ce.daytime_init,
    ce.daytime_end, qce.queue, ce.estatus
FROM campaign_entry ce, queue_call_entry qce
WHERE ce.id = ? AND ce.id_queue_call_entry = qce.id
LEER_RESUMEN_CAMPANIA;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        if (!$tupla) return array();

        // Leer la clasificación por estado de las llamadas de la campaña
        $sPeticionSQL = <<<CLASIFICAR_LLAMADAS
SELECT COUNT(*) AS n, status FROM call_entry
WHERE id_campaign = ? AND ((? IS NULL) OR (datetime_entry_queue >= ?))
GROUP BY status
CLASIFICAR_LLAMADAS;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania, $sFechaInicio, $sFechaInicio));
        $recordset->setFetchMode(PDO::FETCH_ASSOC);
        $tupla['status'] = array(
            //'Pending'   =>  0,  // Llamada no ha sido realizada todavía

            //'Placing'   =>  0,  // Originate realizado, no se recibe OriginateResponse
            //'Ringing'   =>  0,  // Se recibió OriginateResponse, no entra a cola
            'OnQueue'   =>  0,  // Entró a cola, no se asigna a agente todavía
            'Success'   =>  0,  // Conectada y asignada a un agente
            'OnHold'    =>  0,  // Llamada fue puesta en espera por agente
            //'Failure'   =>  0,  // No se puede conectar llamada
            //'ShortCall' =>  0,  // Llamada conectada pero duración es muy corta
            //'NoAnswer'  =>  0,  // Llamada estaba Ringing pero no entró a cola
            'Abandoned' =>  0,  // Llamada estaba OnQueue pero no habían agentes
            'Finished'  =>  0,  // Llamada ha terminado luego de ser conectada a agente
            'LostTrack' =>  0,  // Programa fue terminado mientras la llamada estaba activa
        );
        $mapaEstados = array(
            'en-cola'       =>  'OnQueue',
            'activa'        =>  'Success',
            'hold'          =>  'OnHold',
            'abandonada'    =>  'Abandoned',
            'terminada'     =>  'Finished',
            'fin-monitoreo' =>  'LostTrack',
        );
        foreach ($recordset as $tuplaStatus) {
            $tupla['status'][$mapaEstados[$tuplaStatus['status']]] = $tuplaStatus['n'];
        }

        // Leer estadísticas de la campaña
        $sPeticionSQL = <<<LEER_STATS_CAMPANIA
SELECT SUM(duration) AS total_sec, MAX(duration) AS max_duration FROM call_entry
WHERE id_campaign = ? AND status = 'terminada'
    AND ((? IS NULL) OR (datetime_init >= ?)) AND datetime_end IS NOT NULL
LEER_STATS_CAMPANIA;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($idCampania, $sFechaInicio, $sFechaInicio));
        $recordset->setFetchMode(PDO::FETCH_ASSOC);
        $tupla['stat'] = array();
        foreach ($recordset as $tuplaStat) {
            foreach ($tuplaStat as $k => $v) $tupla['stat'][$k] = is_null($v) ? 0 : (int)$v;
        }

        return $tupla;
    }

    private function _leerResumenColaEntrante($sCola, $sFechaInicio = NULL)
    {
        $tupla['queue'] = $sCola;

        // Leer la clasificación por estado de las llamadas de la campaña
        $sPeticionSQL =
            'SELECT COUNT(*) AS n, status FROM call_entry, queue_call_entry '.
            'WHERE call_entry.id_campaign IS NULL '.
                'AND call_entry.id_queue_call_entry = queue_call_entry.id '.
                'AND queue_call_entry.queue = ? '.
                'AND ((? IS NULL) OR (call_entry.datetime_entry_queue >= ?)) '.
            'GROUP BY status';
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($sCola, $sFechaInicio, $sFechaInicio));
        $recordset->setFetchMode(PDO::FETCH_ASSOC);
        $tupla['status'] = array(
            //'Pending'   =>  0,  // Llamada no ha sido realizada todavía

            //'Placing'   =>  0,  // Originate realizado, no se recibe OriginateResponse
            //'Ringing'   =>  0,  // Se recibió OriginateResponse, no entra a cola
            'OnQueue'   =>  0,  // Entró a cola, no se asigna a agente todavía
            'Success'   =>  0,  // Conectada y asignada a un agente
            'OnHold'    =>  0,  // Llamada fue puesta en espera por agente
            //'Failure'   =>  0,  // No se puede conectar llamada
            //'ShortCall' =>  0,  // Llamada conectada pero duración es muy corta
            //'NoAnswer'  =>  0,  // Llamada estaba Ringing pero no entró a cola
            'Abandoned' =>  0,  // Llamada estaba OnQueue pero no habían agentes
            'Finished'  =>  0,  // Llamada ha terminado luego de ser conectada a agente
            'LostTrack' =>  0,  // Programa fue terminado mientras la llamada estaba activa
        );
        $mapaEstados = array(
            'en-cola'       =>  'OnQueue',
            'activa'        =>  'Success',
            'hold'          =>  'OnHold',
            'abandonada'    =>  'Abandoned',
            'terminada'     =>  'Finished',
            'fin-monitoreo' =>  'LostTrack',
        );
        foreach ($recordset as $tuplaStatus) {
            $tupla['status'][$mapaEstados[$tuplaStatus['status']]] = $tuplaStatus['n'];
        }

        // Leer estadísticas de la campaña
        $sPeticionSQL = <<<LEER_STATS_CAMPANIA
SELECT SUM(duration) AS total_sec, MAX(duration) AS max_duration
FROM call_entry, queue_call_entry
WHERE id_campaign IS NULL AND id_queue_call_entry = queue_call_entry.id
    AND queue_call_entry.queue = ? AND status = 'terminada'
    AND datetime_end IS NOT NULL
    AND ((? IS NULL) OR (datetime_init >= ?))
LEER_STATS_CAMPANIA;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($sCola, $sFechaInicio, $sFechaInicio));
        $recordset->setFetchMode(PDO::FETCH_ASSOC);
        $tupla['stat'] = array();
        foreach ($recordset as $tuplaStat) {
            foreach ($tuplaStat as $k => $v) $tupla['stat'][$k] = is_null($v) ? 0 : (int)$v;
        }

        return $tupla;
    }

    private function Request_agentauth_schedulecall($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que agente está presente
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_scheduleResponse = $xml_response->addChild('schedulecall_response');

        // Verificar si se especifica un callid explícito
        $sTipoCampania = NULL;
        $idLlamada = NULL;
        if (isset($comando->campaign_type) && isset($comando->call_id)) {
            $sTipoCampania = (string)$comando->campaign_type;
            if (!in_array($sTipoCampania, array('outgoing')))
                return $this->_generarRespuestaFallo(400, 'Bad request');
            $idLlamada = (int)$comando->call_id;
        }

        // Verificar si el agente está siendo monitoreado
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_scheduleResponse, 404, 'Agent not found or not logged in through ECCP');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_scheduleResponse, 417, 'Agent currently not logged in');
            return $xml_response;
        }

        $bMismoAgente = FALSE;
        $horario = NULL;
        $sNuevoTelefono = NULL;
        $sNuevoNombre = NULL;

        // Verificar si se debe usar el mismo agente (requiere contexto especial)
        if (isset($comando->sameagent) && (int)$comando->sameagent != 0)
            $bMismoAgente = TRUE;

        // Verificar si se debe usar un nuevo teléfono
        if (isset($comando->newphone)) $sNuevoTelefono = (string)$comando->newphone;

        // Verificar si se debe usar un nuevo nombre de contacto
        if (isset($comando->newcontactname)) $sNuevoNombre = (string)$comando->newcontactname;

        // Verificar que se tiene un horario establecido
        if (isset($comando->schedule)) {
            if (isset($comando->schedule->date_init) && isset($comando->schedule->date_end) &&
                isset($comando->schedule->time_init) && isset($comando->schedule->time_end)) {
                $horario = array(
                    'date_init' =>  (string)$comando->schedule->date_init,
                    'date_end'  =>  (string)$comando->schedule->date_end,
                    'time_init' =>  (string)$comando->schedule->time_init,
                    'time_end'  =>  (string)$comando->schedule->time_end,
                );
            } else {
                $this->_agregarRespuestaFallo($xml_scheduleResponse, 400, 'Bad request: incomplete schedule');
                return $xml_response;
            }
        }

        if ($bMismoAgente && is_null($horario)) {
            $this->_agregarRespuestaFallo($xml_scheduleResponse, 400, 'Bad request: same-agent requires schedule');
            return $xml_response;
        }

        // Ejecutar el agendamiento de la llamada
        $errcode = $errdesc = NULL;
        $bExito = $this->_agendarLlamadaAgente($sTipoCampania, $idLlamada, $sAgente, $horario,
            $bMismoAgente, $sNuevoTelefono, $sNuevoNombre, $errcode, $errdesc);
        if (!$bExito) {
            $this->_agregarRespuestaFallo($xml_scheduleResponse, $errcode, $errdesc);
        } else {
            $xml_scheduleResponse->addChild('success');
        }

        return $xml_response;
    }

    /**
     * Procedimiento que crea una nueva llamada agendada en base a la llamada
     * que está atendiendo el agente indicado por el parámetro.
     *
     * @param   string  $sAgente        Agente en formato Agent/9000
     * @param   mixed   $horario        Arreglo que define el horario como sigue:
     *          date_init               Fecha en inicio de horario en formato YYYY-MM-DD
     *          date_end                Fecha de fin de horario en formato YYYY-MM-DD
     *          time_init               Hora de inicio de horario en formato HH:MM:SS
     *          time_end                Hora de fin de horario en formato HH:MM:SS
     *                                  NULL para agendar llamada al final de campaña
     *                                  a cualquier fecha y hora
     * @param   bool    $bMismoAgente   FALSO si se asigna llamada a cualquier agente
     *                                  VERDADERO para que el mismo agente deba atenderla
     *                                  Si VERDADERO, se requiere $horario.
     * @param   mixed   $sNuevoTelefono Teléfono nuevo al cual marcar llamada, o NULL para mismo anterior
     * @param   mixed   $sNuevoNombre   Nombre del nuevo contacto para llamada, o NULL para mismo anterior
     *
     * @return bool VERDADERO en caso de éxito, FALSO en caso de error
     */
    private function _agendarLlamadaAgente($calltype, $callid, $sAgente, $horario, $bMismoAgente,
        $sNuevoTelefono, $sNuevoNombre, &$errcode, &$errdesc)
    {
        $errcode = 0; $errdesc = 'Success';

        // Revisar teléfono nuevo, si existe
        if (!is_null($sNuevoTelefono) && !preg_match('/^\d+$/', $sNuevoTelefono)) {
            $errcode = 400; $errdesc = 'Bad request: invalid new phone';
            return FALSE;
        }

        // Revisar horarios
        if (is_array($horario)) {
            // Formatos correctos de fecha
            if (!isset($horario['date_init']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $horario['date_init'])) {
                $this->_log->output('ERR: al agendar llamada: fecha de inicio inválida, se espera YYYY-MM-DD | EN: when scheduling call: invalid start date, expected YYYY-MM-DD');
                $errcode = 400; $errdesc = 'Bad request: invalid date_init';
                return FALSE;
            } elseif (!isset($horario['date_end']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $horario['date_end'])) {
                $this->_log->output('ERR: al agendar llamada: fecha de fin inválida, se espera YYYY-MM-DD | EN: when scheduling call: invalid end date, expected YYYY-MM-DD');
                $errcode = 400; $errdesc = 'Bad request: invalid date_end';
                return FALSE;
            } elseif (!isset($horario['time_init']) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $horario['time_init'])) {
                $this->_log->output('ERR: al agendar llamada: hora de inicio inválida, se espera HH:MM:SS | EN: when scheduling call: invalid start time, expected HH:MM:SS');
                $errcode = 400; $errdesc = 'Bad request: invalid time_init';
                return FALSE;
            } elseif (!isset($horario['time_end']) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $horario['time_end'])) {
                $this->_log->output('ERR: al agendar llamada: hora de fin inválida, se espera HH:MM:SS | EN: when scheduling call: invalid end time, expected HH:MM:SS');
                $errcode = 400; $errdesc = 'Bad request: invalid time_end';
                return FALSE;
            }

            // Ordenamiento correcto
            if ($horario['date_init'] > $horario['date_end']) {
                $t = $horario['date_init'];
                $horario['date_init'] = $horario['date_end'];
                $horario['date_end'] = $t;
            }

            // Fecha debe estar en el futuro
            if ($horario['date_init'] < date('Y-m-d')) {
                $this->_log->output('ERR: al agendar llamada: fecha de inicio anterior a fecha actual | EN: ERR: when scheduling call: start date before current date');
                $errcode = 400; $errdesc = 'Bad request: date_init before current date';
                return FALSE;
            }
        } elseif (!is_null($horario)) {
            $this->_log->output('ERR: (internal) al agendar llamada: horario no es un arreglo | EN: ERR: (internal) when scheduling call: schedule is not an array');
            return FALSE;
        }

        // Información de la llamada atendida por el agente
        if (!is_null($calltype) && !is_null($callid)) {
            // Verificar si la llamada existe y el agente está autorizado
            switch ($calltype) {
            case 'outgoing':
                $sql = 'SELECT COUNT(*) AS N FROM calls WHERE id = ?';
                $params = array($callid);
                break;
            }
            $recordset = $this->_db->prepare($sql);
            $recordset->execute($params);
            $tuplaCheck = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();
            if ($tuplaCheck['N'] <= 0) {
                $this->_log->output('WARN: '.__METHOD__.': llamada '.$calltype.' con callid='.$callid.
                    ' no se encuentra para agent='.$sAgente.', se ignoran valores...'.
                    ' | EN: WARN: '.__METHOD__.': call '.$calltype.' with callid='.$callid.
                    ' not found for agent='.$sAgente.', ignoring values...');
                $calltype = NULL;
                $callid = NULL;
            }
        }
        if (is_null($calltype) || is_null($callid)) {
            $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
            if (is_null($infoLlamada)) {
                $errcode = 417; $errdesc = 'Not in outgoing call';
                return FALSE;
            }
            $calltype = $infoLlamada['calltype'];
            $callid = $infoLlamada['callid'];
        }

        switch ($calltype) {
        case 'outgoing':
            return $this->_agendarLlamadaAgente_outgoing($callid, $sAgente, $horario, $bMismoAgente,
                $sNuevoTelefono, $sNuevoNombre, $errcode, $errdesc);
        default:
            $errcode = 417; $errdesc = 'Not in outgoing call';
            return FALSE;
        }
    }

    private function _agendarLlamadaAgente_outgoing($callid, $sAgente, $horario, $bMismoAgente,
        $sNuevoTelefono, $sNuevoNombre, &$errcode, &$errdesc)
    {
        // Leer toda la información de la campaña y la cola
        $sqlLlamadaCampania = <<<SQL_LLAMADA_CAMPANIA_AGENDAMIENTO
SELECT campaign.datetime_init, campaign.datetime_end, campaign.daytime_init,
    campaign.daytime_end, calls.id_campaign, calls.phone
FROM campaign, calls
WHERE campaign.id = calls.id_campaign AND calls.id = ?
SQL_LLAMADA_CAMPANIA_AGENDAMIENTO;
        $recordset = $this->_db->prepare($sqlLlamadaCampania);
        $recordset->execute(array($callid));
        $tuplaCampania = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();

        // Validar que el rango de fecha y hora requerido es compatible con campaña
        if (is_array($horario)) {
            if (!($tuplaCampania['datetime_init'] <= $horario['date_init'] &&
                $horario['date_end'] <= $tuplaCampania['datetime_end'])) {
                $errcode = 417; $errdesc = 'Supplied date range outside campaign range';
                return FALSE;
            }
            if (!($tuplaCampania['daytime_init'] <= $horario['time_init'] &&
                $horario['time_end'] <= $tuplaCampania['daytime_end'])) {
                $errcode = 417; $errdesc = 'Supplied time range outside campaign range';
                return FALSE;
            }
        }

        // Acumular los parámetros de la nueva llamada por insertar
        // DEBEN PERMANECER EN ESTE ORDEN
        $paramNuevaLlamadaSQL = array(
            $tuplaCampania['id_campaign'],  // TODO: se puede mandar llamada a otra campaña...
            is_null($sNuevoTelefono) ? $tuplaCampania['phone'] : $sNuevoTelefono,
            is_null($horario) ? NULL : $horario['date_init'],
            is_null($horario) ? NULL : $horario['date_end'],
            is_null($horario) ? NULL : $horario['time_init'],
            is_null($horario) ? NULL : $horario['time_end'],
        );

        // Leer los atributos a heredar de la llamada, para (opcionalmente) modificarlos
        $sqlLlamadaAtributos = <<<SQL_LLAMADA_ATRIBUTOS_AGENDAMIENTO
SELECT column_number, columna, value FROM call_attribute
WHERE id_call = ?
ORDER BY column_number
SQL_LLAMADA_ATRIBUTOS_AGENDAMIENTO;
        $recordset = $this->_db->prepare($sqlLlamadaAtributos);
        $recordset->execute(array($callid));
        $attrLlamada = array();
        foreach ($recordset as $tupla) {
        	$attrLlamada[$tupla['column_number']] = array($tupla['columna'], $tupla['value']);
        }
        if (!is_null($sNuevoNombre)) {
            // Columnas de propiedades se numeran desde 1
            if (!isset($attrLlamada[1])) $attrLlamada[1] = array('Campo1', $sNuevoNombre);
            $attrLlamada[1][1] = $sNuevoNombre;
        }

        // Leer los datos de los formularios para la llamada
        $sqlLlamadaForm = <<<SQL_LLAMADA_FORM_STATIC
SELECT id_form_field, value FROM form_data_recolected
WHERE id_calls = ?
SQL_LLAMADA_FORM_STATIC;
        $recordset = $this->_db->prepare($sqlLlamadaForm);
        $recordset->execute(array($callid));
        $formLlamada = array();
        foreach ($recordset as $tupla) {
            $formLlamada[$tupla['id_form_field']] = $tupla['value'];
        }

        // Validar que no exista una llamada por agendar al mismo número
        $sqlExistenciaLlamadaPrevia = <<<SQL_LLAMADA_PREVIA
SELECT COUNT(*) FROM calls
WHERE id_campaign = ? AND phone = ? AND date_init = ? AND date_end = ?
    AND time_init = ? AND time_end = ?
SQL_LLAMADA_PREVIA;
        $recordset = $this->_db->prepare($sqlExistenciaLlamadaPrevia);
        $recordset->execute($paramNuevaLlamadaSQL);
        $existe = $recordset->fetchColumn(0);
        $recordset->closeCursor();
        if ($existe > 0) {
            $errcode = 417; $errdesc = 'Found duplicate scheduled call';
            return FALSE;
        }

        try {
            // Inicio de transacción
            $this->_db->beginTransaction();

            // Agregar agente a agendar, si es necesario, e insertar
            $paramNuevaLlamadaSQL[] = $bMismoAgente ? $sAgente : NULL;
            $sqlInsertarLlamadaAgendada = <<<SQL_INSERTAR_AGENDAMIENTO
INSERT INTO calls (scheduled, id_campaign, phone, date_init, date_end, time_init, time_end, agent)
VALUES (1, ?, ?, ?, ?, ?, ?, ?)
SQL_INSERTAR_AGENDAMIENTO;
            $sth = $this->_db->prepare($sqlInsertarLlamadaAgendada);
            $sth->execute($paramNuevaLlamadaSQL);
            $idNuevaLlamada = $this->_db->lastInsertId();

            // Insertar atributos para la nueva llamada
            $sth = $this->_db->prepare(
                'INSERT INTO call_attribute (columna, value, column_number, id_call) '.
                'VALUES (?, ?, ?, ?)');
            foreach ($attrLlamada as $iColNum => $tuplaAttr) {
                // Se asume elemento 0 es 'columna', 1 es 'value' en call_attribute
                $tuplaAttr[] = $iColNum;        // Debería ser posición 2
                $tuplaAttr[] = $idNuevaLlamada; // Debería ser posición 3
                $sth->execute($tuplaAttr);
            }

            // Insertar valores de formularios
            $sth = $this->_db->prepare(
                'INSERT INTO form_data_recolected (value, id_form_field, id_calls) '.
                'VALUES (?, ?, ?)');
            foreach ($formLlamada as $id_ff => $value) {
                $sth->execute(array($value, $id_ff, $idNuevaLlamada));
            }

            // Final de transacción
            $this->_db->commit();
            return TRUE;
        } catch (PDOException $e) {
            $this->_log->output('ERR: '.__METHOD__.
                ': no se puede realizar inserción de llamada agendada: '.
                implode(' - ', $e->errorInfo).
                ' | EN: ERR: '.__METHOD__.
                ': cannot perform scheduled call insertion: '.
                implode(' - ', $e->errorInfo));
            $errcode = 500; $errdesc = 'Failed to insert scheduled call';
        	$this->_db->rollBack();
            return FALSE;
        }
    }

    private function Request_agentauth_transfercall($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        $sAgente = (string)$comando->agent_number;

        // Verificar que número de extensión está presente
        if (!isset($comando->extension))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sExtension = (string)$comando->extension;
        if (!ctype_digit($sExtension))
            return $this->_generarRespuestaFallo(400, 'Bad request');

        $xml_response = new SimpleXMLElement('<response />');
        $xml_transferResponse = $xml_response->addChild('transfercall_response');

        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Specified agent not found');
            return $xml_response;
        }

        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Specified agent not found');
            return $xml_response;
        }
        $sCanalRemoto = $infoSeguimiento['clientchannel'];
        if (is_null($sCanalRemoto)) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Obtener la información de la llamada atendida por el agente
        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (is_null($infoLlamada) || is_null($infoLlamada['callid'])) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Mandar a transferir la llamada usando el canal Agent/9000
        $r = $this->_ami->Redirect(
            $sCanalRemoto,      // channel
            '',                 // extrachannel
            $sExtension,        // exten
            'from-internal',    // context
            1);                 // priority
        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: '.__METHOD__.': al transferir llamada: no se puede transferir '.
                $sCanalRemoto.' a '.$sExtension.' - '.$r['Message'].
                ' | EN: ERR: '.__METHOD__.': when transferring call: cannot transfer '.
                $sCanalRemoto.' to '.$sExtension.' - '.$r['Message']);
            $this->_agregarRespuestaFallo($xml_transferResponse, 500, 'Unable to transfer call');
            return $xml_response;
        } else {
            $this->_registrarTransferencia($infoLlamada, $sExtension);
            // Notify AMIEventProcess to release the agent after blind transfer
            $this->_tuberia->msg_AMIEventProcess_finalizarTransferencia($sAgente);
        }

        $xml_transferResponse->addChild('success');
        return $xml_response;
    }

    private function Request_agentauth_atxfercall($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        $sAgente = (string)$comando->agent_number;

        // Verificar que número de extensión está presente
        if (!isset($comando->extension))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sExtension = (string)$comando->extension;
        if (!ctype_digit($sExtension))
            return $this->_generarRespuestaFallo(400, 'Bad request');

        $xml_response = new SimpleXMLElement('<response />');
        $xml_transferResponse = $xml_response->addChild('atxfercall_response');

        // Obtener la información de la llamada atendida por el agente
        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (is_null($infoLlamada)) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Specified agent not found');
            return $xml_response;
        }
        if (is_null($infoLlamada['agentchannel'])) {
            $this->_agregarRespuestaFallo($xml_transferResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Get agent info to determine agent type and login_channel
        $infoAgente = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);

        // DEBUG: log agent info
        $this->_log->output('DEBUG: '.__METHOD__.': infoAgente/agentInfo = '.print_r($infoAgente, true));

        // For Agent type (app_agent_pool), use Redirect with ExtraChannel instead of Atxfer.
        // Atxfer uses DTMF emulation (*2 + extension + #) which requires bridge DTMF hooks.
        // After Local channel optimization, the agent's SIP phone swaps into the queue bridge
        // but loses the DTMF hooks that were on the original Local channel bridge_channel.
        // Redirect bypasses this issue by explicitly moving both channels to new dialplan contexts.
        if (!is_null($infoAgente) && !empty($infoAgente['login_channel'])) {
            $transferChannel = $infoAgente['login_channel'];
            $this->_log->output('DEBUG: '.__METHOD__.': Using login_channel: '.$transferChannel);

            // Get the external caller's channel (the party to be put on hold)
            $clientChannel = $infoLlamada['actualchannel'];
            if (empty($clientChannel)) {
                $this->_log->output('ERR: '.__METHOD__.': No actualchannel found for the call');
                $this->_agregarRespuestaFallo($xml_transferResponse, 500, 'No caller channel found');
                return $xml_response;
            }
            $this->_log->output('DEBUG: '.__METHOD__.': Client channel (held party): '.$clientChannel);

            // Extract agent number (e.g., "1001" from "Agent/1001")
            $agentNumber = substr($sAgente, strpos($sAgente, '/') + 1);

            // Set channel variables for the dialplan before Redirect
            $this->_ami->SetVar($transferChannel, 'ATXFER_HELD_CHAN', $clientChannel);
            $this->_ami->SetVar($transferChannel, 'ATXFER_AGENT_NUM', $agentNumber);

            // Suppress the Agentlogoff event that fires when SIP phone leaves the bridge.
            // Uses synchronous RPC to ensure the flag is set before Redirect fires.
            $this->_tuberia->AMIEventProcess_prepararAtxferComplete($sAgente);

            // Mark agent as in consultation so ConsultationEnd can be detected.
            // The request timestamp lets AMIEventProcess discard this mark if a
            // ConsultationEnd for the same consultation got there first.
            $this->_tuberia->msg_AMIEventProcess_marcarConsultationIniciada($sAgente, microtime(TRUE));

            // Redirect both channels simultaneously:
            // - Agent's SIP phone -> atxfer-consult (dials the target)
            // - External caller   -> atxfer-hold (music on hold)
            $r = $this->_ami->Redirect(
                $transferChannel,              // Channel: agent's SIP/PJSIP phone
                $clientChannel,                // ExtraChannel: external caller
                $sExtension,                   // Exten: target extension number
                'atxfer-consult',      // Context: agent consultation context
                1,                             // Priority
                's',                           // ExtraExten: hold context uses 's'
                'atxfer-hold',         // ExtraContext: caller MOH context
                1                              // ExtraPriority
            );
        } elseif (strpos($sAgente, 'Agent/') !== 0
                && !is_null($this->_compat) && $this->_compat->hasAppAgentPool()) {
            /* Agente tipo callback (SIP/IAX2/PJSIP) en Asterisk 12+. Se usa el
             * mismo flujo basado en Redirect que el tipo Agent, en sus propios
             * contextos [cbxfer-*], en lugar del Atxfer nativo: éste no
             * distingue "sonando" de "contestada", no expone ${DIALSTATUS} y
             * no ofrece una cancelación utilizable, que es justo de donde
             * venían los fallos de esta ruta. */
            /* EN: Callback-type agent (SIP/IAX2/PJSIP) on Asterisk 12+. Uses
             * the same Redirect-based flow as the Agent type, in its own
             * [cbxfer-*] contexts, instead of native Atxfer: that gives no
             * ringing/answered distinction, no ${DIALSTATUS} and no usable
             * cancel, which is exactly where this path's defects came from. */
            $transferChannel = isset($infoLlamada['actualAgentChannel'])
                ? $infoLlamada['actualAgentChannel']
                : $infoLlamada['agentchannel'];
            $this->_log->output('DEBUG: '.__METHOD__.': callback agent, using channel: '.$transferChannel);

            if (empty($transferChannel) || strpos($transferChannel, '-') === FALSE) {
                // Bare device name (e.g. "SIP/1002") - Asterisk cannot Redirect that.
                $this->_log->output('ERR: '.__METHOD__.': callback agent '.$sAgente.
                    ' has no usable channel ('.var_export($transferChannel, TRUE).')');
                $this->_agregarRespuestaFallo($xml_transferResponse, 500, 'No agent channel found');
                return $xml_response;
            }

            $clientChannel = $infoLlamada['actualchannel'];
            if (empty($clientChannel)) {
                $this->_log->output('ERR: '.__METHOD__.': No actualchannel found for the call');
                $this->_agregarRespuestaFallo($xml_transferResponse, 500, 'No caller channel found');
                return $xml_response;
            }

            /* Comprobar disponibilidad ANTES de mover ningún canal: si el
             * colega está ocupado sin llamada en espera, o en No Molestar, se
             * rechaza aquí y no se llega a marcar nada ni a tocar el estado de
             * consulta. [cbxfer-consult] marca el dispositivo directamente
             * (para evitar el tono de ocupado de 20 s de from-internal), así
             * que sin esta comprobación la consulta se forzaría igualmente. */
            /* EN: Check availability BEFORE moving any channel: if the
             * colleague is busy with no call waiting, or on Do Not Disturb,
             * refuse here - nothing is dialled and no consultation state is
             * touched. [cbxfer-consult] dials the device directly (to avoid
             * from-internal's 20-second busy tone), so without this check the
             * consultation would be forced through regardless. */
            if (!$this->_verificarColegaDisponible($sExtension, $xml_transferResponse))
                return $xml_response;

            $this->_log->output('DEBUG: '.__METHOD__.': Client channel (held party): '.$clientChannel);

            // Channel variables read by [cbxfer-consult] / [cbxfer-cancel-consult].
            // ATXFER_AGENT_ID is the full agent id (e.g. SIP/1002) because the
            // dialer keys its consultation state on that, not on a bare number.
            $this->_ami->SetVar($transferChannel, 'ATXFER_HELD_CHAN', $clientChannel);
            $this->_ami->SetVar($transferChannel, 'ATXFER_AGENT_ID', $sAgente);

            // Mark agent as in consultation so ConsultationEnd can be detected.
            // The request timestamp lets AMIEventProcess discard this mark if a
            // ConsultationEnd for the same consultation got there first.
            $this->_tuberia->msg_AMIEventProcess_marcarConsultationIniciada($sAgente, microtime(TRUE));

            // Redirect both channels simultaneously:
            // - Agent's device channel -> cbxfer-consult (dials the colleague)
            // - External caller        -> atxfer-hold (music on hold)
            $r = $this->_ami->Redirect(
                $transferChannel,       // Channel: agent's SIP/PJSIP/IAX2 channel
                $clientChannel,         // ExtraChannel: external caller
                $sExtension,            // Exten: target extension number
                'cbxfer-consult',       // Context: callback consultation context
                1,                      // Priority
                's',                    // ExtraExten: hold context uses 's'
                'atxfer-hold',          // ExtraContext: caller MOH context
                1                       // ExtraPriority
            );
        } else {
            // For Asterisk 11/13, or an Agent type that somehow has no
            // login_channel, use Atxfer which works when DTMF hooks are available
            $transferChannel = isset($infoLlamada['actualAgentChannel'])
                ? $infoLlamada['actualAgentChannel']
                : $infoLlamada['agentchannel'];
            $this->_log->output('DEBUG: '.__METHOD__.': Using fallback channel (Atxfer): '.$transferChannel);
            $this->_log->output('DEBUG: '.__METHOD__.': infoLlamada = '.print_r($infoLlamada, true));

            // Set TRANSFER_CONTEXT to use custom context that dials device directly
            // This avoids the 20-second busy tone delay when target declines
            $this->_ami->SetVar($transferChannel, 'TRANSFER_CONTEXT', 'cbext-atxfer');

            // Mark agent as in consultation so msg_Link can detect return
            $this->_tuberia->msg_AMIEventProcess_marcarConsultationIniciada($sAgente, microtime(TRUE));

            $this->_log->output('DEBUG: '.__METHOD__.': Sending Atxfer to ext='.$sExtension.' context=cbext-atxfer channel='.$transferChannel);
            $r = $this->_ami->Atxfer(
                $transferChannel,
                $sExtension.'#',    // exten
                'cbext-atxfer',     // context - use custom context to avoid busy tone delay
                1);                 // priority
            $this->_log->output('DEBUG: '.__METHOD__.': Atxfer result = '.print_r($r, true));
        }

        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: '.__METHOD__.': Cannot transfer '.
                $transferChannel.' to '.$sExtension.' - '.$r['Message']);
            $this->_agregarRespuestaFallo($xml_transferResponse, 500, 'Unable to transfer call');
            return $xml_response;
        } else {
            $this->_registrarTransferencia($infoLlamada, $sExtension);
        }

        $xml_transferResponse->addChild('success');
        // Flag attended transfer so front-end disables buttons during consultation
        $xml_transferResponse->addChild('consultation', 'true');
        return $xml_response;
    }

    /**
     * Transfer agent's current call to another logged-in agent.
     * Transfiere la llamada actual del agente a otro agente conectado.
     */
    private function Request_agentauth_transfercallagent($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        $sAgente = (string)$comando->agent_number;

        // Verificar que número de agente destino está presente
        // Verify that target agent number is present
        if (!isset($comando->target_agent_number))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sTargetAgent = (string)$comando->target_agent_number;

        $this->_log->output('INFO: '.__METHOD__.": Transferencia de agente solicitada | EN: INFO: ".__METHOD__.": Agent transfer requested - Source: $sAgente, Target: $sTargetAgent");

        $xml_response = new SimpleXMLElement('<response />');
        $xml_transferResponse = $xml_response->addChild('transfercallagent_response');

        // Validate source agent format
        // El siguiente código asume formato Agent/9000
        if (is_null($this->_parseAgent($sAgente))) {
            $this->_log->output('ERR: '.__METHOD__.": Agente origen no válido | EN: ERR: ".__METHOD__.": Invalid source agent - $sAgente");
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Specified agent not found');
            return $xml_response;
        }

        // Validate target agent format
        if (is_null($this->_parseAgent($sTargetAgent))) {
            $this->_log->output('ERR: '.__METHOD__.": Agente destino no válido | EN: ERR: ".__METHOD__.": Invalid target agent - $sTargetAgent");
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Target agent not found');
            return $xml_response;
        }

        // Check source agent is being monitored and has a call
        // Verificar si el agente origen está siendo monitoreado
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_log->output('ERR: '.__METHOD__.": Agente origen no encontrado | EN: ERR: ".__METHOD__.": Source agent not found - $sAgente");
            $this->_agregarRespuestaFallo($xml_transferResponse, 404, 'Specified agent not found');
            return $xml_response;
        }

        $sCanalRemoto = $infoSeguimiento['clientchannel'];
        if (is_null($sCanalRemoto)) {
            $this->_log->output('ERR: '.__METHOD__.": Agente origen no está en llamada | EN: ERR: ".__METHOD__.": Source agent not in call - $sAgente");
            $this->_agregarRespuestaFallo($xml_transferResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Get source agent's call info
        // Obtener la información de la llamada atendida por el agente origen
        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (is_null($infoLlamada) || is_null($infoLlamada['callid'])) {
            $this->_log->output('ERR: '.__METHOD__.": Agente origen no tiene llamada activa | EN: ERR: ".__METHOD__.": Source agent has no active call - $sAgente");
            $this->_agregarRespuestaFallo($xml_transferResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // === STEP 1: Atomic validation + reservation via AMIEventProcess RPC ===
        // This single RPC call atomically: validates all dialer-side state + acquires transfer lock
        // Esta única llamada RPC atómicamente: valida todo el estado del dialer + adquiere bloqueo de transferencia
        $this->_log->output('INFO: '.__METHOD__.": Requesting transfer reservation: source=$sAgente, target=$sTargetAgent | ES: Solicitando reserva de transferencia: origen=$sAgente, destino=$sTargetAgent");

        $reserveResult = $this->_tuberia->AMIEventProcess_reservarAgenteParaTransferencia($sAgente, $sTargetAgent);

        if (is_null($reserveResult) || !$reserveResult['success']) {
            $sErrorCode = is_null($reserveResult) ? 500 : $reserveResult['error_code'];
            $sErrorMsg = is_null($reserveResult) ? 'Internal communication error | Error interno de comunicación' : $reserveResult['error_msg'];
            $sStatus = is_null($reserveResult) ? 'error' : $reserveResult['status'];
            $this->_log->output('ERR: '.__METHOD__.": Transfer reservation DENIED: status=$sStatus, target=$sTargetAgent | ES: Reserva de transferencia DENEGADA: estado=$sStatus, destino=$sTargetAgent");
            $this->_agregarRespuestaFallo($xml_transferResponse, $sErrorCode, $sErrorMsg);
            return $xml_response;
        }

        $this->_log->output('INFO: '.__METHOD__.": Transfer reservation granted, checking Asterisk device state | ES: Reserva de transferencia concedida, verificando estado de dispositivo Asterisk");

        // === STEP 2: Belt-and-suspenders - Direct Asterisk device state check ===
        // This queries Asterisk directly via AMI ExtensionState for real-time device state
        // Esto consulta Asterisk directamente vía AMI ExtensionState para estado de dispositivo en tiempo real
        $bDeviceStateOk = $this->_checkExtensionState($sTargetAgent, $xml_transferResponse);
        if (!$bDeviceStateOk) {
            // Release the reservation since we're aborting the transfer
            // Liberar la reserva ya que abortamos la transferencia
            $this->_log->output('WARN: '.__METHOD__.": ExtensionState check FAILED, releasing reservation for $sTargetAgent | ES: Verificación ExtensionState FALLÓ, liberando reserva para $sTargetAgent");
            $this->_tuberia->msg_AMIEventProcess_liberarReservaTransferencia($sTargetAgent);
            return $xml_response;
        }

        $this->_log->output('INFO: '.__METHOD__.": All checks passed for transfer to $sTargetAgent | ES: Todas las verificaciones pasaron para transferencia a $sTargetAgent");

        // Get target agent info for extension extraction (validated as existing by reservation RPC)
        // Obtener info del agente destino para extracción de extensión (ya validado como existente por RPC de reserva)
        $infoTargetAgent = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sTargetAgent);

        // Get target agent's extension for the transfer
        // Obtener extensión del agente destino para la transferencia
        $sTargetExtension = NULL;
        $sAgentNumber = NULL;  // Agent number for AgentRequest (e.g., 1002 for Agent/1002)
        $sCanalExt = $infoTargetAgent['login_channel'];
        if (is_null($sCanalExt)) $sCanalExt = $infoTargetAgent['extension'];
        if (!is_null($sCanalExt)) {
            // Extract extension from channel (e.g., SIP/1001-xxx -> 1001)
            $sRegexp = "|^\w+/(\\d+)|";
            $regs = NULL;
            if (preg_match($sRegexp, $sCanalExt, $regs)) {
                $sTargetExtension = $regs[1];
            }
        }

        // For Agent type agents, extract agent number from agent string (Agent/1002 -> 1002)
        // AgentRequest() needs the agent number, not the extension
        if (strpos($sTargetAgent, 'Agent/') === 0) {
            $sRegexp = "|^Agent/(\\d+)|";
            $regs = NULL;
            if (preg_match($sRegexp, $sTargetAgent, $regs)) {
                $sAgentNumber = $regs[1];
            }
        }

        if (is_null($sTargetExtension)) {
            $this->_log->output('ERR: '.__METHOD__.": No se puede determinar extensión del agente destino | EN: ERR: ".__METHOD__.": Cannot determine target agent extension - $sTargetAgent");
            $this->_agregarRespuestaFallo($xml_transferResponse, 500, 'Cannot determine target agent extension');
            $this->_tuberia->msg_AMIEventProcess_liberarReservaTransferencia($sTargetAgent);
            return $xml_response;
        }

        // Determine channel to use for target agent based on agent type
        // Para agentes tipo Agent, usar AgentRequest con número de agente; para otros, usar extensión directa
        $sTargetChannel = $sTargetExtension;
        if (strpos($sTargetAgent, 'Agent/') === 0) {
            // Agent type - use agents context with AgentRequest
            // AgentRequest() needs agent NUMBER (e.g., 1002), not extension (e.g., 102)
            if (is_null($sAgentNumber)) {
                $this->_log->output('ERR: '.__METHOD__.": No se puede determinar número de agente | EN: ERR: ".__METHOD__.": Cannot determine agent number - $sTargetAgent");
                $this->_agregarRespuestaFallo($xml_transferResponse, 500, 'Cannot determine agent number');
                $this->_tuberia->msg_AMIEventProcess_liberarReservaTransferencia($sTargetAgent);
                return $xml_response;
            }
            $sTargetContext = 'agents';
            $sRedirectTarget = $sAgentNumber;  // Use agent number for AgentRequest
            $this->_log->output('INFO: '.__METHOD__.": Transfiriendo a agente tipo Agent via contexto [agents] con AgentRequest($sAgentNumber) | EN: INFO: ".__METHOD__.": Transferring to Agent type via [agents] context using AgentRequest($sAgentNumber)");
        } else {
            // SIP/PJSIP/IAX2 type - use direct context with extension
            $sTargetContext = 'from-internal';
            $sRedirectTarget = $sTargetExtension;
            $this->_log->output('INFO: '.__METHOD__.": Transfiriendo a agente tipo callback | EN: INFO: ".__METHOD__.": Transferring to callback type agent - Target: $sTargetExtension");
        }

        // Perform the transfer using AMI Redirect
        // Realizar la transferencia usando AMI Redirect
        $this->_log->output('INFO: '.__METHOD__.": Iniciando transferencia: $sCanalRemoto -> $sRedirectTarget@$sTargetContext | EN: INFO: ".__METHOD__.": Initiating transfer: $sCanalRemoto -> $sRedirectTarget@$sTargetContext");

        $r = $this->_ami->Redirect(
            $sCanalRemoto,      // channel (caller to transfer)
            '',                 // extrachannel
            $sRedirectTarget,   // exten (agent number for Agent type, extension for callback types)
            $sTargetContext,    // context (agents for Agent type, from-internal for others)
            1);                 // priority

        if ($r['Response'] != 'Success') {
            $this->_log->output('ERR: '.__METHOD__.': Falló la transferencia de agente: no se puede transferir '.
                $sCanalRemoto.' a '.$sRedirectTarget.' - '.$r['Message'].
                ' | EN: ERR: '.__METHOD__.': Agent transfer failed: cannot transfer '.
                $sCanalRemoto.' to '.$sRedirectTarget.' - '.$r['Message']);
            // Release the transfer reservation on Redirect failure
            // Liberar la reserva de transferencia por falla en Redirect
            $this->_log->output('WARN: '.__METHOD__.": Redirect failed, releasing transfer reservation for $sTargetAgent | ES: Redirect falló, liberando reserva de transferencia para $sTargetAgent");
            $this->_tuberia->msg_AMIEventProcess_liberarReservaTransferencia($sTargetAgent);
            $this->_agregarRespuestaFallo($xml_transferResponse, 500, 'Unable to transfer call to agent');
            return $xml_response;
        } else {
            // Register the transfer in database with agent number for Agent type, extension for callback types
            // Registrar transferencia en base de datos con número de agente para tipo Agent, extensión para otros
            $this->_registrarTransferencia($infoLlamada, $sRedirectTarget);

            // === TIMESTAMP TRACKER: Agent Transfer Release Timing ===
            $fTransferMicrotime = microtime(TRUE);
            $fTransferTime = date('Y-m-d H:i:s.', (int)$fTransferMicrotime) . sprintf('%03d', ($fTransferMicrotime - (int)$fTransferMicrotime) * 1000);
            $this->_log->output("TIMING: ".__METHOD__.": [TRANSFER_INIT] Source=$sAgente, Target=$sTargetAgent, microtime=$fTransferMicrotime, time=$fTransferTime | ES: Transferencia iniciada");
            // Notify AMIEventProcess to release the source agent after transfer
            $this->_tuberia->msg_AMIEventProcess_finalizarTransferencia($sAgente);
            $this->_log->output('INFO: '.__METHOD__.": Transferencia de agente completada con éxito | EN: INFO: ".__METHOD__.": Agent transfer completed successfully - Source: $sAgente, Target: $sTargetAgent");
        }

        $xml_transferResponse->addChild('success');
        return $xml_response;
    }

    private function _registrarTransferencia($infoLlamada, $sExtension)
    {
    	$sth = $this->_db->prepare(
            'UPDATE '.(($infoLlamada['calltype'] == 'incoming') ? 'call_entry' : 'calls').
            ' SET transfer = ? WHERE id = ?');
        $sth->execute(array($sExtension, $infoLlamada['callid']));
    }

    /**
     * Check Asterisk device state for target agent via AMI ExtensionState command.
     * Returns TRUE if the device is available, FALSE if busy (and populates error response).
     * Fails open (returns TRUE) if AMI query fails — non-fatal, proceed with transfer.
     *
     * Verifica el estado del dispositivo Asterisk del agente destino vía AMI ExtensionState.
     * Devuelve TRUE si disponible, FALSE si ocupado (y genera respuesta de error).
     * Falla abierto (devuelve TRUE) si la consulta AMI falla — no fatal, proceder con transferencia.
     */
    private function _checkExtensionState($sTargetAgent, $xml_transferResponse)
    {
        // Determine extension and context based on agent type
        // Determinar extensión y contexto según tipo de agente
        if (strpos($sTargetAgent, 'Agent/') === 0) {
            // Agent type: check agent number in 'agents' context
            $regs = NULL;
            if (preg_match('|^Agent/(\d+)|', $sTargetAgent, $regs)) {
                $sExten = $regs[1];
                $sContext = 'agents';
            } else {
                $this->_log->output('WARN: '.__METHOD__.": Cannot parse Agent number from $sTargetAgent, skipping ExtensionState check | ES: No se puede parsear número de Agent de $sTargetAgent, omitiendo verificación ExtensionState");
                return TRUE; // Cannot parse, fail-open
            }
        } else {
            // Callback type (SIP/PJSIP/IAX2): extract extension number, check in from-internal
            $regs = NULL;
            if (preg_match('|^\w+/(\d+)|', $sTargetAgent, $regs)) {
                $sExten = $regs[1];
                $sContext = 'from-internal';
            } else {
                $this->_log->output('WARN: '.__METHOD__.": Cannot parse extension from $sTargetAgent, skipping ExtensionState check | ES: No se puede parsear extensión de $sTargetAgent, omitiendo verificación ExtensionState");
                return TRUE; // Cannot parse, fail-open
            }
        }

        $this->_log->output('INFO: '.__METHOD__.": Querying ExtensionState for $sExten@$sContext (agent=$sTargetAgent) | ES: Consultando ExtensionState para $sExten@$sContext (agente=$sTargetAgent)");

        $r = $this->_ami->ExtensionState($sExten, $sContext);
        if ($r['Response'] != 'Success') {
            $sMsg = isset($r['Message']) ? $r['Message'] : 'unknown';
            $this->_log->output('WARN: '.__METHOD__.": ExtensionState query failed for $sExten@$sContext: $sMsg — proceeding with transfer (fail-open) | ES: Consulta ExtensionState falló para $sExten@$sContext: $sMsg — procediendo con transferencia (falla abierta)");
            return TRUE; // Fail-open: don't block transfer if AMI query fails
        }

        $iStatus = (int)$r['Status'];
        $this->_log->output('INFO: '.__METHOD__.": ExtensionState result for $sExten@$sContext: Status=$iStatus | ES: Resultado ExtensionState para $sExten@$sContext: Status=$iStatus");

        // ExtensionState uses bitmask values (different from AST_DEVICE_* queue constants):
        // 0=Idle, 1=InUse, 2=Busy, 4=Unavailable, 8=Ringing, 16=OnHold, -1=Not found
        // These are bitmask flags, so Status=9 means InUse+Ringing
        $BUSY_MASK = 1 | 2 | 8 | 16; // InUse | Busy | Ringing | OnHold
        if ($iStatus > 0 && ($iStatus & $BUSY_MASK)) {
            $aFlags = array();
            if ($iStatus & 1)  $aFlags[] = 'InUse';
            if ($iStatus & 2)  $aFlags[] = 'Busy';
            if ($iStatus & 8)  $aFlags[] = 'Ringing';
            if ($iStatus & 16) $aFlags[] = 'OnHold';
            $sFlagStr = implode('+', $aFlags);

            $this->_log->output('ERR: '.__METHOD__.": Asterisk device state check FAILED for $sExten@$sContext: Status=$iStatus ($sFlagStr) | ES: Verificación de estado de dispositivo FALLÓ para $sExten@$sContext: Status=$iStatus ($sFlagStr)");
            $this->_agregarRespuestaFallo($xml_transferResponse, 417,
                "Target agent device is busy | Dispositivo del agente destino ocupado ");
            return FALSE;
        }

        $this->_log->output('INFO: '.__METHOD__.": ExtensionState check PASSED for $sExten@$sContext: Status=$iStatus (Idle) | ES: Verificación ExtensionState PASÓ para $sExten@$sContext: Status=$iStatus (Disponible)");
        return TRUE;
    }

    /**
     * Check whether a colleague can take an attended-transfer consultation
     * right now, for the callback path, which dials the device directly and
     * therefore bypasses the from-internal/ext-local dialplan where FreePBX
     * would normally enforce Do Not Disturb and Call Waiting. Without this,
     * a consultation is forced onto a colleague who is already on a call
     * regardless of their Call Waiting setting.
     *
     * Refuses on Do Not Disturb, and on a busy device whose owner has Call
     * Waiting disabled. A busy device WITH Call Waiting enabled is allowed
     * through - that is exactly what Call Waiting means. Fails open on any
     * AMI error: an unavailable check must never block a transfer.
     *
     * Verifica si un colega puede atender ahora una consulta de transferencia
     * atendida, para la ruta callback, que marca el dispositivo directamente
     * y por tanto se salta el plan de marcado from-internal/ext-local donde
     * IssabelPBX aplicaría No Molestar y Llamada en Espera.
     *
     * @param string           $sExtension           colleague's extension
     * @param SimpleXMLElement $xml_transferResponse response to fill on refusal
     * @return bool TRUE if the consultation may proceed
     */
    private function _verificarColegaDisponible($sExtension, $xml_transferResponse)
    {
        // Do Not Disturb - FreePBX stores DND/<exten> only while it is on
        $sDND = $this->_ami->database_get('DND', $sExtension);
        if ($sDND !== FALSE && trim($sDND) != '') {
            $this->_log->output('INFO: '.__METHOD__.": colleague $sExtension is on DND (".trim($sDND).
                ") - refusing consultation | ES: el colega $sExtension está en No Molestar, se rechaza la consulta");
            $this->_agregarRespuestaFallo($xml_transferResponse, 417,
                'Colleague has Do Not Disturb enabled | El colega tiene No Molestar activado');
            return FALSE;
        }

        $r = $this->_ami->ExtensionState($sExtension, 'from-internal');
        if (!is_array($r) || !isset($r['Response']) || $r['Response'] != 'Success') {
            $sMsg = (is_array($r) && isset($r['Message'])) ? $r['Message'] : 'unknown';
            $this->_log->output('WARN: '.__METHOD__.": ExtensionState query failed for $sExtension: $sMsg".
                ' - proceeding with consultation (fail-open) | ES: consulta ExtensionState falló, se continúa');
            return TRUE;
        }

        // Bitmask: 0=Idle, 1=InUse, 2=Busy, 4=Unavailable, 8=Ringing, 16=OnHold, -1=Not found.
        // Unavailable is deliberately NOT treated as busy: an unregistered device
        // should produce a normal CHANUNAVAIL consultation the agent gets told about.
        $iStatus = (int)$r['Status'];
        $BUSY_MASK = 1 | 2 | 8 | 16;
        if ($iStatus <= 0 || !($iStatus & $BUSY_MASK)) {
            $this->_log->output('DEBUG: '.__METHOD__.": colleague $sExtension available (Status=$iStatus)");
            return TRUE;
        }

        // Busy - but Call Waiting turns "busy" into a legitimate second call.
        $sCW = $this->_ami->database_get('CW', $sExtension);
        if ($sCW !== FALSE && trim($sCW) != '') {
            $this->_log->output('INFO: '.__METHOD__.": colleague $sExtension is busy (Status=$iStatus)".
                ' but has Call Waiting enabled - proceeding'.
                " | ES: el colega $sExtension está ocupado pero tiene Llamada en Espera, se continúa");
            return TRUE;
        }

        $aFlags = array();
        if ($iStatus & 1)  $aFlags[] = 'InUse';
        if ($iStatus & 2)  $aFlags[] = 'Busy';
        if ($iStatus & 8)  $aFlags[] = 'Ringing';
        if ($iStatus & 16) $aFlags[] = 'OnHold';
        $this->_log->output('INFO: '.__METHOD__.": colleague $sExtension is busy (Status=$iStatus ".
            implode('+', $aFlags).') with Call Waiting disabled - refusing consultation'.
            " | ES: el colega $sExtension está ocupado sin Llamada en Espera, se rechaza la consulta");
        $this->_agregarRespuestaFallo($xml_transferResponse, 417,
            'Colleague is busy | El colega está ocupado');
        return FALSE;
    }

    private function Request_agentauth_hold($comando)
    {
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_holdResponse = $xml_response->addChild('hold_response');

        // Obtener el ID del break que corresponde al hold
        $recordset = $this->_db->prepare('SELECT id FROM break WHERE tipo = "H" AND status = "A"');
        $recordset->execute();
        $idHold = $recordset->fetchColumn(0);
        $recordset->closeCursor();

        // Verificar si el agente está siendo monitoreado
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_holdResponse, 404, 'Agent not found or not logged in through ECCP');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_holdResponse, 417, 'Agent currently not logged in');
            return $xml_response;
        }
        $sCanalRemoto = $infoSeguimiento['clientchannel'];
        if (is_null($sCanalRemoto)) {
            $this->_agregarRespuestaFallo($xml_holdResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Obtener la información de la llamada atendida por el agente
        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if (is_null($infoLlamada) || is_null($infoLlamada['callid'])) {
            $this->_agregarRespuestaFallo($xml_holdResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        if (!is_null($infoSeguimiento['id_audit_hold'])) {
            // Agente ya estaba en hold
            $this->_agregarRespuestaFallo($xml_holdResponse, 417, 'Agent already in hold');
            return $xml_response;
        }

        // Se escribe el inicio provisional de la pausa en la base de datos
        $iTimestampInicioPausa = time();
        $idAuditHold = $this->_marcarInicioBreakAgente(
            $infoSeguimiento['id_agent'], $idHold, $iTimestampInicioPausa);
        if (is_null($idAuditHold)) {
            $this->_agregarRespuestaFallo($xml_holdResponse, 500, 'Unable to start agent hold');
            return $xml_response;
        }

        // Se comunica a AMIEventProcess la pausa elegida para que la inicie.
        // Esto puede fallar si el estado del agente ha cambiado.
        list($errcode, $errdesc) = $this->_tuberia->AMIEventProcess_iniciarHoldAgente(
            $sAgente, $idHold, $idAuditHold, $iTimestampInicioPausa);
        if ($errcode != 0) {
            // Ha fallado el inicio de pausa, se deshace auditoría
            try {
                $sth = $this->_db->prepare('DELETE FROM audit WHERE id = ?');
                $sth->execute(array($idAuditHold));
                $sth = NULL;
            } catch (PDOException $e) {
                $this->_stdManejoExcepcionDB($e, 'no se puede quitar auditoría provisional!');
            }
            $this->_agregarRespuestaFallo($xml_holdResponse, $errcode, $errdesc);
            return $xml_response;
        }

        $xml_holdResponse->addChild('success');
        return array(
            'response'  =>  $xml_response,
            'eventos'   =>  array(
                array('PauseStart', array($sAgente, array(
                    'pause_class'   =>  'hold',
                    'pause_start'   =>  date('Y-m-d H:i:s', $iTimestampInicioPausa),
                ))),
            ),
        );

    }

    private function Request_agentauth_unhold($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_unholdResponse = $xml_response->addChild('unhold_response');

        // Verificar si el agente está siendo monitoreado
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_unholdResponse, 404, 'Specified agent not found');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_unholdResponse, 417, 'Agent currently not logged in');
            return $xml_response;
        }
        $sCanalRemoto = $infoSeguimiento['clientchannel'];
        if (is_null($sCanalRemoto)) {
            $this->_agregarRespuestaFallo($xml_unholdResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Obtener la información de la llamada atendida por el agente
        $infoLlamada = $this->_tuberia->AMIEventProcess_reportarInfoLlamadaAtendida($sAgente);
        if ($this->DEBUG) {
            $this->_log->output("DEBUG: unhold - infoLlamada/callInfo: ".print_r($infoLlamada, 1));
        }
        if (is_null($infoLlamada) || is_null($infoLlamada['callid'])) {
            $this->_agregarRespuestaFallo($xml_unholdResponse, 417, 'Agent not in call');
            return $xml_response;
        }

        // Si el agente no estaba en hold, se devuelve éxito sin hacer nada más
        if (is_null($infoSeguimiento['id_audit_hold'])) {
            if ($this->DEBUG) {
                $this->_log->output("DEBUG: unhold - agente no en hold, id_audit_hold es NULL | EN: agent not on hold, id_audit_hold is NULL");
            }
            $xml_unholdResponse->addChild('success');
            return $xml_response;
        }

        // Check if call is on hold and park_exten is available
        if ($this->DEBUG) {
            $this->_log->output("DEBUG: unhold - verificando/checking park_exten, isset: ".isset($infoLlamada['park_exten']));
        }
        if (isset($infoLlamada['park_exten']) && !is_null($infoLlamada['park_exten'])) {

            // Check if agent is in atxfer hold-wait state (Agent type only)
            $isAtxferHoldWait = FALSE;
            if (preg_match('|^Agent/(\d+)$|', $sAgente, $regs)) {
                $isAtxferHoldWait = $this->_tuberia->AMIEventProcess_esAgenteEnAtxferComplete($sAgente);
            }

            if ($isAtxferHoldWait && !empty($infoSeguimiento['login_channel'])) {
                // Agent is in Wait() in atxfer-consult holdwait - use Redirect + Bridge
                $agentChannel = $infoSeguimiento['login_channel'];
                $this->_log->output("DEBUG_HOLD: Using Redirect for atxfer hold recovery - "
                    . "agent=$sAgente channel=$agentChannel parked_chan={$infoLlamada['actualchannel']}");

                // Set channel variables for atxfer-unhold context
                $this->_ami->SetVar($agentChannel, 'ATXFER_PARKED_CHAN', $infoLlamada['actualchannel']);

                // Redirect agent from Wait() to atxfer-unhold
                // Redirect params: Channel, ExtraChannel, Exten, Context, Priority
                $r = $this->_ami->Redirect($agentChannel, NULL, 's', 'atxfer-unhold', '1');
                if ($r['Response'] != 'Success') {
                    $this->_log->output('ERR: Redirect for atxfer unhold failed: '.$r['Message'].
                        ' | EN: ERR: Redirect for atxfer unhold failed: '.$r['Message']);
                }
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: Redirect for atxfer unhold returns: '.print_r($r, 1));
                }
            } else {
                // Normal hold recovery - use Originate via AgentRequest
                $sActionID = 'ECCP:1.0:'.posix_getpid().':RedirectFromHold';

                // For Agent type agents, convert Agent/XXXX to Local/XXXX@agents
                // because Agent/XXXX is not a valid channel for Originate
                $sCanalOrigen = $sAgente;
                if (preg_match('|^Agent/(\d+)$|', $sAgente, $regs)) {
                    $sCanalOrigen = 'Local/'.$regs[1].'@agents';
                }

                if ($this->DEBUG) {
                    $this->_log->output("DEBUG: intentando recuperar llamada | EN: attempting to retrieve call:\n".
                        "\tChannel      =>  $sCanalOrigen\n".
                        "\tExten        =>  {$infoLlamada['park_exten']}\n".
                        "\tContext      =>  from-internal\n".
                        "\tActionID     =>  $sActionID");
                }

                // Sacar la llamada del parqueo y redirigirla al agente pausado
                // Set CallerID to show original caller info when retrieving from hold
                $sCallerID = NULL;
                if (isset($infoLlamada['callnumber']) && !empty($infoLlamada['callnumber'])) {
                    $sCallerID = '"'.$infoLlamada['callnumber'].'" <'.$infoLlamada['callnumber'].'>';
                }

                $r = $this->_ami->Originate(
                    $sCanalOrigen,               // channel
                    $infoLlamada['park_exten'],  // extension
                    'from-internal',        // context
                    '1',                    // priority
                    NULL, NULL, NULL,       // Application, Data, Timeout
                    $sCallerID,             // CallerID
                    NULL, NULL,             // Variable, Account
                    TRUE,                   // async
                    $sActionID
                    );
                if ($r['Response'] != 'Success') {
                    $this->_log->output('ERR: al terminar hold: no se puede retomar llamada - '.$r['Message'].
                        ' | EN: ERR: when ending hold: cannot resume call - '.$r['Message']);
                }
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: Originate para recuperar llamada devuelve/Originate to retrieve call returns: '.print_r($r, 1));
                }
            }
        }

        // Se delega registro de final de HOLD a manejadores de eventos

        $xml_unholdResponse->addChild('success');
        return $xml_response;
    }

    private function Request_eccpauth_getagentqueues($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que agente está presente
        if (!isset($comando->agent_number))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_getagentqueuesResponse = $xml_response->addChild('getagentqueues_response');

        // Verificar que la extensión y el agente son válidos en el sistema
        if (!$this->_existeAgente($sAgente)) {
            $this->_agregarRespuestaFallo($xml_getagentqueuesResponse, 404, 'Specified agent not found');
            return $xml_response;
        }

        // Reportar las colas a las que el agente está suscrito o puede suscribirse
        $listaColas = $this->_tuberia->AMIEventProcess_listarTotalColasTrabajoAgente(array($sAgente));
        $xml_agentQueues = $xml_getagentqueuesResponse->addChild('queues');
        if (is_array($listaColas) && isset($listaColas[$sAgente])) {
            // $listaColas[$sAgente][0] son colas suscritas actualmente
            // $listaColas[$sAgente][1] son colas dinámicas a las que puede suscribirse
            foreach (array_unique(array_merge($listaColas[$sAgente][0], $listaColas[$sAgente][1])) as $sCola) {
                $xml_agentQueues->addChild('queue', xmlSafe($sCola));
            }
        }

        return $xml_response;
    }

    private function Request_eccpauth_getmultipleagentqueues($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Verificar que agente está presente
        if (!isset($comando->agents))
            return $this->_generarRespuestaFallo(400, 'Bad request');

        $xml_response = new SimpleXMLElement('<response />');
        $xml_getagentqueuesResponse = $xml_response->addChild('getmultipleagentqueues_response');

        $agentlist = array();
        foreach ($comando->agents->agent_number as $agent_number) {
            $sAgente = (string)$agent_number;

            // El siguiente código asume formato Agent/9000
            $agentFields = $this->_parseAgent($sAgente);
            if (is_null($agentFields)) {
                $this->_agregarRespuestaFallo($xml_getagentqueuesResponse, 417, 'Invalid agent number');
                return $xml_response;
            }
            $agentFields['queues'] = array();

            $agentlist[$sAgente] = $agentFields;
        }

        // Verificar que todos los agentes existen en el sistema
        $listaAgentes = $this->_listarAgentes();
        $agentesExtras = array_diff(array_keys($agentlist), array_keys($listaAgentes));
        if (count($agentesExtras) > 0) {
            $this->_agregarRespuestaFallo($xml_getagentqueuesResponse, 404, 'Specified agent not found');
            return $xml_response;
        }

        // Acumular las colas estáticas y dinámicas para cada agente
        $listaColas = $this->_tuberia->AMIEventProcess_listarTotalColasTrabajoAgente(array_keys($agentlist));
        foreach ($listaColas as $sAgente => $queuelist) {
            if (isset($agentlist[$sAgente])) {
                // $queuelist[0] son colas suscritas actualmente
                // $queuelist[1] son colas dinámicas a las que puede suscribirse
                $agentlist[$sAgente]['queues'] = array_unique(array_merge($queuelist[0], $queuelist[1]));
            }
        }
        unset($listaColas);

        // Conversión de resultado a XML
        $xml_agents = $xml_getagentqueuesResponse->addChild('agents');
        foreach (array_keys($agentlist) as $sAgente) {
            $xml_agent = $xml_agents->addChild('agent');
            $xml_agent->addChild('agent_number', xmlSafe($sAgente));
            $xml_agentQueues = $xml_agent->addChild('queues');
            foreach ($agentlist[$sAgente]['queues'] as $sCola) {
                $xml_agentQueues->addChild('queue', xmlSafe($sCola));
            }
        }

        return $xml_response;
    }

    private function Request_eccpauth_getagentactivitysummary($comando)
    {
        // Fechas de inicio y fin
        $sFechaInicio = $sFechaFin = date('Y-m-d');
        if (isset($comando->datetime_start)) {
            $sFechaInicio = (string)$comando->datetime_start;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaInicio))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid start date');
        }
        if (isset($comando->datetime_end)) {
            $sFechaFin = (string)$comando->datetime_end;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaFin))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid end date');
        }
        if (!is_null($sFechaInicio) && !is_null($sFechaFin) && $sFechaFin < $sFechaInicio) {
            $t = $sFechaInicio;
            $sFechaInicio = $sFechaFin;
            $sFechaFin = $t;
        }

        $xml_response = new SimpleXMLElement('<response />');
        $xml_getagentactivitysummaryResponse = $xml_response->addChild('getagentactivitysummary_response');

        // Leer la información de los agentes conocidos y su historial de sesión
        $sPeticionSQL = <<<LEER_AGENTE_AUDIT
SELECT agent.id, agent.type, agent.number, agent.name, SUM(TIME_TO_SEC(duration)) AS total_login_time
FROM agent
LEFT JOIN audit
    ON agent.id = audit.id_agent AND audit.id_break IS NULL
    AND audit.datetime_init BETWEEN ? AND ?
WHERE estatus = 'A' GROUP BY agent.id
LEER_AGENTE_AUDIT;
        $recordset = $this->_db->prepare($sPeticionSQL);
        $recordset->execute(array($sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59'));
        $listaAgentes = $recordset->fetchAll(PDO::FETCH_ASSOC);
        $recordset->closeCursor();

        $sPeticionSQL_sumallamadasAgente = <<<LEER_HISTORIAL_ATENCION
(SELECT call_entry.id_agent, 'incoming' AS campaign_type, queue_call_entry.queue AS queue,
    SUM(call_entry.duration) AS sec_calls, COUNT(*) AS num_calls
FROM call_entry, queue_call_entry
WHERE call_entry.id_queue_call_entry = queue_call_entry.id
    AND call_entry.datetime_init BETWEEN ? AND ?
GROUP BY call_entry.id_agent, queue_call_entry.queue)
UNION
(SELECT calls.id_agent, 'outgoing' AS campaign_type, campaign.queue,
    SUM(calls.duration) AS sec_calls, COUNT(*) AS num_calls
FROM calls, campaign
WHERE calls.id_campaign = campaign.id
    AND calls.start_time BETWEEN ? AND ?
GROUP BY calls.id_agent, campaign.queue)
LEER_HISTORIAL_ATENCION;
        $recordset_sumallamadasAgente = $this->_db->prepare($sPeticionSQL_sumallamadasAgente);
        $recordset_sumallamadasAgente->execute(array(
            $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59',
            $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59'
        ));
        $historialAtencion = array();
        foreach ($recordset_sumallamadasAgente->fetchAll(PDO::FETCH_ASSOC) as $tupla) {
            $id_agent = array_shift($tupla);
            $historialAtencion[$id_agent][$tupla['campaign_type']][] = $tupla;
        }
        $recordset_sumallamadasAgente->closeCursor();

        $sPeticionSQL_ultimasesionAgente = <<<LEER_ULTIMA_SESION
SELECT a.id_agent, a.datetime_init, a.datetime_end
FROM audit a
LEFT OUTER JOIN audit b
	ON b.id_break IS NULL
	AND a.id_agent = b.id_agent
	AND ((a.datetime_init < b.datetime_init)
		OR (a.datetime_init = b.datetime_init AND a.id < b.id))
	AND b.datetime_init BETWEEN ? AND ?
WHERE a.id_break IS NULL
	AND a.datetime_init BETWEEN ? AND ?
	AND b.id_agent IS NULL
LEER_ULTIMA_SESION;
        $recordset_ultimasesionAgente = $this->_db->prepare($sPeticionSQL_ultimasesionAgente);
        $recordset_ultimasesionAgente->execute(array(
            $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59',
            $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59'
        ));
        $ultimasesion = array();
        foreach ($recordset_ultimasesionAgente->fetchAll(PDO::FETCH_ASSOC) as $tupla) {
            $ultimasesion[$tupla['id_agent']] = $tupla;
        }
        $recordset_ultimasesionAgente->closeCursor();

        $sPeticionSQL_ultimapausaAgente = <<<LEER_ULTIMA_SESION
SELECT a.id_agent, a.datetime_init, a.datetime_end
FROM audit a
LEFT OUTER JOIN audit b
    ON b.id_break IS NOT NULL
    AND a.id_agent = b.id_agent
    AND ((a.datetime_init < b.datetime_init)
        OR (a.datetime_init = b.datetime_init AND a.id < b.id))
    AND b.datetime_init BETWEEN ? AND ?
WHERE a.id_break IS NOT NULL
    AND a.datetime_init BETWEEN ? AND ?
    AND b.id_agent IS NULL
LEER_ULTIMA_SESION;
        $recordset_ultimapausaAgente = $this->_db->prepare($sPeticionSQL_ultimapausaAgente);
        $recordset_ultimapausaAgente->execute(array(
            $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59',
            $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59'
        ));
        $ultimapausa = array();
        foreach ($recordset_ultimapausaAgente->fetchAll(PDO::FETCH_ASSOC) as $tupla) {
            $ultimapausa[$tupla['id_agent']] = $tupla;
        }
        $recordset_ultimapausaAgente->closeCursor();

        // Construir el árbol de salida, y consultar el historial de atención de llamadas
        $xml_agents = $xml_getagentactivitysummaryResponse->addChild('agents');
        foreach ($listaAgentes as $infoAgente) {
        	$xml_agent = $xml_agents->addChild('agent');
            $xml_agent->addChild('agentchannel', $infoAgente['type'].'/'.$infoAgente['number']);
            $xml_agent->addChild('agentname', xmlSafe($infoAgente['name']));
            $xml_agent->addChild('logintime', is_null($infoAgente['total_login_time']) ? 0 : $infoAgente['total_login_time']);

            $listaResumen = array('incoming' => array(), 'outgoing' => array());
            if (isset($historialAtencion[$infoAgente['id']])) foreach (array_keys($listaResumen) as $k) {
                if (isset($historialAtencion[$infoAgente['id']][$k]))
                    $listaResumen[$k] = $historialAtencion[$infoAgente['id']][$k];
            }

            $xml_callsummary = $xml_agent->addChild('callsummary');
            foreach (array('incoming', 'outgoing') as $k) {
            	if (!isset($listaResumen[$k])) $listaResumen[$k] = array();
                $xml_campaigntype = $xml_callsummary->addChild($k);
                foreach ($listaResumen[$k] as $queuesummary) {
                	$xml_queue = $xml_campaigntype->addChild('queue');
                    $xml_queue->addAttribute('id', (string)$queuesummary['queue']);
                    $xml_queue->addChild('sec_calls', $queuesummary['sec_calls']);
                    $xml_queue->addChild('num_calls', $queuesummary['num_calls']);
                }
            }

            // Información sobre inicio y final de sesión más reciente del agente
            if (isset($ultimasesion[$infoAgente['id']])) {
                $xml_agent->addChild('lastsessionstart', $ultimasesion[$infoAgente['id']]['datetime_init']);
                if (!is_null($ultimasesion[$infoAgente['id']]['datetime_end']))
                    $xml_agent->addChild('lastsessionend', $ultimasesion[$infoAgente['id']]['datetime_end']);
            }

            // Información sobre inicio y final de pausa más reciente del agente
            if (isset($ultimapausa[$infoAgente['id']])) {
                $xml_agent->addChild('lastpausestart', $ultimapausa[$infoAgente['id']]['datetime_init']);
                if (!is_null($ultimapausa[$infoAgente['id']]['datetime_end']))
                    $xml_agent->addChild('lastpauseend', $ultimapausa[$infoAgente['id']]['datetime_end']);
            }
        }
        return $xml_response;
    }

    private function Request_agentauth_getchanvars($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        $sAgente = (string)$comando->agent_number;

        $xml_response = new SimpleXMLElement('<response />');
        $xml_getchanvarsResponse = $xml_response->addChild('getchanvars_response');

        // Verificar si el agente está siendo monitoreado
        $infoSeguimiento = $this->_tuberia->AMIEventProcess_infoSeguimientoAgente($sAgente);
        if (is_null($infoSeguimiento)) {
            $this->_agregarRespuestaFallo($xml_getchanvarsResponse, 404, 'Specified agent not found');
            return $xml_response;
        }
        if ($infoSeguimiento['estado_consola'] != 'logged-in') {
            $this->_agregarRespuestaFallo($xml_getchanvarsResponse, 417, 'Agent currently not logged in');
            return $xml_response;
        }
        $sCanalRemoto = $infoSeguimiento['clientchannel'];
        if (is_null($sCanalRemoto)) {
            $this->_agregarRespuestaFallo($xml_getchanvarsResponse, 417, 'Agent not in call');
            return $xml_response;
        }
        $xml_getchanvarsResponse->addChild('clientchannel', xmlSafe($sCanalRemoto));
        $xml_chanvars = $xml_getchanvarsResponse->addChild('chanvars');

        // Listar la información disponible sobre las variables de canal
        $respuesta = $this->_ami->Command('core show channel '.$sCanalRemoto);
        if (isset($respuesta['data'])) {
        	$bSeccionVars = FALSE;
            foreach (explode("\n", $respuesta['data']) as $sLinea) {
            	$regs = NULL;
                if (preg_match('/^\s+Variables:\s*$/', $sLinea)) {
                    $bSeccionVars = TRUE;
                } elseif ($bSeccionVars && preg_match('/^(\w+)=(.*)$/', $sLinea, $regs)) {
                	$xml_chanvar = $xml_chanvars->addChild('chanvar');
                    $xml_chanvar->addChild('label', xmlSafe($regs[1]));
                    $xml_chanvar->addChild('value', xmlSafe($regs[2]));
                } elseif (trim($sLinea) == '') {
                	$bSeccionVars = FALSE;
                }
            }
        } else {
            $this->_log->output('ERR: se perdió sincronización con Asterisk AMI (respuesta de "core show channel" carece de "data") | EN: ERR: lost synch with Asterisk AMI ("core show channel" response lacks "data").');
            return $this->_generarRespuestaFallo(500, 'No AMI connection');
        }
        return $xml_response;
    }

    private function Request_eccpauth_callprogress($comando)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_callprogress = $xml_response->addChild('callprogress_response');

        $xml_callprogress->addChild('success');
        return array(
            'response'          =>  $xml_response,
            'nuevos_valores'    =>  array(
                'progresollamada'   =>  ((int)$comando->enable != 0),
            ),
        );
    }

    private function Request_eccpauth_campaignlog($comando)
    {
        // Fechas de inicio y fin
        $sFechaInicio = $sFechaFin = date('Y-m-d');
        if (isset($comando->datetime_start)) {
            $sFechaInicio = (string)$comando->datetime_start;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaInicio))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid start date');
        }
        if (isset($comando->datetime_end)) {
            $sFechaFin = (string)$comando->datetime_end;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sFechaFin))
                return $this->_generarRespuestaFallo(400, 'Bad request - invalid end date');
        }
        if (!is_null($sFechaInicio) && !is_null($sFechaFin) && $sFechaFin < $sFechaInicio) {
            $t = $sFechaInicio;
            $sFechaInicio = $sFechaFin;
            $sFechaFin = $t;
        }

        // Verificar que id y tipo está presente
        $idCampania = $sCola = NULL;
        if (!isset($comando->campaign_type))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        $sTipoCampania = (string)$comando->campaign_type;
        if (!in_array($sTipoCampania, array('incoming', 'outgoing')))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        if (isset($comando->campaign_id)) $idCampania = (int)$comando->campaign_id;
        if (isset($comando->queue)) $sCola = (string)$comando->queue;
        if ($sTipoCampania == 'outgoing' && is_null($idCampania))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        if ($sTipoCampania == 'incoming' && (is_null($idCampania) && is_null($sCola)))
            return $this->_generarRespuestaFallo(400, 'Bad request');
        if (!is_null($idCampania)) $sCola = NULL;

        // Verificar si se requieren los últimos N desde el offset indicado
        $iUltimosN = NULL; $idBefore = NULL;
        if (isset($comando->last_n)) {
        	$iUltimosN = (int)$comando->last_n;
            if (isset($comando->idbefore)) $idBefore = (int)$comando->idbefore;
        }

        $xml_response = new SimpleXMLElement('<response />');
        $xml_campaignlogResponse = $xml_response->addChild('campaignlog_response');

        if ($sTipoCampania == 'incoming') {
    	   $sPeticionSQL_leerLog = <<<LOG_CAMPANIA_ENTRANTE
SELECT call_progress_log.id, call_progress_log.datetime_entry,
    call_entry.callerid AS phone, queue_call_entry.queue,
    "incoming" AS campaign_type, call_progress_log.id_campaign_incoming AS campaign_id,
    call_progress_log.id_call_incoming AS call_id, call_progress_log.new_status,
    call_progress_log.retry, call_progress_log.uniqueid, call_progress_log.trunk,
    call_progress_log.duration,
    CONCAT(agent.type, "/", agent.number) AS agentchannel
FROM (call_progress_log, call_entry, queue_call_entry)
LEFT JOIN (agent) ON (call_progress_log.id_agent = agent.id)
WHERE (id_campaign_incoming = ? OR (? IS NULL AND id_campaign_incoming IS NULL))
    AND (? IS NULL OR queue_call_entry.queue = ?)
    AND call_progress_log.id_call_incoming = call_entry.id
    AND call_entry.id_queue_call_entry = queue_call_entry.id
    AND call_progress_log.datetime_entry BETWEEN ? AND ?
    AND ((? IS NULL) OR (call_progress_log.id < ?))
ORDER BY id
LOG_CAMPANIA_ENTRANTE;
            $paramSQL = array($idCampania, $idCampania, $sCola, $sCola,
                $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59',
                $idBefore, $idBefore);
        } else {
            $sPeticionSQL_leerLog = <<<LOG_CAMPANIA_SALIENTE
SELECT call_progress_log.id, call_progress_log.datetime_entry,
    calls.phone AS phone, campaign.queue,"outgoing" AS campaign_type,
    call_progress_log.id_campaign_outgoing AS campaign_id,
    call_progress_log.id_call_outgoing AS call_id,
    call_progress_log.new_status, call_progress_log.retry,
    call_progress_log.uniqueid, call_progress_log.trunk,
    call_progress_log.duration,
    CONCAT(agent.type, "/", agent.number) AS agentchannel
FROM (call_progress_log, calls, campaign)
LEFT JOIN (agent) ON (call_progress_log.id_agent = agent.id)
WHERE id_campaign_outgoing = ?
    AND call_progress_log.id_call_outgoing = calls.id
    AND calls.id_campaign = campaign.id
    AND call_progress_log.datetime_entry BETWEEN ? AND ?
    AND ((? IS NULL) OR (call_progress_log.id < ?))
ORDER BY id
LOG_CAMPANIA_SALIENTE;
            $paramSQL = array($idCampania,
                $sFechaInicio.' 00:00:00', $sFechaFin.' 23:59:59',
                $idBefore, $idBefore);
        }

        if (!is_null($iUltimosN)) {
        	$sPeticionSQL_leerLog .= ' DESC LIMIT ?';
            $paramSQL[] = $iUltimosN;
        }

        $sth = $this->_db->prepare($sPeticionSQL_leerLog);
        $sth->execute($paramSQL);
        $xml_logentries = $xml_campaignlogResponse->addChild('logentries');
        $recordset = $sth->fetchAll(PDO::FETCH_ASSOC);

        if (!is_null($iUltimosN)) {
        	// Ya que se pidió el orden inverso, se invierte el orden
            $recordset = array_reverse($recordset);
        }

        foreach ($recordset as $tupla) {
            $xml_logentry = $xml_logentries->addChild('logentry');
        	foreach ($tupla as $k => $v) if (!is_null($v)) {
        		$xml_logentry->addChild($k, xmlSafe($v));
        	}
        }
        return $xml_response;
    }

    private function Request_eccpauth_dumpstatus($comando)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_dumpstatusResponse = $xml_response->addChild('dumpstatus_response');
        $this->_tuberia->AMIEventProcess_dumpstatus();
        $xml_dumpstatusResponse->addChild('success');
        return $xml_response;
    }

    private function Request_eccpauth_refreshagents($comando)
    {
        $xml_response = new SimpleXMLElement('<response />');
        $xml_dumpstatusResponse = $xml_response->addChild('refreshagents_response');
        $this->_tuberia->msg_SQLWorkerProcess_requerir_nuevaListaAgentes();
        $xml_dumpstatusResponse->addChild('success');
        return $xml_response;
    }

    /**
     * ECCP request to check if an extension is registered in Asterisk
     * EN: Petición ECCP para verificar si una extensión está registrada en Asterisk
     */
    private function Request_eccpauth_getextensionstatus($comando)
    {
        if (is_null($this->_ami))
            return $this->_generarRespuestaFallo(500, 'No AMI connection');

        // Get extension from request (format: SIP/101, PJSIP/101, IAX2/101)
        if (!isset($comando->extension))
            return $this->_generarRespuestaFallo(400, 'Bad request');

        $sExtension = (string)$comando->extension;

        // Parse extension to get technology and peer number
        $regs = NULL;
        if (!preg_match('|^(\w+)/(\d+)$|', $sExtension, $regs)) {
            return $this->_generarRespuestaFallo(400, 'Invalid extension format');
        }

        $sTech = strtoupper($regs[1]);  // SIP, PJSIP, IAX2
        $sPeer = $regs[2];              // Extension number

        $xml_response = new SimpleXMLElement('<response />');
        $xml_response_child = $xml_response->addChild('getextensionstatus_response');

        $bRegistered = FALSE;

        // Check registration based on technology
        switch ($sTech) {
            case 'SIP':
                $result = $this->_ami->Command("sip show peer $sPeer");
                if (isset($result['data']) && strpos($result['data'], 'Status') !== false) {
                    $lines = explode("\n", $result['data']);
                    foreach ($lines as $line) {
                        if (stripos($line, 'Status') !== false &&
                            (stripos($line, 'OK') !== false || stripos($line, 'Registered') !== false)) {
                            $bRegistered = TRUE;
                            break;
                        }
                    }
                }
                break;

            case 'PJSIP':
                $result = $this->_ami->Command("pjsip show endpoint $sPeer");
                if (isset($result['data']) && strpos($result['data'], 'Not Found') === false) {
                    /* Estados de contacto en Asterisk 18: Avail, Unavail, Unknown,
                     * NonQual, Created, Removed. Solo Avail (contacto presente y
                     * alcanzable) cuenta como registrado.
                     * stripos($line, 'Avail') era incorrecto porque 'Unavail' lo
                     * contiene como subcadena, de modo que un contacto muerto se
                     * reportaba como registrado. Se compara token por token.
                     * NOTA: 'NONQUAL' (qualify deshabilitado) sigue contando como
                     * no registrado, igual que antes. Agregarlo a este arreglo si
                     * se decide aceptar extensiones con qualify apagado. */
                    /* Asterisk 18 contact statuses: Avail, Unavail, Unknown,
                     * NonQual, Created, Removed. Only Avail (contact present and
                     * reachable) counts as registered.
                     * stripos($line, 'Avail') was wrong because 'Unavail' contains
                     * it as a substring, so a dead contact was reported as
                     * registered. Compare token by token instead.
                     * NOTE: 'NONQUAL' (qualify disabled) still counts as not
                     * registered, same as before. Add it to this array if
                     * extensions with qualify turned off should be accepted. */
                    $estadosRegistrado = array('AVAIL');
                    $lines = explode("\n", $result['data']);
                    foreach ($lines as $line) {
                        if (stripos($line, 'Contact:') === false) continue;

                        // Contact:  <Aor/ContactUri> <Hash> <Status> <RTT(ms)>
                        $campos = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
                        foreach ($campos as $campo) {
                            if (in_array(strtoupper($campo), $estadosRegistrado)) {
                                $bRegistered = TRUE;
                                break 2;
                            }
                        }
                    }
                }
                break;

            case 'IAX2':
                $result = $this->_ami->Command("iax2 show peer $sPeer");
                if (isset($result['data']) && strpos($result['data'], 'Status') !== false) {
                    $lines = explode("\n", $result['data']);
                    foreach ($lines as $line) {
                        if (stripos($line, 'Status') !== false &&
                            (stripos($line, 'OK') !== false || stripos($line, 'Registered') !== false)) {
                            $bRegistered = TRUE;
                            break;
                        }
                    }
                }
                break;

            default:
                $xml_response_child->addChild('status', 'unknown');
                $xml_response_child->addChild('message', 'Unknown technology: ' . $sTech);
                return $xml_response;
        }

        $xml_response_child->addChild('extension', $sExtension);
        $xml_response_child->addChild('registered', $bRegistered ? 'yes' : 'no');

        return $xml_response;
    }
}
?>

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
  $Id: new_campaign.php $ */

/**
 * Clase que contiene la funcionalidad principal de la consola de agente.
 *
 * EN: Class that contains the main functionality of the agent console.
 */

require_once 'libs/paloSantoDB.class.php';
require_once 'ECCP.class.php';

class PaloSantoConsola
{
    var $errMsg = '';    // Mensajes de error // EN: Error messages
    private $_oDB_asterisk = NULL;     // Conexión a base de datos asterisk (FreePBX) // EN: Connection to asterisk database (FreePBX)
    private $_oDB_call_center = NULL;  // Conexión a base de datos call_center // EN: Connection to call_center database
    private $_astman = NULL;
    private $_eccp = NULL;

    private $_agent = NULL;     // Si se ha elegido un agente, es de forma Agent/9000 // EN: If an agent is chosen, it's in the form Agent/9000

    function __construct($sAgent = NULL)
    {
        if (!is_null($sAgent)) $this->_agent = $sAgent;
    }

    // Obtener la conexión requerida, iniciándola si es necesario
    // EN: Get the required connection, initializing it if necessary
    private function _obtenerConexion($sConn)
    {
        global $arrConf;

        switch ($sConn) {
        case 'asterisk':
            if (!is_null($this->_oDB_asterisk)) return $this->_oDB_asterisk;
            $sDSN = generarDSNSistema('asteriskuser', 'asterisk');
            $oDB = new paloDB($sDSN);
            if ($oDB->connStatus) {
                $this->_errMsg = '(internal) Unable to create asterisk DB conn - '.$oDB->errMsg;
                die($this->_errMsg);
            }
            $this->_oDB_asterisk = $oDB;
            return $this->_oDB_asterisk;
            break;
        case 'call_center':
            if (!is_null($this->_oDB_call_center)) return $this->_oDB_call_center;
            $sDSN = $arrConf['cadena_dsn'];
            $oDB = new paloDB($sDSN);
            if ($oDB->connStatus) {
                $this->_errMsg = '(internal) Unable to create asterisk DB conn - '.$oDB->errMsg;
                die($this->_errMsg);
            }
            $this->_oDB_call_center = $oDB;
            return $this->_oDB_call_center;
            break;
        case 'ECCP':
            if (!is_null($this->_eccp)) return $this->_eccp;

            $sUsernameECCP = 'agentconsole';
            $sPasswordECCP = 'agentconsole';

            // Verificar si existe la contraseña de ECCP, e insertar si necesario
            // EN: Verify if ECCP password exists, and insert if necessary
            $dbConnCC = $this->_obtenerConexion('call_center');
            $md5_passwd = $dbConnCC->getFirstRowQuery(
                'SELECT md5_password FROM eccp_authorized_clients WHERE username = ?',
                TRUE, array($sUsernameECCP));
            if (is_array($md5_passwd)) {
                if (count($md5_passwd) <= 0) {
                    $dbConnCC->genQuery(
                        'INSERT INTO eccp_authorized_clients (username, md5_password) VALUES(?, md5(?))',
                        array($sUsernameECCP, $sPasswordECCP));
                } else {
                    $sPasswordECCP = $md5_passwd['md5_password'];
                }
            }

            $oECCP = new ECCP();

            // TODO: configurar credenciales
            // EN: TODO: configure credentials
            $cr = $oECCP->connect("localhost", $sUsernameECCP, $sPasswordECCP);
            if (isset($cr->failure)) {
                throw new ECCPUnauthorizedException(_tr('Failed to authenticate to ECCP').': '.((string)$cr->failure->message));
            }
            if (!is_null($this->_agent)) {
                $oECCP->setAgentNumber($this->_agent);

                /* Privilegio de localhost - se puede recuperar la clave del
                 * agente sin tener que pedirla explícitamente
                 * EN: Localhost privilege - agent password can be retrieved
                 * without explicitly requesting it */
                $tupla = $dbConnCC->getFirstRowQuery(
                        "SELECT eccp_password FROM agent WHERE CONCAT(type,'/',number) = ? AND estatus='A'",
                    FALSE, array($this->_agent));
                if (!is_array($tupla))
                    throw new ECCPConnFailedException(_tr('Failed to retrieve agent password'));
                if (count($tupla) <= 0)
                    throw new ECCPUnauthorizedException(_tr('Agent not found'));
                if (is_null($tupla[0]))
                    throw new ECCPUnauthorizedException(_tr('Agent not authorized for ECCP - ECCP password not set'));
                $oECCP->setAgentPass($tupla[0]);

                // Filtrar los eventos sólo para el agente actual
                // EN: Filter events only for the current agent
                $oECCP->filterbyagent();
            }

            $this->_eccp = $oECCP;
            return $this->_eccp;
            break;
        }
        return NULL;
    }

    // Leer el estado de /etc/asterisk/manager.conf y obtener el primer usuario
    // que puede usar el dialer. Devuelve NULL en caso de error, o tupla
    // user,password para conexión en localhost.
    // EN: Read the state of /etc/asterisk/manager.conf and get the first user
    // that can use the dialer. Returns NULL on error, or tuple user,password
    // for localhost connection.
    private function _leerConfigManager()
    {
        $sNombreArchivo = '/etc/asterisk/manager.conf';
        if (!file_exists($sNombreArchivo)) {
            $this->_errMsg = "(internal) $sNombreArchivo no se encuentra.";
            return NULL;
        }
        if (!is_readable($sNombreArchivo)) {
            $this->_errMsg = "(internal) $sNombreArchivo no puede leerse por usuario de marcador.";
            return NULL;
        }
        $infoConfig = parse_ini_file($sNombreArchivo, TRUE);
        if (is_array($infoConfig)) {
            foreach ($infoConfig as $login => $infoLogin) {
                if ($login != 'general') {
                    if (isset($infoLogin['secret']) && isset($infoLogin['read']) && isset($infoLogin['write'])) {
                        return array($login, $infoLogin['secret']);
                    }
                }
            }
        } else {
            $this->_errMsg = "(internal) $sNombreArchivo no puede parsearse correctamente.";
        }
        return NULL;
    }


    /**
     * Método que desconecta todas las conexiones a base de datos y Asterisk que
     * mantenga conectado el objeto.
     *
     * EN: Method that disconnects all database and Asterisk connections
     * maintained by the object.
     *
     * @return  null
     */
    function desconectarTodo()
    {
        $this->desconectarEspera();
        if (!is_null($this->_eccp)) {
            try {
                $this->_eccp->disconnect();
            } catch (Exception $e) {}
            $this->_eccp = NULL;
        }
    }

    /**
     * Método que desconecta todas las conexiones a bases de datos y a Asterisk,
     * pero mantiene la conexión activa a ECCP. El uso esperado es
     * inmediatamente antes de la espera larga de la interfaz web, donde no
     * se esperan futuras consultas a la base de datos.
     *
     * EN: Method that disconnects all database and Asterisk connections,
     * but maintains the active ECCP connection. Expected use is immediately
     * before the long wait of the web interface, where no future database
     * queries are expected.
     *
     * @return  null
     */
    function desconectarEspera()
    {
        if (!is_null($this->_oDB_asterisk)) {
            $this->_oDB_asterisk->disconnect();
            $this->_oDB_asterisk = NULL;
        }
        if (!is_null($this->_oDB_call_center)) {
            $this->_oDB_call_center->disconnect();
            $this->_oDB_call_center = NULL;
        }
        if (!is_null($this->_astman)) {
            $this->_astman->disconnect();
            $this->_astman = NULL;
        }
    }

    private function _formatoErrorECCP($x)
    {
        if (isset($x->failure)) {
            return (int)$x->failure->code.' - '.(string)$x->failure->message;
        } else {
            return '';
        }
    }

    /**
     * Método que lista todas las extensiones SIP e IAX que están definidas en
     * el sistema. Estas extensiones pueden ser usadas por el agente para
     * logonearse en el sistema. La lista se devuelve de la forma
     * (1000 => 'SIP/1000'), ...
     *
     * EN: Method that lists all SIP and IAX extensions defined in the system.
     * These extensions can be used by the agent to log into the system.
     * The list is returned in the form (1000 => 'SIP/1000'), ...
     *
     * @return  mixed   La lista de extensiones. // EN: The list of extensions.
     */
    function listarExtensiones()
    {
        // TODO: esto duplica a ECCPConn::_listarExtensiones en dialer
        // EN: TODO: this duplicates ECCPConn::_listarExtensiones in dialer
        $oDB = $this->_obtenerConexion('asterisk');
        $sPeticion = 'SELECT user AS extension, dial from devices ORDER BY user';
        $recordset = $oDB->fetchTable($sPeticion, TRUE);
        if (!is_array($recordset)) die('(internal) Cannot list extensions - '.$oDB->errMsg);

        $listaExtensiones = array();
        foreach ($recordset as $tupla) {
            $listaExtensiones[$tupla['extension']] = $tupla['dial'];
        }
        return $listaExtensiones;
    }

    /**
     * Método que lista todos los agentes registrados en la base de datos. La
     * lista se devuelve de la forma ('Agent/9000' => 'Over 9000!!!'), ...
     *
     * EN: Method that lists all agents registered in the database. The list
     * is returned in the form ('Agent/9000' => 'Over 9000!!!'), ...
     *
     * @param   string  $agenttype  Tipo de agente a listar
     *      NULL    todos los agentes // EN: all agents
     *      'static'    Sólo los agentes Agent/XXXX (estáticos) // EN: Only Agent/XXXX agents (static)
     *      'dynamic'   Sólo los agentes SIP/xxxx o IAX2/xxxx (dinámicos) // EN: Only SIP/xxxx or IAX2/xxxx agents (dynamic)
     *
     * @return  mixed   La lista de agentes activos // EN: The list of active agents
     */
    function listarAgentes($agenttype = NULL)
    {
        $oDB = $this->_obtenerConexion('call_center');
        $listaWhere = array("estatus = 'A'");
        if ($agenttype == 'static') $listaWhere[] = "type = 'Agent'";
        if ($agenttype == 'dynamic') $listaWhere[] = "type <> 'Agent'";
        $sPeticion =
            "SELECT CONCAT(type,'/',number) AS number, name FROM agent ".
            "WHERE ".implode(' AND ', $listaWhere)." ORDER BY number";
        $recordset = $oDB->fetchTable($sPeticion, TRUE);
        if (!is_array($recordset)) die('(internal) Cannot list agents - '.$oDB->errMsg);

        $listaAgentes = array();
        foreach ($recordset as $tupla) {
            $listaAgentes[$tupla['number']] = $tupla['number'].' - '.$tupla['name'];
        }
        return $listaAgentes;
    }

    /** Método para autenticar la extensión callback con su respectiva contraseña en la tabla agent.
      *
      * EN: Method to authenticate the callback extension with its respective password in the agent table.
      *
      * @param string  $sExtensionCallback: Extensión que está usando el agente, como "SIP/250"
      *                                  EN: Extension being used by the agent, like "SIP/250"
      * @param string  $sPassword: Contraseña de la extensión para hacer login al Callcenter en el modo Callback.
      *                        EN: Extension password to login to Callcenter in Callback mode.
      *
      * @return VERDADERO en éxito, FALSE en error
      *         EN: TRUE on success, FALSE on error
      */
    function autenticar($sExtensionCallback, $sPassword)
    {
        $oDB = $this->_obtenerConexion('call_center');

        $sPeticion = "SELECT count(*) as cont
                  FROM agent
                  WHERE CONCAT(type,'/',number) = ?
                  AND password = ?
                  AND estatus = 'A'
                  ORDER BY number";
            $recordset = $oDB->fetchTable($sPeticion, TRUE, array($sExtensionCallback, $sPassword));
            if (!is_array($recordset)) die('(internal) Cannot execute query - '.$oDB->errMsg);

        if($recordset[0]['cont']==1){
            return true;
        }else{
            $this->errMsg = "Wrong password.";
            return false;
        }
    }

    /**
     * Authenticate agent by number and password (for Agent type login)
     *
     * @param   string  $sAgentNumber   Agent number (e.g., "1001")
     * @param   string  $sPassword      Agent password
     *
     * @return  bool    TRUE if authentication succeeds, FALSE otherwise
     */
    function autenticarAgente($sAgentNumber, $sPassword)
    {
        $oDB = $this->_obtenerConexion('call_center');

        $sPeticion = "SELECT count(*) as cont
                  FROM agent
                  WHERE number = ?
                  AND password = ?
                  AND type = 'Agent'
                  AND estatus = 'A'";
        $recordset = $oDB->fetchTable($sPeticion, TRUE, array($sAgentNumber, $sPassword));
        if (!is_array($recordset)) die('(internal) Cannot execute query - '.$oDB->errMsg);

        if($recordset[0]['cont'] == 1){
            return true;
        } else {
            $this->errMsg = "Wrong password.";
            return false;
        }
    }

    /**
     * Check if an extension number is already used by an active Agent type login session
     *
     * EN: Verificar si un número de extensión ya está siendo usado por una sesión activa de tipo Agent
     *
     * @param   string  $sExtensionNum  Extension number (e.g., "101")
     * @return  bool    TRUE if extension is already in use by Agent type, FALSE otherwise
     */
    function extensionUsadaPorAgente($sExtensionNum)
    {
        $oDB = $this->_obtenerConexion('call_center');

        // Check if this extension number is already used by an active Agent type session
        $sql_check_extension = "SELECT COUNT(*) as count
                               FROM audit a
                               JOIN agent ag ON a.id_agent = ag.id
                               WHERE a.login_extension LIKE ?
                               AND ag.type = 'Agent'
                               AND a.datetime_end IS NULL";

        $result = $oDB->fetchTable($sql_check_extension, TRUE, array("%$sExtensionNum"));

        if (!is_array($result) || count($result) <= 0) {
            $this->errMsg = "Error checking extension usage: ".$oDB->errMsg;
            return FALSE;
        }

        return $result[0]['count'] > 0;
    }

    /**
     * Check if an extension is already in use by a callback extension type session
     * 
     * EN: Verificar si una extensión ya está siendo usada por una sesión de tipo callback
     *
     * @param   string  $sExtensionNum  Extension number (e.g., "101")
     * @return  bool    TRUE if extension is already in use by callback type, FALSE otherwise
     */
    function extensionUsadaPorCallback($sExtensionNum)
    {
        $oDB = $this->_obtenerConexion('call_center');

        // Check if this extension number is already used by an active callback type session
        // Callback types are: SIP, PJSIP, IAX2 (anything that is NOT 'Agent')
        $sql_check_extension = "SELECT COUNT(*) as count
                               FROM audit a
                               JOIN agent ag ON a.id_agent = ag.id
                               WHERE a.login_extension LIKE ?
                               AND ag.type != 'Agent'
                               AND a.datetime_end IS NULL";

        $result = $oDB->fetchTable($sql_check_extension, TRUE, array("%$sExtensionNum"));

        if (!is_array($result) || count($result) <= 0) {
            $this->errMsg = "Error checking extension usage: ".$oDB->errMsg;
            return FALSE;
        }

        return $result[0]['count'] > 0;
    }

    /**
     * Check if an extension is registered in Asterisk using ECCP
     *
     * EN: Verificar si una extensión está registrada en Asterisk usando ECCP
     *
     * @param   string  $sAgentFormat  Agent in format like "SIP/101", "PJSIP/101", "IAX2/101"
     * @return  bool    TRUE if extension is registered, FALSE otherwise
     */
    function extensionEstaRegistrada($sAgentFormat)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $response = $oECCP->getextensionstatus($sAgentFormat);
            if (isset($response->failure)) {
                $this->errMsg = '(internal) getextensionstatus: ' . $response->failure->message;
                return FALSE;
            }
            return ((string)$response->registered == 'yes');
        } catch (Exception $e) {
            $this->errMsg = '(internal) getextensionstatus: ' . $e->getMessage();
            return FALSE;
        }
    }

    /**
     * Método para iniciar el login del agente con la extensión y el número de
     * agente que se indican.
     *
     * EN: Method to start the agent login with the extension and agent number
     * indicated.
     *
     * @param   string  Extensión que está usando el agente, como "SIP/1064"
     *                  EN: Extension being used by the agent, like "SIP/1064"
     * @param   string  Número del agente que se está logoneando: "9000"
     *                  EN: Agent number being logged in: "9000"
     *
     * @return  VERDADERO en éxito, FALSE en error
     *          EN: TRUE on success, FALSE on error
     */
    function loginAgente($sExtension)
    {
        // Leer el valor del timeout del agente por inactividad
        // EN: Read the agent timeout value for inactivity
        $oDB = $this->_obtenerConexion('call_center');
        $tupla = $oDB->getFirstRowQuery(
            'SELECT config_value FROM valor_config WHERE config_key = ?',
            TRUE, array('dialer.timeout_inactivity'));
        if (!is_array($tupla) || count($tupla) <= 0)
            $iTimeoutMin = 15;
        else $iTimeoutMin = (int)$tupla['config_value'];

        $regs = NULL;
        if (preg_match('|^\w+/(\d+)$|', $sExtension, $regs))
            $sNumero = $regs[1];
        else $sNumero = $sExtension;
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $loginResponse = $oECCP->loginagent($sNumero, NULL, $iTimeoutMin * 60);
            if (isset($loginResponse->failure))
                $this->errMsg = '(internal) loginagent: '.$this->_formatoErrorECCP($loginResponse);
            return ($loginResponse->status == 'logged-in' || $loginResponse->status == 'logging');
        } catch (Exception $e) {
            $this->errMsg = '(internal) loginagent: '.$e->getMessage();
            return FALSE;
        }
    }

    /**
     * Método para esperar 1 segundo por el resultado del login del agente
     * asociado con esta consola de agente. Se asume que previamente se ha
     * iniciado un login de agente con la función loginAgente().
     *
     * EN: Method to wait 1 second for the result of the agent login associated
     * with this agent console. It is assumed that an agent login has been
     * previously started with the loginAgente() function.
     *
     * @return  string  Uno de logged-in logging logged-out mismatch error
     *                  EN: One of logged-in logging logged-out mismatch error
     */
    function esperarResultadoLogin()
    {
        $this->errMsg = '';
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $oECCP->wait_response(1);
            while ($e = $oECCP->getEvent()) {
                foreach ($e->children() as $ee) $evt = $ee;

                if ($evt->getName() == 'agentloggedin' && $evt->agent == $this->_agent)
                    return 'logged-in';
                if ($evt->getName() == 'agentfailedlogin' && $evt->agent == $this->_agent)
                    return 'logged-out';
            }
            return 'logging';   // No se recibieron eventos relevantes // EN: No relevant events received
        } catch (Exception $e) {
            $this->errMsg = '(internal) esperarResultadoLogin: '.$e->getMessage();
            return 'error';
        }
    }

    /**
     * Método para terminar el login de un agente cuyo número se indica. Esta
     * operación también termina cualquier pausa en la que esté puesto el
     * agente.
     *
     * EN: Method to terminate the login of an agent whose number is indicated.
     * This operation also terminates any pause the agent is in.
     *
     * @param   string  Número del agente que se está logoneando: "9000"
     *                  EN: Number of the agent being logged in: "9000"
     *
     * @return  VERDADERO en éxito, FALSE en error
     *          EN: TRUE on success, FALSE on error
     */
    function logoutAgente()
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $response = $oECCP->logoutagent();
            if (isset($response->failure)) {
                $this->errMsg = '(internal) logoutagent: '.$this->_formatoErrorECCP($response);
                return FALSE;
            }
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) logoutagent: '.$e->getMessage();
            return FALSE;
        }
    }

    /**
     * Método para verificar el estado de logoneo de agente a través de
     * 'agent show online'. Este método es el principal mecanismo para mantener
     * la sesión activa del agente en el navegador.
     *
     * EN: Method to verify the agent login status through 'agent show online'.
     * This method is the main mechanism to keep the agent session active in
     * the browser.
     *
     * @param   string  Número del agente que se está logoneando: "9000"
     *                  EN: Number of the agent being logged in: "9000"
     * @param   string  Extensión que está usando el agente, como "SIP/1064"
     *                  EN: Extension being used by the agent, like "SIP/1064"
     *
     * @return  string  Uno de logged-in logging logged-out mismatch error
     *                  EN: One of logged-in logging logged-out mismatch error
     */
    function estadoAgenteLogoneado($sExtension)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $connStatus = $oECCP->getagentstatus();
            if (isset($connStatus->failure)) {
                $this->errMsg = '(internal) getagentstatus: '.$this->_formatoErrorECCP($connStatus);
                return array('estadofinal' => 'error');
            }

            $estado = $this->_traducirEstadoAgente($connStatus);
            $estado['estadofinal'] = 'logged-in';   // A modificar por condiciones

            if (!is_null($estado['pauseinfo'])) foreach (array('pausestart') as $k) {
                if (!is_null($estado['pauseinfo'][$k]) && preg_match('/^\d+:\d+:\d+$/', $estado['pauseinfo'][$k]))
                    $estado['pauseinfo'][$k] = date('Y-m-d ').$estado['pauseinfo'][$k];
            }
            // The dialer strips today's date from holdstart, same as pausestart
            if (!is_null($estado['holdstart']) && preg_match('/^\d+:\d+:\d+$/', $estado['holdstart']))
                $estado['holdstart'] = date('Y-m-d ').$estado['holdstart'];
            if (!is_null($estado['callinfo'])) foreach (array('dialstart', 'dialend', 'queuestart', 'linkstart') as $k) {
                if (!is_null($estado['callinfo'][$k]) && preg_match('/^\d+:\d+:\d+$/', $estado['callinfo'][$k]))
                    $estado['callinfo'][$k] = date('Y-m-d ').$estado['callinfo'][$k];
            }

            if ($estado['status'] == 'offline') {
                $estado['estadofinal'] = is_null($estado['channel']) ? 'logged-out' : 'logging';
            } elseif ($estado['extension'] != $sExtension && preg_match('|^Agent/(\d+)$|', $this->_agent, $regs)) {
                $estado['estadofinal'] = 'mismatch';
                $this->errMsg = _tr('Specified agent already connected to extension').
                    ' '.(string)$connStatus->extension.' status '.$estado['status'];
            }
            return $estado;
        } catch (Exception $e) {
            $this->errMsg = '(internal) getagentstatus: '.$e->getMessage();
            return array('estadofinal' => 'error');
        }
    }

    private function _traducirEstadoAgente($connStatus)
    {
        $estado = array(
            'status'            =>  (string)$connStatus->status,
            'channel'           =>  isset($connStatus->channel) ? (string)$connStatus->channel : NULL,
            'extension'         =>  isset($connStatus->extension) ? (string)$connStatus->extension : NULL,
            'onhold'            =>  isset($connStatus->onhold) ? ($connStatus->onhold == 1) : FALSE,
            // Start of the running hold, so the status bar's hold timer can be
            // restored on a page reload instead of restarting from zero.
            'holdstart'         =>  isset($connStatus->holdstart) ? (string)$connStatus->holdstart : NULL,
            // Attended-transfer consultation state: none/ringing/answered.
            'consultation'      =>  isset($connStatus->consultation) ? (string)$connStatus->consultation : 'none',
            // DIALSTATUS of the last failed consultation (BUSY/NOANSWER/...).
            'consultation_reason' => isset($connStatus->consultation_reason) ? (string)$connStatus->consultation_reason : NULL,
            'callchannel'       =>  isset($connStatus->callchannel) ? (string)$connStatus->callchannel : NULL, // <-- duplicado en remote_channel // EN: duplicated in remote_channel
            'pauseinfo'         =>  isset($connStatus->pauseinfo) ? array(
                'pauseid'       =>  (int)$connStatus->pauseinfo->pauseid,
                'pausename'     =>  (string)$connStatus->pauseinfo->pausename,
                'pausestart'    =>  (string)$connStatus->pauseinfo->pausestart,
            ) : NULL,
            'callinfo'          =>  isset($connStatus->callinfo) ? array_merge(
                $this->_traducirEstadoLlamada($connStatus->callinfo),
                array(
                    'agent_number'  =>  $this->_agent,
                    'remote_channel'    =>  isset($connStatus->remote_channel) ? (string)$connStatus->remote_channel : NULL,
                )
            ) : NULL,
            'waitedcallinfo'    =>  isset($connStatus->waitedcallinfo) ? array(
                'calltype'          =>  (string)$connStatus->waitedcallinfo->calltype,
                'campaign_id'       =>  (int)$connStatus->waitedcallinfo->campaign_id,
                'callid'            =>  (int)$connStatus->waitedcallinfo->callid,
                'status'            =>  (string)$connStatus->waitedcallinfo->status,
            ) : NULL,
        );
        if (isset($connStatus->agentchannel))
            $estado['agentchannel'] = (string)$connStatus->agentchannel;
        if (is_null($estado['pauseinfo']) && isset($connStatus->pauseid)) {
            $estado['pauseinfo'] = array(
                'pauseid'       =>  (int)$connStatus->pauseid,
                'pausename'     =>  (string)$connStatus->pausename,
                'pausestart'    =>  (string)$connStatus->pausestart,
            );
        }
        if (is_null($estado['callinfo']) && isset($connStatus->callchannel)) {
            $estado['callinfo'] = array_merge($this->_traducirEstadoLlamada($connStatus), array(
                'agent_number'  =>  $this->_agent,
                'remote_channel'=>  (string)$connStatus->callchannel,
            ));
        }
        return $estado;
    }

    private function _traducirEstadoLlamada($xml_callinfo)
    {
        return array(
            'callstatus'    =>  (string)$xml_callinfo->callstatus,
            'calltype'      =>  (string)$xml_callinfo->calltype,
            'campaign_id'   =>  isset($xml_callinfo->campaign_id) ? (int)$xml_callinfo->campaign_id : NULL,
            'callid'        =>  (int)$xml_callinfo->callid,
            'callnumber'    =>  (string)$xml_callinfo->callnumber,
            'queuenumber'   =>  isset($xml_callinfo->queuenumber) ? (string)$xml_callinfo->queuenumber : NULL,
            'dialstart'     =>  isset($xml_callinfo->dialstart) ? (string)$xml_callinfo->dialstart : NULL,
            'dialend'       =>  isset($xml_callinfo->dialend) ? (string)$xml_callinfo->dialend : NULL,
            'queuestart'    =>  isset($xml_callinfo->queuestart) ? (string)$xml_callinfo->queuestart : NULL,
            'linkstart'     =>  isset($xml_callinfo->linkstart) ? (string)$xml_callinfo->linkstart : NULL,
            'trunk'         =>  isset($xml_callinfo->trunk) ? (string)$xml_callinfo->trunk : NULL,
        );
    }

    /**
     * Método para calcular un intervalo razonable de espera durante una petición
     * larga de AJAX.
     *
     * EN: Method to calculate a reasonable wait interval during a long AJAX
     * request.
     *
     * @return  integer     El valor en segundos recomendado según el navegador.
     *                      EN: The recommended value in seconds according to the browser.
     */
    function recomendarIntervaloEsperaAjax()
    {
        $iTimeoutPoll = 2 * 60;
/*
        // Problemas con MSIE al haber más de un AJAX con respuesta larga
        // EN: Problems with MSIE when having more than one AJAX with long response
        if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE ') !== false) {
            $iTimeoutPoll = 2;
        }
*/
        /* El intervalo de inactividad debe ser al menos 1.5 veces el intervalo
         * de espera AJAX. Si esto no se cumple, se reduce el intervalo de
         * espera AJAX.
         * EN: The inactivity interval must be at least 1.5 times the AJAX wait
         * interval. If this is not met, the AJAX wait interval is reduced. */
        $oDB = $this->_obtenerConexion('call_center');
        $tupla = $oDB->getFirstRowQuery(
            'SELECT config_value FROM valor_config WHERE config_key = ?',
            TRUE, array('dialer.timeout_inactivity'));
        if (!is_array($tupla) || count($tupla) <= 0)
            $iTimeoutMin = 15;
        else $iTimeoutMin = (int)$tupla['config_value'];

        $iTimeoutSec = $iTimeoutMin * 60;
        if ($iTimeoutSec <= $iTimeoutPoll * 1.5)
            $iTimeoutPoll = (int)($iTimeoutSec / 2);

        return $iTimeoutPoll;
    }


    /**
     * Método para mandar a ejecutar el colgado de la llamada activa.
     *
     * EN: Method to execute the hangup of the active call.
     *
     * @return  bool  TRUE para llamada colgada, o FALSE si error
     *                EN: TRUE for hung up call, or FALSE if error
     */
    function colgarLlamada()
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->hangup();
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to hangup call').' - '.$this->_formatoErrorECCP($respuesta);
                return FALSE;
            }
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) hangup: '.$e->getMessage();
            return FALSE;
        }
    }

    /**
     * Método para poner la llamada actual en hold (espera)
     *
     * EN: Method to put the current call on hold
     *
     * @return TRUE en caso de éxito, FALSE en caso de error
     *         EN: TRUE on success, FALSE on error
     */
    function ponerEnHold()
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->hold();
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to place call on hold').' - '.$this->_formatoErrorECCP($respuesta);
                return FALSE;
            }
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) hold: '.$e->getMessage();
            return FALSE;
        }
    }

    /**
     * Método para reanudar la llamada desde hold (espera)
     *
     * EN: Method to resume the call from hold
     *
     * @return TRUE en caso de éxito, FALSE en caso de error
     *         EN: TRUE on success, FALSE on error
     */
    function reanudarDeHold()
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->unhold();
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to resume call from hold').' - '.$this->_formatoErrorECCP($respuesta);
                return FALSE;
            }
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) unhold: '.$e->getMessage();
            return FALSE;
        }
    }

    /**
     * Método para listar los breaks conocidos en el sistema.
     *
     * EN: Method to list the breaks known in the system.
     *
     * @return NULL en caso de éxito, o lista en la forma
     *         array([breakid]=>"breakname - breakdesc")
     *         EN: NULL on error, or list in the form array([breakid]=>"breakname - breakdesc")
     */
    function listarBreaks()
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->getpauses();
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to fetch break types').' - '.$this->_formatoErrorECCP($respuesta);
                return NULL;
            }
            $listaPausas = array();
            foreach ($respuesta->pause as $xml_pause) {
                if ($xml_pause->status == 'A')
                   $listaPausas[(int)$xml_pause['id']] = (string)$xml_pause->name.' - '.(string)$xml_pause->description;
            }
            return $listaPausas;
        } catch (Exception $e) {
            $this->errMsg = '(internal) getpauses: '.$e->getMessage();
            return NULL;
        }
    }

    /**
     * Método para iniciar el break del agente actualmente logoneado
     *
     * EN: Method to start the break of the currently logged in agent
     *
     * @param   int $idBreak    ID del break a usar para el agente
     *                          EN: ID of the break to use for the agent
     *
     * @return  TRUE en caso de éxito, FALSE en caso de error.
     *          EN: TRUE on success, FALSE on error.
     */
    function iniciarBreak($idBreak)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->pauseagent($idBreak);
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to start break').' - '.$this->_formatoErrorECCP($respuesta);
                return FALSE;
            }
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) pauseagent: '.$e->getMessage();
            return FALSE;
        }
    }

    /**
     * Método para terminar el break del agente actualmente logoneado
     *
     * EN: Method to end the break of the currently logged in agent
     *
     * @return  TRUE en caso de éxito, FALSE en caso de error.
     *          EN: TRUE on success, FALSE on error.
     */
    function terminarBreak()
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->unpauseagent();
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to stop break').' - '.$this->_formatoErrorECCP($respuesta);
                return FALSE;
            }
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) unpauseagent: '.$e->getMessage();
            return FALSE;
        }
    }

    function transferirLlamada($sTransferExt, $bAtxfer = FALSE)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $bAtxfer
                ? $oECCP->atxfercall($sTransferExt)
                : $oECCP->transfercall($sTransferExt);
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to transfer call').' - '.$this->_formatoErrorECCP($respuesta);
                return FALSE;
            }
            // Return 'consultation' if dialer indicates callback attended transfer
            if ($bAtxfer && isset($respuesta->consultation) && (string)$respuesta->consultation == 'true') {
                return 'consultation';
            }
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) transfercall: '.$e->getMessage();
            return FALSE;
        }
    }

    /**
     * Transfer agent's current call to another logged-in agent.
     * Transfiere la llamada actual del agente a otro agente conectado.
     *
     * @param string $sTargetAgent Target agent number (e.g., 'Agent/9001')
     * @return bool TRUE on success, FALSE on failure (check $this->errMsg)
     */
    function transferirLlamadaAgente($sTargetAgent)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->transfercallagent($sTargetAgent);
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to transfer call to agent').' - '.$this->_formatoErrorECCP($respuesta);
                return FALSE;
            }
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) transfercallagent: '.$e->getMessage();
            return FALSE;
        }
    }

    function leerInfoCampania($sCallType, $iCampaignId)
    {
        if ($sCallType == 'incomingqueue') {
            return array(
                'queue'                     =>  $iCampaignId,
                'type'                      =>  'incoming',
                'startdate'                 =>  NULL,
                'enddate'                   =>  NULL,
                'working_time_starttime'    =>  NULL,
                'working_time_endtime'      =>  NULL,
                'retries'                   =>  NULL,
            );
        } else try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->getcampaigninfo($sCallType, $iCampaignId);
            //var_dump($respuesta);
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to read call information').' - '.$this->_formatoErrorECCP($respuesta);
                return NULL;
            }

            $reporte = array();
            foreach ($respuesta->children() as $xml_node) {
                switch ($xml_node->getName()) {
                case 'forms':
                    $reporte['forms'] = array();
                    foreach ($xml_node->form as $xml_form) {
                        $campos = array();
                        foreach ($xml_form->field as $xml_field) {
                            $descCampo = array(
                                'id'    =>  (int)$xml_field['id'],
                                'order' =>  (int)$xml_field['order'],
                                'label' =>  (string)$xml_field->label,
                                'type'  =>  (string)$xml_field->type,
                                'maxsize'   =>  isset($xml_field->maxsize) ? (int)$xml_field->maxsize : NULL,
                            );
                            if (isset($xml_field->default_value))
                                $descCampo['default_value'] = (string)$xml_field->default_value;
                            if (isset($xml_field->options)) foreach ($xml_field->options->value as $xml_option_value) {
                                $descCampo['options'][] = (string)$xml_option_value;
                            }
                            $campos[(int)$xml_field['order']] = $descCampo;
                        }
                        ksort($campos);
                        $reporte['forms'][(int)$xml_form['id']]['fields'] = $campos;
                        $reporte['forms'][(int)$xml_form['id']]['name'] = (string)$xml_form['name'];
                        $reporte['forms'][(int)$xml_form['id']]['description'] = (string)$xml_form['description'];
                    }
                    break;
                default:
                    $reporte[$xml_node->getName()] = (string)$xml_node;
                    break;
                }
            }
            foreach (array('name', 'type', 'startdate', 'enddate',
                'working_time_starttime', 'working_time_endtime', 'queue',
                'retries', 'context', 'maxchan', 'status', 'script', 'forms',
                'urltemplate', 'urldescription', 'urlopentype',
                'urltemplate2', 'urldescription2', 'urlopentype2',
                'urltemplate3', 'urldescription3', 'urlopentype3') as $k)
                if (!isset($reporte[$k]) || $reporte[$k] == '') $reporte[$k] = NULL;
            return $reporte;
        } catch (Exception $e) {
            $this->errMsg = '(internal) getcampaigninfo: '.$e->getMessage();
            return NULL;
        }
    }

    function leerInfoLlamada($sCallType, $iCampaignId, $iCallId)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->getcallinfo($sCallType, $iCampaignId, $iCallId);
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to read call information').' - '.$this->_formatoErrorECCP($respuesta);
                return NULL;
            }

            $reporte = array();
            foreach ($respuesta->children() as $xml_node) {
                switch ($xml_node->getName()) {
                case 'call_attributes':
                    $reporte['call_attributes'] = $this->_traducirCallAttributes($xml_node);
                    break;
                case 'matching_contacts':
                    $reporte['matching_contacts'] = $this->_traducirMatchingContacts($xml_node);
                    break;
                case 'call_survey':
                    $reporte['call_survey'] = $this->_traducirCallSurvey($xml_node);
                    break;
                default:
                    $reporte[$xml_node->getName()] = (string)$xml_node;
                    break;
                }
            }
            foreach (array('calltype', 'call_id', 'campaign_id', 'phone', 'status',
                'uniqueid', 'datetime_join', 'datetime_linkstart', 'trunk', 'queue',
                'agent_number', 'datetime_originate', 'datetime_originateresponse',
                'retries', 'call_attributes', 'matching_contacts', 'call_survey') as $k)
                if (!isset($reporte[$k])) $reporte[$k] = NULL;
            return $reporte;
        } catch (Exception $e) {
            $this->errMsg = '(internal) getcallinfo: '.$e->getMessage();
            return NULL;
        }
    }

    private function _traducirCallAttributes($xml_node)
    {
        $reporte = array();
        foreach ($xml_node->attribute as $xml_attribute) {
            $reporte[(int)$xml_attribute->order] = array(
                'label' =>  (string)$xml_attribute->label,
                'value' =>  (string)$xml_attribute->value,
            );
        }
        ksort($reporte);
        return $reporte;
    }

    private function _traducirMatchingContacts($xml_node)
    {
        $reporte = array();
        foreach ($xml_node->contact as $xml_contact) {
            $atributos = array();
            foreach ($xml_contact->attribute as $xml_attribute) {
                $atributos[(int)$xml_attribute->order] = array(
                    'label' =>  (string)$xml_attribute->label,
                    'value' =>  (string)$xml_attribute->value,
                );
            }
            ksort($atributos);
            $reporte[(int)$xml_contact['id']] = $atributos;
        }
        return $reporte;
    }

    private function _traducirCallSurvey($xml_node)
    {
        $reporte = array();
        foreach ($xml_node->form as $xml_form) {
            $atributos = array();
            foreach ($xml_form->field as $xml_field) {
                $atributos[(int)$xml_field['id']] = array(
                    'label' =>  (string)$xml_field->label,
                    'value' =>  (string)$xml_field->value,
                );
            }
            ksort($atributos);
            $reporte[(int)$xml_form['id']] = $atributos;
        }
        return $reporte;
    }

    function leerScriptCola($queue)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->getqueuescript($queue);
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to read queue script').' - '.$this->_formatoErrorECCP($respuesta);
                return NULL;
            }
            return (string)$respuesta->script;
        } catch (Exception $e) {
            $this->errMsg = '(internal) getqueuescript: '.$e->getMessage();
            return NULL;
        }
    }

    function confirmarContacto($idCall, $idContact)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->setcontact($idCall, $idContact);
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to set contact').' - '.$this->_formatoErrorECCP($respuesta);
                return FALSE;
            }
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) setcontact: '.$e->getMessage();
            return FALSE;
        }
    }

    function agendarLlamada($schedule, $sameagent, $newphone, $newcontactname,
        $calltype = NULL, $callid = NULL)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->schedulecall($schedule, $sameagent, $newphone,
                $newcontactname, $calltype, $callid);
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to schedule call').' - '.$this->_formatoErrorECCP($respuesta);
                return FALSE;
            }
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) schedulecall: '.$e->getMessage();
            return FALSE;
        }
    }

    function guardarDatosFormularios($sTipoLlamada, $idLlamada, $datosForm)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->saveformdata($sTipoLlamada, $idLlamada, $datosForm);
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to save form data').' - '.$this->_formatoErrorECCP($respuesta);
                return FALSE;
            }
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) saveformdata: '.$e->getMessage();
            return FALSE;
        }
    }

    function esperarEventoSesionActiva()
    {
        $this->errMsg = '';
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $oECCP->wait_response(30);
            $listaEventos = array();
            while ($e = $oECCP->getEvent()) {
                foreach ($e->children() as $ee) $evt = $ee;
                $sNombreEvento = (string)$evt->getName();
                $evento = array(
                    'event' =>  $sNombreEvento,
                );
                if (isset($evt->agent)) $evento['agent_number'] = (string)$evt->agent;
                if (isset($evt->agent_number)) $evento['agent_number'] = (string)$evt->agent_number;
                switch ($sNombreEvento) {
                case 'agentloggedin':
                    // TODO: implementar para consola de monitoreo
                    // EN: TODO: implement for monitoring console
                    break;
                case 'agentfailedlogin':
                    // TODO: implementar para consola de monitoreo
                    // EN: TODO: implement for monitoring console
                    break;
                case 'agentloggedout':
                    // TODO: no se devuelve la lista de colas reportada
                    // EN: TODO: the list of reported queues is not returned
                    break;
                case 'agentlinked':
                    $evento['remote_channel'] = (string)$evt->remote_channel;
                    $evento['status'] = (string)$evt->status;
                    $evento['uniqueid'] = (string)$evt->uniqueid;
                    $evento['datetime_join'] = (string)$evt->datetime_join;
                    $evento['datetime_linkstart'] = (string)$evt->datetime_linkstart;
                    $evento['trunk'] = isset($evt->trunk) ? (string)$evt->trunk : NULL;
                    $evento['retries'] = isset($evt->retries) ? (int)$evt->retries : NULL;
                    $evento['datetime_originateresponse'] = isset($evt->datetime_originateresponse) ? (string)$evt->datetime_originateresponse : NULL;
                    $evento['datetime_originate'] = isset($evt->datetime_originate) ? (string)$evt->datetime_originate : NULL;
                    $evento['call_attributes'] = isset($evt->call_attributes) ? $this->_traducirCallAttributes($evt->call_attributes) : NULL;
                    $evento['matching_contacts'] = isset($evt->matching_contacts) ? $this->_traducirMatchingContacts($evt->matching_contacts) : NULL;
                    $evento['call_survey'] = isset($evt->call_survey) ? $this->_traducirCallSurvey($evt->call_survey) : NULL;
                    // Cae al siguiente caso
                    // EN: Falls through to next case
                case 'agentunlinked':
                    if (isset($evt->datetime_linkend)) $evento['datetime_linkend'] = (string)$evt->datetime_linkend;
                    if (isset($evt->duration)) $evento['duration'] = (int)$evt->duration;
                    if (isset($evt->shortcall)) $evento['shortcall'] = ((int)$evt->shortcall != 0);
                    $evento['queue'] = isset($evt->queue) ? (string)$evt->queue : NULL;
                    $evento['call_type'] = (string)$evt->calltype;
                    $evento['campaign_id'] = isset($evt->campaign_id) ? (int)$evt->campaign_id : NULL;
                    $evento['call_id'] = (int)$evt->call_id;
                    $evento['phone'] = (string)$evt->phone;
                    $evento['campaignlog_id'] = isset($evt->campaignlog_id) ? (int)$evt->campaignlog_id : NULL;
                    break;
                case 'pauseend':
                    $evento['pause_end'] = (string)$evt->pause_end;
                    $evento['pause_duration'] = (int)$evt->pause_duration;
                    // Cae al siguiente caso
                    // EN: Falls through to next case
                case 'pausestart':
                    $evento['pause_class'] = (string)$evt->pause_class;
                    $evento['pause_type'] = isset($evt->pause_type) ? (int)$evt->pause_type : NULL;
                    $evento['pause_name'] = isset($evt->pause_name) ? (string)$evt->pause_name : NULL;
                    $evento['pause_start'] = (string)$evt->pause_start;
                    break;
                case 'callprogress':
                    $evento['id'] = (int)$evt->id;
                    $evento['datetime_entry'] = (string)$evt->datetime_entry;
                    $evento['call_type'] = (string)$evt->campaign_type;
                    $evento['campaign_id'] = isset($evt->campaign_id) ? (int)$evt->campaign_id : NULL;
                    $evento['call_id'] = (int)$evt->call_id;
                    $evento['new_status'] = (string)$evt->new_status;
                    $evento['retry'] = (int)$evt->retry;
                    $evento['uniqueid'] = isset($evt->uniqueid) ? (string)$evt->uniqueid : NULL;
                    $evento['trunk'] = isset($evt->trunk) ? (string)$evt->trunk : NULL;
                    $evento['phone'] = (string)$evt->phone;
                    $evento['queue'] = isset($evt->queue) ? (string)$evt->queue : NULL;
                    break;
                case 'queuemembership':
                    $evento = array_merge($evento, $this->_traducirEstadoAgente($evt));
                    $evento['queues'] = array();
                    foreach ($evt->queues->queue as $xml_q) {
                        $evento['queues'][] = (string)$xml_q;
                    }
                    break;
                case 'agentstatechange':
                    $evento['status'] = (string)$evt->status;
                    $evento['queue'] = (string)$evt->queue;
                    break;
                case 'schedulecallstart':
                case 'schedulecallfailed':
                    foreach (array('agent_number', 'calltype') as $k)
                        $evento[$k] = isset($evt->$k) ? (string) $evt->$k : NULL;
                    foreach (array('campaign_id', 'call_id') as $k)
                        $evento[$k] = isset($evt->$k) ? (int) $evt->$k : NULL;
                    break;
                case 'consultationstart':
                case 'consultationanswered':
                    break;
                case 'consultationend':
                    // DIALSTATUS of the failed consult (BUSY/NOANSWER/...) if
                    // the dialer supplied one, NULL otherwise.
                    $evento['reason'] = isset($evt->reason) ? (string)$evt->reason : NULL;
                    break;
                }
                $listaEventos[] = $evento;
            }
            return $listaEventos;
        } catch (Exception $e) {
            $this->errMsg = '(internal) esperarEventoSesionActiva: '.$e->getMessage();
            return NULL;
        }
    }

    function listarEstadoMonitoreoAgentes()
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuestaResumen = $oECCP->getagentactivitysummary();
            $resumenColas = array();

            // Get list of valid Call Center queues (incoming queues + campaign queues)
            $colasCallCenter = $this->_obtenerColasCallCenter($oECCP);

            // Reunir los agentes involucrados
            // EN: Gather the involved agents
            $agentlist = array();
            foreach ($respuestaResumen->agents->agent as $xml_agent) {
                $agentlist[] = (string)$xml_agent->agentchannel;
            }

            // Listar las colas a la que pertenecen todos los agentes
            // EN: List the queues to which all agents belong
            $pertenenciaColas = $oECCP->getmultipleagentqueues($agentlist);
            $agenteColas = array();
            foreach ($agentlist as $sAgente) $agenteColas[$sAgente] = array();  // array_fill_keys
            foreach ($pertenenciaColas->agents->agent as $xml_queue) {
                $colas = array();
                foreach ($xml_queue->queues->queue as $xml_q) {
                    $sCola = (string)$xml_q;
                    // Only include queues that are configured in Call Center module
                    if (in_array($sCola, $colasCallCenter)) {
                        $colas[] = $sCola;
                    }
                }
                $agenteColas[(string)$xml_queue->agent_number] = $colas;
            }

            // Listar el estado de todos los agentes
            // EN: List the status of all agents
            $estadosAgentes = $oECCP->getmultipleagentstatus($agentlist);
            $agenteEstado = array();
            foreach ($agentlist as $sAgente) $agenteEstado[$sAgente] = array();  // array_fill_keys
            foreach ($estadosAgentes->agents->agent as $xml_agent) {
                $agenteEstado[(string)$xml_agent->agent_number] = $xml_agent;
            }


            foreach ($respuestaResumen->agents->agent as $xml_agent) {
                // Averiguar el estado del agente
                // EN: Find out the agent status
                $estadoAgente = $agenteEstado[(string)$xml_agent->agentchannel];

                // Llenar plantilla con toda la información excepto el número de llamadas por cola
                // EN: Fill template with all information except the number of calls per queue
                $linkstart = isset($estadoAgente->callinfo->linkstart) ? (string)$estadoAgente->callinfo->linkstart : NULL;

                if (!is_null($linkstart) && preg_match('/^\d+:\d+:\d+$/', $linkstart))
                    $linkstart = date('Y-m-d ').$linkstart;
                $infoAgente = array(
                    'agentchannel'          =>  (string)$xml_agent->agentchannel,
                    'agentname'             =>  (string)$xml_agent->agentname,
                    'agentstatus'           =>  (string)$estadoAgente->status,  // offline online oncall paused
                    'logintime'             =>  (int)$xml_agent->logintime,
                    'sec_calls'             =>  0,  // a llenar según la cola // EN: to fill according to queue
                    'num_calls'             =>  0,  // a llenar según la cola // EN: to fill according to queue
                    'lastsessionstart'      =>  isset($xml_agent->lastsessionstart) ? (string)$xml_agent->lastsessionstart : NULL,
                    'lastsessionend'        =>  isset($xml_agent->lastsessionend) ? (string)$xml_agent->lastsessionend : NULL,
                    'lastpausestart'        =>  isset($xml_agent->lastpausestart) ? (string)$xml_agent->lastpausestart : NULL,
                    'lastpauseend'          =>  isset($xml_agent->lastpauseend) ? (string)$xml_agent->lastpauseend : NULL,
                    'linkstart'             =>  NULL,
                    'pausename'             =>  isset($estadoAgente->pauseinfo) ? (string)$estadoAgente->pauseinfo->pausename : NULL,
                    'onhold'                =>  isset($estadoAgente->onhold) ? (bool)(int)$estadoAgente->onhold : false,
                );

                // Averiguar a qué colas pertenece el agente
                // EN: Find out which queues the agent belongs to
                foreach ($agenteColas[(string)$xml_agent->agentchannel] as $xml_queue) {
                    $infoAgenteCola = $infoAgente;
                    if (isset($xml_agent->callsummary->incoming->queue)) {
                        foreach ($xml_agent->callsummary->incoming->queue as $xml_queuestat) {
                            if ((string)$xml_queuestat['id'] == (string)$xml_queue) {
                                $infoAgenteCola['sec_calls'] += (int)$xml_queuestat->sec_calls;
                                $infoAgenteCola['num_calls'] += (int)$xml_queuestat->num_calls;
                            }
                            if (isset($estadoAgente->callinfo->queuenumber) &&
                                (string)$estadoAgente->callinfo->queuenumber == (string)$xml_queuestat['id']) {
                            }
                        }
                    }
                    if (isset($xml_agent->callsummary->outgoing->queue)) {
                        foreach ($xml_agent->callsummary->outgoing->queue as $xml_queuestat) {
                            if ((string)$xml_queuestat['id'] == (string)$xml_queue) {
                                $infoAgenteCola['sec_calls'] += (int)$xml_queuestat->sec_calls;
                                $infoAgenteCola['num_calls'] += (int)$xml_queuestat->num_calls;
                            }
                        }
                    }
                    if (isset($estadoAgente->callinfo->queuenumber) &&
                        (string)$estadoAgente->callinfo->queuenumber == (string)$xml_queue) {
                        $infoAgenteCola['linkstart'] = $linkstart;
                    }
                    $resumenColas[(string)$xml_queue][(string)$xml_agent->agentchannel] = $infoAgenteCola;
                }
            }
            return $resumenColas;
        } catch (Exception $e) {
            $this->errMsg = '(internal) listarEstadoMonitoreoAgentes: '.$e->getMessage();
            return NULL;
        }
    }

    function leerVariablesCanalLlamadaActiva($sAgentNumber = NULL)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->getchanvars($sAgentNumber);
            $chanvars = array();
            foreach ($respuesta->chanvars->chanvar as $xml_chanvar) {
                $chanvars[(string)$xml_chanvar->label] = (string)$xml_chanvar->value;
            }
            return $chanvars;
        } catch (Exception $e) {
            $this->errMsg = '(internal) leerVariablesCanalLlamadaActiva'.$e->getMessage();
            return NULL;
        }
    }

    function leerListaColasEntrantes()
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->getincomingqueuelist();
            $listaColas = array();
            foreach ($respuesta->queues->queue as $xml_queue) {
                $listaColas[(int)$xml_queue->id] = array(
                    'id'        =>  (int)$xml_queue->id,
                    'queue'     =>  (string)$xml_queue->queue,
                    'status'    =>  (string)$xml_queue->status,
                );
            }
            return $listaColas;
        } catch (Exception $e) {
            $this->errMsg = '(internal) '.__METHOD__.': '.$e->getMessage();
            return NULL;
        }
    }

    /**
     * Get all queues configured in Call Center module (incoming queues + campaign queues)
     * Used to filter out PBX-only queues from agent monitoring reports
     */
    private function _obtenerColasCallCenter($oECCP)
    {
        $colasCallCenter = array();

        // Get queues from incoming queue list (queue_call_entry table)
        try {
            $respuesta = $oECCP->getincomingqueuelist();
            if (isset($respuesta->queues->queue)) {
                foreach ($respuesta->queues->queue as $xml_queue) {
                    $colasCallCenter[] = (string)$xml_queue->queue;
                }
            }
        } catch (Exception $e) {
            // Ignore errors, continue with campaigns
        }

        // Get queues from all campaigns (incoming and outgoing)
        try {
            $respuesta = $oECCP->getcampaignlist();
            if (isset($respuesta->campaigns->campaign)) {
                foreach ($respuesta->campaigns->campaign as $xml_campaign) {
                    $sType = (string)$xml_campaign->type;
                    $iId = (int)$xml_campaign->id;
                    try {
                        $infoResp = $oECCP->getcampaigninfo($sType, $iId);
                        if (isset($infoResp->queue)) {
                            $colasCallCenter[] = (string)$infoResp->queue;
                        }
                    } catch (Exception $e) {
                        // Ignore individual campaign errors
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore errors
        }

        return array_unique($colasCallCenter);
    }

    function leerListaCampanias()
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->getcampaignlist();
            $listaCampania = array();
            foreach ($respuesta->campaigns->campaign as $xml_campaign) {
                $listaCampania[] = array(
                    'id'        =>  (int)$xml_campaign->id,
                    'type'      =>  (string)$xml_campaign->type,
                    'name'      =>  (string)$xml_campaign->name,
                    'status'    =>  (string)$xml_campaign->status,
                );
            }
            return $listaCampania;
        } catch (Exception $e) {
            $this->errMsg = '(internal) '.__METHOD__.': '.$e->getMessage();
            return NULL;
        }
    }

    // Leer la hora de la última llamada atendida por cada agente de una campaña.
    // Una sola consulta fija por tipo de campaña; el ID de campaña es el único
    // dato enlazado, así que ningún valor de la petición elige tabla o columna.
    // EN: Read each campaign agent's last answered-call time. One fixed query
    // per campaign type; the campaign ID is the only bound value, so no request
    // value selects a table or column.
    function leerUltimasLlamadasAgentes($campaignType, $campaignId)
    {
        $this->errMsg = '';
        if (!in_array($campaignType, array('incoming', 'outgoing'), TRUE) ||
            !is_int($campaignId) || $campaignId <= 0) {
            $this->errMsg = '(internal) Invalid campaign identity';
            return NULL;
        }

        if ($campaignType == 'incoming') {
            $sql = <<<LAST_CALLS_INCOMING
    SELECT CONCAT(a.type, CHAR(47), a.number) AS agent,
        MAX(ce.datetime_end) AS lastCall,
        ce.id_campaign
    FROM call_entry ce
    JOIN agent a ON ce.id_agent = a.id
    WHERE ce.id_campaign = ?
    GROUP BY ce.id_agent, a.type, a.number, ce.id_campaign
LAST_CALLS_INCOMING;
        } else {
            $sql = <<<LAST_CALLS_OUTGOING
    SELECT CONCAT(a.type, CHAR(47), a.number) AS agent,
        MAX(c.end_time) AS lastCall,
        c.id_campaign
    FROM calls c
    JOIN agent a ON c.id_agent = a.id
    WHERE c.id_campaign = ?
    GROUP BY c.id_agent, a.type, a.number, c.id_campaign
LAST_CALLS_OUTGOING;
        }

        $oDB = $this->_obtenerConexion('call_center');
        $rows = $oDB->fetchTable($sql, TRUE, array($campaignId));
        if (!is_array($rows)) {
            $this->errMsg = '(internal) Unable to read agent last calls - '.$oDB->errMsg;
            return NULL;
        }
        return $rows;
    }

    function leerEstadoCampania($sCallType, $iCampaignId, $datetime_start = NULL)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = ($sCallType == 'incomingqueue')
                ? $oECCP->getincomingqueuestatus($iCampaignId, $datetime_start)
                : $oECCP->getcampaignstatus($sCallType, $iCampaignId, $datetime_start);
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to read campaign information').' - '.$this->_formatoErrorECCP($respuesta);
                return NULL;
            }

            $reporte = array(
                'statuscount'   =>  array(),
                'activecalls'   =>  array(),
                'agents'        =>  array(),
            );
            $iNumLlamadasAtendidas = 0;
            foreach ($respuesta->children() as $xml_node) {
                switch ($xml_node->getName()) {
                case 'statuscount':
                    foreach ($xml_node->children() as $xml_field) {
                        $reporte['statuscount'][$xml_field->getName()] = (int)$xml_field;
                    }
                    ksort($reporte['statuscount']);
                    break;
                case 'stats':
                    foreach ($xml_node->children() as $xml_field) {
                        $reporte['stats'][$xml_field->getName()] = (int)$xml_field;
                    }
                    ksort($reporte['stats']);
                    break;
                case 'agents':
                    foreach ($xml_node->agent as $xml_agent) {
                        $sAgente = (string)$xml_agent->agentchannel;
                        $reporte['agents'][$sAgente] = $this->_traducirEstadoAgente($xml_agent);
                        if (isset($reporte['agents'][$sAgente]['callnumber'])) $iNumLlamadasAtendidas++;
                    }
                    // Don't sort - preserve order from dialer (online first, offline last)
                    break;
                case 'activecalls':
                    foreach ($xml_node->activecall as $xml_activecall) {
                        $idCall = (int)$xml_activecall->callid;
                        $reporte['activecalls'][$idCall] = $this->_traducirEstadoLlamada($xml_activecall);
                    }
                    break;
                }
            }
            if (!isset($reporte['statuscount']['finished'])) {
                $reporte['statuscount']['finished'] = $reporte['statuscount']['success'] - $iNumLlamadasAtendidas;
                ksort($reporte['statuscount']);
            }
            return $reporte;
        } catch (Exception $e) {
            $this->errMsg = '(internal) '.__METHOD__.': '.$e->getMessage();
            return NULL;
        }
    }

    function leerLogCampania($sCallType, $iCampaignId, $lastN = NULL, $idbefore = NULL)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = ($sCallType == 'incomingqueue')
                ? $oECCP->campaignlog('incoming', NULL, $iCampaignId, NULL, NULL, $lastN, $idbefore)
                : $oECCP->campaignlog($sCallType, $iCampaignId, NULL, NULL, NULL, $lastN, $idbefore);
            if (isset($respuesta->failure)) {
                $this->errMsg = _tr('Unable to read campaign log').' - '.$this->_formatoErrorECCP($respuesta);
                return NULL;
            }

            $reporte = array();
            if (isset($respuesta->logentries->logentry)) {
                foreach ($respuesta->logentries->logentry as $xml_logentry) {
                    $logentry = array();
                    foreach (array('id', 'datetime_entry', 'phone', 'queue',
                        'campaign_type', 'campaign_id', 'call_id', 'new_status',
                        'retry', 'uniqueid', 'trunk', 'agentchannel', 'duration') as $k)
                        $logentry[$k] = isset($xml_logentry->$k) ? (string) $xml_logentry->$k : NULL;
                    $reporte[] = $logentry;
                }
            }
            return $reporte;
        } catch (Exception $e) {
            $this->errMsg = '(internal) '.__METHOD__.': '.$e->getMessage();
            return NULL;
        }
    }

    function escucharProgresoLlamada($bHabilitar)
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $respuesta = $oECCP->callprogress($bHabilitar);
        } catch (Exception $e) {
            $this->errMsg = '(internal) '.__METHOD__.': '.$e->getMessage();
        }
    }


    /**
     * Get call statistics for incoming campaigns filtered by datetime range.
     * Queries database directly since ECCP only supports date-level filtering.
     *
     * @param array $campaignIds Array of campaign IDs to include
     * @param string $datetime_start Start of datetime range (YYYY-MM-DD HH:MM:SS)
     * @param string $datetime_end End of datetime range (YYYY-MM-DD HH:MM:SS)
     * @return array Associative array with statuscount and stats
     */
    function getIncomingCallStatsByDatetimeRange($campaignIds, $datetime_start, $datetime_end)
    {
        $result = array(
            'statuscount' => array(
                'total' => 0,
                'success' => 0,
                'abandoned' => 0,
                'finished' => 0,
                'losttrack' => 0,
                'onqueue' => 0,
            ),
            'stats' => array(
                'total_sec' => 0,
                'max_duration' => 0,
            ),
        );

        if (empty($campaignIds)) {
            return $result;
        }

        try {
            $oDB = $this->_obtenerConexion('call_center');

            // Build placeholders for campaign IDs
            $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
            $params = $campaignIds;
            $params[] = $datetime_start;
            $params[] = $datetime_end;

            // Query for ALL status counts including 'activa' and 'en-cola'
            $sql = "SELECT
                        status,
                        COUNT(*) as cnt,
                        SUM(IFNULL(duration, 0)) as total_duration,
                        MAX(IFNULL(duration, 0)) as max_duration
                    FROM call_entry
                    WHERE id_campaign IN ($placeholders)
                    AND datetime_entry_queue >= ?
                    AND datetime_entry_queue <= ?
                    GROUP BY status";

            $recordset = $oDB->fetchTable($sql, TRUE, $params);

            if (!is_array($recordset)) {
                return $result;
            }

            $total = 0;
            $totalSec = 0;
            $maxDuration = 0;

            foreach ($recordset as $row) {
                $status = $row['status'];
                $count = (int)$row['cnt'];
                $total += $count;
                $totalSec += (int)$row['total_duration'];
                if ((int)$row['max_duration'] > $maxDuration) {
                    $maxDuration = (int)$row['max_duration'];
                }

                // Map database status to panel status (inbound uses Spanish status values)
                switch ($status) {
                    case 'terminada':
                        $result['statuscount']['success'] += $count;
                        $result['statuscount']['finished'] += $count;
                        break;
                    case 'abandonada':
                        $result['statuscount']['abandoned'] += $count;
                        break;
                    case 'fin-monitoreo':
                        $result['statuscount']['losttrack'] += $count;
                        break;
                    case 'activa':
                        // Active/connected calls - agent has answered
                        $result['statuscount']['success'] += $count;
                        // Don't add to 'finished' - call is still active
                        break;
                    case 'en-cola':
                        // Queued calls - waiting for agent to answer
                        $result['statuscount']['onqueue'] += $count;
                        break;
                    default:
                        break;
                }
            }

            $result['statuscount']['total'] = $total;
            $result['stats']['total_sec'] = $totalSec;
            $result['stats']['max_duration'] = $maxDuration;

        } catch (Exception $e) {
            // Log exception if needed
        }

        return $result;
    }

    /**
     * Get list of active incoming campaigns for today
     * Used by Incoming Campaigns Panel module
     *
     * @return array|null Array of active campaigns or NULL on error
     */
    function getActiveIncomingCampaigns()
    {
        try {
            $oDB = $this->_obtenerConexion('call_center');
            $today = date('Y-m-d');

            $sql = "SELECT ce.id, ce.name, ce.datetime_init, ce.datetime_end, 
                        ce.daytime_init, ce.daytime_end, qce.queue
                    FROM campaign_entry ce
                    INNER JOIN queue_call_entry qce ON ce.id_queue_call_entry = qce.id
                    WHERE ce.estatus = 'A'
                    AND ce.datetime_init <= ?
                    AND ce.datetime_end >= ?";
            
            $result = $oDB->fetchTable($sql, TRUE, array($today, $today));
            
            if (!is_array($result)) {
                $this->errMsg = 'Failed to query active incoming campaigns: ' . $oDB->errMsg;
                return NULL;
            }

            return $result;

        } catch (Exception $e) {
            $this->errMsg = '(internal) ' . __METHOD__ . ': ' . $e->getMessage();
            return NULL;
        }
    }

    /**
     * Get combined status for all active incoming campaigns
     *
     * @param string $datetime_start Optional datetime filter for stats (full datetime with time)
     * @param string $datetime_end Optional datetime end filter for stats
     * @return array|null Combined status or NULL on error
     */
    function getCombinedIncomingCampaignsStatus($datetime_start = NULL, $datetime_end = NULL)
    {
        $campaigns = $this->getActiveIncomingCampaigns();
        if (!is_array($campaigns)) {
            return NULL;
        }

        // For ECCP calls, we need to pass the earliest date that could have data
        if (!is_null($datetime_start)) {
            $eccp_datetime_start = substr($datetime_start, 0, 10);
        } else {
            $eccp_datetime_start = date('Y-m-d');
        }

        if (count($campaigns) == 0) {
            return array(
                'campaigns' => array(),
                'statuscount' => array(
                    'total' => 0, 'success' => 0, 'abandoned' => 0,
                    'finished' => 0, 'losttrack' => 0, 'onqueue' => 0
                ),
                'stats' => array('total_sec' => 0, 'max_duration' => 0),
                'activecalls' => array(),
                'agents' => array(),
            );
        }

        // Collect campaign IDs for database query
        $campaignIds = array();
        foreach ($campaigns as $camp) {
            $campaignIds[] = $camp['id'];
        }

        // Get stats from database with proper datetime filtering
        $dbStats = $this->getIncomingCallStatsByDatetimeRange($campaignIds, $datetime_start, $datetime_end);

        $combined = array(
            'campaigns' => array(),
            'statuscount' => $dbStats['statuscount'],
            'stats' => $dbStats['stats'],
            'activecalls' => array(),
            'agents' => array(),
        );

        foreach ($campaigns as $camp) {
            $campId = $camp['id'];
            $campName = $camp['name'];
            $queue = $camp['queue'];

            $combined['campaigns'][$campId] = array(
                'id' => $campId,
                'name' => $campName,
                'queue' => $queue,
            );

            // Use ECCP for real-time data (active calls and agents only)
            $status = $this->leerEstadoCampania('incoming', $campId, $eccp_datetime_start);

            if (!is_array($status)) {
                continue;
            }

            // Note: statuscount/stats come from database, not ECCP

            foreach ($status['activecalls'] as $callId => $call) {
                // PHP-layer filtering by datetime range for active calls
                if (!is_null($datetime_start) || !is_null($datetime_end)) {
                    $callStartTime = NULL;
                    if (isset($call['queuestart']) && !is_null($call['queuestart'])) {
                        $callStartTime = $call['queuestart'];
                    } elseif (isset($call['dialstart']) && !is_null($call['dialstart'])) {
                        $callStartTime = $call['dialstart'];
                    }

                    if (!is_null($callStartTime)) {
                        // ECCP returns time-only strings (HH:MM:SS) for today's calls
                        // (date prefix stripped in ECCPConn._agregarCallInfo)
                        // Prepend today's date for proper datetime comparison
                        // ES: ECCP devuelve solo hora (HH:MM:SS) para llamadas de hoy
                        // Anteponer la fecha de hoy para comparacion correcta
                        if (strlen($callStartTime) <= 8 || !preg_match('/^\d{4}-\d{2}-\d{2}/', $callStartTime)) {
                            $callStartTime = date('Y-m-d') . ' ' . $callStartTime;
                        }

                        if (!is_null($datetime_start) && $callStartTime < $datetime_start) {
                            continue;
                        }
                        if (!is_null($datetime_end) && $callStartTime > $datetime_end) {
                            continue;
                        }
                    }
                }

                $call['campaign_id'] = $campId;
                $call['campaign_name'] = $campName;
                $call['queue'] = $queue;
                $combined['activecalls'][$callId] = $call;
            }

            foreach ($status['agents'] as $agentChannel => $agent) {
                if (!isset($combined['agents'][$agentChannel])) {
                    if ($agent['status'] == 'oncall' &&
                        isset($agent['callinfo']['queuenumber']) &&
                        $agent['callinfo']['queuenumber'] == $queue) {
                        $agent['campaign_id'] = $campId;
                        $agent['campaign_name'] = $campName;
                    } else {
                        $agent['campaign_id'] = NULL;
                        $agent['campaign_name'] = '-';
                    }
                    $agent['queue'] = $queue;
                    $combined['agents'][$agentChannel] = $agent;
                } else {
                    if ($agent['status'] == 'oncall' &&
                        isset($agent['callinfo']['queuenumber']) &&
                        $agent['callinfo']['queuenumber'] == $queue) {
                        $combined['agents'][$agentChannel]['campaign_id'] = $campId;
                        $combined['agents'][$agentChannel]['campaign_name'] = $campName;
                    }
                }
            }

        }

        return $combined;
    }

    /**
     * Get all active outgoing campaigns
     *
     * @return array|null Array of active campaigns or NULL on error
     */
    function getActiveOutgoingCampaigns()
    {
        try {
            $oDB = $this->_obtenerConexion('call_center');
            $today = date('Y-m-d');

            // For outgoing campaigns, queue is directly in campaign table
            // Include campaigns within date range that are not inactive ('I')
            $sql = "SELECT id, name, datetime_init, datetime_end,
                        daytime_init, daytime_end, estatus, queue
                    FROM campaign
                    WHERE estatus <> 'I'
                    AND datetime_init <= ?
                    AND datetime_end >= ?";

            $result = $oDB->fetchTable($sql, TRUE, array($today, $today));

            if (!is_array($result)) {
                $this->errMsg = 'Failed to query active outgoing campaigns: ' . $oDB->errMsg;
                return NULL;
            }

            return $result;

        } catch (Exception $e) {
            $this->errMsg = '(internal) ' . __METHOD__ . ': ' . $e->getMessage();
            return NULL;
        }
    }

    /**
     * Get call statistics for outgoing campaigns within a datetime range
     *
     * @param array $campaignIds Array of campaign IDs to include
     * @param string $datetime_start Start of datetime range (YYYY-MM-DD HH:MM:SS)
     * @param string $datetime_end End of datetime range (YYYY-MM-DD HH:MM:SS)
     * @return array Associative array with statuscount and stats
     */
    function getOutgoingCallStatsByDatetimeRange($campaignIds, $datetime_start, $datetime_end)
    {
        $result = array(
            'statuscount' => array(
                'total' => 0,
                'success' => 0,
                'abandoned' => 0,
                'finished' => 0,
                'losttrack' => 0,
                'onqueue' => 0,
            ),
            'stats' => array(
                'total_sec' => 0,
                'max_duration' => 0,
            ),
        );

        if (empty($campaignIds)) {
            return $result;
        }

        try {
            $oDB = $this->_obtenerConexion('call_center');

            // Build placeholders for campaign IDs
            $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
            $params = $campaignIds;
            $params[] = $datetime_start;
            $params[] = $datetime_end;

            // Query for status counts - exclude active/in-progress calls
            // Query for ALL calls (for total) and completed calls (for status counts)
            // Total includes all calls, but success/abandoned only count completed calls (not in current_calls)
            $sql = "SELECT
                        c.status,
                        COUNT(*) as cnt,
                        SUM(CASE WHEN cc.id_call IS NULL THEN 1 ELSE 0 END) as completed_cnt,
                        SUM(IFNULL(c.retries, 0)) as total_retries,
                        SUM(IFNULL(c.duration, 0)) as total_duration,
                        MAX(IFNULL(c.duration, 0)) as max_duration
                    FROM calls c
                    LEFT JOIN current_calls cc ON cc.id_call = c.id
                    WHERE c.id_campaign IN ($placeholders)
                    AND c.fecha_llamada >= ?
                    AND c.fecha_llamada <= ?
                    GROUP BY c.status";

            $recordset = $oDB->fetchTable($sql, TRUE, $params);

            if (!is_array($recordset)) {
                return $result;
            }

            $total = 0;
            $totalRetries = 0;
            $totalSec = 0;
            $maxDuration = 0;

            foreach ($recordset as $row) {
                $status = $row['status'];
                $count = (int)$row['cnt'];
                $completedCount = (int)$row['completed_cnt'];
                $retriesCount = (int)$row['total_retries'];
                $total += $count;
                $totalRetries += $retriesCount;
                $totalSec += (int)$row['total_duration'];
                if ((int)$row['max_duration'] > $maxDuration) {
                    $maxDuration = (int)$row['max_duration'];
                }

                // Map database status to panel status (outgoing uses English status values)
                // retries = total dial attempts made for this record
                // Previous attempts (retries - 1) were abandoned/shortcall before final outcome
                switch ($status) {
                    case 'Success':
                        // Final attempt succeeded
                        $result['statuscount']['success'] += $count;
                        $result['statuscount']['finished'] += $completedCount;
                        // Previous attempts (retries - 1 per record) were abandoned
                        $result['statuscount']['abandoned'] += max(0, $retriesCount - $count);
                        break;
                    case 'Abandoned':
                    case 'ShortCall':
                        // All attempts were abandoned (ShortCall treated as Abandoned per requirement)
                        $result['statuscount']['abandoned'] += $retriesCount;
                        break;
                    case 'NoAnswer':
                    case 'Failure':
                        // Final attempt was losttrack
                        $result['statuscount']['losttrack'] += $completedCount;
                        // Previous attempts (retries - 1 per record) were abandoned
                        $result['statuscount']['abandoned'] += max(0, $retriesCount - $count);
                        break;
                    case 'OnQueue':
                        // Current attempt is queued (counted separately below)
                        // Previous attempts (retries - 1 per record) were abandoned
                        $result['statuscount']['abandoned'] += max(0, $retriesCount - $count);
                        break;
                    default:
                        break;
                }
            }

            // Total = sum of all dial attempts (retries column = attempts per record)
            $result['statuscount']['total'] = $totalRetries;
            $result['stats']['total_sec'] = $totalSec;
            $result['stats']['max_duration'] = $maxDuration;

            // Query for currently queued/waiting calls directly from calls table
            // (current_calls only has records AFTER agent answers, so we query calls table directly)
            $params2 = $campaignIds;
            $params2[] = $datetime_start;
            $params2[] = $datetime_end;

            $sqlQueued = "SELECT COUNT(*) as cnt
                          FROM calls
                          WHERE id_campaign IN ($placeholders)
                          AND fecha_llamada >= ?
                          AND fecha_llamada <= ?
                          AND status = 'OnQueue'";

            $queuedRow = $oDB->getFirstRowQuery($sqlQueued, TRUE, $params2);
            if (is_array($queuedRow) && isset($queuedRow['cnt'])) {
                $result['statuscount']['onqueue'] = (int)$queuedRow['cnt'];
            }

        } catch (Exception $e) {
            // Log exception if needed
        }

        return $result;
    }

    /**
     * Get combined status for all active outgoing campaigns
     *
     * @param string $datetime_start Optional datetime filter for stats (full datetime with time)
     * @param string $datetime_end Optional datetime end filter for stats
     * @return array|null Combined status or NULL on error
     */
    function getCombinedOutgoingCampaignsStatus($datetime_start = NULL, $datetime_end = NULL)
    {
        $campaigns = $this->getActiveOutgoingCampaigns();
        if (!is_array($campaigns)) {
            return NULL;
        }

        // For ECCP calls, we need to pass the earliest date that could have data
        if (!is_null($datetime_start)) {
            $eccp_datetime_start = substr($datetime_start, 0, 10);
        } else {
            $eccp_datetime_start = date('Y-m-d');
        }

        if (count($campaigns) == 0) {
            return array(
                'campaigns' => array(),
                'statuscount' => array(
                    'total' => 0, 'success' => 0, 'abandoned' => 0,
                    'finished' => 0, 'losttrack' => 0, 'onqueue' => 0
                ),
                'stats' => array('total_sec' => 0, 'max_duration' => 0),
                'activecalls' => array(),
                'agents' => array(),
            );
        }

        // Collect campaign IDs for database query
        $campaignIds = array();
        foreach ($campaigns as $camp) {
            $campaignIds[] = $camp['id'];
        }

        // Get stats from database with proper datetime filtering
        $dbStats = $this->getOutgoingCallStatsByDatetimeRange($campaignIds, $datetime_start, $datetime_end);

        $combined = array(
            'campaigns' => array(),
            'statuscount' => $dbStats['statuscount'],
            'stats' => $dbStats['stats'],
            'activecalls' => array(),
            'agents' => array(),
        );

        foreach ($campaigns as $camp) {
            $campId = $camp['id'];
            $campName = $camp['name'];
            $queue = $camp['queue'];

            $combined['campaigns'][$campId] = array(
                'id' => $campId,
                'name' => $campName,
                'queue' => $queue,
            );

            // Use ECCP for real-time data (active calls and agents only)
            $status = $this->leerEstadoCampania('outgoing', $campId, $eccp_datetime_start);

            if (!is_array($status)) {
                continue;
            }

            // Note: statuscount/stats come from database, not ECCP

            foreach ($status['activecalls'] as $callId => $call) {
                // PHP-layer filtering by datetime range for active calls
                if (!is_null($datetime_start) || !is_null($datetime_end)) {
                    $callStartTime = NULL;
                    if (isset($call['queuestart']) && !is_null($call['queuestart'])) {
                        $callStartTime = $call['queuestart'];
                    } elseif (isset($call['dialstart']) && !is_null($call['dialstart'])) {
                        $callStartTime = $call['dialstart'];
                    }

                    if (!is_null($callStartTime)) {
                        // ECCP returns time-only strings (HH:MM:SS) for today's calls
                        // (date prefix stripped in ECCPConn._agregarCallInfo)
                        // Prepend today's date for proper datetime comparison
                        // ES: ECCP devuelve solo hora (HH:MM:SS) para llamadas de hoy
                        // Anteponer la fecha de hoy para comparacion correcta
                        if (strlen($callStartTime) <= 8 || !preg_match('/^\d{4}-\d{2}-\d{2}/', $callStartTime)) {
                            $callStartTime = date('Y-m-d') . ' ' . $callStartTime;
                        }

                        if (!is_null($datetime_start) && $callStartTime < $datetime_start) {
                            continue;
                        }
                        if (!is_null($datetime_end) && $callStartTime > $datetime_end) {
                            continue;
                        }
                    }
                }

                $call['campaign_id'] = $campId;
                $call['campaign_name'] = $campName;
                $call['queue'] = $queue;
                $combined['activecalls'][$callId] = $call;
            }

            foreach ($status['agents'] as $agentChannel => $agent) {
                if (!isset($combined['agents'][$agentChannel])) {
                    if ($agent['status'] == 'oncall' &&
                        isset($agent['callinfo']['queuenumber']) &&
                        $agent['callinfo']['queuenumber'] == $queue) {
                        $agent['campaign_id'] = $campId;
                        $agent['campaign_name'] = $campName;
                    } else {
                        $agent['campaign_id'] = NULL;
                        $agent['campaign_name'] = '-';
                    }
                    $agent['queue'] = $queue;
                    $combined['agents'][$agentChannel] = $agent;
                } else {
                    if ($agent['status'] == 'oncall' &&
                        isset($agent['callinfo']['queuenumber']) &&
                        $agent['callinfo']['queuenumber'] == $queue) {
                        $combined['agents'][$agentChannel]['campaign_id'] = $campId;
                        $combined['agents'][$agentChannel]['campaign_name'] = $campName;
                    }
                }
            }

        }

        return $combined;
    }


    function pingAgente()
    {
        try {
            $oECCP = $this->_obtenerConexion('ECCP');
            $oECCP->pingagent();
            return TRUE;
        } catch (Exception $e) {
            $this->errMsg = '(internal) pingagent: '.$e->getMessage();
            return FALSE;
        }
    }

}

?>
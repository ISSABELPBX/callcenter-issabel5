<?php

/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 0.5                                                  |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2007 Palosanto Solutions S. A.                         |
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
  $Id: Agentes.class.php,v  $ */
if (file_exists("/var/lib/asterisk/agi-bin/phpagi-asmanager.php")) {
    include_once "/var/lib/asterisk/agi-bin/phpagi-asmanager.php";
} elseif (file_exists('libs/phpagi-asmanager.php')) {
	include_once 'libs/phpagi-asmanager.php';
} else {
	die('Unable to find phpagi-asmanager.php');
}
include_once("libs/paloSantoDB.class.php");
require_once "modules/agent_console/libs/issabel2.lib.php";

class Agentes
{
    private $AGENT_FILE;
    var $arrAgents;
    private $_DB; // instancia de la clase paloDB // EN: instance of paloDB class
    var $errMsg;
    private $_detectedChanAgent = NULL; // cached: TRUE if Asterisk 11 (chan_agent)

    function __construct(&$pDB, $file = "/etc/asterisk/agents.conf")
    {
        // Se recibe como parámetro una referencia a una conexión paloDB
        // EN: Receives as parameter a reference to a paloDB connection
        if (is_object($pDB)) {
            $this->_DB =& $pDB;
            $this->errMsg = $this->_DB->errMsg;
        } else {
            $dsn = (string)$pDB;
            $this->_DB = new paloDB($dsn);

            if (!$this->_DB->connStatus) {
                $this->errMsg = $this->_DB->errMsg;
                // debo llenar alguna variable de error
                // EN: must fill some error variable
            } else {
                // debo llenar alguna variable de error
                // EN: must fill some error variable
            }
        }

        $this->arrAgents = array();
        $this->AGENT_FILE=$file;
    }

    /**
     * Procedimiento para consultar los agentes estáticos que existen en la
     * base de datos de CallCenter. Opcionalmente, se puede consultar un solo
     * agente específico.
     *
     * EN: Procedure to query the static agents that exist in the CallCenter
     * database. Optionally, a single specific agent can be queried.
     *
     * @param   int     $id     Número de agente asignado
     *                          EN: Assigned agent number
     *
     * @return  NULL en caso de error
     *          Si $id es NULL, devuelve una matriz de las columnas conocidas:
     *              id number name password estatus eccp_password
     *          Si $id no es NULL y agente existe, se devuelve una sola tupla
     *          con la estructura de las columnas indicada anteriormente.
     *          Si $id no es NULL y agente no existe, se devuelve arreglo vacío.
     *          EN: NULL on error. If $id is NULL, returns a matrix of known columns:
     *              id number name password estatus eccp_password. If $id is not NULL
     *              and agent exists, a single tuple is returned with the structure
     *              of the columns indicated above. If $id is not NULL and agent
     *              does not exist, an empty array is returned.
     */
    function getAgents($id=null)
    {
        // CONSULTA DE LA BASE DE DATOS LA INFORMACIÓN DE LOS AGENTES
        // EN: QUERY THE DATABASE FOR AGENT INFORMATION
        $paramQuery = array(); $where = array("type = 'Agent'", "estatus = 'A'"); $sWhere = '';
        if (!is_null($id)) {
        	$paramQuery[] = $id;
            $where[] = 'number = ?';
        }
        if (count($where) > 0) $sWhere = 'WHERE '.join(' AND ', $where);
        $sQuery = 
            "SELECT id, number, name, password, estatus, eccp_password ".
            "FROM agent $sWhere ORDER BY number";
        $arr_result =& $this->_DB->fetchTable($sQuery, true, $paramQuery);
        if (is_array($arr_result)) {
            if (is_null($id) || count($arr_result) <= 0) {
                return $arr_result;
            } else {
                return $arr_result[0];
            }
        } else {
            $this->errMsg = 'Unable to read agent information - '.$this->_DB->errMsg;
            return NULL;
        }
    }


    function existAgent($agent)
    {
        $this->_read_agents();
        foreach ($this->arrAgents as $agente){
            if ($agente[0] == $agent)
                return $agente;
        }
        return false;
    }

    function getAgentsFile()
    {
        $this->_read_agents();
        return array_keys($this->arrAgents);
    }

    /**
     * Procedimiento para agregar un nuevo agente estático a la base de datos
     * de CallCenter y al archivo agents.conf de Asterisk.
     *
     * EN: Procedure to add a new static agent to the CallCenter database
     * and to the Asterisk agents.conf file.
     *
     * @param   array   $agent  Información del agente con las posiciones:
     *                          EN: Agent information with positions:
     *                  0   =>  Número del agente a crear
     *                          EN: Number of the agent to create
     *                  1   =>  Contraseña telefónica del agente
     *                          EN: Telephone password of the agent
     *                  2   =>  Nombre descriptivo del agente
     *                          EN: Descriptive name of the agent
     *                  3   =>  Contraseña para login de ECCP
     *                          EN: Password for ECCP login
     *
     * @return  bool    VERDADERO si se inserta correctamente agente, FALSO si no.
     *                  EN: TRUE if agent is inserted correctly, FALSE otherwise.
     */
    function addAgent($agent)
    {
        if (!is_array($agent) || count($agent) < 3) {
            $this->errMsg = 'Invalid agent data';
            return FALSE;
        }

        $tupla = $this->_DB->getFirstRowQuery(
            'SELECT COUNT(*) FROM agent WHERE estatus = "A" AND number = ?',
            FALSE, array($agent[0]));
        if ($tupla[0] > 0) {
            $this->errMsg = _tr('Agent already exists');
            return FALSE;
        }
        
        /* Se debe de autogenerar una contraseña ECCP si no se especifica.
         * La contraseña será legible por la nueva consola de agente
         * EN: An ECCP password must be auto-generated if not specified.
         * The password will be readable by the new agent console */
        if (!isset($agent[3]) || $agent[3] == '') $agent[3] = sha1(time().rand());

        // GRABAR EN BASE DE DATOS
        // EN: SAVE TO DATABASE
        $sPeticionSQL = 'INSERT INTO agent (number, password, name, eccp_password) VALUES (?, ?, ?, ?)';
        $paramSQL = array($agent[0], $agent[1], $agent[2], $agent[3]);
        
        $this->_DB->genQuery("SET AUTOCOMMIT = 0");
        $result = $this->_DB->genQuery($sPeticionSQL, $paramSQL);

        if (!$result) {
            $this->errMsg = $this->_DB->errMsg;
            $this->_DB->genQuery("ROLLBACK");
            $this->_DB->genQuery("SET AUTOCOMMIT = 1");
            return false;
        }

        $resp = $this->addAgentFile($agent);
        if ($resp) {
            $this->_DB->genQuery("COMMIT");
        } else {
            $this->_DB->genQuery("ROLLBACK");
        }
        $this->_DB->genQuery("SET AUTOCOMMIT = 1");
        if ($resp) $this->_refreshDialerAgentList();
        return $resp;
    }

    /**
     * Procedimiento para modificar un agente estático exitente en la base de
     * datos de CallCenter y en el archivo agents.conf de Asterisk.
     *
     * EN: Procedure to modify an existing static agent in the CallCenter
     * database and in the Asterisk agents.conf file.
     *
     * @param   array   $agent  Información del agente con las posiciones:
     *                          EN: Agent information with positions:
     *                  0   =>  Número del agente a crear
     *                          EN: Number of the agent to create
     *                  1   =>  Contraseña telefónica del agente
     *                          EN: Telephone password of the agent
     *                  2   =>  Nombre descriptivo del agente
     *                          EN: Descriptive name of the agent
     *                  3   =>  Contraseña para login de ECCP
     *                          EN: Password for ECCP login
     *
     * @return  bool    VERDADERO si se inserta correctamente agente, FALSO si no.
     *                  EN: TRUE if agent is inserted correctly, FALSE otherwise.
     */
    function editAgent($agent)
    {
        if (!is_array($agent) || count($agent) < 3) {
            $this->errMsg = 'Invalid agent data';
            return FALSE;
        }

        // Verificar que el agente referenciado existe
        // EN: Verify that the referenced agent exists
        $tupla = $this->_DB->getFirstRowQuery(
            'SELECT COUNT(*) FROM agent WHERE estatus = "A" AND number = ?',
            FALSE, array($agent[0]));
        if ($tupla[0] <= 0) {
            $this->errMsg = _tr('Agent not found');
            return FALSE;
        }        

        // Asumir ninguna contraseña de ECCP (agente no será usable por ECCP)
        // EN: Assume no ECCP password (agent will not be usable by ECCP)
        if (!isset($agent[3]) || $agent[3] == '') $agent[3] = NULL;

        // EDITAR EN BASE DE DATOS
        // EN: EDIT IN DATABASE
        $sPeticionSQL = 'UPDATE agent SET password = ?, name = ?';
        $paramSQL = array($agent[1], $agent[2]);
        if (!is_null($agent[3])) {
        	$sPeticionSQL .= ', eccp_password = ?';
            $paramSQL[] = $agent[3];
        }
        $sPeticionSQL .= ' WHERE number = ?';
        $paramSQL[] = $agent[0];

        $this->_DB->genQuery("SET AUTOCOMMIT = 0");
        $result = $this->_DB->genQuery($sPeticionSQL, $paramSQL);
        if (!$result) {
            $this->errMsg = $this->_DB->errMsg;
            $this->_DB->genQuery("ROLLBACK");
            $this->_DB->genQuery("SET AUTOCOMMIT = 1");
            return false;
        }

        /* Se debe de autogenerar una contraseña ECCP si no se especifica.
         * La contraseña será legible por la nueva consola de agente
         * EN: An ECCP password must be auto-generated if not specified.
         * The password will be readable by the new agent console */
        if (is_null($agent[3])) {
            $agent[3] = sha1(time().rand());
            $sPeticionSQL = 'UPDATE agent SET eccp_password = ? WHERE number = ? AND eccp_password IS NULL';
            $paramSQL = array($agent[3], $agent[0]);
            $result = $this->_DB->genQuery($sPeticionSQL, $paramSQL);
            if (!$result) {
                $this->errMsg = $this->_DB->errMsg;
                $this->_DB->genQuery("ROLLBACK");
                $this->_DB->genQuery("SET AUTOCOMMIT = 1");
                return false;
            }
        }

        // Update agent in agents.conf (format depends on Asterisk version)
        $bExito = TRUE;
        $bChanAgent = $this->_hasChanAgent();
        $contenido = file($this->AGENT_FILE);
        if (!is_array($contenido)) {
            $bExito = FALSE;
            $this->errMsg = '(internal) Unable to read agent file';
        } else {
            $agentId = $agent[0];
            $agentPass = isset($agent[1]) ? $agent[1] : '';
            $agentName = $agent[2];
            $bModificado = FALSE;
            $bEnSeccionAgente = FALSE;
            $contenidoNuevo = array();

            foreach ($contenido as $sLinea) {
                // Check if this is the start of the agent's section (with or without template)
                if (preg_match('/^\[' . preg_quote($agentId, '/') . '\](\(agent-defaults\))?/', trim($sLinea))) {
                    $bEnSeccionAgente = TRUE;
                    $bModificado = TRUE;
                    if ($bChanAgent) {
                        // chan_agent: password used by Asterisk to authenticate AgentLogin
                        $contenidoNuevo[] = "agent => {$agentId},{$agentPass},{$agentName}\n";
                    } else {
                        $contenidoNuevo[] = "[{$agentId}](agent-defaults)\n";
                        $contenidoNuevo[] = "fullname={$agentName}\n";
                    }
                    continue;
                }

                // If we're in the agent section, skip until we hit a new section
                if ($bEnSeccionAgente) {
                    if (preg_match('/^\[[^\]]+\]/', trim($sLinea))) {
                        $bEnSeccionAgente = FALSE;
                        $contenidoNuevo[] = $sLinea;
                    }
                    continue;
                }

                // Also handle old chan_agent format
                if (preg_match('/^[[:space:]]*agent[[:space:]]*=>[[:space:]]*' . preg_quote($agentId, '/') . ',/', $sLinea)) {
                    $bModificado = TRUE;
                    if ($bChanAgent) {
                        $contenidoNuevo[] = "agent => {$agentId},{$agentPass},{$agentName}\n";
                    } else {
                        $contenidoNuevo[] = "\n[{$agentId}](agent-defaults)\n";
                        $contenidoNuevo[] = "fullname={$agentName}\n";
                    }
                    continue;
                }

                $contenidoNuevo[] = $sLinea;
            }

            // If agent wasn't found, add it
            if (!$bModificado) {
                if ($bChanAgent) {
                    $contenidoNuevo[] = "\nagent => {$agentId},{$agentPass},{$agentName}\n";
                } else {
                    $this->_ensureAgentDefaultsTemplate();
                    $contenidoNuevo[] = "\n[{$agentId}](agent-defaults)\n";
                    $contenidoNuevo[] = "fullname={$agentName}\n";
                }
            }

            $hArchivo = fopen($this->AGENT_FILE, 'w');
            if (!$hArchivo) {
                $bExito = FALSE;
                $this->errMsg = '(internal) Unable to write agent file';
            } else {
                foreach ($contenidoNuevo as $sLinea) fwrite($hArchivo, $sLinea);
                fclose($hArchivo);
            }
        }
        
        if ($bExito) {
            $this->_DB->genQuery("COMMIT");
            $this->_DB->genQuery("SET AUTOCOMMIT = 1");

            $this->_refreshDialerAgentList();
            return $this->_reloadAsterisk();
        } else {
            $this->_DB->genQuery("ROLLBACK");
            $this->_DB->genQuery("SET AUTOCOMMIT = 1");
            return FALSE;
        }
    }

    function deleteAgent($id_agent)
    {
        if (!preg_match('/^[[:digit:]]+$/', $id_agent)) {
            $this->errMsg = '(internal) Invalid agent information';
            return FALSE;
        }

        // BORRAR EN BASE DE DATOS
        // EN: DELETE IN DATABASE

        $sPeticionSQL = "UPDATE agent SET estatus='I' WHERE number=$id_agent";

        $this->_DB->genQuery("SET AUTOCOMMIT = 0");
        $result = $this->_DB->genQuery($sPeticionSQL);
        if (!$result) {
            $this->errMsg = $this->_DB->errMsg;
            $this->_DB->genQuery("ROLLBACK");
            $this->_DB->genQuery("SET AUTOCOMMIT = 1");
            return false;
        }

        $resp = $this->deleteAgentFile($id_agent);
        if ($resp) {
            $this->_DB->genQuery("COMMIT");
        } else {
            $this->_DB->genQuery("ROLLBACK");
        }
        $this->_DB->genQuery("SET AUTOCOMMIT = 1");
        if ($resp) $this->_refreshDialerAgentList();

        return $resp;
    }

    /**
     * Add a static agent to agents.conf and reload Asterisk.
     * Format is version-dependent:
     *   chan_agent (Ast 11):      agent => number,,name
     *   app_agent_pool (Ast 12+): [number](agent-defaults) / fullname=name
     *
     * @param   array   $agent  Agent info: [0]=number, [1]=password (ignored), [2]=name
     * @return  bool    TRUE on success, FALSE on error
     */
    function addAgentFile($agent)
    {
        if (!is_array($agent) || count($agent) < 3) {
            $this->errMsg = '(internal) Invalid agent information';
            return FALSE;
        }

        $archivo = $this->AGENT_FILE;
        $agentId = $agent[0];
        $agentName = $agent[2];

        // Check if agent already exists in either format
        $contenido = file($archivo);
        if (is_array($contenido)) {
            foreach ($contenido as $sLinea) {
                if (preg_match('/^\[' . preg_quote($agentId, '/') . '\]/', trim($sLinea)) ||
                    preg_match('/^[[:space:]]*agent[[:space:]]*=>[[:space:]]*' . preg_quote($agentId, '/') . ',/', $sLinea)) {
                    $this->errMsg = "Agent number already exists.";
                    return FALSE;
                }
            }
        }

        if ($this->_hasChanAgent()) {
            // chan_agent format: agent => number,password,name
            // Password is used by Asterisk to authenticate AgentLogin
            $agentPass = isset($agent[1]) ? $agent[1] : '';
            $nuevo_agente = "\nagent => {$agentId},{$agentPass},{$agentName}\n";
        } else {
            // app_agent_pool format with template inheritance
            $this->_ensureAgentDefaultsTemplate();
            $nuevo_agente = "\n[{$agentId}](agent-defaults)\n";
            $nuevo_agente .= "fullname={$agentName}\n";
        }

        // Append to file
        $open = fopen($archivo, "a");
        if (!$open) {
            $this->errMsg = '(internal) Unable to open agent file for writing';
            return FALSE;
        }
        fwrite($open, $nuevo_agente);
        fclose($open);

        return $this->_reloadAsterisk();
    }

    /**
     * Delete agent from agents.conf (app_agent_pool format)
     * Removes the entire [agent-id] section and all its settings
     */
    function deleteAgentFile($id_agent)
    {
        if (!preg_match('/^[[:digit:]]+$/', $id_agent)) {
            $this->errMsg = '(internal) Invalid agent ID';
            return FALSE;
        }

        $bExito = TRUE;
        $contenido = file($this->AGENT_FILE);
        if (!is_array($contenido)) {
            $bExito = FALSE;
            $this->errMsg = '(internal) Unable to read agent file';
        } else {
            $bModificado = FALSE;
            $contenidoNuevo = array();
            $bEnSeccionAgente = FALSE;

            foreach ($contenido as $sLinea) {
                // Check if this is the start of the agent's section [agent-id]
                if (preg_match('/^\[' . preg_quote($id_agent, '/') . '\]/', trim($sLinea))) {
                    $bEnSeccionAgente = TRUE;
                    $bModificado = TRUE;
                    continue; // Skip this line
                }

                // Check if we've reached a new section (end of agent's section)
                if ($bEnSeccionAgente && preg_match('/^\[[^\]]+\]/', trim($sLinea))) {
                    $bEnSeccionAgente = FALSE;
                }

                // Also handle old chan_agent format: agent => number,password,name
                if (preg_match('/^[[:space:]]*agent[[:space:]]*=>[[:space:]]*' . preg_quote($id_agent, '/') . ',/', $sLinea)) {
                    $bModificado = TRUE;
                    continue; // Skip this line
                }

                // Keep lines that are not part of the deleted agent's section
                if (!$bEnSeccionAgente) {
                    $contenidoNuevo[] = $sLinea;
                }
            }

            if ($bModificado) {
                $hArchivo = fopen($this->AGENT_FILE, 'w');
                if (!$hArchivo) {
                    $bExito = FALSE;
                    $this->errMsg = '(internal) Unable to write agent file';
                } else {
                    foreach ($contenidoNuevo as $sLinea) fwrite($hArchivo, $sLinea);
                    fclose($hArchivo);
                }
            }
        }

        return $this->_reloadAsterisk();
    }

    /**
     * Read agents from agents.conf - supports both app_agent_pool format and legacy chan_agent format
     */
    private function _read_agents()
    {
        $contenido = file($this->AGENT_FILE);
        if (!is_array($contenido)) {
            $this->errMsg = '(internal) Unable to read agent file';
            return;
        }

        $this->arrAgents = array();
        $currentAgentId = NULL;
        $currentAgentName = '';

        foreach ($contenido as $sLinea) {
            $sLinea = trim($sLinea);

            // app_agent_pool format: [agent-id] or [agent-id](template) section
            if (preg_match('/^\[([0-9]+)\](\(agent-defaults\))?$/', $sLinea, $regs)) {
                // Save previous agent if exists
                if ($currentAgentId !== NULL) {
                    $this->arrAgents[$currentAgentId] = array($currentAgentId, '', $currentAgentName);
                }
                $currentAgentId = $regs[1];
                $currentAgentName = '';
                continue;
            }

            // Parse fullname within section
            if ($currentAgentId !== NULL && preg_match('/^fullname\s*=\s*(.*)$/', $sLinea, $regs)) {
                $currentAgentName = $regs[1];
                continue;
            }

            // Check for new section (non-agent) - save current agent and reset
            if ($currentAgentId !== NULL && preg_match('/^\[[^\]]+\]$/', $sLinea)) {
                $this->arrAgents[$currentAgentId] = array($currentAgentId, '', $currentAgentName);
                $currentAgentId = NULL;
                $currentAgentName = '';
                continue;
            }

            // Legacy chan_agent format: agent => number,password,name
            if (preg_match('/^agent\s*=>\s*([0-9]+),([^,]*),(.*)$/', $sLinea, $regs)) {
                $this->arrAgents[$regs[1]] = array($regs[1], $regs[2], $regs[3]);
            }
        }

        // Don't forget the last agent if file doesn't end with another section
        if ($currentAgentId !== NULL) {
            $this->arrAgents[$currentAgentId] = array($currentAgentId, '', $currentAgentName);
        }
    }

    /**
     * Ensure [agent-defaults] template exists in agents.conf (app_agent_pool only).
     * Skipped for chan_agent (Asterisk 11) which has no template concept.
     */
    private function _ensureAgentDefaultsTemplate()
    {
        if ($this->_hasChanAgent()) return TRUE;
        $contenido = file($this->AGENT_FILE);
        if (!is_array($contenido)) {
            return FALSE;
        }

        // Check if template already exists
        foreach ($contenido as $sLinea) {
            if (preg_match('/^\[agent-defaults\]\(!\)/', trim($sLinea))) {
                return TRUE; // Template already exists
            }
        }

        // Template doesn't exist, create it after [general] section
        $contenidoNuevo = array();
        $bInsertado = FALSE;

        foreach ($contenido as $sLinea) {
            $contenidoNuevo[] = $sLinea;

            // Insert template after [general] section content (before next section)
            if (!$bInsertado && preg_match('/^\[general\]/', trim($sLinea))) {
                // Find the end of [general] section comments
                continue;
            }

            // If we haven't inserted yet and we hit a non-comment line after [general]
            // or we hit another section, insert the template
            if (!$bInsertado && preg_match('/^\[[^\]]+\]/', trim($sLinea)) && !preg_match('/^\[general\]/', trim($sLinea))) {
                // Insert before this section
                array_pop($contenidoNuevo); // Remove the section line we just added
                $contenidoNuevo[] = "\n; Agent defaults template - inherited by all agents\n";
                $contenidoNuevo[] = "[agent-defaults](!)\n";
                $contenidoNuevo[] = "musiconhold=Silence\n";
                $contenidoNuevo[] = "ackcall=no\n";
                $contenidoNuevo[] = "autologoff=0\n";
                $contenidoNuevo[] = "wrapuptime=0\n";
                $contenidoNuevo[] = "\n";
                $contenidoNuevo[] = $sLinea; // Add back the section line
                $bInsertado = TRUE;
            }
        }

        // If no other section found, append at end
        if (!$bInsertado) {
            $contenidoNuevo[] = "\n; Agent defaults template - inherited by all agents\n";
            $contenidoNuevo[] = "[agent-defaults](!)\n";
            $contenidoNuevo[] = "musiconhold=Silence\n";
            $contenidoNuevo[] = "ackcall=no\n";
            $contenidoNuevo[] = "autologoff=0\n";
            $contenidoNuevo[] = "wrapuptime=0\n";
        }

        $hArchivo = fopen($this->AGENT_FILE, 'w');
        if (!$hArchivo) {
            return FALSE;
        }
        foreach ($contenidoNuevo as $sLinea) fwrite($hArchivo, $sLinea);
        fclose($hArchivo);

        return TRUE;
    }

    private function _get_AGI_AsteriskManager()
    {
        $ip_asterisk = '127.0.0.1';
        $user_asterisk = 'admin';
        $pass_asterisk = function_exists('obtenerClaveAMIAdmin') ? obtenerClaveAMIAdmin() : 'issabel789';
        $astman = new AGI_AsteriskManager();
        if (!$astman->connect($ip_asterisk, $user_asterisk , $pass_asterisk)) {
            $this->errMsg = "Error when connecting to Asterisk Manager";
            return NULL;
        } else {
            return $astman;
        }
    }

    /**
     * Detect if Asterisk uses chan_agent (Asterisk 11) or app_agent_pool (Asterisk 12+).
     * Result is cached after first detection.
     */
    private function _hasChanAgent()
    {
        if ($this->_detectedChanAgent !== NULL) {
            return $this->_detectedChanAgent;
        }
        $astman = $this->_get_AGI_AsteriskManager();
        if (is_null($astman)) {
            $this->_detectedChanAgent = FALSE; // default to app_agent_pool
            return FALSE;
        }
        $r = $astman->Command('core show version');
        $astman->disconnect();
        $version = isset($r['data']) ? $r['data'] : '';
        if (preg_match('/Asterisk (\d+)/', $version, $m)) {
            $major = (int)$m[1];
            $this->_detectedChanAgent = ($major < 12);
        } else {
            $this->_detectedChanAgent = FALSE;
        }
        return $this->_detectedChanAgent;
    }

    /**
     * Reload Asterisk agent module after agents.conf changes
     */
    private function _reloadAsterisk()
    {
        $astman = $this->_get_AGI_AsteriskManager();
        if (is_null($astman)) {
            return FALSE;
        } else {
            $module = $this->_hasChanAgent() ? 'chan_agent.so' : 'app_agent_pool.so';
            $strReload = $astman->Command("module reload $module");
            $astman->disconnect();
            return TRUE;
        }
    }

    /* Pedir al dialer que relea su lista de agentes ahora mismo, en vez de
     * esperar su temporizador de 5 minutos. Falla en silencio: el guardado
     * ya ocurrió y el temporizador del dialer converge de todos modos.
     * EN: Ask the dialer to re-read its agent list right now, instead of
     * waiting for its 5-minute timer. Fails quietly: the save already
     * happened and the dialer's timer converges anyway. */
    private function _refreshDialerAgentList()
    {
        try {
            require_once 'modules/agent_console/libs/ECCP.class.php';
            $sUsernameECCP = 'agentconsole';
            /* Contraseña aleatoria por instalación: nadie necesita el texto
             * claro, porque el hash mismo se inserta y se envía, y
             * Request_login lo acepta (md5_password = ?).
             * EN: Random password per install: nobody needs the plaintext,
             * because the hash itself is inserted and sent, and Request_login
             * accepts it (md5_password = ?). */
            $sPasswordECCP = md5(random_bytes(32));
            $md5_passwd = $this->_DB->getFirstRowQuery(
                'SELECT md5_password FROM eccp_authorized_clients WHERE username = ?',
                TRUE, array($sUsernameECCP));
            if (is_array($md5_passwd)) {
                if (count($md5_passwd) <= 0) {
                    $this->_DB->genQuery(
                        'INSERT INTO eccp_authorized_clients (username, md5_password) VALUES(?, ?)',
                        array($sUsernameECCP, $sPasswordECCP));
                } else {
                    $sPasswordECCP = $md5_passwd['md5_password'];
                }
            }
            $oECCP = new ECCP();
            $cr = $oECCP->connect('localhost', $sUsernameECCP, $sPasswordECCP);
            if (isset($cr->failure)) {
                _cc_debug('ECCP refreshagents refused: '.(string)$cr->failure->message, 'agents');
                return FALSE;
            }
            $oECCP->refreshagents();
            $oECCP->disconnect();
            return TRUE;
        } catch (Exception $e) {
            _cc_debug('ECCP refreshagents failed: '.get_class($e).': '.$e->getMessage(), 'agents');
            return FALSE;
        }
    }

    function getOnlineAgents()
    {
        $astman = $this->_get_AGI_AsteriskManager();
        if (is_null($astman)) {
            return NULL;
        } else {
            $strAgentsOnline = $astman->Command("agent show online");
            $astman->disconnect();
            $data = $strAgentsOnline['data'];
            $lineas = explode("\n", $data);
            $listaAgentes = array();

            foreach ($lineas as $sLinea) {
                // El primer número de la línea es el ID del agente a recuperar
                $regs = NULL;
                if (strpos($sLinea, 'agents online') === FALSE &&
                    preg_match('/^([[:digit:]]+)[[:space:]]*/', $sLinea, $regs)) {
                    $listaAgentes[] = $regs[1];
                }
            }
            return $listaAgentes;
        }
    }

    function isAgentOnline($agentNum)
    {
        $astman = $this->_get_AGI_AsteriskManager();
        if (is_null($astman)) {
            return FALSE;
        } else {
            $strAgentsOnline = $astman->Command("agent show online");
            $astman->disconnect();
            $data = $strAgentsOnline['data'];
            $res = explode($agentNum,$data);
            if(is_array($res) && count($res)==2) {
                return true;
            }
            return false;
        }
    }

    function desconectarAgentes($arrAgentes)
    {
        $this->errMsg = NULL;

        if (!(is_array($arrAgentes) && count($arrAgentes) > 0)) {
            $this->errMsg = "Lista de agentes no válida"; // EN: Invalid agent list
            return FALSE;
        }

        $astman = $this->_get_AGI_AsteriskManager();
        if (is_null($astman)) {
            return FALSE;
        }

        for ($i =0 ; $i < count($arrAgentes) ; $i++) {
            $res = $this->Agentlogoff($astman, $arrAgentes[$i]);
            if ($res['Response']=='Error') {
                $this->errMsg = "Error logoff ".$res['Message'];
                $astman->disconnect();
                return false;
            }
        }
        $astman->disconnect();
        return true;
    }

    /* FUNCIONES DEL AGI
     * EN: AGI FUNCTIONS */
    /**
    * Agent Logoff
    *
    * @link http://www.voip-info.org/wiki/index.php?page=Asterisk+Manager+API+AgentLogoff
    * @param Agent: Agent ID of the agent to login
    */
    private function Agentlogoff($obj_phpAgi, $agent)
    {
        return $obj_phpAgi->send_request('Agentlogoff', array('Agent'=>$agent));
    }
}
?>

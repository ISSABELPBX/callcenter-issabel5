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
  $Id: paloSantoCDR.class.php,v 1.1.1.1 2007/07/06 21:31:55 gcarrillo Exp $ */

class paloSantoCallsDetail
{
    private $_DB;   // Conexión a la base de datos // EN: Database connection
    var $errMsg;    // Último mensaje de error // EN: Last error message

    function __construct(&$pDB)
    {
        // Se recibe como parámetro una referencia a una conexión paloDB
        // EN: A reference to a paloDB connection is received as a parameter
        if (is_object($pDB)) {
            $this->_DB =& $pDB;
            $this->errMsg = $this->_DB->errMsg;
        } else {
            $dsn = (string)$pDB;
            $this->_DB = new paloDB($dsn);

            if (!$this->_DB->connStatus) {
                $this->errMsg = $this->_DB->errMsg;
                // debo llenar alguna variable de error
                // EN: I must fill some error variable
            } else {
                // debo llenar alguna variable de error
                // EN: I must fill some error variable
            }
        }
    }

    // Construir condición WHERE común a llamadas entrantes y salientes
    // EN: Build WHERE condition common to incoming and outgoing calls
    private function _construirWhere($param)
    {
        $condSQL = array();
        $paramSQL = array();

        // Selección del agente que atendió la llamada
        // EN: Selection of the agent that attended the call
        if (isset($param['agent']) && preg_match('/^\d+$/', $param['agent'])) {
            $condSQL[] = 'agent.number = ?';
            $paramSQL[] = $param['agent'];
        }

        return array($condSQL, $paramSQL);
    }

    private function _construirWhere_incoming($param)
    {
        list($condSQL, $paramSQL) = $this->_construirWhere($param);

        // Selección de la cola por la que pasó la llamada
        // EN: Selection of the queue through which the call passed
        if (isset($param['queue']) && preg_match('/^\d+$/', $param['queue'])) {
            $condSQL[] = 'queue_call_entry.queue = ?';
            $paramSQL[] = $param['queue'];
        }

        // Filtrar por patrón de número telefónico de la llamada
        // EN: Filter by phone number pattern of the call
        if (isset($param['phone']) && preg_match('/^\d+$/', $param['phone'])) {
            $condSQL[] = 'IF(contact.telefono IS NULL, call_entry.callerid, contact.telefono) LIKE ?';
            $paramSQL[] = '%'.$param['phone'].'%';
        }

        // Filtrar por ID de campaña entrante
        // EN: Filter by incoming campaign ID
        if (isset($param['id_campaign_in']) && preg_match('/^\d+$/', $param['id_campaign_in'])) {
            $condSQL[] = 'campaign_entry.id = ?';
            $paramSQL[] = (int)$param['id_campaign_in'];
        }

        // Filtrar por estado de la llamada (mapear valores ingles a espaniol para llamadas entrantes)
        // EN: Filter by call status (map English values to Spanish for incoming calls)
        if (isset($param['status']) && $param['status'] != '') {
            $statusMap = array(
                'Success'       => array('activa', 'terminada'),
                'Abandoned'     => array('abandonada'),
                'Failure'       => array('Failure'),
                'NoAnswer'      => array('NoAnswer'),
                'OnQueue'       => array('en-cola'),
                'Placing'       => array('Placing'),
                'Ringing'       => array('Ringing'),
                'ShortCall'     => array('ShortCall'),
                'fin-monitoreo' => array('fin-monitoreo'),
            );
            if (isset($statusMap[$param['status']])) {
                $placeholders = implode(',', array_fill(0, count($statusMap[$param['status']]), '?'));
                $condSQL[] = 'call_entry.status IN ('.$placeholders.')';
                $paramSQL = array_merge($paramSQL, $statusMap[$param['status']]);
            }
        }

        // Filtrar por transferencia
        // EN: Filter by transfer
        if (isset($param['transfer']) && $param['transfer'] != '') {
            if ($param['transfer'] == 'yes') {
                $condSQL[] = '(call_entry.transfer IS NOT NULL AND call_entry.transfer != "")';
            } elseif ($param['transfer'] == 'no') {
                $condSQL[] = '(call_entry.transfer IS NULL OR call_entry.transfer = "")';
            }
        }

        // Fecha y hora de inicio y final del rango
        // EN: Start and end date and time of the range
        $sRegFecha = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
        if (isset($param['date_start']) && preg_match($sRegFecha, $param['date_start'])) {
            $condSQL[] = 'call_entry.datetime_entry_queue >= ?';
            $paramSQL[] = $param['date_start'];
        }
        if (isset($param['date_end']) && preg_match($sRegFecha, $param['date_end'])) {
            $condSQL[] = 'call_entry.datetime_entry_queue <= ?';
            $paramSQL[] = $param['date_end'];
        }

        // Construir fragmento completo de sentencia SQL
        // EN: Build complete SQL statement fragment
        $where = array(implode(' AND ', $condSQL), $paramSQL);
        if ($where[0] != '') $where[0] = ' AND '.$where[0];
        return $where;
    }

    private function _construirWhere_outgoing($param)
    {
        list($condSQL, $paramSQL) = $this->_construirWhere($param);

        // Selección de la cola por la que pasó la llamada
        // EN: Selection of the queue through which the call passed
        if (isset($param['queue']) && preg_match('/^\d+$/', $param['queue'])) {
            $condSQL[] = 'campaign.queue = ?';
            $paramSQL[] = $param['queue'];
        }

        // Filtrar por patrón de número telefónico de la llamada
        // EN: Filter by phone number pattern of the call
        if (isset($param['phone']) && preg_match('/^\d+$/', $param['phone'])) {
            $condSQL[] = 'calls.phone LIKE ?';
            $paramSQL[] = '%'.$param['phone'].'%';
        }

        // Filtrar por ID de campaña saliente
        // EN: Filter by outgoing campaign ID
        if (isset($param['id_campaign_out']) && preg_match('/^\d+$/', $param['id_campaign_out'])) {
            $condSQL[] = 'campaign.id = ?';
            $paramSQL[] = (int)$param['id_campaign_out'];
        }

        // Filtrar por estado de la llamada (las llamadas salientes ya usan valores en ingles)
        // EN: Filter by call status (outgoing calls already use English values)
        if (isset($param['status']) && $param['status'] != '') {
            $statusMap = array(
                'Success'       => array('Success'),
                'Abandoned'     => array('Abandoned'),
                'Failure'       => array('Failure'),
                'NoAnswer'      => array('NoAnswer'),
                'OnQueue'       => array('OnQueue'),
                'Placing'       => array('Placing'),
                'Ringing'       => array('Ringing'),
                'ShortCall'     => array('ShortCall'),
                'fin-monitoreo' => array(), // fin-monitoreo solo existe en llamadas entrantes
            );
            if (isset($statusMap[$param['status']]) && count($statusMap[$param['status']]) > 0) {
                $placeholders = implode(',', array_fill(0, count($statusMap[$param['status']]), '?'));
                $condSQL[] = 'calls.status IN ('.$placeholders.')';
                $paramSQL = array_merge($paramSQL, $statusMap[$param['status']]);
            } elseif (isset($statusMap[$param['status']]) && count($statusMap[$param['status']]) == 0) {
                // Para estados que no existen en llamadas salientes, excluir todas
                // EN: For states that don't exist in outgoing calls, exclude all
                $condSQL[] = '1 = 0';
            }
        }

        // Filtrar por transferencia
        // EN: Filter by transfer
        if (isset($param['transfer']) && $param['transfer'] != '') {
            if ($param['transfer'] == 'yes') {
                $condSQL[] = '(calls.transfer IS NOT NULL AND calls.transfer != "")';
            } elseif ($param['transfer'] == 'no') {
                $condSQL[] = '(calls.transfer IS NULL OR calls.transfer = "")';
            }
        }

        // Fecha y hora de inicio y final del rango
        // EN: Start and end date and time of the range
        $sRegFecha = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
        if (isset($param['date_start']) && preg_match($sRegFecha, $param['date_start'])) {
            $condSQL[] = 'calls.fecha_llamada >= ?';
            $paramSQL[] = $param['date_start'];
        }
        if (isset($param['date_end']) && preg_match($sRegFecha, $param['date_end'])) {
            $condSQL[] = 'calls.fecha_llamada <= ?';
            $paramSQL[] = $param['date_end'];
        }

        // Construir fragmento completo de sentencia SQL
        // EN: Build complete SQL statement fragment
        $where = array(implode(' AND ', $condSQL), $paramSQL);
        if ($where[0] != '') $where[0] = ' AND '.$where[0];
        return $where;
    }

    /**
     * Procedimiento para recuperar el detalle de llamadas realizadas a través
     * del CallCenter.
     * EN: Procedure to retrieve call detail made through CallCenter.
     *
     * @param   mixed   $param  Lista de parámetros de filtrado:
     *                      EN: List of filter parameters:
     *  date_start      Fecha y hora minima de la llamada, en formato
     *                  yyyy-mm-dd hh:mm:ss. Si se omite, se lista desde la
     *                  primera llamada.
     *                  EN: Minimum date and time of the call, in format
     *                  EN: yyyy-mm-dd hh:mm:ss. If omitted, list from the
     *                  EN: first call.
     *  date_end        Fecha y hora máxima de la llamada, en formato
     *                  yyyy-mm-dd hh:mm:ss. Si se omite, se lista hasta la
     *                  última llamada.
     *                  EN: Maximum date and time of the call, in format
     *                  EN: yyyy-mm-dd hh:mm:ss. If omitted, list until the
     *                  EN: last call.
     *  calltype        Tipo de llamada. Se puede indicar "incoming" o "outgoing".
     *                  Si se omite, se recuperan llamadas de los dos tipos.
     *                  EN: Call type. Can indicate "incoming" or "outgoing".
     *                  EN: If omitted, calls of both types are retrieved.
     *  agent           Filtrar por número de agente a recuperar (9000 para
     *                  Agent/9000). Si no se especifica, se recuperan llamadas
     *                  de todos los agentes.
     *                  EN: Filter by agent number to retrieve (9000 for
     *                  EN: Agent/9000). If not specified, calls from all
     *                  EN: agents are retrieved.
     *  queue           Filtrar por número de cola. Si no se especifica, se
     *                  recuperan llamadas mandadas por todas las colas.
     *                  EN: Filter by queue number. If not specified, calls
     *                  EN: sent by all queues are retrieved.
     *  phone           Filtrar por número telefónico que contenga el patrón
     *                  numérico indicado. El patron 123 elige los números
     *                  44123887, 123847693, 999999123, etc. Si no se especifica,
     *                  se recuperan detalles sin importar el número conectado.
     *                  EN: Filter by phone number containing the indicated
     *                  EN: numeric pattern. Pattern 123 selects numbers
     *                  EN: 44123887, 123847693, 999999123, etc. If not specified,
     *                  EN: details are retrieved regardless of connected number.
     * @param   mixed   $limit  Máximo número de CDRs a leer, o NULL para todos
     *                      EN: Maximum number of CDRs to read, or NULL for all
     * @param   mixed   $offset Inicio de lista de CDRs, si se especifica $limit
     *                      EN: Start of CDR list, if $limit is specified
     *
     * @return  mixed   Arreglo de tuplas con los siguientes campos, en el
     *                  siguiente orden, o NULL si falla la petición:
     *                  EN: Array of tuples with the following fields, in the
     *                  EN: following order, or NULL if request fails:
     *      0   número del agente que atendió la llamada
     *          EN: number of the agent that attended the call
     *      1   nombre del agente que atendió la llamada
     *          EN: name of the agent that attended the call
     *      2   fecha de inicio de la llamada, en formato yyyy-mm-dd hh:mm:ss
     *          EN: call start date, in format yyyy-mm-dd hh:mm:ss
     *      3   fecha de final de la llamada, en formato yyyy-mm-dd hh:mm:ss
     *          EN: call end date, in format yyyy-mm-dd hh:mm:ss
     *      4   duración de la llamada, en segundos
     *          EN: call duration, in seconds
     *      5   duración que la llamada estuvo en espera en la cola, en segundos
     *          EN: duration the call was on hold in the queue, in seconds
     *      6   cola a través de la cual se atendió la llamada
     *          EN: queue through which the call was attended
     *      7   tipo de llamada Inbound o Outbound
     *          EN: call type Inbound or Outbound
     *      8   teléfono marcado o atendido en llamada
     *          EN: phone number dialed or attended in call
     *      9   transferencia
     *          EN: transfer
     *     10   estado final de la llamada
     *          EN: final call status
     */
    function & leerDetalleLlamadas($param, $limit = NULL, $offset = 0)
    {
        if (!is_array($param)) {
            $this->errMsg = '(internal) Invalid parameter array';
            return NULL;
        }

        $sPeticion_incoming = <<<SQL_INCOMING
SELECT agent.number, agent.name, call_entry.datetime_init AS start_date,
    call_entry.datetime_end AS end_date, call_entry.duration,
    call_entry.duration_wait, queue_call_entry.queue, 'Inbound' AS type,
    IF(contact.telefono IS NULL, call_entry.callerid, contact.telefono) AS telefono,
    call_entry.transfer, call_entry.status, call_entry.id AS idx,
    call_entry.datetime_entry_queue AS sort_time
FROM (call_entry, queue_call_entry)
LEFT JOIN contact
    ON contact.id = call_entry.id_contact
LEFT JOIN agent
    ON agent.id = call_entry.id_agent
LEFT JOIN campaign_entry
    ON campaign_entry.id = call_entry.id_campaign
WHERE call_entry.id_queue_call_entry = queue_call_entry.id
SQL_INCOMING;
        list($sWhere_incoming, $param_incoming) = $this->_construirWhere_incoming($param);
        $sPeticion_incoming .= $sWhere_incoming;

        $sPeticion_outgoing = <<<SQL_OUTGOING
SELECT agent.number, agent.name, calls.start_time AS start_date,
    calls.end_time AS end_date, calls.duration,
    calls.duration_wait, campaign.queue, 'Outbound' AS type,
    calls.phone AS telefono,
    calls.transfer, calls.status, calls.id AS idx,
    COALESCE(calls.datetime_entry_queue, calls.fecha_llamada) AS sort_time
FROM (calls, campaign)
LEFT JOIN agent
    ON agent.id = calls.id_agent
WHERE campaign.id = calls.id_campaign
SQL_OUTGOING;
        list($sWhere_outgoing, $param_outgoing) = $this->_construirWhere_outgoing($param);
        $sPeticion_outgoing .= $sWhere_outgoing;

        // Construir la unión SQL en caso necesario
        // EN: Build SQL union if necessary
        $sPeticionSQL = NULL; $paramSQL = NULL;
        if (!isset($param['calltype']) || !in_array($param['calltype'], array('incoming', 'outgoing')))
            $param['calltype'] = 'any';
        switch ($param['calltype']) {
        case 'incoming':
            $sPeticionSQL = $sPeticion_incoming;
            $paramSQL = $param_incoming;
            break;
        case 'outgoing':
            $sPeticionSQL = $sPeticion_outgoing;
            $paramSQL = $param_outgoing;
            break;
        default:
            $sPeticionSQL = "($sPeticion_incoming) UNION ($sPeticion_outgoing)";
            $paramSQL = array_merge($param_incoming, $param_outgoing);
            break;
        }
        // Ordenar por cuando la llamada entró en la cola; una llamada
        // saliente que nunca llegó a la cola, por cuando fue marcada. Así las
        // llamadas sin hora de inicio (no contestadas) quedan en su lugar en
        // el orden de tiempo, y no debajo de todas las contestadas.
        // EN: Order by when the call entered the queue; an outgoing call that
        // EN: never reached the queue, by when it was dialled. Calls with no
        // EN: start time (unanswered) then sit in time order instead of below
        // EN: every answered call.
        $sPeticionSQL .= ' ORDER BY sort_time DESC, telefono';
        if (!empty($limit)) {
            $sPeticionSQL .= " LIMIT ? OFFSET ?";
            array_push($paramSQL, $limit, $offset);
        }

        // Ejecutar la petición SQL para todos los datos
        // EN: Execute SQL request for all data
        //print "<pre>$sPeticionSQL</pre>";
        $recordset = $this->_DB->fetchTable($sPeticionSQL, FALSE, $paramSQL);
        if (!is_array($recordset)) {
            $this->errMsg = '(internal) Failed to fetch CDRs - '.$this->_DB->errMsg;
            $recordset = NULL;
        }

        /* Buscar grabaciones para las llamadas leídas. No se usa un LEFT JOIN
         * en el query principal porque pueden haber múltiples grabaciones por
         * registro (múltiples intentos en caso outgoing) y la cuenta de
         * registros no considera esta duplicidad. */
        /* EN: Search for recordings for the read calls. A LEFT JOIN is not used */
        /* EN: in the main query because there may be multiple recordings per */
        /* EN: record (multiple attempts in outgoing case) and the record count */
        /* EN: does not consider this duplication. */
        $sqlfield = array(
            'Inbound'   =>  'id_call_incoming',
            'Outbound'  =>  'id_call_outgoing',
        );
        foreach (array_keys($recordset) as $i) {
            /* La columna 12 es sólo la llave de orden, ajena al reporte:
             * array_pop (y no unset, que dejaría el índice siguiente en 13)
             * libera el índice 12, para que el arreglo de grabaciones añadido
             * más abajo caiga en 12 igual que antes, donde lo lee index.php. */
            /* EN: Column 12 is only the sort key, not part of the report:
             * EN: array_pop (not unset, which would leave the next index at
             * EN: 13) frees index 12, so the recordings array appended below
             * EN: lands at 12 exactly as before, where index.php reads it. */
            array_pop($recordset[$i]);
            /* Se asume que el tipo de llamada está en la columna 7 y el ID del
             * intento de llamada en la columna 11. */
            /* EN: It is assumed that call type is in column 7 and call attempt */
            /* EN: ID is in column 11. */
            /* Ascending: a transferred call is recorded in one file per leg, and
             * the grid shows the first row expanded and collapses the rest. In
             * descending order that put the last leg on top, so the visible
             * recording was the part after the transfer instead of the start of
             * the call.
             * ES: Ascendente: una llamada transferida se graba en un archivo por
             * tramo, y la grilla muestra la primera fila expandida y colapsa el
             * resto. En orden descendente eso dejaba arriba el ultimo tramo, asi
             * que la grabacion visible era la parte posterior a la transferencia
             * en vez del inicio de la llamada. */
            $sql = 'SELECT id, datetime_entry FROM call_recording WHERE '.
                $sqlfield[$recordset[$i][7]].' = ? ORDER BY datetime_entry ASC, id ASC';
            $r2 = $this->_DB->fetchTable($sql, TRUE, array($recordset[$i][11]));
            if (!is_array($r2)) {
                $this->errMsg = '(internal) Failed to fetch recordings for CDRs - '.$this->_DB->errMsg;
                $recordset = NULL;
                break;
            }
            $recordset[$i][] = $r2;
        }

        return $recordset;
    }

    /**
     * Procedimiento para contar el total de registros en el detalle de llamadas
     * realizadas a través del CallCenter.
     * EN: Procedure to count total records in call detail made through CallCenter.
     *
     * @param   mixed   $param  Lista de parámetros de filtrado. Idéntico a
     *                          leerDetalleLlamadas.
     *                          EN: List of filter parameters. Identical to
     *                          EN: leerDetalleLlamadas.
     *
     * @return  mixed   NULL en caso de error, o cuenta de registros.
     *                  EN: NULL on error, or record count.
     */
    function contarDetalleLlamadas($param)
    {
        if (!is_array($param)) {
            $this->errMsg = '(internal) Invalid parameter array';
            return NULL;
        }

        $sPeticion_incoming = <<<SQL_INCOMING
SELECT COUNT(*)
FROM (call_entry, queue_call_entry)
LEFT JOIN contact
    ON contact.id = call_entry.id_contact
LEFT JOIN agent
    ON agent.id = call_entry.id_agent
LEFT JOIN campaign_entry
    ON campaign_entry.id = call_entry.id_campaign
WHERE call_entry.id_queue_call_entry = queue_call_entry.id
SQL_INCOMING;
        list($sWhere_incoming, $param_incoming) = $this->_construirWhere_incoming($param);
        $sPeticion_incoming .= $sWhere_incoming;

        $sPeticion_outgoing = <<<SQL_OUTGOING
SELECT COUNT(*)
FROM (calls, campaign)
LEFT JOIN agent
    ON agent.id = calls.id_agent
WHERE campaign.id = calls.id_campaign
SQL_OUTGOING;
        list($sWhere_outgoing, $param_outgoing) = $this->_construirWhere_outgoing($param);
        $sPeticion_outgoing .= $sWhere_outgoing;

        // Sumar las cuentas de ambas tablas en caso necesario
        // EN: Sum counts from both tables if necessary
        $iNumRegistros = 0;
        if (!isset($param['calltype']) || !in_array($param['calltype'], array('incoming', 'outgoing')))
            $param['calltype'] = 'any';
        if (in_array($param['calltype'], array('any', 'outgoing'))) {
            // Agregar suma de llamadas salientes
            // EN: Add sum of outgoing calls
            $tupla = $this->_DB->getFirstRowQuery($sPeticion_outgoing, FALSE, $param_outgoing);
            if (is_array($tupla) && count($tupla) > 0) {
                $iNumRegistros += $tupla[0];
            } elseif (!is_array($tupla)) {
                $this->errMsg = '(internal) Failed to count CDRs (outgoing) - '.$this->_DB->errMsg;
                return NULL;
            }
        }
        if (in_array($param['calltype'], array('any', 'incoming'))) {
            // Agregar suma de llamadas entrantes
            // EN: Add sum of incoming calls
            $tupla = $this->_DB->getFirstRowQuery($sPeticion_incoming, FALSE, $param_incoming);
            if (is_array($tupla) && count($tupla) > 0) {
                $iNumRegistros += $tupla[0];
            } elseif (!is_array($tupla)) {
                $this->errMsg = '(internal) Failed to count CDRs (incoming) - '.$this->_DB->errMsg;
                return NULL;
            }
        }

        return $iNumRegistros;
    }

    /**
     * Procedimiento para obtener los agentes de CallCenter. A diferencia del
     * método en modules/agents/Agentes.class.php, este método lista también los
     * agentes inactivos, junto con su estado.
     * EN: Procedure to get CallCenter agents. Unlike the method in
     * EN: modules/agents/Agentes.class.php, this method also lists inactive
     * EN: agents, along with their status.
     *
     * @return  mixed   NULL en caso de error, o lista de agentes
     *                  EN: NULL on error, or list of agents
     */
    function getAgents()
    {
        $recordset = $this->_DB->fetchTable(
            'SELECT id, number, name, estatus FROM agent ORDER BY estatus, number',
            TRUE);
        if (!is_array($recordset)) {
            $this->errMsg = '(internal) Failed to fetch agents - '.$this->_DB->errMsg;
            $recordset = NULL;
        }
        return $recordset;
    }

    /**
     * Procedimiento para leer la lista de campañas del CallCenter. Las campañas
     * se listan primero las activas, luego inactivas, luego terminadas, y luego
     * por fecha de creación descendiente.
     * EN: Procedure to read the CallCenter campaign list. Campaigns are listed
     * EN: first active, then inactive, then finished, and then by descending
     * EN: creation date.
     *
     * @param unknown $type
     *                EN: Campaign type (incoming/outgoing)
     */
    function getCampaigns($type)
    {
        $recordset = $this->_DB->fetchTable(
            'SELECT id, name, estatus '.
            'FROM '.(($type == 'incoming') ? 'campaign_entry' : 'campaign').' '.
            'ORDER BY estatus, datetime_init DESC',
            TRUE);
        if (!is_array($recordset)) {
            $this->errMsg = '(internal) Failed to fetch campaigns - '.$this->_DB->errMsg;
            $recordset = NULL;
        }
        return $recordset;
    }

    function getRecordingFilePath($id)
    {
        $tupla = $this->_DB->getFirstRowQuery(
            'SELECT recordingfile FROM call_recording WHERE id = ?',
            TRUE, array($id));
        if (!is_array($tupla)) {
            $this->errMsg = '(internal) Failed to fetch recording filename - '.$this->_DB->errMsg;
            return NULL;
        }
        if (count($tupla) <= 0) return NULL;

        // TODO: volver configurable
        // EN: TODO: make configurable
        $recordingpath = '/var/spool/asterisk/monitor';
        if (substr($tupla['recordingfile'], 0, 1) != '/')
            $tupla['recordingfile'] = $recordingpath.'/'.$tupla['recordingfile'];
        return array(
            $tupla['recordingfile'],            // Ruta de archivo real // EN: Real file path
            basename($tupla['recordingfile'])   // TODO: renombrar según convención campaña // EN: TODO: rename according to campaign convention
        );
    }
}
?>

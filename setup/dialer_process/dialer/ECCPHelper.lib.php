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
  $Id: ECCPHelper.lib.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */


/**
 * Preparar un valor arbitrario para insertarlo como texto en una respuesta XML
 * del protocolo ECCP.
 *
 * SimpleXMLElement::addChild() escapa '<' y '>' por su cuenta, pero NO escapa
 * '&': un '&' crudo provoca "unterminated entity reference" y descarta el valor
 * completo. Por eso el escape de '&' se conserva exactamente como estaba en las
 * 54 llamadas que esta funcion reemplaza. Lo que addChild() no maneja, y esta
 * funcion agrega, es:
 *
 *   - UTF-8 invalido, que addChild() trunca en silencio en el primer byte malo,
 *     sin emitir ninguna advertencia.
 *   - Caracteres prohibidos en XML 1.0 (0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F), que
 *     hacen que libxml vacie el valor o, en versiones mas nuevas, que asXML()
 *     falle por completo y devuelva FALSE, dejando al cliente sin respuesta.
 *
 * EN: Prepare an arbitrary value to be inserted as text into an ECCP protocol
 * XML response.
 *
 * SimpleXMLElement::addChild() escapes '<' and '>' by itself, but does NOT
 * escape '&': a raw '&' raises "unterminated entity reference" and drops the
 * whole value. That is why the '&' escaping is kept exactly as it was in the 54
 * call sites this function replaces. What addChild() does not handle, and this
 * function adds, is:
 *
 *   - Invalid UTF-8, which addChild() silently truncates at the first bad byte,
 *     without emitting any warning at all.
 *   - Characters forbidden in XML 1.0 (0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F), which
 *     make libxml empty the value or, on newer versions, make asXML() fail
 *     outright and return FALSE, leaving the client with no response.
 *
 * @param   mixed   $sValor     Valor a insertar / value to insert
 *
 * @return  string  Texto seguro para addChild() / text safe for addChild()
 */
function xmlSafe($sValor)
{
    $sValor = (string)$sValor;

    // Reparar UTF-8 invalido antes de que addChild() lo trunque en silencio.
    // Repair invalid UTF-8 before addChild() silently truncates it.
    if ($sValor !== '' && !mb_check_encoding($sValor, 'UTF-8')) {
        $mSustitutoPrevio = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $sValor = mb_convert_encoding($sValor, 'UTF-8', 'UTF-8');
        mb_substitute_character($mSustitutoPrevio);
    }

    /* Quitar caracteres prohibidos en XML 1.0. Se opera byte a byte a proposito:
     * todos estos bytes son < 0x80 y por lo tanto nunca aparecen dentro de una
     * secuencia UTF-8 multibyte, cuyos bytes de continuacion son 0x80-0xBF. */
    /* Strip characters forbidden in XML 1.0. This works byte-wise on purpose:
     * all of these bytes are < 0x80 and therefore never appear inside a
     * multi-byte UTF-8 sequence, whose continuation bytes are 0x80-0xBF. */
    $sValor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $sValor);

    // Escape de '&': identico al que hacian las llamadas reemplazadas.
    // '&' escaping: identical to what the replaced call sites did.
    return str_replace('&', '&amp;', $sValor);
}

/**
 * Procedimiento que consulta toda la información de la base de datos sobre
 * una llamada de campaña. Se usa para el evento agentlinked, así como para
 * el requerimiento getcallinfo.
 *
 * Procedure that queries all database information about a campaign call.
 * It is used for the agentlinked event, as well as for the getcallinfo request.
 *
 * @param   string  $sTipoLlamada   Uno de 'incoming', 'outgoing'
 * @param   integer $idCampania     ID de la campaña, puede ser NULL para incoming
 * @param   integer $idLlamada      ID de la llamada dentro de la campaña
 *
 */
function leerInfoLlamada($db, $sTipoLlamada, $idCampania, $idLlamada)
{
    switch ($sTipoLlamada) {
    case 'incoming':
        return _leerInfoLlamadaIncoming($db, $idCampania, $idLlamada);
    case 'outgoing':
        return _leerInfoLlamadaOutgoing($db, $idCampania, $idLlamada);
    default:
        return NULL;
    }
}

// Leer la información de una llamada saliente. La información incluye lo
// almacenado en la tabla calls, más los atributos asociados a la llamada
// en la tabla call_attribute, y los datos ya recogidos en las tablas
// form_data_recolected y form_field
// Read the information of an outgoing call. The information includes what is
// stored in the calls table, plus the attributes associated with the call
// in the call_attribute table, and the data already collected in the
// form_data_recolected and form_field tables
function _leerInfoLlamadaOutgoing($db, $idCampania, $idLlamada)
{
    // Leer información de la llamada principal
    // Read main call information
    $sPeticionSQL = <<<INFO_LLAMADA
SELECT 'outgoing' AS calltype, calls.id AS call_id, id_campaign AS campaign_id, phone, status, uniqueid,
    duration, datetime_originate, fecha_llamada AS datetime_originateresponse,
    datetime_entry_queue AS datetime_join, start_time AS datetime_linkstart,
    end_time AS datetime_linkend, retries, failure_cause, failure_cause_txt,
    CONCAT(agent.type, '/', agent.number) AS agent_number, trunk
FROM (calls)
LEFT JOIN agent ON agent.id = calls.id_agent
WHERE id_campaign = ? AND calls.id = ?
INFO_LLAMADA;
    $recordset = $db->prepare($sPeticionSQL);
    $recordset->execute(array($idCampania, $idLlamada));
    $tuplaLlamada = $recordset->fetch(PDO::FETCH_ASSOC); $recordset->closeCursor();
    if (!$tuplaLlamada) {
        // No se encuentra la llamada indicada
        // The indicated call is not found
        return array();
    }

    // Leer información de los atributos de la llamada
    // Read call attribute information
    $tuplaLlamada['call_attributes'] = leerAtributosContacto($db, 'outgoing', $idLlamada);

    // Leer información de los datos recogidos vía formularios
    // Read information from data collected via forms
    $tuplaLlamada['call_survey'] = leerDatosRecogidosFormularios($db, 'outgoing', $idLlamada);

    return $tuplaLlamada;
}

// Leer la información de la llamada entrante. En esta implementación, a
// diferencia de las llamadas salientes, las llamadas entrantes tienen un
// solo formulario, y su conjunto de atributos es fijo.
// Read the information of an incoming call. In this implementation, unlike
// outgoing calls, incoming calls have only one form, and their set of
// attributes is fixed.
function _leerInfoLlamadaIncoming($db, $idCampania, $idLlamada)
{
    // Leer información de la llamada principal
    // Read main call information
    $sPeticionSQL = <<<INFO_LLAMADA
SELECT 'incoming' AS calltype, call_entry.id AS call_id, id_campaign AS campaign_id,
    callerid AS phone, status, uniqueid, duration, datetime_entry_queue AS datetime_join,
    datetime_init AS datetime_linkstart, datetime_end AS datetime_linkend,
    trunk, queue, id_contact, CONCAT(agent.type, '/', agent.number) AS agent_number
FROM (call_entry, queue_call_entry)
LEFT JOIN agent ON agent.id = call_entry.id_agent
WHERE call_entry.id = ? AND call_entry.id_queue_call_entry = queue_call_entry.id
INFO_LLAMADA;
    $recordset = $db->prepare($sPeticionSQL);
    $recordset->execute(array($idLlamada));
    $tuplaLlamada = $recordset->fetch(PDO::FETCH_ASSOC); $recordset->closeCursor();
    if (!$tuplaLlamada) {
        // No se encuentra la llamada indicada
        // The indicated call is not found
        return array();
    }

    // Leer información de los atributos de la llamada
    // TODO: expandir cuando se tenga tabla de atributos arbitrarios
    // Read call attribute information
    // TODO: expand when arbitrary attributes table is available
    $idContact = $tuplaLlamada['id_contact'];
    unset($tuplaLlamada['id_contact']);
    $tuplaLlamada['call_attributes'] = array();
    if (!is_null($idContact)) {
        $tuplaLlamada['call_attributes'] = leerAtributosContacto($db, 'incoming', $idContact);
    }

    // Leer información de todos los contactos que coincidan en callerid
    // Read information of all contacts that match callerid
    $tuplaLlamada['matching_contacts'] = array();
    $sPeticionSQL = <<<INFO_ATRIBUTOS
SELECT id, name AS first_name, apellido AS last_name, telefono AS phone, cedula_ruc
FROM contact WHERE telefono = ?
INFO_ATRIBUTOS;
    $recordset = $db->prepare($sPeticionSQL);
    $recordset->execute(array($tuplaLlamada['phone']));
    foreach ($recordset as $tuplaContacto) {
        $tuplaLlamada['matching_contacts'][$tuplaContacto['id']] = array(
            array(
                'label' =>  'first_name',
                'value' =>  $tuplaContacto['first_name'],
                'order' =>  1,
            ),
            array(
                'label' =>  'last_name',
                'value' =>  $tuplaContacto['last_name'],
                'order' =>  2,
            ),
            array(
                'label' =>  'phone',
                'value' =>  $tuplaContacto['phone'],
                'order' =>  3,
            ),
            array(
                'label' =>  'cedula_ruc',
                'value' =>  $tuplaContacto['cedula_ruc'],
                'order' =>  4,
            ),
        );
    }

    // Leer información de los datos recogidos vía formularios
    // Read information from data collected via forms
    $tuplaLlamada['call_survey'] = leerDatosRecogidosFormularios($db, 'incoming', $idLlamada);

    return $tuplaLlamada;
}

function leerAtributosContacto($db, $sTipoLlamada, $idContacto)
{
    $r = array();

    switch ($sTipoLlamada) {
    case 'outgoing':
        $sPeticionSQL = <<<INFO_ATRIBUTOS
SELECT columna AS `label`, value, column_number AS `order`
FROM call_attribute WHERE id_call = ?
ORDER BY column_number
INFO_ATRIBUTOS;
        break;
    case 'incoming':
        $sPeticionSQL = NULL;
        break;
    }

    if (!is_null($sPeticionSQL)) {
        $recordset = $db->prepare($sPeticionSQL);
        $recordset->execute(array($idContacto));
        $r = $recordset->fetchAll(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
    }

    // Caso especial: llamadas entrantes
    // Special case: incoming calls
    if ($sTipoLlamada == 'incoming') {
        $sPeticionSQL = <<<INFO_ATRIBUTOS
SELECT name AS first_name, apellido AS last_name, telefono AS phone, cedula_ruc, origen AS contact_source
FROM contact WHERE id = ?
INFO_ATRIBUTOS;
        $recordset = $db->prepare($sPeticionSQL);
        $recordset->execute(array($idContacto));
        $atributosLlamada = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        foreach ($atributosLlamada as $k => $v) {
            $r[] = array(
                'label' =>  $k,
                'value' =>  $v,
                'order' =>  count($r) + 1,
            );
        }
    }
    return $r;
}

function nombresCamposFormulariosEstaticos($sTipoLlamada)
{
    switch ($sTipoLlamada) {
    case 'incoming':
        $fdr_tabla = 'form_data_recolected_entry';
        $fdr_campo = 'id_call_entry';
        break;
    case 'outgoing':
        $fdr_tabla = 'form_data_recolected';
        $fdr_campo = 'id_calls';
        break;
    }
    return array($fdr_tabla, $fdr_campo);
}

function leerDatosRecogidosFormularios($db, $sTipoLlamada, $idLlamada)
{
    list($fdr_tabla, $fdr_campo) = nombresCamposFormulariosEstaticos($sTipoLlamada);

    // Leer información de los datos recogidos vía formularios
    // Read information from data collected via forms
    $sPeticionSQL = <<<INFO_FORMULARIOS
SELECT form_field.id_form, form_field.id, form_field.etiqueta AS label,
    $fdr_tabla.value
FROM $fdr_tabla, form_field
WHERE $fdr_tabla.$fdr_campo = ?
    AND $fdr_tabla.id_form_field = form_field.id
ORDER BY form_field.id_form, form_field.orden
INFO_FORMULARIOS;
    $recordset = $db->prepare($sPeticionSQL);
    $recordset->execute(array($idLlamada));
    $datosFormularios = $recordset->fetchAll(PDO::FETCH_ASSOC);

    $call_survey = array();
    foreach ($datosFormularios as $tuplaFormulario) {
        $call_survey[$tuplaFormulario['id_form']][] = array(
            'id'    => $tuplaFormulario['id'],
            'label' => $tuplaFormulario['label'],
            'value' => $tuplaFormulario['value'],
        );
    }

    return $call_survey;
}

/**
 * Método para marcar en las tablas de auditoría que el agente ha terminado
 * su hold o break.
 *
 * Method to mark in the audit tables that the agent has finished their
 * hold or break.
 *
 * @param   int     $idAuditBreak   ID del break devuelto por marcarInicioBreakAgente()
 *                                 ID of the break returned by marcarInicioBreakAgente()
 */
function marcarFinalBreakAgente($db, $idAuditBreak, $iTimestampLogout)
{
    $sTimeStamp = date('Y-m-d H:i:s', $iTimestampLogout);
    $sth = $db->prepare(
            'UPDATE audit SET datetime_end = ?, duration = TIMEDIFF(?, datetime_init) WHERE id = ?');
    $sth->execute(array($sTimeStamp, $sTimeStamp, $idAuditBreak));
}

function construirEventoPauseEnd($db, $sAgente, $id_audit_break, $pause_class)
{
    // Obtener inicio, fin y duración de break para lanzar evento
    // Get break start, end, and duration to launch event
    $recordset = $db->prepare(
        'SELECT break.id AS break_id, break.name AS break_name, '.
            'audit.datetime_init AS datetime_breakstart, audit.datetime_end AS datetime_breakend, '.
            'TIME_TO_SEC(audit.duration) AS duration_sec '.
        'FROM audit, break '.
        'WHERE audit.id = ? AND audit.id_break = break.id');
    $recordset->execute(array($id_audit_break));
    $tuplaBreak = $recordset->fetch(PDO::FETCH_ASSOC);
    $recordset->closeCursor();
    $paramsEvento = array(
        'pause_class'   =>  $pause_class,
        'pause_start'   =>  $tuplaBreak['datetime_breakstart'],
        'pause_end'     =>  $tuplaBreak['datetime_breakend'],
        'pause_duration'=>  $tuplaBreak['duration_sec'],
    );
    if ($pause_class != 'hold') {
    	$paramsEvento['pause_type'] = $tuplaBreak['break_id'];
        $paramsEvento['pause_name'] = $tuplaBreak['break_name'];
    }
    return array('PauseEnd', array($sAgente, $paramsEvento));
}

function cargarInfoPausa($db, &$infoAgente, &$recordset)
{
    /* El hold es un break de tipo 'H': ambos escriben una fila de audit con
     * datetime_init, y el Agente los sigue en campos paralelos
     * (id_audit_break / id_audit_hold). La consulta va por audit.id, así que
     * sirve igual para los dos. */
    /* EN: A hold is a break of type 'H': both write an audit row with
     * datetime_init, and Agente tracks them in parallel fields
     * (id_audit_break / id_audit_hold). The query is keyed on audit.id, so it
     * serves both. */
    $bHayBreak = !is_null($infoAgente['id_audit_break']);
    $bHayHold  = isset($infoAgente['id_audit_hold']) && !is_null($infoAgente['id_audit_hold']);

    if (($bHayBreak || $bHayHold) && is_null($recordset)) {
        $recordset = $db->prepare(
            'SELECT audit.datetime_init, break.name '.
            'FROM audit, break WHERE audit.id_break = break.id AND audit.id = ?');
    }
    if ($bHayBreak) {
        $recordset->execute(array($infoAgente['id_audit_break']));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        if ($tupla) {
            $infoAgente['pausename'] = $tupla['name'];
            $infoAgente['pausestart'] = $tupla['datetime_init'];
        }
    }
    if ($bHayHold) {
        // Inicio del hold actual, para que la consola pueda mostrar (y
        // recuperar tras un refresco) el cronómetro del hold en curso.
        // EN: start of the current hold, so the console can show - and
        // restore after a refresh - the running hold timer.
        $recordset->execute(array($infoAgente['id_audit_hold']));
        $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        if ($tupla) {
            $infoAgente['holdstart'] = $tupla['datetime_init'];
        }
    }
}

/* Normaliza el errorInfo de una PDOException. PDO deja errorInfo en NULL para las
 * excepciones generadas por el propio PDO y no por el driver (por ejemplo,
 * rollBack() sin transacción activa), por lo que el acceso directo al arreglo no
 * es seguro.
 *
 * Normalizes the errorInfo of a PDOException. PDO leaves errorInfo as NULL for
 * exceptions raised by PDO itself rather than by the driver (for example,
 * rollBack() with no active transaction), so direct array access is not safe. */
function infoErrorPDO(PDOException $e)
{
    if (is_array($e->errorInfo) && count($e->errorInfo) >= 3) return $e->errorInfo;
    return array('HY000', 0, $e->getMessage());
}

function esDeadlockTransaccion(PDOException $e)
{
    $info = infoErrorPDO($e);
    // 40001 - 1213 - Deadlock found when trying to get lock; try restarting transaction
    return ($info[0] == '40001' && $info[1] == 1213);
}

function esLockTimeout(PDOException $e)
{
    $info = infoErrorPDO($e);
    // HY000 - 1205 - Lock wait timeout exceeded; try restarting transaction
    return ($info[0] == 'HY000' && $info[1] == 1205);
}

function esReiniciable(PDOException $e)
{
    return (esDeadlockTransaccion($e) || esLockTimeout($e));
}

/* Indica si la excepción corresponde a una pérdida de conexión con el servidor de
 * base de datos. La acción pendiente DEBE conservarse: se reintentará una vez
 * restablecida la conexión, para no perder registros de llamadas ni de auditoría
 * durante un reinicio del servidor de base de datos.
 *
 * Indicates whether the exception corresponds to a lost connection with the
 * database server. The pending action MUST be retained: it will be retried once
 * the connection is restored, so that call and audit records are not lost during a
 * restart of the database server. */
function esErrorConexion(PDOException $e)
{
    $info = infoErrorPDO($e);
    /* 2002 - no se puede conectar por socket local | cannot connect through local socket
     * 2003 - no se puede conectar al servidor      | cannot connect to server
     * 2006 - el servidor cerró la conexión         | server has gone away
     * 2013 - conexión perdida durante la consulta  | lost connection during query
     * 1053 - el servidor se está apagando          | server shutdown in progress
     * 1040 - demasiadas conexiones                 | too many connections */
    return in_array((int)$info[1], array(2002, 2003, 2006, 2013, 1053, 1040), TRUE);
}

/* Indica si la excepción corresponde a un fallo lógico permanente, que jamás podrá
 * completarse por más que se reintente. La acción pendiente debe descartarse para
 * que la cola pueda avanzar; de lo contrario bloquea indefinidamente a todas las
 * acciones encoladas detrás de ella.
 *
 * Indicates whether the exception corresponds to a permanent logical failure that
 * can never complete no matter how many times it is retried. The pending action
 * must be discarded so that the queue can move forward; otherwise it indefinitely
 * blocks every action queued behind it. */
function esErrorPermanente(PDOException $e)
{
    $info = infoErrorPDO($e);
    /* 23000 - violación de integridad referencial | integrity constraint violation
     *         (1452 clave foránea | foreign key, 1062 clave duplicada | duplicate key,
     *          1048 columna no nula | not null column)
     * 22xxx - dato inválido (fecha, longitud, rango) | data exception (date, length, range)
     * 42S22 - columna inexistente                 | unknown column
     * 42S02 - tabla inexistente                   | unknown table
     * 42000 - error de sintaxis o de permisos     | syntax error or access violation
     *
     * Un error de dato jamás se corrige reintentando: el valor ofendido sigue
     * en la acción y cada reintento fracasa igual, con la cola bloqueada tras
     * él mientras tanto, así que se descarta de inmediato.
     * A data error is never fixed by retrying: the offending value is still in
     * the action and every retry fails the same way, with the queue blocked
     * behind it in the meantime, so it is dropped at once. */
    if (strncmp((string)$info[0], '22', 2) === 0) return TRUE;    // SQLSTATE class 22: data exception
    return in_array((string)$info[0], array('23000', '42S22', '42S02', '42000'), TRUE);
}
?>

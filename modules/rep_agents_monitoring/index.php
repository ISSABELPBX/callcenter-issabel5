<?php
  /* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 1.5.2-3.1                                               |
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
  $Id: index.php,v 1.1.1.1 2009/07/27 09:10:19 dlopez Exp $ */

require_once 'libs/paloSantoGrid.class.php';

function _moduleContent(&$smarty, $module_name)
{
    global $arrConf;
    global $arrLang;

    require_once "modules/agent_console/libs/issabel2.lib.php";
    require_once "modules/agent_console/libs/paloSantoConsola.class.php";
    require_once "modules/agent_console/libs/JSON.php";
    require_once "modules/$module_name/configs/default.conf.php";

    // Directorio de este módulo
    $sDirScript = dirname($_SERVER['SCRIPT_FILENAME']);

    // Se fusiona la configuración del módulo con la configuración global
    $arrConf = array_merge($arrConf, $arrConfModule);

    /* Se pide el archivo de inglés, que se elige a menos que el sistema indique
       otro idioma a usar. Así se dispone al menos de la traducción al inglés
       si el idioma elegido carece de la cadena.
     */
    load_language_module($module_name);

    // Asignación de variables comunes y directorios de plantillas
    $sDirPlantillas = (isset($arrConf['templates_dir']))
        ? $arrConf['templates_dir'] : 'themes';
    $sDirLocalPlantillas = "$sDirScript/modules/$module_name/".$sDirPlantillas.'/'.$arrConf['theme'];
    $smarty->assign("MODULE_NAME", $module_name);

    $sAction = '';
    $sContenido = '';

    $sAction = getParameter('action');
    if (!in_array($sAction, array('', 'checkStatus')))
        $sAction = '';

    $oPaloConsola = new PaloSantoConsola();
    switch ($sAction) {
    case 'checkStatus':
        $sContenido = manejarMonitoreo_checkStatus($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola);
        break;
    case '':
    default:
        $sContenido = manejarMonitoreo_HTML($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola);
        break;
    }
    $oPaloConsola->desconectarTodo();

    return $sContenido;
}

function manejarMonitoreo_HTML($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola)
{
    global $arrLang;

    $smarty->assign(array(
        'FRAMEWORK_TIENE_TITULO_MODULO' => existeSoporteTituloFramework(),
        'icon'                          => 'modules/'.$module_name.'/images/call.png',
        'title'                         =>  _tr('Agent Monitoring'),
    ));

    // The server owns the shift filter (default: full day 00-23). Valid GET
    // parameters are stored in the session; otherwise the session's stored
    // filter is used, so a bare reload keeps the window the user applied.
    $shiftFrom = getParameter('shift_from');
    $shiftTo = getParameter('shift_to');
    if (!is_null($shiftFrom) && $shiftFrom !== '' && ctype_digit("$shiftFrom")
        && (int)$shiftFrom <= 23
        && !is_null($shiftTo) && $shiftTo !== '' && ctype_digit("$shiftTo")
        && (int)$shiftTo <= 23) {
        $_SESSION[$module_name]['shift_from'] = (int)$shiftFrom;
        $_SESSION[$module_name]['shift_to'] = (int)$shiftTo;
    }
    $shiftFrom = isset($_SESSION[$module_name]['shift_from'])
        ? (int)$_SESSION[$module_name]['shift_from'] : 0;
    $shiftTo = isset($_SESSION[$module_name]['shift_to'])
        ? (int)$_SESSION[$module_name]['shift_to'] : 23;
    $shiftRange = calculateShiftDatetimeRange($shiftFrom, $shiftTo);

    /*
     * Un agente puede pertenecer a múltiples colas, y puede o no estar
     * atendiendo una llamada, la cual puede haber llegado de como máximo una
     * cola. Hay 3 cronómetros que se pueden actualizar:
     *
     * último estado:   el tiempo transcurrido desde el último cambio de estado
     * total de login:  el tiempo durante el cual el agente ha estado logoneado
     * total de llamadas: el tiempo que el agente pasa atendiendo llamadas
     *
     * Para el monitoreo de este módulo, los estados en que puede estar
     * una fila (que muestra un agente en una cola) pueden ser los siguientes:
     *
     * offline: el tiempo total de login y el tiempo de llamadas no se
     *  actualizan. Si el cliente estuvo en otro estado previamente
     *  (lastsessionend) entonces se actualiza regularmente el cronómetro de
     *  último estado. De otro modo el cronómetro de último estado está vacío.
     * online: se actualiza el tiempo total de login y el tiempo de último
     *  estado, y el tiempo total de llamadas no se actualiza. El cronómetro de
     *  último estado cuenta desde el inicio de sesión.
     * paused: igual que online, pero el cronómentro de último estado cuenta
     *  desde el inicio de la pausa.
     * oncall: se actualiza el tiempo total de login. El cronómetro de último
     *  estado cuenta desde el inicio de la llamada únicamente para la cola que
     *  proporcionó la llamada que atiende el agente actualmente. De otro modo
     *  el cronómetro no se actualiza. De manera similar, el total de tiempo de
     *  llamadas se actualiza únicamente para la cola que haya proporcionado la
     *  llamada que atiende el agente.
     *
     * El estado del cliente consiste en un arreglo de tantos elementos como
     * agentes haya pertenecientes a cada cola. Si un agente pertenece a más de
     * una cola, hay un elemento por cada pertenencia del mismo agente a cada
     * cola. Cada elemento es una estructura que contiene los siguientes
     * valores:
     *
     * status:          {offline|online|oncall|paused}
     * sec_laststatus:  integer|null
     * sec_calls:       integer
     * logintime:       integer
     * num_calls:       integer
     * oncallupdate:    boolean
     *
     * Cada elemento del arreglo se posiciona por 'queue-{NUM_COLA}-member-{NUM_AGENTE}'
     *
     * El estado enviado por el cliente para detectar cambios es también un
     * arreglo con el mismo número de elementos que el arreglo anterior,
     * posicionado de la misma manera. Cada elemento es una estructura que
     * contiene los siguientes valores:
     *
     * status:          {offline|online|oncall|paused}
     * oncallupdate:    boolean
     */
    $estadoMonitor = $oPaloConsola->listarEstadoMonitoreoAgentes();
    if (!is_array($estadoMonitor)) {
        $smarty->assign(array(
            'mb_title'  =>  'ERROR',
            'mb_message'    =>  $oPaloConsola->errMsg,
        ));
        return '';
    }
    ksort($estadoMonitor);

    $breakData = consultarTiempoBreakAgentes($shiftRange['start'], $shiftRange['end']);
    $holdData  = consultarTiempoHoldAgentes($shiftRange['start'], $shiftRange['end']);
    $loginData = consultarTiempoLoginAgentes($shiftRange['start'], $shiftRange['end']);
    $callData  = consultarLlamadasAgentes($shiftRange['start'], $shiftRange['end']);
    $jsonData = construirDatosJSON($estadoMonitor, $breakData, $holdData, $loginData, $callData, $shiftRange);

    $arrData = array();
    $tuplaTotal = NULL;
    $sPrevQueue = NULL;
    foreach ($jsonData as $jsonKey => $jsonRow) {
        list($d1, $sQueue, $d2, $sTipoAgente, $sNumeroAgente) = explode('-', $jsonKey);

        $sEstadoTag = '(unimplemented)';
        switch ($jsonRow['status']) {
        case 'offline':
            $sEstadoTag = _tr('LOGOUT');
            break;
        case 'online':
            $sEstadoTag = '<img src="modules/'.$module_name.'/images/ready.png" border="0" alt="'._tr('READY').'"/>';
            break;
        case 'ringing':
            $sEstadoTag = '<img src="modules/'.$module_name.'/images/agent-ringing.gif" border="0" alt="'._tr('RINGING').'"/>';
            break;
        case 'oncall':
            if ($jsonRow['onhold'])
                $sEstadoTag = '<img src="modules/'.$module_name.'/images/hold.png" border="0" alt="'._tr('HOLD').'"/>';
            else
                $sEstadoTag = '<img src="modules/'.$module_name.'/images/call.png" border="0" alt="'._tr('CALL').'"/>';
            break;
        case 'paused':
            $sEstadoTag = '<img src="modules/'.$module_name.'/images/break.png" border="0" alt="'._tr('BREAK').'"/>';
            if ($jsonRow['onhold'])
                $sEstadoTag .= '<img src="modules/'.$module_name.'/images/hold.png" border="0" alt="'._tr('HOLD').'"/>';
            elseif (!is_null($jsonRow['pausename']))
                $sEstadoTag .= '<span>'.htmlentities($jsonRow['pausename'], ENT_COMPAT, 'UTF-8').'</span>';
            break;
        }
        $sEstadoTag = '<span id="'.$jsonKey.'-statuslabel">'.$sEstadoTag.'</span>';
        $sEstadoTag .= '&nbsp;<span id="'.$jsonKey.'-sec_laststatus">';
        if (!is_null($jsonRow['sec_laststatus'])) {
        	$sEstadoTag .= timestamp_format($jsonRow['sec_laststatus']);
        }
        $sEstadoTag .= '</span>';

        // Estado a mostrar en HTML se deriva del estado JSON
        // EN: Status to display in HTML is derived from JSON status
        if ($sPrevQueue != $sQueue) {
            if (!is_null($tuplaTotal)) {
            	// Emitir fila de totales para la cola ANTERIOR
            	// EN: Emit totals row for the PREVIOUS queue
                $jsTotalKey = 'queue-'.$sPrevQueue;
                $arrData[] = array(
                    '<b>'._tr('TOTAL').'</b>',
                    '&nbsp;',
                    '<b>'._tr('Agents').': '.$tuplaTotal['num_agents'].'</b>',
                    '&nbsp;',
                    '<b><span id="'.$jsTotalKey.'-num_calls">'.$tuplaTotal['num_calls'].'</span></b>',
                    '<b><span id="'.$jsTotalKey.'-logintime">'.timestamp_format($tuplaTotal['logintime']).'</span></b>',
                    '<b><span id="'.$jsTotalKey.'-sec_calls">'.timestamp_format($tuplaTotal['sec_calls']).'</span></b>',
                    '<b><span id="'.$jsTotalKey.'-sec_breaks">'.timestamp_format($tuplaTotal['sec_breaks']).'</span></b>',
                    '<b><span id="'.$jsTotalKey.'-sec_holds">'.timestamp_format($tuplaTotal['sec_holds']).'</span></b>',
                );
            }

            // Reiniciar totales aquí
            // EN: Reset totals here
            $tuplaTotal = array(
                'num_agents'    =>  0,
                'logintime'     =>  0,
                'num_calls'     =>  0,
                'sec_calls'     =>  0,
                'sec_breaks'    =>  0,
                'sec_holds'     =>  0,
            );
        }
        $tuplaTotal['num_agents']++;
        $tuplaTotal['logintime'] += $jsonRow['logintime'];
        $tuplaTotal['num_calls'] += $jsonRow['num_calls'];
        $tuplaTotal['sec_calls'] += $jsonRow['sec_calls'];
        $tuplaTotal['sec_breaks'] += $jsonRow['sec_breaks'];
        $tuplaTotal['sec_holds'] += $jsonRow['sec_holds'];
        $tupla = array(
            ($sPrevQueue == $sQueue) ? '' : $sQueue,
            $jsonRow['agentchannel'],
            htmlentities($jsonRow['agentname'], ENT_COMPAT, 'UTF-8'),
            $sEstadoTag,
            '<span id="'.$jsonKey.'-num_calls">'.$jsonRow['num_calls'].'</span>',
            '<span id="'.$jsonKey.'-logintime">'.timestamp_format($jsonRow['logintime']).'</span>',
            '<span id="'.$jsonKey.'-sec_calls">'.timestamp_format($jsonRow['sec_calls']).'</span>',
            '<span id="'.$jsonKey.'-sec_breaks">'.timestamp_format($jsonRow['sec_breaks']).'</span>',
            '<span id="'.$jsonKey.'-sec_holds">'.timestamp_format($jsonRow['sec_holds']).'</span>',
        );
        $arrData[] = $tupla;
        $sPrevQueue = $sQueue;
    }
    // Emitir fila de totales para la cola ÚLTIMA
    $jsTotalKey = 'queue-'.$sPrevQueue;
    $arrData[] = array(
        '<b>'._tr('TOTAL').'</b>',
        '&nbsp;',
        '<b>'._tr('Agents').': '.$tuplaTotal['num_agents'].'</b>',
        '&nbsp;',
        '<b><span id="'.$jsTotalKey.'-num_calls">'.$tuplaTotal['num_calls'].'</span></b>',
        '<b><span id="'.$jsTotalKey.'-logintime">'.timestamp_format($tuplaTotal['logintime']).'</span></b>',
        '<b><span id="'.$jsTotalKey.'-sec_calls">'.timestamp_format($tuplaTotal['sec_calls']).'</span></b>',
        '<b><span id="'.$jsTotalKey.'-sec_breaks">'.timestamp_format($tuplaTotal['sec_breaks']).'</span></b>',
        '<b><span id="'.$jsTotalKey.'-sec_holds">'.timestamp_format($tuplaTotal['sec_holds']).'</span></b>',
    );

    // No es necesario emitir el nombre del agente la inicialización JSON
    foreach (array_keys($jsonData) as $k) unset($jsonData[$k]['agentname']);

    // Extraer la información que el navegador va a usar para actualizar
    $estadoCliente = array();
    foreach (array_keys($jsonData) as $k) {
        $estadoCliente[$k] = array(
            'status'        =>  $jsonData[$k]['status'],
            'oncallupdate'  =>  $jsonData[$k]['oncallupdate'],
            'onhold'        =>  $jsonData[$k]['onhold'],
        );
    }
    $estadoHash = generarEstadoHash($module_name, $estadoCliente);

    $oGrid  = new paloSantoGrid($smarty);
    $oGrid->pagingShow(FALSE);
    $json = new Services_JSON();
    $INITIAL_CLIENT_STATE = $json->encode($jsonData);
    $INITIAL_SHIFT_START = strtotime($shiftRange['start']);
    $INITIAL_SHIFT_END = strtotime($shiftRange['end']);
    $INITIAL_SHIFT_LABEL = $json->encode(shiftRangeLabel($shiftRange));
    $sJsonInitialize = <<<JSON_INITIALIZE
<script type="text/javascript">
$(function() {
    initialize_client_state($INITIAL_CLIENT_STATE, '$estadoHash', $INITIAL_SHIFT_START, $INITIAL_SHIFT_END, $shiftFrom, $shiftTo, $INITIAL_SHIFT_LABEL);
});
</script>
JSON_INITIALIZE;

    // Build shift filter HTML
    $sHoursOptions = '';
    for ($h = 0; $h < 24; $h++) {
        $sHourVal = sprintf('%02d', $h);
        $sHoursOptions .= '<option value="'.$sHourVal.'">'.$sHourVal.':00</option>';
    }
    $sShiftFilterHTML = '<div id="shiftFilterPanel" style="margin-bottom: 10px; padding: 8px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;">'
        . '<label for="shiftFromHour" style="font-weight: bold;">'._tr('Shift From').':</label> '
        . '<select id="shiftFromHour" style="margin-right: 15px;">'.$sHoursOptions.'</select>'
        . '<label for="shiftToHour" style="font-weight: bold;">'._tr('Shift To').':</label> '
        . '<select id="shiftToHour" style="margin-right: 15px;">'.$sHoursOptions.'</select>'
        . '<button id="applyShiftFilter" type="button" class="ui-button ui-widget ui-state-default ui-corner-all" style="padding: 4px 12px;">'._tr('Apply').'</button>'
        . '<span id="shiftRangeIndicator" style="margin-left: 15px; font-style: italic; color: #666;"></span>'
        . '</div>';

    return $sShiftFilterHTML . $oGrid->fetchGrid(array(
            'title'     =>  _tr('Agents Monitoring'),
            'icon'      =>  _tr('images/list.png'),
            'width'     =>  '99%',
            'start'     =>  1,
            'end'       =>  1,
            'total'     =>  1,
            'url'       =>  array('menu' => $module_name),
            'columns'   =>  array(
                array('name'    =>  _tr('Queue')),
                array('name'    =>  _tr('Number')),
                array('name'    =>  _tr('Agent')),
                array('name'    =>  _tr('Current status')),
                array('name'    =>  _tr('Total calls')),
                array('name'    =>  _tr('Total login time')),
                array('name'    =>  _tr('Total talk time')),
                array('name'    =>  _tr('Total break time')),
                array('name'    =>  _tr('Total hold time')),
            ),
        ), $arrData, $arrLang).
        $sJsonInitialize;
}

function timestamp_format($i)
{
	return sprintf('%02d:%02d:%02d',
        ($i - ($i % 3600)) / 3600,
        (($i - ($i % 60)) / 60) % 60,
        $i % 60);
}

function calculateShiftDatetimeRange($fromHour, $toHour, $now = NULL)
{
    if (is_null($now)) $now = time();
    $today = date('Y-m-d', $now);
    $yesterday = date('Y-m-d', $now - 86400);
    $tomorrow = date('Y-m-d', $now + 86400);
    $fromHour = max(0, min(23, (int)$fromHour));
    $toHour = max(0, min(23, (int)$toHour));

    if ($fromHour > $toHour) {
        // Overnight shift. Once the current hour has reached the From hour,
        // tonight's shift is the one in progress (today -> tomorrow);
        // otherwise it is the last one that ended (yesterday -> today).
        if (date('G', $now) >= $fromHour) {
            $datetimeStart = $today . ' ' . sprintf('%02d:00:00', $fromHour);
            $datetimeEnd = $tomorrow . ' ' . sprintf('%02d:59:59', $toHour);
        } else {
            $datetimeStart = $yesterday . ' ' . sprintf('%02d:00:00', $fromHour);
            $datetimeEnd = $today . ' ' . sprintf('%02d:59:59', $toHour);
        }
    } else {
        // Same-day shift
        $datetimeStart = $today . ' ' . sprintf('%02d:00:00', $fromHour);
        $datetimeEnd = $today . ' ' . sprintf('%02d:59:59', $toHour);
    }
    return array('start' => $datetimeStart, 'end' => $datetimeEnd);
}

function getSessionShiftRange($module_name)
{
    $shiftFrom = isset($_SESSION[$module_name]['shift_from'])
        ? (int)$_SESSION[$module_name]['shift_from'] : 0;
    $shiftTo = isset($_SESSION[$module_name]['shift_to'])
        ? (int)$_SESSION[$module_name]['shift_to'] : 23;
    return calculateShiftDatetimeRange($shiftFrom, $shiftTo);
}

function shiftRangeLabel($range, $now = NULL)
{
    if (is_null($now)) $now = time();
    $today = date('Y-m-d', $now);
    $sStartDate = date('Y-m-d', strtotime($range['start']));
    $sEndDate = date('Y-m-d', strtotime($range['end']));
    $sFromHour = date('H', strtotime($range['start']));
    $sToHour = date('H', strtotime($range['end']));

    // Day word of a range date against the current date. Under the window
    // rule the start day is always today or yesterday and the end day
    // tomorrow or today, so only these three words can appear.
    $dayWord = function ($sDate) use ($today) {
        if ($sDate == $today) return _tr('Today');
        if ($sDate == date('Y-m-d', strtotime($today . ' -1 day'))) return _tr('Yesterday');
        if ($sDate == date('Y-m-d', strtotime($today . ' +1 day'))) return _tr('Tomorrow');
        return $sDate;
    };

    if ($sStartDate == $sEndDate) {
        return $dayWord($sStartDate) . ' ' . $sFromHour . ':00 - ' . $sToHour . ':59';
    }
    return $dayWord($sStartDate) . ' ' . $sFromHour . ':00 - '
        . $dayWord($sEndDate) . ' ' . $sToHour . ':59';
}

function consultarTiempoBreakAgentes($datetimeStart = NULL, $datetimeEnd = NULL)
{
    global $arrConf;
    $result = array('breakTimes' => array(), 'holdNames' => array());

    try {
        $pDB = new PDO(
            'mysql:host=localhost;dbname=call_center;charset=utf8',
            'asterisk', 'asterisk'
        );
        $pDB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        return $result;
    }

    // Default to full day if no shift range provided
    if (is_null($datetimeStart) || is_null($datetimeEnd)) {
        $sToday = date('Y-m-d');
        $datetimeStart = "$sToday 00:00:00";
        $datetimeEnd = "$sToday 23:59:59";
    }

    // For running breaks (datetime_end IS NULL), use PHP-calculated current
    // time capped at the shift end, so each row counts only its in-window
    // seconds.
    $sNow = date('Y-m-d H:i:s');
    $sActiveEnd = ($sNow < $datetimeEnd) ? $sNow : $datetimeEnd;

    // Query cumulative break time per agent (excluding Hold). GREATEST/LEAST
    // clips each row to the shift boundaries; the overlap WHERE keeps only
    // rows that intersect the window.
    $sql = "SELECT CONCAT(agent.type, '/', agent.number) AS agentchannel, " .
           "SUM(UNIX_TIMESTAMP(LEAST(COALESCE(audit.datetime_end, :active_end), :end1)) " .
           "  - UNIX_TIMESTAMP(GREATEST(audit.datetime_init, :start1))) AS sec_breaks " .
           "FROM audit " .
           "INNER JOIN break ON break.id = audit.id_break " .
           "INNER JOIN agent ON agent.id = audit.id_agent " .
           "WHERE break.tipo = 'B' " .
           "AND audit.datetime_init <= :end2 " .
           "AND (audit.datetime_end IS NULL OR audit.datetime_end >= :start2) " .
           "GROUP BY agent.id " .
           "HAVING sec_breaks > 0";
    $stmt = $pDB->prepare($sql);
    $stmt->execute(array(
        ':active_end' => $sActiveEnd,
        ':start1'     => $datetimeStart,
        ':end1'       => $datetimeEnd,
        ':start2'     => $datetimeStart,
        ':end2'       => $datetimeEnd,
    ));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result['breakTimes'][$row['agentchannel']] = (int)$row['sec_breaks'];
    }

    // Query Hold-type break names
    $sql2 = "SELECT name FROM break WHERE tipo = 'H' AND status = 'A'";
    $stmt2 = $pDB->query($sql2);
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $result['holdNames'][] = $row['name'];
    }

    $pDB = null;
    return $result;
}

function consultarTiempoHoldAgentes($datetimeStart = NULL, $datetimeEnd = NULL)
{
    $result = array();

    try {
        $pDB = new PDO(
            'mysql:host=localhost;dbname=call_center;charset=utf8',
            'asterisk', 'asterisk'
        );
        $pDB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        return $result;
    }

    // Default to full day if no shift range provided
    if (is_null($datetimeStart) || is_null($datetimeEnd)) {
        $sToday = date('Y-m-d');
        $datetimeStart = "$sToday 00:00:00";
        $datetimeEnd = "$sToday 23:59:59";
    }

    // For running holds (datetime_end IS NULL), use PHP-calculated current
    // time capped at the shift end, so each row counts only its in-window
    // seconds.
    $sNow = date('Y-m-d H:i:s');
    $sActiveEnd = ($sNow < $datetimeEnd) ? $sNow : $datetimeEnd;

    // Query cumulative hold time per agent (Hold-type pauses only).
    // GREATEST/LEAST clips each row to the shift boundaries; the overlap
    // WHERE keeps only rows that intersect the window.
    $sql = "SELECT CONCAT(agent.type, '/', agent.number) AS agentchannel, " .
           "SUM(UNIX_TIMESTAMP(LEAST(COALESCE(audit.datetime_end, :active_end), :end1)) " .
           "  - UNIX_TIMESTAMP(GREATEST(audit.datetime_init, :start1))) AS sec_holds " .
           "FROM audit " .
           "INNER JOIN break ON break.id = audit.id_break " .
           "INNER JOIN agent ON agent.id = audit.id_agent " .
           "WHERE break.tipo = 'H' " .
           "AND audit.datetime_init <= :end2 " .
           "AND (audit.datetime_end IS NULL OR audit.datetime_end >= :start2) " .
           "GROUP BY agent.id " .
           "HAVING sec_holds > 0";
    $stmt = $pDB->prepare($sql);
    $stmt->execute(array(
        ':active_end' => $sActiveEnd,
        ':start1'     => $datetimeStart,
        ':end1'       => $datetimeEnd,
        ':start2'     => $datetimeStart,
        ':end2'       => $datetimeEnd,
    ));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[$row['agentchannel']] = (int)$row['sec_holds'];
    }

    $pDB = null;
    return $result;
}

function consultarTiempoLoginAgentes($datetimeStart = NULL, $datetimeEnd = NULL)
{
    $result = array();

    try {
        $pDB = new PDO(
            'mysql:host=localhost;dbname=call_center;charset=utf8',
            'asterisk', 'asterisk'
        );
        $pDB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        return $result;
    }

    if (is_null($datetimeStart) || is_null($datetimeEnd)) {
        $sToday = date('Y-m-d');
        $datetimeStart = "$sToday 00:00:00";
        $datetimeEnd = "$sToday 23:59:59";
    }

    // For active sessions (datetime_end IS NULL), use PHP-calculated current time
    // capped at the shift end, so active sessions are clipped correctly.
    $sNow = date('Y-m-d H:i:s');
    $sActiveEnd = ($sNow < $datetimeEnd) ? $sNow : $datetimeEnd;

    // Query login sessions (id_break IS NULL) overlapping with shift range.
    // GREATEST/LEAST clips each session to the shift boundaries.
    // Active sessions use :active_end (PHP now, capped at shift end) instead of NOW().
    $sql = "SELECT CONCAT(agent.type, '/', agent.number) AS agentchannel, " .
           "SUM(" .
           "  UNIX_TIMESTAMP(LEAST(COALESCE(audit.datetime_end, :active_end), :end1)) " .
           "  - UNIX_TIMESTAMP(GREATEST(audit.datetime_init, :start1))" .
           ") AS logintime " .
           "FROM audit " .
           "INNER JOIN agent ON agent.id = audit.id_agent " .
           "WHERE audit.id_break IS NULL " .
           "AND audit.datetime_init <= :end2 " .
           "AND (audit.datetime_end IS NULL OR audit.datetime_end >= :start2) " .
           "GROUP BY agent.id " .
           "HAVING logintime > 0";
    $stmt = $pDB->prepare($sql);
    $stmt->execute(array(
        ':active_end' => $sActiveEnd,
        ':start1'     => $datetimeStart,
        ':end1'       => $datetimeEnd,
        ':start2'     => $datetimeStart,
        ':end2'       => $datetimeEnd,
    ));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[$row['agentchannel']] = (int)$row['logintime'];
    }

    $pDB = null;
    return $result;
}

function consultarLlamadasAgentes($datetimeStart = NULL, $datetimeEnd = NULL)
{
    $result = array();

    try {
        $pDB = new PDO(
            'mysql:host=localhost;dbname=call_center;charset=utf8',
            'asterisk', 'asterisk'
        );
        $pDB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        return $result;
    }

    if (is_null($datetimeStart) || is_null($datetimeEnd)) {
        $sToday = date('Y-m-d');
        $datetimeStart = "$sToday 00:00:00";
        $datetimeEnd = "$sToday 23:59:59";
    }

    // Incoming calls within shift range (completed only). Counts keep the
    // entry-time attribution; each row's seconds are clipped to the window.
    $sql = "SELECT CONCAT(agent.type, '/', agent.number) AS agentchannel, " .
           "SUM(CASE WHEN call_entry.datetime_init IS NULL THEN 0 ELSE GREATEST(0, " .
           "  UNIX_TIMESTAMP(LEAST(call_entry.datetime_end, :end1)) " .
           "  - UNIX_TIMESTAMP(GREATEST(call_entry.datetime_init, :start1))) END) AS sec_calls, " .
           "COUNT(*) AS num_calls " .
           "FROM call_entry " .
           "INNER JOIN agent ON agent.id = call_entry.id_agent " .
           "WHERE call_entry.datetime_init >= :start2 " .
           "AND call_entry.datetime_init <= :end2 " .
           "AND call_entry.duration IS NOT NULL " .
           "GROUP BY call_entry.id_agent";
    $stmt = $pDB->prepare($sql);
    $stmt->execute(array(
        ':start1' => $datetimeStart,
        ':end1'   => $datetimeEnd,
        ':start2' => $datetimeStart,
        ':end2'   => $datetimeEnd,
    ));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ch = $row['agentchannel'];
        $result[$ch]['sec_calls'] = (isset($result[$ch]['sec_calls']) ? $result[$ch]['sec_calls'] : 0) + (int)$row['sec_calls'];
        $result[$ch]['num_calls'] = (isset($result[$ch]['num_calls']) ? $result[$ch]['num_calls'] : 0) + (int)$row['num_calls'];
    }

    // Outgoing calls within shift range (completed only). Counts keep the
    // entry-time attribution; each row's seconds are clipped to the window.
    $sql2 = "SELECT CONCAT(agent.type, '/', agent.number) AS agentchannel, " .
            "SUM(CASE WHEN calls.start_time IS NULL THEN 0 ELSE GREATEST(0, " .
            "  UNIX_TIMESTAMP(LEAST(calls.end_time, :end1)) " .
            "  - UNIX_TIMESTAMP(GREATEST(calls.start_time, :start1))) END) AS sec_calls, " .
            "COUNT(*) AS num_calls " .
            "FROM calls " .
            "INNER JOIN agent ON agent.id = calls.id_agent " .
            "WHERE calls.start_time >= :start2 " .
            "AND calls.start_time <= :end2 " .
            "AND calls.duration IS NOT NULL " .
            "GROUP BY calls.id_agent";
    $stmt2 = $pDB->prepare($sql2);
    $stmt2->execute(array(
        ':start1' => $datetimeStart,
        ':end1'   => $datetimeEnd,
        ':start2' => $datetimeStart,
        ':end2'   => $datetimeEnd,
    ));
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $ch = $row['agentchannel'];
        $result[$ch]['sec_calls'] = (isset($result[$ch]['sec_calls']) ? $result[$ch]['sec_calls'] : 0) + (int)$row['sec_calls'];
        $result[$ch]['num_calls'] = (isset($result[$ch]['num_calls']) ? $result[$ch]['num_calls'] : 0) + (int)$row['num_calls'];
    }

    $pDB = null;
    return $result;
}

function construirDatosJSON(&$estadoMonitor, $breakData = array(), $holdData = array(), $loginData = array(), $callData = array(), $shiftRange = NULL)
{
    $iTimestampActual = time();
    // For the running quantities the server counts only up to the shift end
    $iNowClamped = is_null($shiftRange)
        ? $iTimestampActual
        : min($iTimestampActual, strtotime($shiftRange['end']));
    $jsonData = array();
    foreach ($estadoMonitor as $sQueue => $agentList) {
        ksort($agentList);
        foreach ($agentList as $sAgentChannel => $infoAgente) {
            $iTimestampEstado = NULL;
            $jsonKey = 'queue-'.$sQueue.'-member-'.strtolower(str_replace('/', '-', $sAgentChannel));

            switch ($infoAgente['agentstatus']) {
            case 'offline':
                if (!is_null($infoAgente['lastsessionend']))
                    $iTimestampEstado = strtotime($infoAgente['lastsessionend']);
                break;
            case 'online':
            case 'ringing':
                if (!is_null($infoAgente['lastsessionstart']))
                    $iTimestampEstado = strtotime($infoAgente['lastsessionstart']);
                break;
            case 'oncall':
                if (!is_null($infoAgente['linkstart']))
                    $iTimestampEstado = strtotime($infoAgente['linkstart']);
                break;
            case 'paused':
                if (!is_null($infoAgente['lastpausestart']))
                    $iTimestampEstado = strtotime($infoAgente['lastpausestart']);
                break;
            }

            // Talk time and call count: DB completed calls within shift + active call if any
            $sec_calls_completed = isset($callData[$sAgentChannel]['sec_calls']) ? $callData[$sAgentChannel]['sec_calls'] : 0;
            $num_calls_completed = isset($callData[$sAgentChannel]['num_calls']) ? $callData[$sAgentChannel]['num_calls'] : 0;
            $active_call_dur = 0;
            $is_oncall = !is_null($infoAgente['linkstart']);
            if ($is_oncall) {
                $active_call_dur = max(0, $iNowClamped - strtotime($infoAgente['linkstart']));
            }

            // Preparar estado inicial JSON
            $jsonData[$jsonKey] = array(
                'agentchannel'          =>  $sAgentChannel,
                'agentname'             =>  $infoAgente['agentname'],
                'status'                =>  $infoAgente['agentstatus'],
                'sec_laststatus'        =>  is_null($iTimestampEstado) ? NULL : ($iTimestampActual - $iTimestampEstado),
                'sec_calls'             =>  $sec_calls_completed + $active_call_dur,
                'sec_calls_completed'   =>  $sec_calls_completed,
                // Login time: the clamped query's total inside the window.
                // With a window set there is no fallback to the dialer's
                // whole-day total: no row in the window means the agent had
                // no login time inside it, so it reads zero.
                'logintime'             =>  isset($loginData[$sAgentChannel])
                    ? $loginData[$sAgentChannel]
                    : (is_null($shiftRange)
                        ? ($infoAgente['logintime'] + (
                            (is_null($infoAgente['lastsessionend']) && !is_null($infoAgente['lastsessionstart']))
                                ? $iNowClamped - strtotime($infoAgente['lastsessionstart'])
                                : 0))
                        : 0),
                'num_calls'             =>  $num_calls_completed + ($is_oncall ? 1 : 0),
                'num_calls_completed'   =>  $num_calls_completed,
                'oncallupdate'          =>  $is_oncall,
                'pausename'             =>  $infoAgente['pausename'],
                'onhold'                =>  $infoAgente['onhold'],
            );

            // Break time tracking. A running pause is counted by the clamped
            // query up to min(now, shift end); nothing is added here.
            $sec_breaks_completed = isset($breakData['breakTimes'][$sAgentChannel])
                ? $breakData['breakTimes'][$sAgentChannel] : 0;
            $isbreakpause = false;
            if ($infoAgente['agentstatus'] == 'paused' && !is_null($infoAgente['pausename'])) {
                $holdNames = isset($breakData['holdNames']) ? $breakData['holdNames'] : array();
                $isbreakpause = !in_array($infoAgente['pausename'], $holdNames);
            }
            $jsonData[$jsonKey]['sec_breaks'] = $sec_breaks_completed;
            $jsonData[$jsonKey]['sec_breaks_completed'] = $sec_breaks_completed;
            $jsonData[$jsonKey]['isbreakpause'] = $isbreakpause;

            // Hold time tracking. A running hold is counted by the clamped
            // query up to min(now, shift end); nothing is added here.
            $sec_holds_completed = isset($holdData[$sAgentChannel])
                ? $holdData[$sAgentChannel] : 0;
            $isholdpause = false;
            if ($infoAgente['onhold'] && !is_null($infoAgente['pausename'])) {
                $holdNames = isset($breakData['holdNames']) ? $breakData['holdNames'] : array();
                $isholdpause = in_array($infoAgente['pausename'], $holdNames);
            }
            $jsonData[$jsonKey]['sec_holds'] = $sec_holds_completed;
            $jsonData[$jsonKey]['sec_holds_completed'] = $sec_holds_completed;
            $jsonData[$jsonKey]['isholdpause'] = $isholdpause;
        }
    }
    return $jsonData;
}


function manejarMonitoreo_checkStatus($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola)
{
    $respuesta = array();
    setupSSESession();

    // Estado del lado del cliente
    $estadoHash = getParameter('clientstatehash');
    if (!is_null($estadoHash)) {
        $estadoCliente = isset($_SESSION[$module_name]['estadoCliente'])
            ? $_SESSION[$module_name]['estadoCliente']
            : array();
    } else {
        $estadoCliente = getParameter('clientstate');
        if (!is_array($estadoCliente)) return;
    }
    foreach (array_keys($estadoCliente) as $k)
        $estadoCliente[$k]['oncallupdate'] = ($estadoCliente[$k]['oncallupdate'] == 'true');

    // The server owns the shift filter (default: full day 00-23). Valid GET
    // parameters are stored in the session; otherwise the session's stored
    // filter is used, so a bare reload keeps the window the user applied.
    $shiftFrom = getParameter('shift_from');
    $shiftTo = getParameter('shift_to');
    if (!is_null($shiftFrom) && $shiftFrom !== '' && ctype_digit("$shiftFrom")
        && (int)$shiftFrom <= 23
        && !is_null($shiftTo) && $shiftTo !== '' && ctype_digit("$shiftTo")
        && (int)$shiftTo <= 23) {
        $_SESSION[$module_name]['shift_from'] = (int)$shiftFrom;
        $_SESSION[$module_name]['shift_to'] = (int)$shiftTo;
    }
    $shiftFrom = isset($_SESSION[$module_name]['shift_from'])
        ? (int)$_SESSION[$module_name]['shift_from'] : 0;
    $shiftTo = isset($_SESSION[$module_name]['shift_to'])
        ? (int)$_SESSION[$module_name]['shift_to'] : 23;
    $shiftRange = calculateShiftDatetimeRange($shiftFrom, $shiftTo);
    $iShiftStart = strtotime($shiftRange['start']);
    $iShiftEnd = strtotime($shiftRange['end']);
    $sShiftLabel = shiftRangeLabel($shiftRange);

    // Modo a funcionar: Long-Polling, o Server-sent Events
    $bSSE = detectSSEMode();
    initSSE($bSSE);

    // Verificar hash correcto
    if (!is_null($estadoHash) && $estadoHash != $_SESSION[$module_name]['estadoClienteHash']) {
    	$respuesta['estadoClienteHash'] = 'mismatch';
        jsonflush($bSSE, $respuesta);
        $oPaloConsola->desconectarTodo();
        return;
    }

    // Estado del lado del servidor
    $estadoMonitor = $oPaloConsola->listarEstadoMonitoreoAgentes();
    if (!is_array($estadoMonitor)) {
        $respuesta['error'] = $oPaloConsola->errMsg;
        jsonflush($bSSE, $respuesta);
    	$oPaloConsola->desconectarTodo();
        return;
    }

    // Acumular inmediatamente las filas que son distintas en estado
    ksort($estadoMonitor);
    $breakData = consultarTiempoBreakAgentes($shiftRange['start'], $shiftRange['end']);
    $holdData  = consultarTiempoHoldAgentes($shiftRange['start'], $shiftRange['end']);
    $loginData = consultarTiempoLoginAgentes($shiftRange['start'], $shiftRange['end']);
    $callData  = consultarLlamadasAgentes($shiftRange['start'], $shiftRange['end']);
    $jsonData = construirDatosJSON($estadoMonitor, $breakData, $holdData, $loginData, $callData, $shiftRange);
    foreach ($jsonData as $jsonKey => $jsonRow) {
    	if (isset($estadoCliente[$jsonKey])) {
    		if ($estadoCliente[$jsonKey]['status'] != $jsonRow['status'] ||
                $estadoCliente[$jsonKey]['oncallupdate'] != $jsonRow['oncallupdate'] ||
                $estadoCliente[$jsonKey]['onhold'] != $jsonRow['onhold']) {
                $respuesta[$jsonKey] = $jsonRow;
                $estadoCliente[$jsonKey]['status'] = $jsonRow['status'];
                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonRow['oncallupdate'];
                $estadoCliente[$jsonKey]['onhold'] = $jsonRow['onhold'];
                unset($respuesta[$jsonKey]['agentname']);
            }
    	}
    }

    $iTimeoutPoll = $oPaloConsola->recomendarIntervaloEsperaAjax();
    do {
        $oPaloConsola->desconectarEspera();

        // Re-poll full state to catch changes not reflected in events (e.g., ringing status)
        $estadoMonitorActual = $oPaloConsola->listarEstadoMonitoreoAgentes();
        if (is_array($estadoMonitorActual)) {
            ksort($estadoMonitorActual);
            $breakData = consultarTiempoBreakAgentes($shiftRange['start'], $shiftRange['end']);
            $holdData  = consultarTiempoHoldAgentes($shiftRange['start'], $shiftRange['end']);
            $loginData = consultarTiempoLoginAgentes($shiftRange['start'], $shiftRange['end']);
            $callData  = consultarLlamadasAgentes($shiftRange['start'], $shiftRange['end']);
            $jsonDataActual = construirDatosJSON($estadoMonitorActual, $breakData, $holdData, $loginData, $callData, $shiftRange);
            foreach ($jsonDataActual as $jsonKey => $jsonRow) {
                if (isset($estadoCliente[$jsonKey])) {
                    if ($estadoCliente[$jsonKey]['status'] != $jsonRow['status'] ||
                        $estadoCliente[$jsonKey]['oncallupdate'] != $jsonRow['oncallupdate'] ||
                        $estadoCliente[$jsonKey]['onhold'] != $jsonRow['onhold']) {
                        $respuesta[$jsonKey] = $jsonRow;
                        $estadoCliente[$jsonKey]['status'] = $jsonRow['status'];
                        $estadoCliente[$jsonKey]['oncallupdate'] = $jsonRow['oncallupdate'];
                        $estadoCliente[$jsonKey]['onhold'] = $jsonRow['onhold'];
                        unset($respuesta[$jsonKey]['agentname']);
                    }
                }
            }
            // Update local state for event handling
            $estadoMonitor = $estadoMonitorActual;
            $jsonData = $jsonDataActual;
        }

        // Se inicia espera larga con el navegador...
        session_commit();
        $iTimestampInicio = time();

        while (connection_status() == CONNECTION_NORMAL && count($respuesta) <= 0
            && time() - $iTimestampInicio <  $iTimeoutPoll) {

            $listaEventos = $oPaloConsola->esperarEventoSesionActiva();
            if (is_null($listaEventos)) {
                $respuesta['error'] = $oPaloConsola->errMsg;
                jsonflush($bSSE, $respuesta);
                $oPaloConsola->desconectarTodo();
                return;
            }

            // Re-poll state after each event wait to catch ringing/device status changes
            // that don't generate events (QueueMemberStatus -> queue_status changes)
            $estadoMonitorActual = $oPaloConsola->listarEstadoMonitoreoAgentes();
            if (is_array($estadoMonitorActual)) {
                ksort($estadoMonitorActual);
                $breakData = consultarTiempoBreakAgentes($shiftRange['start'], $shiftRange['end']);
                $holdData  = consultarTiempoHoldAgentes($shiftRange['start'], $shiftRange['end']);
                $loginData = consultarTiempoLoginAgentes($shiftRange['start'], $shiftRange['end']);
                $callData  = consultarLlamadasAgentes($shiftRange['start'], $shiftRange['end']);
                $jsonDataActual = construirDatosJSON($estadoMonitorActual, $breakData, $holdData, $loginData, $callData, $shiftRange);
                foreach ($jsonDataActual as $jsonKey => $jsonRow) {
                    if (isset($estadoCliente[$jsonKey])) {
                        if ($estadoCliente[$jsonKey]['status'] != $jsonRow['status'] ||
                            $estadoCliente[$jsonKey]['oncallupdate'] != $jsonRow['oncallupdate'] ||
                            $estadoCliente[$jsonKey]['onhold'] != $jsonRow['onhold']) {
                            $respuesta[$jsonKey] = $jsonRow;
                            $estadoCliente[$jsonKey]['status'] = $jsonRow['status'];
                            $estadoCliente[$jsonKey]['oncallupdate'] = $jsonRow['oncallupdate'];
                            $estadoCliente[$jsonKey]['onhold'] = $jsonRow['onhold'];
                            unset($respuesta[$jsonKey]['agentname']);
                        }
                    }
                }
                $estadoMonitor = $estadoMonitorActual;
                $jsonData = $jsonDataActual;
            }

            $iTimestampActual = time();
            // Call durations in the event handlers stop at the shift end
            $iNowClamped = min($iTimestampActual, strtotime($shiftRange['end']));
            foreach ($listaEventos as $evento) {
                $sNumeroAgente = $sCanalAgente = $evento['agent_number'];
                $sNumeroAgente = strtolower(str_replace('/', '-', $sCanalAgente));

            	switch ($evento['event']) {
            	case 'agentloggedin':
                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                        	$jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] == 'offline') {

                                // Estado en el estado de monitor
                                $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = 'online';
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart'] = date('Y-m-d H:i:s', $iTimestampActual);
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastsessionend'] = NULL;
                                if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']) &&
                                    is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'])) {
                                	$estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'] = date('Y-m-d H:i:s', $iTimestampActual);
                                }
                                $estadoMonitor[$sQueue][$sCanalAgente]['linkstart'] = NULL;

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                $jsonData[$jsonKey]['sec_laststatus'] = 0;
                                $jsonData[$jsonKey]['oncallupdate'] = FALSE;

                                // Break time: agent just logged in, no active break
                                $jsonData[$jsonKey]['isbreakpause'] = false;
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];
                                $estadoCliente[$jsonKey]['onhold'] = $jsonData[$jsonKey]['onhold'];

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'agentloggedout':
                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                            $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != 'offline') {

                                // Estado en el estado de monitor
                                $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = 'offline';
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastsessionend'] = date('Y-m-d H:i:s', $iTimestampActual);
                                if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']) &&
                                    is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'])) {
                                    $estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'] = date('Y-m-d H:i:s', $iTimestampActual);
                                }
                                $estadoMonitor[$sQueue][$sCanalAgente]['linkstart'] = NULL;

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                $jsonData[$jsonKey]['sec_laststatus'] = 0;
                                $jsonData[$jsonKey]['oncallupdate'] = FALSE;
                                // logintime comes from DB via re-poll (shift-filtered)

                                // Break time: the clamped query recounts the
                                // closed audit row on the next re-poll
                                $jsonData[$jsonKey]['isbreakpause'] = false;
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];
                                $estadoCliente[$jsonKey]['onhold'] = $jsonData[$jsonKey]['onhold'];

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'pausestart':
                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                            $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != 'offline') {

                                // Estado en el estado de monitor
                                if ($estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] != 'oncall')
                                    $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = 'paused';
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart'] = date('Y-m-d H:i:s', $iTimestampActual);
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'] = NULL;

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                if ($jsonData[$jsonKey]['status'] == 'oncall') {
                                    if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart'])) {
                                        $iTimestampInicio = strtotime($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']);
                                        $iDuracionLlamada = $iNowClamped - $iTimestampInicio;
                                        if ($iDuracionLlamada >= 0) {
                                            $jsonData[$jsonKey]['sec_laststatus'] = $iDuracionLlamada;
                                            $jsonData[$jsonKey]['sec_calls'] =
                                                $estadoMonitor[$sQueue][$sCanalAgente]['sec_calls'] + $iDuracionLlamada;
                                        }
                                    }
                                } else {
                                    $jsonData[$jsonKey]['sec_laststatus'] = 0;
                                }
                                // logintime comes from DB via re-poll (shift-filtered)

                                // Update onhold flag based on event pause class
                                if (isset($evento['pause_class']) && $evento['pause_class'] == 'hold') {
                                    $jsonData[$jsonKey]['onhold'] = true;
                                }

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];
                                $estadoCliente[$jsonKey]['onhold'] = $jsonData[$jsonKey]['onhold'];

                                // Nombre de la pausa
                                $jsonData[$jsonKey]['pausename'] = $evento['pause_name'];

                                // Break time: determine if this is a break-type pause.
                                // A running pause is counted by the clamped query
                                // up to min(now, shift end); nothing is added here.
                                $holdNames = isset($breakData['holdNames']) ? $breakData['holdNames'] : array();
                                $jsonData[$jsonKey]['isbreakpause'] = ($jsonData[$jsonKey]['status'] == 'paused'
                                    && !in_array($evento['pause_name'], $holdNames));
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'pauseend':
                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                            $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != 'offline') {

                                // Estado en el estado de monitor
                                if ($estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] != 'oncall')
                                    $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = 'online';
                                $estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend'] = date('Y-m-d H:i:s', $iTimestampActual);

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                if ($jsonData[$jsonKey]['status'] == 'oncall') {
                                    if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart'])) {
                                        $iTimestampInicio = strtotime($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']);
                                        $iDuracionLlamada = $iNowClamped - $iTimestampInicio;
                                        if ($iDuracionLlamada >= 0) {
                                            $jsonData[$jsonKey]['sec_laststatus'] = $iDuracionLlamada;
                                            $jsonData[$jsonKey]['sec_calls'] =
                                                $estadoMonitor[$sQueue][$sCanalAgente]['sec_calls'] + $iDuracionLlamada;
                                        }
                                    }
                                } else {
                                    $jsonData[$jsonKey]['sec_laststatus'] =
                                        $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart']);
                                }
                                // logintime comes from DB via re-poll (shift-filtered)

                                // Break time: the clamped query recounts the
                                // closed audit row on the next re-poll
                                $jsonData[$jsonKey]['isbreakpause'] = false;
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];

                                // Update onhold flag based on event pause class
                                if (isset($evento['pause_class']) && $evento['pause_class'] == 'hold') {
                                    $jsonData[$jsonKey]['onhold'] = false;
                                }

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];
                                $estadoCliente[$jsonKey]['onhold'] = $jsonData[$jsonKey]['onhold'];

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'agentlinked':
                    // Averiguar la cola por la que entró la llamada nueva
                    $sCallQueue = $evento['queue'];
                    if (is_null($sCallQueue)) {
                    	$infoCampania = $oPaloConsola->leerInfoCampania(
                            $evento['call_type'],
                            $evento['campaign_id']);
                        if (!is_null($infoCampania)) $sCallQueue = $infoCampania['queue'];
                    }

                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                            $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != 'offline') {

                                // Estado en el estado de monitor
                                $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = 'oncall';
                                $estadoMonitor[$sQueue][$sCanalAgente]['linkstart'] = NULL;
                                if ($sCallQueue == $sQueue) {
                                    $estadoMonitor[$sQueue][$sCanalAgente]['num_calls']++;
                                    $estadoMonitor[$sQueue][$sCanalAgente]['linkstart'] = $evento['datetime_linkstart'];
                                }

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                $jsonData[$jsonKey]['sec_laststatus'] =
                                    is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart'])
                                        ? NULL
                                        : $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']);
                                // sec_calls/num_calls: DB completed + active call contribution
                                $iActiveDur = is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']) ? 0
                                    : max(0, $iNowClamped - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']));
                                $jsonData[$jsonKey]['sec_calls'] = $jsonData[$jsonKey]['sec_calls_completed'] + $iActiveDur;
                                $jsonData[$jsonKey]['num_calls'] = $jsonData[$jsonKey]['num_calls_completed']
                                    + (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']) ? 1 : 0);
                                $jsonData[$jsonKey]['oncallupdate'] = !is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']);
                                // logintime comes from DB via re-poll (shift-filtered)

                                // Break time: a running pause is counted by the
                                // clamped query; nothing is added here
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];
                                $jsonData[$jsonKey]['isbreakpause'] = false;

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];
                                $estadoCliente[$jsonKey]['onhold'] = $jsonData[$jsonKey]['onhold'];

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'agentunlinked':
                    foreach (array_keys($estadoMonitor) as $sQueue) {
                        if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                            $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                            if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != 'offline') {

                                // Estado en el estado de monitor
                                $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] =
                                    (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']) && is_null($estadoMonitor[$sQueue][$sCanalAgente]['lastpauseend']))
                                    ? 'paused' : 'online';
                                // Add the just-ended call to completed totals (DB not yet updated)
                                if (!is_null($estadoMonitor[$sQueue][$sCanalAgente]['linkstart'])) {
                                    $iDuracionLlamada = max(0,
                                        $iNowClamped - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['linkstart']));
                                    $jsonData[$jsonKey]['sec_calls_completed'] += $iDuracionLlamada;
                                    $jsonData[$jsonKey]['num_calls_completed']++;
                                }
                                $estadoMonitor[$sQueue][$sCanalAgente]['linkstart'] = NULL;

                                // Estado en la estructura JSON
                                $jsonData[$jsonKey]['status'] = $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'];
                                if ($jsonData[$jsonKey]['status'] == 'paused') {
                                    $jsonData[$jsonKey]['sec_laststatus'] =
                                        $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastpausestart']);
                                } else {
                                    $jsonData[$jsonKey]['sec_laststatus'] =
                                        $iTimestampActual - strtotime($estadoMonitor[$sQueue][$sCanalAgente]['lastsessionstart']);
                                }
                                $jsonData[$jsonKey]['sec_calls'] = $jsonData[$jsonKey]['sec_calls_completed'];
                                $jsonData[$jsonKey]['num_calls'] = $jsonData[$jsonKey]['num_calls_completed'];
                                $jsonData[$jsonKey]['oncallupdate'] = FALSE;
                                // logintime comes from DB via re-poll (shift-filtered)

                                // Break time: check if agent returns to a break-type pause
                                if ($jsonData[$jsonKey]['status'] == 'paused') {
                                    $sPauseName = isset($jsonData[$jsonKey]['pausename']) ? $jsonData[$jsonKey]['pausename'] : null;
                                    $holdNames = isset($breakData['holdNames']) ? $breakData['holdNames'] : array();
                                    $jsonData[$jsonKey]['isbreakpause'] = !is_null($sPauseName) && !in_array($sPauseName, $holdNames);
                                } else {
                                    $jsonData[$jsonKey]['isbreakpause'] = false;
                                }
                                // Break time: a running pause is counted by the
                                // clamped query; nothing is added here
                                $jsonData[$jsonKey]['sec_breaks'] = $jsonData[$jsonKey]['sec_breaks_completed'];

                                // Estado del cliente
                                $estadoCliente[$jsonKey]['status'] = $jsonData[$jsonKey]['status'];
                                $estadoCliente[$jsonKey]['oncallupdate'] = $jsonData[$jsonKey]['oncallupdate'];
                                $estadoCliente[$jsonKey]['onhold'] = $jsonData[$jsonKey]['onhold'];

                                // Estado a emitir al cliente
                                $respuesta[$jsonKey] = $jsonData[$jsonKey];
                                unset($respuesta[$jsonKey]['agentname']);
                            }
                        }
                    }
                    break;
                case 'agentstatechange':
                    // Real-time agent state change (e.g., ringing status)
                    $sQueue = $evento['queue'];
                    $sNewStatus = $evento['status'];
                    if (isset($estadoMonitor[$sQueue][$sCanalAgente])) {
                        $jsonKey = 'queue-'.$sQueue.'-member-'.$sNumeroAgente;
                        if (isset($jsonData[$jsonKey]) && $jsonData[$jsonKey]['status'] != $sNewStatus) {
                            // Update monitor state
                            $estadoMonitor[$sQueue][$sCanalAgente]['agentstatus'] = $sNewStatus;

                            // Update JSON data
                            $jsonData[$jsonKey]['status'] = $sNewStatus;
                            $jsonData[$jsonKey]['sec_laststatus'] = 0;

                            // Update client state
                            $estadoCliente[$jsonKey]['status'] = $sNewStatus;

                            // Emit to client
                            $respuesta[$jsonKey] = $jsonData[$jsonKey];
                            unset($respuesta[$jsonKey]['agentname']);
                        }
                    }
                    break;
            	}
            }


        }
        if (count($respuesta) > 0) {
            @session_start();
            $estadoHash = generarEstadoHash($module_name, $estadoCliente);
            $respuesta['estadoClienteHash'] = $estadoHash;
            session_commit();
        }
        // The window rides with every poll response, beside the hash. It is
        // added here, after the long wait, so an empty response does not
        // defeat the wait; the reset below clears it for the next round.
        $respuesta['shift_start_epoch'] = $iShiftStart;
        $respuesta['shift_end_epoch'] = $iShiftEnd;
        $respuesta['shift_label'] = $sShiftLabel;
        jsonflush($bSSE, $respuesta);

        $respuesta = array();

    } while ($bSSE && connection_status() == CONNECTION_NORMAL);
    $oPaloConsola->desconectarTodo();
}


?>
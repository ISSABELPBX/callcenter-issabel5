<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 0.8                                                  |
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
  $Id: default.conf.php,v 1.1.1.1 2007/03/23 00:13:58 elandivar Exp $ */


require_once "modules/agent_console/libs/issabel2.lib.php";
require_once "modules/agent_console/libs/JSON.php";
require_once "modules/agent_console/libs/paloSantoConsola.class.php";

/**
 * Calculate datetime range based on shift hours.
 * Supports overnight shifts where fromHour > toHour (e.g., 22:00 to 06:00).
 *
 * Once the current hour has reached the fromHour of an overnight shift,
 * the shift in progress is tonight's (today -> tomorrow); otherwise it is
 * the last one that ended (yesterday -> today) - the same rule the agent
 * console's agentConsole_calculateShiftDatetimeRange applies.
 *
 * @param int $fromHour Hour to start shift (0-23)
 * @param int $toHour Hour to end shift (0-23)
 * @param int $now Timestamp the range is computed at (default: now)
 * @return array Array with 'start' and 'end' datetime strings
 */
function calculateShiftDatetimeRange($fromHour, $toHour, $now = NULL)
{
    if (is_null($now)) $now = time();
    $today = date('Y-m-d', $now);
    $yesterday = date('Y-m-d', $now - 86400);
    $tomorrow = date('Y-m-d', $now + 86400);

    $fromHour = (int)$fromHour;
    $toHour = (int)$toHour;

    // Clamp values to valid range
    $fromHour = max(0, min(23, $fromHour));
    $toHour = max(0, min(23, $toHour));

    if ($fromHour > $toHour) {
        // Overnight shift
        if (date('G', $now) >= $fromHour) {
            // Tonight's shift is the one in progress
            $datetimeStart = $today . ' ' . sprintf('%02d:00:00', $fromHour);
            $datetimeEnd = $tomorrow . ' ' . sprintf('%02d:59:59', $toHour);
        } else {
            // The last shift that ended
            $datetimeStart = $yesterday . ' ' . sprintf('%02d:00:00', $fromHour);
            $datetimeEnd = $today . ' ' . sprintf('%02d:59:59', $toHour);
        }
    } else {
        // Same-day shift: today's fromHour to today's toHour
        $datetimeStart = $today . ' ' . sprintf('%02d:00:00', $fromHour);
        $datetimeEnd = $today . ' ' . sprintf('%02d:59:59', $toHour);
    }

    return array(
        'start' => $datetimeStart,
        'end' => $datetimeEnd,
    );
}

/**
 * Build the range label the bar shows beside the filter. The server owns
 * it: it is computed with the window and the same $now, and the browser
 * only displays it. A same-day window renders "<word> HH:00 - HH:59"; an
 * overnight one "<word of start> HH:00 - <word of end> HH:59", each word
 * Yesterday, Today or Tomorrow against the current date.
 *
 * @param array $range Array with 'start' and 'end' datetime strings
 * @param int $now Timestamp the label is computed at (default: now)
 * @return string The label
 */
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

function _moduleContent(&$smarty, $module_name)
{
    global $arrConf;
    global $arrLang;
    global $arrConfig;

    //include module files
    include_once "modules/$module_name/configs/default.conf.php";

    // Se fusiona la configuración del módulo con la configuración global
    $arrConf = array_merge($arrConf, $arrConfModule);

    load_language_module($module_name);

    //folder path for custom templates
    $base_dir = dirname($_SERVER['SCRIPT_FILENAME']);
    $templates_dir = (isset($arrConfig['templates_dir']))?$arrConfig['templates_dir']:'themes';
    $local_templates_dir = "$base_dir/modules/$module_name/".$templates_dir.'/'.$arrConf['theme'];

    // Ember.js requiere jQuery 1.7.2 o superior.
    modificarReferenciasLibreriasJS($smarty);

    $sContenido = '';

    // Procesar los eventos AJAX.
    switch (getParameter('action')) {
        case 'getIncomingPanelData':
            $sContenido = manejarMonitoreo_getIncomingPanelData($module_name, $smarty, $local_templates_dir);
            break;
        case 'checkStatus':
            $sContenido = manejarMonitoreo_checkStatusPanel($module_name, $smarty, $local_templates_dir);
            break;
        default:
            $sContenido = manejarMonitoreo_HTML($module_name, $smarty, $local_templates_dir);
        }
        return $sContenido;
}

function manejarMonitoreo_HTML($module_name, $smarty, $sDirLocalPlantillas)
{
    // Build hours array for shift filter dropdowns (00-23)
    $hoursArray = array();
    for ($h = 0; $h < 24; $h++) {
        $hoursArray[] = sprintf('%02d', $h);
    }

    $smarty->assign("MODULE_NAME", $module_name);
    $smarty->assign(array(
        'title'                         =>  _tr('Incoming Campaigns Panel'),
        'icon'                          =>  '/images/list.png',
        'ETIQUETA_CAMPANIA'             =>  _tr('Campaign'),
        'ETIQUETA_TOTAL_LLAMADAS'       =>  _tr('Total calls'),
        'ETIQUETA_LLAMADAS_COLA'        =>  _tr('Queued calls'),
        'ETIQUETA_LLAMADAS_EXITO'       =>  _tr('Connected calls'),
        'ETIQUETA_LLAMADAS_ABANDONADAS' =>  _tr('Abandoned calls'),
        'ETIQUETA_LLAMADAS_TERMINADAS'  =>  _tr('Finished calls'),
        'ETIQUETA_LLAMADAS_SINRASTRO'   =>  _tr('Lost track'),
        'ETIQUETA_AGENTES'              =>  _tr('Agents'),
        'ETIQUETA_NUMERO_TELEFONO'      =>  _tr('Phone Number'),
        'ETIQUETA_TRONCAL'              =>  _tr('Trunk'),
        'ETIQUETA_ESTADO'               =>  _tr('Status'),
        'ETIQUETA_DESDE'                =>  _tr('Since'),
        'ETIQUETA_AGENTE'               =>  _tr('Agent'),
        'ETIQUETA_REGISTRO'             =>  _tr('View log'),
        'ETIQUETA_MAX_DURAC_LLAM'       =>  _tr('Maximum Call Duration'),
        'ETIQUETA_PROMEDIO_DURAC_LLAM'  =>  _tr('Average Call Duration'),
        'ETIQUETA_LLAMADAS_MARCANDO'    =>  _tr('Active calls'),
        'HOURS_ARRAY'                   =>  $hoursArray,
        'LABEL_SHIFT_FROM'              =>  _tr('Shift From'),
        'LABEL_SHIFT_TO'                =>  _tr('Shift To'),
        'LABEL_APPLY'                   =>  _tr('Apply'),
        'LABEL_YESTERDAY'               =>  _tr('Yesterday'),
        'LABEL_TODAY'                   =>  _tr('Today'),
    ));

    return $smarty->fetch("file:$sDirLocalPlantillas/informacion_campania.tpl");
}

// This module doesn't need getCampaigns - loads all active incoming campaigns automatically
function manejarMonitoreo_getIncomingPanelData($module_name, $smarty, $sDirLocalPlantillas)
{
    $respuesta = array(
        'status'    =>  'success',
        'message'   =>  '(no message)',
    );

    // Parse shift parameters from request
    $shiftFrom = getParameter('shift_from');
    $shiftTo = getParameter('shift_to');

    // Default to full day (00-23) if not specified
    if (is_null($shiftFrom) || $shiftFrom === '') $shiftFrom = 0;
    if (is_null($shiftTo) || $shiftTo === '') $shiftTo = 23;

    $shiftFrom = (int)$shiftFrom;
    $shiftTo = (int)$shiftTo;

    // Calculate datetime range based on shift hours
    $shiftRange = calculateShiftDatetimeRange($shiftFrom, $shiftTo);
    $datetime_start = $shiftRange['start'];
    $datetime_end = $shiftRange['end'];
    $respuesta['shift_label'] = shiftRangeLabel($shiftRange);

    $oPaloConsola = new PaloSantoConsola();

    try {
        $combinedStatus = $oPaloConsola->getCombinedIncomingCampaignsStatus($datetime_start, $datetime_end);
    } catch (Exception $e) {
        $respuesta['status'] = 'error';
        $respuesta['message'] = 'Exception: ' . $e->getMessage();
        $json = new Services_JSON();
        Header('Content-Type: application/json');
        return $json->encode($respuesta);
    }

    if (!is_array($combinedStatus)) {
        $respuesta['status'] = 'error';
        $respuesta['message'] = $oPaloConsola->errMsg;
    } else {
        $estadoCampaniaLlamadas = array();
        foreach ($combinedStatus['activecalls'] as $activecall) {
            $formatted = formatoLlamadaNoConectada($activecall);
            $formatted['campaign_name'] = isset($activecall['campaign_name']) ? $activecall['campaign_name'] : '-';
            $estadoCampaniaLlamadas[] = $formatted;
        }

        $estadoCampaniaAgentes = array();
        foreach ($combinedStatus['agents'] as $agent) {
            $formatted = formatoAgente($agent);
            $formatted['campaign_name'] = isset($agent['campaign_name']) ? $agent['campaign_name'] : '-';
            $estadoCampaniaAgentes[] = $formatted;
        }
        sortAgentsByStatus($estadoCampaniaAgentes);

        $respuesta = array_merge($respuesta, crearRespuestaVacia());
        $respuesta['statuscount']['update'] = $combinedStatus['statuscount'];
        $respuesta['stats']['update'] = $combinedStatus['stats'];
        $respuesta['activecalls']['add'] = $estadoCampaniaLlamadas;
        $respuesta['agents']['add'] = $estadoCampaniaAgentes;
        $respuesta['campaigns'] = $combinedStatus['campaigns'];

        $estadoCliente = array(
            'paneltype'     =>  'incoming_panel',
            'campaigns'     =>  $combinedStatus['campaigns'],
            'statuscount'   =>  $combinedStatus['statuscount'],
            'activecalls'   =>  $combinedStatus['activecalls'],
            'agents'        =>  $combinedStatus['agents'],
            'stats'         =>  $combinedStatus['stats'],
        );

        $respuesta['estadoClienteHash'] = generarEstadoHash($module_name, $estadoCliente);
    }

    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

function manejarMonitoreo_checkStatusPanel($module_name, $smarty, $sDirLocalPlantillas)
{
    $respuesta = array();
    setupSSESession();

    // Get client state hash from request
    $estadoHash = getParameter('clientstatehash');
    if (!is_null($estadoHash)) {
        $estadoCliente = isset($_SESSION[$module_name]['estadoCliente'])
            ? $_SESSION[$module_name]['estadoCliente']
            : array();
    } else {
        // First request without hash - need full state
        $estadoCliente = array();
    }

    // Parse shift parameters from request
    $shiftFrom = getParameter('shift_from');
    $shiftTo = getParameter('shift_to');

    // Default to full day (00-23) if not specified
    if (is_null($shiftFrom) || $shiftFrom === '') $shiftFrom = 0;
    if (is_null($shiftTo) || $shiftTo === '') $shiftTo = 23;

    $shiftFrom = (int)$shiftFrom;
    $shiftTo = (int)$shiftTo;

    // Calculate datetime range based on shift hours
    $shiftRange = calculateShiftDatetimeRange($shiftFrom, $shiftTo);
    $datetime_start = $shiftRange['start'];
    $datetime_end = $shiftRange['end'];
    // The label rides with every response; the array below is rebuilt each
    // round, so it is put into the literal itself
    $sShiftLabel = shiftRangeLabel($shiftRange);

    // Mode: Long-Polling or Server-sent Events
    $bSSE = detectSSEMode();
    initSSE($bSSE);

    // Verify correct hash
    if (!is_null($estadoHash) && isset($_SESSION[$module_name]['estadoClienteHash'])
        && $estadoHash != $_SESSION[$module_name]['estadoClienteHash']) {
        $respuesta['estadoClienteHash'] = 'mismatch';
        jsonflush($bSSE, $respuesta);
        return;
    }

    $oPaloConsola = new PaloSantoConsola();

    // Get current server state
    $combinedStatus = $oPaloConsola->getCombinedIncomingCampaignsStatus($datetime_start, $datetime_end);

    if (!is_array($combinedStatus)) {
        $respuesta['status'] = 'error';
        $respuesta['message'] = $oPaloConsola->errMsg;
        jsonflush($bSSE, $respuesta);
        $oPaloConsola->desconectarTodo();
        return;
    }

    // Build lightweight state fingerprint for comparison (avoid serializing large arrays)
    $estadoActualHash = computeStateHash($combinedStatus);
    $estadoClienteHash = isset($estadoCliente['hash']) ? $estadoCliente['hash'] : '';

    // Listen for call progress events
    $oPaloConsola->escucharProgresoLlamada(TRUE);
    $iTimeoutPoll = $oPaloConsola->recomendarIntervaloEsperaAjax();

    do {
        $oPaloConsola->desconectarEspera();

        // Start long wait with browser
        session_commit();
        $iTimestampInicio = time();

        // Wait for events or state change
        while (connection_status() == CONNECTION_NORMAL
            && $estadoActualHash == $estadoClienteHash
            && time() - $iTimestampInicio < $iTimeoutPoll) {

            $listaEventos = $oPaloConsola->esperarEventoSesionActiva();
            if (is_null($listaEventos)) {
                $respuesta['status'] = 'error';
                $respuesta['message'] = $oPaloConsola->errMsg;
                jsonflush($bSSE, $respuesta);
                $oPaloConsola->desconectarTodo();
                return;
            }

            // Re-poll state after events
            if (count($listaEventos) > 0) {
                $combinedStatus = $oPaloConsola->getCombinedIncomingCampaignsStatus($datetime_start, $datetime_end);
                if (is_array($combinedStatus)) {
                    $estadoActualHash = computeStateHash($combinedStatus);
                }
            }
        }

        // Build response with formatted data
        $estadoCampaniaLlamadas = array();
        foreach ($combinedStatus['activecalls'] as $activecall) {
            $formatted = formatoLlamadaNoConectada($activecall);
            $formatted['campaign_name'] = isset($activecall['campaign_name']) ? $activecall['campaign_name'] : '-';
            $estadoCampaniaLlamadas[] = $formatted;
        }

        $estadoCampaniaAgentes = array();
        foreach ($combinedStatus['agents'] as $agent) {
            $formatted = formatoAgente($agent);
            $formatted['campaign_name'] = isset($agent['campaign_name']) ? $agent['campaign_name'] : '-';
            $estadoCampaniaAgentes[] = $formatted;
        }
        sortAgentsByStatus($estadoCampaniaAgentes);

        $respuesta = array(
            'status' => 'success',
            'shift_label' => $sShiftLabel,
            'statuscount' => $combinedStatus['statuscount'],
            'stats' => $combinedStatus['stats'],
            'activecalls' => $estadoCampaniaLlamadas,
            'agents' => $estadoCampaniaAgentes,
        );

        // Update session state
        @session_start();
        $estadoCliente = array('hash' => $estadoActualHash);
        $respuesta['estadoClienteHash'] = generarEstadoHash($module_name, $estadoCliente);
        session_commit();

        jsonflush($bSSE, $respuesta);

        // Reset for next iteration in SSE mode
        $estadoClienteHash = $estadoActualHash;
        $respuesta = array();

    } while ($bSSE && connection_status() == CONNECTION_NORMAL);

    $oPaloConsola->desconectarTodo();
}

/**
 * Compute a lightweight hash of the campaign state for change detection.
 * Uses key metrics instead of serializing entire arrays.
 */
function computeStateHash($combinedStatus)
{
    $fingerprint = '';

    // Status counts
    if (isset($combinedStatus['statuscount'])) {
        $sc = $combinedStatus['statuscount'];
        $fingerprint .= 't:' . (isset($sc['total']) ? $sc['total'] : 0);
        $fingerprint .= 'q:' . (isset($sc['onqueue']) ? $sc['onqueue'] : 0);
        $fingerprint .= 's:' . (isset($sc['success']) ? $sc['success'] : 0);
        $fingerprint .= 'a:' . (isset($sc['abandoned']) ? $sc['abandoned'] : 0);
        $fingerprint .= 'f:' . (isset($sc['finished']) ? $sc['finished'] : 0);
    }

    // Stats
    if (isset($combinedStatus['stats'])) {
        $fingerprint .= 'm:' . (isset($combinedStatus['stats']['max_duration']) ? $combinedStatus['stats']['max_duration'] : 0);
        $fingerprint .= 'ts:' . (isset($combinedStatus['stats']['total_sec']) ? $combinedStatus['stats']['total_sec'] : 0);
    }

    // Active calls - count and IDs
    if (isset($combinedStatus['activecalls'])) {
        $fingerprint .= 'ac:' . count($combinedStatus['activecalls']);
        foreach ($combinedStatus['activecalls'] as $call) {
            $fingerprint .= ',' . (isset($call['callid']) ? $call['callid'] : '') . ':' . (isset($call['callstatus']) ? $call['callstatus'] : '');
        }
    }

    // Agents - count and statuses (including onhold flag for real-time updates)
    if (isset($combinedStatus['agents'])) {
        $fingerprint .= 'ag:' . count($combinedStatus['agents']);
        foreach ($combinedStatus['agents'] as $agent) {
            $onholdFlag = (isset($agent['onhold']) && $agent['onhold']) ? 'H' : 'Nh';
            $fingerprint .= ',' . (isset($agent['agentchannel']) ? $agent['agentchannel'] : '') . ':' . (isset($agent['status']) ? $agent['status'] : '') . ':' . $onholdFlag;
        }
    }

    return md5($fingerprint);
}

function crearRespuestaVacia()
{
    return array(
        'statuscount'   =>  array('update' => array()),
        'activecalls'   =>  array('add' => array(), 'update' => array(), 'remove' => array()),
        'agents'        =>  array('add' => array(), 'update' => array(), 'remove' => array()),
        'log'           =>  array(),
    );
}

function formatoLlamadaNoConectada($activecall)
{
    $sFechaHoy = date('Y-m-d');
    $sDesde = (!is_null($activecall['queuestart']))
        ? $activecall['queuestart'] : $activecall['dialstart'];
    if (strpos($sDesde, $sFechaHoy) === 0)
        $sDesde = substr($sDesde, strlen($sFechaHoy) + 1);
    $sEstado = ($activecall['callstatus'] == 'placing' && !is_null($activecall['trunk']))
        ? _tr('dialing') : _tr($activecall['callstatus']);
    return array(
        'callid'        =>  $activecall['callid'],
        'callnumber'    =>  $activecall['callnumber'],
        'trunk'         =>  $activecall['trunk'],
        'callstatus'    =>  $sEstado,
        'desde'         =>  $sDesde,
    );
}

function formatoAgente($agent)
{
    $rawStatus = isset($agent['status']) ? $agent['status'] : 'offline';
    $sEtiquetaStatus = _tr($rawStatus);
    $sFechaHoy = date('Y-m-d');
    $sDesde = '-';
    $callinfo = isset($agent['callinfo']) ? $agent['callinfo'] : array();

    switch ($rawStatus) {
    case 'paused':
        if (isset($agent['onhold']) && $agent['onhold']) {
            $sEtiquetaStatus = _tr('Hold');
        } elseif (isset($agent['pauseinfo']) && !is_null($agent['pauseinfo'])) {
            $sDesde = $agent['pauseinfo']['pausestart'];
            $sEtiquetaStatus .= ': '.$agent['pauseinfo']['pausename'];
        }
        break;
    case 'oncall':
        if (isset($agent['onhold']) && $agent['onhold']) {
            $sEtiquetaStatus = _tr('On Call (Hold)');
        }
        if (isset($callinfo['linkstart'])) {
            $sDesde = $callinfo['linkstart'];
        }
        break;
    }
    if (strpos($sDesde, $sFechaHoy) === 0)
        $sDesde = substr($sDesde, strlen($sFechaHoy) + 1);

    return array(
        'agent'         =>  isset($agent['agentchannel']) ? $agent['agentchannel'] : '-',
        'status'        =>  $sEtiquetaStatus,
        'rawstatus'     =>  $rawStatus,
        'callnumber'    =>  isset($callinfo['callnumber']) ? $callinfo['callnumber'] : '-',
        'trunk'         =>  isset($callinfo['trunk']) ? $callinfo['trunk'] : '-',
        'desde'         =>  $sDesde,
    );
}

/**
 * Sort agents array: logged-in agents first, logged-out (offline) agents last.
 * Within each group, sort by agent name.
 */
function sortAgentsByStatus(&$agents)
{
    usort($agents, function($a, $b) {
        $aOffline = ($a['rawstatus'] === 'offline') ? 1 : 0;
        $bOffline = ($b['rawstatus'] === 'offline') ? 1 : 0;

        // First sort by offline status (offline goes to bottom)
        if ($aOffline !== $bOffline) {
            return $aOffline - $bOffline;
        }

        // Then sort by agent name
        return strcmp($a['agent'], $b['agent']);
    });
}

function modificarReferenciasLibreriasJS($smarty)
{
    $listaLibsJS_framework = explode("\n", $smarty->get_template_vars('HEADER_LIBS_JQUERY'));
    $listaLibsJS_modulo = explode("\n", $smarty->get_template_vars('HEADER_MODULES'));

    /* Las referencias a Ember.js y Handlebars se reordenan para que Handlebars
     * aparezca antes que Ember.js.
     */
    $sEmberRef = $sHandleBarsRef = NULL;
    foreach (array_keys($listaLibsJS_modulo) as $k) {
    	if (strpos($listaLibsJS_modulo[$k], 'themes/default/js/handlebars-') !== FALSE) {
            $sHandleBarsRef = $listaLibsJS_modulo[$k];
            unset($listaLibsJS_modulo[$k]);
        } elseif (strpos($listaLibsJS_modulo[$k], 'themes/default/js/ember-') !== FALSE) {
            $sEmberRef = $listaLibsJS_modulo[$k];
            unset($listaLibsJS_modulo[$k]);
        }
    }
    array_unshift($listaLibsJS_modulo, $sEmberRef);
    array_unshift($listaLibsJS_modulo, $sHandleBarsRef);
    $smarty->assign('HEADER_MODULES', implode("\n", $listaLibsJS_modulo));
    $smarty->assign('HEADER_LIBS_JQUERY', implode("\n", $listaLibsJS_framework));
}

?>

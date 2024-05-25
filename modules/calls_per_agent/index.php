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
  $Id: index.php,v 1.1.1.1 2007/07/06 21:31:21 gcarrillo Exp $ */

if (!function_exists('_tr')) {
    function _tr($s)
    {
        global $arrLang;
        return isset($arrLang[$s]) ? $arrLang[$s] : $s;
    }
}
if (!function_exists('load_language_module')) {
    function load_language_module($module_id, $ruta_base='')
    {
        $lang = get_language($ruta_base);
        include_once $ruta_base."modules/$module_id/lang/en.lang";
        $lang_file_module = $ruta_base."modules/$module_id/lang/$lang.lang";
        if ($lang != 'en' && file_exists("$lang_file_module")) {
            $arrLangEN = $arrLangModule;
            include_once "$lang_file_module";
            $arrLangModule = array_merge($arrLangEN, $arrLangModule);
        }

        global $arrLang;
        global $arrLangModule;
        $arrLang = array_merge($arrLang,$arrLangModule);
    }
}

require_once "libs/paloSantoGrid.class.php";
require_once "libs/paloSantoDB.class.php";
require_once "libs/paloSantoForm.class.php";
require_once "libs/paloSantoConfig.class.php";
require_once "libs/misc.lib.php";
    
function _moduleContent(&$smarty, $module_name)
{
    //Incluir librería de lenguaje
    load_language_module($module_name);
    
    //include module files
    require_once "modules/$module_name/configs/default.conf.php";
    require_once "modules/$module_name/libs/paloSantoCallPerAgent.class.php";
    global $arrConf;
    
    //folder path for custom templates
    $base_dir=dirname($_SERVER['SCRIPT_FILENAME']);
    $templates_dir=(isset($arrConfig['templates_dir']))?$arrConfig['templates_dir']:'themes';
    $local_templates_dir="$base_dir/modules/$module_name/".$templates_dir.'/'.$arrConf['theme'];

    $urlVars = array('menu' => $module_name);
    
    $smarty->assign(array(
        'menu'      =>  $module_name,
        'Filter'    =>  _tr('Query'),
    ));
    
    // Construcción del formulario de filtro
    $comboTipos = array(
        ''      =>  _tr('All'),
        'IN'    =>  _tr("Ingoing"),
        'OUT'   =>  _tr("Outgoing"),
    );
    $arrFormElements = createFieldFilter($comboTipos);
    $oFilterForm = new paloForm($smarty, $arrFormElements);
    
    // Variables a usar para el URL, consulta, y POST
    $defaultExtraVars = array(
        'date_start'    =>  date('d M Y'),
        'date_end'      =>  date('d M Y'),
        'number'        =>  '',
        'queue'         =>  '',
        'type'          =>  '',
    );
    $arrFilterExtraVars = $defaultExtraVars;
    foreach (array_keys($arrFilterExtraVars) as $k) {
        $v = trim(getParameter($k));
        $arrFilterExtraVars[$k] = (is_null($v) || $v == '') ? $arrFilterExtraVars[$k] : $v;
    }
    
    // Validación del formulario
    if (!$oFilterForm->validateForm($arrFilterExtraVars)) {
        $arrFilterExtraVars = $defaultExtraVars;
        $smarty->assign(array(
            'mb_title'      =>  _tr('Validation Error'),
            'mb_message'    =>  '<b>'._tr('The following fields contain errors').':</b><br/>'.
                                implode(', ', array_keys($oFilterForm->arrErroresValidacion)),
        ));
    }
    
    // Traducción de valores y petición real
    $pDB = new paloDB($cadena_dsn);
    $oCallsAgent = new paloSantoCallsAgent($pDB);
    $fieldPat = array(
        'number'    =>  array(),
        'queue'     =>  array(),
        'type'      =>  array(),
    );
    foreach (array_keys($fieldPat) as $k) {
        if (isset($arrFilterExtraVars[$k]) && $arrFilterExtraVars[$k] != '')
            $fieldPat[$k][] = $arrFilterExtraVars[$k];
    }
    if (count($fieldPat['type']) <= 0) $fieldPat['type'] = array('IN', 'OUT');
    $arrCallsAgentTmp = $oCallsAgent->obtenerCallsAgent(
        translateDate($arrFilterExtraVars['date_start']).' 00:00:00',
        translateDate($arrFilterExtraVars['date_end']).' 23:59:59',
        $fieldPat);
    if (!is_array($arrCallsAgentTmp)) {
        $smarty->assign(array(
            'mb_title'      =>  _tr('ERROR'),
            'mb_message'    =>  $oCallsAgent->errMsg,
        ));
    	$arrCallsAgentTmp = array();
    }
    $totalCallsAgents = count($arrCallsAgentTmp);
    
    // Construcción del reporte final
    $oGrid = new paloSantoGrid($smarty);
    $oGrid->enableExport();   // enable export.
    $oGrid->showFilter($oFilterForm->fetchForm("$local_templates_dir/filter.tpl", '', $arrFilterExtraVars));
    $oGrid->setLimit($totalCallsAgents);
    $oGrid->setTotal($totalCallsAgents + 1);
    $offset = $oGrid->calculateOffset();
    
    // Bloque comun
    $arrData = array();
    $sumCallAnswered = $sumDuration = $timeMayor = 0;
    foreach($arrCallsAgentTmp as $cdr) {
        $arrData[] = array(
            $cdr['agent_number'],
            htmlspecialchars($cdr['agent_name'], ENT_COMPAT, 'UTF-8'),
            $cdr['type'],
            $cdr['queue'],
            $cdr['num_answered'],
            formatoSegundos($cdr['sum_duration']),
            formatoSegundos($cdr['avg_duration']),
            formatoSegundos($cdr['max_duration']),
        );

                if ($cdr['type'] === 'Inbound'){
        $inbound = TRUE;
        $arrDataInbound[] = array(
            $cdr['agent_number'],
            htmlspecialchars($cdr['agent_name'], ENT_COMPAT, 'UTF-8'),
            $cdr['type'],
            $cdr['queue'],
            $cdr['num_answered'],
            formatoSegundos($cdr['sum_duration']),
            formatoSegundos($cdr['avg_duration']),
            formatoSegundos($cdr['max_duration']),
        );
        } 
        if ($cdr['type'] === 'Outbound'){
        $outbound = TRUE;
        $arrDataOutbound[] = array(
            $cdr['agent_number'],
            htmlspecialchars($cdr['agent_name'], ENT_COMPAT, 'UTF-8'),
            $cdr['type'],
            $cdr['queue'],
            $cdr['num_answered'],
            formatoSegundos($cdr['sum_duration']),
            formatoSegundos($cdr['avg_duration']),
            formatoSegundos($cdr['max_duration']),
        );    
        }
    
        $sumCallAnswered += $cdr['num_answered'];   // Total de llamadas contestadas
        $sumDuration += $cdr['sum_duration'];       // Total de segundos en llamadas
        $timeMayor = ($timeMayor < $cdr['max_duration']) ? $cdr['max_duration'] : $timeMayor;
    }
    $sTagInicio = (!$oGrid->isExportAction()) ? '<b>' : '';
    $sTagFinal = ($sTagInicio != '') ? '</b>' : '';
    $arrData[] = array(
        $sTagInicio._tr('Total').$sTagFinal,
        '', '', '',
        $sTagInicio.$sumCallAnswered.$sTagFinal,
        $sTagInicio.formatoSegundos($sumDuration).$sTagFinal,
        $sTagInicio.formatoSegundos(($sumCallAnswered > 0) ? ($sumDuration / $sumCallAnswered) : 0).$sTagFinal,
        $sTagInicio.formatoSegundos($timeMayor).$sTagFinal,
    );
    
    // Construyo el URL base
    if(isset($arrFilterExtraVars) && is_array($arrFilterExtraVars) && count($arrFilterExtraVars)>0) {
        $urlVars = array_merge($urlVars, $arrFilterExtraVars);
    }
        
    $oGrid->setURL(construirURL($urlVars, array("nav", "start")));
    $oGrid->setData($arrData);
    $arrColumnas = array(_tr("No.Agent"), _tr("Agent"), _tr("Type"), _tr("Queue"),
        _tr("Calls answered"),_tr("Duration"),_tr("Average"),_tr("Call longest"));
    $oGrid->setColumns($arrColumnas);
    $oGrid->setTitle(_tr("Calls per Agent"));
    $oGrid->pagingShow(false);
    $oGrid->setNameFile_Export(_tr("Calls per Agent"));
     
    $smarty->assign("SHOW", _tr("Show"));
    $agentTable = $oGrid->fetchGrid();

    $table = $agentTable;

    if ($inbound) {
        $inboundGraph = callsGraphics($arrDataInbound, "inbound");
        $table .= "<div style='text-align: center; padding: 20px; border: solid; border-radius: 10px;'>";
        $table .= "<h2 style='color: #333;'>Inbound Campaigns</h2>";
        $table .= $inboundGraph;
        $table .= "</div>";
        $table .= "<br>";
    }
    if ($outbound){
        $outboundGraph = callsGraphics($arrDataOutbound, "outbound");
        $table .= "<div style='text-align: center; padding: 20px; border: solid; border-radius: 10px;'>";
        $table .= "<h2 style='color: #333;'>Outbound Campaigns</h2>";
        $table .= $outboundGraph;
        $table .= "</div>";
        $table .= "<br>";
    }


    return $table;    
}

function callsGraphics($callsInfo, $type){
    // Preparar datos para Highcharts
    $labels         = [];
    $numAnswered    = [];
    $count          = 0;
    $totalCalls     = 0;

    // Array asociativo para almacenar respuestas acumuladas por etiqueta
    $accumulatedAnswers = [];

    foreach ($callsInfo as $data) {
        $count++;
        $totalCalls     += (int) $data[4];
        $label          = "(" . $data[0] . ")  " . htmlspecialchars($data[1], ENT_QUOTES, 'UTF-8');

        if (!isset($accumulatedAnswers[$label])) {
            // Si la etiqueta no existe en el array, la inicializa con el valor actual
            $accumulatedAnswers[$label] = (int) $data[4];
        } else {
            // Si la etiqueta ya existe, suma el valor actual al acumulado
            $accumulatedAnswers[$label] += (int) $data[4];
        }
    }

    if ($count <= 4){
        $height = $count * 50;
    } else if ($count <= 10){
        $height = $count * 60;
    } else {
        $height = $count * 70;
    }

    // Convertir el array asociativo a los arreglos necesarios para Chart.js
    foreach ($accumulatedAnswers as $label => $answered) {
        $percentage = ($totalCalls > 0) ? ($answered / $totalCalls) * 100 : 0;
        $color = getColorBasedOnPercentage($percentage);

        $labels[] = $label;
        $numAnswered[] = [
            'value' => $answered,
            'color' => $color,
            'percentage' => $percentage,
        ];
    }

    
    $chartData = [
        'labels' => $labels,
        'numAnswered' => $numAnswered,
    ];

// Función alternativa a array_column para versiones de PHP anteriores a 5.5
if (!function_exists('array_column')) {
    function array_column($array, $column) {
        $result = array();
        foreach ($array as $row) {
            if (isset($row[$column])) {
                $result[] = $row[$column];
            }
        }
        return $result;
    }
}

$chartScript = "
<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>
    <canvas id='chart-container-".$type."' style='width: 100%; height: ".$height."px;'></canvas>
    <script>
        var totalCalls = " . $totalCalls . "; // Obtén el valor total de llamadas
        var ctx = document.getElementById('chart-container-".$type."').getContext('2d');
        var myChart = new Chart(ctx, {
        type: 'bar',  // Mantén 'bar' como tipo de gráfico
        data: {
            labels: " . json_encode($chartData['labels']) . ",
            datasets: [{
                label: 'Calls Answered',
                data: " . json_encode(array_column($chartData['numAnswered'], 'value')) . ",
                backgroundColor: " . json_encode(array_column($chartData['numAnswered'], 'color')) . ",
                borderColor: 'rgba(0,0,0)',
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',  // Configura el eje horizontal
            scales: {
                x: {
                    beginAtZero: true,
                    max: ".$totalCalls." // Configura el valor máximo del eje y
                },
                y: {
                    beginAtZero: true
                }
            }
        }
    });
</script>
";

    return $chartScript;
}

function getColorBasedOnPercentage($percentage) {
    if ($percentage >= 0 && $percentage < 10) {
        return 'rgba(255, 0, 0, 0.2)'; // Rojo
    } elseif ($percentage >= 10 && $percentage < 25) {
        return 'rgba(255, 165, 0, 0.2)'; // Naranjo
    } elseif ($percentage >= 25 && $percentage < 40) {
        return 'rgba(255, 255, 0, 0.2)'; // Amarillo
    } elseif ($percentage >= 40 && $percentage < 60) {
        return 'rgba(173, 255, 47, 0.2)'; // Verde Amarillo
    } elseif ($percentage >= 60 && $percentage <= 100) {
        return 'rgba(0, 128, 0, 0.2)'; // Verde
    } else {
        return 'rgba(0, 0, 0, 0.2)'; // Por defecto, negro
    }
}

function formatoSegundos($iSeg)
{
    $iSeg = (int)$iSeg;
    $iHora = $iMinutos = $iSegundos = 0;
    $iSegundos = $iSeg % 60; $iSeg = ($iSeg - $iSegundos) / 60;
    $iMinutos = $iSeg % 60; $iSeg = ($iSeg - $iMinutos) / 60;
    $iHora = $iSeg;
    return sprintf('%02d:%02d:%02d', $iHora, $iMinutos, $iSegundos);
}

function createFieldFilter($arrDataTipo)
{
    $arrFormElements = array(
        "date_start"  => array(
            "LABEL"                  => _tr('Start Date'),
            "REQUIRED"               => "yes",
            "INPUT_TYPE"             => "DATE",
            "INPUT_EXTRA_PARAM"      => "",
            "VALIDATION_TYPE"        => "ereg",
            "VALIDATION_EXTRA_PARAM" => "^[[:digit:]]{1,2}[[:space:]]+[[:alnum:]]{3}[[:space:]]+[[:digit:]]{4}$"),
         "date_end"    => array(
            "LABEL"                  => _tr("End Date"),
            "REQUIRED"               => "yes",
            "INPUT_TYPE"             => "DATE",
            "INPUT_EXTRA_PARAM"      => "",
            "VALIDATION_TYPE"        => "ereg",
            "VALIDATION_EXTRA_PARAM" => "^[[:digit:]]{1,2}[[:space:]]+[[:alnum:]]{3}[[:space:]]+[[:digit:]]{4}$"),
        "type" => array(
            "LABEL"                  => _tr("Tipo"),
            "REQUIRED"               => "no",
            "INPUT_TYPE"             => "SELECT",
            "INPUT_EXTRA_PARAM"      => $arrDataTipo,
            "VALIDATION_TYPE"        => "text",
            "VALIDATION_EXTRA_PARAM" => "^(IN|OUT)$"),
        "queue" => array(
            "LABEL"                  => _tr("Queue"),
            "REQUIRED"               => "no",
            "INPUT_TYPE"             => "TEXT",
            "INPUT_EXTRA_PARAM"      => "",
            "VALIDATION_TYPE"        => "ereg",
            "VALIDATION_EXTRA_PARAM" => "^[[:digit:]]+$"),
        "number" => array(
            "LABEL"                  => _tr("No.Agent"),
            "REQUIRED"               => "no",
            "INPUT_TYPE"             => "TEXT",
            "INPUT_EXTRA_PARAM"      => "",
            "VALIDATION_TYPE"        => "ereg",
            "VALIDATION_EXTRA_PARAM" => "^[[:digit:]]+$"),
         );
    return $arrFormElements;
}
?>
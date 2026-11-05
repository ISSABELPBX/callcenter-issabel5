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

require_once "libs/paloSantoGrid.class.php";
require_once "libs/paloSantoDB.class.php";
require_once "libs/paloSantoForm.class.php";
require_once "libs/paloSantoConfig.class.php";
require_once "libs/paloSantoQueue.class.php";
require_once "libs/misc.lib.php";

require_once "modules/agent_console/libs/issabel2.lib.php";

function _moduleContent(&$smarty, $module_name)
{
    //include module files
    include_once "modules/$module_name/configs/default.conf.php";
    include_once "modules/$module_name/libs/paloSantoCallsDetail.class.php";
    global $arrConf;

    load_language_module($module_name);

    //folder path for custom templates
    $base_dir = dirname($_SERVER['SCRIPT_FILENAME']);
    $templates_dir = (isset($arrConfig['templates_dir']))?$arrConfig['templates_dir']:'themes';
    $local_templates_dir = "$base_dir/modules/$module_name/".$templates_dir.'/'.$arrConf['theme'];

    $pDB = new paloDB($cadena_dsn);

    switch (getParameter('action')) {
    case 'download':
        return downloadRecording($smarty, $module_name, $pDB);
    case 'play':
        return playRecording($smarty, $module_name, $pDB);
    default:
        return reportCallsDetail($smarty, $module_name, $pDB, $local_templates_dir);
    }
}

function reportCallsDetail($smarty, $module_name, $pDB, $local_templates_dir)
{
    // Cadenas estáticas de Smarty
    $smarty->assign(array(
        "Filter"    =>  _tr('Filter'),
        "SHOW"      =>  _tr("Show"),
        'INCOMING_CAMPAIGN' =>  0,
        'OUTGOING_CAMPAIGN' =>  0,
    ));

    $bIssabelNuevo = method_exists('paloSantoGrid','setURL');

    // Variables iniciales para posición de grid
    $offset = 0;
    $limit = 50;
    $total = 0;

    // Para poder consultar las colas activas
    $pConfig = new paloConfig("/etc", "amportal.conf", "=", "[[:space:]]*=[[:space:]]*");
    $ampconfig = $pConfig->leer_configuracion(false);
    $ampdsn = $ampconfig['AMPDBENGINE']['valor'] . "://" . $ampconfig['AMPDBUSER']['valor'] .
        ":" . $ampconfig['AMPDBPASS']['valor'] . "@" . $ampconfig['AMPDBHOST']['valor'] . "/asterisk";
    $oQueue = new paloQueue($ampdsn);
    $listaColas = $oQueue->getQueue();
    if (!is_array($listaColas)) {
        $smarty->assign("mb_title", _tr("Error when connecting to database"));
        $smarty->assign("mb_message", $oQueue->errMsg);
    }
    // Combo de colas a partir de lista de colas
    $comboColas = array('' => '('._tr('All Queues').')');
    foreach ($listaColas as $tuplaCola) {
        $comboColas[$tuplaCola[0]] = $tuplaCola[1];
    }
    ksort($comboColas);

    $callType = array(
        ''          =>  '('._tr('Any Type').')',
        'incoming'  =>  _tr('Inbound'),
        'outgoing'  =>  _tr('Outbound'),
    );

    // Para poder consultar los agentes de campañas
    $oCallsDetail = new paloSantoCallsDetail($pDB);
    $listaAgentes = $oCallsDetail->getAgents(); // Para llenar el select de agentes
    // Combo de agentes a partir de lista de agentes
    $comboAgentes = array('' => '('._tr('All Agents').')');
    foreach ($listaAgentes as $tuplaAgente) {
        if (!isset($comboAgentes[$tuplaAgente['number']])) {
            $sDesc = $tuplaAgente['number'].' - '.$tuplaAgente['name'];
            if ($tuplaAgente['estatus'] != 'A') $sDesc .= ' ('.$tuplaAgente['estatus'].')';
            $comboAgentes[$tuplaAgente['number']] = $sDesc;
        }
    }

    // Para poder consultar campañas entrantes y salientes
    $campaignIn = $oCallsDetail->getCampaigns('incoming');
    $campaignOut = $oCallsDetail->getCampaigns('outgoing');

    // Status combo options
    $callStatus = array(
        ''          =>  '('._tr('All Status').')',
        'Success'   =>  _tr('Success'),
        'Abandoned' =>  _tr('Abandoned'),
        'Failure'   =>  _tr('Failure'),
        'NoAnswer'  =>  _tr('NoAnswer'),
        'OnQueue'   =>  _tr('OnQueue'),
        'Placing'   =>  _tr('Placing'),
        'Ringing'   =>  _tr('Ringing'),
        'ShortCall' =>  _tr('ShortCall'),
        'fin-monitoreo' => _tr('End Monitor'),
    );

    // Transfer combo options
    $transferFilter = array(
        ''  =>  '('._tr('All Transfers').')',
        'yes'=>  _tr('Transferred'),
        'no' =>  _tr('Not Transferred'),
    );

    $urlVars = array('menu' => $module_name);
    $arrFormElements = createFieldFilter($comboAgentes, $comboColas, $callType, $campaignIn, $campaignOut, $callStatus, $transferFilter);
    $oFilterForm = new paloForm($smarty, $arrFormElements);

    // Validar y aplicar las variables de filtro
    $paramLista = NULL;
    $paramFiltro = array();
    foreach (array('date_start', 'date_end', 'calltype', 'agent', 'queue',
        'phone', 'id_campaign_out', 'id_campaign_in', 'status', 'transfer') as $k)
        $paramFiltro[$k] = getParameter($k);
    if (!isset($paramFiltro['date_start'])) $paramFiltro['date_start'] = date("d M Y");
    if (!isset($paramFiltro['date_end'])) $paramFiltro['date_end'] = date("d M Y");
    if (!$oFilterForm->validateForm($paramFiltro)) {
        // Hay un error al validar las variables del filtro
        $smarty->assign("mb_title", _tr("Validation Error"));
        $arrErrores = $oFilterForm->arrErroresValidacion;
        $strErrorMsg = "<b>"._tr('The following fields contain errors').":</b><br>";
        $strErrorMsg = implode(', ', array_keys($arrErrores));
        $smarty->assign("mb_message", $strErrorMsg);
    } else {
        $urlVars = array_merge($urlVars, $paramFiltro);
        $paramLista = $paramFiltro;
        $paramLista['date_start'] = translateDate($paramFiltro['date_start']) . " 00:00:00";
        $paramLista['date_end'] = translateDate($paramFiltro['date_end']) . " 23:59:59";
    }
    if (isset($paramFiltro['calltype']) && $paramFiltro['calltype'] == 'incoming')
        $smarty->assign('INCOMING_CAMPAIGN', 1);
    if (isset($paramFiltro['calltype']) && $paramFiltro['calltype'] == 'outgoing')
        $smarty->assign('OUTGOING_CAMPAIGN', 1);
    $htmlFilter = $oFilterForm->fetchForm("$local_templates_dir/filter.tpl", "", $paramFiltro);

    // Inicio de objeto grilla y asignación de filtro
    $oGrid = new paloSantoGrid($smarty);
    $oGrid->enableExport();   // enable export.
    $oGrid->showFilter($htmlFilter);
    $bExportando = $bIssabelNuevo
        ? $oGrid->isExportAction()
        : ( (isset( $_GET['exportcsv'] ) && $_GET['exportcsv'] == 'yes') ||
            (isset( $_GET['exportspreadsheet'] ) && $_GET['exportspreadsheet'] == 'yes') ||
            (isset( $_GET['exportpdf'] ) && $_GET['exportpdf'] == 'yes')
          ) ;

    // Ejecutar la consulta con las variables ya validadas
    $arrData = array();
    $total = 0;
    if (is_array($paramLista)) {
        $total = $oCallsDetail->contarDetalleLlamadas($paramLista);
        if (is_null($total)) {
            $smarty->assign("mb_title", _tr("Error when connecting to database"));
            $smarty->assign("mb_message", $oCallsDetail->errMsg);
            $total = 0;
        } else {
            // Habilitar la exportación de todo el contenido consultado
            if ($bExportando) $limit = $total;

            // Calcular el offset de la petición de registros
            if ($bIssabelNuevo) {
                $oGrid->setLimit($limit);
                $oGrid->setTotal($total);
                $offset = $oGrid->calculateOffset();
            } else {
                // Si se quiere avanzar a la sgte. pagina
                if (isset($_GET['nav']) && $_GET['nav'] == "end") {
                    // Mejorar el sgte. bloque.
                    if (($total%$limit)==0) {
                        $offset = $total - $limit;
                    } else {
                        $offset = $total - $total % $limit;
                    }
                }

                // Si se quiere avanzar a la sgte. pagina
                if (isset($_GET['nav']) && $_GET['nav']=="next") {
                    $offset = $_GET['start'] + $limit - 1;
                }

                // Si se quiere retroceder
                if(isset($_GET['nav']) && $_GET['nav']=="previous") {
                    $offset = $_GET['start'] - $limit - 1;
                }
            }

            // Ejecutar la consulta de los datos en el offset indicado
            $recordset = $oCallsDetail->leerDetalleLlamadas($paramLista, $limit, $offset);
            if (!is_array($recordset)) {
                $smarty->assign("mb_title", _tr("Error when connecting to database"));
                $smarty->assign("mb_message", $oCallsDetail->errMsg);
                $total = 0;
            } else {
                $mapaEstados = array(
                    'abandonada'    =>  _tr('Abandoned'),
                    'Abandoned'     =>  _tr('Abandoned'),
                    'terminada'     =>  _tr('Success'),
                    'Success'       =>  _tr('Success'),
                    'fin-monitoreo' =>  _tr('End Monitor'),
                    'Failure'       =>  _tr('Failure'),
                    'NoAnswer'      =>  _tr('NoAnswer'),
                    'OnQueue'       =>  _tr('OnQueue'),
                    'Placing'       =>  _tr('Placing'),
                    'Ringing'       =>  _tr('Ringing'),
                    'ShortCall'     =>  _tr('ShortCall'),
                );
                foreach ($recordset as $cdr) {
                    $tupla = array(
                        $cdr[0],
                        htmlentities($cdr[1], ENT_COMPAT, "UTF-8"),
                        $cdr[2], $cdr[3],
                        is_null($cdr[4]) ? '-' : formatoSegundos($cdr[4]),
                        is_null($cdr[5]) ? '-' : formatoSegundos($cdr[5]),
                        $cdr[6],
                        _tr($cdr[7]),
                        $cdr[8],
                        $cdr[9],
                        isset($mapaEstados[$cdr[10]]) ? $mapaEstados[$cdr[10]] : _tr($cdr[10]),
                    );
                    if (!$bExportando) {
                        $downloadlinks = array();
                        foreach ($cdr[12] as $rec) {
                            $playUrl = '?menu='.$module_name.'&amp;action=play&amp;id='.$rec['id'].'&amp;rawmode=yes';
                            $downloadUrl = '?menu='.$module_name.'&amp;action=download&amp;id='.$rec['id'].'&amp;rawmode=yes';
                            $listenLink = '<a href="javascript:void(0);" onclick="playaudio(\''.$playUrl.'\')">'._tr('Listen').'</a>';
                            $downloadLink = '<a href="'.$downloadUrl.'">'._tr('Download').'</a>';
                            $downloadlinks[] = $listenLink.' | '.$downloadLink;
                        }
                        if (count($downloadlinks) == 0) {
                            $s = '';
                        } elseif (count($downloadlinks) == 1) {
                            $s = $downloadlinks[0];
                        } else {
                            if (count($downloadlinks) > 0) {
                                $s = '<div class="callcenter-recordings collapsed" >'.
                                     '<div title="'._tr('Click to expand or collapse').'">'._tr('Other').': '.(count($downloadlinks) - 1).'</div>';
                                for ($i = 0; $i < count($downloadlinks); $i++) {
                                    $s .= '<div'.(($i > 0) ? ' class="collapsable"' : '').'>'.$downloadlinks[$i].'</div>';
                                }
                                $s .= '</div>';
                            }
                        }
                        $tupla[] = $s;
                    }
                    $arrData[] = $tupla;
                }
            }
        }
    }

    $arrColumnas = array(_tr("No.Agent"), _tr("Agent"),
        _tr('Start Time'), _tr('End Time'),
        _tr("Duration"),
        _tr("Duration Wait"), _tr("Queue"), _tr("Type"), _tr("Phone"),
        _tr("Transfer"), _tr("Status"));
    if (!$bExportando)
        $arrColumnas[] = _tr('Recording');

    if($bIssabelNuevo) {
        $oGrid->addFilterControl(_tr("Filter applied: ")._tr("Start Date")." = ".$paramLista['date_start'].", "._tr("End Date")." = ".
            $paramLista['date_end'], $paramLista, array('date_start' => date("d M Y"),'date_end' => date("d M Y")),true);

        $oGrid->addFilterControl(_tr("Filter applied ")._tr("Phone")." = ".$paramLista['phone'], $_POST, array("fname" =>''));

        $paramLista['calltype'] = isset($callType[$paramLista['calltype']])?$paramLista['calltype'] : '';
        $_POST['calltype']=$paramLista['calltype'];
        $oGrid->addFilterControl(_tr("Filter applied ")._tr("Call Type")." = ".$callType[$paramLista['calltype']], $_POST, array("calltype" => ''),true);

        $paramLista['queue'] = isset($comboColas[$paramLista['queue']]) ? $paramLista['queue'] : '';
        $_POST['queue'] = $paramLista['queue'];
        $oGrid->addFilterControl(_tr("Filter applied ")._tr("Queue")." = ".$comboColas[$paramLista['queue']], $_POST, array("queue" => ''),true);

        $paramLista['agent'] = isset($comboAgentes[$paramLista['agent']]) ? $paramLista['agent'] : '';
        $_POST['agent'] = $paramLista['agent'];
        $oGrid->addFilterControl(_tr("Filter applied ")._tr("Agent")." = ".$comboAgentes[$paramLista['agent']], $_POST, array("agent" => ''),true);

        $paramLista['status'] = isset($callStatus[$paramLista['status']]) ? $paramLista['status'] : '';
        $_POST['status'] = $paramLista['status'];
        $oGrid->addFilterControl(_tr("Filter applied ")._tr("Status")." = ".$callStatus[$paramLista['status']], $_POST, array("status" => ''),true);

        $paramLista['transfer'] = isset($transferFilter[$paramLista['transfer']]) ? $paramLista['transfer'] : '';
        $_POST['transfer'] = $paramLista['transfer'];
        $oGrid->addFilterControl(_tr("Filter applied ")._tr("Transfer")." = ".$transferFilter[$paramLista['transfer']], $_POST, array("transfer" => ''),true);

        $oGrid->setURL(construirURL($urlVars, array("nav", "start")));
        $oGrid->setData($arrData);
        $oGrid->setColumns($arrColumnas);
        $oGrid->setTitle(_tr("Calls Detail"));
        $oGrid->pagingShow(true);
        $oGrid->setNameFile_Export(_tr("Calls Detail"));

        $gridContent = $oGrid->fetchGrid();
        // Insert extra header row with counter
        $counterHtml = '<tr><th colspan="'.count($arrColumnas).'" style="text-align:center; border-bottom:2px solid #ccc; padding:8px 0; border-radius:6px 6px 0 0;"><span style="font-size:13px; color:white;"><span style="font-weight:bold;">'._tr('Total: ').'</span>'.$total.' '._tr('Calls').'</span></th></tr>';
        $gridContent = preg_replace('/(<thead>)/', '<thead>$1'.$counterHtml, $gridContent);

        /* The module's themes/default/js/*.js and themes/default/css/*.css are
         * already injected into <head> by paloSantoNavigation::putHEAD_MODULE_HTML()
         * (libs/paloSantoNavigation.class.php). Emitting them again here made the
         * browser run javascript.js twice, which bound the recordings expand/collapse
         * handler twice: the two handlers toggled the same class on one click and
         * cancelled each other out, so the extra recordings never became visible.
         * Only the modal markup belongs here. */
        $modalHtml = <<<MODAL
<!-- The Modal -->
<div id="myModal" class="modal">
  <div id="single-song-player">
    <span class="close" onclick="closeModal()">&times;</span>
    <div class="bottom-container">
      <div class="slider-wrapper">
        <input type="range" class="amplitude-song-slider" data-amplitude-song-index="0"/>
      </div>
      <div class="time-container">
        <span class="current-time">
          <span class="amplitude-current-minutes">00</span>:<span class="amplitude-current-seconds">00</span>
        </span>
        <span class="duration">
          <span class="amplitude-duration-minutes">00</span>:<span class="amplitude-duration-seconds">00</span>
        </span>
      </div>
      <div class="control-container">
        <div class="amplitude-play-pause" id="play-pause"></div>
        <div class="meta-container">
          <span data-amplitude-song-info="name" class="song-name"></span>
        </div>
      </div>
    </div>
  </div>
</div>
<script type="text/javascript">
function closeModal() {
    var modal = document.getElementById("myModal");
    if (modal) {
        modal.style.display = "none";
        // Stop HTML5 audio player
        var audio = document.getElementById('call-audio-player');
        if (audio) {
            audio.pause();
            audio.currentTime = 0;
            audio.src = '';
        }
    }
}
</script>
MODAL;
        // Agregar el modal al final (no en exportaciones CSV/PDF/XLS)
        // EN: Append modal to the end (not to CSV/PDF/XLS exports)
        if (!$bExportando) $gridContent .= $modalHtml;
        return $gridContent;
     } else {
        global $arrLang;

        $url = construirURL($urlVars, array("nav", "start"));
        function _map_name($s) { return array('name' => $s); }
        $arrGrid = array("title"    => _tr("Calls Detail"),
                     "url"      => $url,
                     "icon"     => "images/user.png",
                     "width"    => "99%",
                     "start"    => ($total==0) ? 0 : $offset + 1,
                     "end"      => ($offset+$limit)<=$total ? $offset+$limit : $total,
                     "total"    => $total,
                     "columns"  => array_map('_map_name', $arrColumnas),
                    );
        if (isset( $_GET['exportpdf'] ) && $_GET['exportpdf'] == 'yes' && method_exists($oGrid, 'fetchGridPDF'))
            return $oGrid->fetchGridPDF($arrGrid, $arrData);
        if (isset( $_GET['exportspreadsheet'] ) && $_GET['exportspreadsheet'] == 'yes' && method_exists($oGrid, 'fetchGridXLS'))
            return $oGrid->fetchGridXLS($arrGrid, $arrData);
        if($bExportando) {
            header("Cache-Control: private");
            header("Pragma: cache");    // Se requiere para HTTPS bajo IE6
            header('Content-disposition: inline; filename="calls_detail.csv"');
            header("Content-Type: text/csv; charset=UTF-8");
        }
        if ($bExportando)
            return $oGrid->fetchGridCSV($arrGrid, $arrData);
        $sContenido = $oGrid->fetchGrid($arrGrid, $arrData, $arrLang);
        // Insert extra header row with counter
        $counterHtml = '<tr><th colspan="'.count($arrColumnas).'" style="text-align:center; border-bottom:2px solid #ccc; padding:8px 0; border-radius:6px 6px 0 0;"><span style="font-size:13px; color:white;"><span style="font-weight:bold;">'._tr('Total: ').'</span>'.$total.' '._tr('Calls').'</span></th></tr>';
        $sContenido = preg_replace('/(<thead>)/', '<thead>$1'.$counterHtml, $sContenido);

        /* The module's themes/default/js/*.js and themes/default/css/*.css are
         * already injected into <head> by paloSantoNavigation::putHEAD_MODULE_HTML()
         * (libs/paloSantoNavigation.class.php). Emitting them again here made the
         * browser run javascript.js twice, which bound the recordings expand/collapse
         * handler twice: the two handlers toggled the same class on one click and
         * cancelled each other out, so the extra recordings never became visible.
         * Only the modal markup belongs here. */
        $modalHtml = <<<MODAL
<!-- The Modal -->
<div id="myModal" class="modal">
  <div id="single-song-player">
    <span class="close" onclick="closeModal()">&times;</span>
    <div class="bottom-container">
      <div class="slider-wrapper">
        <input type="range" class="amplitude-song-slider" data-amplitude-song-index="0"/>
      </div>
      <div class="time-container">
        <span class="current-time">
          <span class="amplitude-current-minutes">00</span>:<span class="amplitude-current-seconds">00</span>
        </span>
        <span class="duration">
          <span class="amplitude-duration-minutes">00</span>:<span class="amplitude-duration-seconds">00</span>
        </span>
      </div>
      <div class="control-container">
        <div class="amplitude-play-pause" id="play-pause"></div>
        <div class="meta-container">
          <span data-amplitude-song-info="name" class="song-name"></span>
        </div>
      </div>
    </div>
  </div>
</div>
<script type="text/javascript">
function closeModal() {
    var modal = document.getElementById("myModal");
    if (modal) {
        modal.style.display = "none";
        // Stop HTML5 audio player
        var audio = document.getElementById('call-audio-player');
        if (audio) {
            audio.pause();
            audio.currentTime = 0;
            audio.src = '';
        }
    }
}
</script>
MODAL;
        // Append modal to the end
        $sContenido .= $modalHtml;
        if (strpos($sContenido, '<form') === FALSE)
            $sContenido = "<form  method=\"POST\" style=\"margin-bottom:0;\" action=\"$url\">$sContenido</form>";
        return $sContenido;
    }
}

function downloadRecording($smarty, $module_name, $pDB)
{
    if (!isset($_GET['id'])) {
        Header('Location: ?menu='.$module_name);
        return;
    }

    $oCallsDetail = new paloSantoCallsDetail($pDB);
    $path = $oCallsDetail->getRecordingFilePath($_GET['id']);
    if (is_null($path)) {
        if ($oCallsDetail->errMsg != '') {
            Header('HTTP/1.1 503 Internal Server Error');
            return $oCallsDetail->errMsg;
        } else {
            Header('HTTP/1.1 404 Not Found');
            return 'Not Found';
        }
    } elseif (!file_exists($path[0])) {
        if (substr($path[0], -6) == '.wav49') {
            // Archivo .wav49 realmente tiene extensión .WAV
            $path[0] = substr($path[0], 0, strlen($path[0]) - 6).'.WAV';
            $path[1] = substr($path[1], 0, strlen($path[1]) - 6).'.WAV';
            if (!file_exists($path[0])) {
                Header('HTTP/1.1 404 Not Found');
                return 'Not Found';
            }
        } else {
            Header('HTTP/1.1 404 Not Found');
            return 'Not Found';
        }
    }

    // Establecer Content-Type
    $content_type = 'application/octet-stream';
    $contentTypes = array(
        'wav'   =>  'audio/wav',
        'WAV'   =>  'audio/wav', // realmente es GSM envuelto en RIFF
        'gsm'   =>  'audio/gsm',
        'mp3'   =>  'audio/mpeg',
    );
    $extension = substr(strtolower($path[0]), -3);
    if (isset($contentTypes[$extension])) {
        $content_type = $contentTypes[$extension];
    }

    // Mandar el archivo con soporte para Range requests (para HTML5 audio seeking)
    $filesize = filesize($path[0]);
    $fp = fopen($path[0], 'rb');
    if (!$fp) {
        Header('HTTP/1.1 503 Internal Server Error');
        return 'Failed to open file';
    }

    // Check if this is a range request
    $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;

    if ($range) {
        // Parse Range header (e.g., "bytes=500-999")
        list($unit, $range) = explode('=', $range, 2);
        if ($unit == 'bytes') {
            list($start, $end) = explode('-', $range, 2);
            $start = intval($start);
            $end = $end === '' ? $filesize - 1 : intval($end);

            // Validate range
            if ($start < 0 || $end >= $filesize || $start > $end) {
                header("HTTP/1.1 416 Requested Range Not Satisfiable");
                header("Content-Range: bytes */$filesize");
                fclose($fp);
                return;
            }

            $content_length = $end - $start + 1;

            // Send partial content
            header("HTTP/1.1 206 Partial Content");
            header("Content-Range: bytes $start-$end/$filesize");
            header("Content-Length: $content_length");
            header("Content-Type: $content_type");
            header("Accept-Ranges: bytes");
            header("Pragma: public");
            header("Expires: 0");
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header("Cache-Control: public");

            // Seek to start position and send partial content
            fseek($fp, $start);
            $bytes_read = 0;
            $chunk_size = 8192;
            while (!feof($fp) && $bytes_read < $content_length) {
                $buffer = fread($fp, min($chunk_size, $content_length - $bytes_read));
                echo $buffer;
                $bytes_read += strlen($buffer);
            }
            fclose($fp);
            return;
        }
    }

    // Send complete file
    header("Pragma: public");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header("Cache-Control: public");
    header("Content-Description: wav file");
    header("Content-Type: $content_type");
    header("Accept-Ranges: bytes");
    header("Content-Disposition: attachment; filename={$path[1]}");
    header("Content-Transfer-Encoding: binary");
    header("Content-length: $filesize");
    fpassthru($fp);
    fclose($fp);
}

function playRecording($smarty, $module_name, $pDB)
{
    if (!isset($_GET['id'])) {
        Header('Location: ?menu='.$module_name);
        return;
    }

    $oCallsDetail = new paloSantoCallsDetail($pDB);
    $path = $oCallsDetail->getRecordingFilePath($_GET['id']);
    if (is_null($path)) {
        if ($oCallsDetail->errMsg != '') {
            Header('HTTP/1.1 503 Internal Server Error');
            return $oCallsDetail->errMsg;
        } else {
            Header('HTTP/1.1 404 Not Found');
            return 'Not Found';
        }
    } elseif (!file_exists($path[0])) {
        if (substr($path[0], -6) == '.wav49') {
            $path[0] = substr($path[0], 0, strlen($path[0]) - 6).'.WAV';
            $path[1] = substr($path[1], 0, strlen($path[1]) - 6).'.WAV';
            if (!file_exists($path[0])) {
                Header('HTTP/1.1 404 Not Found');
                return 'Not Found';
            }
        } else {
            Header('HTTP/1.1 404 Not Found');
            return 'Not Found';
        }
    }

    // Return the URL for the audio player with filename parameter
    $filename = $path[1];
    $audiourl = construirURL(array(
        'menu'       => $module_name,
        'action'     => 'download',
        'id'         => $_GET['id'],
        'namefile'   => $filename,
        'rawmode'    => 'yes',
    ));

    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    return $protocol.'://'.$_SERVER["HTTP_HOST"].'/index.php'.$audiourl;
}

function createFieldFilter($comboAgentes, $comboColas, $arrCallType, $campaignIn, $campaignOut, $callStatus, $transferFilter)
{
    // Combo de campañas entrantes
    $comboCampaignIn = array('' => '('._tr('All Incoming Campaigns').')');
    foreach ($campaignIn as $tuplaCampania) {
        $comboCampaignIn[$tuplaCampania['id']] =
            (($tuplaCampania['estatus'] != 'A') ? '('.$tuplaCampania['estatus'].') ' : '').
            $tuplaCampania['name'];
    }

    // Combo de campañas salientes
    $comboCampaignOut = array('' => '('._tr('All Outgoing Campaigns').')');
    foreach ($campaignOut as $tuplaCampania) {
        $comboCampaignOut[$tuplaCampania['id']] =
            (($tuplaCampania['estatus'] != 'A') ? '('.$tuplaCampania['estatus'].') ' : '').
            $tuplaCampania['name'];
    }

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
        'agent'     =>  array(
            'LABEL'                     =>  _tr('No.Agent'),
            'REQUIRED'                  =>  'no',
            'INPUT_TYPE'                =>  'SELECT',
            'INPUT_EXTRA_PARAM'         =>  $comboAgentes,
            'VALIDATION_TYPE'           =>  'ereg',
            'VALIDATION_EXTRA_PARAM'    =>  '^[[:digit:]]+$',
            'ONCHANGE'                  =>  'submit();',
        ),
        'queue'     =>  array(
            'LABEL'                     =>  _tr('Queue'),
            'REQUIRED'                  =>  'no',
            'INPUT_TYPE'                =>  'SELECT',
            'INPUT_EXTRA_PARAM'         =>  $comboColas,
            'VALIDATION_TYPE'           =>  'ereg',
            'VALIDATION_EXTRA_PARAM'    =>  '^[[:digit:]]+$',
            'ONCHANGE'                  =>  'submit();',
        ),
        'calltype'     =>  array(
            'LABEL'                     =>  _tr('Type'),
            'REQUIRED'                  =>  'no',
            'INPUT_TYPE'                =>  'SELECT',
            'INPUT_EXTRA_PARAM'         =>  $arrCallType,
            'VALIDATION_TYPE'           =>  'ereg',
            'VALIDATION_EXTRA_PARAM'    =>  '^(incoming|outgoing)?$',
            'ONCHANGE'                  =>  'submit();',
        ),
        'phone'     =>  array(
            'LABEL'                     =>  _tr('Phone'),
            'REQUIRED'                  =>  'no',
            'INPUT_TYPE'                =>  'TEXT',
            'INPUT_EXTRA_PARAM'         =>  '',
            'VALIDATION_TYPE'           =>  'ereg',
            'VALIDATION_EXTRA_PARAM'    =>  '^[[:digit:]]+$',
        ),
        'id_campaign_in'    => array(
            'LABEL'                     =>  _tr('Incoming Campaign'),
            'REQUIRED'                  =>  'no',
            'INPUT_TYPE'                =>  'SELECT',
            'INPUT_EXTRA_PARAM'         =>  $comboCampaignIn,
            'VALIDATION_TYPE'           =>  'ereg',
            'VALIDATION_EXTRA_PARAM'    =>  '^[[:digit:]]+$',
            'ONCHANGE'                  =>  'submit();',
        ),
        'id_campaign_out'   => array(
            'LABEL'                     =>  _tr('Outgoing Campaign'),
            'REQUIRED'                  =>  'no',
            'INPUT_TYPE'                =>  'SELECT',
            'INPUT_EXTRA_PARAM'         =>  $comboCampaignOut,
            'VALIDATION_TYPE'           =>  'ereg',
            'VALIDATION_EXTRA_PARAM'    =>  '^[[:digit:]]+$',
            'ONCHANGE'                  =>  'submit();',
        ),
        'status'     =>  array(
            'LABEL'                     =>  _tr('Status'),
            'REQUIRED'                  =>  'no',
            'INPUT_TYPE'                =>  'SELECT',
            'INPUT_EXTRA_PARAM'         =>  $callStatus,
            'VALIDATION_TYPE'           =>  'ereg',
            'VALIDATION_EXTRA_PARAM'    =>  '^(|Success|Abandoned|Failure|NoAnswer|OnQueue|Placing|Ringing|ShortCall|fin-monitoreo)$',
            'ONCHANGE'                  =>  'submit();',
        ),
        'transfer'     =>  array(
            'LABEL'                     =>  _tr('Transfer'),
            'REQUIRED'                  =>  'no',
            'INPUT_TYPE'                =>  'SELECT',
            'INPUT_EXTRA_PARAM'         =>  $transferFilter,
            'VALIDATION_TYPE'           =>  'ereg',
            'VALIDATION_EXTRA_PARAM'    =>  '^(|yes|no)$',
            'ONCHANGE'                  =>  'submit();',
        ),
    );
    return $arrFormElements;
}

function formatoSegundos($iSeg)
{
    $iHora = $iMinutos = $iSegundos = 0;
    $iSegundos = $iSeg % 60; $iSeg = ($iSeg - $iSegundos) / 60;
    $iMinutos = $iSeg % 60; $iSeg = ($iSeg - $iMinutos) / 60;
    $iHora = $iSeg;
    return sprintf('%02d:%02d:%02d', $iHora, $iMinutos, $iSegundos);
}
?>

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
require_once "libs/paloSantoForm.class.php";
require_once "libs/paloSantoTrunk.class.php";
require_once "libs/paloSantoConfig.class.php";
require_once '/var/lib/asterisk/agi-bin/phpagi-asmanager.php';
require_once "libs/paloSantoJSON.class.php";
require_once "libs/paloSantoDB.class.php";

$extension = '';

function _moduleContent(&$smarty, $module_name)
{
    global $arrConf;
    global $arrLang;
    global $extension;

    require_once "modules/$module_name/libs/issabel2.lib.php";
    require_once "modules/$module_name/libs/paloSantoConsola.class.php";
    require_once "modules/$module_name/configs/default.conf.php";
    require_once "modules/$module_name/libs/JSON.php";


    $pDB = new paloDB($arrConf['issabel_dsn']['acl']);
    $pACL = new paloACL($pDB);
    $user = $_SESSION['issabel_user'];
    $extension = $pACL->getUserExtension($user);

    $astman = new AGI_AsteriskManager();
    if (!$astman->connect("127.0.0.1", 'admin' , obtenerClaveAMIAdmin())) {
            $asteriskVersion=array(11,25,3);
    } else {
        $r = $astman->send_request('CoreSettings');
        if ($r['Response'] == 'Success' && isset($r['AsteriskVersion'])) {
            $asteriskVersion = explode('.', $r['AsteriskVersion']);
        }
    }

    // Agent login is now supported on Asterisk 12+ via app_agent_pool
    // No longer need to force callback-only mode
    $onlyCallback=0;
    $smarty->assign('ONLY_CALLBACK', $onlyCallback);

    _debug("module entry: ".
        "\$_GET = ".print_r($_GET, TRUE)."\n".
        "\$_POST = ".print_r($_POST, TRUE));

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

    // Estado inicial de la consola del Call Center
    if (!isset($_SESSION['callcenter']) ||
        !is_array($_SESSION['callcenter']) ||
        !isset($_SESSION['callcenter']['estado_consola']))
        $_SESSION['callcenter'] = generarEstadoInicial();

    /* Al iniciar la sesión del agente, se asignan las variables elastix_agent_user y elastix_extension  */
    if ($_SESSION['callcenter']['estado_consola'] == 'logged-in') {
        // Manejo de la sesión activa del agente logoneado
        return manejarSesionActiva($module_name, $smarty, $sDirLocalPlantillas);
    } else {
        // Manejo del inicio de la sesión del agente
        return manejarLogin($module_name, $smarty, $sDirLocalPlantillas);
    }
}

function _debug($s)
{
    _cc_debug($s, 'agent_console');
}

/* Procedimiento para generar el estado inicial de la información del agente en
 * la sesión PHP.  */
function generarEstadoInicial()
{
    return array(
        /*  Estado de la consola. Los valores posibles son
            logged-out  No hay agente logoneado
            logging     Agente intenta autenticarse con la llamada
            logged-in   Agente fue autenticado y está logoneado en consola
         */
        'estado_consola'    =>  'logged-out',

        /* El número del agente que se logonea. P.ej. 8000 para el agente 8000.
         * En estado logout el agente es NULL.
         */
        'agente'            =>  NULL,

        /* El nombre del agente */
        'agente_nombre'     =>  NULL,

        /* El número de la extensión interna que se logonea al agente. En estado
           logout la extensión es NULL
         */
        'extension'         =>  NULL,

        /* El último tipo de llamada y el último ID de llamada atendida. Esto
         * permite que se pueda guardar un formulario de una llamada que ya
         * ha terminado */
        'ultimo_calltype'       =>  NULL,
        'ultimo_callid'         =>  NULL,
        'ultimo_callsurvey'     =>  NULL,
        'ultimo_campaignform'   =>  NULL,

        /* Se lleva la cuenta de la duración, en segundos, de los breaks que se
         * han iniciado y terminado durante la sesión. El posible break en curso
         * no se cuenta en break_acumulado. Pero el hecho de que hay un break
         * en curso se registra en break_iniciado por si se refresca la interfaz
         * y se encuentra que el break ha terminado. */
        'break_acumulado'       =>  0,
        'break_iniciado'        =>  NULL,
    );
}

/**
 * Get list of agents for transfer dialog, excluding current logged-in agent.
 * Obtiene lista de agentes para diálogo de transferencia, excluyendo agente actual.
 *
 * @param PaloSantoConsola $oPaloConsola Console object
 * @return array Array of agents with format ['Agent/9000' => 'Agent/9000 - John Doe']
 */
function _obtenerListaAgentesTransferencia($oPaloConsola)
{
    $listaAgentes = $oPaloConsola->listarAgentes();

    // Filter out the current logged-in agent from the list
    // Filtrar el agente actual conectado de la lista
    $currentAgent = isset($_SESSION['callcenter']['agente']) ? $_SESSION['callcenter']['agente'] : '';
    if (!empty($currentAgent) && isset($listaAgentes[$currentAgent])) {
        unset($listaAgentes[$currentAgent]);
    }

    return $listaAgentes;
}

/**
 * The answer to an AJAX (rawmode) request whose agent session is over.
 *
 * Every branch here answers in a shape its caller in javascript.js acts on.
 * A redirect would not do: the XHR follows it to the login page's HTML, the
 * JSON parse fails, and the page never learns the session ended.
 * - checkStatus gets the logged-out event: manejarRespuestaStatus redirects
 *   on it.
 * - ping gets ERROR_SESSION: verificar_error_session redirects on it.
 * - Any other action gets the error object its handler shows. That action
 *   did not run, so it must never be answered as a success.
 *
 * @param string $sAction The request's action
 * @return string The JSON answer
 */
function answerEndedSession($sAction)
{
    $json = new Services_JSON();
    Header('Content-Type: application/json');
    if ($sAction == 'checkStatus') {
        return $json->encode(array(array('event' => 'logged-out')));
    }
    if ($sAction == 'ping') {
        // Computed as manejarSesionActiva_ping does: do_ping schedules itself on it.
        $gc_maxlifetime = ini_get('session.gc_maxlifetime');
        if ($gc_maxlifetime == "") $gc_maxlifetime = 10 * 60;
        return $json->encode(array(
            'statusResponse'    =>  'ERROR_SESSION',
            'gc_maxlifetime'    =>  (int)$gc_maxlifetime,
        ));
    }
    return $json->encode(array(
        'action'    =>  'error',
        'message'   =>  _tr('(internal) Action valid only while logged-in, agent session lost or not started')));
}

// Procedimiento para decidir qué acción tomar en el estado de login de agente
function manejarLogin($module_name, &$smarty, $sDirLocalPlantillas)
{
    $sAction = '';
    $sContenido = '';

    $sAction = getParameter('action');

    /* Si el método está entre estos, pero el estado es de login, entonces se
     * ha perdido un estado de callcenter anterior. */
    if (in_array($sAction, array('checkStatus', 'agentLogout', 'hangup',
        'break', 'unbreak', 'transfer', 'transferagent', 'confirm_contact', 'schedule',
        'saveforms', 'updateShiftTimes'))) {
        return answerEndedSession($sAction);
    }

    if (!in_array($sAction, array('', 'doLogin', 'checkLogin')))
        $sAction = '';

    switch ($sAction) {
    case 'doLogin':
        $sContenido = manejarLogin_doLogin();
        break;
    case 'checkLogin':
        $sContenido = manejarLogin_checkLogin();
        break;
    default:
        $sContenido = manejarLogin_HTML($module_name, $smarty, $sDirLocalPlantillas);
        break;
    }

    print_r($sAction);

    return $sContenido;
}

// Mostrar el formulario donde el agente ingresa su login
function manejarLogin_HTML($module_name, &$smarty, $sDirLocalPlantillas)
{
    global $arrConf;

    // Acciones para mostrar el formulario, fuera de cualquier acción AJAX
    $smarty->assign(array(
        'FRAMEWORK_TIENE_TITULO_MODULO' => existeSoporteTituloFramework(),
        'icon'                          => 'modules/'.$module_name.'/images/call_center.png',
        'title'                         =>  _tr('Agent Console'),
        'WELCOME_AGENT'         =>  _tr('Welcome to Agent Console'),
        'ENTER_USER_PASSWORD'   =>  _tr('Please select your agent number and your extension'),
        'USERNAME'              =>  _tr('Agent Number'),
        'EXTENSION'             =>  _tr('Extension'),
        'CALLBACK_LOGIN'        =>  _tr('Callback Login'),
        'PASSWORD'              =>  _tr('Password'),
        'CALLBACK_EXTENSION'    =>  _tr('Callback Extension'),
        'LABEL_SUBMIT'          =>  _tr('Enter'),
        'LABEL_NOEXTENSIONS'    =>  _tr('There are no extensions available. At least one extension is required for agent login.'),
        'LABEL_NOAGENTS'        =>  _tr('There are no agents available. At least one agent is required for agent login.'),
        'ESTILO_FILA_ESTADO_LOGIN'  =>  'style="visibility: hidden; position: absolute;"',
        'REANUDAR_VERIFICACION' =>  0,
    ));

    $oPaloConsola = new PaloSantoConsola();
    $listaExtensiones = $oPaloConsola->listarExtensiones();
    $listaAgentes = $oPaloConsola->listarAgentes('static');
    $listaExtensionesCallback = $oPaloConsola->listarAgentes('dynamic');
    $oPaloConsola->desconectarTodo();
    $oPaloConsola = NULL;

    $bNoHayAgentes = (count($listaAgentes) == 0 && count($listaExtensionesCallback) == 0);
    if (count($listaAgentes) == 0) $listaAgentes[] = _tr('(no agents)');
    if (count($listaExtensionesCallback) == 0) $listaExtensionesCallback[] = _tr('(no agents)');
    $smarty->assign(array(
        'LISTA_EXTENSIONES' =>  $listaExtensiones,
        'LISTA_AGENTES'     =>  $listaAgentes,
        'LISTA_EXTENSIONES_CALLBACK'     =>  $listaExtensionesCallback,
        'NO_EXTENSIONS'     =>  (count($listaExtensiones) == 0),
        'NO_AGENTS'         =>  $bNoHayAgentes,
    ));

    // Restaurar el estado de espera en caso de que se refresque la página
    if (!is_null($_SESSION['callcenter']['agente']) &&
        !is_null($_SESSION['callcenter']['extension'])) {
        $smarty->assign(array(
            'ID_AGENT'                  =>  $_SESSION['callcenter']['agente'],
            'ID_EXTENSION'              =>  $_SESSION['callcenter']['extension'],
            'ID_EXTENSION_CALLBACK'     =>  $_SESSION['callcenter']['agente'],
            'ESTILO_FILA_ESTADO_LOGIN'  =>  'style="visibility: visible; position: none;"',
            'MSG_ESPERA'                =>  _tr('Logging agent in. Please wait...'),
            'REANUDAR_VERIFICACION'     =>  1,
        ));
    } else {
    	/* Si el usuario Issabel logoneado coincide con el número de agente de
         * la lista, se coloca este agente como opción por omisión para login.
         */
        if (isset($listaAgentes['Agent/'.$_SESSION['issabel_user']]))
            $smarty->assign('ID_AGENT', 'Agent/'.$_SESSION['issabel_user']);

        /* Si el usuario Issabel logoneado tiene una extensión y aparece en la
         * lista, se sugiere esta extension como la extensión a usar para
         * marcar. */
        $pACL = new paloACL($arrConf['issabel_dsn']['acl']);
        $idUser = $pACL->getIdUser($_SESSION['issabel_user']);
        if ($idUser !== FALSE) {
        	$tupla = $pACL->getUsers($idUser);
            if (is_array($tupla) && count($tupla) > 0) {
                $sExtension = $tupla[0][3];
                if (isset($listaExtensiones[$sExtension]))
                    $smarty->assign('ID_EXTENSION', $sExtension);

                foreach (array_keys($listaExtensionesCallback) as $k) {
                	$regs = NULL;
                    if (preg_match('|^(\w+)/(\d+)$|', $k, $regs) && $regs[2] == $sExtension)
                        $smarty->assign('ID_EXTENSION_CALLBACK', $k);
                }
            }
        }
    }
    $sContenido = $smarty->fetch("$sDirLocalPlantillas/login_agent.tpl");
    return $sContenido . _cc_debug_flush_html();
}

// Procesar requerimiento AJAX para iniciar el login del agente
function manejarLogin_doLogin()
{
    $oPaloConsola = new PaloSantoConsola();

    // Acción AJAX para iniciar el login de agente
    $bCallback = in_array(getParameter('callback'), array('true', 'checked'));
    if ($bCallback) {
        $sAgente = getParameter('ext_callback');
        $sPasswordCallback = getParameter('pass_callback');
        $regs = NULL;
        $sExtension = (preg_match('|^(\w+)/(\d+)$|', $sAgente, $regs)) ? $regs[2]: NULL;
        $sAgentPassword = NULL;
    } else {
        $sAgente = getParameter('agent');
        $sExtension = getParameter('ext');
        $sPasswordCallback = NULL;
        $sAgentPassword = getParameter('pass_agent');
    }

    $respuesta = array(
        'status'    =>  FALSE,  // VERDADERO para éxito en iniciar timbrado
        'message'   =>  '(no message)', // Posible mensaje de error
    );
    $bContinuar = TRUE;

    // Verificar que la extensión y el agente son válidos en el sistema
    if ($bContinuar) {
        $listaExtensiones = $oPaloConsola->listarExtensiones();
        $listaAgentes = $oPaloConsola->listarAgentes();
        if (!in_array($sAgente, array_keys($listaAgentes))) {
            $bContinuar = FALSE;
            $respuesta['status'] = FALSE;
            $respuesta['message'] = _tr('Invalid agent number');
        } elseif (!in_array($sExtension, array_keys($listaExtensiones))) {
            $bContinuar = FALSE;
            $respuesta['status'] = FALSE;
            $respuesta['message'] = _tr('Invalid extension number');
        }
    }

    // Verify agent password for non-callback login
    if ($bContinuar && !$bCallback) {
        // Extract agent number from "Agent/XXXX" format
        $sAgentNumber = preg_match('|^Agent/(\d+)$|', $sAgente, $regs) ? $regs[1] : $sAgente;
        if (!$oPaloConsola->autenticarAgente($sAgentNumber, $sAgentPassword)) {
            $bContinuar = FALSE;
            $respuesta['status'] = FALSE;
            $respuesta['message'] = _tr('Invalid agent password');
        }
    }

    // Check if Agent type extension is already used by callback extension session
    if ($bContinuar && !$bCallback) {
        $oPaloConsola->desconectarTodo();

        // Check if extension is already used by callback type session
        if ($oPaloConsola->extensionUsadaPorCallback($sExtension)) {
            $bContinuar = FALSE;
            $respuesta['status'] = FALSE;
            $respuesta['message'] = _tr('Extension is already in use by another agent');
        }
        // Reconnect for normal login flow
        $oPaloConsola = new PaloSantoConsola($sAgente);
    }

    // Check if callback extension is already used by Agent type session
    if ($bContinuar && $bCallback) {
        $oPaloConsola->desconectarTodo();

        // Extract extension number from callback format (e.g., SIP/101 -> 101)
        $regs = NULL;
        $sExtensionNum = (preg_match('|^(\w+)/(\d+)$|', $sAgente, $regs)) ? $regs[2] : $sAgente;

        // NEW: Check if extension is registered in Asterisk
        if (!$oPaloConsola->extensionEstaRegistrada($sAgente)) {
            $bContinuar = FALSE;
            $respuesta['status'] = FALSE;
            $respuesta['message'] = _tr('Extension is not registered');
        }
        // Check if extension is already used by Agent type session
        elseif ($oPaloConsola->extensionUsadaPorAgente($sExtensionNum)) {
            $bContinuar = FALSE;
            $respuesta['status'] = FALSE;
            $respuesta['message'] = _tr('Extension is already in use by another agent');
        }
        // Reconnect for normal login flow
        $oPaloConsola = new PaloSantoConsola($sAgente);
    }

    // Verificar si el número de agente no está ya ocupado por otra extensión
    if ($bContinuar) {
        $oPaloConsola->desconectarTodo();
        $oPaloConsola = new PaloSantoConsola($sAgente);

        // For Agent login, skip the callback authentication (already verified above)
        if ($bCallback) {
            $estado = $oPaloConsola->autenticar($sAgente, $sPasswordCallback)
                ? $oPaloConsola->estadoAgenteLogoneado($sExtension)
                : array('estadofinal' => 'error');
        } else {
            // Agent login - password already verified, proceed directly
            $estado = $oPaloConsola->estadoAgenteLogoneado($sExtension);
        }
        switch ($estado['estadofinal']) {
        case 'error':
        case 'mismatch':
            $respuesta['status'] = FALSE;
            $respuesta['message'] = _tr('Cannot start agent login').' - '.$oPaloConsola->errMsg;
            break;
        case 'logged-out':
            // No hay canal de login. Se inicia login a través de Originate para el caso de Agent/xxx
            $bExito = $oPaloConsola->loginAgente($sExtension);
            if (!$bExito) {
                $respuesta['status'] = FALSE;
                $respuesta['message'] = _tr('Cannot start agent login').' - '.$oPaloConsola->errMsg;
                break;
            }
            // En caso de éxito, se cuela al siguiente caso
        case 'logging':
        case 'logged-in':
            // Ya está logoneado este agente. Se procede directamente a espera
            $_SESSION['callcenter']['estado_consola'] = 'logging';
            $_SESSION['callcenter']['agente'] = $sAgente;
            $_SESSION['callcenter']['agente_nombre'] = $listaAgentes[$sAgente];
            $_SESSION['callcenter']['extension'] = $sExtension;
            $respuesta['status'] = TRUE;
            $respuesta['message'] = _tr('Logging agent in. Please wait...');

            if ($estado['estadofinal'] != 'logged-in') {
                // Esperar hasta 1 segundo para evento de fallo de login.
                $sEstado = $oPaloConsola->esperarResultadoLogin();
                if ($sEstado == 'logged-in') {
                    /* El agente ha podido logonearse. Se delega el cambio de
                     * estado_consola a logged-in a la verificación de
                     * manejarLogin_checkLogin() */
                } elseif ($sEstado == 'logged-out') {
                    // El procedimiento de login ha fallado, sin causa conocida
                    $_SESSION['callcenter'] = generarEstadoInicial();
                    $respuesta['status'] = FALSE;
                    $respuesta['message'] = _tr('Agent log-in failed!');
                } elseif ($sEstado == 'error') {
                    // Ocurre un error al consultar el estado del agente
                    $_SESSION['callcenter'] = generarEstadoInicial();
                    $respuesta['status'] = FALSE;
                    $respuesta['message'] = _tr('Agent log-in failed!').' - '.$oPaloConsola->errMsg;
                }
            }
            break;
        }
    }

// modified by Pajulio, Service Json AND return Replace by this code//
    header('Content-Type: application/json');
    echo json_encode($respuesta);
    $oPaloConsola->desconectarTodo();
    exit();
}

// Procesar requerimiento AJAX para revisar el estado del proceso de login
function manejarLogin_checkLogin()
{
    $respuesta = array(
        'action'    =>  'wait', // Opciones: wait login error
        'message'   =>  '(no message)', // Posible mensaje de error
    );
    $bContinuar = TRUE;

    // Verificación rápida para saber si el canal es correcto
    $sAgente = $_SESSION['callcenter']['agente'];
    $sExtension = $_SESSION['callcenter']['extension'];
    $oPaloConsola = new PaloSantoConsola($sAgente);

    if ($bContinuar) {
        $estado = $oPaloConsola->estadoAgenteLogoneado($sExtension);
        switch ($estado['estadofinal']) {
        case 'error':
        case 'mismatch':
            // Otra extensión ya ocupa el login del agente indicado, o error
            $_SESSION['callcenter'] = generarEstadoInicial();
            $respuesta['action'] = 'error';
            $respuesta['message'] = _tr('Cannot start agent login').' - '.$oPaloConsola->errMsg.
                "ext=$sExtension agente=$sAgente";
            $bContinuar = FALSE;
            break;
        case 'logged-out':
            // No se encuentra evidencia de que se empezara el login
            $_SESSION['callcenter'] = generarEstadoInicial();
            $respuesta['action'] = 'error';
            $respuesta['message'] = _tr('Agent login process not started');
            $bContinuar = FALSE;
            break;
        case 'logging':
            $_SESSION['callcenter']['estado_consola'] = 'logging';
            $respuesta['action'] = 'wait';
            $respuesta['message'] = _tr('Logging agent in. Please wait...');
            break;
        case 'logged-in':
            // El agente ha podido logonearse. Se procede a mostrar el formulario
            $_SESSION['callcenter']['estado_consola'] = 'logged-in';
            $respuesta['action'] = 'login';
            $bContinuar = FALSE;
            break;
        }

    }

    if ($bContinuar && $respuesta['action'] == 'wait') {
        $iTimeoutPoll = $oPaloConsola->recomendarIntervaloEsperaAjax();
        $oPaloConsola->desconectarEspera();

        // Se inicia espera larga con el navegador...
        session_commit();
        set_time_limit(0);
        $iTimestampInicio = time();

        while ($bContinuar && time() - $iTimestampInicio <  $iTimeoutPoll) {

            // Verificar si el agente ya está en línea
            $sEstado = $oPaloConsola->esperarResultadoLogin();
            if ($sEstado == 'logged-in') {
                // Reiniciar la sesión para poder modificar las variables
                session_start();

                // El agente ha podido logonearse. Se procede a mostrar el formulario
                $_SESSION['callcenter']['estado_consola'] = 'logged-in';
                $respuesta['action'] = 'login';
                $bContinuar = FALSE;

            } elseif ($sEstado == 'logged-out') {
                // Reiniciar la sesión para poder modificar las variables
                session_start();

                // El procedimiento de login ha fallado, sin causa conocida
                $_SESSION['callcenter'] = generarEstadoInicial();
                $respuesta['action'] = 'error';
                $respuesta['message'] = _tr('Agent log-in terminated.');
                $bContinuar = FALSE;
            } elseif ($sEstado == 'error') {
                // Reiniciar la sesión para poder modificar las variables
                session_start();

                // Ocurre un error al consultar el estado del agente
                $_SESSION['callcenter'] = generarEstadoInicial();
                $respuesta['action'] = 'error';
                $respuesta['message'] = _tr('Agent log-in failed!').' - '.$oPaloConsola->errMsg;
                $bContinuar = FALSE;
            }
        }
    }

// modified by Pajulio, Service Json AND return Replace by this code//
    header('Content-Type: application/json');
    echo json_encode($respuesta);
    $oPaloConsola->desconectarTodo();
    exit();
    
}

// Procedimiento para decidir qué acción tomar en el estado de sesión activa
function manejarSesionActiva($module_name, &$smarty, $sDirLocalPlantillas)
{
    $sContenido = '';
    $json_method = NULL;

    // Construir lista de todos los paneles conocidos
    $listpanels = array();
    foreach (scandir("modules/$module_name/panels/") as $panelname) {
        if ($panelname != '.' && $panelname != '..' && is_dir("modules/$module_name/panels/$panelname")) {
            $listpanels[] = $panelname;
        }
    }

    // Carga de todas las funciones auxiliares de los diálogos
    foreach ($listpanels as $panelname) {
        if (file_exists("modules/$module_name/panels/$panelname/index.php")) {
            if (file_exists("modules/$module_name/panels/$panelname/lang/en.lang"))
                load_language_module("$module_name/panels/$panelname");
            require_once "modules/$module_name/panels/$panelname/index.php";
        }
    }

    // Se verifica si el agente sigue logoneado en la cola de Asterisk
    $sAgente = $_SESSION['callcenter']['agente'];
    $sExtension = $_SESSION['callcenter']['extension'];
    $oPaloConsola = new PaloSantoConsola($sAgente);
    $estado = $oPaloConsola->estadoAgenteLogoneado($sExtension);
    if ($estado['estadofinal'] != 'logged-in') {
        // Se marca el final de la sesión del agente en las tablas de auditoría
        $oPaloConsola->logoutAgente();
        $_SESSION['callcenter'] = generarEstadoInicial();

        // An AJAX (rawmode) request cannot follow a redirect: answerEndedSession says why.
        if (isset($_REQUEST['rawmode']) && $_REQUEST['rawmode'] == 'yes') {
            $sContenido = answerEndedSession(isset($_REQUEST['action']) ? $_REQUEST['action'] : '');
        } else {
            // Para agente no logoneado, se redirecciona a la página de login
            Header('Location: ?menu='.$module_name);
            $sContenido = '';
        }
    } else {
        $h = 'manejarSesionActiva_HTML';
        if (isset($_REQUEST['action'])) {
            $h = NULL;

            $regs = NULL;
            if (preg_match('/^(\w+)_(.*)$/', $_REQUEST['action'], $regs)) {
                $classname = 'Panel_'.ucfirst($regs[1]);
                $methodname = 'handleJSON_'.$regs[2];

                if (method_exists($classname, $methodname)) {
                    $h = array($classname, $methodname);
                }
            }
            if (is_null($h) && function_exists('manejarSesionActiva_'.$_REQUEST['action']))
                $h = 'manejarSesionActiva_'.$_REQUEST['action'];
            if (is_null($h))
                $h = 'manejarSesionActiva_unimplemented';
        }
        $sContenido = call_user_func($h, $module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado, $listpanels);
    }

    $oPaloConsola->desconectarTodo();

    return $sContenido;
}

function manejarSesionActiva_unimplemented($module_name, &$smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode(array(
        'status'    =>  'error',
        'message'   =>  _tr('Unimplemented method'),
    ));

}

function manejarSesionActiva_HTML($module_name, &$smarty, $sDirLocalPlantillas, $oPaloConsola, $estado, $listpanels)
{

    global $extension;



    // Incluir bibliotecas javascript de paneles
    $listaLibsJS_modulo = explode("\n", $smarty->get_template_vars('HEADER_MODULES'));
    foreach ($listpanels as $panelname) {
        foreach (scandir("modules/$module_name/panels/$panelname/js") as $jslib) {
            if ($jslib != '.' && $jslib != '..') {
                array_push($listaLibsJS_modulo, "<script type='text/javascript' src='modules/$module_name/panels/$panelname/js/$jslib'></script>");
            }
        }
    }
    $smarty->assign('HEADER_MODULES', implode("\n", $listaLibsJS_modulo));

    $bInactivarBotonColgar = FALSE;
    $bPuedeConfirmarContacto = FALSE;

    // Acciones para mostrar la pantalla principal, fuera de cualquier acción AJAX
    for ($i = 0; $i < 24; $i++) { $ii = sprintf('%02d', $i); $comboHora[$ii] = $ii; }
    for ($i = 0; $i < 60; $i++) { $ii = sprintf('%02d', $i); $comboMinuto[$ii] = $ii; }

    // Build shift hours options for the filter dropdown
    $sShiftHoursOptions = '';
    for ($h = 0; $h < 24; $h++) {
        $sHourVal = sprintf('%02d', $h);
        $sShiftHoursOptions .= '<option value="'.$sHourVal.'">'.$sHourVal.':00</option>';
    }

    $smarty->assign(array(
        // Shift filter labels
        'LBL_SHIFT_FROM'                =>  _tr('From'),
        'LBL_SHIFT_TO'                  =>  _tr('To'),
        'BTN_SHIFT_APPLY'               =>  _tr('Apply'),
        'SHIFT_HOURS_OPTIONS'           =>  $sShiftHoursOptions,
        'FRAMEWORK_TIENE_TITULO_MODULO' => existeSoporteTituloFramework(),
        'icon'                          => 'modules/'.$module_name.'/images/call_center.png',
        'title'                         =>  _tr('Agent Console').': '.$_SESSION['callcenter']['agente_nombre'],
        'BTN_COLGAR_LLAMADA'            =>  _tr('Hangup'),
        'BTN_TRANSFER'                  =>  _tr('Transfer'),
        'BTN_VTIGERCRM'                 =>  file_exists('/var/www/html/vtigercrm') ? _tr('VTiger CRM') : NULL,
        'BTN_FINALIZAR_LOGIN'           =>  _tr('End session'),
        'TITLE_BREAK_DIALOG'            =>  _tr('Select break type'),
        'LBL_CONTACTO_TELEFONO'         =>  _tr('Phone number'),
        'LBL_CONTACTO_NOMBRES'          =>  _tr('Names'),
        'TEXTO_CONTACTO_NOMBRES'        =>  '',
        'TEXTO_CONTACTO_TELEFONO'       =>  '',
        'BTN_AGENDAR_LLAMADA'           =>  _tr('Schedule call'),
        'TITLE_TRANSFER_DIALOG'         =>  _tr('Select extension to transfer to'),
        'LBL_TRANSFER_BLIND'            =>  _tr('Blind transfer'),
        'LBL_TRANSFER_ATTENDED'         =>  _tr('Attended transfer'),
        'LBL_TRANSFER_AGENT'            =>  _tr('Transfer to agent'),
        'LBL_COMPLETE_TRANSFER'         =>  _tr('Complete transfer'),
        'LBL_CANCEL_TRANSFER'           =>  _tr('Cancel transfer'),
        'MSG_TRANSFER_BUSY'             =>  _tr('Cannot transfer: colleague is busy'),
        'MSG_TRANSFER_NOANSWER'         =>  _tr('Cannot transfer: colleague did not answer'),
        'MSG_TRANSFER_UNAVAILABLE'      =>  _tr('Cannot transfer: colleague is unavailable'),
        'TITLE_SCHEDULE_CALL'           =>  _tr('Schedule call'),
        'LBL_SCHEDULE_CAMPAIGN_END'     =>  _tr('Call at end of campaign'),
        'LBL_SCHEDULE_BYDATE'           =>  _tr('Schedule at date'),
        'LBL_SCHEDULE_DATE_START'       =>  _tr('Start date'),
        'LBL_SCHEDULE_DATE_END'         =>  _tr('End date'),
        'LBL_SCHEDULE_TIME_START'       =>  _tr('Start time'),
        'LBL_SCHEDULE_TIME_END'         =>  _tr('End time'),
        'LBL_SCHEDULE_SAME_AGENT'       =>  _tr('Schedule to same agent'),
        'SCHEDULE_TIME_HH'              =>  $comboHora,
        'SCHEDULE_TIME_MM'              =>  $comboMinuto,
        'TAB_LLAMADA'                   =>  _tr('Call'),
        'TAB_LLAMADA_INFO'              =>  _tr('Information'),
        'TAB_LLAMADA_SCRIPT'            =>  _tr('Script'),
        'TAB_LLAMADA_FORM'              =>  _tr('Forms'),
        'CRONOMETRO'                    =>  '00:00:00',
        'LISTA_BREAKS'                  =>  $oPaloConsola->listarBreaks(),
        'LISTA_AGENTES'                 =>  _obtenerListaAgentesTransferencia($oPaloConsola),
        'CONTENIDO_LLAMADA_INFORMACION' =>  '',
        'CONTENIDO_LLAMADA_SCRIPT'      =>  '',
        'CONTENIDO_LLAMADA_FORMULARIO'  =>  '',
        'CALLINFO_CALLTYPE'             =>  '',
        'BTN_HOLD'                      =>  $estado['onhold'] ? _tr('End Hold') : _tr('Hold'),
        'BTN_GUARDAR_FORMULARIOS'       =>  _tr('Save data'),
    ));
    $estadoInicial = array(
        /* 'consultation' se omite deliberadamente: la consola arranca en
         * 'none' y, si el agente ya estaba en una consulta al cargar la
         * página, el primer poll detecta la discrepancia y sintetiza el
         * evento, dejando el botón Hangup con la etiqueta correcta. */
        /* EN: 'consultation' is deliberately omitted: the console starts at
         * 'none' and, if the agent was already in a consultation when the page
         * loaded, the first poll spots the mismatch and synthesizes the event,
         * leaving the Hangup button with the right label. */
        'onhold'            =>  $estado['onhold'],
        'break_id'          =>  is_null($estado['pauseinfo']) ? NULL : $estado['pauseinfo']['pauseid'],
        'calltype'          =>  NULL,
        'campaign_id'       =>  NULL,
        'callid'            =>  NULL,
        'timer_seconds'     =>  '',
        'url'               =>  NULL,
        'urldescription'    =>  NULL,
        'urlopentype'       =>  NULL,
        'url2'              =>  NULL,
        'urldescription2'   =>  NULL,
        'urlopentype2'      =>  NULL,
        'url3'              =>  NULL,
        'urldescription3'   =>  NULL,
        'urlopentype3'      =>  NULL,
        'waitingcall'       =>  FALSE,
    );

    // Query shift-based times for this agent
    // EN: Get agent channel from session (e.g., "SIP/101" or "Agent/8001")
    $agentChannel = $_SESSION['callcenter']['agente'];
    $shiftRange = agentConsole_calculateShiftDatetimeRange(0, 23);
    $breakData = agentConsole_consultarTiempoBreakAgentes($shiftRange['start'], $shiftRange['end']);
    $holdData = agentConsole_consultarTiempoHoldAgentes($shiftRange['start'], $shiftRange['end']);
    $loginData = agentConsole_consultarTiempoLoginAgentes($shiftRange['start'], $shiftRange['end']);

    $sec_shift_login = isset($loginData[$agentChannel]) ? $loginData[$agentChannel] : 0;
    $sec_shift_break = isset($breakData['breakTimes'][$agentChannel]) ? $breakData['breakTimes'][$agentChannel] : 0;
    $sec_shift_hold = isset($holdData[$agentChannel]) ? $holdData[$agentChannel] : 0;

    // Add current active break/hold time if applicable
    if (!is_null($estado['pauseinfo'])) {
        $iCurrentPauseDur = time() - strtotime($estado['pauseinfo']['pausestart']);
        $holdNames = isset($breakData['holdNames']) ? $breakData['holdNames'] : array();
        if (in_array($estado['pauseinfo']['pausename'], $holdNames)) {
            $sec_shift_hold += $iCurrentPauseDur;
        } else {
            $sec_shift_break += $iCurrentPauseDur;
        }
    }

    $smarty->assign(array(
        'SHIFT_LOGIN_TIME' => agentConsole_timestamp_format($sec_shift_login),
        'SHIFT_BREAK_TIME' => agentConsole_timestamp_format($sec_shift_break),
        'SHIFT_HOLD_TIME'  => agentConsole_timestamp_format($sec_shift_hold),
    ));
    $estadoInicial['shift_login_time'] = $sec_shift_login;
    $estadoInicial['shift_break_time'] = $sec_shift_break;
    $estadoInicial['shift_hold_time'] = $sec_shift_hold;
    $estadoInicial['is_hold_pause'] = (!is_null($estado['pauseinfo']) && in_array($estado['pauseinfo']['pausename'], isset($breakData['holdNames']) ? $breakData['holdNames'] : array()));

    // Decidir estado del break a mostrar
    if (!is_null($estado['pauseinfo'])) {
        $_SESSION['callcenter']['break_iniciado'] = $estado['pauseinfo']['pausestart'];
        $iDuracionPausaActual = time() - strtotime($estado['pauseinfo']['pausestart']);
        $iDuracionPausa = $iDuracionPausaActual + $_SESSION['callcenter']['break_acumulado'];
        $smarty->assign(array(
            'CLASS_BOTON_BREAK'             =>  'issabel-callcenter-boton-unbreak',
            'CLASS_ESTADO_AGENTE_INICIAL'   =>  'issabel-callcenter-class-estado-break',
            'BTN_BREAK'                     =>  _tr('End Break'),
            'TEXTO_ESTADO_AGENTE_INICIAL'   =>  _tr('On break').': '.$estado['pauseinfo']['pausename'],

            // TODO: debe contener tiempo acumulado de break desde inicio sesión
            // TODO: idea: sumar inicios y finales de breaks en variable sesión
            'CRONOMETRO'                    =>  sprintf('%02d:%02d:%02d',
                ($iDuracionPausa - ($iDuracionPausa % 3600)) / 3600,
                (($iDuracionPausa - ($iDuracionPausa % 60)) / 60) % 60,
                $iDuracionPausa % 60),
        ));
        $estadoInicial['timer_seconds'] = $iDuracionPausa;
    } else {
        if (!is_null($_SESSION['callcenter']['break_iniciado'])) {
        	/* Si esta condición se cumple, entonces se ha perdido el evento
             * pauseexit durante la espera en manejarSesionActiva_checkStatus().
             * Se hace la suposición de que el refresco ocurre poco después de
             * que termina el break, y que por lo tanto el error al usar time()
             * como fin del break es pequeño.
             */
            $_SESSION['callcenter']['break_acumulado'] += time() - strtotime($_SESSION['callcenter']['break_iniciado']);
        }

        $smarty->assign(array(
            'CLASS_BOTON_BREAK'             =>  'issabel-callcenter-boton-break',
            'BTN_BREAK'                     =>  _tr('Take Break'),
            'CLASS_ESTADO_AGENTE_INICIAL'   =>  'issabel-callcenter-class-estado-ocioso',
            'TEXTO_ESTADO_AGENTE_INICIAL'   =>  _tr('No active call'),
        ));
        $_SESSION['callcenter']['break_iniciado'] = NULL;
    }

    // Cambios según agente conectado a una llamada versus ocioso
    if (!is_null($estado['callinfo'])) {
        // Información sobre la llamada conectada
        $infoLlamada = $oPaloConsola->leerInfoLlamada(
            $estado['callinfo']['calltype'],
            $estado['callinfo']['campaign_id'],
            $estado['callinfo']['callid']);
        if ($estado['callinfo']['calltype'] == 'incoming' && is_null($estado['callinfo']['campaign_id'])) {
            $infoCampania['queue'] = $infoLlamada['queue'];
        	$infoCampania['script'] = $oPaloConsola->leerScriptCola($infoCampania['queue']);
            $infoCampania['forms'] = NULL;
        } else {
            $infoCampania = $oPaloConsola->leerInfoCampania(
                $estado['callinfo']['calltype'],
                $estado['callinfo']['campaign_id']);
        }
        if (is_null($infoCampania['script']) || $infoCampania['script'] == '')
            $infoCampania['script'] = _tr('(No script available)');

        // Variables de canal de la llamada activa
        $chanvars = $oPaloConsola->leerVariablesCanalLlamadaActiva();

        // Almacenar para regenerar formulario
        $_SESSION['callcenter']['ultimo_calltype'] = $estado['callinfo']['calltype'];
        $_SESSION['callcenter']['ultimo_callid'] = $estado['callinfo']['callid'];
        $_SESSION['callcenter']['ultimo_callsurvey']['call_survey'] = $infoLlamada['call_survey'];
        $_SESSION['callcenter']['ultimo_campaignform']['forms'] = $infoCampania['forms'];

        // Fecha completa de la llamada
        $iDuracionLlamada = time() - strtotime($estado['callinfo']['linkstart']);

        // Asignaciones independientes del tipo de llamada
        $bInactivarBotonColgar = false; // Se usa para botón hangup y botón transfer
        $smarty->assign(array(
            'CLASS_ESTADO_AGENTE_INICIAL'   =>  'issabel-callcenter-class-estado-activo',
            'TEXTO_ESTADO_AGENTE_INICIAL'   =>  _tr('Connected to call'),
            'CALLINFO_CALLTYPE'             =>  $estado['callinfo']['calltype'],

            // TODO: debe contener tiempo transcurrido en llamada
            'CRONOMETRO'                    =>  sprintf('%02d:%02d:%02d',
                ($iDuracionLlamada - ($iDuracionLlamada % 3600)) / 3600,
                (($iDuracionLlamada - ($iDuracionLlamada % 60)) / 60) % 60,
                $iDuracionLlamada % 60),

            'CONTENIDO_LLAMADA_INFORMACION' =>  _manejarSesionActiva_HTML_generarInformacion($smarty, $sDirLocalPlantillas, $infoLlamada, $infoCampania),
            'CONTENIDO_LLAMADA_FORMULARIO'  =>  _manejarSesionActiva_HTML_generarFormulario($smarty, $sDirLocalPlantillas, $infoLlamada, $infoCampania),
            'CONTENIDO_LLAMADA_SCRIPT'      =>  $infoCampania['script'],
        ));
        $estadoInicial['timer_seconds'] = $iDuracionLlamada;
        $estadoInicial['calltype'] = $estado['callinfo']['calltype'];
        $estadoInicial['campaign_id'] = $estado['callinfo']['campaign_id'];
        $estadoInicial['callid'] = $estado['callinfo']['callid'];

        $estadoInicial['urlopentype'] = isset($infoCampania['urlopentype']) ? $infoCampania['urlopentype'] : NULL;
        $estadoInicial['urldescription'] = isset($infoCampania['urldescription']) ? $infoCampania['urldescription'] : NULL;
        $estadoInicial['url'] = is_null($estadoInicial['urlopentype'])
            ? NULL : construirUrlExterno($infoCampania['urltemplate'], $infoLlamada + array(
                'callnumber'        =>  $estado['callinfo']['callnumber'],
                'callid'            =>  $infoLlamada['call_id'],
                'agent_number'      =>  $estado['callinfo']['agent_number'],
                'remote_channel'    =>  $estado['callinfo']['remote_channel']),
                $chanvars);

        // Para url2, urldescription2 y urlopentype2
        $estadoInicial['urlopentype2'] = isset($infoCampania['urlopentype2']) ? $infoCampania['urlopentype2'] : NULL;
        $estadoInicial['urldescription2'] = isset($infoCampania['urldescription2']) ? $infoCampania['urldescription2'] : NULL;
        $estadoInicial['url2'] = is_null($estadoInicial['urlopentype2'])
            ? NULL : construirUrlExterno($infoCampania['urltemplate2'], $infoLlamada + array(
                'callnumber'        =>  $estado['callinfo']['callnumber'],
                'callid'            =>  $infoLlamada['call_id'],
                'agent_number'      =>  $estado['callinfo']['agent_number'],
                'remote_channel'    =>  $estado['callinfo']['remote_channel']),
                $chanvars);

        // Para url3, urldescription3 y urlopentype3
        $estadoInicial['urlopentype3'] = isset($infoCampania['urlopentype3']) ? $infoCampania['urlopentype3'] : NULL;
        $estadoInicial['urldescription3'] = isset($infoCampania['urldescription3']) ? $infoCampania['urldescription3'] : NULL;
        $estadoInicial['url3'] = is_null($estadoInicial['urlopentype3'])
            ? NULL : construirUrlExterno($infoCampania['urltemplate3'], $infoLlamada + array(
                'callnumber'        =>  $estado['callinfo']['callnumber'],
                'callid'            =>  $infoLlamada['call_id'],
                'agent_number'      =>  $estado['callinfo']['agent_number'],
                'remote_channel'    =>  $estado['callinfo']['remote_channel']),
                $chanvars);
    } elseif (!is_null($estado['waitedcallinfo'])) {
        $estadoInicial['waitingcall'] = TRUE;


        $smarty->assign(array(
            'CLASS_ESTADO_AGENTE_INICIAL'   =>  'issabel-callcenter-class-estado-esperando',
            'TEXTO_ESTADO_AGENTE_INICIAL'   =>  _tr('Waiting for call'),
            'CONTENIDO_LLAMADA_FORMULARIO'  =>  is_null($_SESSION['callcenter']['ultimo_calltype'])
                ? ''
                : _manejarSesionActiva_HTML_generarFormulario($smarty, $sDirLocalPlantillas,
                        $_SESSION['callcenter']['ultimo_callsurvey'],
                        $_SESSION['callcenter']['ultimo_campaignform']),
        ));
    } else {
    	$bInactivarBotonColgar = true; // Se usa para botón hangup y botón transfer
        $smarty->assign(array(
            'CONTENIDO_LLAMADA_FORMULARIO'  =>  is_null($_SESSION['callcenter']['ultimo_calltype'])
                ? ''
                : _manejarSesionActiva_HTML_generarFormulario($smarty, $sDirLocalPlantillas,
                        $_SESSION['callcenter']['ultimo_callsurvey'],
                        $_SESSION['callcenter']['ultimo_campaignform']),
        ));
    }

    /* Barra naranja de llamada retenida. Va después de los bloques de arriba
     * porque el de llamada activa asigna el verde incondicionalmente. Se toma
     * el estado del servidor (no el del cliente) y el inicio real del hold, así
     * que tras un F5 en mitad de una retención la barra vuelve en naranja y el
     * cronómetro continúa desde donde iba en vez de reiniciarse a cero.
     * describirEstadoBarra() decide lo mismo para el long-poll. */
    /* EN: Orange bar for a held call. It comes after the blocks above because
     * the active-call one assigns green unconditionally. It reads server state
     * (not client state) and the real hold start, so after an F5 mid-hold the
     * bar comes back orange and the timer continues from where it was instead
     * of restarting at zero. describirEstadoBarra() makes the same decision for
     * the long poll. */
    if (describirEstadoBarra(array(
            'calltype'      =>  is_null($estado['callinfo']) ? NULL : $estado['callinfo']['calltype'],
            'onhold'        =>  $estado['onhold'],
            'consultation'  =>  isset($estado['consultation']) ? $estado['consultation'] : 'none',
            'waitingcall'   =>  !is_null($estado['waitedcallinfo']),
            'break_id'      =>  is_null($estado['pauseinfo']) ? NULL : $estado['pauseinfo']['pauseid'],
        )) == 'hold') {
        $iDuracionHoldInicial = empty($estado['holdstart'])
            ? 0 : time() - strtotime($estado['holdstart']);
        $smarty->assign(array(
            'CLASS_ESTADO_AGENTE_INICIAL'   =>  'issabel-callcenter-class-estado-hold',
            'TEXTO_ESTADO_AGENTE_INICIAL'   =>  _tr('Call on hold'),
            'CRONOMETRO'                    =>  sprintf('%02d:%02d:%02d',
                ($iDuracionHoldInicial - ($iDuracionHoldInicial % 3600)) / 3600,
                (($iDuracionHoldInicial - ($iDuracionHoldInicial % 60)) / 60) % 60,
                $iDuracionHoldInicial % 60),
        ));
        $estadoInicial['timer_seconds'] = $iDuracionHoldInicial;
    }

    $json = new Services_JSON();
    $smarty->assign(array(
        'APPLY_UI_STYLES'   =>  $json->encode(array(
            'break_commit'              =>  _tr('Take Break'),
            'break_dismiss'             =>  _tr('Dismiss'),
            'transfer_commit'           =>  _tr('Transfer'),
            'transfer_dismiss'          =>  _tr('Dismiss'),
            'schedule_commit'           =>  _tr('Schedule'),
            'schedule_dismiss'          =>  _tr('Dismiss'),
            'external_url_tab'          =>  _tr('External site'),
            'schedule_call_error_msg_missing_date' => _tr('Start and end date are required for date scheduling.'),
            'no_call'                   =>  $bInactivarBotonColgar,
            'can_confirm_contact'       =>  (isset($infoLlamada['matching_contacts']) && (count($infoLlamada['matching_contacts']) > 1)),
            'can_save_formdata'         =>  !is_null($_SESSION['callcenter']['ultimo_calltype']),
            )),
        'INITIAL_CLIENT_STATE'  =>  $json->encode($estadoInicial),
    ));

    // Se invoca la preparación de las plantillas de cada panel
    $tpath = explode('/', $sDirLocalPlantillas);
    array_pop($tpath); array_pop($tpath);
    $tpath = implode('/', $tpath).'/panels';
    $htmlpanels = array();
    foreach ($listpanels as $panelname) {
        // No hay soporte de namespace en PHP 5.1, se simula con una clase
        $classname = 'Panel_'.ucfirst($panelname);
        if (class_exists($classname) && method_exists($classname, 'templateContent')) {
            $tc = call_user_func(array($classname, 'templateContent'), $module_name,
                $smarty, $tpath.'/'.$panelname.'/tpl', $oPaloConsola, $estado);
            $tc['panelname'] = $panelname;
            $htmlpanels[] = $tc;
        }
    }
    $smarty->assign('CUSTOM_PANELS', $htmlpanels);


    return $smarty->fetch("$sDirLocalPlantillas/agent_console.tpl") . _cc_debug_flush_html();
}

function _manejarSesionActiva_HTML_generarInformacion($smarty, $sDirLocalPlantillas, $infoLlamada, $infoCampania)
{
    $atributos = array();
    foreach ($infoLlamada['call_attributes'] as $iOrden => $atributo) {
        // Skip index 1 (2nd column) for outgoing calls - it's shown in the hardcoded "Name:" field
        if ($infoLlamada['calltype'] == 'outgoing' && $iOrden == 1) {
            continue;
        }
        if (preg_match('|^http(s)?://|', $atributo['value'])) {
        	$atributo['value'] = '<a target="_blank" href="'.$atributo['value'].'">'.$atributo['value'].'</a>';
        } else {
            $atributo['value'] = htmlentities($atributo['value'], ENT_COMPAT, 'UTF-8');
        }
        $atributos[] = $atributo;
    }

    // Caso especial: verificación de etiquetas de contact llamada entrante
    if ($infoLlamada['calltype'] == 'incoming' && count($atributos) == 5) {
    	$n = 5;
        foreach ($atributos as $atributo) {
    		if (in_array($atributo['label'], array('first_name', 'last_name', 'phone', 'cedula_ruc', 'contact_source')))
                $n--;
    	}
        if ($n == 0) {
            $traduccion = array(
                'first_name'    =>  _tr('First name'),
                'last_name'     =>  _tr('Last name'),
                'phone'         =>  _tr('Phone number'),
                'cedula_ruc'    =>  _tr('National ID'),
            );

        	// Se deben copiar los atributos, excepto el contact_source
            $t = array();
            foreach ($atributos as $atributo) {
            	if ($atributo['label'] != 'contact_source') {
            		$atributo['label'] = $traduccion[$atributo['label']];
                    $t[] = $atributo;
            	}
            }
            $atributos = $t;
        }
    }

    // Asignaciones independientes del tipo de llamada
    $smarty->assign(array(
        'LBL_NOMBRE_CAMPANIA'           =>  _tr('Campaign'),
        'LBL_CALL_ID'                   =>  _tr('Internal Call ID'),
        'TEXTO_NOMBRE_CAMPANIA'         =>  (isset($infoCampania['name']) ? $infoCampania['name'] : '(none)'),
        'TEXTO_CALL_ID'                 =>  $infoLlamada['calltype'].'-'.
            (isset($infoLlamada['campaign_id']) ? $infoLlamada['campaign_id'] : 'q'.$infoLlamada['queue']).'-'.
            (isset($infoLlamada['contact_id']) ? 'c'.$infoLlamada['contact_id'] : (isset($infoLlamada['callid']) ? $infoLlamada['callid'] : $infoLlamada['call_id'])),
        'CALLINFO_CALLTYPE'             =>  $infoLlamada['calltype'],
        'LBL_CONTACTO_TELEFONO'         =>  _tr('Phone number'),
        'TEXTO_CONTACTO_TELEFONO'       =>  $infoLlamada['phone'],
    ));

    // Asignaciones específicas para llamadas entrantes
    if ($infoLlamada['calltype'] == 'incoming') {
        $comboContactos = array();
        foreach ($infoLlamada['matching_contacts'] as $idContacto => $tuplaContacto) {
            $infoContactoViejo = array();
            $sDescripcionContacto = '';
            foreach ($tuplaContacto as $attrContacto) {
                $sDescripcionContacto .= $attrContacto['value'].' ';
                if (in_array($attrContacto['label'], array('first_name', 'last_name', 'cedula_ruc')))
                    $infoContactoViejo[$attrContacto['label']] = $attrContacto['value'];
            }
            if (count($infoContactoViejo) == 3) {
                $comboContactos[$idContacto] = $infoContactoViejo['cedula_ruc'].
                ' - '.$infoContactoViejo['first_name'].' '.$infoContactoViejo['last_name'];
            } else {
                /* TODO: dar formato adecuado para cuando contactos de llamadas
                 * entrantes puedan tener atributos arbitrarios */
                $comboContactos[$idContacto] = $sDescripcionContacto;
            }
        }
        if (count($comboContactos) == 0) {
            $comboContactos[''] = _tr('(no matching contacts)');
        }
        $smarty->assign(array(
            'LBL_CONTACTO_SELECT'       =>  _tr('Contact'),
            'LISTA_CONTACTOS'           =>  $comboContactos,
            'BTN_CONFIRMAR_CONTACTO'    =>  _tr('Confirm contact'),
        ));
    }

    // Asignaciones específicas para llamadas salientes
    if ($infoLlamada['calltype'] == 'outgoing') {

        /* TODO: el siguiente código asume que el atributo 1 es el nombre
         * del cliente. Esta suposición se hereda del callcenter anterior.
         * Se debe de idear un método para dar formato al nombre del cliente
         * a partir de cualquier combinación de columnas */
        $sNombreCliente = isset($infoLlamada['call_attributes'][1])
            ? $infoLlamada['call_attributes'][1]['value']
            : _tr('(unavailable)');

        $smarty->assign(array(
            'LBL_CONTACTO_NOMBRES'          =>  _tr('Names'),
            'TEXTO_CONTACTO_NOMBRES'        =>  $sNombreCliente,
        ));
    }

    $smarty->assign(array(
        'MSG_NO_ATTRIBUTES'         =>  _tr('No information available for this call'),
        'ATRIBUTOS_LLAMADA'         =>  $atributos,
    ));
	return $smarty->fetch("$sDirLocalPlantillas/agent_console_atributos.tpl") . _cc_debug_flush_html();
}

// Se usa $infoLlamada['call_survey'] , $infoCampania['forms']
function _manejarSesionActiva_HTML_generarFormulario($smarty, $sDirLocalPlantillas, $infoLlamada, $infoCampania)
{
    $nforms = 0;

    // Se puebla current_value con los valores recogidos previamente, si existen
    if (isset($infoCampania['forms']) && is_array($infoCampania['forms'])) {
        $nforms += count($infoCampania['forms']);
        foreach ($infoCampania['forms'] as $idForm => $tuplaForm) {
            if (isset($infoLlamada['call_survey'][$idForm])) foreach ($tuplaForm['fields'] as $idxCampo => $tuplaCampo) {
                if (isset($infoLlamada['call_survey'][$idForm][$tuplaCampo['id']])) {
                    $infoCampania['forms'][$idForm]['fields'][$idxCampo]['current_value'] =
                        $infoLlamada['call_survey'][$idForm][$tuplaCampo['id']]['value'];
                }
            }
        }
        $smarty->assign('FORMS', $infoCampania['forms']);
    }

    if ($nforms > 0) {
        return $smarty->fetch("$sDirLocalPlantillas/agent_console_formulario.tpl") . _cc_debug_flush_html();
    } else {
        return _tr('No forms available for this call');
    }
}

function manejarSesionActiva_ping($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $gc_maxlifetime = ini_get('session.gc_maxlifetime');
    if ($gc_maxlifetime == "") $gc_maxlifetime = 10 * 60;
    $gc_maxlifetime = (int)$gc_maxlifetime;

    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode(array(
        'statusResponse'    =>  'OK',
        'gc_maxlifetime'    =>  $gc_maxlifetime,
    ));
}

function manejarSesionActiva_agentLogout($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $respuesta = array(
        'action'    =>  'logged-out',   // logged-out error
        'message'   =>  '(no message)',
    );
    $bExito = $oPaloConsola->logoutAgente();
    if (!$bExito) {
        $respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Error while logging out agent').' - '.$oPaloConsola->errMsg;
    }

    // Se asume que el único error posible en logout es que el agente ya
    // esté deslogoneado.
    $_SESSION['callcenter']['estado_consola'] = 'logged-out';
    $_SESSION['callcenter']['agente'] = NULL;
    $_SESSION['callcenter']['agente_nombre'] = NULL;
    $_SESSION['callcenter']['extension'] = NULL;

    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

function manejarSesionActiva_hangup($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $respuesta = array(
        'action'    =>  'hangup',
        'message'   =>  '(no message)',
    );
    $bExito = $oPaloConsola->colgarLlamada();
    if (!$bExito) {
        $respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Error while hanging up call').' - '.$oPaloConsola->errMsg;
    }

    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

function manejarSesionActiva_hold($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $respuesta = array(
        'action'    =>  'hold',
        'message'   =>  '(no message)',
    );

    // Check if currently on hold and toggle action
    if ($estado['onhold']) {
        // Resume call from hold
        $bExito = $oPaloConsola->reanudarDeHold();
        if (!$bExito) {
            $respuesta['action'] = 'error';
            $respuesta['message'] = _tr('Error while resuming call from hold').' - '.$oPaloConsola->errMsg;
        }
    } else {
        // Put call on hold
        $bExito = $oPaloConsola->ponerEnHold();
        if (!$bExito) {
            $respuesta['action'] = 'error';
            $respuesta['message'] = _tr('Error while placing call on hold').' - '.$oPaloConsola->errMsg;
        }
    }

    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

function manejarSesionActiva_break($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $respuesta = array(
        'action'    =>  'break',
        'message'   =>  '(no message)',
    );
    $idBreak = getParameter('breakid');
    if (is_null($idBreak) || !ctype_digit($idBreak)) {
    	$respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Invalid or missing break ID');
    } else {
        $bExito = $oPaloConsola->iniciarBreak($idBreak);
        if (!$bExito) {
            $respuesta['action'] = 'error';
            $respuesta['message'] = _tr('Error while starting break').' - '.$oPaloConsola->errMsg;
        }
    }

    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

function manejarSesionActiva_unbreak($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $respuesta = array(
        'action'    =>  'unbreak',
        'message'   =>  '(no message)',
    );
    $bExito = $oPaloConsola->terminarBreak();
    if (!$bExito) {
        $respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Error while stopping break').' - '.$oPaloConsola->errMsg;
    }

    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

function manejarSesionActiva_transfer($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $respuesta = array(
        'action'    =>  'transfer',
        'message'   =>  '(no message)',
    );
    $sTransferExt = getParameter('extension');
    if ($estado['onhold']) {
        $respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Cannot transfer while call is on hold');
    } elseif (is_null($sTransferExt) || !ctype_digit($sTransferExt)) {
        $respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Invalid or missing extension to transfer');
    } else {
        $bExito = $oPaloConsola->transferirLlamada($sTransferExt, in_array(getParameter('atxfer'), array('true', 'checked')));
        if ($bExito === FALSE) {
            $respuesta['action'] = 'error';
            $respuesta['message'] = _tr('Error while transferring call').' - '.$oPaloConsola->errMsg;
        } elseif ($bExito === 'consultation') {
            $respuesta['consultation'] = true;
        }
    }

    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

function manejarSesionActiva_transferagent($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $respuesta = array(
        'action'    =>  'transferagent',
        'message'   =>  '(no message)',
    );
    $sTargetAgent = getParameter('target_agent');
    if ($estado['onhold']) {
        $respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Cannot transfer while call is on hold');
    } elseif (is_null($sTargetAgent) || empty($sTargetAgent)) {
        $respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Invalid or missing target agent');
    } else {
        $bExito = $oPaloConsola->transferirLlamadaAgente($sTargetAgent);
        if (!$bExito) {
            $respuesta['action'] = 'error';
            $respuesta['message'] = _tr('Error while transferring call to agent').' - '.$oPaloConsola->errMsg;
        }
    }

    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

function manejarSesionActiva_confirm_contact($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $respuesta = array(
        'action'    =>  'confirmed',
        'message'   =>  _tr('Contact successfully confirmed'),
    );
    $idContact = getParameter('id_contact');
    if (is_null($idContact) || !ctype_digit($idContact)) {
        $respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Invalid or missing contact ID');
    } elseif (!isset($estado['callinfo']) || $estado['callinfo']['calltype'] != 'incoming') {
        $respuesta['action'] = 'error';
        $respuesta['message'] = _tr('Agent not handling an incoming call');
    } else {
        $bExito = $oPaloConsola->confirmarContacto($estado['callinfo']['callid'], $idContact);
        if (!$bExito) {
            $respuesta['action'] = 'error';
            $respuesta['message'] = _tr('Error while confirming contact').' - '.$oPaloConsola->errMsg;
        }
    }

    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

function manejarSesionActiva_schedule($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $respuesta = array(
        'action'    =>  'scheduled',
        'message'   =>  _tr('Call successfully scheduled'),
    );

    /* El orden de prioridad del uso de IDs es: parámetros especificados, luego
     * los parámetros almacenados en la sesión, y por último NULL para usar los
     * parámetros de la llamada activa. */
    $calltype = NULL;
    $callid = NULL;
    if (isset($_SESSION['callcenter']['ultimo_calltype']) &&
        isset($_SESSION['callcenter']['ultimo_callid'])) {
        $calltype = $_SESSION['callcenter']['ultimo_calltype'];
        $callid = $_SESSION['callcenter']['ultimo_callid'];
    }
    if (isset($_POST['calltype']) && isset($_POST['callid'])) {
        $calltype = $_POST['calltype'];
        $callid = $_POST['callid'];
    }

    $infoAgendar = getParameter('data');
    foreach (array('schedule_new_phone', 'schedule_new_name',
        'schedule_use_daterange', 'schedule_use_sameagent',
        'schedule_date_start', 'schedule_date_end', 'schedule_time_start',
        'schedule_time_end') as $k)
        if (!isset($infoAgendar[$k])) $infoAgendar[$k] = NULL;

    $schedule = in_array($infoAgendar['schedule_use_daterange'], array('true', 'checked')) ? array(
        'date_init' =>  $infoAgendar['schedule_date_start'],
        'date_end'  =>  $infoAgendar['schedule_date_end'],
        'time_init' =>  $infoAgendar['schedule_time_start'],
        'time_end'  =>  $infoAgendar['schedule_time_end'],
    ) : NULL;
    $sameagent = in_array($infoAgendar['schedule_use_sameagent'], array('true', 'checked'));
    $newphone = $infoAgendar['schedule_new_phone'];
    $newname = $infoAgendar['schedule_new_name'];

    if (is_array($schedule) && ($schedule['date_init'] == '' || $schedule['date_end'] == '' ||
        $schedule['time_init'] == '' || $schedule['time_end'] == '')) {
        $respuesta = array(
            'action'    =>  'error',
            'message'   =>  _tr('Invalid or incomplete schedule'),
        );
    } else {
        $bExito = $oPaloConsola->agendarLlamada($schedule, $sameagent, $newphone,
            $newname, $calltype, $callid);
        if (!$bExito) {
            $respuesta['action'] = 'error';
            $respuesta['message'] = _tr('Error while scheduling call').' - '.$oPaloConsola->errMsg;
        }
    }
    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

function manejarSesionActiva_saveforms($module_name, $smarty, $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    $respuesta = array(
        'action'    =>  'saved',
        'message'   =>  _tr('Form data successfully saved'),
    );

    $formdata = getParameter('data');
    if (!is_array($formdata)) {
        $respuesta = array(
            'action'    =>  'error',
            'message'   =>  _tr('Invalid or incomplete form data'),
        );
    } else {
        $bExito = TRUE;

        $formInfo = array();
        foreach ($formdata as $tupladata) {
            $regs = NULL;
            if (preg_match('/^field-(\d+)-(\d+)$/', $tupladata[0], $regs)) {
                $formInfo[$regs[1]][$regs[2]] = $tupladata[1];
                $_SESSION['callcenter']['ultimo_callsurvey']['call_survey'][$regs[1]][$regs[2]] = array(
                    'label' =>  '', // TODO: asignar desde formulario de campaña
                    'value' =>  $tupladata[1],
                );
            }
        }

        if ($bExito && count($formInfo) > 0) {
            $bExito = $oPaloConsola->guardarDatosFormularios(
                $_SESSION['callcenter']['ultimo_calltype'],
                $_SESSION['callcenter']['ultimo_callid'],
                $formInfo);
            if (!$bExito) {
                $respuesta['action'] = 'error';
                $respuesta['message'] = _tr('Error while saving form data').' - '.$oPaloConsola->errMsg;
            }
        }
    }
    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

// Handler for updating shift times when filter changes
function manejarSesionActiva_updateShiftTimes($module_name, $smarty,
    $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    global $arrConf;

    $shiftFrom = getParameter('shift_from');
    $shiftTo = getParameter('shift_to');
    $shiftFrom = is_numeric($shiftFrom) ? intval($shiftFrom) : 0;
    $shiftTo = is_numeric($shiftTo) ? intval($shiftTo) : 23;

    // Get current agent channel from session
    $agentChannel = $_SESSION['callcenter']['agente'];

    // Query shift-based times for this agent
    $shiftRange = agentConsole_calculateShiftDatetimeRange($shiftFrom, $shiftTo);
    $breakData = agentConsole_consultarTiempoBreakAgentes($shiftRange['start'], $shiftRange['end']);
    $holdData = agentConsole_consultarTiempoHoldAgentes($shiftRange['start'], $shiftRange['end']);
    $loginData = agentConsole_consultarTiempoLoginAgentes($shiftRange['start'], $shiftRange['end']);

    $sec_shift_login = isset($loginData[$agentChannel]) ? $loginData[$agentChannel] : 0;
    $sec_shift_break = isset($breakData['breakTimes'][$agentChannel]) ? $breakData['breakTimes'][$agentChannel] : 0;
    $sec_shift_hold = isset($holdData[$agentChannel]) ? $holdData[$agentChannel] : 0;

    // Add current pause time if agent is on a pause
    $isHoldPause = false;
    if (!is_null($estado['pauseinfo'])) {
        $iCurrentPauseDur = time() - strtotime($estado['pauseinfo']['pausestart']);
        $isHoldPause = in_array($estado['pauseinfo']['pausename'], isset($breakData['holdNames']) ? $breakData['holdNames'] : array());
        if ($isHoldPause) {
            $sec_shift_hold += $iCurrentPauseDur;
        } else {
            $sec_shift_break += $iCurrentPauseDur;
        }
    }

    $respuesta = array(
        'shift_login_time' => $sec_shift_login,
        'shift_break_time' => $sec_shift_break,
        'shift_hold_time'  => $sec_shift_hold,
        'is_hold_pause'    => $isHoldPause,
    );

    $json = new Services_JSON();
    Header('Content-Type: application/json');
    return $json->encode($respuesta);
}

function manejarSesionActiva_checkStatus($module_name, $smarty,
    $sDirLocalPlantillas, $oPaloConsola, $estado)
{
    _debug(__FUNCTION__.' start');

    $respuesta = array();
    setupSSESession();

    $sNombrePausa = NULL;
    $iDuracionLlamada = NULL;
    $sLinkStartLlamada = NULL;
    $iDuracionHold = NULL;
    $iDuracionPausa = $iDuracionPausaActual = NULL;

    $estadoCliente = getParameter('clientstate');
    _debug(__FUNCTION__.' before sanitizing clientstate='.print_r($estadoCliente, TRUE));

    // Validación del estado del cliente:
    // onhold break_id calltype campaign_id callid
    $estadoCliente['onhold'] = isset($estadoCliente['onhold'])
        ? ($estadoCliente['onhold'] == 'true')
        : false;
    foreach (array('break_id', 'calltype', 'campaign_id', 'callid') as $k) {
        if (!isset($estadoCliente[$k]) || $estadoCliente[$k] == 'null' || $estadoCliente[$k] == '')
            $estadoCliente[$k] = NULL;
    }
    if (is_null($estadoCliente['calltype'])) {
        $estadoCliente['campaign_id'] = $estadoCliente['callid'] = NULL;
    } elseif (is_null($estadoCliente['callid'])) {
        $estadoCliente['campaign_id'] = $estadoCliente['calltype'] = NULL;
    } elseif (is_null($estadoCliente['campaign_id']) && $estadoCliente['calltype'] != 'incoming') {
        $estadoCliente['calltype'] = $estadoCliente['callid'] = NULL;
    }
    $estadoCliente['waitingcall'] = isset($estadoCliente['waitingcall'])
        ? ($estadoCliente['waitingcall'] == 'true')
        : false;
    if (!isset($estadoCliente['consultation']) ||
            !in_array($estadoCliente['consultation'], array('none', 'ringing', 'answered'))) {
        $estadoCliente['consultation'] = 'none';
    }

    _debug(__FUNCTION__.' after sanitizing clientstate='.print_r($estadoCliente, TRUE));

    // Modo a funcionar: Long-Polling, o Server-sent Events
    $bSSE = detectSSEMode();
    initSSE($bSSE);

    _debug(__FUNCTION__.' using Server-sent Events: '.($bSSE ? 'YES' : 'NO'));
    _debug(__FUNCTION__.' server state for agent='.print_r($estado, TRUE));

    // Respuesta inmediata si el agente ya no está logoneado
    if ($estado['estadofinal'] != 'logged-in') {
        // Respuesta inmediata si el agente ya no está logoneado
        $respuesta[] = array(
            'event' =>  'logged-out',
        );
        jsonflush($bSSE, $respuesta);
        _debug(__FUNCTION__.' agent not logged-in, aborting.');
        return;
    }

    // Verificación de la consistencia del estado de break
    if (!is_null($estado['pauseinfo'])) {
        $sNombrePausa = $estado['pauseinfo']['pausename'];
        $iDuracionPausaActual = time() - strtotime($estado['pauseinfo']['pausestart']);
        $iDuracionPausa = $iDuracionPausaActual + $_SESSION['callcenter']['break_acumulado'];
    } else {
        /* Si esta condición se cumple, entonces se ha perdido el evento
         * pauseexit durante la espera en manejarSesionActiva_checkStatus().
         * Se hace la suposición de que el refresco ocurre poco después de
         * que termina el break, y que por lo tanto el error al usar time()
         * como fin del break es pequeño.
         */
        if (!is_null($_SESSION['callcenter']['break_iniciado'])) {
           $_SESSION['callcenter']['break_acumulado'] += time() - strtotime($_SESSION['callcenter']['break_iniciado']);
        }
        $_SESSION['callcenter']['break_iniciado'] = NULL;
    }
    if (!is_null($estado['pauseinfo']) &&
        (is_null($estadoCliente['break_id']) || $estadoCliente['break_id'] != $estado['pauseinfo']['pauseid'])) {
        // La consola debe de entrar en break
        $respuesta[] = construirRespuesta_breakenter($estado['pauseinfo']['pauseid']);
        _debug(__FUNCTION__.' initial: agent has entered break');
    } elseif (!is_null($estadoCliente['break_id']) && is_null($estado['pauseinfo'])) {
        // La consola debe de salir del break
        $respuesta[] = construirRespuesta_breakexit();
        _debug(__FUNCTION__.' initial: agent has exited break');
    }

    // Verificación de la consistencia del estado de hold
    // Duración del hold en curso, para el cronómetro de la barra naranja
    // EN: duration of the running hold, for the orange bar's timer
    if ($estado['onhold'] && !empty($estado['holdstart'])) {
        $iDuracionHold = time() - strtotime($estado['holdstart']);
    }
    if (!$estadoCliente['onhold'] && $estado['onhold']) {
        // La consola debe de entrar en hold
        $respuesta[] = construirRespuesta_holdenter();
        _debug(__FUNCTION__.' initial: agent has entered hold');
    } elseif ($estadoCliente['onhold'] && !$estado['onhold']) {
        // La consola debe de salir de hold
        $respuesta[] = construirRespuesta_holdexit();
        _debug(__FUNCTION__.' initial: agent has exited break');
    }

    if (!is_null($estado['callinfo'])) {
        $sLinkStartLlamada = $estado['callinfo']['linkstart'];
        $iDuracionLlamada = time() - strtotime($sLinkStartLlamada);
    }

    // Verificación de atención a llamada
    if (!is_null($estado['callinfo']) &&
        (is_null($estadoCliente['calltype']) ||
            $estadoCliente['calltype'] != $estado['callinfo']['calltype'] ||
            $estadoCliente['campaign_id'] != $estado['callinfo']['campaign_id'] ||
            $estadoCliente['callid'] != $estado['callinfo']['callid'])) {

        // Información sobre la llamada conectada
        $infoLlamada = $oPaloConsola->leerInfoLlamada(
            $estado['callinfo']['calltype'],
            $estado['callinfo']['campaign_id'],
            $estado['callinfo']['callid']);

        // Leer información del formulario de la campaña
        if ($estado['callinfo']['calltype'] == 'incoming' && is_null($estado['callinfo']['campaign_id'])) {
            $infoCampania['forms'] = NULL;
        } else {
            $infoCampania = $oPaloConsola->leerInfoCampania(
                $estado['callinfo']['calltype'],
                $estado['callinfo']['campaign_id']);
        }

        // Almacenar para regenerar formulario
        $_SESSION['callcenter']['ultimo_calltype'] = $estado['callinfo']['calltype'];
        $_SESSION['callcenter']['ultimo_callid'] = $estado['callinfo']['callid'];
        $_SESSION['callcenter']['ultimo_callsurvey']['call_survey'] = $infoLlamada['call_survey'];
        $_SESSION['callcenter']['ultimo_campaignform']['forms'] = $infoCampania['forms'];

        $respuesta[] = construirRespuesta_agentlinked($smarty, $sDirLocalPlantillas,
            $oPaloConsola, $estado['callinfo'], $infoLlamada, $infoCampania);
        _debug(__FUNCTION__.' initial: agent has received call');
    } elseif (!is_null($estadoCliente['calltype']) && is_null($estado['callinfo'])) {
        // La consola dejó de atender una llamada
        $infoCampania = array();
        if (!($estadoCliente['calltype'] == 'incoming' && is_null($estadoCliente['campaign_id']))) {
            $infoCampania = $oPaloConsola->leerInfoCampania(
                $estadoCliente['calltype'],
                $estadoCliente['campaign_id']);
        }
        if (!is_array($infoCampania)) $infoCampania = array();
        $infoCampania['forms'] = NULL;

        $callinfoHangup = array(
            'calltype'    => $estadoCliente['calltype'],
            'campaign_id' => $estadoCliente['campaign_id'],
            'callid'      => $estadoCliente['callid'],
            'callnumber'  => NULL,
            'linkstart'   => NULL,
        );
        $infoLlamadaHangup = $oPaloConsola->leerInfoLlamada(
            $estadoCliente['calltype'],
            $estadoCliente['campaign_id'],
            $estadoCliente['callid']);
        if (!is_array($infoLlamadaHangup)) $infoLlamadaHangup = array();
        foreach (array('agent_number', 'remote_channel', 'uniqueid',
            'campaign_id', 'callid', 'calltype', 'callnumber') as $k) {
            if (!isset($infoLlamadaHangup[$k]))
                $infoLlamadaHangup[$k] = isset($callinfoHangup[$k]) ? $callinfoHangup[$k] : NULL;
        }

        $respuesta[] = construirRespuesta_agentunlinked($smarty, $sDirLocalPlantillas,
            $oPaloConsola, $callinfoHangup, $infoLlamadaHangup, $infoCampania);
        _debug(__FUNCTION__.' initial: agent has ended call');
    }

    /* Verificación de la consistencia del estado de consulta de transferencia
     * atendida. Los eventos ConsultationStart/Answered/End sólo llegan a los
     * clientes ECCP conectados en ese instante, así que la consola puede
     * perder uno mientras su long-poll se está reconectando y quedarse con el
     * botón Hangup mostrando "Cancel transfer" hasta recargar la página. Aquí
     * se compara contra el estado real y se sintetiza el evento faltante, tal
     * como ya se hace arriba con break y hold. */
    /* EN: Attended-transfer consultation state consistency check. The
     * ConsultationStart/Answered/End events only reach ECCP clients connected
     * at that very instant, so the console can miss one while its long poll is
     * reconnecting and be left with the Hangup button stuck showing "Cancel
     * transfer" until the page is reloaded. Compare against the real state and
     * synthesize the missing event, exactly as break and hold do above. */
    $sConsultaReal = isset($estado['consultation']) ? $estado['consultation'] : 'none';
    if ($sConsultaReal != $estadoCliente['consultation']) {
        switch ($sConsultaReal) {
        case 'ringing':
            $respuesta[] = array('event' => 'consultationstart');
            break;
        case 'answered':
            $respuesta[] = array('event' => 'consultationanswered');
            break;
        default:
            /* Se adjunta el motivo guardado por el dialer (BUSY/NOANSWER/...)
             * para que el aviso al agente aparezca también cuando el evento
             * ConsultationEnd original se perdió, que es justo lo que ocurre
             * casi siempre con el colega ocupado. */
            /* EN: Attach the reason the dialer stored (BUSY/NOANSWER/...) so
             * the agent still gets the notice when the original ConsultationEnd
             * event was lost, which is exactly what almost always happens with
             * a busy colleague. */
            $respuesta[] = array(
                'event'  => 'consultationend',
                'reason' => (isset($estado['consultation_reason']) && $estado['consultation_reason'] !== '')
                    ? $estado['consultation_reason'] : NULL,
            );
            break;
        }
        _debug(__FUNCTION__.' initial: consultation state resynced to '.$sConsultaReal.
            ' reason='.(isset($estado['consultation_reason']) ? $estado['consultation_reason'] : '(none)'));
    }

    // Verificación de espera de llamada
    if (!is_null($estado['waitedcallinfo']) && !$estadoCliente['waitingcall']) {
        $respuesta[] = construirRespuesta_waitingenter($oPaloConsola, $estado['waitedcallinfo']);
        _debug(__FUNCTION__.' initial: agent is waiting for manual call');
    } elseif (is_null($estado['waitedcallinfo']) && $estadoCliente['waitingcall']) {
        $respuesta[] = construirRespuesta_waitingexit();
        _debug(__FUNCTION__.' initial: agent stops waiting for manual call');
    }

    _debug(__FUNCTION__.' initial list of changes: '.print_r($respuesta, TRUE));

    // Ciclo de verificación para Server-sent Events
    $sAgente = $_SESSION['callcenter']['agente'];
    $iTimeoutPoll = $oPaloConsola->recomendarIntervaloEsperaAjax();
    $bReinicioSesion = FALSE;
    do {
        $oPaloConsola->desconectarEspera();

        // Se inicia espera larga con el navegador...
        session_commit();
        $iTimestampInicio = time();

        $respuestaEventos = array();

        $oPaloConsola->pingAgente();
        while (connection_status() == CONNECTION_NORMAL &&
            count($respuestaEventos) <= 0 && count($respuesta) <= 0
            && time() - $iTimestampInicio <  $iTimeoutPoll) {

            $listaEventos = $oPaloConsola->esperarEventoSesionActiva();
            if (is_null($listaEventos)) {
                // Ocurrió una excepción al esperar eventos
                @session_start();

                $respuesta[] = array(
                    'event' =>  'logged-out',
                );

                // Eliminar la información de login
                $_SESSION['callcenter'] = generarEstadoInicial();
                $bReinicioSesion = TRUE;
                break;
            }

            foreach ($listaEventos as $evento) switch ($evento['event']) {
            case 'agentloggedout':
                // Reiniciar la sesión para poder modificar las variables
                @session_start();

                $respuesta[] = array(
                    'event' =>  'logged-out',
                );

                // Eliminar la información de login
                $_SESSION['callcenter'] = generarEstadoInicial();
                $bReinicioSesion = TRUE;
                break;
            case 'pausestart':
                if (!(isset($evento['agent_number']) && $evento['agent_number'] == $sAgente)) break;
                unset($respuestaEventos[$evento['pause_class']]);
                switch ($evento['pause_class']) {
                case 'break':
                    if (is_null($estadoCliente['break_id']) ||
                        $estadoCliente['break_id'] != $evento['pause_type']) {
                        $sNombrePausa = $evento['pause_name'];
                        $respuestaEventos['break'] = construirRespuesta_breakenter($evento['pause_type']);
                    }
                    @session_start();
                    $iDuracionPausaActual = time() - strtotime($evento['pause_start']);
                    $iDuracionPausa = $iDuracionPausaActual + $_SESSION['callcenter']['break_acumulado'];
                    $_SESSION['callcenter']['break_iniciado'] = $evento['pause_start'];
                    break;
                case 'hold':
                    if (!$estadoCliente['onhold']) {
                        $respuestaEventos['hold'] = construirRespuesta_holdenter();
                    }
                    break;
                }
                break;
            case 'pauseend':
                if (!(isset($evento['agent_number']) && $evento['agent_number'] == $sAgente)) break;
                unset($respuestaEventos[$evento['pause_class']]);
                switch ($evento['pause_class']) {
                case 'break':
                    if (!is_null($estadoCliente['break_id'])) {
                        $respuestaEventos['break'] = construirRespuesta_breakexit();
                    }
                    @session_start();
                    if (!is_null($_SESSION['callcenter']['break_iniciado'])) {
                        $_SESSION['callcenter']['break_acumulado'] += $evento['pause_duration'];
                        $_SESSION['callcenter']['break_iniciado'] = NULL;
                    }
                    break;
                case 'hold':
                    if ($estadoCliente['onhold']) {
                        $respuestaEventos['hold'] = construirRespuesta_holdexit();
                    }
                    break;
                }
                break;
            case 'agentlinked':
                if (!(isset($evento['agent_number']) && $evento['agent_number'] == $sAgente)) break;
                unset($respuestaEventos['llamada']);
                /* Actualizar la interfaz si entra una nueva llamada, o si
                 * la llamada activa anterior es reemplazada. */
                if (is_null($estadoCliente['calltype']) ||
                    $estadoCliente['calltype'] != $evento['call_type'] ||
                    $estadoCliente['campaign_id'] != $evento['campaign_id'] ||
                    $estadoCliente['callid'] != $evento['call_id']) {
                    $nuevoEstado = array(
                        'calltype'      =>  $evento['call_type'],
                        'campaign_id'   =>  $evento['campaign_id'],
                        'linkstart'     =>  $evento['datetime_linkstart'],
                        'callid'        =>  $evento['call_id'],
                        'callnumber'    =>  $evento['phone'],
                    );
                    $sLinkStartLlamada = $nuevoEstado['linkstart'];
                    $iDuracionLlamada = time() - strtotime($sLinkStartLlamada);

                    // Leer información del formulario de la campaña
                    if ($nuevoEstado['calltype'] == 'incoming' && is_null($nuevoEstado['campaign_id'])) {
                        $infoCampania['forms'] = NULL;
                    } else {
                        $infoCampania = $oPaloConsola->leerInfoCampania(
                            $nuevoEstado['calltype'],
                            $nuevoEstado['campaign_id']);
                    }

                    // Almacenar para regenerar formulario
                    @session_start();
                    $_SESSION['callcenter']['ultimo_calltype'] = $nuevoEstado['calltype'];
                    $_SESSION['callcenter']['ultimo_callid'] = $nuevoEstado['callid'];
                    $_SESSION['callcenter']['ultimo_callsurvey']['call_survey'] = $evento['call_survey'];
                    $_SESSION['callcenter']['ultimo_campaignform']['forms'] = $infoCampania['forms'];

                    $respuestaEventos['llamada'] = construirRespuesta_agentlinked(
                        $smarty, $sDirLocalPlantillas, $oPaloConsola, $nuevoEstado,
                        $evento, $infoCampania);

                    // Si la llamada fue enlazada, entonces ya no está esperando
                    if ($estadoCliente['waitingcall']) {
                        $respuestaEventos['waitingcall'] = construirRespuesta_waitingexit();
                    }
                }
                break;
            case 'agentunlinked':
                if (!(isset($evento['agent_number']) && $evento['agent_number'] == $sAgente)) break;
                unset($respuestaEventos['llamada']);
                if (!is_null($estadoCliente['calltype'])) {
                    $nuevoEstado = array(
                        'calltype'      =>  $evento['call_type'],
                        'campaign_id'   =>  $evento['campaign_id'],
                        'linkstart'     =>  isset($evento['datetime_linkstart']) ? $evento['datetime_linkstart'] : NULL,
                        'callid'        =>  $evento['call_id'],
                        'callnumber'    =>  isset($evento['phone']) ? $evento['phone'] : NULL,
                    );
                    if ($nuevoEstado['calltype'] == 'incoming' && is_null($nuevoEstado['campaign_id'])) {
                        $infoCampania = array('forms' => NULL);
                    } else {
                        $infoCampania = $oPaloConsola->leerInfoCampania(
                            $nuevoEstado['calltype'],
                            $nuevoEstado['campaign_id']);
                    }
                    $respuestaEventos['llamada'] = construirRespuesta_agentunlinked(
                        $smarty, $sDirLocalPlantillas, $oPaloConsola,
                        $nuevoEstado, $evento, $infoCampania);
                }
                break;
            case 'schedulecallstart':
                if (!(isset($evento['agent_number']) && $evento['agent_number'] == $sAgente)) break;
                if (!$estadoCliente['waitingcall']) {
                    $respuestaEventos['waitingcall'] = construirRespuesta_waitingenter($oPaloConsola, array(
                        'calltype'          => $evento['calltype'],
                        'campaign_id'       => $evento['campaign_id'],
                        'callid'            => $evento['call_id'],
                        //'status'            => $evento['new_status'],
                    ));
                }
                break;
            case 'schedulecallfailed':
                if (!(isset($evento['agent_number']) && $evento['agent_number'] == $sAgente)) break;
                if ($estadoCliente['waitingcall']) {
                    $respuestaEventos['waitingcall'] = construirRespuesta_waitingexit();
                }
                break;
            case 'consultationstart':
                if (!(isset($evento['agent_number']) && $evento['agent_number'] == $sAgente)) break;
                $respuestaEventos['consultation'] = array('event' => 'consultationstart');
                break;
            case 'consultationend':
                if (!(isset($evento['agent_number']) && $evento['agent_number'] == $sAgente)) break;
                $respuestaEventos['consultation'] = array(
                    'event'  => 'consultationend',
                    'reason' => isset($evento['reason']) ? $evento['reason'] : NULL,
                );
                break;
            case 'consultationanswered':
                if (!(isset($evento['agent_number']) && $evento['agent_number'] == $sAgente)) break;
                $respuestaEventos['consultation'] = array('event' => 'consultationanswered');
                break;
            }
        } // while(...)

        // Sólo debe haber hasta un evento de llamada, de break, de hold
        if (isset($respuestaEventos['break'])) $respuesta[] = $respuestaEventos['break'];
        if (isset($respuestaEventos['hold'])) $respuesta[] = $respuestaEventos['hold'];
        if (isset($respuestaEventos['llamada'])) $respuesta[] = $respuestaEventos['llamada'];
        if (isset($respuestaEventos['waitingcall'])) $respuesta[] = $respuestaEventos['waitingcall'];
        if (isset($respuestaEventos['consultation'])) $respuesta[] = $respuestaEventos['consultation'];

        // Acumular todos los eventos que no deben de ser únicos
        if (isset($respuestaEventos['other'])) $respuesta = array_merge($respuesta, $respuestaEventos['other']);

        // Agregar los textos a cambiar en la interfaz
        $sDescInicial = describirEstadoBarra($estadoCliente);
        foreach ($respuesta as $evento) switch ($evento['event']) {
        case 'holdenter':
            $estadoCliente['onhold'] = TRUE;
            break;
        case 'holdexit':
            $estadoCliente['onhold'] = FALSE;
            break;
        case 'breakenter':
            $estadoCliente['break_id'] = $evento['break_id'];
            break;
        case 'breakexit':
            $estadoCliente['break_id'] = NULL;
            break;
        case 'agentlinked':
            $estadoCliente['calltype'] = $evento['calltype'];
            $estadoCliente['campaign_id'] = $evento['campaign_id'];
            $estadoCliente['callid'] = $evento['callid'];
            break;
        case 'agentunlinked':
            $estadoCliente['calltype'] = NULL;
            $estadoCliente['campaign_id'] = NULL;
            $estadoCliente['callid'] = NULL;
            break;
        case 'waitingenter':
            $estadoCliente['waitingcall'] = TRUE;
            break;
        case 'waitingexit':
            $estadoCliente['waitingcall'] = FALSE;
            break;
        default:
            _debug(__FUNCTION__.' '.$evento['event'].': does not modify clientstate');
            break;
        }
        /* El long-poll se bloquea hasta que llega un evento, así que la
         * duración calculada al inicio de la petición puede tener muchos
         * segundos de retraso cuando por fin se usa aquí. Se recalcula en el
         * momento de usarla, o el cronómetro arrancaría atrasado justo esos
         * segundos: se nota al salir de un hold, donde la barra vuelve a
         * "llamada" mucho después de haberse calculado. */
        /* EN: The long poll blocks until an event arrives, so the duration
         * computed at the start of the request can be many seconds stale by
         * the time it is used here. Recompute it at the point of use, or the
         * timer starts exactly that many seconds behind - visible when leaving
         * a hold, where the bar returns to "llamada" long after the value was
         * computed. */
        if (!is_null($sLinkStartLlamada)) {
            $iDuracionLlamada = time() - strtotime($sLinkStartLlamada);
        }
        $sDescFinal = describirEstadoBarra($estadoCliente);
        $iPosEvento = count($respuesta) - 1;
        _debug(__FUNCTION__.' old barstate '.$sDescInicial.' new barstate '.$sDescFinal);
        if ($iPosEvento >= 0 && $sDescInicial != $sDescFinal) switch ($sDescFinal) {
        case 'llamada':
            $respuesta[$iPosEvento]['txt_estado_agente_inicial'] = _tr('Connected to call');
            $respuesta[$iPosEvento]['class_estado_agente_inicial'] = 'issabel-callcenter-class-estado-activo';
            $respuesta[$iPosEvento]['timer_seconds'] = $iDuracionLlamada;
            break;
        case 'break':
            $respuesta[$iPosEvento]['txt_estado_agente_inicial'] = _tr('On break').': '.$sNombrePausa;
            $respuesta[$iPosEvento]['class_estado_agente_inicial'] = 'issabel-callcenter-class-estado-break';
            $respuesta[$iPosEvento]['timer_seconds'] = $iDuracionPausa;
            break;
        case 'hold':
            $respuesta[$iPosEvento]['txt_estado_agente_inicial'] = _tr('Call on hold');
            $respuesta[$iPosEvento]['class_estado_agente_inicial'] = 'issabel-callcenter-class-estado-hold';
            // Timer for the current hold, not the call and not the shift total
            $respuesta[$iPosEvento]['timer_seconds'] = is_null($iDuracionHold) ? 0 : $iDuracionHold;
            break;
        case 'esperando':
            $respuesta[$iPosEvento]['txt_estado_agente_inicial'] = _tr('Waiting for call');
            $respuesta[$iPosEvento]['class_estado_agente_inicial'] = 'issabel-callcenter-class-estado-esperando';
            $respuesta[$iPosEvento]['timer_seconds'] = '';
            break;
        case 'ocioso':
            $respuesta[$iPosEvento]['txt_estado_agente_inicial'] = _tr('No active call');
            $respuesta[$iPosEvento]['class_estado_agente_inicial'] = 'issabel-callcenter-class-estado-ocioso';
            $respuesta[$iPosEvento]['timer_seconds'] = '';
            break;
        }

        // Add shift-based times to the response
        // EN: Calculate shift times for the current agent
        $agentChannel = $_SESSION['callcenter']['agente'];
        $shiftRange = agentConsole_calculateShiftDatetimeRange(0, 23);
        $breakData = agentConsole_consultarTiempoBreakAgentes($shiftRange['start'], $shiftRange['end']);
        $holdData = agentConsole_consultarTiempoHoldAgentes($shiftRange['start'], $shiftRange['end']);
        $loginData = agentConsole_consultarTiempoLoginAgentes($shiftRange['start'], $shiftRange['end']);

        $sec_shift_login = isset($loginData[$agentChannel]) ? $loginData[$agentChannel] : 0;
        $sec_shift_break = isset($breakData['breakTimes'][$agentChannel]) ? $breakData['breakTimes'][$agentChannel] : 0;
        $sec_shift_hold = isset($holdData[$agentChannel]) ? $holdData[$agentChannel] : 0;
        $holdNames = isset($breakData['holdNames']) ? $breakData['holdNames'] : array();

        // Add current active break/hold time if applicable
        $is_hold_pause = false;
        if (!is_null($estado['pauseinfo'])) {
            $iCurrentPauseDur = time() - strtotime($estado['pauseinfo']['pausestart']);
            if (in_array($estado['pauseinfo']['pausename'], $holdNames)) {
                $sec_shift_hold += $iCurrentPauseDur;
                $is_hold_pause = true;
            } else {
                $sec_shift_break += $iCurrentPauseDur;
            }
        }

        // Add shift times to each response event
        foreach ($respuesta as $idx => $evt) {
            $respuesta[$idx]['shift_login_time'] = $sec_shift_login;
            $respuesta[$idx]['shift_break_time'] = $sec_shift_break;
            $respuesta[$idx]['shift_hold_time'] = $sec_shift_hold;
            $respuesta[$idx]['is_hold_pause'] = $is_hold_pause;
        }

        jsonflush($bSSE, $respuesta);

        $respuesta = array();

    } while($bSSE && !$bReinicioSesion && connection_status() == CONNECTION_NORMAL);
    $oPaloConsola->desconectarTodo();
}

/*
 * La barra de color de la interfaz debe terminar en uno de tres estados:
 * llamada, break, ocioso.
 */
function describirEstadoBarra($estado)
{
    if (!is_null($estado['calltype'])) {
        /* El hold es un refinamiento de "en llamada", así que se comprueba
         * dentro de esta rama y se conserva la precedencia actual (un break
         * tomado durante una llamada sigue mostrándose en verde).
         *
         * Nunca durante una consulta de transferencia atendida: ahí el cliente
         * también escucha música, pero el agente está hablando con un colega,
         * no reteniendo la llamada. */
        /* EN: A hold is a refinement of "on a call", so it is checked inside
         * this branch and the existing precedence is preserved (a break taken
         * during a call still shows green).
         *
         * Never during an attended-transfer consultation: the customer hears
         * music there too, but the agent is talking to a colleague rather than
         * holding the call. */
        if (!empty($estado['onhold']) &&
                (!isset($estado['consultation']) || $estado['consultation'] == 'none'))
            return 'hold';
        return 'llamada';
    }
    if ($estado['waitingcall'])
        return 'esperando';
    if (!is_null($estado['break_id']))
        return 'break';
    return 'ocioso';
}

function construirRespuesta_breakenter($pause_id)
{
    return array(
        'event'                     =>  'breakenter',
        'break_id'                  =>  $pause_id,

        // Etiquetas a modificar en la interfaz
        'txt_btn_break' =>              _tr('End Break'),
    );
}

function construirRespuesta_breakexit()
{
    return array(
        'event'                     =>  'breakexit',

        // Etiquetas a modificar en la interfaz
        'txt_btn_break'             =>  _tr('Take Break'),
    );
}

function construirRespuesta_holdenter()
{
    return array(
        'event'         =>  'holdenter',

        // Etiquetas a modificar en la interfaz
        'txt_btn_hold' =>  _tr('End Hold'),
    );
}

function construirRespuesta_holdexit()
{
    return array(
        'event'         =>  'holdexit',

        // Etiquetas a modificar en la interfaz
        'txt_btn_hold' =>  _tr('Hold'),
    );
}

function construirRespuesta_agentlinked($smarty, $sDirLocalPlantillas,
    $oPaloConsola, $callinfo, $infoLlamada, &$infoCampania)
{
    foreach (array('calltype', 'campaign_id', 'callid', 'callnumber',
        'agent_number', 'remote_channel') as $k) {
        if (!isset($infoLlamada[$k]) && isset($callinfo[$k]))
            $infoLlamada[$k] = $callinfo[$k];
    }
    if ($callinfo['calltype'] == 'incoming' && is_null($callinfo['campaign_id'])) {
        $infoCampania['queue'] = $infoLlamada['queue'];
        $infoCampania['script'] = $oPaloConsola->leerScriptCola($infoCampania['queue']);
        $infoCampania['forms'] = NULL;
    }
    if (is_null($infoCampania['script']) || $infoCampania['script'] == '')
        $infoCampania['script'] = _tr('(No script available)');

    // Variables de canal de la llamada activa
    $chanvars = $oPaloConsola->leerVariablesCanalLlamadaActiva();

    // Fecha completa de la llamada
    $iDuracionLlamada = time() - strtotime($callinfo['linkstart']);

    // La consola empezó a atender a una llamada
    $registroCambio = array(
        'event'                 =>  'agentlinked',
        'calltype'              =>  $callinfo['calltype'],
        'campaign_id'           =>  $callinfo['campaign_id'],
        'callid'                =>  $callinfo['callid'],
        'txt_contacto_telefono' =>  $callinfo['callnumber'],
        'cronometro'            =>  sprintf('%02d:%02d:%02d', ($iDuracionLlamada - ($iDuracionLlamada % 3600)) / 3600, (($iDuracionLlamada - ($iDuracionLlamada % 60)) / 60) % 60, $iDuracionLlamada % 60),
        'llamada_informacion'   =>  _manejarSesionActiva_HTML_generarInformacion($smarty, $sDirLocalPlantillas, $infoLlamada, $infoCampania),
        'llamada_formulario'    =>  _manejarSesionActiva_HTML_generarFormulario($smarty, $sDirLocalPlantillas, $infoLlamada, $infoCampania),
        'llamada_script'        =>  $infoCampania['script'],
        'urlopentype'           =>  isset($infoCampania['urlopentype']) ? $infoCampania['urlopentype'] : NULL,
        'urldescription'        =>  isset($infoCampania['urldescription']) ? $infoCampania['urldescription'] : NULL,
        'url'                   =>  NULL,
        'urlopentype2'          =>  isset($infoCampania['urlopentype2']) ? $infoCampania['urlopentype2'] : NULL,
        'urldescription2'       =>  isset($infoCampania['urldescription2']) ? $infoCampania['urldescription2'] : NULL,
        'url2'                  =>  NULL,
        'urlopentype3'          =>  isset($infoCampania['urlopentype3']) ? $infoCampania['urlopentype3'] : NULL,
        'urldescription3'       =>  isset($infoCampania['urldescription3']) ? $infoCampania['urldescription3'] : NULL,
        'url3'                  =>  NULL,
    );

    if (isset($infoCampania['urltemplate']) && !is_null($infoCampania['urltemplate'])) {
        $registroCambio['url'] = construirUrlExterno($infoCampania['urltemplate'], $infoLlamada, $chanvars);
    }
    if (isset($infoCampania['urltemplate2']) && !is_null($infoCampania['urltemplate2'])) {
        $registroCambio['url2'] = construirUrlExterno($infoCampania['urltemplate2'], $infoLlamada, $chanvars);
    }
    if (isset($infoCampania['urltemplate3']) && !is_null($infoCampania['urltemplate3'])) {
        $registroCambio['url3'] = construirUrlExterno($infoCampania['urltemplate3'], $infoLlamada, $chanvars);
    }

    // URLs marked with a "_hangup" opentype are reserved for the agentunlinked
    // event and must not pop at call startup.
    foreach (array('', '2', '3') as $sfx) {
        if (_esOpentypeHangup($registroCambio["urlopentype$sfx"])) {
            $registroCambio["urlopentype$sfx"]    = NULL;
            $registroCambio["urldescription$sfx"] = NULL;
            $registroCambio["url$sfx"]            = NULL;
        }
    }

    // Asignaciones específicas para llamadas entrantes
    if ($callinfo['calltype'] == 'incoming') {
        $comboContactos = array();
        foreach ($infoLlamada['matching_contacts'] as $idContacto => $tuplaContacto) {
            $infoContactoViejo = array();
            $sDescripcionContacto = '';
            foreach ($tuplaContacto as $attrContacto) {
                $sDescripcionContacto .= $attrContacto['value'].' ';
                if (in_array($attrContacto['label'], array('first_name', 'last_name', 'cedula_ruc')))
                    $infoContactoViejo[$attrContacto['label']] = $attrContacto['value'];
            }
            if (count($infoContactoViejo) == 3) {
                $sDescripcionContacto = $infoContactoViejo['cedula_ruc'].
                    ' - '.$infoContactoViejo['first_name'].' '.$infoContactoViejo['last_name'];
            } else {
                /* TODO: dar formato adecuado para cuando contactos de llamadas
                 * entrantes puedan tener atributos arbitrarios */

            }

            /* El htmlentities de clave y valor es necesario porque del lado
             * Javascript, se usa concatenación directa de cadenas, porque el
             * objeto option devuelto por createElement no muestra la etiqueta
             * en IE6. Si se descubre la manera de hacerlo, hay que deshacer
             * el htmlentities aquí. */
            $comboContactos[htmlentities($idContacto, ENT_COMPAT, 'UTF-8')] =
                htmlentities($sDescripcionContacto, ENT_COMPAT, 'UTF-8');
        }
        if (count($comboContactos) == 0) {
            $comboContactos['x'] = htmlentities(_tr('(no matching contacts)'), ENT_COMPAT, 'UTF-8');
        }

        $registroCambio['lista_contactos'] = $comboContactos;
        $registroCambio['puede_confirmar_contacto'] = (count($comboContactos) > 1);
    }

    // Asignaciones específicas para llamadas salientes
    if ($callinfo['calltype'] == 'outgoing') {

        /* TODO: el siguiente código asume que el atributo 1 es el nombre
         * del cliente. Esta suposición se hereda del callcenter anterior.
         * Se debe de idear un método para dar formato al nombre del cliente
         * a partir de cualquier combinación de columnas */
        $sNombreCliente = isset($infoLlamada['call_attributes'][1])
            ? $infoLlamada['call_attributes'][1]['value']
            : _tr('(unavailable)');

        $registroCambio['txt_contacto_nombres'] = $sNombreCliente;
    }

    return $registroCambio;
}

function construirRespuesta_agentunlinked($smarty = NULL, $sDirLocalPlantillas = NULL,
    $oPaloConsola = NULL, $callinfo = NULL, $infoLlamada = NULL, &$infoCampania = NULL)
{
    $registroCambio = array('event' => 'agentunlinked');
    if (!is_array($infoCampania) || is_null($oPaloConsola) || !is_array($infoLlamada)) {
        return $registroCambio;
    }

    // The agentunlinked event uses field names call_type/call_id/phone, but
    // construirUrlExterno expects calltype/callid/callnumber. Merge the
    // normalized $callinfo (built by the caller with the proper key names)
    // into $infoLlamada so URL token substitution works.
    if (is_array($callinfo)) {
        foreach (array('calltype', 'campaign_id', 'callid', 'callnumber',
            'agent_number', 'remote_channel', 'uniqueid', 'datetime_linkstart') as $k) {
            if (!isset($infoLlamada[$k]) && isset($callinfo[$k]))
                $infoLlamada[$k] = $callinfo[$k];
        }
    }

    $chanvars = $oPaloConsola->leerVariablesCanalLlamadaActiva();
    foreach (array('', '2', '3') as $sfx) {
        $ot   = isset($infoCampania["urlopentype$sfx"])    ? $infoCampania["urlopentype$sfx"]    : NULL;
        $tpl  = isset($infoCampania["urltemplate$sfx"])    ? $infoCampania["urltemplate$sfx"]    : NULL;
        $desc = isset($infoCampania["urldescription$sfx"]) ? $infoCampania["urldescription$sfx"] : NULL;
        if (_esOpentypeHangup($ot) && !is_null($tpl)) {
            $registroCambio["urlopentype$sfx"]    = _opentypeBase($ot);
            $registroCambio["urldescription$sfx"] = $desc;
            $registroCambio["url$sfx"]            = construirUrlExterno($tpl, $infoLlamada, $chanvars);
        } else {
            $registroCambio["urlopentype$sfx"]    = NULL;
            $registroCambio["urldescription$sfx"] = NULL;
            $registroCambio["url$sfx"]            = NULL;
        }
    }
    return $registroCambio;
}

function construirRespuesta_waitingenter($oPaloConsola, $waitedcallinfo)
{
    $registroCambio = array(
        'event'             =>  'waitingenter',
        'urlopentype'       =>  NULL,
        'urldescription'    =>  NULL,
        'url'               =>  NULL,
        'urlopentype2'      =>  NULL,
        'urldescription2'   =>  NULL,
        'url2'              =>  NULL,
        'urlopentype3'      =>  NULL,
        'urldescription3'   =>  NULL,
        'url3'              =>  NULL,
        // Etiquetas a modificar en la interfaz
        //'txt_btn_hold' =>  _tr('End Hold'),
    );

    return $registroCambio;
}

function construirRespuesta_waitingexit()
{
    return array(
        'event'         =>  'waitingexit',

        // Etiquetas a modificar en la interfaz
        //'txt_btn_hold' =>  _tr('Hold'),
    );
}

function _esOpentypeHangup($opentype)
{
    return is_string($opentype) && substr($opentype, -7) === '_hangup';
}

function _opentypeBase($opentype)
{
    return _esOpentypeHangup($opentype) ? substr($opentype, 0, -7) : $opentype;
}

function construirUrlExterno($s, $infoLlamada, $chanvars)
{
    $reemplazos = array(
        '{__AGENT_NUMBER__}'    =>  (isset($infoLlamada['agent_number'])
                ? $infoLlamada['agent_number'] : ''),
        '{__REMOTE_CHANNEL__}'  =>  (isset($infoLlamada['remote_channel'])
                ? $infoLlamada['remote_channel'] : ''),
        '{__CALL_TYPE__}'       =>  isset($infoLlamada['calltype']) ? $infoLlamada['calltype'] : '',
        '{__CAMPAIGN_ID__}'     =>  isset($infoLlamada['campaign_id']) ? $infoLlamada['campaign_id'] : '',
        '{__CALL_ID__}'         =>  isset($infoLlamada['callid']) ? $infoLlamada['callid'] : '',
        '{__PHONE__}'           =>  isset($infoLlamada['callnumber']) ? $infoLlamada['callnumber'] : '',
        '{__UNIQUEID__}'        =>  isset($infoLlamada['uniqueid']) ? $infoLlamada['uniqueid'] : '',
        '{__ANSWER__}'          =>  isset($infoLlamada['datetime_linkstart']) ? $infoLlamada['datetime_linkstart'] : '',
        '{__END__}'             =>  isset($infoLlamada['datetime_linkend']) ? $infoLlamada['datetime_linkend'] : '',
        '{__DURATION__}'        =>  isset($infoLlamada['duration']) ? $infoLlamada['duration'] : '',
        '{__AGENT__}'           =>  isset($_SESSION['callcenter']['agente_nombre'])
                ? trim(substr($_SESSION['callcenter']['agente_nombre'], strpos($_SESSION['callcenter']['agente_nombre'], '-') + 1))
                : '',
    );
    if (is_array($chanvars)) foreach ($chanvars as $k => $v) {
    	$reemplazos['{'.$k.'}'] = $v;
    }
    if (isset($infoLlamada['call_attributes'])) foreach ($infoLlamada['call_attributes'] as $tupla) {
        $reemplazos['{'.$tupla['label'].'}'] = $tupla['value'];
    }
    foreach ($reemplazos as $k => $v) {
        $s = str_replace($k, urlencode($v), $s);
    }
    return $s;
}

// Shift-based time calculation functions for Agent Console
// EN: Calculate shift datetime range (default full day 00-23)
function agentConsole_calculateShiftDatetimeRange($fromHour = 0, $toHour = 23)
{
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $fromHour = max(0, min(23, (int)$fromHour));
    $toHour = max(0, min(23, (int)$toHour));

    if ($fromHour > $toHour) {
        $datetimeStart = $yesterday . ' ' . sprintf('%02d:00:00', $fromHour);
        $datetimeEnd = $today . ' ' . sprintf('%02d:59:59', $toHour);
    } else {
        $datetimeStart = $today . ' ' . sprintf('%02d:00:00', $fromHour);
        $datetimeEnd = $today . ' ' . sprintf('%02d:59:59', $toHour);
    }
    return array('start' => $datetimeStart, 'end' => $datetimeEnd);
}

// EN: Query cumulative break time per agent (Break-type pauses only)
function agentConsole_consultarTiempoBreakAgentes($datetimeStart = NULL, $datetimeEnd = NULL)
{
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

    if (is_null($datetimeStart) || is_null($datetimeEnd)) {
        $sToday = date('Y-m-d');
        $datetimeStart = "$sToday 00:00:00";
        $datetimeEnd = "$sToday 23:59:59";
    }

    $sql = "SELECT CONCAT(agent.type, '/', agent.number) AS agentchannel, " .
           "SUM(UNIX_TIMESTAMP(audit.datetime_end) - UNIX_TIMESTAMP(audit.datetime_init)) AS sec_breaks " .
           "FROM audit " .
           "INNER JOIN break ON break.id = audit.id_break " .
           "INNER JOIN agent ON agent.id = audit.id_agent " .
           "WHERE break.tipo = 'B' " .
           "AND audit.datetime_end IS NOT NULL " .
           "AND audit.datetime_init >= :start " .
           "AND audit.datetime_init <= :end " .
           "GROUP BY agent.id";
    $stmt = $pDB->prepare($sql);
    $stmt->execute(array(':start' => $datetimeStart, ':end' => $datetimeEnd));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result['breakTimes'][$row['agentchannel']] = (int)$row['sec_breaks'];
    }

    $sql2 = "SELECT name FROM break WHERE tipo = 'H' AND status = 'A'";
    $stmt2 = $pDB->query($sql2);
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $result['holdNames'][] = $row['name'];
    }

    $pDB = null;
    return $result;
}

// EN: Query cumulative hold time per agent (Hold-type pauses only)
function agentConsole_consultarTiempoHoldAgentes($datetimeStart = NULL, $datetimeEnd = NULL)
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

    $sql = "SELECT CONCAT(agent.type, '/', agent.number) AS agentchannel, " .
           "SUM(UNIX_TIMESTAMP(audit.datetime_end) - UNIX_TIMESTAMP(audit.datetime_init)) AS sec_holds " .
           "FROM audit " .
           "INNER JOIN break ON break.id = audit.id_break " .
           "INNER JOIN agent ON agent.id = audit.id_agent " .
           "WHERE break.tipo = 'H' " .
           "AND audit.datetime_end IS NOT NULL " .
           "AND audit.datetime_init >= :start " .
           "AND audit.datetime_init <= :end " .
           "GROUP BY agent.id";
    $stmt = $pDB->prepare($sql);
    $stmt->execute(array(':start' => $datetimeStart, ':end' => $datetimeEnd));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $result[$row['agentchannel']] = (int)$row['sec_holds'];
    }

    $pDB = null;
    return $result;
}

// EN: Query cumulative login time per agent
function agentConsole_consultarTiempoLoginAgentes($datetimeStart = NULL, $datetimeEnd = NULL)
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

    $sNow = date('Y-m-d H:i:s');
    $sActiveEnd = ($sNow < $datetimeEnd) ? $sNow : $datetimeEnd;

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

// EN: Format seconds as HH:MM:SS
function agentConsole_timestamp_format($i)
{
    return sprintf('%02d:%02d:%02d',
        ($i - ($i % 3600)) / 3600,
        (($i - ($i % 60)) / 60) % 60,
        $i % 60);
}

?>
<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
 Codificación: UTF-8
 Encoding: UTF-8
 +----------------------------------------------------------------------+
 | Issabel version 1.2-2                                                |
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

class SQLWorkerProcess extends TuberiaProcess
{
    private $DEBUG = FALSE; // VERDADERO si se activa la depuración
                            // TRUE if debugging is enabled

    private $_log;      // Log abierto por framework de demonio
                        // Log opened by daemon framework
    private $_dsn;      // Cadena que representa el DSN, estilo PDO
                        // String representing the DSN, PDO style
    private $_db;       // Conexión a la base de datos, PDO
                        // Database connection, PDO
    private $_configDB; // Objeto de configuración desde la base de datos
                        // Configuration object from database

    private $_iTimestampInicioProceso;

    // Contadores para actividades ejecutadas regularmente
    // Counters for regularly executed activities
    private $_iTimestampActualizacion = 0;          // Última actualización remota
                                                    // Last remote update
    private $_iTimestampUltimaRevisionConfig = 0;   // Última revisión de configuración
                                                    // Last configuration review

    /* Lista de acciones pendientes encargadas por otros procesos. Cada elemento
     * de este arreglo es una tupla cuyo primer elemento es callable y el segundo
     * elemento es la lista de parámetros con los que se debe invocar el callable.
     * Ya que todos los callables usan la base de datos, es posible que la
     * ejecución arroje excepciones PDOException. Todos los callables se invocan
     * dentro de una transacción de la base de datos, la cual se hará commit()
     * en caso de que no se arrojen excepciones. De lo contrario, y si la conexión
     * sigue siendo válida, se realizará un rollback() y se reintentará la operación
     * en un momento posterior. Todos los callables deben de devolver un arreglo
     * que contiene los eventos a ser lanzados como resultado de haber completado
     * las operaciones correspondientes.
     *
     * List of pending actions requested by other processes. Each element of this
     * array is a tuple where the first element is callable and the second element
     * is the list of parameters with which the callable must be invoked. Since all
     * callables use the database, execution may throw PDOException exceptions.
     * All callables are invoked within a database transaction, which will be
     * committed if no exceptions are thrown. Otherwise, and if the connection
     * remains valid, a rollback will be performed and the operation will be
     * retried at a later time. All callables must return an array containing the
     * events to be launched as a result of having completed the corresponding
     * operations.
     */
    private $_accionesPendientes = array();

    private $_finalizandoPrograma = FALSE;

    /* Estado de reintento de la acción que encabeza la cola. Garantiza que una
     * acción que jamás podrá completarse no bloquee la cola de forma permanente
     * (head-of-line blocking) ni genere un ciclo de reintento sin pausa. Sin este
     * control, un solo fallo permanente impide que se emitan los eventos ECCP y las
     * consolas de agente dejan de actualizarse.
     *
     * Retry state for the action at the head of the queue. Guarantees that an action
     * that can never complete does not block the queue permanently (head-of-line
     * blocking) nor generate a retry cycle without pause. Without this control, a
     * single permanent failure prevents ECCP events from being emitted and the agent
     * consoles stop updating. */
    const MAX_ACTION_RETRIES = 50;          // Reintentos antes de descartar | Retries before discarding
    const BACKOFF_CAP_SECONDS = 30;         // Tope de la espera creciente | Cap of the increasing wait
    const LOG_REPEAT_WINDOW_SECONDS = 60;   // Ventana anti-inundación de log | Log anti-flood window
    const FAST_RETRIES_ON_CONTENTION = 5;   // Reintentos inmediatos ante contención | Immediate retries on contention

    private $_iReintentosAccion = 0;    // Reintentos de la acción actual | Retries of the current action
    private $_tsProximoReintento = 0;   // Instante del próximo intento | Timestamp of the next attempt
    private $_sUltimoErrorFirma = NULL; // Firma del último error registrado | Signature of last logged error
    private $_tsUltimoErrorLog = 0;     // Instante del último registro | Timestamp of the last log entry
    private $_iErroresSuprimidos = 0;   // Errores idénticos suprimidos | Identical errors suppressed

    public function inicioPostDemonio($infoConfig, &$oMainLog)
    {
    	$this->_log = $oMainLog;
        $this->_multiplex = new MultiplexServer(NULL, $this->_log);
        $this->_tuberia->registrarMultiplexHijo($this->_multiplex);
        $this->_tuberia->setLog($this->_log);

        $this->_iTimestampInicioProceso = time();

        // Interpretar la configuración del demonio
        // Interpret daemon configuration
        $this->_dsn = $this->_interpretarConfiguracion($infoConfig);
        if (!$this->_iniciarConexionDB()) return FALSE;

        // Leer el resto de la configuración desde la base de datos
        // Read the rest of the configuration from the database
        try {
            $this->_configDB = new ConfigDB($this->_db, $this->_log);
        } catch (PDOException $e) {
            $this->_log->output("FATAL: no se puede leer configuración DB - ".$e->getMessage()." | EN: cannot read DB configuration - ".$e->getMessage());
            return FALSE;
        }

        $this->_repararAuditoriasIncompletas();

        // Registro de manejadores de eventos desde AMIEventProcess
        // Registration of event handlers from AMIEventProcess
        foreach (array('sqlinsertcalls', 'sqlupdatecalls',
            'sqlinsertcurrentcalls', 'sqldeletecurrentcalls',
            'sqlupdatecurrentcalls', 'sqlupdatestatcampaign', 'finalsql',
            'verificarFinLlamadasAgendables', 'agregarArchivoGrabacion',
            'AgentLogin', 'AgentLogoff', 'AgentLinked', 'AgentUnlinked',
            'marcarFinalHold', 'nuevaMembresiaCola', 'notificarProgresoLlamada',
            'requerir_credencialesAsterisk', 'AgentStateChange',) as $k)
            $this->_tuberia->registrarManejador('AMIEventProcess', $k, array($this, "msg_$k"));

        // Registro de manejadores de eventos desde ECCPWorkerProcess
        // Registration of event handlers from ECCPWorkerProcess
        foreach (array('requerir_nuevaListaAgentes') as $k)
            $this->_tuberia->registrarManejador('*', $k, array($this, "msg_$k"));

        // Registro de manejadores de eventos desde HubProcess
        // Registration of event handlers from HubProcess
        $this->_tuberia->registrarManejador('HubProcess', 'finalizando', array($this, "msg_finalizando"));

        $this->DEBUG = $this->_configDB->dialer_debug;

        // Informar a AMIEventProcess la configuración de Asterisk
        // Inform AMIEventProcess of Asterisk configuration
        $this->_informarCredencialesAsterisk(FALSE);

        return TRUE;
    }

    private function _informarCredencialesAsterisk($por_pedido)
    {
        $this->_tuberia->AMIEventProcess_informarCredencialesAsterisk(array(
            'asterisk'  =>  array(
                'asthost'           =>  $this->_configDB->asterisk_asthost,
                'astuser'           =>  $this->_configDB->asterisk_astuser,
                'astpass'           =>  $this->_configDB->asterisk_astpass,
                'duracion_sesion'   =>  $this->_configDB->asterisk_duracion_sesion,
            ),
            'dialer'    =>  array(
                'llamada_corta'     =>  $this->_configDB->dialer_llamada_corta,
                'tiempo_contestar'  =>  $this->_configDB->dialer_tiempo_contestar,
                'debug'             =>  $this->_configDB->dialer_debug,
                'allevents'         =>  $this->_configDB->dialer_allevents,
                'relatedevents'     =>  $this->_configDB->dialer_relatedevents,
            ),
        ), $por_pedido);
    }

    private function _interpretarConfiguracion($infoConfig)
    {
        $dbHost = 'localhost';
        $dbUser = 'asterisk';
        $dbPass = 'asterisk';
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbhost'])) {
            $dbHost = $infoConfig['database']['dbhost'];
            $this->_log->output('Usando host de base de datos: '.$dbHost.' | EN: Using database host: '.$dbHost);
        } else {
            $this->_log->output('Usando host (por omisión) de base de datos: '.$dbHost.' | EN: Using (default) database host: '.$dbHost);
        }
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbuser']))
            $dbUser = $infoConfig['database']['dbuser'];
        if (isset($infoConfig['database']) && isset($infoConfig['database']['dbpass']))
            $dbPass = $infoConfig['database']['dbpass'];

        return array("mysql:host=$dbHost;dbname=call_center;charset=utf8mb4", $dbUser, $dbPass);
    }

    private function _iniciarConexionDB()
    {
        try {
            $this->_db = new PDO($this->_dsn[0], $this->_dsn[1], $this->_dsn[2]);
            $this->_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->_db->setAttribute(PDO::ATTR_EMULATE_PREPARES, FALSE);
            return TRUE;
        } catch (PDOException $e) {
            $this->_db = NULL;
            $this->_log->output("FATAL: no se puede conectar a DB - ".$e->getMessage()." | EN: cannot connect to DB - ".$e->getMessage());
            return FALSE;
        }
    }

    public function procedimientoDemonio()
    {
        // Lo siguiente NO debe de iniciar operaciones DB, sólo acumular acciones
        // The following must NOT initiate DB operations, only accumulate actions
        $bPaqProcesados = $this->_multiplex->procesarPaquetes();
        /* Si la acción que encabeza la cola está esperando su reintento, el proceso
         * debe quedar ocioso en lugar de girar sin pausa consumiendo CPU y llenando
         * el log.
         *
         * If the action at the head of the queue is waiting for its retry, the process
         * must go idle instead of spinning without pause, consuming CPU and filling
         * up the log. */
        $this->_multiplex->procesarActividad(($bPaqProcesados || $this->_hayAccionEjecutable()) ? 0 : 1);

        // Verificar posible desconexión de la base de datos
        // Verify possible database disconnection
        if (is_null($this->_db)) {
            if (count($this->_accionesPendientes) > 0) {
                $this->_log->output('INFO: falta conexión DB y hay '.count($this->_accionesPendientes).' acciones pendientes. | EN: DB connection missing and there are '.count($this->_accionesPendientes).' pending actions.');
                if ($this->DEBUG) {
                    foreach ($this->_accionesPendientes as $accion)
                        $this->_volcarAccion($accion);
                }
            }
            $this->_log->output('INFO: intentando volver a abrir conexión a DB... | EN: trying to reopen DB connection...');
            if (!$this->_iniciarConexionDB()) {
                $this->_log->output('ERR: no se puede restaurar conexión a DB, se espera... | EN: cannot restore DB connection, waiting...');

                $t1 = time();
                do {
                    $this->_multiplex->procesarPaquetes();
                    $this->_multiplex->procesarActividad(1);
                } while (time() - $t1 < 5);
            } else {
                $this->_log->output('INFO: conexión a DB restaurada, se reinicia operación normal. | EN: DB connection restored, resuming normal operation.');
                $this->_configDB->setDBConn($this->_db);
            }
        } else {
            $this->_procesarUnaAccion();
        }

        return TRUE;
    }

    /* $bIgnorarBackoff fuerza el intento aunque la acción esté en espera de
     * reintento. Lo usa limpiezaDemonio(), que dispone de un plazo acotado para
     * vaciar la cola y no debe desperdiciarlo esperando.
     *
     * $bIgnorarBackoff forces the attempt even if the action is waiting for a retry.
     * It is used by limpiezaDemonio(), which has a bounded window to drain the queue
     * and must not waste it waiting. */
    private function _procesarUnaAccion($bIgnorarBackoff = FALSE)
    {
        /* Distingue si la excepción proviene de la acción encolada o de las
         * verificaciones periódicas previas, que no forman parte de ella.
         *
         * Distinguishes whether the exception comes from the queued action or from
         * the preceding periodic checks, which are not part of it. */
        $bEjecutandoAccion = FALSE;
        try {
            if (!$this->_finalizandoPrograma) {
                // Verificar si se ha cambiado la configuración
                // Check if configuration has changed
                $this->_verificarCambioConfiguracion();

                // Verificar si hay que refrescar agentes disponibles
                // Check if available agents need to be refreshed
                $this->_verificarActualizacionAgentes();
            }

            /* Por ahora se intenta ejecutar todas las operaciones, incluso
             * si se intenta finalizar el programa.
             *
             * For now, all operations are attempted to be executed, even if
             * the program is being finalized. */
            if (count($this->_accionesPendientes) > 0 &&
                ($bIgnorarBackoff || $this->_hayAccionEjecutable())) {
                if ($this->DEBUG) {
                    $this->_volcarAccion($this->_accionesPendientes[0]);
                }

                $t_1 = microtime(TRUE);
                $bEjecutandoAccion = TRUE;
                $this->_db->beginTransaction();

                $eventos = call_user_func_array(
                    $this->_accionesPendientes[0][0],
                    $this->_accionesPendientes[0][1]);

                /* El commit también puede arrojar excepción. Se debe pasar más
                 * allá del commit antes de quitar la acción pendiente y lanzar
                 * los eventos.
                 *
                 * The commit can also throw exceptions. Must go past the commit
                 * before removing the pending action and launching the events. */
                $this->_db->commit();
                $bEjecutandoAccion = FALSE;
                $t_2 = microtime(TRUE);
                if ($this->DEBUG) {
                    $this->_log->output('DEBUG: '.__METHOD__.' acción ejecutada correctamente. | EN: action executed correctly.');
                }
                if ($this->DEBUG || ($t_2 - $t_1 >= 1.0)) {
                    $this->_log->output('DEBUG: '.__METHOD__.' acción '.
                        $this->_accionesPendientes[0][0][1].' tomó '.
                        sprintf('%.2f s.', $t_2 - $t_1).' | EN: action '.$this->_accionesPendientes[0][0][1].' took '.sprintf('%.2f s.', $t_2 - $t_1));
                }

                array_shift($this->_accionesPendientes);
                $this->_reiniciarEstadoReintento();
                $this->_lanzarEventos($eventos);
            }
        } catch (PDOException $e) {
            $this->_manejarErrorAccion($e, $bEjecutandoAccion);
        }
    }

    /* Clasifica el fallo de base de datos y decide el destino de la acción encolada:
     *
     *   1. Pérdida de conexión  -> se conserva la acción y se cierra la conexión.
     *   2. Contención transitoria (interbloqueo, timeout de lock) -> reintento inmediato.
     *   3. Fallo lógico permanente -> se descarta la acción; reintentar es inútil.
     *   4. Cualquier otro error  -> reintento con espera creciente y descarte tras
     *      MAX_ACTION_RETRIES, de modo que ningún error imprevisto pueda bloquear la
     *      cola de forma permanente.
     *
     * Classifies the database failure and decides the fate of the queued action:
     *
     *   1. Connection loss       -> the action is retained and the connection closed.
     *   2. Transient contention (deadlock, lock timeout) -> immediate retry.
     *   3. Permanent logical failure -> the action is discarded; retrying is useless.
     *   4. Any other error       -> retry with increasing backoff and discard after
     *      MAX_ACTION_RETRIES, so that no unforeseen error can block the queue
     *      permanently. */
    private function _manejarErrorAccion(PDOException $e, $bEjecutandoAccion)
    {
        $info = infoErrorPDO($e);
        $sError = implode(' - ', $info);

        /* Categoría 1: la conexión ya no sirve. La acción DEBE conservarse; se cierra
         * la conexión para que procedimientoDemonio() la restablezca y reintente.
         *
         * Category 1: the connection is no longer usable. The action MUST be retained;
         * the connection is closed so procedimientoDemonio() restores it and retries. */
        if (esErrorConexion($e)) {
            $this->_log->output('WARN: '.__METHOD__.
                ': conexión a DB perdida ('.$sError.'), se conservan '.
                count($this->_accionesPendientes).' acciones pendientes... | EN: DB connection lost ('.
                $sError.'), retaining '.count($this->_accionesPendientes).' pending actions...');
            $this->_db = NULL;
            return;
        }

        /* rollBack() arroja excepción si no hay transacción activa, lo que ocurre
         * cuando el fallo proviene de una lectura hecha fuera de transacción. Sin
         * esta guarda, esa segunda excepción escaparía del catch y abortaría el
         * proceso.
         *
         * rollBack() throws an exception if there is no active transaction, which
         * happens when the failure comes from a read performed outside a transaction.
         * Without this guard, that second exception would escape the catch and abort
         * the process. */
        if (!is_null($this->_db)) {
            try {
                if ($this->_db->inTransaction()) $this->_db->rollBack();
            } catch (PDOException $e2) {
                $this->_log->output('WARN: '.__METHOD__.
                    ': fallo al deshacer la transacción, se cierra la conexión: '.$e2->getMessage().
                    ' | EN: failed to roll back the transaction, closing the connection: '.$e2->getMessage());
                $this->_db = NULL;
                return;
            }
        }

        /* El fallo puede provenir de _verificarCambioConfiguracion() o de
         * _verificarActualizacionAgentes(). En ese caso la acción encolada es inocente
         * y descartarla perdería datos que nunca se llegaron a intentar.
         *
         * The failure may come from _verificarCambioConfiguracion() or
         * _verificarActualizacionAgentes(). In that case the queued action is innocent
         * and discarding it would lose data that was never even attempted. */
        if (!$bEjecutandoAccion) {
            if ($this->_debeRegistrarError('cfg-'.$info[0].'-'.$info[1])) {
                $this->_log->output('ERR: '.__METHOD__.
                    ': fallo de base de datos fuera de la acción encolada: '.$sError.
                    ' | EN: database failure outside the queued action: '.$sError);
            }
            return;
        }

        /* Categoría 3: el error nunca podrá resolverse solo. Se registra una vez, junto
         * con los datos que no pudieron persistirse, y se descarta para desbloquear la
         * cola.
         *
         * Category 3: the error can never resolve by itself. It is logged once, along
         * with the data that could not be persisted, and discarded to unblock the
         * queue. */
        if (esErrorPermanente($e)) {
            $accion = array_shift($this->_accionesPendientes);
            $this->_reiniciarEstadoReintento();
            $this->_log->output('ERR: '.__METHOD__.
                ': acción descartada permanentemente, el error nunca podrá resolverse: '.$sError.
                ' | EN: action permanently discarded, the error can never be resolved: '.$sError);
            $this->_volcarAccion($accion, TRUE);
            return;
        }

        // Categorías 2 y 4: se conserva la acción y se reintenta.
        // Categories 2 and 4: the action is retained and retried.
        $this->_iReintentosAccion++;
        if ($this->_iReintentosAccion >= self::MAX_ACTION_RETRIES) {
            $accion = array_shift($this->_accionesPendientes);
            $this->_reiniciarEstadoReintento();
            $this->_log->output('ERR: '.__METHOD__.
                ': acción descartada tras '.self::MAX_ACTION_RETRIES.' reintentos fallidos: '.$sError.
                ' | EN: action discarded after '.self::MAX_ACTION_RETRIES.' failed retries: '.$sError);
            $this->_volcarAccion($accion, TRUE);
            return;
        }

        if (esReiniciable($e)) {
            /* Los interbloqueos se resuelven en milisegundos: los primeros intentos se
             * repiten de inmediato y sin registrar, para no ensuciar el log con un
             * evento normal. Si la contención persiste se aplica la misma espera
             * creciente, de modo que el tope de reintentos abarque un lapso útil en
             * lugar de agotarse en microsegundos.
             *
             * Deadlocks resolve within milliseconds: the first attempts are repeated
             * immediately and without logging, so as not to pollute the log with a
             * normal event. If the contention persists, the same increasing wait is
             * applied, so that the retry cap spans a useful period instead of being
             * exhausted within microseconds. */
            $this->_tsProximoReintento = ($this->_iReintentosAccion <= self::FAST_RETRIES_ON_CONTENTION)
                ? 0
                : time() + min(pow(2, $this->_iReintentosAccion - self::FAST_RETRIES_ON_CONTENTION),
                               self::BACKOFF_CAP_SECONDS);
            if ($this->DEBUG) {
                $this->_log->output('DEBUG: '.__METHOD__.
                    ': contención transitoria, reintento '.$this->_iReintentosAccion.': '.$sError.
                    ' | EN: transient contention, retry '.$this->_iReintentosAccion.': '.$sError);
            }
            return;
        }

        $iEspera = min(pow(2, min($this->_iReintentosAccion, 10)), self::BACKOFF_CAP_SECONDS);
        $this->_tsProximoReintento = time() + $iEspera;
        if ($this->_debeRegistrarError($info[0].'-'.$info[1])) {
            $this->_log->output('ERR: '.__METHOD__.
                ': no se puede realizar la operación de base de datos (reintento '.
                $this->_iReintentosAccion.' de '.self::MAX_ACTION_RETRIES.', espera '.$iEspera.' s): '.$sError.
                ' | EN: cannot perform the database operation (retry '.
                $this->_iReintentosAccion.' of '.self::MAX_ACTION_RETRIES.', waiting '.$iEspera.' s): '.$sError);
            $this->_log->output('ERR: traza de pila | EN: stack trace: '."\n".$e->getTraceAsString());
        }
    }

    /* Indica si hay una acción lista para ejecutarse: la cola no está vacía y la
     * acción que la encabeza ya cumplió su espera de reintento.
     *
     * Indicates whether there is an action ready to run: the queue is not empty and
     * the action heading it has already served its retry wait. */
    private function _hayAccionEjecutable()
    {
        return (count($this->_accionesPendientes) > 0 &&
                time() >= $this->_tsProximoReintento);
    }

    private function _reiniciarEstadoReintento()
    {
        $this->_iReintentosAccion = 0;
        $this->_tsProximoReintento = 0;
    }

    /* Evita que un error repetido inunde el log. Registra la primera aparición y
     * luego, a lo sumo, una vez por ventana, informando cuántas se suprimieron.
     *
     * Prevents a repeated error from flooding the log. Logs the first occurrence and
     * then at most once per window, reporting how many were suppressed. */
    private function _debeRegistrarError($sFirma)
    {
        $iAhora = time();
        if ($sFirma === $this->_sUltimoErrorFirma &&
            $iAhora - $this->_tsUltimoErrorLog < self::LOG_REPEAT_WINDOW_SECONDS) {
            $this->_iErroresSuprimidos++;
            return FALSE;
        }
        if ($this->_iErroresSuprimidos > 0) {
            $this->_log->output('WARN: se suprimieron '.$this->_iErroresSuprimidos.
                ' errores idénticos en los últimos '.self::LOG_REPEAT_WINDOW_SECONDS.
                ' s | EN: suppressed '.$this->_iErroresSuprimidos.
                ' identical errors in the last '.self::LOG_REPEAT_WINDOW_SECONDS.' s');
            $this->_iErroresSuprimidos = 0;
        }
        $this->_sUltimoErrorFirma = $sFirma;
        $this->_tsUltimoErrorLog = $iAhora;
        return TRUE;
    }

    /* $bForzar registra la acción aunque la depuración esté desactivada. Se usa al
     * descartar una acción, para no perder el dato que no pudo persistirse.
     *
     * $bForzar logs the action even if debugging is disabled. It is used when
     * discarding an action, so as not to lose the data that could not be persisted. */
    private function _volcarAccion($accion, $bForzar = FALSE)
    {
        if (!$this->DEBUG && !$bForzar) return;
        /* array_shift() devuelve NULL si la cola estaba vacía. No debería ocurrir en
         * el flujo normal, pero se comprueba para no emitir un aviso de PHP.
         *
         * array_shift() returns NULL if the queue was empty. This should not happen in
         * the normal flow, but it is checked so as not to emit a PHP notice. */
        if (!is_array($accion)) return;
        $this->_log->output('DEBUG: acción pendiente '.$accion[0][1].': '.print_r($accion[1], TRUE).' | EN: pending action '.$accion[0][1].': ');
    }

    private function _lanzarEventos(&$eventos)
    {
        foreach ($eventos as $ev) {
            list($target, $msg, $args) = $ev;
            call_user_func_array(
                array($this->_tuberia, 'msg_'.$target.'_'.$msg),
                $args);
        }
    }

    public function limpiezaDemonio($signum)
    {
        // Mandar a cerrar todas las conexiones activas
        // Order to close all active connections
        $this->_multiplex->finalizarServidor();

        // Se intentan evacuar acciones pendientes
        // Attempt to evacuate pending actions
        if (count($this->_accionesPendientes) > 0)
            $this->_log->output('WARN: todavía hay '.count($this->_accionesPendientes).' acciones pendientes. | EN: there are still '.count($this->_accionesPendientes).' pending actions.');
        $t1 = time();
        while (time() - $t1 < 10 && !is_null($this->_db) &&
            count($this->_accionesPendientes) > 0) {
            /* Se ignora la espera de reintento: el cierre dispone de 10 s para vaciar
             * la cola y no debe desperdiciarlos esperando.
             *
             * The retry wait is ignored: shutdown has 10 s to drain the queue and must
             * not waste them waiting. */
            $this->_procesarUnaAccion(TRUE);

            // No se hace I/O y por lo tanto no se lanzan eventos
            // No I/O is done and therefore no events are launched
        }
        if (count($this->_accionesPendientes) > 0)
            $this->_log->output('ERR: no se pueden evacuar las siguientes acciones: '.
                print_r($this->_accionesPendientes, TRUE).' | EN: cannot evacuate the following actions: '.
                print_r($this->_accionesPendientes, TRUE));

        // Desconectarse de la base de datos
        // Disconnect from database
        $this->_configDB = NULL;
        if (!is_null($this->_db)) {
            $this->_log->output('INFO: desconectando de la base de datos... | EN: disconnecting from database...');
            $this->_db = NULL;
        }
    }

    private function _verificarCambioConfiguracion()
    {
        $iTimestamp = time();
        if ($iTimestamp - $this->_iTimestampUltimaRevisionConfig > 3) {
            $this->_configDB->leerConfiguracionDesdeDB();
            $listaVarCambiadas = $this->_configDB->listaVarCambiadas();
            if (count($listaVarCambiadas) > 0) {
                foreach ($listaVarCambiadas as $k) {
                    if (in_array($k, array('asterisk_asthost', 'asterisk_astuser', 'asterisk_astpass'))) {
                        $this->_tuberia->msg_AMIEventProcess_actualizarConfig(
                            'asterisk_cred', array(
                                $this->_configDB->asterisk_asthost,
                                $this->_configDB->asterisk_astuser,
                                $this->_configDB->asterisk_astpass,
                            ));
                    } elseif (in_array($k, array('asterisk_duracion_sesion',
                        'dialer_llamada_corta', 'dialer_tiempo_contestar',
                        'dialer_debug', 'dialer_allevents', 'dialer_relatedevents'))) {
                        $this->_tuberia->msg_AMIEventProcess_actualizarConfig(
                            $k, $this->_configDB->$k);
                    }

                    if (in_array($k, array('dialer_debug'))) {
                        $this->_tuberia->msg_ECCPProcess_actualizarConfig(
                            $k, $this->_configDB->$k);
                    }
                }

                if (in_array('dialer_debug', $listaVarCambiadas))
                    $this->DEBUG = $this->_configDB->dialer_debug;
                $this->_configDB->limpiarCambios();
            }
            $this->_iTimestampUltimaRevisionConfig = $iTimestamp;
        }
    }

    /* Mandar a los otros procedimientos la información que no pueden leer
     * directamente porque no tienen conexión de base de datos.
     *
     * Send to other processes the information they cannot read directly
     * because they don't have database connection. */
    private function _verificarActualizacionAgentes()
    {
        $iTimestamp = time();
        if ($iTimestamp - $this->_iTimestampActualizacion >= 5 * 60) {
            $this->_actualizarInformacionRemota_agentes();

            $this->_iTimestampActualizacion = $iTimestamp;
        }
    }

    function _actualizarInformacionRemota_agentes()
    {
        $eventos = $this->_requerir_nuevaListaAgentes();
        $this->_lanzarEventos($eventos);
    }

    /**************************************************************************/

    private function _encolarAccionPendiente($method, $params)
    {
        array_push($this->_accionesPendientes, array(
            array($this, $method),    // callable
            $params,    // params
        ));

    }

    public function msg_requerir_nuevaListaAgentes($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        $this->_log->output("INFO: $sFuente requiere refresco de lista de agentes | EN: $sFuente requires refresh of agent list");
        $this->_encolarAccionPendiente('_requerir_nuevaListaAgentes', $datos);
    }

    public function msg_requerir_credencialesAsterisk($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        $this->_log->output("INFO: $sFuente requiere envío de credenciales Asterisk | EN: $sFuente requires sending Asterisk credentials");
        $this->_informarCredencialesAsterisk(TRUE);
    }

    public function msg_sqlinsertcalls($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_sqlinsertcalls', $datos);
    }

    public function msg_sqlupdatecalls($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_sqlupdatecalls', $datos);
    }

    public function msg_sqlupdatecurrentcalls($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_sqlupdatecurrentcalls', $datos);
    }

    public function msg_sqlinsertcurrentcalls($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_sqlinsertcurrentcalls', $datos);
    }

    public function msg_sqldeletecurrentcalls($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_sqldeletecurrentcalls', $datos);
    }

    public function msg_sqlupdatestatcampaign($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_sqlupdatestatcampaign', $datos);
    }

    public function msg_agregarArchivoGrabacion($sFuente, $sDestino,
        $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_agregarArchivoGrabacion', $datos);
    }

    public function msg_AgentLogin($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_AgentLogin', $datos);
    }

    public function msg_AgentLogoff($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_AgentLogoff', $datos);
    }

    public function msg_AgentLinked($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_AgentLinked', $datos);
    }

    public function msg_AgentUnlinked($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_AgentUnlinked', $datos);
    }

    public function msg_marcarFinalHold($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_marcarFinalHold', $datos);
    }

    public function msg_nuevaMembresiaCola($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_nuevaMembresiaCola', $datos);
    }

    public function msg_AgentStateChange($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_agentStateChange', $datos);
    }

    public function msg_notificarProgresoLlamada($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.' - datos/data: '.print_r($datos, 1));
        }
        $this->_encolarAccionPendiente('_notificarProgresoLlamada', $datos);
    }

    public function msg_finalizando($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        $this->_log->output('INFO: recibido mensaje de finalización... | EN: received shutdown message...');
        $this->_finalizandoPrograma = TRUE;
    }

    public function msg_finalsql($sFuente, $sDestino, $sNombreMensaje, $iTimestamp, $datos)
    {
        if (!$this->_finalizandoPrograma) {
            $this->_log->output('WARN: AMIEventProcess envió mensaje antes que HubProcess | EN: AMIEventProcess sent message before HubProcess');
        }
        $this->_finalizandoPrograma = TRUE;
        $this->_tuberia->msg_HubProcess_finalizacionTerminada();
    }

    /**************************************************************************/

    // Mandar a AMIEventProcess una lista actualizada de los agentes activos
    // Send to AMIEventProcess an updated list of active agents
    private function _requerir_nuevaListaAgentes()
    {
        // El ORDER BY del query garantiza que estatus A aparece antes que I
        // The query's ORDER BY guarantees that status A appears before I
        $recordset = $this->_db->query(
            'SELECT id, number, name, estatus, type FROM agent ORDER BY number, estatus');
        $lista = array(); $listaNum = array();
        foreach ($recordset as $tupla) {
            if (!in_array($tupla['number'], $listaNum)) {
                $lista[] = array(
                    'id'        =>  $tupla['id'],
                    'number'    =>  $tupla['number'],
                    'name'      =>  $tupla['name'],
                    'estatus'   =>  $tupla['estatus'],
                    'type'      =>  $tupla['type'],
                );
                $listaNum[] = $tupla['number'];
            }
        }

        /* Leer el estado de las banderas de activación de eventos de las colas
         * a partir del archivo de configuración. El código a continuación
         * depende de la existencia de queues_additional.conf de una instalación
         * FreePBX, y además asume Asterisk 11 o inferior. Se debe modificar
         * esto cuando se migre a una versión superior de Asterisk que siempre
         * emite los eventos.
         *
         * Read the status of queue event activation flags from the configuration
         * file. The code below depends on the existence of queues_additional.conf
         * from a FreePBX installation, and also assumes Asterisk 11 or lower.
         * This must be modified when migrating to a higher Asterisk version that
         * always emits the events. */
        $queueflags = array();
        if (file_exists('/etc/asterisk/queues_additional.conf')) {
            $queue = NULL;
            foreach (file('/etc/asterisk/queues_additional.conf') as $s) {
                $regs = NULL;
                if (preg_match('/^\[(\S+)\]/', $s, $regs)) {
                    $queue = $regs[1];
                    $queueflags[$queue]['eventmemberstatus'] = FALSE;
                    $queueflags[$queue]['eventwhencalled'] = FALSE;
                } elseif (preg_match('/^(\w+)\s*=\s*(.*)/', trim($s), $regs)) {
                    if (in_array($regs[1], array('eventmemberstatus', 'eventwhencalled'))) {
                        $queueflags[$queue][$regs[1]] = in_array($regs[2], array('yes', 'true', 'y', 't', 'on', '1'));
                    }
                }
            }
        }

        // Mandar el recordset a AMIEventProcess como un mensaje
        // Send the recordset to AMIEventProcess as a message
        return array(
            array('AMIEventProcess', 'nuevaListaAgentes', array($lista, $queueflags)),
        );
    }

    private function _sqlinsertcalls($paramInsertar)
    {
        $eventos = array();

        // Porción que identifica la tabla a modificar
        // Portion that identifies the table to modify
        $tipo_llamada = $paramInsertar['tipo_llamada'];
        unset($paramInsertar['tipo_llamada']);
        switch ($tipo_llamada) {
        case 'outgoing':
            $sqlTabla = 'INSERT INTO calls ';
            break;
        case 'incoming':
            $sqlTabla = 'INSERT INTO call_entry ';
            break;
        default:
            $this->_log->output('ERR: '.__METHOD__.' no debió haberse recibido para '.
                print_r($paramInsertar, TRUE).' | EN: should not have been received for ');
            return $eventos;
        }

        // Caso especial: llamada entrante requiere ID de contacto
        // Special case: incoming call requires contact ID
        if ($tipo_llamada == 'incoming') {
            /* Se consulta el posible contacto en base al caller-id. Si hay
             * exactamente un contacto, su ID se usa para la inserción.
             *
             * The possible contact is queried based on caller-id. If there is
             * exactly one contact, its ID is used for insertion. */
            $recordset = $this->_db->prepare('SELECT id FROM contact WHERE telefono = ?');

            $recordset->execute(array($paramInsertar['callerid']));
            $listaIdContactos = $recordset->fetchAll(PDO::FETCH_COLUMN, 0);
            if (count($listaIdContactos) == 1) {
                $paramInsertar['id_contact'] = $listaIdContactos[0];
            }
        }

        $sqlCampos = array();
        $params = array();
        foreach ($paramInsertar as $k => $v) {
            $sqlCampos[] = $k;
            $params[] = $v;
        }
        $sql = $sqlTabla.'('.implode(', ', $sqlCampos).') VALUES ('.
            implode(', ', array_fill(0, count($params), '?')).')';

        $sth = $this->_db->prepare($sql);
        $sth->execute($params);
        $idCall = $this->_db->lastInsertId();

        // Mandar de vuelta el ID de inserción a AMIEventProcess
        // Send back the insertion ID to AMIEventProcess
        $eventos[] = array('AMIEventProcess', 'idnewcall',
            array($tipo_llamada, $paramInsertar['uniqueid'], $idCall,
                isset($paramInsertar['id_queue_call_entry'])
                    ? $paramInsertar['id_queue_call_entry'] : NULL));

        // Para llamada entrante se debe de insertar el log de progreso
        // For incoming call, progress log must be inserted
        if ($tipo_llamada == 'incoming') {
            // Notificar el progreso de la llamada
            // Notify call progress
            $infoProgreso = array(
                'datetime_entry'        =>  $paramInsertar['datetime_entry_queue'],
                'new_status'            =>  'OnQueue',
                'id_campaign_incoming'  =>  $paramInsertar['id_campaign'],
                'id_call_incoming'      =>  $idCall,
                'uniqueid'              =>  $paramInsertar['uniqueid'],
                'trunk'                 =>  $paramInsertar['trunk'],
            );

            list($id_campaignlog, $eventos_forward) = $this->_construirEventoProgresoLlamada($infoProgreso);
            $eventos[] = array('ECCPProcess', 'emitirEventos',
                array($eventos_forward));
        }

        return $eventos;
    }

    // Procedimiento que actualiza una sola llamada de la tabla calls o call_entry
    // Procedure that updates a single call from the calls or call_entry table
    private function _sqlupdatecalls($paramActualizar)
    {
        $eventos = array();

        $sql_list = array();
        $id_llamada = NULL;

        // Porción que identifica la tabla a modificar
        // Portion that identifies the table to modify
        $tipo_llamada = $paramActualizar['tipo_llamada'];
        unset($paramActualizar['tipo_llamada']);
        switch ($tipo_llamada) {
        case 'outgoing':
            $sqlTabla = 'UPDATE calls SET ';
            break;
        case 'incoming':
            $sqlTabla = 'UPDATE call_entry SET ';
            break;
        default:
            $this->_log->output('ERR: '.__METHOD__.' no debió haberse recibido para '.
                print_r($paramActualizar, TRUE).' | EN: should not have been received for ');
            return $eventos;
        }

        // Porción que identifica la tupla a modificar
        // Portion that identifies the tuple to modify
        $sqlWhere = array();
        $paramWhere = array();
        if (isset($paramActualizar['id_campaign'])) {
            if (!is_null($paramActualizar['id_campaign'])) {
                $sqlWhere[] = 'id_campaign = ?';
                $paramWhere[] = $paramActualizar['id_campaign'];
            }
            unset($paramActualizar['id_campaign']);
        }
        if (isset($paramActualizar['id'])) {
            $sqlWhere[] = 'id = ?';
            $paramWhere[] = $paramActualizar['id'];
            $id_llamada = $paramActualizar['id'];
            unset($paramActualizar['id']);
        }

        // Parámetros a modificar
        // Parameters to modify
        $sqlCampos = array();
        $paramCampos = array();

        foreach ($paramActualizar as $k => $v) {
            $sqlCampos[] = "$k = ?";
            $paramCampos[] = $v;
        }
        $sql_list[] = array(
            $sqlTabla.implode(', ', $sqlCampos).' WHERE '.implode(' AND ', $sqlWhere),
            array_merge($paramCampos, $paramWhere),
        );

        $id_contact = NULL;
        $failstates = array('Failure', 'NoAnswer', 'ShortCall', 'Abandoned');

        foreach ($sql_list as $sql_item) {
            $sth = $this->_db->prepare($sql_item[0]);
            $sth->execute($sql_item[1]);
        }

        return $eventos;
    }

    // Procedimiento que inserta un solo registro en current_calls o current_call_entry
    // Procedure that inserts a single record in current_calls or current_call_entry
    private function _sqlinsertcurrentcalls($paramInsertar)
    {
        $eventos = array();

        // Porción que identifica la tabla a modificar
        // Portion that identifies the table to modify
        $tipo_llamada = $paramInsertar['tipo_llamada'];
        unset($paramInsertar['tipo_llamada']);
        switch ($tipo_llamada) {
        case 'outgoing':
            $sqlTabla = 'INSERT INTO current_calls ';
            break;
        case 'incoming':
            $sqlTabla = 'INSERT INTO current_call_entry ';
            break;
        default:
            $this->_log->output('ERR: '.__METHOD__.' no debió haberse recibido para '.
                print_r($paramInsertar, TRUE).' | EN: should not have been received for ');
            return $eventos;
        }

        $sqlCampos = array();
        $params = array();
        foreach ($paramInsertar as $k => $v) {
            $sqlCampos[] = $k;
            $params[] = $v;
        }
        $sql = $sqlTabla.'('.implode(', ', $sqlCampos).') VALUES ('.
            implode(', ', array_fill(0, count($params), '?')).')';

        $sth = $this->_db->prepare($sql);
        $sth->execute($params);

        // Mandar de vuelta el ID de inserción a AMIEventProcess
        // Send back the insertion ID to AMIEventProcess
        $eventos[] = array('AMIEventProcess', 'idcurrentcall', array(
            $tipo_llamada,
            isset($paramInsertar['id_call_entry'])
            ? $paramInsertar['id_call_entry']
            : $paramInsertar['id_call'],
            $this->_db->lastInsertId())
        );

        return $eventos;
    }

    // Procedimiento que actualiza un solo registro en current_calls o current_call_entry
    // Procedure that updates a single record in current_calls or current_call_entry
    private function _sqlupdatecurrentcalls($paramActualizar)
    {
        $eventos = array();

        // Porción que identifica la tabla a modificar
        // Portion that identifies the table to modify
        switch ($paramActualizar['tipo_llamada']) {
        case 'outgoing':
            $sqlTabla = 'UPDATE current_calls SET ';
            break;
        case 'incoming':
            $sqlTabla = 'UPDATE current_call_entry SET ';
            break;
        default:
            $this->_log->output('ERR: '.__METHOD__.' no debió haberse recibido para '.
                print_r($paramActualizar, TRUE).' | EN: should not have been received for ');
            return $eventos;
        }
        unset($paramActualizar['tipo_llamada']);

        // Porción que identifica la tupla a modificar
        // Portion that identifies the tuple to modify
        $sqlWhere = array();
        $paramWhere = array();
        if (isset($paramActualizar['id'])) {
            $sqlWhere[] = 'id = ?';
            $paramWhere[] = $paramActualizar['id'];
            unset($paramActualizar['id']);
        }

        // Parámetros a modificar
        // Parameters to modify
        $sqlCampos = array();
        $paramCampos = array();

        foreach ($paramActualizar as $k => $v) {
            $sqlCampos[] = "$k = ?";
            $paramCampos[] = $v;
        }

        $sql = $sqlTabla.implode(', ', $sqlCampos).' WHERE '.implode(' AND ', $sqlWhere);
        $params = array_merge($paramCampos, $paramWhere);

        $sth = $this->_db->prepare($sql);
        $sth->execute($params);

        return $eventos;
    }

    private function _sqldeletecurrentcalls($paramBorrar)
    {
        $eventos = array();

        // Esto no debería pasar (manualdialing)
        // This should not happen (manualdialing)
        if (!in_array($paramBorrar['tipo_llamada'], array('incoming', 'outgoing'))) {
            $this->_log->output('ERR: '.__METHOD__.' no debió haberse recibido para '.
                print_r($paramBorrar, TRUE).' | EN: should not have been received for ');
            return $eventos;
        }

        // Porción que identifica la tabla a modificar
        // Portion that identifies the table to modify
        $sth = $this->_db->prepare(($paramBorrar['tipo_llamada'] == 'outgoing')
            ? 'DELETE FROM current_calls WHERE id = ?'
            : 'DELETE FROM current_call_entry WHERE id = ?');
        $sth->execute(array($paramBorrar['id']));

        return $eventos;
    }

    private function _sqlupdatestatcampaign($id_campaign, $num_completadas,
            $promedio, $desviacion)
    {
        $eventos = array();

        $sth = $this->_db->prepare(
            'UPDATE campaign SET num_completadas = ?, promedio = ?, desviacion = ? WHERE id = ?');
        $sth->execute(array($num_completadas, $promedio, $desviacion, $id_campaign));

        return $eventos;
    }

    private function _agregarArchivoGrabacion($tipo_llamada, $id_llamada, $uniqueid, $channel, $recordingfile)
    {
        $eventos = array();

        // TODO: configurar prefijo de monitoring
        // TODO: configure monitoring prefix
        $sDirBaseMonitor = '/var/spool/asterisk/monitor/';

        // Quitar el prefijo de monitoring de todos los archivos
        // Remove monitoring prefix from all files
        if (strpos($recordingfile, $sDirBaseMonitor) === 0)
            $recordingfile = substr($recordingfile, strlen($sDirBaseMonitor));

        // Se asume que el archivo está completo con extensión
        // It is assumed that the file is complete with extension
        $field = 'id_call_'.$tipo_llamada;
        $recordset = $this->_db->prepare("SELECT COUNT(*) AS N FROM call_recording WHERE {$field} = ? AND recordingfile = ?");
        $recordset->execute(array($id_llamada, $recordingfile));
        $iNumDuplicados = $recordset->fetch(PDO::FETCH_COLUMN, 0);
        $recordset->closeCursor();
        if ($iNumDuplicados <= 0) {
            // El archivo no constaba antes - se inserta con los datos actuales
            // The file was not recorded before - inserted with current data
            $sth = $this->_db->prepare(
                "INSERT INTO call_recording (datetime_entry, {$field}, uniqueid, channel, recordingfile) ".
                'VALUES (NOW(), ?, ?, ?, ?)');
            $sth->execute(array($id_llamada, $uniqueid, $channel, $recordingfile));
        }

        return $eventos;
    }

    private function _AgentLogin($sAgente, $iTimestampLogin, $id_agent, $sExtension = NULL)
    {
        $eventos = array();
        $eventos_forward = array();

        if (is_null($id_agent)) {
            // Ha fallado un intento de login
            // A login attempt has failed
            $eventos_forward[] = array('AgentLogin', array($sAgente, FALSE));
        } else {
            $id_sesion = $this->_marcarInicioSesionAgente($id_agent, $iTimestampLogin, $sExtension);
            if (!is_null($id_sesion)) {
                $eventos[] = array('AMIEventProcess', 'idNuevaSesionAgente', array($sAgente, $id_sesion));

                // Notificar a todas las conexiones abiertas
                // Notify all open connections
                $eventos_forward[] = array('AgentLogin', array($sAgente, TRUE));
            }
        }

        $eventos[] = array('ECCPProcess', 'emitirEventos', array($eventos_forward));
        return $eventos;
    }

    private function _AgentLogoff($sAgente, $iTimestampLogout, $id_agent, $id_sesion, $pausas)
    {
        $eventos = array();
        $eventos_forward = array();

        // Escribir la información de auditoría en la base de datos
        // Write audit information to database
        foreach ($pausas as $tipo_pausa => $id_pausa) if (!is_null($id_pausa)) {
            // TODO: ¿Qué ocurre con la posible llamada parqueada?
            // TODO: What happens with the possible parked call?
            marcarFinalBreakAgente($this->_db, $id_pausa, $iTimestampLogout);
            $eventos_forward[] = construirEventoPauseEnd($this->_db, $sAgente, $id_pausa, $tipo_pausa);
        }
        marcarFinalBreakAgente($this->_db, $id_sesion, $iTimestampLogout);

        // Notificar a todas las conexiones abiertas
        // Notify all open connections
        $eventos_forward[] = array('AgentLogoff', array($sAgente));

        $eventos[] = array('ECCPProcess', 'emitirEventos', array($eventos_forward));
        return $eventos;
    }

    /**
     * Método para marcar en las tablas de auditoría que el agente ha iniciado
     * la sesión. Esta implementación verifica si el agente ya ha sido marcado
     * previamente como que inició la sesión, y sólo marca el inicio si no está
     * ya marcado antes.
     *
     * Method to mark in audit tables that the agent has started the session.
     * This implementation checks if the agent has already been marked as having
     * started the session, and only marks the start if not already marked before.
     *
     * @param   string  $sAgente    Canal del agente que se verifica sesión
     *                              Agent channel for which session is verified
     * @param   int     $id_agent   ID en base de datos del agente
     *                              Database ID of the agent
     * @param   float   $iTimestampLogin timestamp devuelto por microtime() de login
     *                                    timestamp returned by microtime() of login
     *
     * @return  mixed   NULL en error, o el ID de la auditoría de inicio de sesión
     *                  NULL on error, or the ID of the login audit
     */
    private function _marcarInicioSesionAgente($idAgente, $iTimestampLogin, $sExtension = NULL)
    {
        // Verificación de sesión activa
        // Active session verification
        $sPeticionExiste = <<<SQL_EXISTE_AUDIT
SELECT id FROM audit
WHERE id_agent = ? AND datetime_init >= ? AND datetime_end IS NULL
    AND duration IS NULL AND id_break IS NULL
ORDER BY datetime_init DESC
SQL_EXISTE_AUDIT;
        $recordset = $this->_db->prepare($sPeticionExiste);
        $recordset->execute(array($idAgente, date('Y-m-d H:i:s', $this->_iTimestampInicioProceso)));
        $tupla = $recordset->fetch();
        $recordset->closeCursor();

        // Se indica éxito de inmediato si ya hay una sesión
        // Success is indicated immediately if there is already a session
        $idAudit = NULL;
        if ($tupla) {
            $idAudit = $tupla['id'];
            $this->_log->output('WARN: '.__METHOD__.": id_agente={$idAgente} ".
                    'inició sesión en '.date('Y-m-d H:i:s', $iTimestampLogin).
                    " pero hay sesión abierta ID={$idAudit}, se reusa. | EN: agent {$idAgente} ".
                    'started session at '.date('Y-m-d H:i:s', $iTimestampLogin).
                    " but there is open session ID={$idAudit}, reusing.");
            if (!is_null($sExtension)) {
                $sthExt = $this->_db->prepare('UPDATE audit SET login_extension = ? WHERE id = ?');
                $sthExt->execute(array($sExtension, $idAudit));
            }
        } else {
            // Ingreso de sesión del agente
            // Agent session entry
            $sTimeStamp = date('Y-m-d H:i:s', $iTimestampLogin);
            $sth = $this->_db->prepare('INSERT INTO audit (id_agent, datetime_init, login_extension) VALUES (?, ?, ?)');
            $sth->execute(array($idAgente, $sTimeStamp, $sExtension));
            $idAudit = $this->_db->lastInsertId();
        }

        return $idAudit;
    }

    private function _AgentLinked($sTipoLlamada, $idCampania, $idLlamada,
        $sChannel, $sRemChannel, $sFechaLink, $id_agent, $trunk, $queue)
    {
        $eventos = array();
        $eventos_forward = array();

        $infoLlamada = leerInfoLlamada($this->_db, $sTipoLlamada, $idCampania, $idLlamada);
        /* Ya que la escritura a la base de datos es asíncrona, puede
         * ocurrir que se lea la llamada en el estado OnQueue y sin fecha
         * de linkstart.
         *
         * Since database writing is asynchronous, it may happen that the
         * call is read in OnQueue state and without linkstart date. */
        $infoLlamada['status'] = ($infoLlamada['calltype'] == 'incoming') ? 'activa' : 'Success';
        if (!isset($infoLlamada['queue']) && !is_null($queue))
            $infoLlamada['queue'] = $queue;
        $infoLlamada['datetime_linkstart'] = $sFechaLink;
        if (!isset($infoLlamada['trunk']) || is_null($infoLlamada['trunk']))
            $infoLlamada['trunk'] = $trunk;

        // Notificar el progreso de la llamada
        // Notify call progress
        $paramProgreso = array(
            'datetime_entry'    =>  $sFechaLink,
            'new_status'        =>  'Success',
            'id_agent'          =>  $id_agent,
        );
        $paramProgreso['id_call_'.$sTipoLlamada] = $idLlamada;
        if (!is_null($idCampania)) $paramProgreso['id_campaign_'.$sTipoLlamada] = $idCampania;

        list($infoLlamada['campaignlog_id'], $eventos_forward) = $this->_construirEventoProgresoLlamada($paramProgreso);
        $eventos_forward[] = array('AgentLinked', array($sChannel, $sRemChannel, $infoLlamada));

        $eventos[] = array('ECCPProcess', 'emitirEventos', array($eventos_forward));
        return $eventos;
    }

    private function _AgentUnlinked($sAgente, $sTipoLlamada, $idCampaign,
        $idLlamada, $sPhone, $sFechaFin, $iDuracion, $bShortFlag, $paramProgreso)
    {
        $eventos = array();
        $eventos_forward = array();

        $infoLlamada = array(
            'calltype'      =>  $sTipoLlamada,
            'campaign_id'   =>  $idCampaign,
            'call_id'       =>  $idLlamada,
            'phone'         =>  $sPhone,
            'datetime_linkend'  =>  $sFechaFin,
            'duration'      =>  $iDuracion,
            'shortcall'     =>  $bShortFlag ? 1 : 0,
            'campaignlog_id'=>  NULL,
            'queue'         =>  $paramProgreso['queue'],
        );

        list($infoLlamada['campaignlog_id'], $eventos_forward) = $this->_construirEventoProgresoLlamada($paramProgreso);
        $eventos_forward[] = array('AgentUnlinked', array($sAgente, $infoLlamada));

        $eventos[] = array('ECCPProcess', 'emitirEventos', array($eventos_forward));
        return $eventos;
    }

    private function _marcarFinalHold($iTimestampFinalPausa, $sAgente, $infoLlamada, $infoSeguimiento)
    {
        $eventos = array();
        $eventos_forward = array();

        // Actualizar las tablas de calls y current_calls
        // Update calls and current_calls tables
        // TODO: esto es equivalente a SQLWorkerProcess->sqlupdatecurrentcalls
        // TODO: this is equivalent to SQLWorkerProcess->sqlupdatecurrentcalls
        if ($infoLlamada['calltype'] == 'incoming') {
            $sth = $this->_db->prepare(
                'UPDATE current_call_entry SET hold = ? WHERE id = ?');
            $sth->execute(array('N', $infoLlamada['currentcallid']));
            $sth = $this->_db->prepare('UPDATE call_entry set status = ? WHERE id = ?');
            $sth->execute(array('activa', $infoLlamada['callid']));
        } elseif ($infoLlamada['calltype'] == 'outgoing') {
            $sth = $this->_db->prepare(
                'UPDATE current_calls SET hold = ? WHERE id = ?');
            $sth->execute(array('N', $infoLlamada['currentcallid']));
            $sth = $this->_db->prepare('UPDATE calls set status = ? WHERE id = ?');
            $sth->execute(array('Success', $infoLlamada['callid']));
        }

        // Auditoría del fin del hold
        // Audit of the end of hold
        marcarFinalBreakAgente($this->_db, $infoSeguimiento['id_audit_hold'], $iTimestampFinalPausa);
        $eventos_forward[] = construirEventoPauseEnd($this->_db, $sAgente, $infoSeguimiento['id_audit_hold'], 'hold');
        $eventos_forward[] = construirEventoPauseEnd($this->_db, $sAgente, $infoSeguimiento['id_audit_hold'], 'hold');

        $eventos[] = array('ECCPProcess', 'emitirEventos', array($eventos_forward));
        return $eventos;
    }

    private function _nuevaMembresiaCola($sAgente, $infoSeguimiento, $listaColas)
    {
        $eventos = array();
        $eventos_forward = array();

        $recordset_breakinfo = NULL;
        cargarInfoPausa($this->_db, $infoSeguimiento, $recordset_breakinfo);
        $eventos_forward[] = array('QueueMembership', array($sAgente, $infoSeguimiento, $listaColas));

        $eventos[] = array('ECCPProcess', 'emitirEventos', array($eventos_forward));
        return $eventos;
    }

    private function _agentStateChange($sAgente, $sNewStatus, $sQueue)
    {
        $eventos = array();
        $eventos_forward = array();

        // Emit the AgentStateChange event for real-time UI updates (e.g., ringing status)
        $eventos_forward[] = array('AgentStateChange', array($sAgente, $sNewStatus, $sQueue));

        $eventos[] = array('ECCPProcess', 'emitirEventos', array($eventos_forward));
        return $eventos;
    }

    private function _notificarProgresoLlamada($prop)
    {
        $eventos = array();
        $eventos_forward = array();

        // Para asegurar orden estricto de eventos
        // To ensure strict order of events
        if (isset($prop['extra_events'])) {
            $eventos_forward = array_merge($eventos_forward, $prop['extra_events']);
            unset($prop['extra_events']);
        }

        list($id_campaignlog, $eventos_progreso) = $this->_construirEventoProgresoLlamada($prop);
        $eventos_forward = array_merge($eventos_forward, $eventos_progreso);

        $eventos[] = array('ECCPProcess', 'emitirEventos', array($eventos_forward));
        return $eventos;
    }

    private function _construirEventoProgresoLlamada($prop)
    {
// CUSTOMIZATIONS WC 05/08/2025
        /* Se registra bajo depuración únicamente. Esta traza se emite una vez por cada
         * cambio de estado de llamada, de modo que sin la guarda queda activa de forma
         * permanente en producción.
         *
         * Logged under debugging only. This trace is emitted once per call state
         * change, so without the guard it stays permanently active in production. */
        if ($this->DEBUG) {
            $temp_data=var_export($prop,true);
            $this->_log->output('LOGDATA: '.$temp_data);
        }
// CUSTOMIZATIONS WC 05/08/2025
        $id_campaignlog = NULL;
        $ev = NULL;
        $evlist = array();

        $campaign_type = NULL;
        foreach (array('incoming', 'outgoing') as $ct) {
            if (isset($prop['id_call_'.$ct])) {
                $campaign_type = $ct;
                break;
            }
        }

        /* Se leen las propiedades del último log de la llamada, o NULL si no
	 * hay cambio de estado previo.
         *
         * The properties of the last call log are read, or NULL if there is
	 * no previous status change. */
// CUSTOMIZATIONS WC 05/08/2025
	if($campaign_type=="")
	{
	    $campaign_type="incoming";
	    $this->_log->output('LOGDATA: no se encontro la informacion de campaign_type, utilizando incoming... | EN: campaign_type information not found, using incoming...');
	}
// CUSTOMIZATIONS WC 05/08/2025
        $recordset = $this->_db->prepare(
            "SELECT retry, uniqueid, trunk, id_agent, duration ".
            "FROM call_progress_log WHERE id_call_{$campaign_type} = ? ".
            "ORDER BY datetime_entry DESC, id DESC LIMIT 0,1");
        $recordset->execute(array($prop['id_call_'.$campaign_type]));
        $tuplaAnterior = $recordset->fetch(PDO::FETCH_ASSOC);
        $recordset->closeCursor();
        if (!is_array($tuplaAnterior) || count($tuplaAnterior) <= 0) {
            $tuplaAnterior = array(
                'retry'             =>  0,
                'uniqueid'          =>  NULL,
                'trunk'             =>  NULL,
                'id_agent'          =>  NULL,
                'duration'          =>  NULL,
            );
        }

        // Obtener agente agendado avisado por CampaignProcess o AMIEventProcess
        // Get scheduled agent notified by CampaignProcess or AMIEventProcess
        $agente_agendado = NULL;
        if (isset($prop['agente_agendado'])) {
            $agente_agendado = $prop['agente_agendado'];
            unset($prop['agente_agendado']);
        }

        // Si el número de reintento es distinto, se anulan datos anteriores
        // If retry number is different, previous data is invalidated
        if (isset($prop['retry']) && $tuplaAnterior['retry'] != $prop['retry']) {
            $tuplaAnterior['uniqueid'] = NULL;
            $tuplaAnterior['trunk'] = NULL;
            $tuplaAnterior['id_agent'] = NULL;
            $tuplaAnterior['duration'] = NULL;
        }
        $tuplaAnterior = array_merge($tuplaAnterior, $prop);

        // Escribir los valores nuevos en un nuevo registro
        // Write new values in a new record
        unset($tuplaAnterior['queue']);
        $columnas = array_keys($tuplaAnterior);
        $paramSQL = array();
        foreach ($columnas as $k) $paramSQL[] = $tuplaAnterior[$k];
        $sPeticionSQL = 'INSERT INTO call_progress_log ('.
                implode(', ', $columnas).') VALUES ('.
                implode(', ', array_fill(0, count($columnas), '?')).')';
        $sth = $this->_db->prepare($sPeticionSQL);
        $sth->execute($paramSQL);

        $id_campaignlog = $tuplaAnterior['id'] = $this->_db->lastInsertId();

        // Avisar el inicio del marcado de la llamada saliente agendada
        // Notify the start of dialing of the scheduled outgoing call
        if ($campaign_type == 'outgoing' && !is_null($agente_agendado)) {
            if ($tuplaAnterior['new_status'] == 'Placing') {
                $ev = array('ScheduledCallStart', array($agente_agendado, $campaign_type,
                    $tuplaAnterior['id_campaign_outgoing'], $tuplaAnterior['id_call_outgoing']));
                $evlist[] = $ev;
            }
            if (in_array($tuplaAnterior['new_status'], array('NoAnswer', 'Failure'))) {
                $ev = array('ScheduledCallFailed', array($agente_agendado, $campaign_type,
                    $tuplaAnterior['id_campaign_outgoing'], $tuplaAnterior['id_call_outgoing']));
                $evlist[] = $ev;
            }
        }

        /* Emitir el evento a las conexiones ECCP. Para mantener la
         * consistencia con el resto del API, se quitan los valores de
        * id_call_* y id_campaign_*, y se sintetiza tipo_llamada.
        *
        * Emit the event to ECCP connections. To maintain consistency with
        * the rest of the API, the values of id_call_* and id_campaign_* are
        * removed, and call_type is synthesized. */
        if (!in_array($tuplaAnterior['new_status'], array('Success', 'Hangup', 'ShortCall'))) {
            // Todavía no se soporta emitir agente conectado para OnHold/OffHold
            // Emitting connected agent for OnHold/OffHold is not yet supported
            unset($tuplaAnterior['id_agent']);

            $tuplaAnterior['campaign_type'] = $campaign_type;
            if (isset($tuplaAnterior['id_campaign_'.$campaign_type]))
                $tuplaAnterior['campaign_id'] = $tuplaAnterior['id_campaign_'.$campaign_type];
            $tuplaAnterior['call_id'] = $tuplaAnterior['id_call_'.$campaign_type];
            unset($tuplaAnterior['id_campaign_'.$campaign_type]);
            unset($tuplaAnterior['id_call_'.$campaign_type]);

            // Agregar el teléfono callerid o marcado
            // Add the callerid or dialed phone
            $sql = array(
                'outgoing'  =>
                    'SELECT calls.phone, campaign.queue '.
                    'FROM calls, campaign '.
                    'WHERE calls.id_campaign = campaign.id AND calls.id = ?',
                'incoming'  =>
                    'SELECT call_entry.callerid AS phone, queue_call_entry.queue '.
                    'FROM call_entry, queue_call_entry '.
                    'WHERE call_entry.id_queue_call_entry = queue_call_entry.id AND call_entry.id = ?',
            );
            $recordset = $this->_db->prepare($sql[$tuplaAnterior['campaign_type']]);
            $recordset->execute(array($tuplaAnterior['call_id']));
            $tuplaNumero = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();
            $tuplaAnterior['phone'] = $tuplaNumero['phone'];
            $tuplaAnterior['queue'] = $tuplaNumero['queue'];
            $ev = array('CallProgress', array($tuplaAnterior));
            $evlist[] = $ev;
        }

        return array($id_campaignlog, $evlist);
    }

    /**************************************************************************/

    /**
     * Procedimiento que intenta reparar los registros de auditoría que no están
     * correctamente cerrados, es decir, que tiene NULL como fecha de cierre.
     * Primero se identifican los agentes para los cuales existen auditorías
     * incompletas, y luego se intenta reparar para cada agente. Se asume que
     * este método se invoca ANTES de empezar a escuchar peticiones ECCP, y que
     * la base de datos es modificada únicamente por este proceso, y no por
     * otras copias concurrentes del dialer (lo cual no está soportado
     * actualmente).
     *
     * Procedure that attempts to repair audit records that are not properly
     * closed, i.e., that have NULL as closing date. First, agents for which
     * incomplete audits exist are identified, and then an attempt is made to
     * repair for each agent. It is assumed that this method is invoked BEFORE
     * starting to listen to ECCP requests, and that the database is modified
     * only by this process, and not by other concurrent copies of the dialer
     * (which is not currently supported).
     */
    private function _repararAuditoriasIncompletas()
    {
        try {
            $sPeticionSQL = <<<AGENTES_AUDIT_INCOMPLETO
SELECT DISTINCT agent.id, agent.type, agent.number, agent.name, agent.estatus
FROM audit, agent
WHERE agent.id = audit.id_agent AND audit.id_break IS NULL AND audit.datetime_end IS NULL
ORDER BY agent.id
AGENTES_AUDIT_INCOMPLETO;
            $recordset = $this->_db->prepare($sPeticionSQL);
            $recordset->execute();
            $agentesReparar = $recordset->fetchAll(PDO::FETCH_ASSOC);
            $recordset->closeCursor();
            foreach ($agentesReparar as $row) {
            	$this->_log->output('INFO: se ha detectado auditoría incompleta '.
                    "para {$row['type']}/{$row['number']} - {$row['name']} ".
                    "(id_agent={$row['id']} ".(($row['estatus'] == 'A') ? 'ACTIVO' : 'INACTIVO').") | EN: incomplete audit detected ".
                    "for {$row['type']}/{$row['number']} - {$row['name']} ".
                    "(id_agent={$row['id']} ".(($row['estatus'] == 'A') ? 'ACTIVE' : 'INACTIVE').")");
                $this->_repararAuditoriaAgente($row['id']);
            }
        } catch (PDOException $e) {
            $this->_stdManejoExcepcionDB($e, 'no se puede terminar de reparar auditorías | EN: cannot finish repairing audits');
        }
    }

    private function _repararAuditoriaAgente($idAgente)
    {
        // Listar todas las auditorías incompletas para este agente
        // List all incomplete audits for this agent
        $sPeticionAuditorias = <<<LISTA_AUDITORIAS_AGENTE
SELECT id, datetime_init FROM audit
WHERE id_agent = ? AND id_break IS NULL AND datetime_end IS NULL
ORDER BY datetime_init
LISTA_AUDITORIAS_AGENTE;
        $recordset = $this->_db->prepare($sPeticionAuditorias);
        $recordset->execute(array($idAgente));
        $listaAudits = $recordset->fetchAll(PDO::FETCH_ASSOC);
        $recordset->closeCursor();

        foreach ($listaAudits as $auditIncompleto) {
            /* Se intenta examinar la base de datos para obtener la fecha
             * máxima para la cual hay evidencia de actividad entre el inicio
             * de este registro y el inicio del siguiente registro.
             *
             * Attempt to examine the database to obtain the maximum date for
             * which there is evidence of activity between the start of this
             * record and the start of the next record. */
            $this->_log->output("INFO:\tSesión ID={$auditIncompleto['id']} iniciada en {$auditIncompleto['datetime_init']} | EN:\tSession ID={$auditIncompleto['id']} started at {$auditIncompleto['datetime_init']}");

            $sFechaSiguienteSesion = NULL;
            $idUltimoBreak = NULL;
            $sFechaInicioBreak = NULL;
            $sFechaFinalBreak = NULL;
            $sFechaInicioLlamada = NULL;
            $sFechaFinalLlamada = NULL;

            // El inicio de la siguiente sesión es un tope máximo para el final de la sesión incompleta.
            // The start of the next session is a maximum limit for the end of the incomplete session.
            $recordset = $this->_db->prepare(
                'SELECT datetime_init FROM audit WHERE id_agent = ? AND id_break IS NULL '.
                'AND datetime_init > ? ORDER BY datetime_init LIMIT 0,1');
            $recordset->execute(array($idAgente, $auditIncompleto['datetime_init']));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();
            if (!$tupla) {
                $this->_log->output("INFO:\tNo hay sesiones posteriores a esta sesión incompleta. | EN:\tThere are no sessions after this incomplete session.");
            } else {
                $this->_log->output("INFO:\tSiguiente sesión iniciada en {$tupla['datetime_init']} | EN:\tNext session started at {$tupla['datetime_init']}");
                $sFechaSiguienteSesion = $tupla['datetime_init'];
            }

            /* La sesión sólo puede extenderse hasta el final de la pausa antes de
             * la siguiente sesión, o la fecha actual
             *
             * The session can only extend until the end of the pause before the
             * next session, or the current date */
            $recordset = $this->_db->prepare(
                'SELECT id, datetime_init, datetime_end FROM audit WHERE id_agent = ? '.
                    'AND id_break IS NOT NULL AND datetime_init > ? AND datetime_init < ? ' .
                'ORDER BY datetime_init DESC LIMIT 0,1');
            $recordset->execute(array($idAgente, $auditIncompleto['datetime_init'],
                (is_null($sFechaSiguienteSesion) ? date('Y-m-d H:i:s') : $sFechaSiguienteSesion)));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();
            if (!$tupla) {
                $this->_log->output("INFO:\tNo hay breaks pertenecientes a esta sesión incompleta. | EN:\tThere are no breaks belonging to this incomplete session.");
            } else {
                $this->_log->output("INFO:\tÚltimo break de sesión incompleta inicia en {$tupla['datetime_init']}, ".
                    (is_null($tupla['datetime_end']) ? 'está incompleto' : 'termina en '.$tupla['datetime_end'])." | EN:\tLast break of incomplete session starts at {$tupla['datetime_init']}, ".
                    (is_null($tupla['datetime_end']) ? 'is incomplete' : 'ends at '.$tupla['datetime_end']));
                $idUltimoBreak = $tupla['id'];
                $sFechaInicioBreak = $tupla['datetime_init'];
                $sFechaFinalBreak = $tupla['datetime_end'];
            }

            /* La sesión sólo puede extenderse hasta el final de la última llamada
             * atendida antes de la siguiente sesión, si existe, o hasta la fecha
             * actual
             *
             * The session can only extend until the end of the last call attended
             * before the next session, if it exists, or until the current date */
            $recordset = $this->_db->prepare(
                'SELECT start_time, end_time FROM calls '.
                'WHERE id_agent = ? AND start_time >= ? AND start_time < ? '.
                'ORDER BY start_time DESC LIMIT 0,1');
            $recordset->execute(array($idAgente, $auditIncompleto['datetime_init'],
                (is_null($sFechaSiguienteSesion) ? date('Y-m-d H:i:s') : $sFechaSiguienteSesion)));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();
            if (!$tupla) {
                $this->_log->output("INFO:\tNo hay llamadas salientes pertenecientes a esta sesión incompleta. | EN:\tThere are no outgoing calls belonging to this incomplete session.");
            } else {
                $this->_log->output("INFO:\tÚltima llamada saliente de sesión incompleta inicia en {$tupla['start_time']}, ".
                    (is_null($tupla['end_time']) ? 'está incompleta' : 'termina en '.$tupla['end_time'])." | EN:\tLast outgoing call of incomplete session starts at {$tupla['start_time']}, ".
                    (is_null($tupla['end_time']) ? 'is incomplete' : 'ends at '.$tupla['end_time']));
                $sFechaInicioLlamada = $tupla['start_time'];
                $sFechaFinalLlamada = $tupla['end_time'];
            }
            $recordset = $this->_db->prepare(
                'SELECT datetime_init, datetime_end FROM call_entry '.
                'WHERE id_agent = ? AND datetime_init >= ? AND datetime_init < ? '.
                'ORDER BY datetime_init DESC LIMIT 0,1');
            $recordset->execute(array($idAgente, $auditIncompleto['datetime_init'],
                (is_null($sFechaSiguienteSesion) ? date('Y-m-d H:i:s') : $sFechaSiguienteSesion)));
            $tupla = $recordset->fetch(PDO::FETCH_ASSOC);
            $recordset->closeCursor();
            if (!$tupla) {
                $this->_log->output("INFO:\tNo hay llamadas entrantes pertenecientes a esta sesión incompleta. | EN:\tThere are no incoming calls belonging to this incomplete session.");
            } else {
                $this->_log->output("INFO:\tÚltima llamada entrante de sesión incompleta inicia en {$tupla['datetime_init']}, ".
                    (is_null($tupla['datetime_end']) ? 'está incompleta' : 'termina en '.$tupla['datetime_end'])." | EN:\tLast incoming call of incomplete session starts at {$tupla['datetime_init']}, ".
                    (is_null($tupla['datetime_end']) ? 'is incomplete' : 'ends at '.$tupla['datetime_end']));
                if (is_null($sFechaInicioLlamada) || $sFechaInicioLlamada < $tupla['datetime_init'])
                    $sFechaInicioLlamada = $tupla['datetime_init'];
                if (is_null($sFechaFinalLlamada) || $sFechaFinalLlamada < $tupla['datetime_end'])
                    $sFechaFinalLlamada = $tupla['datetime_end'];
            }

            /* De entre todas las fecha recogidas, se elige la más reciente como
             * la fecha de final de auditoría. Esto incluye a la fecha de inicio
             * de auditoría, con lo que una auditoría sin otros indicios quedará
             * de longitud cero.
             *
             * From all the dates collected, the most recent is chosen as the audit
             * end date. This includes the audit start date, so that an audit without
             * other indications will have zero length. */
            $sFechaFinal = $auditIncompleto['datetime_init'];
            if (!is_null($sFechaInicioBreak) && $sFechaInicioBreak > $sFechaFinal)
                $sFechaFinal = $sFechaInicioBreak;
            if (!is_null($sFechaFinalBreak) && $sFechaFinalBreak > $sFechaFinal)
                $sFechaFinal = $sFechaFinalBreak;
            if (!is_null($sFechaInicioLlamada) && $sFechaInicioLlamada > $sFechaFinal)
                $sFechaFinal = $sFechaInicioLlamada;
            if (!is_null($sFechaFinalLlamada) && $sFechaFinalLlamada > $sFechaFinal)
                $sFechaFinal = $sFechaFinalLlamada;

            $this->_log->output("INFO:\t--> Fecha estimada de final de sesión es $sFechaFinal, se actualiza... | EN:\t--> Estimated session end date is $sFechaFinal, updating...");
            $sth = $this->_db->prepare(
                'UPDATE audit SET datetime_end = ?, duration = TIMEDIFF(?, datetime_init) WHERE id = ?');
            if (!is_null($idUltimoBreak) && is_null($sFechaFinalBreak)) {
                $sth->execute(array($sFechaFinal, $sFechaFinal, $idUltimoBreak));
            }
            $sth->execute(array($sFechaFinal, $sFechaFinal, $auditIncompleto['id']));
        }
    }

    private function _stdManejoExcepcionDB($e, $s)
    {
        $this->_log->output('ERR: '.__METHOD__. ": $s: ".implode(' - ', $e->errorInfo)." | EN: $s: ".implode(' - ', $e->errorInfo));
        $this->_log->output("ERR: traza de pila: \n".$e->getTraceAsString()." | EN: stack trace: \n".$e->getTraceAsString());
        if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 2006) {
            // Códigos correspondientes a pérdida de conexión de base de datos
            // Codes corresponding to database connection loss
            $this->_log->output('WARN: '.__METHOD__.
                ': conexión a DB parece ser inválida, se cierra... | EN: DB connection appears invalid, closing...');
            $this->_db = NULL;
        }
    }
}

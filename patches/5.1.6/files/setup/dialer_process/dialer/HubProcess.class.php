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
  $Id: DialerProcess.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

define('MIN_ECCP_WORKERS', 2);  // Número mínimo de ECCPWorkerProcess a mantener
                                    // Minimum number of ECCPWorkerProcess to maintain

class HubProcess extends AbstractProcess implements iRoutedMessageHook
{
    private $_log;      // Log abierto por framework de demonio
                    // Log opened by daemon framework
    private $_config;   // Información de configuración copiada del archivo
                    // Configuration information copied from file
    private $_hub;      // Hub de mensajes entre todos los procesos
                    // Message hub between all processes
    private $_tareas;   // Lista de tareas, nombreClase => PID
                    // List of tasks, className => PID

    // Estas tareas deben estar siempre en ejecución
    // These tasks must always be running
    private $_tareasFijas = array('AMIEventProcess', 'CampaignProcess',
        'ECCPProcess', 'SQLWorkerProcess');

    // Contador para garantizar unicidad de nombre de tarea dinámica
    // Counter to guarantee uniqueness of dynamic task name
    private $_dynProcessCounter = 0;

    // Conexión debido a la cual se marcó como ocupada cada tarea dinámica
    // Connection due to which each dynamic task was marked as busy
    private $_conexionTareaOcupada = array();

    // Lista de tareas dinámicas que avisaron que atendieron su última petición
    // List of dynamic tasks that notified they attended their last request
    private $_tareasUltimaPeticion = array();

    // Último instante en que se verificó que los procesos estaban activos
    // Last instant when it was verified that processes were active
    private $_iTimestampVerificacionProcesos = NULL;

    public function inicioPostDemonio($infoConfig, &$oMainLog)
    {
        $this->_log =& $oMainLog;
        $this->_config =& $infoConfig;
        $this->_tareas = array();
        $this->_hub = new HubServer($this->_log);
        $this->_hub->registrarInspectorMsg($this);
        return TRUE;
    }

    /* Verificar si la tarea indicada sigue activa. Devuelve VERDADERO si la
     * tarea sigue corriendo, FALSO si inactiva o si se detecta que terminó. */
    /* Verify if the indicated task is still active. Returns TRUE if the
     * task is still running, FALSE if inactive or if it is detected that it ended. */
    private function _revisarTareaActiva($sTarea, $finalizando = FALSE)
    {
        $bTareaActiva = FALSE;

        // Si está definido el PID del proceso, se verifica si se ejecuta.
        // If the process PID is defined, it is verified if it is running.
        if (isset($this->_tareas[$sTarea])) {
            $iStatus = NULL;
            $iPidDevuelto = pcntl_waitpid($this->_tareas[$sTarea], $iStatus, WNOHANG);
            if ($iPidDevuelto > 0) {
                if (!$finalizando && in_array($sTarea, $this->_tareasFijas)) {
                    $this->_log->output("WARN: $sTarea (PID=$iPidDevuelto) ha terminado inesperadamente (status=$iStatus), se agenda reinicio... | EN: $sTarea (PID=$iPidDevuelto) terminated unexpectedly (status=$iStatus), scheduling restart...");
                }
                $iErrCode = pcntl_wifexited($iStatus) ? pcntl_wexitstatus($iStatus) : 255;
                $iRcvSignal = pcntl_wifsignaled($iStatus) ? pcntl_wtermsig($iStatus) : 0;
                if ($iRcvSignal != 0) { $this->_log->output("WARN: $sTarea terminó debido a señal $iRcvSignal... | EN: $sTarea terminated due to signal $iRcvSignal..."); }
                if ($iErrCode != 0) { $this->_log->output("WARN: $sTarea devolvió código de error $iErrCode... | EN: $sTarea returned error code $iErrCode..."); }
                $this->_manejarCaidaECCPWorker($sTarea);
                unset($this->_tareas[$sTarea]);
                $this->_tareasUltimaPeticion = array_diff($this->_tareasUltimaPeticion, array($sTarea));

                // Quitar la tubería del proceso que ha terminado
                // Remove the pipe of the process that has finished
                $this->_hub->quitarTuberia($sTarea);
            } else {
                $bTareaActiva = TRUE;
            }
        }

        return $bTareaActiva;
    }

    public function procedimientoDemonio()
    {
        $bHayNuevasTareas = FALSE;

        // Si la tarea ha finalizado o no existe, se debe iniciar
        // If the task has finished or does not exist, it must be started
        if (is_null($this->_iTimestampVerificacionProcesos) || time() - $this->_iTimestampVerificacionProcesos > 0) {
            foreach (array_keys($this->_tareas) as $sTarea) {
                // Si está definido el PID del proceso, se verifica si se ejecuta.
                // If the process PID is defined, it is verified if it is running.
                $this->_revisarTareaActiva($sTarea);
            }
            foreach ($this->_tareasFijas as $sTarea) {
                // Si no está definido el PID del proceso, se intenta iniciar
                // If the process PID is not defined, attempt to start it
                if (!isset($this->_tareas[$sTarea])) {
                    $this->_tareas[$sTarea] = $this->_iniciarTarea($sTarea);
                    $bHayNuevasTareas = TRUE;
                }
            }
            if ($this->_revisarTareasDinamicasActivas('ECCPWorkerProcess', MIN_ECCP_WORKERS))
                $bHayNuevasTareas = TRUE;
            $this->_iTimestampVerificacionProcesos = time();
        }

        // Registrar el multiplex con todas las conexiones nuevas
        // Register the multiplex with all new connections
        if ($bHayNuevasTareas) $this->_hub->registrarMultiplexPadre();

        $this->propagarSIGHUP();

        // Rutear todos los mensajes pendientes entre tareas
        // Route all pending messages between tasks
        if ($this->_hub->procesarPaquetes())
            $this->_hub->procesarActividad(0);
        else $this->_hub->procesarActividad(1);

        return TRUE;
    }

    public function propagarSIGHUP()
    {
        global $gsNombreSignal;

        if (!is_null($gsNombreSignal) && $gsNombreSignal == SIGHUP) {
            // Mandar la señal a todos los procesos controlados
            // Send the signal to all controlled processes
            $this->_log->output("PID = ".posix_getpid().", se ha recibido señal #$gsNombreSignal, ".
                (($gsNombreSignal == SIGHUP) ? 'cambiando logs | EN: changing logs' : 'terminando | EN: terminating')."...");
            $this->_propagarSIG($gsNombreSignal);
        }
    }

    /* Iniciar una tarea específica en un proceso separado. Para el proceso
     * padre, devuelve el PID del proceso hijo. */
    /* Start a specific task in a separate process. For the parent
     * process, returns the PID of the child process. */
    private function _iniciarTarea($sNombreTarea)
    {
        return $this->_iniciarTareaClase($sNombreTarea, $sNombreTarea);
    }

    private function _iniciarTareaClase($sNombreTarea, $sNombreClase)
    {
        global $gsNombreSignal;

        // Verificar que el nombre de la clase que implementa el proceso es válido
        // Verify that the class name that implements the process is valid
        if (!class_exists($sNombreClase)) {
            $this->_log->output("FATAL: (interno) Nombre de clase de proceso inválido '$sNombreClase' | EN: (internal) Invalid process classname '$sNombreClase'");
            die("(internal) Invalid process classname '$sNombreClase'\n");
        }

        // Nueva tubería con el nombre de la tarea
        // New pipe with the task name
        $oTuberia = $this->_hub->crearTuberia($sNombreTarea);
        $oTuberia->setLog($this->_log);

        // Iniciar tarea en proceso separado
        // Start task in separate process
        $iPidProceso = pcntl_fork();
        if ($iPidProceso != -1) {
            if ($iPidProceso == 0) {
                $this->_log->prefijo($sNombreTarea);
                $this->_log->output("iniciando proceso... | EN: starting process...");

                // Instalar los manejadores de señal para el proceso hijo
                // Install signal handlers for the child process
                pcntl_signal(SIGTERM, 'manejadorPrimarioSignal');
                pcntl_signal(SIGQUIT, 'manejadorPrimarioSignal');
                pcntl_signal(SIGINT, 'manejadorPrimarioSignal');
                pcntl_signal(SIGHUP, 'manejadorPrimarioSignal');

                // Elegir la tarea que debe de ejecutarse
                // Choose the task that must be executed
                $oProceso = NULL;
                try {
                    $oProceso = new $sNombreClase($oTuberia);
                    if (!($oProceso instanceof TuberiaProcess)) throw new Exception('Not a subclass of TuberiaProcess!');
                } catch (Throwable $ex) {
                    $this->_log->output("ERR: al crear $sNombreTarea - excepción no manejada: ".$ex->getMessage()." | EN: error when creating $sNombreTarea - unhandled exception: ".$ex->getMessage());
                    die("ERR: al crear $sNombreTarea - ".$ex->getMessage()."\n");
                }

                // Realizar inicialización adicional de la tarea
                // Perform additional initialization of the task
                try {
                    $bContinuar = $oProceso->inicioPostDemonio($this->_config, $this->_log);
                    if ($bContinuar) $this->_log->output("PID = ".posix_getpid().", proceso iniciado normalmente | EN: PID = ".posix_getpid().", process started normally");
                } catch (Throwable $ex) {
                    $bContinuar = FALSE;
                    $this->_log->output("ERR: al inicializar $sNombreTarea - excepción no manejada: ".$ex->getMessage()." | EN: error when initializing $sNombreTarea - unhandled exception: ".$ex->getMessage());
                }

                // Continuar la tarea hasta que se finalice
                // Continue the task until it finishes
                while ($bContinuar) {
                    // Ejecutar el procedimiento de trabajo del demonio
                    // Execute the daemon work procedure
                    if (is_null($gsNombreSignal)) {
                        try {
                            $bContinuar = $oProceso->procedimientoDemonio();
                        } catch (Throwable $ex) {
                            $bContinuar = FALSE;
                            $this->_log->output("ERR: al ejecutar $sNombreTarea - excepción no manejada: ".$ex->getMessage()." | EN: error when executing $sNombreTarea - unhandled exception: ".$ex->getMessage());
                        }
                    }

                    /* dialerd's declare(ticks) covers only its own file, so in
                     * this loop a signal reached manejadorPrimarioSignal() only
                     * when dialerd's error handler happened to tick enough, and
                     * a logrotate SIGHUP could stay pending for hours while the
                     * task kept writing to the rotated log. Dispatch pending
                     * signals here, right before they are acted on.
                     * This relies on KillMode=mixed in issabeldialer.service:
                     * under control-group systemd SIGTERMs every task at once,
                     * and a task that obeys it exits before HubProcess's
                     * shutdown handshake (agents never logged out). */
                    pcntl_signal_dispatch();

                    // Revisar si existe señal que indique finalización del programa
                    // Check if there is a signal indicating program termination
                    if (!is_null($gsNombreSignal)) {
                        if (in_array($gsNombreSignal, array(SIGTERM, SIGINT, SIGQUIT))) {
                            $this->_log->output("PID = ".posix_getpid().", proceso recibió señal $gsNombreSignal, terminando... | EN: process received signal $gsNombreSignal, terminating...");
                            $bContinuar = FALSE;
                        } elseif ($gsNombreSignal == SIGHUP) {
                            $this->_log->output("PID = ".posix_getpid().", proceso recibió señal $gsNombreSignal, cambiando logs... | EN: process received signal $gsNombreSignal, changing logs...");
                            $this->_log->reopen();
                            $this->_log->output("PID = ".posix_getpid().", proceso recibió señal $gsNombreSignal, usando nuevo log. | EN: process received signal $gsNombreSignal, using new log.");
                            $gsNombreSignal = NULL;
                        }
                    }
                }

                // Indicar al módulo de trabajo por qué se está finalizando
                // Indicate to the work module why it is finishing
                try {
                    $oProceso->limpiezaDemonio($gsNombreSignal);
                } catch (Throwable $ex) {
                    $this->_log->output("ERR: al finalizar $sNombreTarea - excepción no manejada: ".$ex->getMessage()." | EN: error when finalizing $sNombreTarea - unhandled exception: ".$ex->getMessage());
                }
                $this->_log->output("PID = ".posix_getpid().", proceso terminó normalmente. | EN: process finished normally.");
                $this->_log->close();

                exit(0);   // Finalizar el proceso hijo
                          // Terminate the child process
            }
        } else {
            // Avisar que no se puede iniciar la tarea requerida
            // Notify that the required task cannot be started
            $this->_log->output("No se pudo bifurcar $sNombreTarea - $! | EN: Unable to fork $sNombreTarea - $!");
        }
        return $iPidProceso;
    }

    private function _revisarTareasDinamicasActivas($sNombreClase, $min_workers)
    {
        $bHayNuevasTareas = FALSE;

        $i = 0;
        foreach (array_keys($this->_tareas) as $sTarea)
            if (strpos($sTarea, $sNombreClase.'-') === 0) $i++;
        for (; $i < $min_workers; $i++) {
            $this->_iniciarTareaDinamica($sNombreClase);
            $bHayNuevasTareas = TRUE;
        }
        return $bHayNuevasTareas;
    }

    private function _iniciarTareaDinamica($sNombreClase)
    {
        $sTarea = $sNombreClase.'-'.$this->_dynProcessCounter;
        $this->_dynProcessCounter++;
        $this->_tareas[$sTarea] = $this->_iniciarTareaClase($sTarea, $sNombreClase);
        return $sTarea;
    }

    // Por interfaz iRoutedMessageHook
    // Through iRoutedMessageHook interface
    public function inspeccionarMensaje(&$sFuente, &$sDestino, &$sNombreMensaje, &$datos)
    {
        if ($sFuente == 'ECCPProcess' && $sDestino == 'ECCPWorkerProcess') {
            /* Al manejar ECCP, ECCPProcess requiere que el requerimiento lo maneje
             * una instancia de ECCPWorkerProcess, pero no puede saber cuál.
             * Aquí debe elegirse la instancia, y una vez elegida, asignar el
             * estado de ocupado hasta que mande una respuesta. Si es necesario,
             * debe de crearse un nuevo proceso. */
            /* When handling ECCP, ECCPProcess requires that the request be handled
             * by an ECCPWorkerProcess instance, but cannot know which one.
             * Here the instance must be chosen, and once chosen, assign the
             * busy state until it sends a response. If necessary,
             * a new process must be created. */
            $sTareaElegida = NULL;
            foreach (array_keys($this->_tareas) as $sTarea) {
                if ($this->_revisarTareaActiva($sTarea) &&
                    strpos($sTarea, 'ECCPWorkerProcess-') === 0 &&
                    !isset($this->_conexionTareaOcupada[$sTarea]) &&
                    !in_array($sTarea, $this->_tareasUltimaPeticion)) {
                    $sTareaElegida = $sTarea;
                    break;
                }
            }

            // Iniciar nuevo proceso si no hay tarea elegida
            // Start new process if no task is chosen
            if (is_null($sTareaElegida)) {
                $sTarea = $this->_iniciarTareaDinamica('ECCPWorkerProcess');
                $this->_hub->registrarMultiplexPadre();
                $sTareaElegida = $sTarea;
            }

            // El primer parámetro es la conexión proxy
            // The first parameter is the proxy connection
            $this->_conexionTareaOcupada[$sTareaElegida] = $datos[0];
            $sDestino = $sTareaElegida;
        } elseif (strpos($sFuente, 'ECCPWorkerProcess-') === 0 && $sDestino == 'ECCPProcess'
                && $sNombreMensaje == 'eccpresponse') {
            /* Al mandar la respuesta asíncrona desde un ECCPWorkerProcess, se
             * debe de limpiar el estado de ocupado para esa instancia en
             * particular. */
            /* When sending the asynchronous response from an ECCPWorkerProcess,
             * the busy state must be cleared for that particular instance. */
            unset($this->_conexionTareaOcupada[$sFuente]);

            /* El ECCPWorkerProcess manda como primer parámetro una bandera que
             * indica si finaliza por haber atendido su última petición. Si es
             * así, se lo agrega a la lista negra de procesos que terminan. */
            /* The ECCPWorkerProcess sends as first parameter a flag that
             * indicates if it finishes after having attended its last request. If
             * so, it is added to the blacklist of terminating processes. */
            $bUltimaPeticion = array_shift($datos);
            if ($bUltimaPeticion) {
                $this->_tareasUltimaPeticion[] = $sFuente;

                // Permitir al proceso ECCPWorkerProcess que finalice
                // Allow the ECCPWorkerProcess to finish
                $this->_hub->rutearMensaje(
                    'HubProcess',
                    $sFuente,
                    'finalizarWorker',
                    microtime(TRUE),
                    array());
            }
        }
    }

    private function _manejarCaidaECCPWorker($sTarea)
    {
        if (isset($this->_conexionTareaOcupada[$sTarea])) {
            $this->_log->output("WARN: tarea $sTarea con PID=".
                $this->_tareas[$sTarea]." ha terminado mientras procesaba una ".
                "petición ECCP para la conexión ".$this->_conexionTareaOcupada[$sTarea]." | EN: task $sTarea with PID=".
                $this->_tareas[$sTarea]." terminated while processing an ".
                "ECCP request for connection ".$this->_conexionTareaOcupada[$sTarea]);
            $sCrashMsg = <<<XML_CRASH_MSG
<?xml version="1.0"?>
<response><failure><code>503</code><message>Internal server error - worker crash while handling request</message></failure></response>
XML_CRASH_MSG;
            $this->_hub->rutearMensaje(
                $sTarea,
                'ECCPProcess',
                'eccpresponse',
                microtime(TRUE),
                array(
                    TRUE,
                    $this->_conexionTareaOcupada[$sTarea],
                    $sCrashMsg,
                    array(
                        'usuarioeccp'   =>  NULL,
                        'appcookie'     =>  NULL,
                        'finalizando'   =>  TRUE,
                    ),
                    NULL,
                ));
        }
    }

    public function limpiezaDemonio($signum)
    {
        // Propagar la señal si no es NULL
        // Propagate the signal if not NULL
        if (!is_null($signum)) {
            // Mandar la señal a todos los procesos controlados
            // Send the signal to all controlled processes
            $this->_log->output("PID = ".posix_getpid().", se ha recibido señal #$signum, terminando... | EN: received signal #$signum, terminating...");
        } else {
            $signum = SIGTERM;
            $this->_log->output("Término normal del programa, se terminará procesos hijos... | EN: Normal program termination, terminating child processes...");
        }

        // Avisar a todos los procesos que se terminará el programa
        // Notify all processes that the program will terminate
        $this->_log->output('INFO: avisando de finalización a todos los procesos... | EN: notifying all processes of termination...');
        $this->_hub->enviarFinalizacion();
        $this->_log->output('INFO: esperando respuesta de todos los procesos... | EN: waiting for response from all processes...');
        /* La espera de confirmación está acotada: una tarea colgada no puede
         * confirmar, y esperarla sin límite entregaba el stop al SIGKILL de
         * systemd a los 30 s. The confirmation wait is bounded: a stuck task
         * cannot confirm, and waiting for it without a bound handed the stop
         * to systemd's 30 s SIGKILL. */
        $iTiempoIniEspera = time();
        while ($this->_hub->numFinalizados() < count(array_filter($this->_tareas))) {
            foreach (array_keys($this->_tareas) as $sTarea)
                $this->_revisarTareaActiva($sTarea, TRUE);
            $this->_routeAndWait();
            if (time() - $iTiempoIniEspera >= 10) {
                $sTareasPendientes = implode(', ', array_keys(array_filter($this->_tareas)));
                $this->_log->output('WARN: tareas sin confirmar finalización en 10 s '.
                    '(siguen en ejecución: '.$sTareasPendientes.'), se continúa con la señal '.
                    '| EN: tasks did not confirm termination within 10 s '.
                    '(still running: '.$sTareasPendientes.'), proceeding to signal');
                break;
            }
        }

        $this->_propagarSIG($signum);
        $iPrimeraSenal = time();

        $this->_log->output('INFO: esperando a que todas las tareas terminen... | EN: waiting for all tasks to finish...');
        $bTodosTerminaron = FALSE;
        $t1 = time();
        do {
            $bTodosTerminaron = TRUE;
            foreach (array_keys($this->_tareas) as $sTarea) {
                // Si está definido el PID del proceso, se verifica si se ejecuta.
                // If the process PID is defined, it is verified if it is running.
                if ($this->_revisarTareaActiva($sTarea, TRUE)) {
                    // Este proceso aún no termina...
                    // This process has not finished yet...
                    $bTodosTerminaron = FALSE;
                }
            }

            if (!$bTodosTerminaron) {
                $t2 = time();
                /* Una tarea colgada dentro de wait_response() no atiende
                 * SIGTERM (su manejador sólo corre de vuelta en el bucle de
                 * la tarea), así que se escala a SIGKILL para que el stop
                 * completo quede muy por debajo de los 30 s de systemd. A
                 * task stuck inside wait_response() never gets back to the
                 * task loop that runs its SIGTERM handler, so escalate to
                 * SIGKILL to keep the whole stop well under systemd's 30 s. */
                if ($t2 - $iPrimeraSenal >= 10) {
                    $sTareasVivas = implode(', ', array_keys(array_filter($this->_tareas)));
                    $this->_log->output('WARN: tareas aún vivas 10 s tras la señal '.
                        '(se envía SIGKILL a: '.$sTareasVivas.') | EN: tasks still alive '.
                        '10 s after signal (sending SIGKILL to: '.$sTareasVivas.')');
                    $this->_propagarSIG(SIGKILL);
                    $iPrimeraSenal = $t2;
                } elseif ($t2 - $t1 >= 4) {
                    $this->_log->output('WARN: no todas las tareas han terminado, se vuelve a enviar señal... | EN: not all tasks have finished, resending signal...');
                    $this->_propagarSIG($signum);
                    $t1 = $t2;
                }

                // Rutear todos los mensajes pendientes entre tareas
                // Route all pending messages between tasks
                $this->_routeAndWait();
            }
        } while (!$bTodosTerminaron);
        $this->_log->output('INFO: todas las tareas han terminado. | EN: all tasks have finished.');

        // Mandar a cerrar todas las conexiones activas
        // Send to close all active connections
        $this->_hub->finalizarServidor();
    }

    /* Route pending messages, waiting up to 1 s for activity on the task
     * pipes. A task closes its pipe as it exits, and once every pipe is closed
     * procesarActividad() has nothing to wait on and returns at once, so the
     * shutdown loops above spun at full CPU and starved the exiting tasks.
     * The 10 ms pause keeps them from spinning. */
    private function _routeAndWait()
    {
        if ($this->_hub->procesarPaquetes())
            $this->_hub->procesarActividad(0);
        else $this->_hub->procesarActividad(1);
        usleep(10000);
    }

    // Propagar la señal recibida o sintetizada
    // Propagate the received or synthesized signal
    private function _propagarSIG($signum)
    {
        foreach (array_keys($this->_tareas) as $sTarea) {
            $this->_log->output("Propagando señal #$signum a $sTarea... | EN: propagating signal #$signum to $sTarea...");
            posix_kill($this->_tareas[$sTarea], $signum);
            $this->_log->output("Completada propagación de señal a $sTarea | EN: completed signal propagation to $sTarea");
        }
    }
}
?>
<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  Encoding: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 4.0                                                  |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2019 Issabel Foundation                                |
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
  $Id: QueueShadow.class.php, Mon 25 Nov 2019 03:12:40 PM EST, nicolas@issabel.com
*/

// Para obtener los estados de miembros definidos en Agente.class.php
// To get the member statuses defined in Agente.class.php
require_once 'Agente.class.php';

class QueueShadow
{
    var $DEBUG = FALSE;
    private $_log;

    private $_queues = array();
    private $_queueflags = NULL;

    function __construct($log)
    {
        $this->_log = $log;
    }

    /**
     * Método para iniciar el modo de enumeración de la información de las
     * colas. Se invalidan todos los elementos esperando que los eventos
     * recibidos los vuelvan a validar.
     * Method to start the enumeration mode of queue information.
     * All elements are invalidated waiting for received events to re-validate them.
     */
    function QueueStatus_start($queueflags)
    {
        $this->_queueflags = $queueflags;
        foreach (array_keys($this->_queues) as $q) {
            $this->_queues[$q]['removed'] = TRUE;
            foreach (array_keys($this->_queues[$q]['members']) as $m) {
                $this->_queues[$q]['members'][$m]['removed'] = TRUE;
            }
        }
    }

    function msg_QueueParams($params)
    {
        if (!isset($this->_queues[$params['Queue']])) {
            $this->_queues[$params['Queue']] = array(
                'removed'           => FALSE,
                'members'           => array(),
                'callers'           => 0,
                'eventmemberstatus' =>  FALSE,
                'eventwhencalled'   =>  FALSE,
            );
        } else {
            $this->_queues[$params['Queue']]['removed'] = FALSE;
            $this->_queues[$params['Queue']]['callers'] = 0;

            // ¿Cómo puedo saber si es seguro heredar event* ?
            // How can I know if it's safe to inherit event*?
            $this->_queues[$params['Queue']]['eventmemberstatus'] = FALSE;
            $this->_queues[$params['Queue']]['eventwhencalled'] = FALSE;
        }

        if (isset($this->_queueflags[$params['Queue']])) {
            foreach (array('eventmemberstatus', 'eventwhencalled') as $k)
                $this->_queues[$params['Queue']][$k] = $this->_queueflags[$params['Queue']][$k];
        }
    }

    function msg_QueueMember($params)
    {
        if (!isset($this->_queues[$params['Queue']]['members'][$params['Location']])) {
            $this->_queues[$params['Queue']]['members'][$params['Location']] = array(
                'removed'   => FALSE,
                'Status'    => $params['Status'],
                'Paused'    => ($params['Paused'] != 0),
                'LinkStart' => NULL,
            );
        } else {
            $this->_queues[$params['Queue']]['members'][$params['Location']]['removed'] = FALSE;
            $this->_queues[$params['Queue']]['members'][$params['Location']]['Status'] = $params['Status'];
            $this->_queues[$params['Queue']]['members'][$params['Location']]['Paused'] = ($params['Paused'] != 0);
            // Voy a asumir que puedo conservar el valor de LinkStart
            // I'm going to assume I can keep the LinkStart value
        }
    }

    function msg_QueueEntry($params)
    {
        $this->_queues[$params['Queue']]['callers']++;
    }

    /**
     * Quitar todo elemento que no haya sido re-validado en enumeración.
     * Remove all elements that haven't been re-validated in enumeration.
     */
    function msg_QueueStatusComplete($params)
    {
        $colasSinEventos = array();

        foreach (array_keys($this->_queues) as $q) {
            if ($this->_queues[$q]['removed']) {
                unset($this->_queues[$q]);
            } else {
                // Acumular colas sin banderas activas
                // Accumulate queues without active flags
                if (!($this->_queues[$q]['eventwhencalled'] && $this->_queues[$q]['eventmemberstatus'])) {
                    if ($q != 'default') $colasSinEventos[] = $q;
                }

                foreach (array_keys($this->_queues[$q]['members']) as $m) {
                    if ($this->_queues[$q]['members'][$m]['removed']) {
                        unset($this->_queues[$q]['members'][$m]);
                    }
                }
            }
        }
        $this->_queueflags = NULL;
        if ($this->DEBUG) {
            $this->_log->output('DEBUG: '.__METHOD__.': estado de colas | EN: queue status: '.print_r($this->_queues, TRUE));
        }
        if (count($colasSinEventos) > 0) {
            sort($colasSinEventos);
            $this->_log->output('WARN: '.__METHOD__.': para mejorar el desempeño de '.
                'campañas salientes, se recomienda activar eventwhencalled y '.
                'eventmemberstatus en las siguientes colas: ['.
                implode(' ', $colasSinEventos).'] | EN: to improve outbound campaign performance, '.
                'it is recommended to activate eventwhencalled and '.
                'eventmemberstatus in the following queues: ['.
                implode(' ', $colasSinEventos).']');
        }
    }

    /**************************************************************************/

    function msg_QueueMemberAdded($params)
    {
        if (!isset($this->_queues[$params['Queue']])) {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra cola '.$params['Queue'].' | EN: queue '.$params['Queue'].' not found');
            return;
        }

        $params['Location'] = isset($params['Location'])?$params['Location']:$params['Interface'];
        $this->_queues[$params['Queue']]['members'][$params['Location']] = array(
            'removed'   => FALSE,
            'Status'    => $params['Status'],
            'Paused'    => ($params['Paused'] != 0),
            'LinkStart' => NULL,
        );
    }

    function msg_QueueMemberRemoved($params)
    {
        if (!isset($this->_queues[$params['Queue']])) {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra cola '.$params['Queue'].' | EN: queue '.$params['Queue'].' not found');
            return;
        }

        $params['Location'] = isset($params['Location'])?$params['Location']:$params['Interface'];
        unset($this->_queues[$params['Queue']]['members'][$params['Location']]);
    }

    function msg_QueueMemberPaused($params)
    {
        if (!isset($this->_queues[$params['Queue']])) {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra cola '.$params['Queue'].' | EN: queue '.$params['Queue'].' not found');
            return;
        }

        $params['Location'] = isset($params['Location'])?$params['Location']:$params['Interface'];
        if (isset($this->_queues[$params['Queue']]['members'][$params['Location']])) {
            $this->_queues[$params['Queue']]['members'][$params['Location']]['Paused'] = ($params['Paused'] != 0);
        } else {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra miembro '.$params['Location'].
                    ' en cola '.$params['Queue'].' | EN: member '.$params['Location'].
                    ' not found in queue '.$params['Queue']);
        }
    }

    function msg_QueueMemberStatus($params)
    {
        if (!isset($this->_queues[$params['Queue']])) {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra cola '.$params['Queue'].' | EN: queue '.$params['Queue'].' not found');
            return;
        }

        // Cola validada que tiene eventmemberstatus activo
        // Queue validated that has eventmemberstatus active
        $this->_queues[$params['Queue']]['eventmemberstatus'] = TRUE;

        $params['Location'] = isset($params['Location'])?$params['Location']:$params['Interface'];
        if (isset($this->_queues[$params['Queue']]['members'][$params['Location']])) {
            $this->_queues[$params['Queue']]['members'][$params['Location']]['Status'] = $params['Status'];
            $this->_queues[$params['Queue']]['members'][$params['Location']]['Paused'] = ($params['Paused'] != 0);
            // Voy a asumir que puedo conservar el valor de LinkStart
            // I'm going to assume I can keep the LinkStart value
        } else {
           $this->_log->output('WARN: '.__METHOD__.': no se encuentra miembro '.$params['Location'].
               ' en cola '.$params['Queue'].', se agrega | EN: member '.$params['Location'].
               ' not found in queue '.$params['Queue'].', adding');
            $this->_queues[$params['Queue']]['members'][$params['Location']] = array(
                'removed'   => FALSE,
                'Status'    => $params['Status'],
                'Paused'    => ($params['Paused'] != 0),
                'LinkStart' => NULL,
            );
        }
    }
    function msg_Join($params)
    {
        if (!isset($this->_queues[$params['Queue']])) {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra cola '.$params['Queue'].' | EN: queue '.$params['Queue'].' not found');
            return;
        }

        $this->_queues[$params['Queue']]['callers']++;
    }

    function msg_Leave($params)
    {
        if (!isset($this->_queues[$params['Queue']])) {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra cola '.$params['Queue'].' | EN: queue '.$params['Queue'].' not found');
            return FALSE;
        }

        $this->_queues[$params['Queue']]['callers']--;

        if ($this->_queues[$params['Queue']]['callers'] < 0) {
            $this->_queues[$params['Queue']]['callers'] = 0;
            return FALSE;
        }
        return TRUE;
    }

    function msg_AgentCalled($params)
    {
        if (!isset($this->_queues[$params['Queue']])) {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra cola '.$params['Queue'].' | EN: queue '.$params['Queue'].' not found');
            return;
        }

        // Cola validada que tiene eventwhencalled activo
        // Queue validated that has eventwhencalled active
        $this->_queues[$params['Queue']]['eventwhencalled'] = TRUE;

        // TODO: qué se puede hacer aquí?
        // TODO: what can be done here?
    }

    function msg_AgentComplete($params)
    {
        if (!isset($this->_queues[$params['Queue']])) {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra cola '.$params['Queue'].' | EN: queue '.$params['Queue'].' not found');
            return;
        }

        // Cola validada que tiene eventwhencalled activo
        // Queue validated that has eventwhencalled active
        $this->_queues[$params['Queue']]['eventwhencalled'] = TRUE;

        $params['Member'] = isset($params['Member'])?$params['Member']:$params['Interface'];
        if (isset($this->_queues[$params['Queue']]['members'][$params['Member']])) {
            $this->_queues[$params['Queue']]['members'][$params['Member']]['LinkStart'] = NULL;

            // La actualización de Status debería hacerse en un QueueMemberStatus próximo
            // The Status update should be done in a next QueueMemberStatus
        } else {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra miembro '.$params['Member'].
                ' en cola '.$params['Queue'].' | EN: member '.$params['Member'].' not found in queue '.$params['Queue']);
        }
    }

    function msg_AgentConnect($params)
    {
        if (!isset($this->_queues[$params['Queue']])) {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra cola '.$params['Queue'].' | EN: queue '.$params['Queue'].' not found');
            return;
        }

        // Cola validada que tiene eventwhencalled activo
        // Queue validated that has eventwhencalled active
        $this->_queues[$params['Queue']]['eventwhencalled'] = TRUE;

        $params['Member'] = isset($params['Member'])?$params['Member']:$params['Interface'];
        if (isset($this->_queues[$params['Queue']]['members'][$params['Member']])) {
            $this->_queues[$params['Queue']]['members'][$params['Member']]['LinkStart'] = $params['local_timestamp_received'];

            // La actualización de Status debería hacerse en un QueueMemberStatus próximo
            // The Status update should be done in a next QueueMemberStatus
        } else {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra miembro '.$params['Member'].
                ' en cola '.$params['Queue'].' | EN: member '.$params['Member'].' not found in queue '.$params['Queue']);
        }
    }

    function msg_AgentDump($params)
    {
        if (!isset($this->_queues[$params['Queue']])) {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra cola '.$params['Queue'].' | EN: queue '.$params['Queue'].' not found');
            return;
        }

        // Cola validada que tiene eventwhencalled activo
        // Queue validated that has eventwhencalled active
        $this->_queues[$params['Queue']]['eventwhencalled'] = TRUE;

        // TODO: qué se puede hacer aquí?
        // TODO: what can be done here?
    }

    /**************************************************************************/

    function llamadasEnEspera()
    {
        $t = array();

        foreach ($this->_queues as $q => $info) {
            $t[$q] = $info['callers'];
        }
        return $t;
    }

    // A member on a live call (LinkStart plus a busy or ringing device state) stays busy (5.3.25).
    // A member whose device is ringing is being offered a call (a queue delivery or any other
    // call): the queue will not ring it again under ringinuse=no, and no counter subtracts the
    // call already ringing it, so it is not a free agent in any mode; it is free again once its
    // device reads NOT_INUSE.
    // Un miembro en llamada viva (LinkStart más un estado de dispositivo ocupado o sonando) sigue ocupado (5.3.25).
    // Un miembro cuyo dispositivo está sonando está recibiendo una oferta de llamada (una entrega
    // de cola o cualquier otra llamada): la cola no lo volverá a llamar con ringinuse=no y ningún
    // contador resta la llamada que ya lo está haciendo sonar, así que no es un agente libre en
    // ningún modo; queda libre cuando su dispositivo marca NOT_INUSE.
    function infoPrediccionCola($queue, $predictive = true)
    {
        if (!isset($this->_queues[$queue])) {
            $this->_log->output('WARN: '.__METHOD__.': no se encuentra cola '.$queue.' | EN: queue '.$queue.' not found');
            return NULL;
        }

        if (!($this->_queues[$queue]['eventwhencalled'] && $this->_queues[$queue]['eventmemberstatus'])) {
            if ($this->DEBUG) {
                $this->_log->output('DEBUG: '.__METHOD__.': cola '.$queue.' no ha recibido eventos que '.
                    'indiquen activación de eventwhencalled y eventmemberstatus | EN: queue '.$queue.' has not received events '.
                    'indicating activation of eventwhencalled and eventmemberstatus');
            }
            return NULL;
        }

        // === TIMESTAMP TRACKER: Queue Info Entry ===
        $fQueueMicrotime = microtime(TRUE);
        $fQueueTime = date('Y-m-d H:i:s.', (int)$fQueueMicrotime) . sprintf('%03d', ($fQueueMicrotime - (int)$fQueueMicrotime) * 1000);
        $this->_log->output("TIMING: ".__METHOD__.": [QUEUE_CHECK] Queue=$queue, microtime=$fQueueMicrotime, time=$fQueueTime | ES: Verificando agentes libres en cola");

        $iNumLlamadasColocar = array(
            'AGENTES_LIBRES'        =>  0,
            'AGENTES_LIBRES_LISTA'  =>  array(),  // List of free agent interfaces for conflict detection
            'AGENTES_POR_DESOCUPAR' =>  array(),
            'CLIENTES_ESPERA'       =>  $this->_queues[$queue]['callers'],
        );

        $arrAgentStatus = array();  // Track each agent's status for logging
        foreach ($this->_queues[$queue]['members'] as $miembro_key => $miembro) {

            // Se ignora miembro en pausa
            // Paused member is ignored
            if ($miembro['Paused']) continue;

            // Track status for debugging
            $arrAgentStatus[$miembro_key] = array(
                'Status' => $miembro['Status'],
                'StatusName' => $this->_getStatusName($miembro['Status']),
                'LinkStart' => $miembro['LinkStart']
            );

            // Asterisk puede re-publicar un RINGING caducado después de la respuesta y
            // dejarlo durante toda la llamada; el LinkStart (AgentConnect) dice que el
            // miembro está en esa llamada. La guarda por estado del dispositivo mantiene
            // libre a un miembro cuyo AgentComplete se perdió, en cuanto su dispositivo
            // marca NOT_INUSE.
            // Asterisk can re-publish a stale RINGING after the answer and leave it for
            // the whole call; the LinkStart (AgentConnect) says the member is on that
            // call. The device-state guard keeps a member whose AgentComplete was lost
            // free once its device reads NOT_INUSE.
            $onLiveCall = !is_null($miembro['LinkStart']) &&
                in_array($miembro['Status'], array(AST_DEVICE_INUSE, AST_DEVICE_BUSY, AST_DEVICE_RINGINUSE, AST_DEVICE_RINGING));

            // Miembro definitivamente libre
            // Member definitely free
            $freeStatuses = array(AST_DEVICE_NOT_INUSE);
            if (!$onLiveCall && in_array($miembro['Status'], $freeStatuses)) {
                $iNumLlamadasColocar['AGENTES_LIBRES']++;
                $iNumLlamadasColocar['AGENTES_LIBRES_LISTA'][] = $miembro_key;
                $this->_log->output("TIMING: ".__METHOD__.": [AGENT_FREE] Agent=$miembro_key, Status=".$miembro['Status']."(".$this->_getStatusName($miembro['Status'])."), Queue=$queue, predictive=".($predictive?'YES':'NO')." | ES: Agente marcado como libre (predictivo=".($predictive?'SI':'NO').")");
            }

            // Miembro ocupado, se verifica si se desocupará
            // Busy member, verify if it will become free
            if ($onLiveCall) {
                if ($miembro['Status'] == AST_DEVICE_RINGING) {
                    $this->_log->output("TIMING: ".__METHOD__.": [AGENT_ON_CALL] Agent=$miembro_key, Status=".$miembro['Status']."(".$this->_getStatusName($miembro['Status'])."), Queue=$queue, call_age=".sprintf('%.1f', microtime(TRUE) - $miembro['LinkStart'])."s | ES: Agente en llamada aunque su dispositivo figura en RINGING");
                }
                $iNumLlamadasColocar['AGENTES_POR_DESOCUPAR'][] = microtime(TRUE) - $miembro['LinkStart'];
            }
        }

        // === TIMESTAMP TRACKER: Queue Info Summary ===
        $fAfterMicrotime = microtime(TRUE);
        $fElapsed = ($fAfterMicrotime - $fQueueMicrotime) * 1000;
        $this->_log->output("TIMING: ".__METHOD__.": [QUEUE_SUMMARY] Queue=$queue, free_count=".$iNumLlamadasColocar['AGENTES_LIBRES'].", free_list=[".implode(',',$iNumLlamadasColocar['AGENTES_LIBRES_LISTA'])."], elapsed_ms=".round($fElapsed,3)." | ES: Resumen de cola");

        if ($this->DEBUG) {
            $this->_log->output('DEBUG: a punto de devolver | EN: about to return: '.print_r($iNumLlamadasColocar, true));
        }
        return $iNumLlamadasColocar;
    }

    /**
     * Helper function to get status name for logging
     * Helper para obtener nombre de estado para logging
     */
    private function _getStatusName($status)
    {
        $arrStatus = array(
            AST_DEVICE_NOTINQUEUE => 'NOT_IN_QUEUE',
            AST_DEVICE_UNKNOWN    => 'UNKNOWN',
            AST_DEVICE_NOT_INUSE  => 'NOT_INUSE',
            AST_DEVICE_INUSE      => 'INUSE',
            AST_DEVICE_BUSY       => 'BUSY',
            AST_DEVICE_INVALID    => 'INVALID',
            AST_DEVICE_UNAVAILABLE=> 'UNAVAILABLE',
            AST_DEVICE_RINGING    => 'RINGING',
            AST_DEVICE_RINGINUSE  => 'RINGINUSE',
            AST_DEVICE_ONHOLD     => 'ONHOLD',
        );
        return isset($arrStatus[$status]) ? $arrStatus[$status] : 'UNKNOWN_'.$status;
    }
}

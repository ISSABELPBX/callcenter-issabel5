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
  $Id: Llamada.class.php,v 1.48 2009/03/26 13:46:58 alex Exp $ */

class ListaLlamadas implements IteratorAggregate
{
    private $_tuberia;
    private $_log;

    private $_llamadas = array();
    private $_indices = array(
        'id_llamada_saliente'   =>  array(),
        'id_llamada_entrante'   =>  array(),
        'dialstring'            =>  array(),
        'channel'               =>  array(),
        'actualchannel'         =>  array(),
        'uniqueid'              =>  array(),
        'actionid'              =>  array(),
        'auxchannel'            =>  array(),
    );
    private $_idcurrentcall_retrasado = array();

    /* Llamadas entrantes finalizadas antes de recibir su ID de inserción,
     * por uniqueid e id_queue_call_entry: el objeto Llamada mismo, con su
     * actualización final y sus grabaciones pendientes. La respuesta
     * idnewcall lleva la cola que insertó, así que el emparejamiento es
     * exacto: un insert descartado jamás entregará su actualización a la
     * fila de otra cola. */
    /* Incoming calls finalized before their insert ID arrived, keyed by
     * uniqueid and id_queue_call_entry: the Llamada object itself, with its
     * final update and pending recordings. The idnewcall reply carries the
     * queue it inserted, so the pairing is exact: a discarded insert can
     * never hand its update to another queue's row. */
    private $_finalizacionPendiente = array();

    function __construct($tuberia, $log)
    {
    	$this->_tuberia = $tuberia;
        $this->_log = $log;
    }

    function numLlamadas() { return count($this->_llamadas); }

    function nuevaLlamada($tipo_llamada)
    {
        if (!in_array($tipo_llamada, array('incoming', 'outgoing')))
            die(__METHOD__.' - tipo de llamada no implementado: '.$tipo_llamada);
    	$o = new Llamada($this, $tipo_llamada, $this->_tuberia, $this->_log);
        $this->_llamadas[] = $o;
        return $o;
    }

    function getIterator() {
        return new ArrayIterator($this->_llamadas);
    }

    function agregarIndice($sIndice, $key, Llamada $obj)
    {
        if (!isset($this->_indices[$sIndice]))
            die(__METHOD__.' - índice no implementado: '.$sIndice);
    	$this->_indices[$sIndice][$key] = $obj;
    }

    function removerIndice($sIndice, $key)
    {
        if (!isset($this->_indices[$sIndice]))
            die(__METHOD__.' - índice no implementado: '.$sIndice);
    	unset($this->_indices[$sIndice][$key]);
    }

    function buscar($sIndice, $key)
    {
        if (!isset($this->_indices[$sIndice]))
            die(__METHOD__.' - índice no implementado: '.$sIndice);
        return isset($this->_indices[$sIndice][$key])
            ? $this->_indices[$sIndice][$key] : NULL;
    }

    function remover(Llamada $obj)
    {
    	foreach (array_keys($this->_llamadas) as $k) {
    		if ($this->_llamadas[$k] === $obj) {

    		    /* Se almacena el ID de llamada (si está disponible) para toda
    		     * llamada que esté todavía esperando un ID de current_call al
    		     * momento de ser removida.
    		     * The call ID (if available) is stored for every
    		     * call that is still waiting for a current_call ID at the
    		     * time of being removed. */
    		    if ($obj->waiting_id_current_call) {
    		        $this->_idcurrentcall_retrasado[$obj->tipo_llamada][] = $obj->id_llamada;
    		    }

    		    if (!is_null($obj->finalizacionPendiente)) {
    		        $this->_finalizacionPendiente[$obj->tipo_llamada]
    		            [$obj->uniqueid][(string)$obj->id_queue_call_entry] = $obj;
    		    }

                unset($this->_llamadas[$k]);
                if ($obj->tipo_llamada == 'outgoing' && isset($this->_indices['id_llamada_saliente'][$obj->id_llamada]))
                    unset($this->_indices['id_llamada_saliente'][$obj->id_llamada]);
                if ($obj->tipo_llamada == 'incoming' && isset($this->_indices['id_llamada_entrante'][$obj->id_llamada]))
                    unset($this->_indices['id_llamada_entrante'][$obj->id_llamada]);
                if (isset($this->_indices['dialstring'][$obj->dialstring]))
                    unset($this->_indices['dialstring'][$obj->dialstring]);
                if (isset($this->_indices['channel'][$obj->channel]))
                    unset($this->_indices['channel'][$obj->channel]);
                if (isset($this->_indices['actualchannel'][$obj->actualchannel]))
                    unset($this->_indices['actualchannel'][$obj->actualchannel]);
                if (isset($this->_indices['uniqueid'][$obj->uniqueid]))
                    unset($this->_indices['uniqueid'][$obj->uniqueid]);
                if (isset($this->_indices['actionid'][$obj->actionid]))
                    unset($this->_indices['actionid'][$obj->actionid]);

                foreach (array_keys($obj->AuxChannels) as $k)
                    unset($this->_indices['auxchannel'][$k]);
    			break;
    		}
    	}
    }

    /**
     * Procedimiento que busca el tipo e ID de una llamada entre las llamadas
     * previamente removidas, para verificar si fue removida antes de que
     * supiera su ID de current_call. Si se encuentra este tipo e ID de llamada,
     * se lo quita.
     *
     * @param string    $t      Tipo de llamada
     * @param int       $id     ID de llamada
     *
     * @return  bool    VERDADERO si la llamada fue removida sin id de current_call
     */
    function remover_llamada_sin_idcurrentcall($t, $id)
    {
        if (isset($this->_idcurrentcall_retrasado[$t]) &&
            in_array($id, $this->_idcurrentcall_retrasado[$t])) {
            $this->_idcurrentcall_retrasado[$t] = array_diff($this->_idcurrentcall_retrasado[$t], array($id));
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Procedimiento que saca la llamada finalizada antes de recibir su ID
     * de inserción, para aplicarle el ID de esa misma inserción (mismo
     * uniqueid y misma cola) con aplicarIdLlamadaFinalizada().
     *
     * @param string    $t          Tipo de llamada
     * @param string    $uniqueid   Uniqueid de la llamada
     * @param int|null  $idq        id_queue_call_entry de la inserción
     *
     * @return  Llamada|null  La llamada aparcada, o NULL si no hay ninguna
     */
    function sacarFinalizacionPendiente($t, $uniqueid, $idq)
    {
        $k = (string)$idq;
        if (isset($this->_finalizacionPendiente[$t][$uniqueid][$k])) {
            $p = $this->_finalizacionPendiente[$t][$uniqueid][$k];
            unset($this->_finalizacionPendiente[$t][$uniqueid][$k]);
            if (count($this->_finalizacionPendiente[$t][$uniqueid]) == 0)
                unset($this->_finalizacionPendiente[$t][$uniqueid]);
            return $p;
        }
        return NULL;
    }

    function dump($log)
    {
        foreach ($this->_llamadas as &$llamada) $llamada->dump($log);

    }
}
?>
var module_name = 'rep_agents_monitoring';

/* El siguiente objeto es el estado de la interfaz del CallCenter. Al comparar
 * este objeto con los cambios de estado producto de eventos del ECCP, se
 * consigue detectar los cambios requeridos a la interfaz sin tener que recurrir
 * a llamadas repetidas al servidor.
 * Este objeto se inicializa en initialize_client_state() */
var estadoCliente = null;
var estadoClienteHash = null;

// Objeto de timer para el cronómetro
var timer = null;

//Objeto EventSource, si está soportado por el navegador
var evtSource = null;

// Shift filter variables (default: full day 00:00-23:59)
var shiftFromHour = 0;
var shiftToHour = 23;
var STORAGE_KEY_SHIFT_FROM = 'agents_monitoring_shift_from';
var STORAGE_KEY_SHIFT_TO = 'agents_monitoring_shift_to';

// Shift-based time tracking. The server owns the window: it sends the
// totals together with the window's two ends (shiftStartTs/shiftEndTs, ms)
// and the range label. Until the first server value arrives the bounds are
// null and the counters run unbounded, as they always did. The browser
// never re-derives the window's day rule.
var shiftStartTs = null;      // ms epoch of the window start, from the server
var shiftEndTs = null;        // ms epoch of the window end, from the server
var lastShiftFetchTs = null;  // ms epoch of this page's fetch, set at initialize
var shiftLabel = null;        // the server-built range label, if known
// Each row keeps its own fetch time, estadoCliente[k]['shift_fetch_ts'] (ms),
// set at initialize and by a poll carrying that row: the poll carries only
// the rows whose status changed, so one page-wide fetch time would set every
// other row back by the time since its own fetch.

function loadShiftPreferences() {
	var storedFrom = localStorage.getItem(STORAGE_KEY_SHIFT_FROM);
	var storedTo = localStorage.getItem(STORAGE_KEY_SHIFT_TO);

	if (storedFrom !== null) {
		shiftFromHour = parseInt(storedFrom, 10);
		if (isNaN(shiftFromHour) || shiftFromHour < 0 || shiftFromHour > 23) {
			shiftFromHour = 0;
		}
	}

	if (storedTo !== null) {
		shiftToHour = parseInt(storedTo, 10);
		if (isNaN(shiftToHour) || shiftToHour < 0 || shiftToHour > 23) {
			shiftToHour = 23;
		}
	}
}

function saveShiftPreferences() {
	localStorage.setItem(STORAGE_KEY_SHIFT_FROM, shiftFromHour);
	localStorage.setItem(STORAGE_KEY_SHIFT_TO, shiftToHour);
}

function updateShiftRangeIndicator() {
	var indicator = $('#shiftRangeIndicator');

	// The server builds the label; before the first server value only the
	// hours are shown, with no day word
	if (shiftLabel != null) {
		indicator.text(shiftLabel);
		return;
	}
	var fromStr = (shiftFromHour < 10 ? '0' : '') + shiftFromHour + ':00';
	var toStr = (shiftToHour < 10 ? '0' : '') + shiftToHour + ':59';
	indicator.text(fromStr + ' - ' + toStr);
}

function applyShiftFilter() {
	var rawFrom = $('#shiftFromHour').val();
	var rawTo = $('#shiftToHour').val();

	var newFrom = parseInt(rawFrom, 10);
	var newTo = parseInt(rawTo, 10);

	if (isNaN(newFrom) || newFrom < 0 || newFrom > 23) newFrom = 0;
	if (isNaN(newTo) || newTo < 0 || newTo > 23) newTo = 23;

	shiftFromHour = newFrom;
	shiftToHour = newTo;

	saveShiftPreferences();
	shiftLabel = null;   // the applied window's label arrives with the reload
	updateShiftRangeIndicator();

	// Close existing SSE connection before reload
	if (evtSource != null) {
		evtSource.close();
		evtSource = null;
	}

	// Reload page with shift parameters
	location.href = 'index.php?menu=' + module_name + '&shift_from=' + shiftFromHour + '&shift_to=' + shiftToHour;
}

//Redireccionar la página entera en caso de que la sesión se haya perdido
function verificar_error_session(respuesta)
{
	if (respuesta['statusResponse'] == 'ERROR_SESSION') {
		if (respuesta['error'] != null && respuesta['error'] != '')
			alert(respuesta['error']);
		window.open('index.php', '_self');
	}
}

//Inicializar estado del cliente al refrescar la página. Every argument
//after the hash may be undefined: the server owns the window, and nothing
//is done with an argument that did not arrive.
function initialize_client_state(nuevoEstado, nuevoEstadoHash, shiftStartEpochS, shiftEndEpochS, shiftFrom, shiftTo, shiftLabelS)
{
	estadoCliente = nuevoEstado;
	estadoClienteHash = nuevoEstadoHash;

	if (shiftStartEpochS !== undefined && shiftEndEpochS !== undefined) {
		shiftStartTs = shiftStartEpochS * 1000;
		shiftEndTs = shiftEndEpochS * 1000;
		lastShiftFetchTs = new Date().getTime();
	}
	if (shiftLabelS !== undefined) {
		shiftLabel = shiftLabelS;
	}

	var fechaInicio = new Date();
	var regexp = /^(queue-\d+)/;
	for (var k in estadoCliente) {
		// Every row was fetched by this initialize, so every row's fetch
		// time is this instant until a poll carrying the row replaces it
		estadoCliente[k]['shift_fetch_ts'] = fechaInicio.getTime();
		var keys = ['sec_laststatus', 'logintime', 'sec_calls', 'sec_breaks', 'sec_holds'];
		for (var j = 0; j < keys.length; j++) {
			var ktimestamp = keys[j];
			estadoCliente[k]['orig_'+ktimestamp] = estadoCliente[k][ktimestamp];
			if (estadoCliente[k][ktimestamp] != null) {
				var d = new Date();
				d.setTime(fechaInicio.getTime() - estadoCliente[k][ktimestamp] * 1000);
				estadoCliente[k][ktimestamp] = d;
			}
		}

		// Registrar a qué total de cola contribuye esta fila
		var kq = regexp.exec(k);
		estadoCliente[k]['queuetotal'] = null;
		if (kq != null) {
			estadoCliente[k]['queuetotal'] = kq[1];
		}
	}

	// Initialize shift filter UI. The server owns the filter: when the
	// browser has a stored filter that differs from the server's hours and
	// the URL carries no shift_from, one location.replace() pushes the
	// stored filter back to the server - the replaced URL carries it, so
	// this hops once. Otherwise the selects show the server's hours and
	// they are saved to localStorage.
	loadShiftPreferences();
	if (shiftFrom !== undefined && shiftTo !== undefined) {
		var storedFrom = localStorage.getItem(STORAGE_KEY_SHIFT_FROM);
		var storedTo = localStorage.getItem(STORAGE_KEY_SHIFT_TO);
		if (storedFrom !== null && storedTo !== null
			&& (shiftFromHour != shiftFrom || shiftToHour != shiftTo)
			&& location.search.indexOf('shift_from=') < 0) {
			if (evtSource != null) {
				evtSource.close();
				evtSource = null;
			}
			location.replace('index.php?menu=' + module_name
				+ '&shift_from=' + shiftFromHour + '&shift_to=' + shiftToHour);
			return;
		}
		shiftFromHour = shiftFrom;
		shiftToHour = shiftTo;
		saveShiftPreferences();
	}
	$('#shiftFromHour').val(('0' + shiftFromHour).slice(-2));
	$('#shiftToHour').val(('0' + shiftToHour).slice(-2));
	updateShiftRangeIndicator();
	$('#applyShiftFilter').on('click', applyShiftFilter);

	// Lanzar el callback que actualiza el estado de la llamada
    setTimeout(do_checkstatus, 1);

	timer = setTimeout(actualizar_cronometro, 1);
}

$(window).unload(function() {
	if (evtSource != null) {
		evtSource.close();
		evtSource = null;
	}
});

//Cada 500 ms se llama a esta función para actualizar el cronómetro
function actualizar_cronometro()
{
	actualizar_valores_cronometro();
	timer = setTimeout(actualizar_cronometro, 500);
}

function actualizar_valores_cronometro()
{
	var totalesCola = {};
	for (var k in estadoCliente) {
		var kq = estadoCliente[k]['queuetotal'];
		if (totalesCola[kq] == null) {
			// Estos totales son en milisegundos
			totalesCola[kq] = {
				logintime: 0,
				sec_calls: 0,
				sec_breaks: 0,
				sec_holds: 0
			};
		}

		// El último estado se actualiza si el tiempo no es nulo
		if (estadoCliente[k]['sec_laststatus'] != null) {
			formatoCronometro('#'+k+'-sec_laststatus', estadoCliente[k]['sec_laststatus']);
		}

		// El tiempo total de login se actualiza si el estado no es offline
		if (estadoCliente[k]['status'] != 'offline') {
			totalesCola[kq]['logintime'] += formatoShiftTimer('#'+k+'-logintime', estadoCliente[k]['orig_logintime'], estadoCliente[k]['shift_fetch_ts']) * 1000;
		} else {
			totalesCola[kq]['logintime'] += estadoCliente[k]['orig_logintime'] * 1000;
		}

		// El tiempo total de llamadas se actualiza si el estado es oncall y si
		// está activa la bandera oncallupdate
		if (estadoCliente[k]['status'] == 'oncall' && estadoCliente[k]['oncallupdate']) {
			totalesCola[kq]['sec_calls'] += formatoShiftTimer('#'+k+'-sec_calls', estadoCliente[k]['orig_sec_calls'], estadoCliente[k]['shift_fetch_ts']) * 1000;
		} else {
			totalesCola[kq]['sec_calls'] += estadoCliente[k]['orig_sec_calls'] * 1000;
		}

		// Break time increments when agent is on a break-type pause
		if (estadoCliente[k]['status'] == 'paused' && estadoCliente[k]['isbreakpause']) {
			totalesCola[kq]['sec_breaks'] += formatoShiftTimer('#'+k+'-sec_breaks', estadoCliente[k]['orig_sec_breaks'], estadoCliente[k]['shift_fetch_ts']) * 1000;
		} else {
			totalesCola[kq]['sec_breaks'] += estadoCliente[k]['orig_sec_breaks'] * 1000;
		}

		// Hold time increments when agent is on a hold-type pause
		if (estadoCliente[k]['onhold'] && estadoCliente[k]['isholdpause']) {
			totalesCola[kq]['sec_holds'] += formatoShiftTimer('#'+k+'-sec_holds', estadoCliente[k]['orig_sec_holds'], estadoCliente[k]['shift_fetch_ts']) * 1000;
		} else {
			totalesCola[kq]['sec_holds'] += estadoCliente[k]['orig_sec_holds'] * 1000;
		}
	}

	// Actualizar totales por cola
	for (var kq in totalesCola) {
		formatoMilisegundo('#'+kq+'-logintime', totalesCola[kq]['logintime']);
		formatoMilisegundo('#'+kq+'-sec_calls', totalesCola[kq]['sec_calls']);
		formatoMilisegundo('#'+kq+'-sec_breaks', totalesCola[kq]['sec_breaks']);
		formatoMilisegundo('#'+kq+'-sec_holds', totalesCola[kq]['sec_holds']);
	}
}

function formatoCronometro(selector, fechaInicio)
{
	var fechaDiff = new Date();
	var msec = fechaDiff.getTime() - fechaInicio.getTime();

	formatoMilisegundo(selector, msec);
	return msec;
}

// Render a shift stat: the server-reported seconds plus only the wall time
// between the fetch and now that falls inside the window. Past the window
// end it freezes at the boundary value; before the start it reads
// 00:00:00; with no window known yet it counts plain wall time, as before.
function formatoShiftTimer(selector, origSeconds, fetchMs) {
	var nowMs = Date.now();
	if (fetchMs === undefined) fetchMs = lastShiftFetchTs;
	if (fetchMs == null) fetchMs = nowMs;
	var clampMs = function (ms) {
		if (shiftStartTs == null || shiftEndTs == null) return ms;
		return Math.min(Math.max(ms, shiftStartTs), shiftEndTs);
	};
	var sec = origSeconds + Math.max(0, Math.floor((clampMs(nowMs) - clampMs(fetchMs)) / 1000));
	var tiempo = [0, 0, 0];
	tiempo[0] = sec % 60;
	tiempo[1] = Math.floor(sec / 60) % 60;
	tiempo[2] = Math.floor(sec / 3600);
	for (var i = 0; i < 3; i++) {
		if (tiempo[i] <= 9) tiempo[i] = "0" + tiempo[i];
	}
	$(selector).text(tiempo[2] + ':' + tiempo[1] + ':' + tiempo[0]);
	return sec;
}

function formatoMilisegundo(selector, msec)
{
	var tiempo = [0, 0, 0];
	tiempo[0] = (msec - (msec % 1000)) / 1000;
	tiempo[1] = (tiempo[0] - (tiempo[0] % 60)) / 60;
	tiempo[0] %= 60;
	tiempo[2] = (tiempo[1] - (tiempo[1] % 60)) / 60;
	tiempo[1] %= 60;
	var i = 0;
	for (i = 0; i < 3; i++) { if (tiempo[i] <= 9) tiempo[i] = "0" + tiempo[i]; }
	$(selector).text(tiempo[2] + ':' + tiempo[1] + ':' + tiempo[0]);
}

function do_checkstatus()
{
  var params = {
      menu:    module_name,
      rawmode:  'yes',
      action:    'checkStatus',
      clientstatehash: estadoClienteHash,
      shift_from: shiftFromHour,
      shift_to: shiftToHour
    };
  if (window.EventSource) {
    params['serverevents'] = true;
    evtSource = new EventSource('index.php?' + $.param(params));
    evtSource.onmessage = function(event) {
      manejarRespuestaStatus($.parseJSON(event.data));
    };
    evtSource.onerror = function(event) {
      event.target.close();
      setTimeout(function() { location.reload(); }, 3000);
    };
  } else {
    $.post('index.php?menu=' + module_name + '&rawmode=yes', params,
    function (respuesta) {
      verificar_error_session(respuesta);
      manejarRespuestaStatus(respuesta);

      // Lanzar el método de inmediato
      setTimeout(do_checkstatus, 1);
    }, 'json');
  }
}

function manejarRespuestaStatus(respuesta)
{
	var fechaInicio = new Date();
	var keys = ['sec_laststatus', 'logintime', 'sec_calls', 'sec_breaks', 'sec_holds'];

	// Intentar recargar la página en caso de error
	if (respuesta['error'] != null) {
		window.alert(respuesta['error']);
		location.reload();
		return;
	}

	for (var k in respuesta) {
		if (k == 'estadoClienteHash') {
			// Caso especial - actualizar hash de estado
			if (respuesta[k] == 'mismatch') {
				// Ha ocurrido un error y se ha perdido sincronía
				location.reload();
				return;
			} else {
				estadoClienteHash = respuesta[k];
			}
		} else if (k == 'shift_start_epoch') {
			// La ventana llega con la respuesta del servidor: solo los
			// límites, nunca un tiempo de consulta de fila
			shiftStartTs = respuesta[k] * 1000;
		} else if (k == 'shift_end_epoch') {
			shiftEndTs = respuesta[k] * 1000;
		} else if (k == 'shift_label') {
			if (respuesta[k] != null && respuesta[k] !== '') {
				shiftLabel = respuesta[k];
				updateShiftRangeIndicator();
			}
		} else if (estadoCliente[k] != null) {
			// A row's values were computed at the server at this instant,
			// so this response's time is this row's fetch time - only
			// this row's, the response carries no other
			estadoCliente[k]['shift_fetch_ts'] = fechaInicio.getTime();
			if (estadoCliente[k]['status'] != respuesta[k]['status'] ||
				estadoCliente[k]['onhold'] != respuesta[k]['onhold']) {
				// El estado del agente ha cambiado, actualizar icono
				var statuslabel = $('#'+k+'-statuslabel');
				statuslabel.empty();
				switch (respuesta[k]['status']) {
				case 'offline':
					statuslabel.text('LOGOUT'); // TODO: i18n
					break;
				case 'online':
					statuslabel.append('<img src="modules/'+module_name+'/images/ready.png" border="0" alt="'+'READY'+'"/>');
					break;
				case 'ringing':
					statuslabel.append('<img src="modules/'+module_name+'/images/agent-ringing.gif" border="0" alt="'+'RINGING'+'"/>');
					break;
				case 'oncall':
					if (respuesta[k]['onhold'])
						statuslabel.append('<img src="modules/'+module_name+'/images/hold.png" border="0" alt="'+'HOLD'+'"/>');
					else
						statuslabel.append('<img src="modules/'+module_name+'/images/call.png" border="0" alt="'+'CALL'+'"/>');
					break;
				case 'paused':
					statuslabel.append('<img src="modules/'+module_name+'/images/break.png" border="0" alt="'+'BREAK'+'"/>');
					if (respuesta[k]['onhold']) {
						statuslabel.append('<img src="modules/'+module_name+'/images/hold.png" border="0" alt="'+'HOLD'+'"/>');
					} else if (typeof respuesta[k].pausename == 'string') {
						statuslabel.append($('<span></span>').text(respuesta[k].pausename));
					}
					break;
				}
				estadoCliente[k]['status'] = respuesta[k]['status'];
				estadoCliente[k]['onhold'] = respuesta[k]['onhold'];
			}

			// Actualizar los cronómetros con los nuevos valores
			for (var j = 0; j < keys.length; j++) {
				var ktimestamp = keys[j];
				estadoCliente[k]['orig_'+ktimestamp] = respuesta[k][ktimestamp];
				if (respuesta[k][ktimestamp] == null) {
					estadoCliente[k][ktimestamp] = null;
					$('#'+k+'-'+ktimestamp).empty();
				} else {
					var d = new Date();
					d.setTime(fechaInicio.getTime() - respuesta[k][ktimestamp] * 1000);
					estadoCliente[k][ktimestamp] = d;
					if (ktimestamp == 'sec_laststatus') {
						// El último estado no es un contador de turno
						formatoCronometro('#'+k+'-'+ktimestamp, estadoCliente[k][ktimestamp]);
					} else {
						formatoShiftTimer('#'+k+'-'+ktimestamp, estadoCliente[k]['orig_'+ktimestamp], estadoCliente[k]['shift_fetch_ts']);
					}
				}
			}
			estadoCliente[k]['oncallupdate'] = respuesta[k]['oncallupdate'];
			estadoCliente[k]['isbreakpause'] = respuesta[k]['isbreakpause'];
			estadoCliente[k]['isholdpause'] = respuesta[k]['isholdpause'];
			estadoCliente[k]['num_calls'] = respuesta[k]['num_calls'];

			$('#'+k+'-num_calls').text(estadoCliente[k]['num_calls']);
		} else {
			// TODO: no se maneja todavía aparición de agente en nueva cola
		}
	}

	// Actualizar número de llamadas por cola
	var totalesCola = {};
	for (var k in estadoCliente) {
		var kq = estadoCliente[k]['queuetotal'];
		if (kq != null) {
			if (totalesCola[kq] == null) {
				totalesCola[kq] = estadoCliente[k]['num_calls'];
			} else {
				totalesCola[kq] += estadoCliente[k]['num_calls'];
			}
		}
	}
	for (var kq in totalesCola) {
		$('#'+kq+'-num_calls').text(totalesCola[kq]);
	}

	// Actualizar los totales de tiempo por cola
	actualizar_valores_cronometro();
}

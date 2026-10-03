var module_name = 'agent_console';

// Variables de mensajes internacionalizados
var schedule_call_error_msg_missing_date = '';

/* Set once the page starts leaving for another page. From then on the status
 * poll stops and nothing navigates again. A poll sent while the next page
 * loads is answered with the logged-out event, and calling window.open again
 * on it would restart that load every time. */
var leavingConsole = false;

// Leave the console for url, once.
function leaveConsole(url)
{
	if (leavingConsole) return;
	leavingConsole = true;
	window.open(url, '_self');
}

/* El siguiente objeto es el estado de la interfaz del CallCenter. Al comparar
 * este objeto con los cambios de estado producto de eventos del ECCP, se
 * consigue detectar los cambios requeridos a la interfaz sin tener que recurrir
 * a llamadas repetidas al servidor.
 * Este objeto se inicializa en initialize_client_state() */
var estadoCliente =
{
	onhold:		false,	// VERDADERO si el sistema está en hold
	// Estado de la consulta de transferencia atendida: none/ringing/answered.
	// Se reenvía al servidor en cada poll para poder resincronizar el botón
	// Hangup si se perdió un evento Consultation*.
	// EN: attended-transfer consultation state: none/ringing/answered. Echoed
	// back to the server on every poll so the Hangup button can be resynced if
	// a Consultation* event was missed.
	consultation: 'none',
	break_id:	null,	// Si != null, el ID del break en que está el agente
	calltype:	null,	// Si != null, tipo de llamada incoming/outgoing
	campaign_id:null,	// ID de la campaña a que pertenece la llamada atendida
	callid:		null,	// Si != null, ID de llamada que se está atendiendo

	// Por ahora sólo se modela si se espera o no una llamada manual
	waitingcall: false
};

/* Al cargar la página, o al recibir un evento AJAX, si timer_seconds tiene un
 * valor distinto de null, se inicia fechaInicio a la fecha y hora actual menos
 * el valor en segundos indicado por timer_seconds. En cualquier momento futuro,
 * el valor correcto del contador es la fecha actual menos la almacenada en
 * fechaInicio. */
var fechaInicio = null;
var timer = null;

// Objeto EventSource, si está soportado por el navegador
var evtSource = null;

// Shift-based time tracking variables
var shiftLoginTime = null;  // Date object for login timer
var shiftBreakTime = null;  // Date object for break timer
var shiftHoldTime = null;   // Date object for hold timer
var origShiftLogin = 0;     // Original seconds from server
var origShiftBreak = 0;
var origShiftHold = 0;
var isHoldPause = false;    // True if current pause is hold-type
var lblHangupDefault = null;      // #btn_hangup's normal label, captured at init
var lblCompleteTransfer = null;   // Set from server-side translation in agent_console.tpl
var lblCancelTransfer = null;     // Set from server-side translation in agent_console.tpl
var msgTransferBusy = null;        // Set from server-side translation in agent_console.tpl
var msgTransferNoAnswer = null;    // Set from server-side translation in agent_console.tpl
var msgTransferUnavailable = null; // Set from server-side translation in agent_console.tpl

// Shift filter variables (default: full day 00:00-23:59)
var shiftFromHour = 0;
var shiftToHour = 23;
var STORAGE_KEY_SHIFT_FROM = 'agent_console_shift_from';
var STORAGE_KEY_SHIFT_TO = 'agent_console_shift_to';

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
    var fromStr = (shiftFromHour < 10 ? '0' : '') + shiftFromHour + ':00';
    var toStr = (shiftToHour < 10 ? '0' : '') + shiftToHour + ':59';

    if (shiftFromHour > shiftToHour) {
        indicator.text('Yesterday ' + fromStr + ' - Today ' + toStr);
    } else {
        indicator.text('Today ' + fromStr + ' - ' + toStr);
    }
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
    updateShiftRangeIndicator();

    // Request updated shift times from server
    do_updateShiftTimes();
}

// Copia del URL a cargar al agregar la nueva cejilla
var jqueryui_tabs_use_refresh = true;
var externalurl = null;
var externalurl2 = null;
var externalurl3 = null;
var externalurl_title = null;

$(document).ready(function() {
    $('#issabel-callcenter-error-message').hide();
    $('#issabel-callcenter-info-message').hide();
    $('#issabel-callcenter-agendar-llamada-error-message').hide();

    if($('#onlycallback').val()==0) {
        $('#label_extension_callback').hide();
        $('#input_extension_callback').hide();
        $('#label_password_callback').hide();
        $('#input_password_callback').hide();
        // Show agent password field for Agent login mode
        $('#row_agent_password').show();
    } else {
        $('#input_callback').prop('checked', true);

	$('#input_extension').hide();
	$('#input_agent_user').hide();
	$('#label_extension').hide();
	$('#label_agent_user').hide();
	$('#callbackcheck').hide();
	// Hide agent password field for callback mode
	$('#row_agent_password').hide();
    }

    // Allow Enter key to submit login from password fields
    $('#input_agent_password, #input_password_callback').keypress(function(e) {
        if (e.which == 13) {
            e.preventDefault();
            do_login();
        }
    });

    // Prevent form submission on Enter in transfer field, trigger transfer instead
    $('#transfer_extension').keypress(function(e) {
        if (e.which == 13) {
            e.preventDefault();
            do_transfer();
            $('#issabel-callcenter-seleccion-transfer').dialog('close');
        }
    });

    // Handle transfer type radio button changes - show/hide appropriate fields
    $('input[name="transfer_type"]').change(function() {
        var transferType = $(this).val();
        if (transferType == 'agent') {
            // Show agent dropdown, hide extension input
            $('#transfer_extension_row').hide();
            $('#transfer_agent_row').show();
        } else {
            // Show extension input, hide agent dropdown
            $('#transfer_extension_row').show();
            $('#transfer_agent_row').hide();
        }
    });

    $('#btn_hangup').button();
    lblHangupDefault = $('#btn_hangup').button('option', 'label');
    $('#btn_hold').button();
    $('#btn_togglebreak').button();
    $('#btn_transfer').button();
    $('#btn_vtigercrm').button();
    $('#btn_logout').button();
    $('#btn_confirmar_contacto').button();
    $('#btn_confirmar_contacto').click(do_confirm_contact);
    $('#btn_agendar_llamada').button();
    $('#schedule_same_agent').button();
    $('#schedule_radio').buttonset();
    $('#transfer_type_radio').buttonset();
    $('#btn_guardar_formularios').button();
    $('#schedule_date').hide();
    $('#issabel-callcenter-cejillas-contenido').tabs({
        // Este evento sólo se dispara para jQueryUI < 1.9.0
        add:    function (event, ui) {
            if (externalurl != null)
                $(ui.panel).append("<iframe scrolling=\"auto\" height=\"450\" frameborder=\"0\" width=\"100%\" src=\"" + externalurl + "\" />");
            externalurl = null;
            if (externalurl2 != null)
                $(ui.panel).append("<iframe scrolling=\"auto\" height=\"450\" frameborder=\"0\" width=\"100%\" src=\"" + externalurl2 + "\" />");
            externalurl2 = null;
            if (externalurl3 != null)
                $(ui.panel).append("<iframe scrolling=\"auto\" height=\"450\" frameborder=\"0\" width=\"100%\" src=\"" + externalurl3 + "\" />");
            externalurl3 = null;
        }
    });

    // Verificar versión de jQueryUI para manejo de tabs
    // map() sobre Array no existe en IE8
    //var curr_uiversion = t_curr_uiversion.map(function(x) { return parseInt(x); });
    var curr_uiversion = [];
    var t_curr_uiversion = $.ui.version.split('.');
    for (var i = 0; i < t_curr_uiversion.length; i++) curr_uiversion[i] = parseInt(t_curr_uiversion[i]);
    var min_uiversion = [1,9,0];
    while (curr_uiversion.length > min_uiversion.length) min_uiversion.push(0);
    while (curr_uiversion.length < min_uiversion.length) curr_uiversion.push(0);
    while (curr_uiversion.length > 0) {
        var a = curr_uiversion.shift();
        var b = min_uiversion.shift();
        if (a > b) {
            jqueryui_tabs_use_refresh = true;
            break;
        }
        if (a < b) {
            jqueryui_tabs_use_refresh = false;
            break;
        }
    }

    if ($('#issabel-callcenter-llamada-paneles').length > 0) {
        $('#issabel-callcenter-llamada-paneles').layout({fxName: 'none', west: { size: 300 }});
        $('#issabel-callcenter-llamada-paneles-izq').layout({fxName: 'none', south: { size: 250 }});
    }

    // Operaciones que deben de repetirse al obtener formulario vía AJAX
    apply_form_styles();

    $('#submit_agent_login').click(do_login);
    $('#btn_logout').click(do_logout);
    $('#btn_hangup').click(do_hangup);
    $('#btn_hold').click(do_hold);

    // El siguiente código se ejecuta al hacer click en el botón de break
    $('#btn_togglebreak').click(function() {
    	if ($('#btn_togglebreak').hasClass('issabel-callcenter-boton-unbreak')) {
    		do_unbreak();
    	} else {
    		// Botón está en estado de elegir break
    		$('#issabel-callcenter-seleccion-break').dialog('open');
    	}
    });

    // Botón para guardar formularios
    $('#btn_guardar_formularios').click(do_save_forms);

    $('#btn_transfer').click(function() {
		$('#issabel-callcenter-seleccion-transfer').dialog('open');
    });
    $('#btn_agendar_llamada').click(function() {
		$('#issabel-callcenter-agendar-llamada').dialog('open');
    });

    // El siguiente código se ejecuta al presionar el botón de VTiger
    $('#btn_vtigercrm').click(function() {
    	window.open("/vtigercrm/","vtigercrm");
    });

    var fechasAgenda = $('#schedule_date_start, #schedule_date_end').datepicker({
    	minDate:		0,
    	showOn:			'both',
    	buttonImage:	'images/calendar.gif',
    	buttonImageOnly: true,
    	showButtonPanel: true,
    	dateFormat:		'yy-mm-dd',
    	onSelect:		function (selectedDate) {
    		// Al seleccionar la fecha en un calendario, el otro se restringe
    		var option = (this.id == "schedule_date_start") ? "minDate" : "maxDate",
				instance = $( this ).data( "datepicker" ),
				date = $.datepicker.parseDate(
						instance.settings.dateFormat ||
						$.datepicker._defaults.dateFormat,
						selectedDate, instance.settings );
    		fechasAgenda.not( this ).datepicker( "option", option, date );
    	}
    });
    $('#schedule_type_campaign_end').change(function() {
    	$('#schedule_date').hide();
    });
    $('#schedule_type_bydate').change(function() {
    	$('#schedule_date').show();
    });

    $('#input_callback').click(function() {
		var $this = $(this);
		// $this will contain a reference to the checkbox
		if ($this.is(':checked')) {
		    $('#input_extension').hide();
		    $('#input_agent_user').hide();
		    $('#label_extension').hide();
		    $('#label_agent_user').hide();
		    $('#row_agent_password').hide();

		    $('#label_extension_callback').show();
		    $('#input_extension_callback').show();
		    $('#label_password_callback').show();
		    $('#input_password_callback').show();

		} else {
		    $('#input_extension').show();
		    $('#input_agent_user').show();
		    $('#label_extension').show();
		    $('#label_agent_user').show();
		    $('#row_agent_password').show();

		    $('#label_extension_callback').hide();
		    $('#input_extension_callback').hide();
		    $('#label_password_callback').hide();
		    $('#input_password_callback').hide();
		}
    });
});

$(window).unload(function() {
	if (evtSource != null) {
		evtSource.close();
		evtSource = null;
	}
});

function apply_form_styles()
{
    $('#issabel-callcenter-cejillas-formulario').tabs();
    $('.issabel-callcenter-field-date').datepicker({
    	showOn:			'both',
    	buttonImage:	'images/calendar.gif',
    	buttonImageOnly: true,
    	showButtonPanel: true,
    	changeMonth:	true,
    	changeYear:		true,
    	dateFormat:		'yy-mm-dd'
    });
}

// Inicializar estado del cliente al refrescar la página
function initialize_client_state(nuevoEstado)
{
	estadoCliente.onhold = nuevoEstado.onhold;
	estadoCliente.consultation = nuevoEstado.consultation || 'none';
	estadoCliente.break_id = nuevoEstado.break_id;
	estadoCliente.calltype = nuevoEstado.calltype;
	estadoCliente.campaign_id = nuevoEstado.campaign_id;
	estadoCliente.callid = nuevoEstado.callid;
	estadoCliente.waitingcall = nuevoEstado.waitingcall;

	// Disable Transfer button if currently on hold
	if (estadoCliente.onhold) {
		$('#btn_transfer').button('disable');
	}

	// Initialize shift stat timers
	origShiftLogin = nuevoEstado.shift_login_time || 0;
	origShiftBreak = nuevoEstado.shift_break_time || 0;
	origShiftHold = nuevoEstado.shift_hold_time || 0;
	isHoldPause = nuevoEstado.is_hold_pause || false;

	var now = new Date();
	shiftLoginTime = new Date(now.getTime() - origShiftLogin * 1000);
	shiftBreakTime = new Date(now.getTime() - origShiftBreak * 1000);
	shiftHoldTime = new Date(now.getTime() - origShiftHold * 1000);

	// Initialize shift filter UI
	loadShiftPreferences();
	$('#shiftFromHour').val(('0' + shiftFromHour).slice(-2));
	$('#shiftToHour').val(('0' + shiftToHour).slice(-2));
	updateShiftRangeIndicator();
	$('#applyShiftFilter').on('click', applyShiftFilter);

	// If saved preferences differ from default (0-23), fetch correct shift data
	if (shiftFromHour !== 0 || shiftToHour !== 23) {
		setTimeout(do_updateShiftTimes, 100);
	}

	// Lanzar el callback que actualiza el estado de la llamada
    setTimeout(do_checkstatus, 1);

    iniciar_cronometro((nuevoEstado.timer_seconds !== '') ? nuevoEstado.timer_seconds : null);
    abrir_url_externo3(nuevoEstado.urlopentype3, nuevoEstado.url3, nuevoEstado.urldescription3);
    abrir_url_externo2(nuevoEstado.urlopentype2, nuevoEstado.url2, nuevoEstado.urldescription2);
    abrir_url_externo(nuevoEstado.urlopentype, nuevoEstado.url, nuevoEstado.urldescription);
    
    
}

// Inicializar el cronómetro con el valor de segundos indicado
function iniciar_cronometro(timer_seconds)
{
	// Anular el estado anterior
	if (timer != null) {
		clearTimeout(timer);
		timer = null;
	}
	fechaInicio = null;
	$('#issabel-callcenter-cronometro').text('00:00:00');

	// Iniciar el estado nuevo, si es válido
	if (timer_seconds != null) {
		fechaInicio = new Date();
		fechaInicio.setTime(fechaInicio.getTime() - timer_seconds * 1000);
	}
	// Always start the timer loop for shift stats (even when idle)
	timer = setTimeout(actualizar_cronometro, 1);
}

// Cada 500 ms se llama a esta función para actualizar el cronómetro
function actualizar_cronometro()
{
	// Update main chronometer only if active (fechaInicio is set)
	if (fechaInicio != null) {
		var fechaDiff = new Date();
		var msec = fechaDiff.getTime() - fechaInicio.getTime();
		var tiempo = [0, 0, 0];
		tiempo[0] = (msec - (msec % 1000)) / 1000;
		tiempo[1] = (tiempo[0] - (tiempo[0] % 60)) / 60;
		tiempo[0] %= 60;
		tiempo[2] = (tiempo[1] - (tiempo[1] % 60)) / 60;
		tiempo[1] %= 60;
		var i = 0;
		for (i = 0; i < 3; i++) { if (tiempo[i] <= 9) tiempo[i] = "0" + tiempo[i]; }
		$('#issabel-callcenter-cronometro').text(tiempo[2] + ':' + tiempo[1] + ':' + tiempo[0]);
	}

	// Update shift stat timers
	// Login timer always ticks when logged in
	if (shiftLoginTime != null) {
		formatoShiftTimer('#shift-stat-login', shiftLoginTime);
	}

	// Break timer ticks only when on break (and not hold-type)
	if (shiftBreakTime != null && estadoCliente.break_id != null && !isHoldPause) {
		formatoShiftTimer('#shift-stat-break', shiftBreakTime);
	}

	// Hold timer ticks only when on hold
	if (shiftHoldTime != null && (estadoCliente.onhold || isHoldPause)) {
		formatoShiftTimer('#shift-stat-hold', shiftHoldTime);
	}

	timer = setTimeout(actualizar_cronometro, 500);
}

// Format shift timer display
function formatoShiftTimer(selector, fechaInicio) {
	var fechaDiff = new Date();
	var msec = fechaDiff.getTime() - fechaInicio.getTime();
	var tiempo = [0, 0, 0];
	tiempo[0] = (msec - (msec % 1000)) / 1000;
	tiempo[1] = (tiempo[0] - (tiempo[0] % 60)) / 60;
	tiempo[0] %= 60;
	tiempo[2] = (tiempo[1] - (tiempo[1] % 60)) / 60;
	tiempo[1] %= 60;
	for (var i = 0; i < 3; i++) {
		if (tiempo[i] <= 9) tiempo[i] = "0" + tiempo[i];
	}
	$(selector).text(tiempo[2] + ':' + tiempo[1] + ':' + tiempo[0]);
}

// El siguiente código aplica estilos de jQueryUI
function apply_ui_styles(uidata)
{
    if (uidata.no_call) {
        $('#btn_hangup').button('disable');
        $('#btn_hold').button('disable');
        $('#btn_transfer').button('disable');

        /* Esta llamada generalmente se realiza cuando el agente recién carga
         * la consola y no ha recibido una llamada todavía. Se debe de modificar
         * si se requiere que el agente recargue frecuentemente la consola y
         * preserve el hecho de que atendió previamente una llamada en la misma
         * sesión. */
        $('#btn_agendar_llamada').button('disable');
    }
    if (!uidata.can_confirm_contact) {
    	$('#btn_confirmar_contacto').button('disable');
    }
    if (!uidata.can_save_formdata) {
        $('#btn_guardar_formularios').button('disable');
    }
    schedule_call_error_msg_missing_date = uidata.schedule_call_error_msg_missing_date;
    $('#issabel-callcenter-seleccion-break').dialog({
        autoOpen: false,
        width: 300,
        height: 150,
        modal: true,
        buttons: [
            {
                text: uidata['break_commit'],
                click: function() { do_break(); $(this).dialog('close'); }
            },
            {
                text: uidata['break_dismiss'],
                click: function() { $(this).dialog('close'); }
            }
        ]
    });
    $('#issabel-callcenter-seleccion-transfer').dialog({
        autoOpen: false,
        width: 600,
        height: 320,
        modal: true,
        buttons: [
            {
                text: uidata['transfer_commit'],
                click: function() { do_transfer(); $(this).dialog('close'); }
            },
            {
                text: uidata['transfer_dismiss'],
                click: function() { $(this).dialog('close'); }
            }
        ]
    });
    $('#issabel-callcenter-agendar-llamada').dialog({
        autoOpen: false,
        width: 700,
        height: 350,
        modal: true,
        buttons: [
            {
                text: uidata['schedule_commit'],
                click: function() { if (do_schedule()) { $(this).dialog('close'); }}
            },
            {
                text: uidata['schedule_dismiss'],
                click: function() { $(this).dialog('close'); }
            }
        ]
    });

    externalurl_title = uidata['external_url_tab'];
}

// Redireccionar la página entera en caso de que la sesión se haya perdido
function verificar_error_session(respuesta)
{
	if (respuesta['statusResponse'] == 'ERROR_SESSION') {
		if (respuesta['error'] != null && respuesta['error'] != '')
			alert(respuesta['error']);
		leaveConsole('index.php');
	}
}

// El siguiente código se ejecutará cuando se presione el botón de login del agente
function do_login()
{
	$.post('index.php?menu=' + module_name + '&rawmode=yes', {
        menu:		module_name,
        rawmode:	'yes',
        action:		'doLogin',
        agent:		$('#input_agent_user').val(),
        ext:		$('#input_extension').val(),
        ext_callback: 	$('#input_extension_callback').val(),
        pass_callback: 	$('#input_password_callback').val(),
        pass_agent:	$('#input_agent_password').val(),
        callback:	$('#input_callback').is(':checked')
	},
	function(respuesta) {
		verificar_error_session(respuesta);
        if (respuesta['status']) {
            // Se inicia la espera del login del agente
            login_estado_espera(respuesta['message']);
            setTimeout('do_checklogin()', 1);
        } else {
            // Ha ocurrido un error
            login_estado_error(respuesta['message']);
        }
	}, 'json')
	.fail(function() {
		login_estado_error('Failed to connect to server for agent login!');
	});
}

// El siguiente código se ejecuta al presionar el botón de colgado
function do_hangup()
{
	$('#btn_hangup').button('disable');
	$.post('index.php?menu=' + module_name + '&rawmode=yes', {
		menu:		module_name,
		rawmode:	'yes',
		action:		'hangup'
	},
	function (respuesta) {
		verificar_error_session(respuesta);
        if (respuesta['action'] == 'error') {
        	mostrar_mensaje_error(respuesta['message']);
        	if (estadoCliente.campaign_id != null || estadoCliente.waitingcall)
                $('#btn_hangup').button('enable');
        }

        // El cambio de estado de la interfaz se delega a la revisión
        // periódica del estado del agente.
	}, 'json')
	.fail(function() {
		mostrar_mensaje_error('Failed to connect to server to run request!');
	});
}

// El siguiente código se ejecuta al presionar el botón de hold
function do_hold()
{
	$('#btn_hold').button('disable');
	$.post('index.php?menu=' + module_name + '&rawmode=yes', {
		menu:		module_name,
		rawmode:	'yes',
		action:		'hold'
	},
	function (respuesta) {
		verificar_error_session(respuesta);
        if (respuesta['action'] == 'error') {
        	mostrar_mensaje_error(respuesta['message']);
        }

        // Re-enable button after response
        $('#btn_hold').button('enable');

        // El cambio de estado de la interfaz se delega a la revisión
        // periódica del estado del agente.
	}, 'json')
	.fail(function() {
		mostrar_mensaje_error('Failed to connect to server to run request!');
		$('#btn_hold').button('enable');
	});
}

// Función que verifica si se ha completado el proceso de login
function do_checklogin()
{
	$.post('index.php?menu=' + module_name + '&rawmode=yes', {
			menu:		module_name,
			rawmode:	'yes',
			action:		'checkLogin'
		},
		function (respuesta) {
			verificar_error_session(respuesta);
	        if (respuesta['action'] == 'error') {
	            // El login ha concluido con un error
	            login_estado_error(respuesta['message']);
	        }
	        if (respuesta['action'] == 'wait') {
	            // Todavía no se termina proceso login, se espera
	            setTimeout('do_checklogin()', 1);
	        }
	        if (respuesta['action'] == 'login') {
	            // Login de agente ha tenido éxito, se refresca para mostrar formulario
	            window.open('index.php?menu=' + module_name, "_self");
	        }
    	}, 'json')
    	.fail(function() {
    		login_estado_error('Failed to connect to server to check for agent login!');
    	});
}

// El siguiente código se ejecuta al presionar el botón de fin de sesión
function do_logout()
{
    $.post('index.php?menu=' + module_name + '&rawmode=yes', {
		menu:		module_name,
		rawmode:	'yes',
		action:		'agentLogout'
	},
	function (respuesta) {
		verificar_error_session(respuesta);
        if (respuesta['action'] == 'error') {
        	mostrar_mensaje_error(respuesta['message']);
        }

        // Se asume que a pesar del error, el agente está deslogoneado
        leaveConsole('index.php?menu=' + module_name);
	}, 'json')
	.fail(function() {
		mostrar_mensaje_error('Failed to connect to server to run request!');
	});
}

// Cambiar el mensaje de login al estado de espera
function login_estado_espera(msg)
{
    $('#login_icono_espera').attr("style", "visibility: visible; position: none;");
    $('#login_fila_estado').attr("style", "visibility: visible; position: none;");
    $('#login_msg_espera').text(msg);
    $('#login_msg_error').text("");
}

// Cambiar el mensaje de login al estado de error
function login_estado_error(msg)
{
    $('#login_icono_espera').attr("style", "visibility: hidden; position: absolute;");
    $('#login_fila_estado').attr("style", "visibility: visible; position: none;");
    $('#login_msg_espera').text("");
    $('#login_msg_error').text(msg);
}

// Cambiar el mensaje de login al estado ocioso
function login_estado_ocioso()
{
    $('#login_icono_espera').attr("style", "visibility: hidden; position: absolute;");
    $('#login_fila_estado').attr("style", "visibility: hidden; position: absolute;");
    $('#login_msg_espera').text("");
    $('#login_msg_error').text("");
}

function do_break()
{
	$.post('index.php?menu=' + module_name + '&rawmode=yes', {
		menu:		module_name,
		rawmode:	'yes',
		action:		'break',
		breakid:	$('#break_select').val()
	},
	function (respuesta) {
		verificar_error_session(respuesta);
        if (respuesta['action'] == 'error') {
        	mostrar_mensaje_error(respuesta['message']);
        }

        // El cambio de estado de la interfaz se delega a la revisión
        // periódica del estado del agente.
        // TODO: definir evento agentbreakenter y agentbreakexit
	}, 'json')
	.fail(function() {
		mostrar_mensaje_error('Failed to connect to server to run request!');
	});
}

function do_unbreak()
{
	// Botón está en estado de quitar break
    $.post('index.php?menu=' + module_name + '&rawmode=yes', {
		menu:		module_name,
		rawmode:	'yes',
		action:		'unbreak'
	},
	function (respuesta) {
		verificar_error_session(respuesta);
        if (respuesta['action'] == 'error') {
        	mostrar_mensaje_error(respuesta['message']);
        }

        // El cambio de estado de la interfaz se delega a la revisión
        // periódica del estado del agente.
        // TODO: definir evento agentbreakenter y agentbreakexit
	}, 'json')
	.fail(function() {
		mostrar_mensaje_error('Failed to connect to server to run request!');
	});
}

function do_transfer()
{
	// Determine transfer type
	var transferType = $('input[name="transfer_type"]:checked').val();
	var postData = {
		menu:		module_name,
		rawmode:	'yes',
		action:		'transfer',
		extension:	$('#transfer_extension').val(),
		atxfer: 	$('#transfer_type_attended').is(':checked')
	};

	// If transferring to agent, change action and parameters
	if (transferType == 'agent') {
		postData.action = 'transferagent';
		postData.target_agent = $('#transfer_agent').val();
	}

	$.post('index.php?menu=' + module_name + '&rawmode=yes', postData,
	function (respuesta) {
		verificar_error_session(respuesta);
        if (respuesta['action'] == 'error') {
        	mostrar_mensaje_error(respuesta['message']);
        } else if (respuesta['consultation']) {
        	// Attended transfer consultation started - disable Hold/Transfer buttons
        	$('#btn_hold').button('disable');
        	$('#btn_transfer').button('disable');
        	arm_consultation_watchdog();
        }

        // El cambio de estado de la interfaz se delega a la revisión
        // periódica del estado del agente.
	}, 'json')
	.fail(function() {
		mostrar_mensaje_error('Failed to connect to server to run request!');
	});
}

/* Undo the speculative Hold/Transfer disable above when the console never
 * learns that a consultation actually started.
 *
 * The dialer answers "consultation started" as soon as its AMI Redirect
 * succeeds, before it knows whether the consultation took. It marks the
 * consultation with an asynchronous message sent just before that Redirect, so
 * when the colleague device fails instantly (unregistered peer, busy) the
 * ConsultationEnd UserEvent can overtake that message, which is then discarded
 * on purpose - and ConsultationStart is never emitted. estadoCliente
 * .consultation therefore stays 'none', the server-side resync in
 * manejarSesionActiva_checkStatus() sees client and server agreeing on 'none'
 * and synthesizes no event, and the two buttons disabled just above would stay
 * disabled until the page is reloaded.
 *
 * A consultation that really started moves estadoCliente.consultation to
 * 'ringing' or 'answered' (either from the Consultation* event or from that
 * same resync), which makes this watchdog a no-op. The call and hold guards
 * mirror the ones the consultationend and holdenter handlers already use, so
 * this never re-enables a button those handlers meant to keep disabled.
 */
function arm_consultation_watchdog()
{
	setTimeout(function() {
		if (estadoCliente.consultation != 'none') return;
		if (estadoCliente.callid == null || estadoCliente.onhold) return;
		$('#btn_hold').button('enable');
		$('#btn_transfer').button('enable');
	}, 5000);
}

function do_confirm_contact()
{
	$.post('index.php?menu=' + module_name + '&rawmode=yes', {
		menu:		module_name,
		rawmode:	'yes',
		action:		'confirm_contact',
		id_contact:	$('#llamada_entrante_contacto_id').val()
	},
	function (respuesta) {
		verificar_error_session(respuesta);
        if (respuesta['action'] == 'error') {
        	mostrar_mensaje_error(respuesta['message']);
        } else {
        	mostrar_mensaje_info(respuesta['message']);
        }

	}, 'json')
	.fail(function() {
		mostrar_mensaje_error('Failed to connect to server to run request!');
	});
}

function do_schedule()
{
	// Verificar que se ha elegido realmente una fecha
	if ($('#schedule_type_bydate').is(':checked') &&
		($('#schedule_date_start').datepicker('getDate') == null || $('#schedule_date_end').datepicker('getDate') == null )) {

		$('#issabel-callcenter-agendar-llamada-error-message-text').text(schedule_call_error_msg_missing_date);
		$('#issabel-callcenter-agendar-llamada-error-message').show('slow', 'linear', function() {
			setTimeout(function() {
				$('#issabel-callcenter-agendar-llamada-error-message').fadeOut();
			}, 5000);
		});
		return false;
	}
	$.post('index.php?menu=' + module_name + '&rawmode=yes', {
		menu:		module_name,
		rawmode:	'yes',
		action:		'schedule',
		data:		{
			schedule_new_phone:		$('#schedule_new_phone').val(),
			schedule_new_name:		$('#schedule_new_name').val(),
			schedule_use_daterange:	$('#schedule_type_bydate').is(':checked'),
			schedule_use_sameagent:	$('#schedule_same_agent').is(':checked'),
			schedule_date_start:	$('#schedule_date_start').val(),	// Asume yyyy-mm-dd
			schedule_date_end:		$('#schedule_date_end').val(),		// Asume yyyy-mm-dd
			schedule_time_start:	$('#schedule_time_start_hh').val() + ':' + $('#schedule_time_start_mm').val() + ':00',
			schedule_time_end:		$('#schedule_time_end_hh').val() + ':' + $('#schedule_time_end_mm').val() + ':59'
		}
	},
	function (respuesta) {
		verificar_error_session(respuesta);
        if (respuesta['action'] == 'error') {
        	mostrar_mensaje_error(respuesta['message']);
        } else {
        	mostrar_mensaje_info(respuesta['message']);
        }

	}, 'json')
	.fail(function() {
		mostrar_mensaje_error('Failed to connect to server to run request!');
	});
	return true;
}

function do_save_forms()
{
	$.post('index.php?menu=' + module_name + '&rawmode=yes', {
		menu:		module_name,
		rawmode:	'yes',
		action:		'saveforms',
		data:		$('.issabel-callcenter-field').map(function() {
						return [[this.id, $(this).val()]];
					}).get()
	},
	function (respuesta) {
		verificar_error_session(respuesta);
        if (respuesta['action'] == 'error') {
        	mostrar_mensaje_error(respuesta['message']);
        } else {
        	mostrar_mensaje_info(respuesta['message']);
        }

	}, 'json')
	.fail(function() {
		mostrar_mensaje_error('Failed to connect to server to run request!');
	});
}

function do_ping()
{
	$.get('index.php', {menu: module_name, action: 'ping', rawmode: 'yes'}, function (respuesta) {
		verificar_error_session(respuesta);
		setTimeout(do_ping, (respuesta['gc_maxlifetime'] / 2) * 1000);
	});
}

// Request updated shift times from server with current filter
function do_updateShiftTimes()
{
    var params = {
        menu:       module_name,
        rawmode:    'yes',
        action:     'updateShiftTimes',
        shift_from: shiftFromHour,
        shift_to:   shiftToHour
    };
    $.post('index.php?menu=' + module_name + '&rawmode=yes', params,
    function (respuesta) {
        verificar_error_session(respuesta);
        if (respuesta.shift_login_time !== undefined) {
            var now = new Date();
            origShiftLogin = respuesta.shift_login_time;
            origShiftBreak = respuesta.shift_break_time;
            origShiftHold = respuesta.shift_hold_time;
            shiftLoginTime = new Date(now.getTime() - origShiftLogin * 1000);
            shiftBreakTime = new Date(now.getTime() - origShiftBreak * 1000);
            shiftHoldTime = new Date(now.getTime() - origShiftHold * 1000);
            isHoldPause = respuesta.is_hold_pause || false;
            // Update display immediately
            formatoShiftTimer('#shift-stat-login', shiftLoginTime);
            formatoShiftTimer('#shift-stat-break', shiftBreakTime);
            formatoShiftTimer('#shift-stat-hold', shiftHoldTime);
        }
    }, 'json');
}

function do_checkstatus()
{
    params = {
        menu:       module_name,
        rawmode:    'yes',
        action:     'checkStatus',
        clientstate: estadoCliente
    };
    /*if (window.EventSource) {
       params['serverevents'] = true;
        evtSource = new EventSource('index.php?' + $.param(params));
        evtSource.onmessage = function(event) {
            manejarRespuestaStatus($.parseJSON(event.data));
        }
        evtSource.onerror = function(event) {
            mostrar_mensaje_error('Lost connection to server (SSE), retrying...');
        }

        // Iniciar el ping de inmediato
        setTimeout(do_ping, 1);
    } else {*/
        $.post('index.php?menu=' + module_name + '&rawmode=yes', params,
        function (respuesta) {
            verificar_error_session(respuesta);
            manejarRespuestaStatus(respuesta);

            // Lanzar el método de inmediato
            if (!leavingConsole) setTimeout(do_checkstatus, 1);
        }, 'json').fail(function() {
            // Leaving the page aborts the poll in flight: no error, no retry.
            if (leavingConsole) return;
            mostrar_mensaje_error('Lost connection to server (Long-Polling), retrying...');
            setTimeout(do_checkstatus, 5000);
        });
    //}
}


function manejarRespuestaStatus(respuesta)
{
	for (var i in respuesta) {
		if (respuesta[i].txt_estado_agente_inicial != null)
			$('#issabel-callcenter-estado-agente-texto').text(respuesta[i].txt_estado_agente_inicial);
		if (respuesta[i].class_estado_agente_inicial != null)
			$('#issabel-callcenter-estado-agente')
				.removeClass('issabel-callcenter-class-estado-ocioso')
				.removeClass('issabel-callcenter-class-estado-break')
				.removeClass('issabel-callcenter-class-estado-activo')
				.removeClass('issabel-callcenter-class-estado-esperando')
				.removeClass('issabel-callcenter-class-estado-hold')
				.addClass(respuesta[i].class_estado_agente_inicial);
		if (respuesta[i].timer_seconds != null) {
			if (respuesta[i].timer_seconds !== '') {
				iniciar_cronometro(respuesta[i].timer_seconds);
			} else {
				iniciar_cronometro(null);
			}
		}

		// Update shift times from server response
		var fechaAhora = new Date();
		if (respuesta[i].shift_login_time !== undefined) {
			origShiftLogin = respuesta[i].shift_login_time;
			shiftLoginTime = new Date(fechaAhora.getTime() - origShiftLogin * 1000);
		}
		if (respuesta[i].shift_break_time !== undefined) {
			origShiftBreak = respuesta[i].shift_break_time;
			shiftBreakTime = new Date(fechaAhora.getTime() - origShiftBreak * 1000);
		}
		if (respuesta[i].shift_hold_time !== undefined) {
			origShiftHold = respuesta[i].shift_hold_time;
			shiftHoldTime = new Date(fechaAhora.getTime() - origShiftHold * 1000);
		}
		if (respuesta[i].is_hold_pause !== undefined) {
			isHoldPause = respuesta[i].is_hold_pause;
		}

		switch (respuesta[i].event) {
		case 'logged-out':
			// El refresco debería conducir a la página de login
			leaveConsole('index.php?menu=' + module_name);
			return;
		case 'breakenter':
			// El agente ha entrado en break
			estadoCliente.break_id = respuesta[i].break_id;
			$('#btn_togglebreak')
				.removeClass('issabel-callcenter-boton-break')
				.addClass('issabel-callcenter-boton-unbreak')
				.children('span').text(respuesta[i].txt_btn_break);
			break;
		case 'breakexit':
			// El agente ha salido del break
			estadoCliente.break_id = null;
			$('#btn_togglebreak')
				.removeClass('issabel-callcenter-boton-unbreak')
				.addClass('issabel-callcenter-boton-break')
				.children('span').text(respuesta[i].txt_btn_break);
			break;
		case 'holdenter':
			estadoCliente.onhold = true;
			// Update button text to "End Hold" and disable Transfer while on hold
			$('#btn_hold').button('option', 'label', respuesta[i].txt_btn_hold);
			$('#btn_transfer').button('disable');
			break;
		case 'holdexit':
			estadoCliente.onhold = false;
			// Update button text to "Hold" and re-enable Transfer
			$('#btn_hold').button('option', 'label', respuesta[i].txt_btn_hold);
			$('#btn_transfer').button('enable');
			break;
		case 'agentlinked':
			// El agente ha recibido una llamada
			estadoCliente.calltype = respuesta[i].calltype;
			estadoCliente.campaign_id = respuesta[i].campaign_id;
			estadoCliente.callid = respuesta[i].callid;
			$('#btn_hangup').button('enable');
			$('#btn_hold').button('enable');
			if (!estadoCliente.onhold) {
				$('#btn_transfer').button('enable');
			}
			$('#issabel-callcenter-cronometro').text(respuesta[i].cronometro);
			$('#issabel-callcenter-llamada-info')
			    .css('color', '')
				.empty()
				.append(respuesta[i].llamada_informacion);
			$('#issabel-callcenter-llamada-script')
				.empty()
				.append(respuesta[i].llamada_script);
			$('#issabel-callcenter-llamada-form')
				.empty()
				.append(respuesta[i].llamada_formulario);
			$('#llamada_entrante_contacto_telefono, #llamada_saliente_contacto_telefono')
				.text(respuesta[i].txt_contacto_telefono);
			$('#schedule_new_phone').val(respuesta[i].txt_contacto_telefono);

			// Preparar y mostrar la barra correspondiente
			if (respuesta[i].calltype == 'incoming') {
				$('#btn_confirmar_contacto').button();
				if (respuesta[i].puede_confirmar_contacto)
					$('#btn_confirmar_contacto').button('enable');
				else $('#btn_confirmar_contacto').button('disable');
				$('#btn_confirmar_contacto').click(do_confirm_contact);
			} else if (respuesta[i].calltype == 'outgoing') {
				$('#llamada_saliente_nombres').text(respuesta[i].txt_contacto_nombres);
				$('#schedule_new_name').val(respuesta[i].txt_contacto_nombres);
				$('#btn_agendar_llamada').button('enable');
			}

			apply_form_styles();
		    $('#btn_guardar_formularios').button('enable');
            if (!respuesta[i].urlopentype3){
                respuesta[i].urlopentype3 = "DELETE";
            }
            abrir_url_externo3(respuesta[i].urlopentype3, respuesta[i].url3, respuesta[i].urldescription3);

            if (!respuesta[i].urlopentype2){
                respuesta[i].urlopentype2 = "DELETE";
            }
            abrir_url_externo2(respuesta[i].urlopentype2, respuesta[i].url2, respuesta[i].urldescription2);

            if (!respuesta[i].urlopentype){
                respuesta[i].urlopentype = "DELETE";
            }
			abrir_url_externo(respuesta[i].urlopentype, respuesta[i].url, respuesta[i].urldescription);
			break;
		case 'agentunlinked':
	        // El agente se ha desconectado de la llamada
		    var l_calltype = estadoCliente.calltype;
		    var l_campaign_id = estadoCliente.campaign_id;
			estadoCliente.calltype = null;
			estadoCliente.campaign_id = null;
			estadoCliente.callid = null;

			$('#btn_hangup').button('disable');
			$('#btn_hold').button('disable');
	        $('#btn_transfer').button('disable');
	        if (l_calltype == 'incoming') {
	            $('#btn_agendar_llamada').button('disable');
	        }
	        $('#issabel-callcenter-cronometro').text('00:00:00');

	        // Vaciar las áreas para la llamada
			$('#issabel-callcenter-llamada-script').empty();
			$('#issabel-callcenter-llamada-info').css("color", "#778899");

	        // URLs marked with a "_hangup" opentype open here (the PHP backend
	        // strips the suffix so the openers receive a plain iframe/window/
	        // popup/jsonp). A null opentype is mapped to DELETE so any existing
	        // startup tab/button for that slot is removed.
	        if (!respuesta[i].urlopentype3) { respuesta[i].urlopentype3 = "DELETE"; }
	        abrir_url_externo3(respuesta[i].urlopentype3, respuesta[i].url3, respuesta[i].urldescription3);

	        if (!respuesta[i].urlopentype2) { respuesta[i].urlopentype2 = "DELETE"; }
	        abrir_url_externo2(respuesta[i].urlopentype2, respuesta[i].url2, respuesta[i].urldescription2);

	        if (!respuesta[i].urlopentype) { respuesta[i].urlopentype = "DELETE"; }
	        abrir_url_externo(respuesta[i].urlopentype, respuesta[i].url, respuesta[i].urldescription);
			break;
		case 'waitingenter':
			estadoCliente.waitingcall = true;
			break;
		case 'waitingexit':
			estadoCliente.waitingcall = false;
			break;
		case 'consultationstart':
			// Attended transfer consultation started (colleague still
			// ringing) - disable Hold/Transfer, and make clear that
			// clicking Hangup now cancels the consultation.
			estadoCliente.consultation = 'ringing';
			$('#btn_hold').button('disable');
			$('#btn_transfer').button('disable');
			if (lblCancelTransfer) {
				$('#btn_hangup').button('option', 'label', lblCancelTransfer);
			}
			$('#btn_hangup').removeClass('issabel-callcenter-boton-completar-transferencia')
				.addClass('issabel-callcenter-boton-cancelar-transferencia');
			break;
		case 'consultationanswered':
			// Colleague picked up - clicking Hangup now completes the
			// transfer (bridges colleague+customer) instead of cancelling
			// it. Make that visible on the button itself.
			estadoCliente.consultation = 'answered';
			$('#btn_hold').button('disable');
			$('#btn_transfer').button('disable');
			if (lblCompleteTransfer) {
				$('#btn_hangup').button('option', 'label', lblCompleteTransfer);
			}
			$('#btn_hangup').removeClass('issabel-callcenter-boton-cancelar-transferencia')
				.addClass('issabel-callcenter-boton-completar-transferencia');
			break;
		case 'consultationend':
			// Consultation ended - re-enable all buttons only if the agent
			// still has an active call. This covers both: agent cancelling
			// consultation (reconnects to customer) and customer hanging up
			// during consultation (buttons should stay disabled).
			// Use callid (not campaign_id) because incoming queue calls
			// without a campaign have callid set but campaign_id == null.
			estadoCliente.consultation = 'none';
			$('#btn_hangup').button('option', 'label',
				lblHangupDefault ? lblHangupDefault : $('#btn_hangup').text());
			$('#btn_hangup').removeClass('issabel-callcenter-boton-cancelar-transferencia')
				.removeClass('issabel-callcenter-boton-completar-transferencia');
			if (estadoCliente.callid != null) {
				$('#btn_hangup').button('enable');
				$('#btn_hold').button('enable');
				$('#btn_transfer').button('enable');
			}
			// If the consultation ended on its own (rather than the agent
			// cancelling it, or completing it, or the customer hanging up),
			// the dialer supplies the colleague Dial()'s DIALSTATUS - let the
			// agent know why they are back with the customer.
			switch (respuesta[i].reason) {
			case 'BUSY':
				if (msgTransferBusy) mostrar_mensaje_info(msgTransferBusy);
				break;
			case 'NOANSWER':
				if (msgTransferNoAnswer) mostrar_mensaje_info(msgTransferNoAnswer);
				break;
			case 'CONGESTION':
			case 'CHANUNAVAIL':
				if (msgTransferUnavailable) mostrar_mensaje_info(msgTransferUnavailable);
				break;
			}
			break;
		}
	}
}

function mostrar_mensaje_info(s)
{
	$('#issabel-callcenter-info-message-text').text(s);
	$('#issabel-callcenter-info-message').show('slow', 'linear', function() {
		setTimeout(function() {
			$('#issabel-callcenter-info-message').fadeOut();
		}, 5000);
	});
}

function mostrar_mensaje_error(s)
{
	$('#issabel-callcenter-error-message-text').text(s);
	$('#issabel-callcenter-error-message').show('slow', 'linear', function() {
		setTimeout(function() {
			$('#issabel-callcenter-error-message').fadeOut();
		}, 5000);
	});
}

function abrir_url_externo(urlopentype, url, title)
{
		switch (urlopentype) {
		case 'iframe':
			if (jqueryui_tabs_use_refresh) {
			    // Se quita la cejilla anterior. Se asume que se fue marcada con clase .externalurl
		    	$('#issabel-callcenter-cejillas-contenido').find('.ui-tabs-nav li.tab-externalurl').remove();
			    $('#tabs-externalurl').remove();

                if ($('#externalurl-btn').length) {
                    $('#externalurl-btn').remove();
                }

			    // Se agrega la nueva cejilla, si existe
			    if (url != null) {
			        $('#issabel-callcenter-cejillas-contenido').append(
			            '<div id="tabs-externalurl"><iframe scrolling="auto" height="450" frameborder="0" width="100%" src="' + url + '" /></div>');
			        $('<li class="tab-externalurl"><a href="#tabs-externalurl">'+title+'</a></li>')
			            .appendTo('#issabel-callcenter-cejillas-contenido > .ui-tabs-nav');
			    }

			    // Aplicar cambios
			    $('#issabel-callcenter-cejillas-contenido').tabs('refresh');
		    } else {
                externalurl = url;
                $('#issabel-callcenter-cejillas-contenido').tabs('remove', '#tabs-externalurl');
                $('#issabel-callcenter-cejillas-contenido').tabs('add', '#tabs-externalurl', title);
		    }
			break;
		case 'jsonp':
			$.ajax(url, {
				dataType: 'jsonp',
				context:	document
			});
			break;
		case 'popup':
		case 'window':
            // Se quita la cejilla anterior. Se asume que se fue marcada con la clase .tab-externalurl
            $('#issabel-callcenter-cejillas-contenido').find('.ui-tabs-nav li.tab-externalurl').remove();
            $('#tabs-externalurl').remove();

            // Eliminar cualquier botón existente antes de agregar uno nuevo
            $('#externalurl-btn').remove();

            // Se agrega la nueva cejilla, si existe
            if (url != null) {
                $('<button id="externalurl-btn" class="externalurl-btn">' + title + '</button>')
                    .appendTo('#issabel-callcenter-cejillas-contenido > .ui-tabs-nav');

                // Agregar evento de clic al botón
                $('#externalurl-btn').on('click', function () {
                    if (url) {
                        window.open(url, '_blank');
                    }
                });

                // 'popup' opentype: auto-open on call connect (original v1 behavior).
                if (urlopentype === 'popup') {
                    window.open(url, '_blank');
                }
            }
            break;
        default:
            break;
		}
}

function abrir_url_externo2(urlopentype, url2, title)
{
    if (urlopentype != null) {
        switch (urlopentype) {
        case 'iframe':
            if (jqueryui_tabs_use_refresh) {
                // Se quita la cejilla anterior. Se asume que se fue marcada con clase .externalurl
                $('#issabel-callcenter-cejillas-contenido').find('.ui-tabs-nav li.tab-externalurl2').remove();
                $('#tabs-externalurl2').remove();

                if ($('.externalurl2-btn').length) {
                    $('.externalurl2-btn').remove();
                }

                // Se agrega la nueva cejilla, si existe
                if (url2 != null) {
                    $('#issabel-callcenter-cejillas-contenido').append(
                        '<div id="tabs-externalurl2"><iframe scrolling="auto" height="450" frameborder="0" width="100%" src="' + url2 + '" /></div>');
                    $('<li class="tab-externalurl2"><a href="#tabs-externalurl2">'+title+'</a></li>')
                        .appendTo('#issabel-callcenter-cejillas-contenido > .ui-tabs-nav');
                }

                // Aplicar cambios
                $('#issabel-callcenter-cejillas-contenido').tabs('refresh');
            } else {
                externalurl2 = url2;
                $('#issabel-callcenter-cejillas-contenido').tabs('remove', '#tabs-externalurl2');
                $('#issabel-callcenter-cejillas-contenido').tabs('add', '#tabs-externalurl2', title);
            }
            break;
        case 'jsonp':
            $.ajax(url2, {
                dataType: 'jsonp',
                context:    document
            });
            break;
        case 'popup':
        case 'window':
        default:
            // Se quita la cejilla anterior. Se asume que se fue marcada con la clase .tab-externalurl
            $('#issabel-callcenter-cejillas-contenido').find('.ui-tabs-nav li.tab-externalurl2').remove();
            $('#tabs-externalurl2').remove();

            // Eliminar cualquier botón existente antes de agregar uno nuevo
            $('#externalurl2-btn').remove();

            // Se agrega la nueva cejilla, si existe
            if (url2 != null) {
                // Agregar el botón con un ID
                $('<button id="externalurl2-btn" class="externalurl-btn">' + title + '</button>')
                    .appendTo('#issabel-callcenter-cejillas-contenido > .ui-tabs-nav');

                // Agregar evento de clic al botón
                $('#externalurl2-btn').on('click', function () {
                    if (url2) {
                        window.open(url2, '_blank');
                    }
                });

                if (urlopentype === 'popup') {
                    window.open(url2, '_blank');
                }
            }
            break;
        }
    }
}

function abrir_url_externo3(urlopentype, url3, title)
{
    if (urlopentype != null) {
        switch (urlopentype) {
        case 'iframe':
            if (jqueryui_tabs_use_refresh) {
                // Se quita la cejilla anterior. Se asume que se fue marcada con clase .externalurl
                $('#issabel-callcenter-cejillas-contenido').find('.ui-tabs-nav li.tab-externalurl3').remove();
                $('#tabs-externalurl3').remove();

                if ($('.externalurl3-btn').length) {
                    $('.externalurl3-btn').remove();
                }

                // Se agrega la nueva cejilla, si existe
                if (url3 != null) {
                    $('#issabel-callcenter-cejillas-contenido').append(
                        '<div id="tabs-externalurl3"><iframe scrolling="auto" height="450" frameborder="0" width="100%" src="' + url3 + '" /></div>');
                    $('<li class="tab-externalurl3"><a href="#tabs-externalurl3">'+title+'</a></li>')
                        .appendTo('#issabel-callcenter-cejillas-contenido > .ui-tabs-nav');
                }

                // Aplicar cambios
                $('#issabel-callcenter-cejillas-contenido').tabs('refresh');
            } else {
                externalurl3 = url3;
                $('#issabel-callcenter-cejillas-contenido').tabs('remove', '#tabs-externalurl3');
                $('#issabel-callcenter-cejillas-contenido').tabs('add', '#tabs-externalurl3', title);
            }
            break;
        case 'jsonp':
            $.ajax(url3, {
                dataType: 'jsonp',
                context:    document
            });
            break;
        case 'popup':
        case 'window':
        default:
            // Se quita la cejilla anterior. Se asume que se fue marcada con la clase .tab-externalurl
            $('#issabel-callcenter-cejillas-contenido').find('.ui-tabs-nav li.tab-externalurl3').remove();
            $('#tabs-externalurl3').remove();

            // Eliminar cualquier botón existente antes de agregar uno nuevo
            $('#externalurl3-btn').remove();

            // Se agrega la nueva cejilla, si existe
            if (url3 != null) {
                $('<button id="externalurl3-btn" class="externalurl-btn">' + title + '</button>')
                    .appendTo('#issabel-callcenter-cejillas-contenido > .ui-tabs-nav');

                // Agregar evento de clic al botón
                $('#externalurl3-btn').on('click', function () {
                    if (url3) {
                        window.open(url3, '_blank');
                    }
                });

                if (urlopentype === 'popup') {
                    window.open(url3, '_blank');
                }
            }
            break;
        }
    }
}

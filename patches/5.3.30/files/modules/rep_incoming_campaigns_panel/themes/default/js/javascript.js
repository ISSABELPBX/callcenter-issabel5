var module_name = 'rep_incoming_campaigns_panel';
var estadoClienteHash = null;

// EventSource object for SSE
var evtSource = null;

// Fallback polling for browsers without EventSource
var longPoll = null;

// Shift filter variables
var shiftFromHour = 0;
var shiftToHour = 23;
var shiftLabel = null;   // the server-built range label, if known

// localStorage keys for shift preferences
var STORAGE_KEY_SHIFT_FROM = 'incoming_panel_shift_from';
var STORAGE_KEY_SHIFT_TO = 'incoming_panel_shift_to';

/**
 * Load shift preferences from localStorage
 */
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

/**
 * Save shift preferences to localStorage
 */
function saveShiftPreferences() {
    localStorage.setItem(STORAGE_KEY_SHIFT_FROM, shiftFromHour);
    localStorage.setItem(STORAGE_KEY_SHIFT_TO, shiftToHour);
}

/**
 * Update the shift range indicator text. The server builds the label;
 * before the first server value only the hours are shown, with no day word.
 */
function updateShiftRangeIndicator() {
    var indicator = $('#shiftRangeIndicator');

    if (shiftLabel != null) {
        indicator.text(shiftLabel);
        return;
    }
    var fromStr = (shiftFromHour < 10 ? '0' : '') + shiftFromHour + ':00';
    var toStr = (shiftToHour < 10 ? '0' : '') + shiftToHour + ':59';
    indicator.text(fromStr + ' - ' + toStr);
}

/**
 * Apply shift filter and reload data
 */
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
    shiftLabel = null;   // the applied window's label arrives with the next response
    updateShiftRangeIndicator();

    // Reload data with new shift parameters
    loadInitialData();
}

function verificar_error_session(respuesta) {
    if (respuesta['statusResponse'] == 'ERROR_SESSION') {
        if (respuesta['error'] != null && respuesta['error'] != '')
            alert(respuesta['error']);
        window.open('index.php', '_self');
    }
}

function formatTime(seconds) {
    var h = Math.floor(seconds / 3600);
    var m = Math.floor((seconds % 3600) / 60);
    var s = seconds % 60;
    return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
}

function updateStats(statuscount, stats) {
    // Update status counts
    if (statuscount) {
        $('#stat_total').text(statuscount.total || 0);
        $('#stat_onqueue').text(statuscount.onqueue || 0);
        $('#stat_success').text(statuscount.success || 0);
        $('#stat_abandoned').text(statuscount.abandoned || 0);
        $('#stat_finished').text(statuscount.finished || 0);
        $('#stat_losttrack').text(statuscount.losttrack || 0);
    }

    // Update duration stats
    if (stats) {
        $('#stat_maxduration').text(formatTime(stats.max_duration || 0));

        // Calculate average
        var finished = statuscount ? (statuscount.finished || statuscount.success || 0) : 0;
        var avgSec = finished > 0 ? Math.round((stats.total_sec || 0) / finished) : 0;
        $('#stat_avgduration').text(formatTime(avgSec));
    }
}

function updateActiveCalls(calls) {
    var tbody = $('#activeCallsBody');
    tbody.empty();

    if (!calls || calls.length === 0) {
        return;
    }

    for (var i = 0; i < calls.length; i++) {
        var call = calls[i];
        var row = $('<tr>')
            .append($('<td>').text(call.campaign_name || '-'))
            .append($('<td>').text(call.callstatus || '-'))
            .append($('<td>').text(call.callnumber || '-'))
            .append($('<td>').text(call.trunk || '-'))
            .append($('<td>').text(call.desde || '-'));
        tbody.append(row);
    }
}

function updateAgents(agents) {
    var tbody = $('#agentsBody');
    tbody.empty();

    if (!agents || agents.length === 0) {
        return;
    }

    for (var i = 0; i < agents.length; i++) {
        var agent = agents[i];
        var row = $('<tr>')
            .append($('<td>').text(agent.campaign_name || '-'))
            .append($('<td>').text(agent.agent || '-'))
            .append($('<td>').text(agent.status || '-'))
            .append($('<td>').text(agent.callnumber || '-'))
            .append($('<td>').text(agent.trunk || '-'))
            .append($('<td>').text(agent.desde || '-'));
        tbody.append(row);
    }
}

function manejarRespuestaStatus(respuesta) {
    verificar_error_session(respuesta);

    if (respuesta.status === 'error') {
        mostrar_mensaje_error(respuesta.message || 'Unknown error');
        return;
    }

    // Handle hash mismatch - need to reload
    if (respuesta.estadoClienteHash === 'mismatch') {
        loadInitialData();
        return;
    }

    // Update hash
    if (respuesta.estadoClienteHash) {
        estadoClienteHash = respuesta.estadoClienteHash;
    }

    // Update the server-built range label
    if (respuesta.shift_label !== undefined && respuesta.shift_label !== null
        && respuesta.shift_label !== '') {
        shiftLabel = respuesta.shift_label;
        updateShiftRangeIndicator();
    }

    // Update stats
    updateStats(respuesta.statuscount, respuesta.stats);

    // Update active calls
    updateActiveCalls(respuesta.activecalls);

    // Update agents
    updateAgents(respuesta.agents);
}

function loadInitialData() {
    // Stop any existing monitoring before reloading
    stopStatusMonitoring();

    $.ajax({
        url: 'index.php',
        data: {
            menu: module_name,
            rawmode: 'yes',
            action: 'getIncomingPanelData',
            shift_from: shiftFromHour,
            shift_to: shiftToHour
        },
        dataType: 'json',
        success: function(respuesta) {
            verificar_error_session(respuesta);

            if (respuesta.status === 'error') {
                mostrar_mensaje_error(respuesta.message);
                return;
            }

            // Store hash
            if (respuesta.estadoClienteHash) {
                estadoClienteHash = respuesta.estadoClienteHash;
            }

            // Update the server-built range label
            if (respuesta.shift_label !== undefined && respuesta.shift_label !== null
                && respuesta.shift_label !== '') {
                shiftLabel = respuesta.shift_label;
                updateShiftRangeIndicator();
            }

            // Update stats
            if (respuesta.statuscount && respuesta.statuscount.update) {
                updateStats(respuesta.statuscount.update, respuesta.stats ? respuesta.stats.update : null);
            }

            // Update active calls
            if (respuesta.activecalls && respuesta.activecalls.add) {
                updateActiveCalls(respuesta.activecalls.add);
            }

            // Update agents
            if (respuesta.agents && respuesta.agents.add) {
                updateAgents(respuesta.agents.add);
            }

            // Start SSE/polling
            startStatusMonitoring();
        },
        error: function(xhr, status, error) {
            mostrar_mensaje_error('Failed to load data: ' + error);
        }
    });
}

function startStatusMonitoring() {
    // Stop any existing connection
    stopStatusMonitoring();

    var params = {
        menu: module_name,
        rawmode: 'yes',
        action: 'checkStatus',
        clientstatehash: estadoClienteHash,
        shift_from: shiftFromHour,
        shift_to: shiftToHour
    };

    if (window.EventSource) {
        // Use Server-Sent Events
        params['serverevents'] = true;
        evtSource = new EventSource('index.php?' + $.param(params));
        evtSource.onmessage = function(event) {
            manejarRespuestaStatus($.parseJSON(event.data));
        };
        evtSource.onerror = function(event) {
            // Connection error - try to reconnect after delay
            evtSource.close();
            evtSource = null;
            setTimeout(startStatusMonitoring, 5000);
        };
    } else {
        // Fallback to long-polling
        longPoll = $.ajax({
            url: 'index.php',
            data: params,
            dataType: 'json',
            timeout: 65000,
            success: function(respuesta) {
                manejarRespuestaStatus(respuesta);
                // Continue polling
                startStatusMonitoring();
            },
            error: function(xhr, status, error) {
                if (status !== 'abort') {
                    // Retry after delay
                    setTimeout(startStatusMonitoring, 5000);
                }
            }
        });
    }
}

function stopStatusMonitoring() {
    if (evtSource) {
        evtSource.close();
        evtSource = null;
    }
    if (longPoll) {
        longPoll.abort();
        longPoll = null;
    }
}

function mostrar_mensaje_error(s) {
    $('#issabel-callcenter-error-message-text').text(s);
    $('#issabel-callcenter-error-message').show('slow', 'linear', function() {
        setTimeout(function() {
            $('#issabel-callcenter-error-message').fadeOut();
        }, 5000);
    });
}

$(document).ready(function() {
    $('#issabel-callcenter-error-message').hide();

    // Load shift preferences from localStorage
    loadShiftPreferences();

    // Set dropdown values to match loaded preferences
    $('#shiftFromHour').val((shiftFromHour < 10 ? '0' : '') + shiftFromHour);
    $('#shiftToHour').val((shiftToHour < 10 ? '0' : '') + shiftToHour);

    // Update the shift range indicator
    updateShiftRangeIndicator();

    // Bind apply button click handler
    $('#applyShiftFilter').on('click', function() {
        applyShiftFilter();
    });

    // Load initial data
    loadInitialData();

    // Cleanup on page unload
    $(window).on('unload', function() {
        stopStatusMonitoring();
    });
});

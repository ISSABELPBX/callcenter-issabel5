<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4: */

// Este punto de entrada directo fue retirado: era alcanzable sin sesión y sin
// control de acceso. Los datos de monitoreo de campañas (última llamada por
// agente, estado del teléfono) se sirven ahora desde la acción autenticada
// getAgentLastCalls del despachador de módulos de Issabel.
// This directly-served endpoint is retired: it was reachable with no session
// and no access control. Campaign monitoring data (per-agent last call, phone
// state) is now served by the authenticated getAgentLastCalls action through
// Issabel's module dispatcher.
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(array(
    'status' => 'error',
    'message' => 'This endpoint is no longer available',
));

#!/usr/bin/php
<?php
/**
 * ECCP Example: Enable/Disable Call Progress Tracking
 *
 * This example demonstrates the callprogress() method which enables or disables
 * call progress event notifications for the ECCP client.
 *
 * USAGE:
 *   ./callprogress.php [0|1]
 *
 * ARGUMENTS:
 *   0 - Disable call progress tracking
 *   1 - Enable call progress tracking
 *
 * TEST SCENARIOS:
 *
 *   1. Verify dialer is running:
 *      systemctl status issabeldialer
 *
 *   2. Enable call progress tracking:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/callprogress.php 1"
 *
 *   3. Disable call progress tracking:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/callprogress.php 0"
 *
 *   4. Monitor dialer logs during testing:
 *      tail -f /opt/issabel/dialer/dialerd.log | grep -i eccp
 *
 * PREREQUISITES: None (no agent authentication required)
 */
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 2) {
    fprintf(STDERR, $argv[0]." [0|1]\n");
    exit(0);
}

/* Credentials come from the environment, not from this file: ECCP_SERVER,
 * ECCP_USER and ECCP_PASS - the password, or its md5 hash; the login
 * accepts either. The box ships no default password, so without ECCP_PASS
 * there is nothing to connect with.
 * Las credenciales vienen del entorno, no de este archivo: ECCP_SERVER,
 * ECCP_USER y ECCP_PASS - la contrasena, o su hash md5; el login acepta
 * ambos. La instalacion no trae contrasena por omision, asi que sin
 * ECCP_PASS no hay con que conectar. */
$eccp_server = getenv('ECCP_SERVER');
$eccp_user   = getenv('ECCP_USER');
$eccp_pass   = getenv('ECCP_PASS');
if ($eccp_server === false || $eccp_server === '') $eccp_server = 'localhost';
if ($eccp_user === false || $eccp_user === '') $eccp_user = 'agentconsole';
if ($eccp_pass === false || $eccp_pass === '') {
	fwrite(STDERR, "ECCP_PASS is not set - set it to the ECCP password (or its md5 hash) of $eccp_user | Es: ECCP_PASS no esta fijada - fijela a la contrasena ECCP (o su hash md5) de $eccp_user\n");
	exit(1);
}
$x = new ECCP();
try {
    print "Connect...\n";
    $cr = $x->connect($eccp_server, $eccp_user, $eccp_pass);
    if (isset($cr->failure)) die('Failed to connect to ECCP - '.$cr->failure->message."\n");

    $enable = ($argv[1] == '1');
    print "Setting call progress to ".($enable ? 'enabled' : 'disabled')."...\n";
    $r = $x->callprogress($enable);
    print_r($r);

    print "Disconnect...\n";
    $x->disconnect();
} catch (Exception $e) {
    print_r($e);
    print_r($x->getParseError());
}
?>

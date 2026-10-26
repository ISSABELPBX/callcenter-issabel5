#!/usr/bin/php
<?php
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");
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

if (count($argv) < 2) die("Uso: {$argv[0]} campaigntype [campaignid [queue [startdate [enddate]]]]\n");

try {
    print "Connect...\n";
	$cr = $x->connect($eccp_server, $eccp_user, $eccp_pass);
	if (isset($cr->failure)) die('Failed to connect to ECCP - '.$cr->failure->message."\n");
    print_r($x->campaignlog(
        $argv[1],
        (count($argv) > 2 && trim($argv[2]) != '') ? $argv[2] : NULL,
        (count($argv) > 3 && trim($argv[3]) != '') ? $argv[3] : NULL,
        (count($argv) > 4 && trim($argv[4]) != '') ? $argv[4] : NULL,
        (count($argv) > 5 && trim($argv[5]) != '') ? $argv[5] : NULL,
        (count($argv) > 6 && trim($argv[6]) != '') ? $argv[6] : NULL,
        (count($argv) > 7 && trim($argv[7]) != '') ? $argv[7] : NULL
        ));
    print "Disconnect...\n";
    $x->disconnect();
} catch (Exception $e) {
    print_r($e);
    print_r($x->getParseError());
}
?>

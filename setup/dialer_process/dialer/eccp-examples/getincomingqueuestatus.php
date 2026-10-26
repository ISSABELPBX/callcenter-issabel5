#!/usr/bin/php
<?php
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 2) die("Use: {$argv[0]} [queue]\n");

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
	print_r($x->getincomingqueuestatus($argv[1]));
	print "Disconnect...\n";
	$x->disconnect();
} catch (Exception $e) {
	print_r($e);
	print_r($x->getParseError());
}
?>

#!/usr/bin/php
<?php
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 3) die("Use: {$argv[0]} agentchannel agentpassword [sameagent [newphone [newcontactname [campaigntype callid]]]]\n");
$agentname = $argv[1];
$agentpass = $argv[2];

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
    $x->setAgentNumber($agentname);
    $x->setAgentPass($agentpass);
    $sameagent      = (count($argv) > 3 && trim($argv[3]) != '') ? trim($argv[3]) : 0;
    $newphone       = (count($argv) > 4 && trim($argv[4]) != '') ? trim($argv[4]) : NULL;
    $newcontactname = (count($argv) > 5 && trim($argv[5]) != '') ? trim($argv[5]) : NULL;
    $campaigntype   = (count($argv) > 6 && trim($argv[6]) != '') ? trim($argv[6]) : NULL;
    $callid         = (count($argv) > 7 && trim($argv[7]) != '') ? trim($argv[7]) : NULL;

    print "Agendando llamada...\n";
    $r = $x->schedulecall(
/*
        array(
            'date_init' =>  date('Y-m-d'),
            'date_end'  =>  '2011-08-31',
            'time_init' =>  '00:00:00',
            'time_end'  =>  '23:00:01'
        ),   // schedule
*/
        NULL,
        $sameagent,
        $newphone,
        $newcontactname,
        $campaigntype,
        $callid
    );
    print_r($r);
    print "Disconnect...\n";
    $x->disconnect();
} catch (Exception $e) {
    print_r($e);
    print_r($x->getParseError());
}
?>

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
try {
    print "Connect...\n";
	$cr = $x->connect($eccp_server, $eccp_user, $eccp_pass);
	if (isset($cr->failure)) die('Failed to connect to ECCP - '.$cr->failure->message."\n");
    
    $campaign_type  = (count($argv) > 1 && trim($argv[1]) != '') ? trim($argv[1]) : NULL;
    $status         = (count($argv) > 2 && trim($argv[2]) != '') ? trim($argv[2]) : NULL;
    $filtername     = (count($argv) > 3 && trim($argv[3]) != '') ? trim($argv[3]) : NULL;
    $datetime_start = (count($argv) > 4 && trim($argv[4]) != '') ? trim($argv[4]) : NULL;
    $datetime_end   = (count($argv) > 5 && trim($argv[5]) != '') ? trim($argv[5]) : NULL;
    $offset         = (count($argv) > 6 && trim($argv[6]) != '') ? trim($argv[6]) : NULL;
    $limit          = (count($argv) > 7 && trim($argv[7]) != '') ? trim($argv[7]) : NULL;

    print "Campaign type:   ".(is_null($campaign_type) ? '(any)' : $campaign_type)."\n";
    print "Status:          ".(is_null($status) ? '(any)' : $status)."\n";
    print "Name containing: ".(is_null($filtername) ? '(any)' : $filtername)."\n";
    print "Start date:      ".(is_null($datetime_start) ? '(any)' : $datetime_start)."\n";
    print "End date:        ".(is_null($datetime_end) ? '(any)' : $datetime_end)."\n";
    print "Offset:          ".(is_null($offset) ? '(not set)' : $offset)."\n";
    print "Limit:           ".(is_null($limit) ? '(not set)' : $limit)."\n";
    
    print_r($x->getcampaignlist(
        $campaign_type,
        $status,
        $filtername,
        $datetime_start,
        $datetime_end,
        $offset,
        $limit
        ));
    print "Disconnect...\n";
    $x->disconnect();
} catch (Exception $e) {
    print_r($e);
    print_r($x->getParseError());
}
?>

#!/usr/bin/php
<?php
/**
 * ECCP Example: Get Campaign Information
 *
 * This example demonstrates the getcampaigninfo() method which retrieves
 * campaign configuration including forms, scripts, and working times.
 *
 * USAGE:
 *   ./getcampaigninfo.php [incoming|outgoing] [campaign-id]
 *
 * ARGUMENTS:
 *   incoming|outgoing - Campaign type
 *   campaign-id       - Numeric ID of the campaign
 *
 * TEST SCENARIOS:
 *
 *   1. Verify dialer is running:
 *      systemctl status issabeldialer
 *
 *   2. Get a valid campaign ID from database:
 *      mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) \
 *        -e "SELECT id, name, tipo FROM call_center.campania LIMIT 3;"
 *
 *   3. Get outgoing campaign info:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/getcampaigninfo.php outgoing 1"
 *
 *   4. Get incoming campaign info:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/getcampaigninfo.php incoming 1"
 *
 *   5. Monitor dialer logs during testing:
 *      tail -f /opt/issabel/dialer/dialerd.log | grep -i eccp
 *
 * PREREQUISITES: None (no agent authentication required)
 */
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 3) {
    fprintf(STDERR, $argv[0]." [incoming|outgoing] [campaign-id]\n");
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

    print "Getting campaign info...\n";
    $r = $x->getcampaigninfo($argv[1], $argv[2]);
    print_r($r);

    print "Disconnect...\n";
    $x->disconnect();
} catch (Exception $e) {
    print_r($e);
    print_r($x->getParseError());
}
?>

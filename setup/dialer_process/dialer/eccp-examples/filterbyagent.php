#!/usr/bin/php
<?php
/**
 * ECCP Example: Filter Events by Agent
 *
 * This example demonstrates the filterbyagent() method which filters
 * ECCP events to only receive events for a specific agent.
 *
 * USAGE:
 *   ./filterbyagent.php [agent-channel] [agent-password]
 *
 * ARGUMENTS:
 *   agent-channel   - Agent channel (e.g., Agent/9000, SIP/1001)
 *   agent-password  - Agent password
 *
 * TEST SCENARIOS:
 *
 *   1. Verify dialer is running:
 *      systemctl status issabeldialer
 *
 *   2. Get agent credentials from database:
 *      mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) \
 *        -e "SELECT number, nombre, password FROM call_center.agent WHERE status = 'A' LIMIT 3;"
 *
 *   3. Agent must be logged in first via agent console or:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/agentlogin.php Agent/9000 password 9000"
 *
 *   4. Set filter by agent:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/filterbyagent.php Agent/9000 password"
 *
 *   5. Monitor dialer logs during testing:
 *      tail -f /opt/issabel/dialer/dialerd.log | grep -i "filter\|eccp"
 *
 * PREREQUISITES:
 *   - Agent must exist in database
 *   - Agent authentication required (setAgentNumber/setAgentPass)
 */
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 3) die("Use: {$argv[0]} agentchannel agentpassword\n");

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
    print_r($x->getAgentStatus());

    print "Setting filter by agent...\n";
    $r = $x->filterbyagent();
    print_r($r);

    print "Disconnect...\n";
    $x->disconnect();
} catch (Exception $e) {
    print_r($e);
    print_r($x->getParseError());
}
?>

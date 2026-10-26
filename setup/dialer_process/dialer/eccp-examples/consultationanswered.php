#!/usr/bin/php
<?php
/**
 * ECCP Example: Attended Transfer Consultation Answered Event
 *
 * This example demonstrates the "consultationanswered" server-side event, which
 * is generated when the colleague picks up the consultation leg of an attended
 * transfer. It is the signal that lets the agent console offer to *complete*
 * the transfer, as opposed to only cancelling it.
 *
 * The answer itself happens on a physical phone and cannot be driven from ECCP,
 * so this example can only trigger the consultation (optional extension
 * argument) and then wait for the colleague to pick up.
 *
 * USAGE:
 *   ./consultationanswered.php [agent-channel] [agent-password] [extension]
 *
 * ARGUMENTS:
 *   agent-channel   - Agent channel (e.g., Agent/9000, SIP/1001)
 *   agent-password  - Agent password
 *   extension       - OPTIONAL. Colleague extension to consult (e.g., 9001).
 *                     When omitted the example only listens, and the attended
 *                     transfer is started from the agent console instead.
 *
 * EVENT PAYLOAD:
 *   <event><consultationanswered><agent_number>Agent/9000</agent_number>
 *   </consultationanswered></event>
 *
 * TEST SCENARIOS:
 *
 *   1. Verify dialer is running:
 *      systemctl status issabeldialer
 *
 *   2. Trigger the consultation and wait for the colleague to answer:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/consultationanswered.php Agent/9000 password 9001"
 *      Then answer the phone at extension 9001.
 *
 *   3. Listen only, starting the transfer from the agent console:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/consultationanswered.php Agent/9000 password"
 *
 *   4. If the colleague never answers, the consultation ends instead and the
 *      example reports the consultationend reason (NOANSWER, BUSY, ...).
 *
 *   5. Monitor dialer logs during testing:
 *      tail -f /opt/issabel/dialer/dialerd.log | grep -iE "ConsultationAnswered|consulta"
 *
 * PREREQUISITES:
 *   - Agent must be logged in (see agentlogin.php)
 *   - Agent must be on an active call to transfer
 */
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 3) die("Use: {$argv[0]} agentchannel agentpassword [extension]\n");
$agentname = $argv[1];
$agentpass = $argv[2];
$extension = isset($argv[3]) ? $argv[3] : NULL;

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
	$bListo = FALSE;
	if (!is_null($extension)) {
		print "Iniciando consulta de transferencia atendida... | EN: Starting attended transfer consultation...\n";
		$r = $x->atxfercall($extension);
		print_r($r);
		if (isset($r->failure)) $bListo = TRUE;
	} else {
		print "Esperando la consulta iniciada desde la consola... | EN: Waiting for a consultation started from the console...\n";
	}
	while (!$bListo) {
		$x->wait_response(1);
		while ($e = $x->getEvent()) {
			print_r($e);
			foreach ($e->children() as $ee) $evt = $ee;
			if ($evt->getName() == 'consultationanswered') {
				print "Colleague answered for agent ".$evt->agent_number.
					" - the transfer can now be completed\n";
				$bListo = TRUE;
				break;
			}
			/* La consulta puede terminar sin que el colega conteste nunca. */
			/* EN: The consultation can end without the colleague ever answering. */
			if ($evt->getName() == 'consultationend') {
				print "Consultation ended without an answer, reason: ".
					(isset($evt->reason) ? $evt->reason : '(none)')."\n";
				$bListo = TRUE;
				break;
			}
		}
	}
	print "Disconnect...\n";
	$x->disconnect();
} catch (Exception $e) {
	print_r($e);
	print_r($x->getParseError());
}
?>

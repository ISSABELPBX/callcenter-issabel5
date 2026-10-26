#!/usr/bin/php
<?php
/**
 * ECCP Example: Attended Transfer Consultation End Event
 *
 * This example demonstrates the "consultationend" server-side event, generated
 * when the consultation leg of an attended transfer ends, and in particular its
 * optional <reason> child:
 *
 *   - PRESENT, carrying the ${DIALSTATUS} of the consultation Dial() - BUSY,
 *     NOANSWER, CONGESTION, CHANUNAVAIL... - when the consultation ended on its
 *     own, without the agent doing anything.
 *   - ABSENT for every other way it can end: the agent cancels the transfer,
 *     completes it, or a channel hangs up.
 *
 * Both shapes are reachable from here:
 *
 *   - Without the extension argument the example only listens, so a naturally
 *     failing consultation (started from the agent console) shows the reason.
 *   - With the extension argument it starts the consultation with atxfercall()
 *     and cancels it with hangup() as soon as the colleague starts ringing,
 *     producing a consultationend with no reason.
 *
 * USAGE:
 *   ./consultationend.php [agent-channel] [agent-password] [extension]
 *
 * ARGUMENTS:
 *   agent-channel   - Agent channel (e.g., Agent/9000, SIP/1001)
 *   agent-password  - Agent password
 *   extension       - OPTIONAL. Colleague extension to consult (e.g., 9001).
 *                     When given, the consultation is started and then
 *                     cancelled to demonstrate the reason-less event.
 *
 * EVENT PAYLOAD:
 *   <event><consultationend><agent_number>Agent/9000</agent_number>
 *   <reason>BUSY</reason></consultationend></event>
 *
 * TEST SCENARIOS:
 *
 *   1. Verify dialer is running:
 *      systemctl status issabeldialer
 *
 *   2. Reason present - listen only, and start an attended transfer from the
 *      agent console towards an extension that is busy or will not answer:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/consultationend.php Agent/9000 password"
 *
 *   3. Reason absent - start and cancel the consultation in one command:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/consultationend.php Agent/9000 password 9001"
 *
 *   4. Monitor dialer logs during testing:
 *      tail -f /opt/issabel/dialer/dialerd.log | grep -iE "ConsultationEnd|CANCELAR CONSULTA"
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
		print "Esperando el fin de una consulta iniciada desde la consola... | EN: Waiting for the end of a consultation started from the console...\n";
	}
	while (!$bListo) {
		$x->wait_response(1);
		while ($e = $x->getEvent()) {
			print_r($e);
			foreach ($e->children() as $ee) $evt = $ee;
			/* El hangup del agente mientras el colega sigue timbrando cancela
			 * la consulta, y el ConsultationEnd resultante no lleva reason. */
			/* EN: The agent hanging up while the colleague is still ringing
			 * cancels the consultation, and the resulting ConsultationEnd
			 * carries no reason. */
			if ($evt->getName() == 'consultationstart' && !is_null($extension)) {
				print "Cancelando la consulta... | EN: Cancelling the consultation...\n";
				print_r($x->hangup());
				continue;
			}
			if ($evt->getName() == 'consultationend') {
				print "Consultation ended for agent ".$evt->agent_number.
					", reason: ".(isset($evt->reason)
						? $evt->reason
						: '(none - cancelled or completed)')."\n";
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

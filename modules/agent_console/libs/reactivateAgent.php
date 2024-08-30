<?php


$agent = $argv[1];
$number = $argv[2];

reactiveAgent($agent, $number);

function reactiveAgent($agent, $number) {
    if (is_readable("/etc/amportal.conf")) {
        $amp_conf = amportal_conf("/etc/amportal.conf");
        $amiHost = $amp_conf['ASTMANAGERHOST'];
        $amiPort = $amp_conf['ASTMANAGERPORT'];
        $amiUser = $amp_conf['AMPMGRUSER'];
        $amiPass = $amp_conf['AMPMGRPASS'];

        $oSocket = fsockopen($amiHost, $amiPort, $errnum, $errdesc) or die("Connection to host failed AMI.php");

        // Login al AMI
        fputs($oSocket, "Action: Login\r\n");
        fputs($oSocket, "Username: $amiUser\r\n"); // Corregir a 'Username' en lugar de 'UserName'
        fputs($oSocket, "Secret: $amiPass\r\n\r\n");

        fputs($oSocket, "Action: Originate\r\n");
        fputs($oSocket, "Channel: $agent\r\n"); // PJSIP/1000
        fputs($oSocket, "Exten: $number\r\n");  // 3006
        fputs($oSocket, "Context: from-internal\r\n");
        fputs($oSocket, "Priority: 1\r\n");
        fputs($oSocket, "CallerID: END BREAK\r\n"); // 1000
        fputs($oSocket, "Timeout: 100\r\n\r\n");

        // Leer respuesta de llamada
        while ($line = fgets($oSocket, 4096)) {
            echo $line;
            if (strpos($line, 'Response: Success') !== false) {
                echo "Call successfully sent.\n";
                break;
            }
            if (strpos($line, 'Response: Error') !== false) {
                echo "Failed to send call.\n";
                break;
            }
        }

        // Logoff
        fputs($oSocket, "Action: Logoff\r\n\r\n");

        fclose($oSocket);
    }
}

function amportal_conf($filename) {
    $file = file($filename);
    if (is_array($file)) {
        foreach ($file as $line) {
            if (preg_match("/^\s*([^=]*)\s*=\s*[\"']?([\w\/\:\.\,\}\{\>\<\(\)\*\?\%!=\+\#@&\\$-]*)[\"']?\s*([;].*)?/", $line, $matches)) {
                if (preg_match('/\$amp_conf/', $matches[1])) {
                    $matches[1] = preg_replace('/\$amp_conf\[\'/', '', $matches[1]);
                    $matches[1] = preg_replace('/\$amp_conf\["/', '', $matches[1]);
                    $matches[1] = trim($matches[1]);
                    $matches[1] = substr($matches[1], 0, -2);
                }
                $matches[1] = trim($matches[1]);
                $conf[$matches[1]] = trim($matches[2]);
            }
        }
    } else {
        die("<h1>" . sprintf("Missing or unreadable config file (%s)...cannot continue", $filename) . "</h1>");
    }
    return $conf;
}


?>
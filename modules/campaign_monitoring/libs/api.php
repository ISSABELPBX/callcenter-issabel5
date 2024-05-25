<?php


if (is_readable("/etc/amportal.conf")) {
    $amp_conf 	= amportal_conf("/etc/amportal.conf");
    $DBHOST     = $amp_conf['AMPDBHOST'];
    $DBNAME     = "call_center";
    $DBUSER     = $amp_conf['AMPDBNAME'];
    $DBPASS     = $amp_conf['AMPDBNAME'];


$conn = new mysqli($DBHOST, $DBUSER, $DBPASS, $DBNAME);

// Verificar la conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Obtener el parámetro id_campaign de la URL
    if (isset($_GET['id_campaignIncoming'])){

        $id_campaign = $_GET['id_campaignIncoming'];

        // Preparar la consulta SQL con un marcador de posición para evitar la inyección de SQL
        $sql = "SELECT
                    /*ce.id_agent,*/
                    CONCAT(a.type, '/', a.number) AS agent,
                    MAX(ce.datetime_end) AS lastCall,
                    ce.id_campaign
                FROM
                    call_entry ce
                JOIN
                    agent a ON ce.id_agent = a.id
                WHERE
                    ce.id_campaign = ?
                GROUP BY
                    ce.id_agent, a.type, a.number, ce.id_campaign";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_campaign);
        $stmt->execute();

        // Obtener resultados y almacenarlos en un array asociativo
        $stmt->bind_result($agent, $lastCall, $id_campaign);
        $listaLastCall = array();

        while ($stmt->fetch()) {
            $listaLastCall[] = array(
                'agent' => $agent,
                'lastCall' => $lastCall,
                'id_campaign' => $id_campaign,
            );
        }


        // Cerrar la conexión
        $stmt->close();
        $conn->close();

        $queueNumber        = $_GET['queue'];
        $command            = "asterisk -rx 'queue show $queueNumber' | grep 'Unavailable' | awk -F'[()]' '{print $2}'";
        $resultUnavailable  = shell_exec($command);

        $lines = explode("\n", trim($resultUnavailable));
        // Elimina líneas vacías
        $lines = array_filter($lines);
        $unavailables = array();
        foreach ($lines as $line) {
        // Almacena la información en el array con la clave "agent"
            $unavailables[] = array(
                'agent' => trim($line),
            );
        }

        $data['listaLastCall'] = $listaLastCall;
        $data['unavailables'] = $unavailables;
        // Devolver el resultado como JSON
        header('Content-Type: application/json');
        echo json_encode($data);

    }

    if (isset($_GET['id_campaignOutgoing'])){

        $id_campaign = $_GET['id_campaignOutgoing'];

        // Preparar la consulta SQL con un marcador de posición para evitar la inyección de SQL
        $sql = "SELECT
                    /*c.id_agent,*/
                    CONCAT(a.type, '/', a.number) AS agent,
                    MAX(c.end_time) AS lastCall,
                    c.id_campaign
                FROM
                    calls c
                JOIN
                    agent a ON c.id_agent = a.id
                WHERE
                    c.id_campaign = ?
                GROUP BY
                    c.id_agent, a.type, a.number, c.id_campaign";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_campaign);
        $stmt->execute();

        // Obtener resultados y almacenarlos en un array asociativo
        $result = $stmt->get_result();
        $listaLastCall = array();

        while ($row = $result->fetch_assoc()) {
            $listaLastCall[] = $row;
        }

        // Cerrar la conexión
        $stmt->close();
        $conn->close();

        $queueNumber        = $_GET['queue'];
        $command            = "asterisk -rx 'queue show $queueNumber' | grep 'Unavailable' | awk -F'[()]' '{print $2}'";
        $resultUnavailable  = shell_exec($command);

        $lines = explode("\n", trim($resultUnavailable));
        // Elimina líneas vacías
        $lines = array_filter($lines);
        $unavailables = array();
        foreach ($lines as $line) {
         // Almacena la información en el array con la clave "agent"
            $unavailables[] = array(
                'agent' => trim($line),
            );
        }

        $data['listaLastCall'] = $listaLastCall;
        $data['unavailables'] = $unavailables;
        // Devolver el resultado como JSON
        header('Content-Type: application/json');
        echo json_encode($data);

    }

}



function amportal_conf($filename) {

    $file = file($filename);
    if (is_array($file)) {
        foreach ($file as $line) {
            if (preg_match("/^\s*([^=]*)\s*=\s*[\"']?([\w\/\:\.\,\}\{\>\<\(\)\*\?\%!=\+\#@&\\$-]*)[\"']?\s*([;].*)?/",$line,$matches)) {
                if(preg_match('/\$amp_conf/',$matches[1])) {
                    $matches[1] = preg_replace('/\$amp_conf\[\'/','',$matches[1]);
                    $matches[1] = preg_replace('/\$amp_conf\["/','',$matches[1]);
                    $matches[1] = trim($matches[1]);
                    $matches[1] = substr($matches[1],0,-2);
                }
                $matches[1] = trim($matches[1]);
                $conf[ $matches[1] ] = trim($matches[2]);
            }
        }
    } else {
        die("<h1>".sprintf("Missing or unreadable config file (%s)...cannot continue", $filename)."</h1>");
    }
    return $conf;
}


?>
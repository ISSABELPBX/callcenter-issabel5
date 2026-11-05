<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 0.5                                                  |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | The contents of this file are subject to the General Public License  |
  | (GPL) Version 2 (the "License"); you may not use this file except in |
  | compliance with the License. You may obtain a copy of the License at |
  | http://www.opensource.org/licenses/gpl-license.php                   |
  |                                                                      |
  | Software distributed under the License is distributed on an "AS IS"  |
  | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
  | the License for the specific language governing rights and           |
  | limitations under the License.                                       |
  +----------------------------------------------------------------------+
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
*/
include_once("libs/paloSantoDB.class.php");

class paloSantoUploadFile
{
	private $_DB;
	var $errMsg;
    private $_numInserciones;
    private $_numActualizaciones;
	
	function __construct(&$pDB)
	{
        // Se recibe como parámetro una referencia a una conexión paloDB
        // EN: A reference to a paloDB connection is received as a parameter
        if (is_object($pDB)) {
            $this->_DB =& $pDB;
            $this->errMsg = $this->_DB->errMsg;
        } else {
            $dsn = (string)$pDB;
            $this->_DB = new paloDB($dsn);

            if (!$this->_DB->connStatus) {
                $this->errMsg = $this->_DB->errMsg;
                // debo llenar alguna variable de error
                // EN: I must fill some error variable
            } else {
                // debo llenar alguna variable de error
                // EN: I must fill some error variable
            }
        }
        $this->_numInserciones = 0;
        $this->_numActualizaciones = 0;
	}

    /**
     * Procedimiento para agregar los números de teléfono indicados por la
     * ruta de archivo indicada a la tabla de contactos. Si el número de
     * cédula ya existe en el sistema, su información se sobreescribe con la
     * información presente en el archivo.
     * EN: Procedure to add phone numbers indicated by file path to the contacts
     * EN: table. If the ID number already exists in the system, its information
     * EN: is overwritten with the information present in the file.
     *
     * Esta función está construida en base a parseCampaignNumbers() y
     * EN: This function is built based on parseCampaignNumbers() and
     * addCampaignNumbers()
     * EN: addCampaignNumbers()
     *
     * @param	int		$idCampaign	ID de la campaña a modificar
     *                              EN: Campaign ID to modify
     * @param	string	$sFilePath	Archivo local a leer para los números
     *                              EN: Local file to read for numbers
     *
     * @return bool		VERDADERO si éxito, FALSO si ocurre un error
     *                      EN: TRUE on success, FALSE if an error occurs
     */
    function addCampaignNumbersFromFile($sFilePath, &$sEncoding)
    {
    	$bExito = FALSE;
    	
    	$listaNumeros = $this->parseCampaignNumbers($sFilePath, $sEncoding); 
    	if (is_array($listaNumeros)) {
    		$bExito = $this->addCampaignNumbers($listaNumeros);
    	}
    	return $bExito;
    }

    /**
     * Procedimiento que carga un archivo CSV con números y parámetros en memoria
     * y devuelve la matriz de datos obtenida. El formato del archivo es CSV,
     * con campos separados por comas. La primera columna contiene el número
     * telefónico, el cual consiste de cualquier cadena numérica. El resto de
     * columnas contienen parámetros que se agregan como campos adicionales. Las
     * líneas vacías se ignoran, al igual que las líneas que empiecen con #
     * EN: Procedure that loads a CSV file with numbers and parameters into memory
     * EN: and returns the obtained data matrix. The file format is CSV, with
     * EN: comma-separated fields. The first column contains the phone number,
     * EN: which consists of any numeric string. The rest of columns contain
     * EN: parameters that are added as additional fields. Empty lines are ignored,
     * EN: as well as lines starting with #
     *
     * @param	string	$sFilePath	Archivo local a leer para la lista
     *                              EN: Local file to read for the list
     * @param   string  $sEncoding  (SALIDA) Codificación detectada para archivo
     *                              EN: (OUTPUT) Detected encoding for file
     *
     * @return	mixed	Matriz cuyas tuplas contienen los contenidos del archivo,
     *                  en el orden en que fueron leídos, o NULL en caso de error.
     *                  EN: Matrix whose tuples contain file contents, in the order
     *                  EN: they were read, or NULL on error.
     */
    private function parseCampaignNumbers($sFilePath, &$sEncoding)
    {
    	$listaNumeros = NULL;

        // Detectar codificación para procesar siempre como UTF-8 (bug #325)
        // EN: Detect encoding to always process as UTF-8 (bug #325)
        $sEncoding = $this->_adivinarCharsetArchivo($sFilePath);    	

    	$hArchivo = fopen($sFilePath, 'rt');
    	if (!$hArchivo) {
    		$this->errMsg = _tr("Invalid CSV File");//'No se puede abrir archivo especificado para leer CSV';
    	} else {
    		$iNumLinea = 0;
    		$listaNumeros = array();
    		$clavesColumnas = array();
    		while ($tupla = fgetcsv($hArchivo, 2048,",")) {
    			$iNumLinea++;
    			foreach ($tupla as $k => $v) $tupla[$k] = mb_convert_encoding($tupla[$k], "UTF-8", $sEncoding);
                $tupla[0] = trim($tupla[0]);
    			if (count($tupla) == 1 && trim($tupla[0]) == '') {
    				// Línea vacía
    				// EN: Empty line
    			} elseif (strlen($tupla[0]) > 0 && $tupla[0][0] == '#') {
    				// Línea que empieza por numeral
    				// EN: Line starting with hash mark
    			} elseif (!preg_match('/^[0-9#*]+$/', $tupla[0])) {
                    if ($iNumLinea == 1) {
                        // Podría ser una cabecera de nombres de columnas
                        // EN: Could be a column name header
                        array_shift($tupla);
                        $clavesColumnas = $tupla;
                    } else {
                        // Teléfono no es numérico
                        // EN: Phone is not numeric
                        $this->errMsg =  _tr('Line').' '.$iNumLinea.' - '._tr("Invalid phone number").': '.$tupla[0];
                        return NULL;
                    }
    			} else {
                    // Como efecto colateral, $tupla pierde su primer elemento
                    $tuplaLista = array(
                        'POSICION'  => $iNumLinea,
                        'NUMERO'    =>  array_shift($tupla),
                        'ATRIBUTOS' =>  array(),
                    );
                    for ($i = 0; $i < count($tupla); $i++) {
                    	$tuplaLista['ATRIBUTOS'][$i + 1] = array(
                            'CLAVE' =>  ($i < count($clavesColumnas) && $clavesColumnas[$i] != '') ? $clavesColumnas[$i] : ($i + 1),
                            'VALOR' =>  $tupla[$i],
                        );
                    }
  					$listaNumeros[] = $tuplaLista;
    			}
    		}
    		fclose($hArchivo);
    	}
    	return $listaNumeros;
    }

    // Función que intenta adivinar la codificación de caracteres del archivo
    // EN: Function that tries to guess file character encoding
    private function _adivinarCharsetArchivo($sFilePath)
    {
        // Agregar a lista para detectar más encodings. ISO-8859-15 debe estar
        // al último porque toda cadena de texto es válida como ISO-8859-15.
        // EN: Add to list to detect more encodings. ISO-8859-15 must be last
        // EN: because any text string is valid as ISO-8859-15.
        $listaEncodings = array(
            "ASCII",
            "UTF-8",
            //"EUC-JP",
            //"SJIS",
            //"JIS",
            //"ISO-2022-JP",
            "ISO-8859-15"
        );
        $sContenido = file_get_contents($sFilePath);
        $sEncoding = mb_detect_encoding($sContenido, $listaEncodings);
        return $sEncoding;
    }
    
    /**
     * Procedimiento que agrega números a una campaña existente. La lista de
     * números consiste en un arreglo de tuplas, cuyo elemento NUMERO es el
     * número de teléfono, el elemento POSICION contiene el número de línea del
     * cual procece la información, y el resto de claves es el conjunto
     * clave->valor a guardar en la tabla call_attribute para cada llamada.
     * EN: Procedure that adds numbers to an existing campaign. The list of numbers
     * EN: consists of an array of tuples, whose NUMERO element is the phone number,
     * EN: the POSICION element contains the line number from which the information
     * EN: comes, and the rest of keys is the key->value set to save in the
     * EN: call_attribute table for each call.
     *
     * Actualmente se rechazan cabeceras de columnas, para insertar, a pesar
     * de que se pueden parsear, porque el esquema de base de datos no tiene
     * la capacidad de guardar atributos arbitrarios del contacto (véase tabla
     * 'contact'). Sólo se aceptan claves numéricas. Se asume que el valor de
     * clave 1 es CÉDULA, el valor 2 es NOMBRE, y el valor 3 es APELLIDO. La
     * información se considera única con respeto a CÉDULA.
     * EN: Currently column headers are rejected for insertion, even though they
     * EN: can be parsed, because the database schema does not have the capacity
     * EN: to store arbitrary contact attributes (see table 'contact'). Only
     * EN: numeric keys are accepted. It is assumed that key value 1 is CÉDULA,
     * EN: value 2 is NOMBRE, and value 3 is APELLIDO. The information is
     * EN: considered unique with respect to CÉDULA.
     *
     * @param int $idCampaign   ID de Campaña
     *                          EN: Campaign ID
     * @param array $listaNumeros   Lista de números como se describe arriba
     *                              EN: List of numbers as described above
     *      array('NUMERO' => '1234567',
     *          'POSICION' => 45,
     *          ATRIBUTOS => array(
     *              array( CLAVE => 1, VALOR => 0911111111),
     *              array( CLAVE => 2, VALOR => Fulano),
     *              array( CLAVE => 3, VALOR => De Tal),
     *          ))
     *
     * @return bool VERDADERO si todos los números fueron insertados, FALSO en error
     *              EN: TRUE if all numbers were inserted, FALSE on error
     */
    private function addCampaignNumbers($listaNumeros)
    {
        if (!is_array($listaNumeros)) {
            $this->errMsg = '(internal) '._tr('Number list must be an array');
            return FALSE;
    	} else {
            $this->_numInserciones = 0;
            $this->_numActualizaciones = 0;
            $this->errMsg = '';

    	    // Realizar inserción de número y atributos
    	    // EN: Perform number and attribute insertion
    	    $bValido = TRUE;
    	    foreach ($listaNumeros as $tuplaNumero) {
                $sCedula = NULL;
                $sNombre = NULL;
                $sApellido = NULL;
                
                // Recolectar los atributos y rechazar los atributos desconocidos
                // EN: Collect attributes and reject unknown attributes
                foreach ($tuplaNumero['ATRIBUTOS'] as $atributo) {
                    if ($atributo['CLAVE'] == 1) 
                        $sCedula = $atributo['VALOR'];
                    elseif ($atributo['CLAVE'] == 2)
                        $sNombre = $atributo['VALOR'];
                    elseif ($atributo['CLAVE'] == 3)
                        $sApellido = $atributo['VALOR'];
                    else {
                        $this->errMsg = _tr('Line').' '.$tuplaNumero['POSICION'].' - '._tr('Unsupported attribute').': '.$atributo['CLAVE'];
                        $bValido = FALSE;
                        break;
                    }
                }
                // Detener la validación en el primer error para no sobrescribir el mensaje
                // EN: Stop at the first error so the message is not overwritten
                if (!$bValido) break;

    	        // Validar que la CÉDULA sea una cadena numérica
    	        // EN: Validate that CÉDULA is a numeric string
    	        if (!preg_match('/^\d+$/', $sCedula)) {
    	            $this->errMsg = _tr('Line').' '.$tuplaNumero['POSICION'].' - '._tr('Invalid Cedula/RUC').': '.$sCedula;
    	            $bValido = FALSE;
                    break;
    	        }
    	        if (is_null($sNombre) || $sNombre == '') {
    	            $this->errMsg = _tr('Line').' '.$tuplaNumero['POSICION'].' - '._tr('Missing or empty name').': '.$sCedula;
    	            $bValido = FALSE;
                    break;
    	        }
    	        if (is_null($sApellido) || $sApellido == '') {
    	            $this->errMsg = _tr('Line').' '.$tuplaNumero['POSICION'].' - '._tr('Missing or empty surname').': '.$sCedula;
    	            $bValido = FALSE;
                    break;
    	        }
    	    }
    	    
    	    if ($bValido) {
        	    $sOrigen = 'file'; // Fuente de los datos insertados // EN: Source of inserted data
        	    $sPeticionSQL_Cedula = 'SELECT id FROM contact WHERE cedula_ruc = ?';
    	    
                // Insertar cada uno de los valores ya validados
                // EN: Insert each of the validated values
        	    foreach ($listaNumeros as $tuplaNumero) {
                    $sCedula = NULL;
                    $sNombre = NULL;
                    $sApellido = NULL;
                    
                    foreach ($tuplaNumero['ATRIBUTOS'] as $atributo) {
                        if ($atributo['CLAVE'] == 1) $sCedula = $atributo['VALOR'];
                        elseif ($atributo['CLAVE'] == 2) $sNombre = $atributo['VALOR'];
                        elseif ($atributo['CLAVE'] == 3) $sApellido = $atributo['VALOR'];
                    }
                    $r = $this->_DB->getFirstRowQuery($sPeticionSQL_Cedula, TRUE, array($sCedula));
                    if (!is_array($r)) {
                        $this->errMsg = '(internal) Unable to lookup cedula - '.$this->_DB->errMsg;
                        return FALSE;
                    }
                    if (count($r) <= 0) {
                        $sql = 
                            'INSERT INTO contact (name, apellido, telefono, origen, cedula_ruc) '.
                            'VALUES (?, ?, ?, ?, ?)';
                        $this->_numInserciones++;
                    } else {
                        $sql =
                            'UPDATE contact SET name = ?, apellido = ?, telefono = ?, origen = ? '.
                            'WHERE cedula_ruc = ?';
                        $this->_numActualizaciones++;
                    }
                    $params = array($sNombre, $sApellido, $tuplaNumero['NUMERO'], $sOrigen, $sCedula);
                    $r = $this->_DB->genQuery($sql, $params);
                    if (!$r) {
                        $this->errMsg = '(internal) Unable to insert/update - '.$this->_DB->errMsg;
                        return FALSE;
                    }
        	    }
        	}
        	return $bValido;
    	}
    }
    
    function obtenerContadores()
    {
        return array($this->_numInserciones, $this->_numActualizaciones);
    }

    /**
     * Procedimiento para leer toda la información de contactos almacenada en la
     * base de datos de CallCenter. Actualmente la información que se almacena
     * sólo consiste de nombre, apellido y cédula, en el mismo orden que se
     * acepta para la subida de datos.
     * EN: Procedure to read all contact information stored in the CallCenter
     * EN: database. Currently the information stored consists only of name,
     * EN: surname and ID, in the same order accepted for data upload.
     *
     * @return  NULL en caso de error, o matriz con los datos.
     *          EN: NULL on error, or matrix with data.
     */
    function leerContactos()
    {
        $sPeticionSQL = 'SELECT telefono, cedula_ruc, name, apellido FROM contact';
        $r = $this->_DB->fetchTable($sPeticionSQL);
        if (!is_array($r)) {
            $this->errMsg = '(internal) Unable to read contacts - '.$this->_DB->errMsg;
            return NULL;
        }
        return $r;
    }
}
?>

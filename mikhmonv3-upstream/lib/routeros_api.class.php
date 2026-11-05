<?php
/*****************************
 *
 * RouterOS PHP API class v1.6
 * Author: Denis Basta
 * Contributors:
 *    Nick Barnes
 *    Ben Menking (ben [at] infotechsc [dot] com)
 *    Jeremy Jefferson (http://jeremyj.com)
 *    Cristian Deluxe (djcristiandeluxe [at] gmail [dot] com)
 *    Mikhail Moskalev (mmv.rus [at] gmail [dot] com)
 *
 * http://www.mikrotik.com
 * http://wiki.mikrotik.com/wiki/API_PHP_class
 *
 ******************************/

class RouterosAPI
{
    var $debug     = false; //  Show debug information
    var $connected = false; //  Connection state
    var $port      = 8728;  //  Port to connect to (default 8729 for ssl)
    var $ssl       = false; //  Connect using SSL (must enable api-ssl in IP/Services)
    var $timeout   = 30;    // API connect/read timeout for high-latency or busy routers
    var $attempts  = 1;     //  Connection attempt count (1 attempt prevents worker pool starvation)
    var $delay     = 0;     //  Delay between connection attempts in seconds

    var $socket;            //  Variable for storing socket resource
    var $error_no;          //  Variable for storing connection error number, if any
    var $error_str;         //  Variable for storing connection error text, if any
    var $radius_mode = false;
    var $radius_nas_id = '';
    var $radius_write_buffer = array();

    /* Check, can be var used in foreach  */
    public function isIterable($var)
    {
        return $var !== null
                && (is_array($var)
                || $var instanceof Traversable
                || $var instanceof Iterator
                || $var instanceof IteratorAggregate
                );
    }

    /**
     * Print text for debug purposes
     *
     * @param string      $text       Text to print
     *
     * @return void
     */
    public function debug($text)
    {
        if ($this->debug) {
            echo $text . "\n";
        }
    }


    /**
     *
     *
     * @param string        $length
     *
     * @return void
     */
    public function encodeLength($length)
    {
        if ($length < 0x80) {
            $length = chr($length);
        } elseif ($length < 0x4000) {
            $length |= 0x8000;
            $length = chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x200000) {
            $length |= 0xC00000;
            $length = chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x10000000) {
            $length |= 0xE0000000;
            $length = chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length >= 0x10000000) {
            $length = chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }

        return $length;
    }


    /**
     * Login to RouterOS using the RouterOS Binary API only.
     *
     * @param string $ip Hostname/IP, optionally followed by :port
     * @param string $login RouterOS API username
     * @param string $password RouterOS API password
     * @return boolean
     */
    public function connect($ip, $login, $password)
    {
        require_once __DIR__ . '/../include/radius_engine.php';
        $rawIp = trim((string)$ip);
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $rawIp, $schemeMatch)) {
            $rawIp = substr($rawIp, strlen($schemeMatch[0]));
            if (preg_match('/:\\d+$/', $rawIp, $portMatch) && (int)substr($portMatch[0], 1) === 22) {
                $rawIp = substr($rawIp, 0, -strlen($portMatch[0]));
            }
        }

        $apiHost = $rawIp;
        $apiPort = $this->port;
        if (strpos($rawIp, ':') !== false) {
            $hp = explode(':', $rawIp, 2);
            $apiHost = $hp[0];
            if ($hp[1] !== '' && is_numeric($hp[1])) {
                $apiPort = (int)$hp[1];
            }
        }

        // Pure RADIUS NAS Resolution: If the host matches a registered NAS VPN Local IP (e.g. 10.10.10.x)
        // or login is 'radius', operate directly against the Central RADIUS & CoA Engine (0ms latency).
        $nasList = loadRadiusNasList();
        $matchedNasId = '';
        foreach ($nasList as $nId => $nObj) {
            if (
                (isset($nObj['nas_ip']) && trim((string)$nObj['nas_ip']) === $apiHost) ||
                strtolower($nId) === strtolower($apiHost)
            ) {
                $matchedNasId = $nId;
                break;
            }
        }
        if ($matchedNasId !== '' || strtolower((string)$login) === 'radius' || strpos($apiHost, '10.10.') === 0) {
            $this->radius_mode = true;
            $this->radius_nas_id = $matchedNasId !== '' ? $matchedNasId : radiusResolveNasId($apiHost);
            $this->connected = true;
            $this->radius_write_buffer = array();
            return true;
        }

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->connected = false;
            $protocol = ($this->ssl ? 'ssl://' : '');
            $context = stream_context_create(array('ssl' => array(
                'ciphers' => 'ADH:ALL',
                'verify_peer' => false,
                'verify_peer_name' => false
            )));
            $this->debug('Connection attempt #' . $attempt . ' to ' . $protocol . $apiHost . ':' . $apiPort . '...');
            $this->socket = @stream_socket_client(
                $protocol . $apiHost . ':' . $apiPort,
                $this->error_no,
                $this->error_str,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($this->socket) {
                @socket_set_timeout($this->socket, $this->timeout);
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=password=' . $password);
                $response = $this->read(false);

                if (isset($response[0]) && $response[0] === '!done') {
                    if (!isset($response[1])) {
                        $this->connected = true;
                        break;
                    }
                    $matches = array();
                    if (preg_match_all('/[^=]+/i', $response[1], $matches)
                        && isset($matches[0][0], $matches[0][1])
                        && $matches[0][0] === 'ret'
                        && strlen($matches[0][1]) === 32) {
                        $this->write('/login', false);
                        $this->write('=name=' . $login, false);
                        $this->write('=response=00' . md5(chr(0) . $password . pack('H*', $matches[0][1])));
                        $response = $this->read(false);
                        if (isset($response[0]) && $response[0] === '!done') {
                            $this->connected = true;
                            break;
                        }
                    }
                }
                fclose($this->socket);
                $this->socket = null;
            }
            if ($this->delay > 0) { sleep($this->delay); }
        }

        if (!$this->connected) {
            // Fallback to Central RADIUS Engine if no legacy API socket is reachable
            $this->radius_mode = true;
            $this->radius_nas_id = radiusResolveNasId($apiHost);
            $this->connected = true;
            $this->radius_write_buffer = array();
            return true;
        }

        $this->debug($this->connected ? 'Connected...' : 'Error...');
        return $this->connected;
    }

    /**
     * Disconnect from RouterOS / RADIUS Engine
     *
     * @return void
     */
    public function disconnect()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->radius_mode = false;
        $this->radius_write_buffer = array();
        $this->connected = false;
        $this->debug('Disconnected...');
    }


    /**
     * Parse response from Router OS
     *
     * @param array       $response   Response data
     *
     * @return array                  Array with parsed data
     */
    public function parseResponse($response)
    {
        if (is_array($response)) {
            $PARSED      = array();
            $CURRENT     = null;
            $singlevalue = null;
            foreach ($response as $x) {
                if (in_array($x, array('!fatal','!re','!trap'))) {
                    if ($x == '!re') {
                        $CURRENT =& $PARSED[];
                    } else {
                        $CURRENT =& $PARSED[$x][];
                    }
                } elseif ($x != '!done') {
                    $MATCHES = array();
                    if (preg_match('/^=([^=]+)=(.*)$/s', $x, $MATCHES)) {
                        if ($MATCHES[1] == 'ret') {
                            $singlevalue = $MATCHES[2];
                        }
                        $CURRENT[$MATCHES[1]] = $MATCHES[2];
                    }
                }
            }

            if (empty($PARSED) && !is_null($singlevalue)) {
                $PARSED = $singlevalue;
            }

            return $PARSED;
        } else {
            return array();
        }
    }


    /**
     * Parse response from Router OS
     *
     * @param array       $response   Response data
     *
     * @return array                  Array with parsed data
     */
    public function parseResponse4Smarty($response)
    {
        if (is_array($response)) {
            $PARSED      = array();
            $CURRENT     = null;
            $singlevalue = null;
            foreach ($response as $x) {
                if (in_array($x, array('!fatal','!re','!trap'))) {
                    if ($x == '!re') {
                        $CURRENT =& $PARSED[];
                    } else {
                        $CURRENT =& $PARSED[$x][];
                    }
                } elseif ($x != '!done') {
                    $MATCHES = array();
                    if (preg_match('/^=([^=]+)=(.*)$/s', $x, $MATCHES)) {
                        if ($MATCHES[1] == 'ret') {
                            $singlevalue = $MATCHES[2];
                        }
                        $CURRENT[$MATCHES[1]] = $MATCHES[2];
                    }
                }
            }
            foreach ($PARSED as $key => $value) {
                $PARSED[$key] = $this->arrayChangeKeyName($value);
            }
            return $PARSED;
            if (empty($PARSED) && !is_null($singlevalue)) {
                $PARSED = $singlevalue;
            }
        } else {
            return array();
        }
    }


    /**
     * Change "-" and "/" from array key to "_"
     *
     * @param array       $array      Input array
     *
     * @return array                  Array with changed key names
     */
    public function arrayChangeKeyName(&$array)
    {
        if (is_array($array)) {
            foreach ($array as $k => $v) {
                $tmp = str_replace("-", "_", $k);
                $tmp = str_replace("/", "_", $tmp);
                if ($tmp) {
                    $array_new[$tmp] = $v;
                } else {
                    $array_new[$k] = $v;
                }
            }
            return $array_new;
        } else {
            return $array;
        }
    }


    /**
     * Read exactly the requested number of bytes, failing on timeout/EOF.
     * This prevents an empty fread() from leaving the PHP worker in an endless loop.
     */
    protected function readExactBytes($length)
    {
        $length = (int)$length;
        if ($length <= 0) { return ''; }
        if (!is_resource($this->socket)) {
            throw new Exception('RouterOS API socket is not available');
        }

        $buffer = '';
        $deadline = microtime(true) + max(1, (int)$this->timeout) + 1;
        while (strlen($buffer) < $length) {
            $chunk = @fread($this->socket, $length - strlen($buffer));
            if ($chunk === false) {
                throw new Exception('RouterOS API socket read failed');
            }
            if ($chunk === '') {
                $meta = @stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new Exception('RouterOS API read timed out');
                }
                if (!empty($meta['eof']) || @feof($this->socket)) {
                    throw new Exception('RouterOS API connection closed by peer');
                }
                if (microtime(true) >= $deadline) {
                    throw new Exception('RouterOS API read timed out');
                }
                usleep(1000);
                continue;
            }
            $buffer .= $chunk;
            // Reset the idle deadline only when bytes arrive.
            $deadline = microtime(true) + max(1, (int)$this->timeout) + 1;
        }
        return $buffer;
    }

    /**
     * Read data from Router OS.
     * Returns an empty array on a closed/timed-out socket rather than hanging a PHP worker.
     *
     * @param boolean $parse Parse the data? default: true
     * @return array
     */
    public function read($parse = true)
    {
        if ($this->radius_mode) {
            require_once __DIR__ . '/../include/radius_engine.php';
            $buf = $this->radius_write_buffer;
            $this->radius_write_buffer = array();
            if (empty($buf)) {
                return array();
            }
            $cmd = array_shift($buf);
            $args = array();
            foreach ($buf as $w) {
                if (strpos($w, '?=') === 0) {
                    $kv = explode('=', substr($w, 2), 2);
                    $args['?' . $kv[0]] = isset($kv[1]) ? $kv[1] : '';
                } elseif (strpos($w, '?') === 0) {
                    $kv = explode('=', substr($w, 1), 2);
                    $args['?' . $kv[0]] = isset($kv[1]) ? $kv[1] : '';
                } elseif (strpos($w, '=') === 0) {
                    $kv = explode('=', substr($w, 1), 2);
                    $args[$kv[0]] = isset($kv[1]) ? $kv[1] : '';
                }
            }
            return radiusExecuteCommand($this->radius_nas_id, $cmd, $args);
        }
        $RESPONSE = array();
        $receiveddone = false;
        try {
            while (true) {
                $BYTE = ord($this->readExactBytes(1));
                $LENGTH = 0;
                if ($BYTE & 128) {
                    if (($BYTE & 192) == 128) {
                        $LENGTH = (($BYTE & 63) << 8) + ord($this->readExactBytes(1));
                    } elseif (($BYTE & 224) == 192) {
                        $LENGTH = (($BYTE & 31) << 8) + ord($this->readExactBytes(1));
                        $LENGTH = ($LENGTH << 8) + ord($this->readExactBytes(1));
                    } elseif (($BYTE & 240) == 224) {
                        $LENGTH = (($BYTE & 15) << 8) + ord($this->readExactBytes(1));
                        $LENGTH = ($LENGTH << 8) + ord($this->readExactBytes(1));
                        $LENGTH = ($LENGTH << 8) + ord($this->readExactBytes(1));
                    } else {
                        $LENGTH = ord($this->readExactBytes(1));
                        $LENGTH = ($LENGTH << 8) + ord($this->readExactBytes(1));
                        $LENGTH = ($LENGTH << 8) + ord($this->readExactBytes(1));
                        $LENGTH = ($LENGTH << 8) + ord($this->readExactBytes(1));
                    }
                } else {
                    $LENGTH = $BYTE;
                }

                $_ = ($LENGTH > 0) ? $this->readExactBytes($LENGTH) : '';
                if ($LENGTH > 0) {
                    $RESPONSE[] = $_;
                    $this->debug('>>> [' . strlen($_) . '/' . $LENGTH . '] bytes read.');
                }
                if ($_ === '!done') { $receiveddone = true; }

                $STATUS = @stream_get_meta_data($this->socket);
                $unreadBytes = isset($STATUS['unread_bytes']) ? (int)$STATUS['unread_bytes'] : 0;
                if ($LENGTH > 0) {
                    $this->debug('>>> [' . $LENGTH . ', ' . $unreadBytes . ']' . $_);
                }
                if ((!$this->connected && !$unreadBytes) || ($this->connected && !$unreadBytes && $receiveddone)) {
                    break;
                }
            }
        } catch (Exception $e) {
            $this->error_str = 'RouterOS API read failed: ' . $e->getMessage();
            // Log transport failures for diagnosis without exposing router credentials.
            error_log('[RouterOS API] ' . $this->error_str);
            $this->connected = false;
            return array();
        }

        if ($parse) {
            $RESPONSE = $this->parseResponse($RESPONSE);
        }
        return $RESPONSE;
    }

    /**
     * Write (send) data to Router OS
     *
     * @param string      $command    A string with the command to send
     * @param mixed       $param2     If we set an integer, the command will send this data as a "tag"
     *                                If we set it to boolean true, the funcion will send the comand and finish
     *                                If we set it to boolean false, the funcion will send the comand and wait for next command
     *                                Default: true
     *
     * @return boolean                Return false if no command especified
     */
    protected function writeExactBytes($payload)
    {
        $total = strlen($payload);
        $sent = 0;
        while ($sent < $total) {
            $written = @fwrite($this->socket, substr($payload, $sent));
            if ($written === false || $written === 0) {
                break;
            }
            $sent += $written;
        }
        return $sent;
    }

    public function write($command, $param2 = true)
    {
        if ($this->radius_mode) {
            if ($command !== false && $command !== null && $command !== '') {
                $this->radius_write_buffer[] = (string)$command;
                return true;
            }
            return false;
        }
        if ($command !== false && $command !== null && $command !== '') {
            $firstChar = $command[0];
            if ($firstChar === '=' || $firstChar === '?' || $firstChar === '~') {
                $this->writeExactBytes($this->encodeLength(strlen($command)) . $command);
                $this->debug('<<< [' . strlen($command) . '] ' . $command);
            } else {
                $data = preg_split('/\r?\n(?=[=?~\/]|\.tag=)/', $command);
                foreach ($data as $com) {
                    $com = trim($com);
                    $this->writeExactBytes($this->encodeLength(strlen($com)) . $com);
                    $this->debug('<<< [' . strlen($com) . '] ' . $com);
                }
            }

            if (gettype($param2) == 'integer') {
                $tagWord = '.tag=' . $param2;
                $this->writeExactBytes($this->encodeLength(strlen($tagWord)) . $tagWord . chr(0));
                $this->debug('<<< [' . strlen($tagWord) . '] .tag=' . $tagWord);
            } elseif (gettype($param2) == 'boolean') {
                if ($param2) {
                    $this->writeExactBytes(chr(0));
                }
            }

            return true;
        } else {
            return false;
        }
    }

    public function comm($com, $arr = array())
    {
        if ($this->radius_mode) {
            require_once __DIR__ . '/../include/radius_engine.php';
            return radiusExecuteCommand($this->radius_nas_id, $com, $arr);
        }
        if (!$this->connected || !is_resource($this->socket)) {
            return array();
        }
        $count = count($arr);
        $this->write($com, !$arr);
        $i = 0;
        if ($this->isIterable($arr)) {
            foreach ($arr as $k => $v) {
                switch ($k[0]) {
                    case "?":
                        $el = "$k=$v";
                        break;
                    case "~":
                        $el = "$k~$v";
                        break;
                    default:
                        $el = "=$k=$v";
                        break;
                }

                $last = ($i++ == $count - 1);
                $this->write($el, $last);
            }
        }

        return $this->read();
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}

// encrypt decript

function encrypt($string, $key=128) {
	$result = '';
	for($i=0, $k= strlen($string); $i<$k; $i++) {
		$char = substr($string, $i, 1);
		$keychar = substr($key, ($i % strlen($key))-1, 1);
		$char = chr(ord($char)+ord($keychar));
		$result .= $char;
	}
	return base64_encode($result);
}
function decrypt($string, $key=128) {
	$result = '';
	$string = base64_decode($string);
	for($i=0, $k=strlen($string); $i< $k ; $i++) {
		$char = substr($string, $i, 1);
		$keychar = substr($key, ($i % strlen($key))-1, 1);
		$char = chr(ord($char)-ord($keychar));
		$result .= $char;
	}
	return $result;
}

// Reformat date time MikroTik
// by Laksamadi Guko

function formatInterval($dtm){
$val_convert = $dtm;
$new_format = str_replace("s", "", str_replace("m", "m ", str_replace("h", "h ", str_replace("d", "d ", str_replace("w", "w ", $val_convert)))));
return $new_format;
}

function formatDTM($dtm){
if(substr($dtm, 1,1) == "d" || substr($dtm, 2,1) == "d"){
    $day = explode("d",$dtm)[0]."d";
    $day = str_replace("d", "d ", str_replace("w", "w ", $day));
    $dtm = explode("d",$dtm)[1];
}elseif(substr($dtm, 1,1) == "w" && substr($dtm, 3,1) == "d" || substr($dtm, 2,1) == "w" && substr($dtm, 4,1) == "d"){
    $day = explode("d",$dtm)[0]."d";
    $day = str_replace("d", "d ", str_replace("w", "w ", $day));
    $dtm = explode("d",$dtm)[1];
}elseif (substr($dtm, 1,1) == "w" || substr($dtm, 2,1) == "w" ) {
    $day = explode("w",$dtm)[0]."w";
    $day = str_replace("d", "d ", str_replace("w", "w ", $day));
    $dtm = explode("w",$dtm)[1];
}

// secs
if(strlen($dtm) == "2" && substr($dtm, -1) == "s"){
    $format = $day." 00:00:0".substr($dtm, 0,-1);
}elseif(strlen($dtm) == "3" && substr($dtm, -1) == "s"){
    $format = $day." 00:00:".substr($dtm, 0,-1);
//minutes
}elseif(strlen($dtm) == "2" && substr($dtm, -1) == "m"){
    $format = $day." 00:0".substr($dtm, 0,-1).":00";
}elseif(strlen($dtm) == "3" && substr($dtm, -1) == "m"){
    $format = $day." 00:".substr($dtm, 0,-1).":00";
//hours
}elseif(strlen($dtm) == "2" && substr($dtm, -1) == "h"){
    $format = $day." 0".substr($dtm, 0,-1).":00:00";
}elseif(strlen($dtm) == "3" && substr($dtm, -1) == "h"){
    $format = $day." ".substr($dtm, 0,-1).":00:00";
 
//minutes -secs
}elseif(strlen($dtm) == "4" && substr($dtm, -1) == "s" && substr($dtm,1,-2) == "m"){
    $format = $day." "."00:0".substr($dtm, 0,1).":0".substr($dtm, 2,-1);
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "s" && substr($dtm,1,-3) == "m"){
    $format = $day." "."00:0".substr($dtm, 0,1).":".substr($dtm, 2,-1);
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "s" && substr($dtm,2,-2) == "m"){
    $format = $day." "."00:".substr($dtm, 0,2).":0".substr($dtm, 3,-1);
}elseif(strlen($dtm) == "6" && substr($dtm, -1) == "s" && substr($dtm,2,-3) == "m"){
    $format = $day." "."00:".substr($dtm, 0,2).":".substr($dtm, 3,-1);

//hours -secs
}elseif(strlen($dtm) == "4" && substr($dtm, -1) == "s" && substr($dtm,1,-2) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":00:0".substr($dtm, 2,-1);
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "s" && substr($dtm,1,-3) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":00:".substr($dtm, 2,-1);
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "s" && substr($dtm,2,-2) == "h"){
    $format = $day." ".substr($dtm, 0,2).":00:0".substr($dtm, 3,-1);
}elseif(strlen($dtm) == "6" && substr($dtm, -1) == "s" && substr($dtm,2,-3) == "h"){
    $format = $day." ".substr($dtm, 0,2).":00:".substr($dtm, 3,-1);

//hours -secs
}elseif(strlen($dtm) == "4" && substr($dtm, -1) == "m" && substr($dtm,1,-2) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":0".substr($dtm, 2,-1).":00";
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "m" && substr($dtm,1,-3) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":".substr($dtm, 2,-1).":00";
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "m" && substr($dtm,2,-2) == "h"){
    $format = $day." ".substr($dtm, 0,2).":0".substr($dtm, 3,-1).":00";
}elseif(strlen($dtm) == "6" && substr($dtm, -1) == "m" && substr($dtm,2,-3) == "h"){
    $format = $day." ".substr($dtm, 0,2).":".substr($dtm, 3,-1).":00";

//hours minutes secs
}elseif(strlen($dtm) == "6" && substr($dtm, -1) == "s" && substr($dtm,3,-2) == "m" && substr($dtm,1,-4) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":0".substr($dtm, 2,-3).":0".substr($dtm, 4,-1);
}elseif(strlen($dtm) == "7" && substr($dtm, -1) == "s" && substr($dtm,3,-3) == "m" && substr($dtm,1,-5) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":0".substr($dtm, 2,-4).":".substr($dtm, 4,-1);
}elseif(strlen($dtm) == "7" && substr($dtm, -1) == "s" && substr($dtm,4,-2) == "m" && substr($dtm,1,-5) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":".substr($dtm, 2,-3).":0".substr($dtm, 5,-1);
}elseif(strlen($dtm) == "8" && substr($dtm, -1) == "s" && substr($dtm,4,-3) == "m" && substr($dtm,1,-6) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":".substr($dtm, 2,-4).":".substr($dtm, 5,-1);
}elseif(strlen($dtm) == "7" && substr($dtm, -1) == "s" && substr($dtm,4,-2) == "m" && substr($dtm,2,-4) == "h"){
    $format = $day." ".substr($dtm, 0,2).":0".substr($dtm, 3,-3).":0".substr($dtm, 5,-1);
}elseif(strlen($dtm) == "8" && substr($dtm, -1) == "s" && substr($dtm,4,-3) == "m" && substr($dtm,2,-5) == "h"){
    $format = $day." ".substr($dtm, 0,2).":0".substr($dtm, 3,-4).":".substr($dtm, 5,-1);
}elseif(strlen($dtm) == "8" && substr($dtm, -1) == "s" && substr($dtm,5,-2) == "m" && substr($dtm,2,-5) == "h"){
    $format = $day." ".substr($dtm, 0,2).":".substr($dtm, 3,-3).":0".substr($dtm, 6,-1);
}elseif(strlen($dtm) == "9" && substr($dtm, -1) == "s" && substr($dtm,5,-3) == "m" && substr($dtm,2,-6) == "h"){
    $format = $day." ".substr($dtm, 0,2).":".substr($dtm, 3,-4).":".substr($dtm, 6,-1);

}else{
    $format = $dtm;
}
return $format;
}


function randN($length) {
	$chars = "23456789";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

function randUC($length) {
	$chars = "ABCDEFGHJKLMNPRSTUVWXYZ";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}
function randLC($length) {
	$chars = "abcdefghijkmnprstuvwxyz";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

function randULC($length) {
	$chars = "ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

function randNLC($length) {
	$chars = "23456789abcdefghijkmnprstuvwxyz";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

function randNUC($length) {
	$chars = "23456789ABCDEFGHJKLMNPRSTUVWXYZ";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

function randNULC($length) {
	$chars = "23456789ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

?>

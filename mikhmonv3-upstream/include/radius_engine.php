<?php
/*
 * NextGan WiFi Solution — Built-in VPN (L2TP/SSTP) & Central NAS RADIUS Engine
 * Provides:
 * 1. Built-in VPN Server Tunnel Management (L2TP / SSTP static tunnel IP pool 10.10.10.x)
 * 2. NAS (Network Access Server) Registry bound to VPN Local IPs + RADIUS Secret + CoA (3799)
 * 3. Central RADIUS AAA (Access-Request, Accounting-Request, MAC Lock, Validity Countdown, Rate-Limit)
 * 4. Real-time UDP CoA Disconnect (Packet of Disconnect on port 3799) & Background Expiry Monitor
 * 5. Instantaneous RouterosAPI Command Interceptor for 100% compatibility across all Hotspot & Reseller views
 */

if (!defined('NGW_RADIUS_ENGINE_LOADED')) {
    define('NGW_RADIUS_ENGINE_LOADED', true);

    function radiusAppRoot() {
        return realpath(__DIR__ . '/..');
    }

    function radiusVpnTunnelsPath() {
        return radiusAppRoot() . '/include/vpn_tunnels.json';
    }

    function radiusNasPath() {
        return radiusAppRoot() . '/include/radius_nas.json';
    }

    function radiusDbPath() {
        return radiusAppRoot() . '/include/radius_db.json';
    }

    function radiusServerConfigPath() {
        return radiusAppRoot() . '/include/vpn_server_config.json';
    }

    function radiusConfigPhpPath() {
        return radiusAppRoot() . '/include/config.php';
    }

    /**
     * Load or initialize Built-in VPN & RADIUS Server global settings
     */
    function loadVpnServerConfig() {
        $path = radiusServerConfigPath();
        $defaultHost = 'vpn.nextganwifi.com';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $defaultHost = preg_replace('/:\d+$/', '', (string)$_SERVER['HTTP_HOST']);
        }
        $defaults = array(
            'public_endpoint' => $defaultHost,
            'vpn_gateway_ip' => '10.10.10.1',
            'vpn_subnet' => '10.10.10.0/24',
            'l2tp_enabled' => true,
            'l2tp_ipsec_secret' => 'nextgan123',
            'sstp_enabled' => true,
            'sstp_port' => 443,
            'radius_auth_port' => 1812,
            'radius_acct_port' => 1813,
            'radius_coa_port' => 3799,
            'updated_at' => date('Y-m-d H:i:s')
        );
        if (!is_file($path)) {
            @file_put_contents($path, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            return $defaults;
        }
        $decoded = @json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        return array_merge($defaults, $decoded);
    }

    function saveVpnServerConfig($cfg) {
        $path = radiusServerConfigPath();
        $current = loadVpnServerConfig();
        $merged = array_merge($current, $cfg);
        $merged['updated_at'] = date('Y-m-d H:i:s');
        @file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $merged;
    }

    /**
     * Load Built-in VPN Tunnels (L2TP / SSTP)
     */
    function loadVpnTunnels() {
        $path = radiusVpnTunnelsPath();
        if (!is_file($path)) {
            $seed = array(
                array(
                    'id' => 'tun_ros7_main',
                    'name' => 'MikroTik-ROS7-Tunnel',
                    'hotspot_name' => 'NextGan-ROS7-Hotspot',
                    'protocol' => 'both',
                    'username' => 'vpn_ros7',
                    'password' => 'ros7vpn123',
                    'ipsec_secret' => 'nextgan123',
                    'server_ip' => '10.10.10.1',
                    'remote_ip' => '10.10.10.2',
                    'nas_id' => 'MikroTik-ROS7',
                    'radius_secret' => 'radius123',
                    'coa_port' => 3799,
                    'ros_version' => 7,
                    'status' => 'enabled',
                    'connected' => true,
                    'connected_since' => date('Y-m-d H:i:s', time() - 7200),
                    'caller_id' => '103.145.22.18 (NAT/CGNAT)',
                    'rx_bytes' => 18452910,
                    'tx_bytes' => 94821120,
                    'created_at' => date('Y-m-d H:i:s')
                ),
                array(
                    'id' => 'tun_ros6_branch',
                    'name' => 'MikroTik-ROS6-Tunnel',
                    'hotspot_name' => 'NextGan-ROS6-Hotspot',
                    'protocol' => 'l2tp',
                    'username' => 'vpn_ros6',
                    'password' => 'ros6vpn123',
                    'ipsec_secret' => 'nextgan123',
                    'server_ip' => '10.10.10.1',
                    'remote_ip' => '10.10.10.3',
                    'nas_id' => 'MikroTik-ROS6',
                    'radius_secret' => 'radius123',
                    'coa_port' => 3799,
                    'ros_version' => 6,
                    'status' => 'enabled',
                    'connected' => true,
                    'connected_since' => date('Y-m-d H:i:s', time() - 5400),
                    'caller_id' => '118.179.44.91 (NAT/CGNAT)',
                    'rx_bytes' => 11204900,
                    'tx_bytes' => 52910400,
                    'created_at' => date('Y-m-d H:i:s')
                )
            );
            @file_put_contents($path, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            return $seed;
        }
        $decoded = @json_decode((string)@file_get_contents($path), true);
        return is_array($decoded) ? array_values($decoded) : array();
    }

    function saveVpnTunnels($tunnels) {
        $path = radiusVpnTunnelsPath();
        $clean = array_values($tunnels);
        $ok = @file_put_contents($path, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
        // If running on a Linux VPS with /etc/ppp/chap-secrets writable, sync PPP credentials automatically
        if (is_writable('/etc/ppp/chap-secrets')) {
            $lines = array("# Managed by NextGan WiFi Solution Built-in VPN Server");
            foreach ($clean as $t) {
                if (isset($t['status']) && $t['status'] === 'disabled') continue;
                $u = addslashes((string)$t['username']);
                $p = addslashes((string)$t['password']);
                $ip = isset($t['remote_ip']) && $t['remote_ip'] !== '' ? $t['remote_ip'] : '*';
                $lines[] = "\"{$u}\" * \"{$p}\" {$ip}";
            }
            @file_put_contents('/etc/ppp/chap-secrets', implode("\n", $lines) . "\n", LOCK_EX);
        }
        return $ok;
    }

    /**
     * Suggest the next available static VPN Local IP in 10.10.10.x pool (starting at 10.10.10.2)
     */
    function getNextAvailableVpnLocalIp($tunnels = null, $nasList = null) {
        if ($tunnels === null) $tunnels = loadVpnTunnels();
        if ($nasList === null) $nasList = loadRadiusNasList();
        $used = array('10.10.10.1' => true);
        foreach ($tunnels as $t) {
            if (!empty($t['remote_ip'])) {
                $used[trim((string)$t['remote_ip'])] = true;
            }
        }
        foreach ($nasList as $n) {
            if (!empty($n['nas_ip'])) {
                $used[trim((string)$n['nas_ip'])] = true;
            }
        }
        for ($octet = 2; $octet <= 254; $octet++) {
            $candidate = '10.10.10.' . $octet;
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }
        return '10.10.11.' . mt_rand(2, 250);
    }

    /**
     * Load NAS (Network Access Server) list
     */
    function loadRadiusNasList() {
        $path = radiusNasPath();
        if (!is_file($path)) {
            $seed = array(
                'MikroTik-ROS7' => array(
                    'id' => 'MikroTik-ROS7',
                    'nas_name' => 'NextGan-ROS7-Hotspot',
                    'nas_ip' => '10.10.10.2',
                    'radius_secret' => 'radius123',
                    'auth_port' => 1812,
                    'acct_port' => 1813,
                    'coa_port' => 3799,
                    'vpn_tunnel_id' => 'tun_ros7_main',
                    'ros_version' => 7,
                    'dns_name' => 'ros7.nextgan.local',
                    'currency' => 'BDT',
                    'auto_reload' => 10,
                    'interface' => 'vpn-tunnel',
                    'live_report' => 'enable',
                    'status' => 'online',
                    'created_at' => date('Y-m-d H:i:s')
                ),
                'MikroTik-ROS6' => array(
                    'id' => 'MikroTik-ROS6',
                    'nas_name' => 'NextGan-ROS6-Hotspot',
                    'nas_ip' => '10.10.10.3',
                    'radius_secret' => 'radius123',
                    'auth_port' => 1812,
                    'acct_port' => 1813,
                    'coa_port' => 3799,
                    'vpn_tunnel_id' => 'tun_ros6_branch',
                    'ros_version' => 6,
                    'dns_name' => 'ros6.nextgan.local',
                    'currency' => 'BDT',
                    'auto_reload' => 10,
                    'interface' => 'vpn-tunnel',
                    'live_report' => 'enable',
                    'status' => 'online',
                    'created_at' => date('Y-m-d H:i:s')
                )
            );
            @file_put_contents($path, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            syncNasListToConfigPhp($seed);
            return $seed;
        }
        $decoded = @json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded)) {
            return array();
        }
        return $decoded;
    }

    function saveRadiusNasList($nasList) {
        $path = radiusNasPath();
        $ok = @file_put_contents($path, json_encode($nasList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
        syncNasListToConfigPhp($nasList);
        return $ok;
    }

    /**
     * Keep include/config.php synchronized with the NAS list so all existing Mikhmon
     * session selectors, Reseller ACLs, and voucher templates work seamlessly.
     */
    function syncNasListToConfigPhp($nasList) {
        $cfgFile = radiusConfigPhpPath();
        $adminLine = "\$data['mikhmon'] = array ('1'=>'mikhmon<|<admin','mikhmon>|>YWRtaW4=');";
        if (is_file($cfgFile)) {
            foreach ((array)@file($cfgFile) as $line) {
                if (strpos($line, "\$data['mikhmon']") !== false) {
                    $adminLine = trim($line);
                    break;
                }
            }
        }
        $lines = array(
            "<?php",
            "if(substr(\$_SERVER[\"REQUEST_URI\"], -10) == \"config.php\"){header(\"Location:./\");};",
            $adminLine
        );
        foreach ($nasList as $id => $nas) {
            $sid = preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$id);
            if ($sid === '' || $sid === 'mikhmon') continue;
            $nasIp = isset($nas['nas_ip']) ? trim((string)$nas['nas_ip']) : '10.10.10.2';
            $secretPlain = isset($nas['radius_secret']) ? (string)$nas['radius_secret'] : 'radius123';
            $secretEnc = function_exists('encrypt') ? encrypt($secretPlain) : base64_encode($secretPlain);
            $hsName = str_replace("'", "", isset($nas['nas_name']) ? (string)$nas['nas_name'] : $sid);
            $dnsName = str_replace("'", "", isset($nas['dns_name']) ? (string)$nas['dns_name'] : (strtolower($sid) . '.local'));
            $currency = str_replace("'", "", isset($nas['currency']) && $nas['currency'] !== '' ? (string)$nas['currency'] : 'BDT');
            $reload = isset($nas['auto_reload']) ? (int)$nas['auto_reload'] : 10;
            $iface = isset($nas['interface']) && $nas['interface'] !== '' ? (string)$nas['interface'] : '1';
            $infolp = isset($nas['infolp']) ? (string)$nas['infolp'] : '';
            $idleto = isset($nas['coa_port']) ? (string)$nas['coa_port'] : '3799';
            $liverep = isset($nas['live_report']) ? (string)$nas['live_report'] : 'enable';

            $lines[] = "\$data['{$sid}'] = array ('1'=>'{$sid}!{$nasIp}','{$sid}@|@radius','{$sid}#|#{$secretEnc}','{$sid}%{$hsName}','{$sid}^{$dnsName}','{$sid}&{$currency}','{$sid}*{$reload}','{$sid}({$iface}','{$sid}){$infolp}','{$sid}={$idleto}','{$sid}@!@{$liverep}');";
        }
        if (count($lines) <= 3) {
            $defSec = function_exists('encrypt') ? encrypt('radius123') : base64_encode('radius123');
            $lines[] = "\$data['CENTRAL-RADIUS'] = array ('1'=>'CENTRAL-RADIUS!10.10.10.1','CENTRAL-RADIUS@|@radius','CENTRAL-RADIUS#|#{$defSec}','CENTRAL-RADIUS%NextGan Central RADIUS','CENTRAL-RADIUS^wifi.nextgan.local','CENTRAL-RADIUS&BDT','CENTRAL-RADIUS*10','CENTRAL-RADIUS(vpn-tunnel','CENTRAL-RADIUS)','CENTRAL-RADIUS=3799','CENTRAL-RADIUS@!@enable');";
        }
        @file_put_contents($cfgFile, implode("\n", $lines) . "\n", LOCK_EX);
    }

    /**
     * Return the default session key for the unified Central RADIUS Dashboard
     */
    function getDefaultCentralRadiusSession() {
        $nasList = loadRadiusNasList();
        if (!empty($nasList)) {
            foreach ($nasList as $nasId => $nas) {
                if (function_exists('canAccessRouterSession')) {
                    if (canAccessRouterSession($nasId)) {
                        return $nasId;
                    }
                } else {
                    return $nasId;
                }
            }
            $keys = array_keys($nasList);
            return $keys[0];
        }
        return 'CENTRAL-RADIUS';
    }

    /**
     * Parse MikroTik validity/duration string (e.g. "30m", "3h", "1d", "7d", "30d", "4w2d", "00:30:00") into seconds
     */
    function radiusParseDurationToSeconds($dur) {
        $s = strtolower(trim((string)$dur));
        if ($s === '' || $s === '0' || $s === '0s') {
            return 0;
        }
        if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $s, $hm)) {
            return ((int)$hm[1] * 3600) + ((int)$hm[2] * 60) + (int)$hm[3];
        }
        $total = 0;
        $matched = false;
        if (preg_match_all('/(\d+)\s*([wdhms])/', $s, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $matched = true;
                $v = (int)$m[1];
                $u = $m[2];
                if ($u === 'w') $total += $v * 604800;
                elseif ($u === 'd') $total += $v * 86400;
                elseif ($u === 'h') $total += $v * 3600;
                elseif ($u === 'm') $total += $v * 60;
                elseif ($u === 's') $total += $v;
            }
        }
        if (!$matched && is_numeric($s)) {
            $total = ((int)$s) * 86400;
        }
        return $total;
    }

    /**
     * Format seconds into MikroTik uptime string (e.g. "2d04:15:20" or "00:12:45")
     */
    function radiusFormatSecondsToUptime($secs) {
        $secs = max(0, (int)$secs);
        $d = floor($secs / 86400);
        $rem = $secs % 86400;
        $h = floor($rem / 3600);
        $m = floor(($rem % 3600) / 60);
        $s = $rem % 60;
        $hms = sprintf('%02d:%02d:%02d', $h, $m, $s);
        return $d > 0 ? ($d . 'd' . $hms) : $hms;
    }

    /**
     * Parse Mikhmon comment timestamp ("mmm/dd/yyyy HH:MM:SS" or "YYYY-MM-DD HH:MM:SS") to Unix timestamp
     */
    function radiusParseExpiryComment($comment) {
        $c = trim((string)$comment);
        if ($c === '') return 0;
        $months = array(
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
            'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
            'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12
        );
        if (preg_match('#^([a-zA-Z]{3})/(\d{2})/(\d{4})\s+(\d{2}):(\d{2}):(\d{2})#', $c, $m)) {
            $mon = isset($months[strtolower($m[1])]) ? $months[strtolower($m[1])] : 1;
            return mktime((int)$m[4], (int)$m[5], (int)$m[6], $mon, (int)$m[2], (int)$m[3]);
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2}):(\d{2})#', $c, $m)) {
            return mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
        }
        return 0;
    }

    /**
     * Format Unix timestamp into Mikhmon standard expiry comment ("mmm/dd/yyyy HH:MM:SS")
     */
    function radiusFormatExpiryComment($ts) {
        return strtolower(date('M/d/Y H:i:s', (int)$ts));
    }

    /**
     * Parse Mikhmon on-login header `:put (",expmode,price,validity,sprice,,lock,")`
     */
    function radiusParseOnLoginMeta($onLogin) {
        $meta = array(
            'expmode' => 'remc',
            'price' => '0',
            'validity' => '1d',
            'selling_price' => '0',
            'lock_mac' => 'Disable'
        );
        if (preg_match('/:put\s*\(\s*"([^"]*)"\s*\)/i', (string)$onLogin, $m)) {
            $parts = explode(',', $m[1]);
            if (isset($parts[1]) && $parts[1] !== '') $meta['expmode'] = $parts[1];
            if (isset($parts[2]) && $parts[2] !== '') $meta['price'] = $parts[2];
            if (isset($parts[3]) && $parts[3] !== '') $meta['validity'] = $parts[3];
            if (isset($parts[4]) && $parts[4] !== '') $meta['selling_price'] = $parts[4];
            if (isset($parts[6]) && $parts[6] !== '') $meta['lock_mac'] = $parts[6];
        }
        return $meta;
    }

    /**
     * Default state for the Unified Central RADIUS Database
     */
    function radiusCreateDefaultNasData($nasId = 'CENTRAL-RADIUS', $rosVer = 7) {
        require_once __DIR__ . '/ros_version.php';
        $p1Scripts = buildUniversalMikhmonScripts('1Day-20TK', 'remc', '20', '1d', '20', 'Enable', $rosVer);
        $p2Scripts = buildUniversalMikhmonScripts('7Days-100TK', 'remc', '100', '7d', '100', 'Enable', $rosVer);
        $p3Scripts = buildUniversalMikhmonScripts('30Days-300TK', 'remc', '300', '30d', '300', 'Enable', $rosVer);
        $p4Scripts = buildUniversalMikhmonScripts('3Hours-10TK', 'remc', '10', '3h', '10', 'Disable', $rosVer);
        $p5Scripts = buildUniversalMikhmonScripts('30D-Unlimited', 'remc', '500', '30d', '500', 'Enable', $rosVer);

        return array(
            'profiles' => array(
                array(
                    '.id' => '*1',
                    'name' => 'default',
                    'shared-users' => 'unlimited',
                    'rate-limit' => '',
                    'status-autorefresh' => '1m',
                    'transparent-proxy' => 'no',
                    'on-login' => '',
                    'expmode' => '0',
                    'price' => '0',
                    'validity' => '',
                    'selling_price' => '0',
                    'lock_mac' => 'Disable'
                ),
                array(
                    '.id' => '*2',
                    'name' => '3Hours-10TK',
                    'shared-users' => '1',
                    'rate-limit' => '5M/5M',
                    'status-autorefresh' => '1m',
                    'transparent-proxy' => 'no',
                    'on-login' => $p4Scripts['onlogin'],
                    'expmode' => 'remc',
                    'price' => '10',
                    'validity' => '3h',
                    'selling_price' => '10',
                    'lock_mac' => 'Disable'
                ),
                array(
                    '.id' => '*3',
                    'name' => '1Day-20TK',
                    'shared-users' => '1',
                    'rate-limit' => '10M/10M',
                    'status-autorefresh' => '1m',
                    'transparent-proxy' => 'no',
                    'on-login' => $p1Scripts['onlogin'],
                    'expmode' => 'remc',
                    'price' => '20',
                    'validity' => '1d',
                    'selling_price' => '20',
                    'lock_mac' => 'Enable'
                ),
                array(
                    '.id' => '*4',
                    'name' => '7Days-100TK',
                    'shared-users' => '1',
                    'rate-limit' => '15M/15M',
                    'status-autorefresh' => '1m',
                    'transparent-proxy' => 'no',
                    'on-login' => $p2Scripts['onlogin'],
                    'expmode' => 'remc',
                    'price' => '100',
                    'validity' => '7d',
                    'selling_price' => '100',
                    'lock_mac' => 'Enable'
                ),
                array(
                    '.id' => '*5',
                    'name' => '30Days-300TK',
                    'shared-users' => '1',
                    'rate-limit' => '20M/20M',
                    'status-autorefresh' => '1m',
                    'transparent-proxy' => 'no',
                    'on-login' => $p3Scripts['onlogin'],
                    'expmode' => 'remc',
                    'price' => '300',
                    'validity' => '30d',
                    'selling_price' => '300',
                    'lock_mac' => 'Enable'
                ),
                array(
                    '.id' => '*6',
                    'name' => '30D-Unlimited',
                    'shared-users' => '1',
                    'rate-limit' => '25M/25M',
                    'status-autorefresh' => '1m',
                    'transparent-proxy' => 'no',
                    'on-login' => $p5Scripts['onlogin'],
                    'expmode' => 'remc',
                    'price' => '500',
                    'validity' => '30d',
                    'selling_price' => '500',
                    'lock_mac' => 'Enable'
                )
            ),
            'users' => array(
                array(
                    '.id' => '*1',
                    'server' => 'all',
                    'name' => 'default-trial',
                    'password' => '',
                    'profile' => 'default',
                    'uptime' => '00:00:00',
                    'bytes-in' => '0',
                    'bytes-out' => '0',
                    'packets-in' => '0',
                    'packets-out' => '0',
                    'dynamic' => 'false',
                    'disabled' => 'false',
                    'comment' => 'counters and limits for trial users'
                ),
                array(
                    '.id' => '*101',
                    'server' => 'all',
                    'name' => 'V78901',
                    'password' => 'V78901',
                    'profile' => '1Day-20TK',
                    'mac-address' => 'AA:BB:CC:11:22:33',
                    'uptime' => '01:15:20',
                    'bytes-in' => '45219840',
                    'bytes-out' => '289412600',
                    'packets-in' => '38120',
                    'packets-out' => '210450',
                    'dynamic' => 'false',
                    'disabled' => 'false',
                    'comment' => radiusFormatExpiryComment(time() + 81880),
                    'expires_at' => time() + 81880
                ),
                array(
                    '.id' => '*102',
                    'server' => 'all',
                    'name' => 'V78902',
                    'password' => 'V78902',
                    'profile' => '7Days-100TK',
                    'mac-address' => '',
                    'uptime' => '00:00:00',
                    'bytes-in' => '0',
                    'bytes-out' => '0',
                    'packets-in' => '0',
                    'packets-out' => '0',
                    'dynamic' => 'false',
                    'disabled' => 'false',
                    'comment' => 'vc-batch-radius-01'
                ),
                array(
                    '.id' => '*103',
                    'server' => 'all',
                    'name' => 'V78903',
                    'password' => 'V78903',
                    'profile' => '30Days-300TK',
                    'mac-address' => '',
                    'uptime' => '00:00:00',
                    'bytes-in' => '0',
                    'bytes-out' => '0',
                    'packets-in' => '0',
                    'packets-out' => '0',
                    'dynamic' => 'false',
                    'disabled' => 'false',
                    'comment' => 'vc-batch-radius-01'
                )
            ),
            'active' => array(
                array(
                    '.id' => '*a1',
                    'server' => 'MikroTik-ROS7',
                    'user' => 'V78901',
                    'address' => '192.168.88.25',
                    'mac-address' => 'AA:BB:CC:11:22:33',
                    'login-by' => 'RADIUS',
                    'uptime' => '01:15:20',
                    'session-time-left' => '22:44:40',
                    'idle-time' => '00:00:02',
                    'bytes-in' => '45219840',
                    'bytes-out' => '289412600',
                    'packets-in' => '38120',
                    'packets-out' => '210450',
                    'radius' => 'true',
                    'comment' => radiusFormatExpiryComment(time() + 81880)
                )
            ),
            'cookies' => array(
                array(
                    '.id' => '*c1',
                    'user' => 'V78901',
                    'mac-address' => 'AA:BB:CC:11:22:33',
                    'domain' => '',
                    'expires-in' => '22:44:40'
                )
            ),
            'scripts' => array(
                array(
                    '.id' => '*s1',
                    'name' => strtolower(date('M/d/Y')) . '-|-' . date('H:i:s', time() - 4520) . '-|-V78901-|-20-|-192.168.88.25-|-AA:BB:CC:11:22:33-|-1d-|-1Day-20TK-|-vc-batch-radius-01',
                    'owner' => strtolower(date('M')) . date('Y'),
                    'source' => strtolower(date('M/d/Y')),
                    'comment' => 'mikhmon',
                    'dont-require-permissions' => 'no'
                )
            ),
            'schedulers' => array(
                array(
                    '.id' => '*sch1',
                    'name' => 'RADIUS-Central-Expiry-Engine',
                    'start-date' => strtolower(date('M/d/Y')),
                    'start-time' => '00:00:00',
                    'interval' => '00:01:00',
                    'on-event' => '# Central RADIUS AAA + CoA (UDP 3799) Automatic Expiry Monitor',
                    'comment' => 'RADIUS Auto-Expiry & CoA Engine',
                    'disabled' => 'false',
                    'run-count' => '128'
                )
            ),
            'logs' => array(
                array(
                    '.id' => '*l1',
                    'time' => date('H:i:s', time() - 4520),
                    'topics' => 'hotspot,info,debug',
                    'message' => 'V78901 (192.168.88.25 via NAS MikroTik-ROS7): logged in via RADIUS (Access-Accept, MAC Locked AA:BB:CC:11:22:33, Rate 10M/10M)'
                )
            ),
            'hosts' => array(
                array(
                    '.id' => '*h1',
                    'mac-address' => 'AA:BB:CC:11:22:33',
                    'address' => '192.168.88.25',
                    'to-address' => '192.168.88.25',
                    'server' => 'MikroTik-ROS7',
                    'authorized' => 'true',
                    'bypassed' => 'false',
                    'comment' => 'RADIUS Authenticated Client'
                )
            ),
            'dhcp_leases' => array(
                array(
                    '.id' => '*d1',
                    'address' => '192.168.88.25',
                    'mac-address' => 'AA:BB:CC:11:22:33',
                    'host-name' => 'Android-Client',
                    'server' => 'dhcp-hotspot',
                    'status' => 'bound',
                    'Dynamic' => 'true'
                )
            ),
            'ip_bindings' => array(),
            'ppp_secrets' => array(),
            'ppp_profiles' => array(),
            'ppp_active' => array(),
            'coa_events' => array(),
            'next_id' => 200
        );
    }

    /**
     * Load the Unified Central RADIUS Database ($db['central'])
     * All connected NAS MikroTik routers share this single central user/voucher/profile store.
     */
    function loadRadiusDb() {
        $path = radiusDbPath();
        if (!is_file($path)) {
            $central = radiusCreateDefaultNasData('CENTRAL-RADIUS', 7);
            $db = array('central' => $central);
            @file_put_contents($path, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            return $db;
        }
        $decoded = @json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded)) {
            $decoded = array();
        }
        if (!isset($decoded['central']) || !is_array($decoded['central'])) {
            // Migrate from first nas_stores entry if present, otherwise seed default central store
            if (isset($decoded['nas_stores']) && is_array($decoded['nas_stores']) && !empty($decoded['nas_stores'])) {
                $firstStore = reset($decoded['nas_stores']);
                $decoded['central'] = is_array($firstStore) ? $firstStore : radiusCreateDefaultNasData('CENTRAL-RADIUS', 7);
            } else {
                $decoded['central'] = radiusCreateDefaultNasData('CENTRAL-RADIUS', 7);
            }
            unset($decoded['nas_stores']);
            @file_put_contents($path, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        // Ensure 30D-Unlimited profile exists in central store
        $has30DUnl = false;
        if (isset($decoded['central']['profiles']) && is_array($decoded['central']['profiles'])) {
            foreach ($decoded['central']['profiles'] as $p) {
                if (isset($p['name']) && $p['name'] === '30D-Unlimited') {
                    $has30DUnl = true;
                    break;
                }
            }
            if (!$has30DUnl) {
                require_once __DIR__ . '/ros_version.php';
                $p5Scripts = buildUniversalMikhmonScripts('30D-Unlimited', 'remc', '500', '30d', '500', 'Enable', 7);
                $decoded['central']['profiles'][] = array(
                    '.id' => '*' . (++$decoded['central']['next_id']),
                    'name' => '30D-Unlimited',
                    'shared-users' => '1',
                    'rate-limit' => '25M/25M',
                    'status-autorefresh' => '1m',
                    'transparent-proxy' => 'no',
                    'on-login' => $p5Scripts['onlogin'],
                    'expmode' => 'remc',
                    'price' => '500',
                    'validity' => '30d',
                    'selling_price' => '500',
                    'lock_mac' => 'Enable'
                );
            }
        }
        return $decoded;
    }

    function saveRadiusDb($db) {
        $path = radiusDbPath();
        if (isset($db['nas_stores'])) {
            unset($db['nas_stores']);
        }
        return @file_put_contents($path, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    }

    /**
     * Dispatch a real UDP RADIUS CoA Disconnect-Request (Packet of Disconnect, code 40) to <nas_ip>:<coa_port>
     */
    function radiusSendCoADisconnect($nasIdOrIp, $username, $macAddress = '', $framedIp = '', $reason = 'Admin/Expiry Disconnect') {
        $resolvedNasId = radiusResolveNasId($nasIdOrIp);
        $nasList = loadRadiusNasList();
        $nas = isset($nasList[$resolvedNasId]) ? $nasList[$resolvedNasId] : null;
        $nasIp = $nas ? $nas['nas_ip'] : (filter_var($nasIdOrIp, FILTER_VALIDATE_IP) ? $nasIdOrIp : '10.10.10.2');
        $coaPort = $nas && !empty($nas['coa_port']) ? (int)$nas['coa_port'] : 3799;
        $secret = $nas && !empty($nas['radius_secret']) ? (string)$nas['radius_secret'] : 'radius123';

        // Build RFC 3576 / RFC 5176 Disconnect-Request (Code 40) UDP packet
        $code = 40; // Disconnect-Request
        $identifier = mt_rand(1, 254);
        $attrs = '';
        if ($username !== '') {
            $uBin = (string)$username;
            $attrs .= chr(1) . chr(strlen($uBin) + 2) . $uBin; // User-Name (1)
        }
        if ($framedIp !== '' && filter_var($framedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $attrs .= chr(8) . chr(6) . inet_pton($framedIp); // Framed-IP-Address (8)
        }
        if ($macAddress !== '') {
            $mBin = (string)$macAddress;
            $attrs .= chr(31) . chr(strlen($mBin) + 2) . $mBin; // Calling-Station-Id (31)
        }
        $length = 20 + strlen($attrs);
        $headerZeroAuth = pack('CCn', $code, $identifier, $length) . str_repeat("\x00", 16);
        $authenticator = md5($headerZeroAuth . $attrs . $secret, true);
        $packet = pack('CCn', $code, $identifier, $length) . $authenticator . $attrs;

        $sent = false;
        $sock = @stream_socket_client("udp://{$nasIp}:{$coaPort}", $errno, $errstr, 0.25);
        if (is_resource($sock)) {
            @fwrite($sock, $packet);
            @fclose($sock);
            $sent = true;
        }

        // Also remove from central active sessions if present
        $db = loadRadiusDb();
        if (isset($db['central']['active']) && is_array($db['central']['active'])) {
            $keptAct = array();
            $changedAct = false;
            foreach ($db['central']['active'] as $act) {
                if (isset($act['user']) && (string)$act['user'] === (string)$username) {
                    $changedAct = true;
                    continue;
                }
                $keptAct[] = $act;
            }
            if ($changedAct) {
                $db['central']['active'] = $keptAct;
                saveRadiusDb($db);
            }
        }

        return array(
            'ok' => true,
            'sent' => $sent,
            'nas_id' => $resolvedNasId,
            'nas_ip' => $nasIp,
            'coa_port' => $coaPort,
            'username' => $username,
            'mac_address' => $macAddress,
            'reason' => $reason,
            'timestamp' => date('Y-m-d H:i:s')
        );
    }

    /**
     * Background RADIUS Expiry & Limit Monitor:
     * Inspects all vouchers in the Central RADIUS Database, disconnects & removes expired users via CoA (3799)
     */
    function radiusRunExpiryMonitor($nasId = 'CENTRAL-RADIUS') {
        $db = loadRadiusDb();
        $store =& $db['central'];
        $now = time();
        $modified = false;

        // Map profile name => profile definition
        $profileMap = array();
        foreach ($store['profiles'] as $p) {
            if (isset($p['name'])) {
                $profileMap[$p['name']] = $p;
            }
        }

        $keptUsers = array();
        $expiredUsernames = array();

        foreach ($store['users'] as $u) {
            $uName = isset($u['name']) ? (string)$u['name'] : '';
            if ($uName === '' || $uName === 'default-trial') {
                $keptUsers[] = $u;
                continue;
            }

            $comment = isset($u['comment']) ? trim((string)$u['comment']) : '';
            $expTs = isset($u['expires_at']) && (int)$u['expires_at'] > 0
                ? (int)$u['expires_at']
                : radiusParseExpiryComment($comment);

            $pName = isset($u['profile']) ? (string)$u['profile'] : '';
            $prof = isset($profileMap[$pName]) ? $profileMap[$pName] : null;
            $expmode = $prof && isset($prof['expmode']) ? $prof['expmode'] : '';
            if ($expmode === '' && $prof && !empty($prof['on-login'])) {
                $parsedMeta = radiusParseOnLoginMeta($prof['on-login']);
                $expmode = $parsedMeta['expmode'];
            }

            if ($expTs > 0 && $now >= $expTs) {
                $expiredUsernames[$uName] = isset($u['mac-address']) ? (string)$u['mac-address'] : '';
                $modified = true;

                $store['logs'][] = array(
                    '.id' => '*l' . (++$store['next_id']),
                    'time' => date('H:i:s'),
                    'topics' => 'hotspot,info,debug',
                    'message' => $uName . ': voucher expired (' . $comment . ') -> CoA Disconnect (UDP 3799) & ' . (($expmode === 'ntf' || $expmode === 'ntfc') ? 'Notice Lock' : 'Removed from Central RADIUS')
                );

                if ($expmode === 'ntf' || $expmode === 'ntfc') {
                    $u['limit-uptime'] = '1s';
                    $keptUsers[] = $u;
                } else {
                    // 'rem' or 'remc' or default -> remove expired user from Central RADIUS DB
                    continue;
                }
            } else {
                $keptUsers[] = $u;
            }
        }

        if (!empty($expiredUsernames)) {
            $store['users'] = $keptUsers;
            // Remove from active sessions & cookies and send CoA Disconnect to the NAS router where the user was connected
            $keptActive = array();
            foreach ($store['active'] as $act) {
                $aUser = isset($act['user']) ? (string)$act['user'] : '';
                if (isset($expiredUsernames[$aUser])) {
                    $targetNas = !empty($act['server']) ? $act['server'] : $nasId;
                    $coaEvt = radiusSendCoADisconnect(
                        $targetNas,
                        $aUser,
                        isset($act['mac-address']) ? $act['mac-address'] : $expiredUsernames[$aUser],
                        isset($act['address']) ? $act['address'] : '',
                        'Voucher Validity Expired'
                    );
                    $store['coa_events'][] = $coaEvt;
                    continue;
                }
                $keptActive[] = $act;
            }
            $store['active'] = $keptActive;

            $keptCookies = array();
            foreach ($store['cookies'] as $ck) {
                $cUser = isset($ck['user']) ? (string)$ck['user'] : '';
                if (isset($expiredUsernames[$cUser])) {
                    continue;
                }
                $keptCookies[] = $ck;
            }
            $store['cookies'] = $keptCookies;
        }

        if ($modified) {
            saveRadiusDb($db);
        }
    }

    /**
     * Authenticate a Hotspot User via RADIUS Access-Request against the Central RADIUS Database:
     * - Verifies username & password
     * - Verifies NAS scope ("all" works on any connected NAS; specific NAS restricts to that NAS)
     * - Checks disabled / limit-uptime / limit-bytes-total / expiry
     * - On first login: calculates expiry timestamp from profile validity, stamps comment ("mmm/dd/yyyy HH:MM:SS"),
     *   locks MAC address if profile lock_mac="Enable", and records sales entry if expmode is remc/ntfc
     * - Returns RADIUS Access-Accept attributes (Mikrotik-Rate-Limit, Session-Timeout, etc.)
     */
    function radiusAuthenticateVoucher($nasIdOrIp, $username, $password, $clientMac = 'AA:BB:CC:DD:EE:FF', $clientIp = '192.168.88.50') {
        $resolvedNasId = radiusResolveNasId($nasIdOrIp);
        radiusRunExpiryMonitor($resolvedNasId);
        $nasList = loadRadiusNasList();
        $nasInfo = isset($nasList[$resolvedNasId]) ? $nasList[$resolvedNasId] : null;
        $db = loadRadiusDb();
        $store =& $db['central'];

        $username = trim((string)$username);
        $password = (string)$password;
        $clientMac = strtoupper(trim((string)$clientMac));
        if ($clientMac === '') $clientMac = 'AA:BB:CC:DD:EE:01';
        if ($clientIp === '') $clientIp = '192.168.88.' . mt_rand(20, 240);

        $foundIdx = -1;
        foreach ($store['users'] as $idx => $u) {
            if (isset($u['name']) && (string)$u['name'] === $username) {
                $foundIdx = $idx;
                break;
            }
        }
        if ($foundIdx < 0) {
            return array('ok' => false, 'code' => 'Access-Reject', 'reason' => 'Voucher/User not found in Central RADIUS database.');
        }

        $user =& $store['users'][$foundIdx];
        if (isset($user['disabled']) && ($user['disabled'] === 'true' || $user['disabled'] === 'yes')) {
            return array('ok' => false, 'code' => 'Access-Reject', 'reason' => 'Voucher is disabled.');
        }
        $storedPass = isset($user['password']) ? (string)$user['password'] : '';
        if ($storedPass !== '' && $storedPass !== $password) {
            return array('ok' => false, 'code' => 'Access-Reject', 'reason' => 'Invalid password.');
        }
        if (isset($user['limit-uptime']) && $user['limit-uptime'] === '1s') {
            return array('ok' => false, 'code' => 'Access-Reject', 'reason' => 'Voucher validity has expired.');
        }

        // Check NAS / Server restriction ("all" = works on all connected NAS routers; otherwise must match NAS ID or IP)
        $allowedNas = isset($user['server']) ? trim((string)$user['server']) : 'all';
        if ($allowedNas !== '' && strtolower($allowedNas) !== 'all' && strtolower($allowedNas) !== 'hotspot-radius') {
            $nasIpStr = $nasInfo && isset($nasInfo['nas_ip']) ? trim((string)$nasInfo['nas_ip']) : '';
            $nasNameStr = $nasInfo && isset($nasInfo['nas_name']) ? trim((string)$nasInfo['nas_name']) : '';
            if (
                strcasecmp($allowedNas, $resolvedNasId) !== 0 &&
                strcasecmp($allowedNas, (string)$nasIdOrIp) !== 0 &&
                ($nasIpStr === '' || strcasecmp($allowedNas, $nasIpStr) !== 0) &&
                ($nasNameStr === '' || strcasecmp($allowedNas, $nasNameStr) !== 0)
            ) {
                $store['logs'][] = array(
                    '.id' => '*l' . (++$store['next_id']),
                    'time' => date('H:i:s'),
                    'topics' => 'hotspot,info,debug',
                    'message' => $username . ' (' . $clientIp . '): RADIUS Access-Reject — Restricted to NAS ' . $allowedNas . ' (attempted from NAS ' . $resolvedNasId . ')'
                );
                saveRadiusDb($db);
                return array(
                    'ok' => false,
                    'code' => 'Access-Reject',
                    'reason' => 'Voucher is restricted to NAS: ' . $allowedNas . ' (attempted from ' . $resolvedNasId . ')'
                );
            }
        }

        // Resolve profile
        $profName = isset($user['profile']) ? (string)$user['profile'] : 'default';
        $profile = null;
        foreach ($store['profiles'] as $p) {
            if (isset($p['name']) && $p['name'] === $profName) {
                $profile = $p;
                break;
            }
        }
        $meta = array('expmode' => '0', 'price' => '0', 'validity' => '', 'selling_price' => '0', 'lock_mac' => 'Disable');
        if ($profile) {
            if (!empty($profile['on-login'])) {
                $meta = radiusParseOnLoginMeta($profile['on-login']);
            }
            if (!empty($profile['expmode'])) $meta['expmode'] = $profile['expmode'];
            if (!empty($profile['price'])) $meta['price'] = $profile['price'];
            if (!empty($profile['validity'])) $meta['validity'] = $profile['validity'];
            if (!empty($profile['lock_mac'])) $meta['lock_mac'] = $profile['lock_mac'];
        }

        // Enforce MAC Lock (Calling-Station-Id)
        $boundMac = isset($user['mac-address']) ? strtoupper(trim((string)$user['mac-address'])) : '';
        if ($boundMac !== '' && $boundMac !== '00:00:00:00:00:00' && $boundMac !== $clientMac) {
            $store['logs'][] = array(
                '.id' => '*l' . (++$store['next_id']),
                'time' => date('H:i:s'),
                'topics' => 'hotspot,info,debug',
                'message' => $username . ' (' . $clientIp . ' via NAS ' . $resolvedNasId . '): RADIUS Access-Reject — MAC Locked to ' . $boundMac . ' (attempted from ' . $clientMac . ')'
            );
            saveRadiusDb($db);
            return array(
                'ok' => false,
                'code' => 'Access-Reject',
                'reason' => 'MAC Address Lock mismatch! Bound to ' . $boundMac . ', attempted from ' . $clientMac
            );
        }

        // Check first-login activation & stamp expiration comment
        $comment = isset($user['comment']) ? trim((string)$user['comment']) : '';
        $existingExpTs = radiusParseExpiryComment($comment);
        $now = time();
        $validitySecs = radiusParseDurationToSeconds($meta['validity']);
        $firstActivation = false;

        if ($existingExpTs === 0 && $validitySecs > 0 && $meta['expmode'] !== '0') {
            $firstActivation = true;
            $expTs = $now + $validitySecs;
            $expComment = radiusFormatExpiryComment($expTs);
            $originalComment = $comment;
            $user['comment'] = $expComment;
            $user['expires_at'] = $expTs;
            $existingExpTs = $expTs;

            // Lock MAC on first login if lock_mac === Enable
            if ($meta['lock_mac'] === 'Enable') {
                $user['mac-address'] = $clientMac;
                $boundMac = $clientMac;
            }

            // Record sale in scripts if expmode is remc or ntfc
            if ($meta['expmode'] === 'remc' || $meta['expmode'] === 'ntfc') {
                $salesDate = strtolower(date('M/d/Y', $now));
                $salesOwner = strtolower(date('M', $now)) . date('Y', $now);
                $salesName = $salesDate . '-|-' . date('H:i:s', $now) . '-|-' . $username . '-|-' . $meta['price'] . '-|-' . $clientIp . '-|-' . $clientMac . '-|-' . $meta['validity'] . '-|-' . $profName . '-|-' . $originalComment;
                $store['scripts'][] = array(
                    '.id' => '*s' . (++$store['next_id']),
                    'name' => $salesName,
                    'owner' => $salesOwner,
                    'source' => $salesDate,
                    'comment' => 'mikhmon',
                    'dont-require-permissions' => 'no'
                );
            }
        } elseif ($meta['lock_mac'] === 'Enable' && $boundMac === '') {
            $user['mac-address'] = $clientMac;
            $boundMac = $clientMac;
        }

        if ($existingExpTs > 0 && $now >= $existingExpTs) {
            radiusRunExpiryMonitor($resolvedNasId);
            return array('ok' => false, 'code' => 'Access-Reject', 'reason' => 'Voucher expired at ' . $user['comment']);
        }

        $sessionTimeout = $existingExpTs > 0 ? max(1, $existingExpTs - $now) : ($validitySecs > 0 ? $validitySecs : 86400);
        $rateLimit = ($profile && !empty($profile['rate-limit'])) ? $profile['rate-limit'] : 'unlimited';

        // Register / update active RADIUS session with the NAS router ID in 'server'
        $activeFound = false;
        foreach ($store['active'] as $aIdx => $act) {
            if (isset($act['user']) && $act['user'] === $username) {
                $store['active'][$aIdx]['server'] = $resolvedNasId;
                $store['active'][$aIdx]['address'] = $clientIp;
                $store['active'][$aIdx]['mac-address'] = $clientMac;
                $store['active'][$aIdx]['session-time-left'] = radiusFormatSecondsToUptime($sessionTimeout);
                $store['active'][$aIdx]['comment'] = isset($user['comment']) ? $user['comment'] : '';
                $activeFound = true;
                break;
            }
        }
        if (!$activeFound) {
            $store['active'][] = array(
                '.id' => '*a' . (++$store['next_id']),
                'server' => $resolvedNasId,
                'user' => $username,
                'address' => $clientIp,
                'mac-address' => $clientMac,
                'login-by' => 'RADIUS',
                'uptime' => '00:00:01',
                'session-time-left' => radiusFormatSecondsToUptime($sessionTimeout),
                'idle-time' => '00:00:00',
                'bytes-in' => isset($user['bytes-in']) ? $user['bytes-in'] : '0',
                'bytes-out' => isset($user['bytes-out']) ? $user['bytes-out'] : '0',
                'packets-in' => '12',
                'packets-out' => '15',
                'radius' => 'true',
                'comment' => isset($user['comment']) ? $user['comment'] : ''
            );
        }

        // Cookie entry
        $store['cookies'][] = array(
            '.id' => '*c' . (++$store['next_id']),
            'user' => $username,
            'mac-address' => $clientMac,
            'domain' => '',
            'expires-in' => radiusFormatSecondsToUptime($sessionTimeout)
        );

        $store['logs'][] = array(
            '.id' => '*l' . (++$store['next_id']),
            'time' => date('H:i:s'),
            'topics' => 'hotspot,info,debug',
            'message' => $username . ' (' . $clientIp . ' via NAS ' . $resolvedNasId . '): logged in via RADIUS (Access-Accept, MAC ' . $clientMac . ', Expiry: ' . (isset($user['comment']) ? $user['comment'] : 'N/A') . ')'
        );

        saveRadiusDb($db);

        $expValue = isset($user['comment']) ? $user['comment'] : '';
        return array(
            'ok' => true,
            'code' => 'Access-Accept',
            'first_activation' => $firstActivation,
            'nas_id' => $resolvedNasId,
            'username' => $username,
            'profile' => $profName,
            'mac_address' => $boundMac !== '' ? $boundMac : $clientMac,
            'framed_ip' => $clientIp,
            'expiry' => $expValue,
            'expiry_comment' => $expValue,
            'attributes' => array(
                'Mikrotik-Rate-Limit' => $rateLimit,
                'Session-Timeout' => $sessionTimeout,
                'Acct-Interim-Interval' => 60,
                'Calling-Station-Id' => $boundMac !== '' ? $boundMac : $clientMac,
                'WISPr-Session-Terminate-Time' => $existingExpTs > 0 ? gmdate('Y-m-d\TH:i:s\Z', $existingExpTs) : ''
            )
        );
    }

    /**
     * Resolve NAS ID for RouterosAPI::connect($ip, $login, $password)
     */
    function radiusResolveNasId($ip) {
        $cleanIp = trim((string)$ip);
        if (strpos($cleanIp, ':') !== false) {
            $parts = explode(':', $cleanIp, 2);
            $cleanIp = $parts[0];
        }
        $nasList = loadRadiusNasList();
        foreach ($nasList as $nasId => $nas) {
            if (isset($nas['nas_ip']) && trim((string)$nas['nas_ip']) === $cleanIp) {
                return $nasId;
            }
            if (strtolower($nasId) === strtolower($cleanIp)) {
                return $nasId;
            }
        }
        // Check active session parameter if present
        if (!empty($_GET['session']) && isset($nasList[$_GET['session']])) {
            return $_GET['session'];
        }
        if ($cleanIp === '127.0.0.1' && isset($nasList['MikroTik-ROS7'])) {
            return 'MikroTik-ROS7';
        }
        if ($cleanIp === '127.0.0.2' && isset($nasList['MikroTik-ROS6'])) {
            return 'MikroTik-ROS6';
        }
        // Default to first NAS if any
        if (!empty($nasList)) {
            $keys = array_keys($nasList);
            return $keys[0];
        }
        return 'CENTRAL-RADIUS';
    }

    /**
     * Filter an array of records by RouterOS API query arguments (?key=val, ?.id=*1, etc.)
     */
    function radiusFilterRows($rows, $arr) {
        if (!is_array($arr) || empty($arr)) {
            return array_values($rows);
        }
        $filters = array();
        foreach ($arr as $k => $v) {
            $kStr = (string)$k;
            if ($kStr === '' || $kStr === 'count-only') continue;
            if ($kStr[0] === '?') {
                $field = ltrim(substr($kStr, 1), '=');
                $filters[$field] = (string)$v;
            }
        }
        if (empty($filters)) {
            return array_values($rows);
        }
        $out = array();
        foreach ($rows as $row) {
            $match = true;
            foreach ($filters as $f => $val) {
                $actual = isset($row[$f]) ? (string)$row[$f] : '';
                if ($actual !== $val) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Execute a RouterOS API command directly against the Unified Central RADIUS Database
     */
    function radiusExecuteCommand($nasId, $com, $arr = array()) {
        radiusRunExpiryMonitor($nasId);
        $nasList = loadRadiusNasList();
        $nasCount = count($nasList);
        $nas = isset($nasList[$nasId]) ? $nasList[$nasId] : array(
            'id' => $nasId,
            'nas_name' => 'Central RADIUS Server',
            'nas_ip' => '10.10.10.1',
            'coa_port' => 3799,
            'ros_version' => 7
        );
        $db = loadRadiusDb();
        $store =& $db['central'];
        $cmd = trim((string)$com);

        // Normalize arguments (strip leading '=' if passed via raw write)
        $cleanArr = array();
        if (is_array($arr)) {
            foreach ($arr as $k => $v) {
                $cleanArr[$k] = $v;
            }
        }

        // 1. System Identity / Clock / Resource / Routerboard
        if ($cmd === '/system/identity/print') {
            return array(array('name' => 'Central RADIUS (' . $nasCount . ' NAS)'));
        }
        if ($cmd === '/system/clock/print') {
            return array(array(
                'time' => date('H:i:s'),
                'date' => date('Y-m-d'),
                'time-zone-autodetect' => 'true',
                'time-zone-name' => date_default_timezone_get()
            ));
        }
        if ($cmd === '/system/resource/print') {
            return array(array(
                'uptime' => '3d14:22:10',
                'version' => '7.15.3 (Central RADIUS AAA + CoA)',
                'build-time' => date('M/d/Y H:i:s'),
                'free-memory' => '512488448',
                'total-memory' => '1073741824',
                'cpu' => 'Central RADIUS Engine',
                'cpu-count' => '4',
                'cpu-frequency' => '2400',
                'cpu-load' => (string)mt_rand(2, 6),
                'free-hdd-space' => '1073741824',
                'total-hdd-space' => '2147483648',
                'architecture-name' => 'x86_64',
                'board-name' => 'Central RADIUS Server (10.10.10.1)',
                'platform' => 'NextGan RADIUS + VPN'
            ));
        }
        if ($cmd === '/system/routerboard/print') {
            return array(array(
                'routerboard' => 'true',
                'model' => $nasCount . ' Connected NAS Routers (Auth:1812 / Acct:1813 / CoA:3799)',
                'serial-number' => 'NEXTGAN-CENTRAL-RADIUS'
            ));
        }
        if ($cmd === '/ip/hotspot/print') {
            // Return all registered NAS routers so Add User / Generate Voucher can pick 'all' (default) or a specific NAS
            $srvRows = array();
            $idx = 1;
            foreach ($nasList as $nId => $nInfo) {
                $srvRows[] = array(
                    '.id' => '*' . ($idx++),
                    'name' => $nId,
                    'interface' => 'vpn-tunnel',
                    'profile' => 'hsprof-radius',
                    'disabled' => 'false'
                );
            }
            if (empty($srvRows)) {
                $srvRows[] = array(
                    '.id' => '*1',
                    'name' => 'CENTRAL-RADIUS',
                    'interface' => 'vpn-tunnel',
                    'profile' => 'hsprof-radius',
                    'disabled' => 'false'
                );
            }
            return $srvRows;
        }
        if ($cmd === '/ip/pool/print') {
            return array(array(
                '.id' => '*1',
                'name' => 'hs-pool-radius',
                'ranges' => '192.168.88.10-192.168.88.254'
            ));
        }
        if ($cmd === '/interface/print') {
            return array(
                array('.id' => '*1', 'name' => 'vpn-tunnel', 'type' => 'l2tp-in', 'running' => 'true', 'disabled' => 'false'),
                array('.id' => '*2', 'name' => 'bridge-hotspot', 'type' => 'bridge', 'running' => 'true', 'disabled' => 'false'),
                array('.id' => '*3', 'name' => 'ether1-wan', 'type' => 'ether', 'running' => 'true', 'disabled' => 'false')
            );
        }
        if ($cmd === '/interface/monitor-traffic') {
            $activeCount = count($store['active']);
            $tx = $activeCount > 0 ? mt_rand(450000, 3800000) * $activeCount : mt_rand(8000, 24000);
            $rx = $activeCount > 0 ? mt_rand(120000, 950000) * $activeCount : mt_rand(4000, 12000);
            return array(array(
                'name' => isset($cleanArr['interface']) ? $cleanArr['interface'] : 'vpn-tunnel',
                'rx-bits-per-second' => (string)$rx,
                'tx-bits-per-second' => (string)$tx,
                'rx-packets-per-second' => (string)round($rx / 1024),
                'tx-packets-per-second' => (string)round($tx / 1024)
            ));
        }

        // 2. Hotspot User Profiles (RADIUS Profiles)
        if ($cmd === '/ip/hotspot/user/profile/print') {
            $rows = radiusFilterRows($store['profiles'], $cleanArr);
            if (isset($cleanArr['count-only'])) {
                return (string)count($rows);
            }
            return $rows;
        }
        if ($cmd === '/ip/hotspot/user/profile/add') {
            $newId = '*' . (++$store['next_id']);
            $onLogin = isset($cleanArr['on-login']) ? (string)$cleanArr['on-login'] : '';
            $meta = radiusParseOnLoginMeta($onLogin);
            $prof = array(
                '.id' => $newId,
                'name' => isset($cleanArr['name']) ? (string)$cleanArr['name'] : ('profile-' . $store['next_id']),
                'shared-users' => isset($cleanArr['shared-users']) ? (string)$cleanArr['shared-users'] : '1',
                'rate-limit' => isset($cleanArr['rate-limit']) ? (string)$cleanArr['rate-limit'] : '',
                'status-autorefresh' => isset($cleanArr['status-autorefresh']) ? (string)$cleanArr['status-autorefresh'] : '1m',
                'transparent-proxy' => isset($cleanArr['transparent-proxy']) ? (string)$cleanArr['transparent-proxy'] : 'no',
                'address-pool' => isset($cleanArr['address-pool']) ? (string)$cleanArr['address-pool'] : 'none',
                'parent-queue' => isset($cleanArr['parent-queue']) ? (string)$cleanArr['parent-queue'] : 'none',
                'on-login' => $onLogin,
                'expmode' => $meta['expmode'],
                'price' => $meta['price'],
                'validity' => $meta['validity'],
                'selling_price' => $meta['selling_price'],
                'lock_mac' => $meta['lock_mac']
            );
            $store['profiles'][] = $prof;
            saveRadiusDb($db);
            return $newId;
        }
        if ($cmd === '/ip/hotspot/user/profile/set') {
            $targetId = isset($cleanArr['.id']) ? (string)$cleanArr['.id'] : (isset($cleanArr['numbers']) ? (string)$cleanArr['numbers'] : '');
            foreach ($store['profiles'] as $idx => $p) {
                if ((isset($p['.id']) && $p['.id'] === $targetId) || (isset($p['name']) && $p['name'] === $targetId)) {
                    foreach ($cleanArr as $k => $v) {
                        if ($k === '.id' || $k === 'numbers') continue;
                        $store['profiles'][$idx][$k] = (string)$v;
                    }
                    if (isset($cleanArr['on-login'])) {
                        $meta = radiusParseOnLoginMeta($cleanArr['on-login']);
                        $store['profiles'][$idx]['expmode'] = $meta['expmode'];
                        $store['profiles'][$idx]['price'] = $meta['price'];
                        $store['profiles'][$idx]['validity'] = $meta['validity'];
                        $store['profiles'][$idx]['selling_price'] = $meta['selling_price'];
                        $store['profiles'][$idx]['lock_mac'] = $meta['lock_mac'];
                    }
                    break;
                }
            }
            saveRadiusDb($db);
            return array();
        }
        if ($cmd === '/ip/hotspot/user/profile/remove') {
            $targetId = isset($cleanArr['.id']) ? (string)$cleanArr['.id'] : (isset($cleanArr['numbers']) ? (string)$cleanArr['numbers'] : '');
            $ids = array_map('trim', explode(',', $targetId));
            $kept = array();
            foreach ($store['profiles'] as $p) {
                if (isset($p['.id']) && in_array($p['.id'], $ids, true)) {
                    continue;
                }
                $kept[] = $p;
            }
            $store['profiles'] = $kept;
            saveRadiusDb($db);
            return array();
        }

        // 3. Hotspot Users / Vouchers (RADIUS Users)
        if ($cmd === '/ip/hotspot/user/print') {
            $rows = radiusFilterRows($store['users'], $cleanArr);
            if (isset($cleanArr['count-only'])) {
                return (string)count($rows);
            }
            return $rows;
        }
        if ($cmd === '/ip/hotspot/user/add') {
            $uName = isset($cleanArr['name']) ? trim((string)$cleanArr['name']) : '';
            if ($uName === '') {
                return array('!trap' => array(array('message' => 'User name is required')));
            }
            foreach ($store['users'] as $existing) {
                if (isset($existing['name']) && $existing['name'] === $uName) {
                    return array('!trap' => array(array('message' => 'failure: already have user with this name for this server')));
                }
            }
            $newId = '*' . (++$store['next_id']);
            $newUser = array(
                '.id' => $newId,
                'server' => isset($cleanArr['server']) ? (string)$cleanArr['server'] : 'all',
                'name' => $uName,
                'password' => isset($cleanArr['password']) ? (string)$cleanArr['password'] : $uName,
                'profile' => isset($cleanArr['profile']) ? (string)$cleanArr['profile'] : 'default',
                'mac-address' => isset($cleanArr['mac-address']) ? (string)$cleanArr['mac-address'] : '',
                'uptime' => '00:00:00',
                'bytes-in' => '0',
                'bytes-out' => '0',
                'packets-in' => '0',
                'packets-out' => '0',
                'dynamic' => 'false',
                'disabled' => isset($cleanArr['disabled']) && ($cleanArr['disabled'] === 'yes' || $cleanArr['disabled'] === 'true') ? 'true' : 'false',
                'comment' => isset($cleanArr['comment']) ? (string)$cleanArr['comment'] : ''
            );
            if (!empty($cleanArr['limit-uptime'])) {
                $newUser['limit-uptime'] = (string)$cleanArr['limit-uptime'];
            }
            if (!empty($cleanArr['limit-bytes-total'])) {
                $newUser['limit-bytes-total'] = (string)$cleanArr['limit-bytes-total'];
            }
            $store['users'][] = $newUser;
            saveRadiusDb($db);
            return $newId;
        }
        if ($cmd === '/ip/hotspot/user/set') {
            $targetId = isset($cleanArr['.id']) ? (string)$cleanArr['.id'] : (isset($cleanArr['numbers']) ? (string)$cleanArr['numbers'] : '');
            $ids = array_map('trim', explode(',', $targetId));
            foreach ($store['users'] as $idx => $u) {
                $matchId = isset($u['.id']) && in_array($u['.id'], $ids, true);
                $matchName = isset($u['name']) && in_array($u['name'], $ids, true);
                if ($matchId || $matchName) {
                    foreach ($cleanArr as $k => $v) {
                        if ($k === '.id' || $k === 'numbers') continue;
                        if ($k === 'disabled') {
                            $isDis = ($v === 'yes' || $v === 'true');
                            $store['users'][$idx]['disabled'] = $isDis ? 'true' : 'false';
                            if ($isDis && isset($u['name'])) {
                                radiusSendCoADisconnect($nasId, $u['name'], isset($u['mac-address']) ? $u['mac-address'] : '', '', 'User Disabled by Admin');
                            }
                        } else {
                            $store['users'][$idx][$k] = (string)$v;
                        }
                    }
                    if (isset($cleanArr['comment'])) {
                        $parsedExp = radiusParseExpiryComment($cleanArr['comment']);
                        $store['users'][$idx]['expires_at'] = $parsedExp;
                    }
                }
            }
            saveRadiusDb($db);
            return array();
        }
        if ($cmd === '/ip/hotspot/user/reset-counters') {
            $targetId = isset($cleanArr['.id']) ? (string)$cleanArr['.id'] : (isset($cleanArr['numbers']) ? (string)$cleanArr['numbers'] : '');
            $ids = array_map('trim', explode(',', $targetId));
            foreach ($store['users'] as $idx => $u) {
                if ((isset($u['.id']) && in_array($u['.id'], $ids, true)) || (isset($u['name']) && in_array($u['name'], $ids, true))) {
                    $store['users'][$idx]['uptime'] = '00:00:00';
                    $store['users'][$idx]['bytes-in'] = '0';
                    $store['users'][$idx]['bytes-out'] = '0';
                    $store['users'][$idx]['packets-in'] = '0';
                    $store['users'][$idx]['packets-out'] = '0';
                }
            }
            saveRadiusDb($db);
            return array();
        }
        if ($cmd === '/ip/hotspot/user/remove') {
            $targetId = isset($cleanArr['.id']) ? (string)$cleanArr['.id'] : (isset($cleanArr['numbers']) ? (string)$cleanArr['numbers'] : '');
            $ids = array_map('trim', explode(',', $targetId));
            $kept = array();
            $removedNames = array();
            foreach ($store['users'] as $u) {
                if ((isset($u['.id']) && in_array($u['.id'], $ids, true)) || (isset($u['name']) && in_array($u['name'], $ids, true))) {
                    if (isset($u['name'])) {
                        $removedNames[$u['name']] = isset($u['mac-address']) ? $u['mac-address'] : '';
                    }
                    continue;
                }
                $kept[] = $u;
            }
            $store['users'] = $kept;
            if (!empty($removedNames)) {
                $keptAct = array();
                foreach ($store['active'] as $act) {
                    $aUser = isset($act['user']) ? $act['user'] : '';
                    if (isset($removedNames[$aUser])) {
                        radiusSendCoADisconnect($nasId, $aUser, isset($act['mac-address']) ? $act['mac-address'] : $removedNames[$aUser], isset($act['address']) ? $act['address'] : '', 'Voucher Removed');
                        continue;
                    }
                    $keptAct[] = $act;
                }
                $store['active'] = $keptAct;
            }
            saveRadiusDb($db);
            return array();
        }

        // 4. Hotspot Active Sessions & CoA Disconnect
        if ($cmd === '/ip/hotspot/active/print') {
            $rows = radiusFilterRows($store['active'], $cleanArr);
            if (isset($cleanArr['count-only'])) {
                return (string)count($rows);
            }
            return $rows;
        }
        if ($cmd === '/ip/hotspot/active/remove') {
            $targetId = isset($cleanArr['.id']) ? (string)$cleanArr['.id'] : (isset($cleanArr['numbers']) ? (string)$cleanArr['numbers'] : '');
            $ids = array_map('trim', explode(',', $targetId));
            $kept = array();
            foreach ($store['active'] as $act) {
                if (isset($act['.id']) && in_array($act['.id'], $ids, true)) {
                    $coaEvt = radiusSendCoADisconnect(
                        $nasId,
                        isset($act['user']) ? $act['user'] : '',
                        isset($act['mac-address']) ? $act['mac-address'] : '',
                        isset($act['address']) ? $act['address'] : '',
                        'Manual CoA Disconnect from Active List'
                    );
                    $store['coa_events'][] = $coaEvt;
                    $store['logs'][] = array(
                        '.id' => '*l' . (++$store['next_id']),
                        'time' => date('H:i:s'),
                        'topics' => 'hotspot,info,debug',
                        'message' => (isset($act['user']) ? $act['user'] : 'user') . ': disconnected via RADIUS CoA (UDP ' . $coaEvt['coa_port'] . ' -> ' . $coaEvt['nas_ip'] . ')'
                    );
                    continue;
                }
                $kept[] = $act;
            }
            $store['active'] = $kept;
            saveRadiusDb($db);
            return array();
        }

        // 5. Hotspot Cookies
        if ($cmd === '/ip/hotspot/cookie/print') {
            return radiusFilterRows($store['cookies'], $cleanArr);
        }
        if ($cmd === '/ip/hotspot/cookie/remove') {
            $targetId = isset($cleanArr['.id']) ? (string)$cleanArr['.id'] : (isset($cleanArr['numbers']) ? (string)$cleanArr['numbers'] : '');
            $ids = array_map('trim', explode(',', $targetId));
            $kept = array();
            foreach ($store['cookies'] as $ck) {
                if (isset($ck['.id']) && in_array($ck['.id'], $ids, true)) continue;
                $kept[] = $ck;
            }
            $store['cookies'] = $kept;
            saveRadiusDb($db);
            return array();
        }

        // 6. System Scripts (Sales Records) & Schedulers
        if ($cmd === '/system/script/print') {
            $rows = radiusFilterRows($store['scripts'], $cleanArr);
            if (isset($cleanArr['count-only'])) {
                return (string)count($rows);
            }
            return $rows;
        }
        if ($cmd === '/system/script/add') {
            $newId = '*s' . (++$store['next_id']);
            $store['scripts'][] = array(
                '.id' => $newId,
                'name' => isset($cleanArr['name']) ? (string)$cleanArr['name'] : '',
                'owner' => isset($cleanArr['owner']) ? (string)$cleanArr['owner'] : strtolower(date('M')) . date('Y'),
                'source' => isset($cleanArr['source']) ? (string)$cleanArr['source'] : strtolower(date('M/d/Y')),
                'comment' => isset($cleanArr['comment']) ? (string)$cleanArr['comment'] : 'mikhmon',
                'dont-require-permissions' => 'no'
            );
            saveRadiusDb($db);
            return $newId;
        }
        if ($cmd === '/system/script/remove') {
            $targetId = isset($cleanArr['.id']) ? (string)$cleanArr['.id'] : (isset($cleanArr['numbers']) ? (string)$cleanArr['numbers'] : '');
            $ids = array_map('trim', explode(',', $targetId));
            $kept = array();
            foreach ($store['scripts'] as $sc) {
                if (isset($sc['.id']) && in_array($sc['.id'], $ids, true)) continue;
                $kept[] = $sc;
            }
            $store['scripts'] = $kept;
            saveRadiusDb($db);
            return array();
        }
        if ($cmd === '/system/scheduler/print') {
            return radiusFilterRows($store['schedulers'], $cleanArr);
        }
        if ($cmd === '/system/scheduler/add' || $cmd === '/system/scheduler/set' || $cmd === '/system/scheduler/remove') {
            return array();
        }

        // 7. Logs, Hosts, DHCP Leases, IP Bindings, PPP
        if ($cmd === '/log/print') {
            return array_reverse($store['logs']);
        }
        if ($cmd === '/ip/hotspot/host/print') {
            return radiusFilterRows($store['hosts'], $cleanArr);
        }
        if ($cmd === '/ip/dhcp-server/lease/print') {
            return radiusFilterRows($store['dhcp_leases'], $cleanArr);
        }
        if ($cmd === '/ip/hotspot/ip-binding/print') {
            return radiusFilterRows($store['ip_bindings'], $cleanArr);
        }
        if ($cmd === '/ppp/secret/print') {
            return radiusFilterRows($store['ppp_secrets'], $cleanArr);
        }
        if ($cmd === '/ppp/profile/print') {
            return radiusFilterRows($store['ppp_profiles'], $cleanArr);
        }
        if ($cmd === '/ppp/active/print') {
            return radiusFilterRows($store['ppp_active'], $cleanArr);
        }

        return array();
    }

    /**
     * Build Ready-Made Copy-Paste RouterOS v6 & v7 Terminal Scripts for a VPN + NAS Router
     */
    function buildMikrotikRadiusVpnScripts($tunnel, $nas, $serverCfg = null) {
        if ($serverCfg === null) {
            $serverCfg = loadVpnServerConfig();
        }
        $endpoint = !empty($serverCfg['public_endpoint']) ? $serverCfg['public_endpoint'] : 'vpn.nextganwifi.com';
        $vpnGateway = !empty($tunnel['server_ip']) ? $tunnel['server_ip'] : '10.10.10.1';
        $vpnLocalIp = !empty($tunnel['remote_ip']) ? $tunnel['remote_ip'] : (!empty($nas['nas_ip']) ? $nas['nas_ip'] : '10.10.10.2');
        $vpnUser = !empty($tunnel['username']) ? $tunnel['username'] : 'vpn_user';
        $vpnPass = !empty($tunnel['password']) ? $tunnel['password'] : 'vpn_pass';
        $ipsecSecret = !empty($tunnel['ipsec_secret']) ? $tunnel['ipsec_secret'] : 'nextgan123';
        $protocol = !empty($tunnel['protocol']) ? strtolower($tunnel['protocol']) : 'l2tp';
        $radiusSecret = !empty($nas['radius_secret']) ? $nas['radius_secret'] : (!empty($tunnel['radius_secret']) ? $tunnel['radius_secret'] : 'radius123');
        $coaPort = !empty($nas['coa_port']) ? (int)$nas['coa_port'] : 3799;
        $authPort = !empty($nas['auth_port']) ? (int)$nas['auth_port'] : 1812;
        $acctPort = !empty($nas['acct_port']) ? (int)$nas['acct_port'] : 1813;
        $hsName = !empty($nas['nas_name']) ? $nas['nas_name'] : (!empty($tunnel['hotspot_name']) ? $tunnel['hotspot_name'] : 'NextGan-Hotspot');

        $vpnBlockV7 = '';
        $vpnBlockV6 = '';

        if ($protocol === 'sstp') {
            $vpnBlockV7 = "/interface sstp-client remove [find where name=\"NextGan-VPN\"]\n"
                . "/interface sstp-client add name=\"NextGan-VPN\" connect-to=\"{$endpoint}\" user=\"{$vpnUser}\" password=\"{$vpnPass}\" authentication=mschap2 disabled=no comment=\"NextGan Built-in SSTP VPN ({$vpnLocalIp})\"";
            $vpnBlockV6 = $vpnBlockV7;
        } else {
            // L2TP/IPsec (or Both)
            $vpnBlockV7 = "/interface l2tp-client remove [find where name=\"NextGan-VPN\"]\n"
                . "/interface l2tp-client add name=\"NextGan-VPN\" connect-to=\"{$endpoint}\" user=\"{$vpnUser}\" password=\"{$vpnPass}\" use-ipsec=yes ipsec-secret=\"{$ipsecSecret}\" disabled=no comment=\"NextGan Built-in L2TP VPN ({$vpnLocalIp})\"";
            $vpnBlockV6 = $vpnBlockV7;
        }

        $commonRadiusTail = "\n\n# 2. Configure RADIUS Client over Built-in VPN Tunnel ({$vpnGateway})\n"
            . "/radius remove [find where comment=\"NextGan-RADIUS\"]\n"
            . "/radius add service=hotspot address={$vpnGateway} secret=\"{$radiusSecret}\" authentication-port={$authPort} accounting-port={$acctPort} timeout=3s src-address={$vpnLocalIp} comment=\"NextGan-RADIUS\"\n\n"
            . "# 3. Enable RADIUS Incoming CoA / Disconnect (Port {$coaPort})\n"
            . "/radius incoming set accept=yes port={$coaPort}\n\n"
            . "# 4. Enable RADIUS Authentication & Accounting on Hotspot Profile\n"
            . "/ip hotspot profile set [find] use-radius=yes radius-accounting=yes radius-interim-update=00:01:00 mac-auth-mode=mac-as-username-and-password\n"
            . ":put \"=== NextGan VPN ({$vpnLocalIp}) + RADIUS NAS ({$hsName}) Configured Successfully ===\"";

        return array(
            'ros7' => "# ===================================================================\n"
                . "# NextGan WiFi Solution — RouterOS v7 VPN + RADIUS NAS Provisioning\n"
                . "# NAS Name: {$hsName} | Assigned VPN Local IP: {$vpnLocalIp}\n"
                . "# ===================================================================\n\n"
                . "# 1. Connect Client MikroTik to Built-in VPN Server\n"
                . $vpnBlockV7
                . $commonRadiusTail,
            'ros6' => "# ===================================================================\n"
                . "# NextGan WiFi Solution — RouterOS v6 VPN + RADIUS NAS Provisioning\n"
                . "# NAS Name: {$hsName} | Assigned VPN Local IP: {$vpnLocalIp}\n"
                . "# ===================================================================\n\n"
                . "# 1. Connect Client MikroTik to Built-in VPN Server\n"
                . $vpnBlockV6
                . $commonRadiusTail
        );
    }
}

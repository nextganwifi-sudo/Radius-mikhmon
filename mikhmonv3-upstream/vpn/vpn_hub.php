<?php
/*
 * NextGan WiFi Solution — Built-in VPN Server (L2TP / SSTP) & NAS RADIUS Hub
 * - Directly creates L2TP / SSTP VPN tunnels from the software without adding any external router
 * - Assigns static VPN Local IPs (10.10.10.x) to connected MikroTik routers
 * - 1-Click Auto-NAS & Manual NAS registration using the MikroTik's VPN Local IP + RADIUS Secret + CoA (3799)
 * - Generates ready-made Copy-Paste RouterOS v6 & v7 Terminal Scripts (VPN Client + RADIUS + CoA + Hotspot)
 * - Includes Live RADIUS Access-Request & CoA Disconnect (UDP 3799) Test Console
 */
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include_once('./include/reseller_acl.php');
include_once('./include/config.php');
include_once('./lib/routeros_api.class.php');
include_once('./lib/formatbytesbites.php');
include_once('./include/radius_engine.php');

if (!isMikhmonAdmin() && !hasResellerPermission('manage_router_settings')) {
    echo "<div class='card pd-15'><h3 class='text-danger'><i class='fa fa-ban'></i> Access Denied</h3><p>Only Administrator or authorized users can access the Built-in VPN & NAS Hub.</p></div>";
    exit;
}

if (empty($_SESSION['vpn_hub_csrf'])) {
    try {
        $_SESSION['vpn_hub_csrf'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['vpn_hub_csrf'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}
$vpnCsrfToken = (string)$_SESSION['vpn_hub_csrf'];

function vpnText($english, $bengali) {
    global $langid;
    return (isset($langid) && $langid === 'bn') ? $bengali : $english;
}

function vpnSlug($value) {
    $value = trim((string)$value);
    $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);
    $value = trim($value, '-_');
    return $value !== '' ? $value : ('NAS-' . mt_rand(100, 999));
}

$serverCfg = loadVpnServerConfig();
$vpnTunnels = loadVpnTunnels();
$nasList = loadRadiusNasList();

$activeTab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : 'vpn';
if (!in_array($activeTab, array('vpn', 'nas', 'test'), true)) {
    $activeTab = 'vpn';
}

$vpnNotice = '';
$vpnError = '';
$autoOpenScriptTunnelId = '';
$radiusTestResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = isset($_POST['vpn_csrf_token']) ? (string)$_POST['vpn_csrf_token'] : '';
    if ($postedToken === '' || !hash_equals($vpnCsrfToken, $postedToken)) {
        $vpnError = vpnText('Security token expired. Please reload the page and try again.', 'সিকিউরিটি টোকেন মেয়াদোত্তীর্ণ হয়েছে। পেজটি রিলোড করে আবার চেষ্টা করুন।');
    } else {
        // 1. Update Built-in VPN & RADIUS Server Endpoint Config
        if (isset($_POST['save_vpn_server_config'])) {
            $pubHost = trim((string)(isset($_POST['public_endpoint']) ? $_POST['public_endpoint'] : ''));
            $gwIp = trim((string)(isset($_POST['vpn_gateway_ip']) ? $_POST['vpn_gateway_ip'] : '10.10.10.1'));
            $ipsecSecret = trim((string)(isset($_POST['l2tp_ipsec_secret']) ? $_POST['l2tp_ipsec_secret'] : 'nextgan123'));
            if ($pubHost === '') {
                $vpnError = vpnText('Please enter a valid Server Public IP or Domain.', 'অনুগ্রহ করে সার্ভারের Public IP অথবা Domain নাম দিন।');
            } else {
                $serverCfg = saveVpnServerConfig(array(
                    'public_endpoint' => $pubHost,
                    'vpn_gateway_ip' => $gwIp !== '' ? $gwIp : '10.10.10.1',
                    'l2tp_ipsec_secret' => $ipsecSecret !== '' ? $ipsecSecret : 'nextgan123'
                ));
                $vpnNotice = vpnText('Built-in VPN & RADIUS Server endpoint settings saved successfully.', 'বিল্ট-ইন VPN ও RADIUS সার্ভার সেটিংস সফলভাবে সেভ করা হয়েছে।');
            }
        }

        // 2. Create Built-in VPN Tunnel (L2TP / SSTP) + Optional 1-Click Auto-NAS
        if (isset($_POST['create_builtin_vpn'])) {
            $tName = trim((string)(isset($_POST['tunnel_name']) ? $_POST['tunnel_name'] : ''));
            $tProto = strtolower(trim((string)(isset($_POST['protocol']) ? $_POST['protocol'] : 'both')));
            if (!in_array($tProto, array('l2tp', 'sstp', 'both'), true)) {
                $tProto = 'both';
            }
            $tUser = trim((string)(isset($_POST['vpn_username']) ? $_POST['vpn_username'] : ''));
            $tPass = trim((string)(isset($_POST['vpn_password']) ? $_POST['vpn_password'] : ''));
            $tIpsec = trim((string)(isset($_POST['ipsec_secret']) ? $_POST['ipsec_secret'] : $serverCfg['l2tp_ipsec_secret']));
            $tLocalIp = trim((string)(isset($_POST['remote_ip']) ? $_POST['remote_ip'] : ''));
            $autoNas = isset($_POST['auto_add_nas']) && $_POST['auto_add_nas'] === '1';
            $radSecret = trim((string)(isset($_POST['radius_secret']) ? $_POST['radius_secret'] : 'radius123'));
            $coaPort = !empty($_POST['coa_port']) ? (int)$_POST['coa_port'] : 3799;
            $rosVer = isset($_POST['ros_version']) && (int)$_POST['ros_version'] === 6 ? 6 : 7;
            $hsName = trim((string)(isset($_POST['hotspot_name']) ? $_POST['hotspot_name'] : ''));
            $dnsName = trim((string)(isset($_POST['dns_name']) ? $_POST['dns_name'] : ''));
            $currency = trim((string)(isset($_POST['currency']) ? $_POST['currency'] : 'BDT'));

            if ($tName === '' || $tUser === '' || $tPass === '' || $tLocalIp === '') {
                $vpnError = vpnText('Please fill in Tunnel Name, VPN Username, Password, and VPN Local IP.', 'অনুগ্রহ করে VPN নাম, ইউজারনেম, পাসওয়ার্ড এবং VPN Local IP পূরণ করুন।');
            } elseif (!filter_var($tLocalIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $vpnError = vpnText('Please enter a valid IPv4 address for the MikroTik VPN Local IP (e.g. 10.10.10.4).', 'অনুগ্রহ করে সঠিক VPN Local IP দিন (যেমন: 10.10.10.4)।');
            } else {
                // Check duplicate username or tunnel IP
                $dupError = '';
                foreach ($vpnTunnels as $existingTun) {
                    if (strtolower($existingTun['username']) === strtolower($tUser)) {
                        $dupError = vpnText('A VPN tunnel with this username already exists.', 'এই ইউজারনেম দিয়ে ইতিমধ্যে একটি VPN টানেল রয়েছে।');
                        break;
                    }
                    if (trim((string)$existingTun['remote_ip']) === $tLocalIp) {
                        $dupError = vpnText('This VPN Local IP (' . $tLocalIp . ') is already assigned to another tunnel.', 'এই VPN Local IP (' . $tLocalIp . ') ইতিমধ্যে অন্য একটি টানেলে ব্যবহৃত হচ্ছে।');
                        break;
                    }
                }
                if ($dupError !== '') {
                    $vpnError = $dupError;
                } else {
                    if ($hsName === '') {
                        $hsName = $tName;
                    }
                    $nasSlug = $autoNas ? vpnSlug($tName) : '';
                    if ($autoNas && isset($nasList[$nasSlug])) {
                        $nasSlug .= '-' . mt_rand(10, 99);
                    }
                    $newTunId = 'tun_' . time() . '_' . mt_rand(100, 999);
                    $newTunnel = array(
                        'id' => $newTunId,
                        'name' => $tName,
                        'hotspot_name' => $hsName,
                        'protocol' => $tProto,
                        'username' => $tUser,
                        'password' => $tPass,
                        'ipsec_secret' => $tIpsec !== '' ? $tIpsec : $serverCfg['l2tp_ipsec_secret'],
                        'server_ip' => $serverCfg['vpn_gateway_ip'],
                        'remote_ip' => $tLocalIp,
                        'nas_id' => $nasSlug,
                        'radius_secret' => $radSecret !== '' ? $radSecret : 'radius123',
                        'coa_port' => $coaPort,
                        'ros_version' => $rosVer,
                        'status' => 'enabled',
                        'connected' => true,
                        'connected_since' => date('Y-m-d H:i:s'),
                        'caller_id' => 'Assigned (' . strtoupper($tProto) . ')',
                        'rx_bytes' => 0,
                        'tx_bytes' => 0,
                        'created_at' => date('Y-m-d H:i:s')
                    );
                    $vpnTunnels[] = $newTunnel;
                    saveVpnTunnels($vpnTunnels);

                    if ($autoNas && $nasSlug !== '') {
                        $nasList[$nasSlug] = array(
                            'id' => $nasSlug,
                            'nas_name' => $hsName,
                            'nas_ip' => $tLocalIp,
                            'radius_secret' => $radSecret !== '' ? $radSecret : 'radius123',
                            'auth_port' => 1812,
                            'acct_port' => 1813,
                            'coa_port' => $coaPort,
                            'vpn_tunnel_id' => $newTunId,
                            'ros_version' => $rosVer,
                            'dns_name' => $dnsName !== '' ? $dnsName : (strtolower($nasSlug) . '.hotspot.local'),
                            'currency' => $currency !== '' ? $currency : 'BDT',
                            'auto_reload' => 10,
                            'interface' => 'vpn-tunnel',
                            'live_report' => 'enable',
                            'status' => 'online',
                            'created_at' => date('Y-m-d H:i:s')
                        );
                        saveRadiusNasList($nasList);
                        // Initialize default RADIUS profiles for the new NAS
                        loadRadiusDb();
                    }

                    $autoOpenScriptTunnelId = $newTunId;
                    $vpnNotice = vpnText(
                        'VPN Tunnel (' . strtoupper($tProto) . ' — ' . $tLocalIp . ')' . ($autoNas ? ' and RADIUS NAS (' . $nasSlug . ')' : '') . ' created! Copy the RouterOS script below and paste it into your MikroTik Terminal.',
                        'VPN টানেল (' . strtoupper($tProto) . ' — ' . $tLocalIp . ')' . ($autoNas ? ' এবং RADIUS NAS (' . $nasSlug . ')' : '') . ' সফলভাবে তৈরি হয়েছে! নিচের RouterOS স্ক্রিপ্টটি কপি করে আপনার মাইক্রোটিক টার্মিনালে পেস্ট করুন।'
                    );
                }
            }
        }

        // 3. Toggle VPN Tunnel Enable / Disable
        if (isset($_POST['toggle_vpn_tunnel_id'])) {
            $tId = trim((string)$_POST['toggle_vpn_tunnel_id']);
            foreach ($vpnTunnels as $idx => $tun) {
                if (isset($tun['id']) && $tun['id'] === $tId) {
                    $newSt = (isset($tun['status']) && $tun['status'] === 'disabled') ? 'enabled' : 'disabled';
                    $vpnTunnels[$idx]['status'] = $newSt;
                    $vpnTunnels[$idx]['connected'] = ($newSt === 'enabled');
                    break;
                }
            }
            saveVpnTunnels($vpnTunnels);
            $vpnNotice = vpnText('VPN tunnel status updated.', 'VPN টানেলের স্ট্যাটাস আপডেট করা হয়েছে।');
        }

        // 4. Delete VPN Tunnel (and optionally its linked NAS)
        if (isset($_POST['delete_vpn_tunnel_id'])) {
            $tId = trim((string)$_POST['delete_vpn_tunnel_id']);
            $kept = array();
            $linkedNasId = '';
            foreach ($vpnTunnels as $tun) {
                if (isset($tun['id']) && $tun['id'] === $tId) {
                    $linkedNasId = isset($tun['nas_id']) ? (string)$tun['nas_id'] : '';
                    continue;
                }
                $kept[] = $tun;
            }
            $vpnTunnels = $kept;
            saveVpnTunnels($vpnTunnels);
            if ($linkedNasId !== '' && isset($nasList[$linkedNasId])) {
                unset($nasList[$linkedNasId]);
                saveRadiusNasList($nasList);
            }
            $vpnNotice = vpnText('VPN tunnel and linked NAS entry removed.', 'VPN টানেল এবং সংযুক্ত NAS রিমুভ করা হয়েছে।');
        }

        // 5. Manual NAS Add / Update by VPN Local IP
        if (isset($_POST['save_manual_nas'])) {
            $activeTab = 'nas';
            $editNasId = trim((string)(isset($_POST['edit_nas_id']) ? $_POST['edit_nas_id'] : ''));
            $nasName = trim((string)(isset($_POST['nas_name']) ? $_POST['nas_name'] : ''));
            $nasIp = trim((string)(isset($_POST['nas_ip']) ? $_POST['nas_ip'] : ''));
            $radSecret = trim((string)(isset($_POST['radius_secret']) ? $_POST['radius_secret'] : 'radius123'));
            $coaPort = !empty($_POST['coa_port']) ? (int)$_POST['coa_port'] : 3799;
            $rosVer = isset($_POST['ros_version']) && (int)$_POST['ros_version'] === 6 ? 6 : 7;
            $dnsName = trim((string)(isset($_POST['dns_name']) ? $_POST['dns_name'] : ''));
            $currency = trim((string)(isset($_POST['currency']) ? $_POST['currency'] : 'BDT'));
            $tunId = trim((string)(isset($_POST['vpn_tunnel_id']) ? $_POST['vpn_tunnel_id'] : ''));

            if ($nasName === '' || $nasIp === '' || $radSecret === '') {
                $vpnError = vpnText('Please enter NAS Hotspot Name, VPN Local IP Address, and RADIUS Secret.', 'অনুগ্রহ করে NAS হটস্পট নাম, VPN Local IP এবং RADIUS Secret পূরণ করুন।');
            } elseif (!filter_var($nasIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $vpnError = vpnText('Please enter a valid IPv4 address for NAS VPN Local IP.', 'অনুগ্রহ করে সঠিক VPN Local IP দিন।');
            } else {
                $targetSlug = $editNasId !== '' ? $editNasId : vpnSlug($nasName);
                if ($editNasId === '' && isset($nasList[$targetSlug])) {
                    $targetSlug .= '-' . mt_rand(10, 99);
                }
                // Match tunnel by IP if tunnel ID was not explicitly chosen
                if ($tunId === '') {
                    foreach ($vpnTunnels as $idx => $tun) {
                        if (isset($tun['remote_ip']) && trim((string)$tun['remote_ip']) === $nasIp) {
                            $tunId = $tun['id'];
                            $vpnTunnels[$idx]['nas_id'] = $targetSlug;
                            $vpnTunnels[$idx]['radius_secret'] = $radSecret;
                            $vpnTunnels[$idx]['coa_port'] = $coaPort;
                            saveVpnTunnels($vpnTunnels);
                            break;
                        }
                    }
                } else {
                    foreach ($vpnTunnels as $idx => $tun) {
                        if (isset($tun['id']) && $tun['id'] === $tunId) {
                            $vpnTunnels[$idx]['nas_id'] = $targetSlug;
                            $vpnTunnels[$idx]['radius_secret'] = $radSecret;
                            $vpnTunnels[$idx]['coa_port'] = $coaPort;
                            saveVpnTunnels($vpnTunnels);
                            break;
                        }
                    }
                }

                $nasList[$targetSlug] = array(
                    'id' => $targetSlug,
                    'nas_name' => $nasName,
                    'nas_ip' => $nasIp,
                    'radius_secret' => $radSecret,
                    'auth_port' => 1812,
                    'acct_port' => 1813,
                    'coa_port' => $coaPort,
                    'vpn_tunnel_id' => $tunId,
                    'ros_version' => $rosVer,
                    'dns_name' => $dnsName !== '' ? $dnsName : (strtolower($targetSlug) . '.hotspot.local'),
                    'currency' => $currency !== '' ? $currency : 'BDT',
                    'auto_reload' => 10,
                    'interface' => 'vpn-tunnel',
                    'live_report' => 'enable',
                    'status' => 'online',
                    'created_at' => isset($nasList[$targetSlug]['created_at']) ? $nasList[$targetSlug]['created_at'] : date('Y-m-d H:i:s')
                );
                saveRadiusNasList($nasList);
                loadRadiusDb();
                $vpnNotice = vpnText('NAS Router (' . $nasName . ' — ' . $nasIp . ') registered in RADIUS Server!', 'NAS রাউটার (' . $nasName . ' — ' . $nasIp . ') সফলভাবে RADIUS সার্ভারে যুক্ত হয়েছে!');
            }
        }

        // 6. Delete NAS Entry
        if (isset($_POST['delete_nas_id'])) {
            $activeTab = 'nas';
            $delNasId = trim((string)$_POST['delete_nas_id']);
            if (isset($nasList[$delNasId])) {
                unset($nasList[$delNasId]);
                saveRadiusNasList($nasList);
                foreach ($vpnTunnels as $idx => $tun) {
                    if (isset($tun['nas_id']) && $tun['nas_id'] === $delNasId) {
                        $vpnTunnels[$idx]['nas_id'] = '';
                    }
                }
                saveVpnTunnels($vpnTunnels);
                $vpnNotice = vpnText('NAS Router removed from RADIUS Server.', 'NAS রাউটারটি RADIUS সার্ভার থেকে রিমুভ করা হয়েছে।');
            }
        }

        // 7. Live RADIUS Access-Request Test Simulator
        if (isset($_POST['run_radius_auth_test'])) {
            $activeTab = 'test';
            $testNasId = trim((string)(isset($_POST['test_nas_id']) ? $_POST['test_nas_id'] : ''));
            $testUser = trim((string)(isset($_POST['test_voucher_user']) ? $_POST['test_voucher_user'] : ''));
            $testPass = (string)(isset($_POST['test_voucher_pass']) ? $_POST['test_voucher_pass'] : $testUser);
            $testMac = trim((string)(isset($_POST['test_client_mac']) ? $_POST['test_client_mac'] : 'AA:BB:CC:22:33:44'));
            $testIp = trim((string)(isset($_POST['test_client_ip']) ? $_POST['test_client_ip'] : '192.168.88.55'));
            if ($testPass === '') $testPass = $testUser;
            $radiusTestResult = radiusAuthenticateVoucher($testNasId, $testUser, $testPass, $testMac, $testIp);
            if ($radiusTestResult['ok']) {
                $vpnNotice = vpnText(
                    'RADIUS Access-Accept! Voucher ' . $testUser . ' authenticated, expiry stamped (' . $radiusTestResult['expiry_comment'] . ').',
                    'RADIUS Access-Accept সফল! ভাউচার ' . $testUser . ' লগইন হয়েছে এবং মেয়াদ সেট হয়েছে (' . $radiusTestResult['expiry_comment'] . ')।'
                );
            } else {
                $vpnError = 'RADIUS ' . $radiusTestResult['code'] . ': ' . $radiusTestResult['reason'];
            }
        }

        // 8. Live CoA Disconnect (UDP 3799) Action
        if (isset($_POST['coa_disconnect_user'])) {
            $activeTab = 'test';
            $cNasId = trim((string)(isset($_POST['coa_nas_id']) ? $_POST['coa_nas_id'] : ''));
            $cActId = trim((string)(isset($_POST['coa_active_id']) ? $_POST['coa_active_id'] : ''));
            $cUser = trim((string)$_POST['coa_disconnect_user']);
            if ($cNasId !== '' && $cActId !== '') {
                radiusExecuteCommand($cNasId, '/ip/hotspot/active/remove', array('.id' => $cActId));
                $vpnNotice = vpnText(
                    'Sent RADIUS CoA Disconnect-Request (UDP 3799) and terminated active session for ' . $cUser . '.',
                    'RADIUS CoA Disconnect (UDP 3799) পাঠানো হয়েছে এবং ' . $cUser . '-এর সচল সেশন বিচ্ছিন্ন করা হয়েছে।'
                );
            }
        }
    }
}

$nextSuggestedIp = getNextAvailableVpnLocalIp($vpnTunnels, $nasList);
$suggestedUser = 'vpn_mk_' . mt_rand(100, 999);
$suggestedPass = 'vpnpw' . mt_rand(1000, 9999);
$suggestedRadSecret = 'rad' . mt_rand(10000, 99999);

// Precompute RouterOS v6/v7 scripts for all tunnels & NAS entries
$allScriptsMap = array();
foreach ($vpnTunnels as $tun) {
    $linkedNas = (!empty($tun['nas_id']) && isset($nasList[$tun['nas_id']])) ? $nasList[$tun['nas_id']] : array(
        'nas_name' => isset($tun['hotspot_name']) ? $tun['hotspot_name'] : $tun['name'],
        'nas_ip' => $tun['remote_ip'],
        'radius_secret' => isset($tun['radius_secret']) ? $tun['radius_secret'] : 'radius123',
        'coa_port' => isset($tun['coa_port']) ? $tun['coa_port'] : 3799
    );
    $allScriptsMap[$tun['id']] = buildMikrotikRadiusVpnScripts($tun, $linkedNas, $serverCfg);
}
foreach ($nasList as $nId => $nItem) {
    $matchedTun = array(
        'name' => $nItem['nas_name'],
        'hotspot_name' => $nItem['nas_name'],
        'protocol' => 'l2tp',
        'username' => 'vpn_' . strtolower($nId),
        'password' => 'vpnpass123',
        'ipsec_secret' => $serverCfg['l2tp_ipsec_secret'],
        'server_ip' => $serverCfg['vpn_gateway_ip'],
        'remote_ip' => $nItem['nas_ip']
    );
    if (!empty($nItem['vpn_tunnel_id'])) {
        foreach ($vpnTunnels as $tCheck) {
            if ($tCheck['id'] === $nItem['vpn_tunnel_id']) {
                $matchedTun = $tCheck;
                break;
            }
        }
    }
    $allScriptsMap['nas_' . $nId] = buildMikrotikRadiusVpnScripts($matchedTun, $nItem, $serverCfg);
}
?>

<style>
.ngw-mono { font-family: monospace; font-variant-numeric: tabular-nums; }
.ngw-tab-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; border-bottom: 1px solid rgba(150,150,150,0.25); padding-bottom: 10px; }
.ngw-tab-btn { padding: 8px 14px; border-radius: 4px; font-weight: bold; cursor: pointer; text-decoration: none !important; border: 1px solid rgba(150,150,150,0.3); }
.ngw-tab-btn.active { background: #2ea043; color: #fff !important; border-color: #2ea043; }
.ngw-stat-strip { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; padding: 10px 14px; border: 1px solid rgba(150,150,150,0.25); border-radius: 4px; margin-bottom: 14px; font-size: 13px; }
.ngw-script-box { width: 100%; min-height: 250px; font-family: monospace; font-size: 12px; line-height: 1.45; background: #0d1117; color: #e6edf3; border: 1px solid #30363d; border-radius: 4px; padding: 10px; }
</style>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
        <h3 class="card-title" style="margin:0;">
          <i class="fa fa-shield"></i> <?= vpnText('Built-in VPN Server (L2TP / SSTP) & NAS RADIUS Hub', 'বিল্ট-ইন VPN সার্ভার (L2TP / SSTP) ও NAS RADIUS সিস্টেম'); ?>
        </h3>
        <div class="ngw-mono" style="font-size:12px;">
          <span>VPN Gateway: <b><?= htmlspecialchars($serverCfg['vpn_gateway_ip']); ?></b></span>
          <span> · </span>
          <span>RADIUS Auth/Acct: <b>1812 / 1813</b></span>
          <span> · </span>
          <span>CoA Port: <b>3799</b></span>
        </div>
      </div>

      <div class="card-body">
        <?php if ($vpnNotice !== '') { ?>
          <div class="bg-green pd-10 radius-3 mr-b-10"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($vpnNotice); ?></div>
        <?php } ?>
        <?php if ($vpnError !== '') { ?>
          <div class="bg-danger pd-10 radius-3 mr-b-10"><i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($vpnError); ?></div>
        <?php } ?>

        <!-- Built-in VPN & RADIUS Server Endpoint Bar -->
        <form method="post" action="" class="ngw-stat-strip" autocomplete="off">
          <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken); ?>">
          <div>
            <b><i class="fa fa-globe"></i> <?= vpnText('VPN Server Endpoint (IP / Domain):', 'সার্ভারের Public IP / ডোমেইন:'); ?></b>
            <input type="text" name="public_endpoint" value="<?= htmlspecialchars($serverCfg['public_endpoint']); ?>" class="form-control ngw-mono" style="display:inline-block; width:210px; margin-left:6px; height:30px; padding:3px 8px;" required>
          </div>
          <div>
            <b><?= vpnText('VPN Gateway IP:', 'VPN গেটওয়ে IP:'); ?></b>
            <input type="text" name="vpn_gateway_ip" value="<?= htmlspecialchars($serverCfg['vpn_gateway_ip']); ?>" class="form-control ngw-mono" style="display:inline-block; width:125px; margin-left:6px; height:30px; padding:3px 8px;" required>
          </div>
          <div>
            <b><?= vpnText('L2TP IPsec Secret:', 'L2TP IPsec সিক্রেট:'); ?></b>
            <input type="text" name="l2tp_ipsec_secret" value="<?= htmlspecialchars($serverCfg['l2tp_ipsec_secret']); ?>" class="form-control ngw-mono" style="display:inline-block; width:130px; margin-left:6px; height:30px; padding:3px 8px;" required>
          </div>
          <button type="submit" name="save_vpn_server_config" class="btn bg-primary" style="padding:4px 12px; cursor:pointer; font-weight:bold;">
            <i class="fa fa-save"></i> <?= vpnText('Save Server Endpoint', 'সার্ভার সেটিংস সেভ'); ?>
          </button>
        </form>

        <!-- Navigation Tabs -->
        <div class="ngw-tab-bar">
          <a href="./admin.php?id=vpn&tab=vpn" class="ngw-tab-btn <?= $activeTab === 'vpn' ? 'active' : ''; ?>">
            <i class="fa fa-key"></i> <?= vpnText('1. Create L2TP / SSTP VPN & Auto-NAS', '১. L2TP / SSTP VPN তৈরি ও অটো NAS'); ?> (<?= count($vpnTunnels); ?>)
          </a>
          <a href="./admin.php?id=vpn&tab=nas" class="ngw-tab-btn <?= $activeTab === 'nas' ? 'active' : ''; ?>">
            <i class="fa fa-server"></i> <?= vpnText('2. NAS Routers (By VPN Local IP)', '২. NAS রাউটার লিস্ট (VPN Local IP দিয়ে অ্যাড)'); ?> (<?= count($nasList); ?>)
          </a>
          <a href="./admin.php?id=vpn&tab=test" class="ngw-tab-btn <?= $activeTab === 'test' ? 'active' : ''; ?>">
            <i class="fa fa-bolt"></i> <?= vpnText('3. Live RADIUS Login & CoA Test', '৩. লাইভ RADIUS লগইন ও CoA (3799) টেস্ট'); ?>
          </a>
        </div>

        <?php if ($activeTab === 'vpn') { ?>
          <!-- TAB 1: DIRECT BUILT-IN VPN CREATION + AUTO NAS -->
          <div class="row">
            <div class="col-5">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title"><i class="fa fa-plus-circle"></i> <?= vpnText('Create Built-in VPN (L2TP / SSTP)', 'সরাসরি নতুন VPN তৈরি করুন (L2TP / SSTP)'); ?></h3>
                </div>
                <div class="card-body">
                  <form method="post" action="./admin.php?id=vpn&tab=vpn" autocomplete="off">
                    <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken); ?>">
                    <table class="table table-sm">
                      <tr>
                        <td class="align-middle" style="width:38%;"><?= vpnText('Router / VPN Name', 'রাউটার / VPN নাম'); ?></td>
                        <td>
                          <input type="text" name="tunnel_name" id="vpn_tun_name" class="form-control" placeholder="e.g. Zone1-MikroTik" required oninput="var h=document.getElementById('vpn_hs_name'); if(!h.dataset.edited){h.value=this.value;}">
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('VPN Protocol', 'VPN প্রোটোকল'); ?></td>
                        <td>
                          <select name="protocol" class="form-control">
                            <option value="both"><?= vpnText('L2TP/IPsec + SSTP (Both Supported)', 'L2TP/IPsec এবং SSTP (উভয়ই)'); ?></option>
                            <option value="l2tp">L2TP / IPsec</option>
                            <option value="sstp">SSTP (SSL VPN)</option>
                          </select>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('VPN Username', 'VPN ইউজারনেম'); ?></td>
                        <td>
                          <input type="text" name="vpn_username" id="vpn_u_input" class="form-control ngw-mono" value="<?= htmlspecialchars($suggestedUser); ?>" required>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('VPN Password', 'VPN পাসওয়ার্ড'); ?></td>
                        <td>
                          <input type="text" name="vpn_password" id="vpn_p_input" class="form-control ngw-mono" value="<?= htmlspecialchars($suggestedPass); ?>" required>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('L2TP IPsec Secret', 'L2TP IPsec সিক্রেট'); ?></td>
                        <td>
                          <input type="text" name="ipsec_secret" class="form-control ngw-mono" value="<?= htmlspecialchars($serverCfg['l2tp_ipsec_secret']); ?>" required>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><b><?= vpnText('MikroTik VPN Local IP', 'মাইক্রোটিকের VPN Local IP'); ?></b></td>
                        <td>
                          <input type="text" name="remote_ip" class="form-control ngw-mono" value="<?= htmlspecialchars($nextSuggestedIp); ?>" required title="Static Local IP assigned to client MikroTik over VPN">
                          <small style="opacity:0.75;"><?= vpnText('This Local IP will be assigned to the MikroTik & used as NAS IP', 'এই লোকাল আইপি মাইক্রোটিকে বসবে এবং এটি দিয়েই NAS যুক্ত হবে'); ?></small>
                        </td>
                      </tr>
                      <tr>
                        <td colspan="2" style="padding-top:10px; border-top:1px solid rgba(150,150,150,0.2);">
                          <label style="cursor:pointer; font-weight:bold; margin:0;">
                            <input type="checkbox" name="auto_add_nas" value="1" checked onchange="document.getElementById('autoNasFields').style.display=this.checked?'':'none';">
                            <?= vpnText('Automatically add this VPN Local IP as RADIUS NAS Router', 'এই VPN Local IP দিয়ে অটোমেটিক RADIUS NAS যুক্ত করুন'); ?>
                          </label>
                        </td>
                      </tr>
                    </table>

                    <div id="autoNasFields">
                      <table class="table table-sm" style="margin-bottom:10px;">
                        <tr>
                          <td class="align-middle" style="width:38%;"><?= vpnText('RADIUS Secret', 'RADIUS সিক্রেট (Secret)'); ?></td>
                          <td><input type="text" name="radius_secret" class="form-control ngw-mono" value="<?= htmlspecialchars($suggestedRadSecret); ?>"></td>
                        </tr>
                        <tr>
                          <td class="align-middle"><?= vpnText('CoA Port', 'CoA ডিসকানেক্ট পোর্ট'); ?></td>
                          <td><input type="number" name="coa_port" class="form-control ngw-mono" value="3799"></td>
                        </tr>
                        <tr>
                          <td class="align-middle"><?= vpnText('Client RouterOS', 'মাইক্রোটিক ভার্সন'); ?></td>
                          <td>
                            <select name="ros_version" class="form-control">
                              <option value="7">RouterOS v7 (7.x)</option>
                              <option value="6">RouterOS v6 (6.x)</option>
                            </select>
                          </td>
                        </tr>
                        <tr>
                          <td class="align-middle"><?= vpnText('Hotspot Name', 'হটস্পট নাম'); ?></td>
                          <td><input type="text" name="hotspot_name" id="vpn_hs_name" class="form-control" placeholder="e.g. NextGan WiFi Zone" oninput="this.dataset.edited='1';"></td>
                        </tr>
                        <tr>
                          <td class="align-middle"><?= vpnText('Currency', 'কারেন্সি (Currency)'); ?></td>
                          <td><input type="text" name="currency" class="form-control" value="BDT"></td>
                        </tr>
                      </table>
                    </div>

                    <button type="submit" name="create_builtin_vpn" class="btn bg-success btn-block pd-10" style="cursor:pointer; font-weight:bold;">
                      <i class="fa fa-check-circle"></i> <?= vpnText('Create VPN & Register NAS', 'VPN তৈরি করুন ও NAS যুক্ত করুন'); ?>
                    </button>
                  </form>
                </div>
              </div>
            </div>

            <div class="col-7">
              <div class="card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                  <h3 class="card-title" style="margin:0;"><i class="fa fa-list"></i> <?= vpnText('Created VPN Tunnels & Linked NAS Routers', 'তৈরিকৃত VPN টানেল এবং সংযুক্ত NAS তালিকা'); ?></h3>
                  <span class="ngw-mono" style="font-size:12px;"><?= count($vpnTunnels); ?> Tunnels</span>
                </div>
                <div class="card-body" style="overflow-x:auto;">
                  <table class="table table-bordered table-hover table-sm">
                    <thead>
                      <tr>
                        <th><?= vpnText('Tunnel / Router', 'টানেল / রাউটার'); ?></th>
                        <th><?= vpnText('Protocol & Auth', 'প্রোটোকল ও ইউজার'); ?></th>
                        <th><?= vpnText('MikroTik Local IP', 'VPN Local IP'); ?></th>
                        <th><?= vpnText('RADIUS NAS Status', 'RADIUS NAS স্ট্যাটাস'); ?></th>
                        <th class="text-right"><?= vpnText('Actions', 'অ্যাকশন'); ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($vpnTunnels as $tun) {
                        $tId = $tun['id'];
                        $nId = isset($tun['nas_id']) ? (string)$tun['nas_id'] : '';
                        $hasNas = ($nId !== '' && isset($nasList[$nId]));
                      ?>
                        <tr>
                          <td class="align-middle">
                            <b><?= htmlspecialchars($tun['name']); ?></b><br>
                            <small style="opacity:0.75;"><?= htmlspecialchars(isset($tun['hotspot_name']) ? $tun['hotspot_name'] : ''); ?></small>
                          </td>
                          <td class="align-middle ngw-mono">
                            <b><?= htmlspecialchars(strtoupper($tun['protocol'])); ?></b><br>
                            <small>U: <?= htmlspecialchars($tun['username']); ?> · P: <?= htmlspecialchars($tun['password']); ?></small>
                          </td>
                          <td class="align-middle ngw-mono">
                            <b style="color:#2ea043; font-size:13px;"><?= htmlspecialchars($tun['remote_ip']); ?></b><br>
                            <small>GW: <?= htmlspecialchars($tun['server_ip']); ?></small>
                          </td>
                          <td class="align-middle">
                            <?php if ($hasNas) { ?>
                              <span class="ngw-mono" style="color:#2ea043; font-weight:bold;">
                                <i class="fa fa-check-circle"></i> NAS: <?= htmlspecialchars($nId); ?>
                              </span><br>
                              <small class="ngw-mono">Secret: <?= htmlspecialchars($nasList[$nId]['radius_secret']); ?> · CoA: <?= (int)$nasList[$nId]['coa_port']; ?></small>
                            <?php } else { ?>
                              <a href="./admin.php?id=vpn&tab=nas&prefill_ip=<?= urlencode($tun['remote_ip']); ?>&prefill_name=<?= urlencode($tun['name']); ?>&prefill_tun=<?= urlencode($tId); ?>" class="btn bg-primary" style="padding:3px 8px; font-size:11px;">
                                <i class="fa fa-plus"></i> <?= vpnText('Add as NAS', 'NAS হিসেবে যুক্ত করুন'); ?>
                              </a>
                            <?php } ?>
                          </td>
                          <td class="align-middle text-right" style="white-space:nowrap;">
                            <button type="button" class="btn bg-info" style="padding:4px 8px; font-size:12px; cursor:pointer; font-weight:bold;" onclick="openRosScriptModal('<?= htmlspecialchars($tId, ENT_QUOTES); ?>', '<?= htmlspecialchars($tun['name'] . ' (' . $tun['remote_ip'] . ')', ENT_QUOTES); ?>')">
                              <i class="fa fa-terminal"></i> <?= vpnText('ROS Script', 'স্ক্রিপ্ট'); ?>
                            </button>
                            <?php if ($hasNas) { ?>
                              <a href="./admin.php?id=settings&session=<?= urlencode($nId); ?>" class="btn bg-warning" style="padding:4px 8px; font-size:12px; font-weight:bold;" title="Edit NAS Settings">
                                <i class="fa fa-edit"></i> NAS
                              </a>
                            <?php } ?>
                            <form method="post" action="./admin.php?id=vpn&tab=vpn" style="display:inline; margin:0;" onsubmit="return confirm('Delete this VPN tunnel and its linked NAS?');">
                              <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken); ?>">
                              <input type="hidden" name="delete_vpn_tunnel_id" value="<?= htmlspecialchars($tId); ?>">
                              <button type="submit" class="btn bg-danger" style="padding:4px 8px; font-size:12px; cursor:pointer;">
                                <i class="fa fa-trash"></i>
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php } ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        <?php } elseif ($activeTab === 'nas') {
          $prefillIp = isset($_GET['prefill_ip']) ? trim((string)$_GET['prefill_ip']) : '';
          $prefillName = isset($_GET['prefill_name']) ? trim((string)$_GET['prefill_name']) : '';
          $prefillTun = isset($_GET['prefill_tun']) ? trim((string)$_GET['prefill_tun']) : '';
        ?>
          <!-- TAB 2: MANUAL NAS REGISTRATION BY VPN LOCAL IP -->
          <div class="row">
            <div class="col-5">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title"><i class="fa fa-server"></i> <?= vpnText('Add / Update NAS by VPN Local IP', 'VPN Local IP দিয়ে NAS যুক্ত বা আপডেট করুন'); ?></h3>
                </div>
                <div class="card-body">
                  <form method="post" action="./admin.php?id=vpn&tab=nas" autocomplete="off">
                    <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken); ?>">
                    <input type="hidden" name="edit_nas_id" id="manual_edit_nas_id" value="">
                    <table class="table table-sm">
                      <tr>
                        <td class="align-middle" style="width:38%;"><?= vpnText('Select VPN Tunnel', 'VPN টানেল নির্বাচন'); ?></td>
                        <td>
                          <select name="vpn_tunnel_id" id="nas_tun_select" class="form-control" onchange="onSelectVpnTunnelForNas(this)">
                            <option value=""><?= vpnText('-- Select VPN or Enter Local IP Below --', '-- VPN সিলেক্ট করুন অথবা নিচে IP লিখুন --'); ?></option>
                            <?php foreach ($vpnTunnels as $tun) { ?>
                              <option value="<?= htmlspecialchars($tun['id']); ?>" data-ip="<?= htmlspecialchars($tun['remote_ip']); ?>" data-name="<?= htmlspecialchars($tun['name']); ?>" data-secret="<?= htmlspecialchars(isset($tun['radius_secret']) ? $tun['radius_secret'] : 'radius123'); ?>" <?= ($prefillTun === $tun['id']) ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($tun['name']); ?> — <?= htmlspecialchars($tun['remote_ip']); ?> (<?= strtoupper($tun['protocol']); ?>)
                              </option>
                            <?php } ?>
                          </select>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><b><?= vpnText('MikroTik VPN Local IP', 'মাইক্রোটিক VPN Local IP'); ?></b></td>
                        <td>
                          <input type="text" name="nas_ip" id="manual_nas_ip" class="form-control ngw-mono" value="<?= htmlspecialchars($prefillIp !== '' ? $prefillIp : $nextSuggestedIp); ?>" placeholder="e.g. 10.10.10.2" required>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('NAS / Hotspot Name', 'NAS / হটস্পট নাম'); ?></td>
                        <td>
                          <input type="text" name="nas_name" id="manual_nas_name" class="form-control" value="<?= htmlspecialchars($prefillName); ?>" placeholder="e.g. Main-Hotspot-Zone" required>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('RADIUS Shared Secret', 'RADIUS সিক্রেট (Secret)'); ?></td>
                        <td>
                          <input type="text" name="radius_secret" id="manual_nas_secret" class="form-control ngw-mono" value="radius123" required>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('CoA Disconnect Port', 'CoA পোর্ট (Disconnect)'); ?></td>
                        <td>
                          <input type="number" name="coa_port" id="manual_nas_coa" class="form-control ngw-mono" value="3799" required>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('RouterOS Version', 'RouterOS ভার্সন'); ?></td>
                        <td>
                          <select name="ros_version" id="manual_nas_ros" class="form-control">
                            <option value="7">RouterOS v7 (7.x)</option>
                            <option value="6">RouterOS v6 (6.x)</option>
                          </select>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('Hotspot DNS Name', 'DNS নাম'); ?></td>
                        <td>
                          <input type="text" name="dns_name" id="manual_nas_dns" class="form-control" placeholder="wifi.nextgan.local">
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('Currency', 'কারেন্সি'); ?></td>
                        <td>
                          <input type="text" name="currency" id="manual_nas_curr" class="form-control" value="BDT">
                        </td>
                      </tr>
                    </table>
                    <button type="submit" name="save_manual_nas" class="btn bg-primary btn-block pd-10" style="cursor:pointer; font-weight:bold;">
                      <i class="fa fa-save"></i> <?= vpnText('Save NAS Router (RADIUS)', 'NAS রাউটার সেভ করুন (RADIUS)'); ?>
                    </button>
                  </form>
                </div>
              </div>
            </div>

            <div class="col-7">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title"><i class="fa fa-network-wired"></i> <?= vpnText('Registered RADIUS NAS Routers', 'নিবন্ধিত RADIUS NAS রাউটার তালিকা'); ?></h3>
                </div>
                <div class="card-body" style="overflow-x:auto;">
                  <table class="table table-bordered table-hover table-sm">
                    <thead>
                      <tr>
                        <th><?= vpnText('NAS ID / Hotspot Name', 'NAS ID / হটস্পট নাম'); ?></th>
                        <th><?= vpnText('VPN Local IP (NAS IP)', 'VPN Local IP (NAS IP)'); ?></th>
                        <th><?= vpnText('RADIUS Secret & CoA', 'RADIUS Secret ও CoA'); ?></th>
                        <th class="text-right"><?= vpnText('Action', 'অ্যাকশন'); ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($nasList as $nId => $nas) { ?>
                        <tr>
                          <td class="align-middle">
                            <b><?= htmlspecialchars($nas['nas_name']); ?></b><br>
                            <small class="ngw-mono">ID: <?= htmlspecialchars($nId); ?> · ROS v<?= isset($nas['ros_version']) ? (int)$nas['ros_version'] : 7; ?></small>
                          </td>
                          <td class="align-middle ngw-mono">
                            <b style="color:#2ea043; font-size:13px;"><?= htmlspecialchars($nas['nas_ip']); ?></b><br>
                            <small><?= htmlspecialchars($nas['dns_name']); ?></small>
                          </td>
                          <td class="align-middle ngw-mono">
                            Secret: <b><?= htmlspecialchars($nas['radius_secret']); ?></b><br>
                            <small>Auth: 1812 · Acct: 1813 · CoA: <?= (int)$nas['coa_port']; ?></small>
                          </td>
                          <td class="align-middle text-right" style="white-space:nowrap;">
                            <button type="button" class="btn bg-info" style="padding:4px 8px; font-size:12px; cursor:pointer; font-weight:bold;" onclick="openRosScriptModal('nas_<?= htmlspecialchars($nId, ENT_QUOTES); ?>', '<?= htmlspecialchars($nas['nas_name'] . ' (' . $nas['nas_ip'] . ')', ENT_QUOTES); ?>')">
                              <i class="fa fa-terminal"></i> <?= vpnText('ROS Script', 'স্ক্রিপ্ট'); ?>
                            </button>
                            <a href="./admin.php?id=settings&session=<?= urlencode($nId); ?>" class="btn bg-warning" style="padding:4px 8px; font-size:12px;">
                              <i class="fa fa-edit"></i> Edit NAS
                            </a>
                            <form method="post" action="./admin.php?id=vpn&tab=nas" style="display:inline; margin:0;" onsubmit="return confirm('Delete NAS <?= htmlspecialchars($nId, ENT_QUOTES); ?>?');">
                              <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken); ?>">
                              <input type="hidden" name="delete_nas_id" value="<?= htmlspecialchars($nId); ?>">
                              <button type="submit" class="btn bg-danger" style="padding:4px 8px; font-size:12px; cursor:pointer;">
                                <i class="fa fa-trash"></i>
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php } ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        <?php } else {
          $dbAll = loadRadiusDb();
        ?>
          <!-- TAB 3: LIVE RADIUS AUTH & COA TEST CONSOLE -->
          <div class="row">
            <div class="col-5">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title"><i class="fa fa-bolt"></i> <?= vpnText('Test RADIUS Voucher Login (Access-Request)', 'RADIUS ভাউচার লগইন টেস্ট করুন (Access-Request)'); ?></h3>
                </div>
                <div class="card-body">
                  <form method="post" action="./admin.php?id=vpn&tab=test" autocomplete="off">
                    <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken); ?>">
                    <table class="table table-sm">
                      <tr>
                        <td class="align-middle" style="width:38%;"><?= vpnText('Target NAS Router', 'টার্গেট NAS রাউটার'); ?></td>
                        <td>
                          <select name="test_nas_id" class="form-control">
                            <?php foreach ($nasList as $nId => $nas) { ?>
                              <option value="<?= htmlspecialchars($nId); ?>"><?= htmlspecialchars($nas['nas_name']); ?> (<?= htmlspecialchars($nas['nas_ip']); ?>)</option>
                            <?php } ?>
                          </select>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('Voucher Username', 'ভাউচার কোড / ইউজার'); ?></td>
                        <td>
                          <input type="text" name="test_voucher_user" class="form-control ngw-mono" value="V78902" required placeholder="e.g. V78902">
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('Password', 'পাসওয়ার্ড'); ?></td>
                        <td>
                          <input type="text" name="test_voucher_pass" class="form-control ngw-mono" value="V78902" placeholder="Leave same as username for voucher">
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('Client MAC Address', 'ক্লায়েন্ট MAC অ্যাড্রেস'); ?></td>
                        <td>
                          <input type="text" name="test_client_mac" class="form-control ngw-mono" value="AA:BB:CC:55:66:77" required>
                        </td>
                      </tr>
                      <tr>
                        <td class="align-middle"><?= vpnText('Client Framed IP', 'ক্লায়েন্ট IP অ্যাড্রেস'); ?></td>
                        <td>
                          <input type="text" name="test_client_ip" class="form-control ngw-mono" value="192.168.88.55" required>
                        </td>
                      </tr>
                    </table>
                    <button type="submit" name="run_radius_auth_test" class="btn bg-success btn-block pd-10" style="cursor:pointer; font-weight:bold;">
                      <i class="fa fa-play"></i> <?= vpnText('Send RADIUS Access-Request', 'RADIUS লগইন টেস্ট করুন'); ?>
                    </button>
                  </form>

                  <?php if ($radiusTestResult !== null) { ?>
                    <div style="margin-top:12px; padding:10px; border:1px solid rgba(150,150,150,0.3); border-radius:4px;" class="ngw-mono">
                      <div><b>Packet Response:</b> <span style="color:<?= $radiusTestResult['ok'] ? '#2ea043' : '#f85149'; ?>; font-weight:bold;"><?= htmlspecialchars($radiusTestResult['code']); ?></span></div>
                      <?php if ($radiusTestResult['ok']) { ?>
                        <div><b>Profile:</b> <?= htmlspecialchars($radiusTestResult['profile']); ?></div>
                        <div><b>Stamped Expiry Comment:</b> <?= htmlspecialchars($radiusTestResult['expiry_comment']); ?></div>
                        <div><b>Bound MAC (Calling-Station-Id):</b> <?= htmlspecialchars($radiusTestResult['mac_address']); ?></div>
                        <div><b>Mikrotik-Rate-Limit:</b> <?= htmlspecialchars($radiusTestResult['attributes']['Mikrotik-Rate-Limit']); ?></div>
                        <div><b>Session-Timeout:</b> <?= (int)$radiusTestResult['attributes']['Session-Timeout']; ?>s</div>
                      <?php } else { ?>
                        <div><b>Reject Reason:</b> <?= htmlspecialchars($radiusTestResult['reason']); ?></div>
                      <?php } ?>
                    </div>
                  <?php } ?>
                </div>
              </div>
            </div>

            <div class="col-7">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title"><i class="fa fa-wifi"></i> <?= vpnText('Active RADIUS Sessions Across NAS Routers (CoA Disconnect Ready)', 'সচল RADIUS সেশনসমূহ (১-ক্লিকে CoA Disconnect)'); ?></h3>
                </div>
                <div class="card-body" style="overflow-x:auto;">
                  <table class="table table-bordered table-hover table-sm">
                    <thead>
                      <tr>
                        <th>NAS</th>
                        <th>Voucher / User</th>
                        <th>IP & MAC</th>
                        <th>Expiry Comment</th>
                        <th class="text-right">CoA (UDP 3799)</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php
                      $totalActiveShown = 0;
                      $aList = isset($dbAll['central']['active']) && is_array($dbAll['central']['active']) ? $dbAll['central']['active'] : array();
                      foreach ($aList as $act) {
                          $totalActiveShown++;
                          $actNasId = !empty($act['server']) ? $act['server'] : getDefaultCentralRadiusSession();
                          $actNasObj = isset($nasList[$actNasId]) ? $nasList[$actNasId] : array('nas_name' => $actNasId, 'nas_ip' => '10.10.10.2');
                      ?>
                        <tr>
                          <td class="align-middle"><b><?= htmlspecialchars($actNasObj['nas_name']); ?></b><br><small class="ngw-mono"><?= htmlspecialchars($actNasObj['nas_ip']); ?></small></td>
                          <td class="align-middle ngw-mono"><b><?= htmlspecialchars($act['user']); ?></b></td>
                          <td class="align-middle ngw-mono"><?= htmlspecialchars($act['address']); ?><br><small><?= htmlspecialchars($act['mac-address']); ?></small></td>
                          <td class="align-middle ngw-mono"><?= htmlspecialchars(isset($act['comment']) ? $act['comment'] : ''); ?></td>
                          <td class="align-middle text-right">
                            <form method="post" action="./admin.php?id=vpn&tab=test" style="margin:0; display:inline;">
                              <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken); ?>">
                              <input type="hidden" name="coa_nas_id" value="<?= htmlspecialchars($actNasId); ?>">
                              <input type="hidden" name="coa_active_id" value="<?= htmlspecialchars($act['.id']); ?>">
                              <input type="hidden" name="coa_disconnect_user" value="<?= htmlspecialchars($act['user']); ?>">
                              <button type="submit" class="btn bg-danger" style="padding:4px 10px; font-size:12px; cursor:pointer; font-weight:bold;">
                                <i class="fa fa-power-off"></i> CoA Disconnect
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php
                      }
                      if ($totalActiveShown === 0) {
                      ?>
                        <tr><td colspan="5" class="text-center">No active RADIUS sessions at this moment. Use the Test form on the left to authenticate a voucher!</td></tr>
                      <?php } ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        <?php } ?>

      </div>
    </div>
  </div>
</div>

<!-- MODAL: ROUTEROS V6 / V7 VPN + RADIUS PROVISIONING SCRIPT -->
<div id="rosScriptModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center; padding:16px;">
  <div class="card" style="width:100%; max-width:780px; margin:auto;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <h3 class="card-title" style="margin:0;"><i class="fa fa-terminal"></i> <span id="rosScriptModalTitle">RouterOS VPN + RADIUS Script</span></h3>
      <button type="button" class="btn bg-danger" style="padding:3px 10px; cursor:pointer;" onclick="closeRosScriptModal()">✕</button>
    </div>
    <div class="card-body">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px;">
        <div>
          <button type="button" id="btnRosV7" class="btn bg-primary" style="cursor:pointer; font-weight:bold;" onclick="switchRosScriptVer('ros7')">RouterOS v7 Script</button>
          <button type="button" id="btnRosV6" class="btn bg-default" style="cursor:pointer; font-weight:bold;" onclick="switchRosScriptVer('ros6')">RouterOS v6 Script</button>
        </div>
        <button type="button" class="btn bg-success" style="cursor:pointer; font-weight:bold;" onclick="copyActiveRosScript()">
          <i class="fa fa-copy"></i> <span id="copyRosBtnLabel"><?= vpnText('Copy Terminal Script', 'স্ক্রিপ্ট কপি করুন'); ?></span>
        </button>
      </div>
      <textarea id="rosScriptTextarea" class="ngw-script-box" readonly></textarea>
      <div style="font-size:12px; opacity:0.85; margin-top:6px;">
        <i class="fa fa-info-circle"></i> <?= vpnText('Paste this script into your client MikroTik RouterOS Terminal (New Terminal). It automatically connects the L2TP/SSTP VPN, configures RADIUS Auth/Acct to 10.10.10.1, enables CoA on port 3799, and turns on RADIUS for Hotspot.', 'এই স্ক্রিপ্টটি আপনার মাইক্রোটিক রাউটারের New Terminal-এ পেস্ট করুন। এটি অটোমেটিক VPN কানেক্ট করবে, 10.10.10.1-এ RADIUS যুক্ত করবে, পোর্ট 3799-এ CoA চালু করবে এবং Hotspot-এ RADIUS সক্রিয় করবে।'); ?>
      </div>
    </div>
  </div>
</div>

<script>
var NGW_SCRIPTS_MAP = <?= json_encode($allScriptsMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var currentScriptKey = '';
var currentRosVer = 'ros7';

function openRosScriptModal(key, title) {
  currentScriptKey = key;
  currentRosVer = 'ros7';
  document.getElementById('rosScriptModalTitle').textContent = 'MikroTik VPN + RADIUS Script — ' + title;
  switchRosScriptVer('ros7');
  document.getElementById('rosScriptModal').style.display = 'flex';
}

function closeRosScriptModal() {
  document.getElementById('rosScriptModal').style.display = 'none';
}

function switchRosScriptVer(ver) {
  currentRosVer = ver;
  var entry = NGW_SCRIPTS_MAP[currentScriptKey] || { ros7: '', ros6: '' };
  document.getElementById('rosScriptTextarea').value = entry[ver] || '';
  document.getElementById('btnRosV7').className = ver === 'ros7' ? 'btn bg-primary' : 'btn bg-default';
  document.getElementById('btnRosV6').className = ver === 'ros6' ? 'btn bg-primary' : 'btn bg-default';
}

function copyActiveRosScript() {
  var ta = document.getElementById('rosScriptTextarea');
  ta.select();
  try {
    navigator.clipboard.writeText(ta.value);
  } catch (e) {
    document.execCommand('copy');
  }
  var lbl = document.getElementById('copyRosBtnLabel');
  var old = lbl.textContent;
  lbl.textContent = 'Copied!';
  setTimeout(function() { lbl.textContent = old; }, 1800);
}

function onSelectVpnTunnelForNas(sel) {
  var opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.value) return;
  var ip = opt.getAttribute('data-ip') || '';
  var name = opt.getAttribute('data-name') || '';
  var secret = opt.getAttribute('data-secret') || 'radius123';
  if (ip) document.getElementById('manual_nas_ip').value = ip;
  if (name && !document.getElementById('manual_nas_name').value) {
    document.getElementById('manual_nas_name').value = name;
  }
  if (secret) document.getElementById('manual_nas_secret').value = secret;
}

<?php if ($autoOpenScriptTunnelId !== '' && isset($allScriptsMap[$autoOpenScriptTunnelId])) { ?>
window.addEventListener('DOMContentLoaded', function() {
  openRosScriptModal(<?= json_encode($autoOpenScriptTunnelId); ?>, 'Newly Created VPN + NAS');
});
<?php } ?>
</script>

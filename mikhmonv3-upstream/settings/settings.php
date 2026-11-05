<?php
/*
 * NextGan WiFi Solution — RADIUS NAS Router Configuration (By VPN Local IP)
 * Replaces legacy Public IP / RouterOS API configuration with VPN Local IP + RADIUS Shared Secret + CoA (3799)
 */
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  include_once('./include/radius_engine.php');
  $vpnTunnels = loadVpnTunnels();
  $nasList = loadRadiusNasList();
  $serverCfg = loadVpnServerConfig();

  if ($id == "settings" && explode("-", $router)[0] == "new") {
    $nextIp = getNextAvailableVpnLocalIp($vpnTunnels, $nasList);
    $nasList[$router] = array(
      'id' => $router,
      'nas_name' => $router,
      'nas_ip' => $nextIp,
      'radius_secret' => 'radius123',
      'auth_port' => 1812,
      'acct_port' => 1813,
      'coa_port' => 3799,
      'vpn_tunnel_id' => '',
      'ros_version' => 7,
      'dns_name' => strtolower($router) . '.hotspot.local',
      'currency' => 'BDT',
      'auto_reload' => 10,
      'interface' => '1',
      'live_report' => 'enable',
      'status' => 'online',
      'created_at' => date('Y-m-d H:i:s')
    );
    saveRadiusNasList($nasList);
    loadRadiusDb();
    echo "<script>window.location='./admin.php?id=settings&session=" . rawurlencode($router) . "'</script>";
    exit;
  }

  if (isset($_POST['save'])) {
    $raw_ipmik = preg_replace('/\s+/', '', isset($_POST['ipmik']) ? $_POST['ipmik'] : '');
    $siphost = $raw_ipmik !== '' ? $raw_ipmik : '10.10.10.2';
    $radSecretPlain = isset($_POST['passmik']) && trim($_POST['passmik']) !== '' ? trim($_POST['passmik']) : 'radius123';
    $shotspotname = str_replace("'", '', isset($_POST['hotspotname']) ? trim($_POST['hotspotname']) : '');
    $sdnsname = isset($_POST['dnsname']) ? trim($_POST['dnsname']) : '';
    $scurrency = isset($_POST['currency']) && trim($_POST['currency']) !== '' ? trim($_POST['currency']) : 'BDT';
    $sreload = isset($_POST['areload']) ? (int)$_POST['areload'] : 10;
    if ($sreload < 10) { $sreload = 10; }
    $siface = isset($_POST['iface']) ? $_POST['iface'] : '1';
    $sinfolp = isset($_POST['infolp']) ? implode(unpack('H*', $_POST['infolp'])) : '';
    $coaPort = isset($_POST['coa_port']) && (int)$_POST['coa_port'] > 0 ? (int)$_POST['coa_port'] : 3799;
    $rosVer = isset($_POST['ros_version']) && (int)$_POST['ros_version'] === 6 ? 6 : 7;
    $tunId = isset($_POST['vpn_tunnel_id']) ? trim((string)$_POST['vpn_tunnel_id']) : '';
    $sesname = preg_replace('/\s+/', '-', trim(isset($_POST['sessname']) ? $_POST['sessname'] : $session));
    $slivereport = isset($_POST['livereport']) ? $_POST['livereport'] : 'enable';

    if ($session !== $sesname && isset($nasList[$session])) {
      $oldEntry = $nasList[$session];
      unset($nasList[$session]);
    }
    $nasList[$sesname] = array(
      'id' => $sesname,
      'nas_name' => $shotspotname !== '' ? $shotspotname : $sesname,
      'nas_ip' => $siphost,
      'radius_secret' => $radSecretPlain,
      'auth_port' => 1812,
      'acct_port' => 1813,
      'coa_port' => $coaPort,
      'vpn_tunnel_id' => $tunId,
      'ros_version' => $rosVer,
      'dns_name' => $sdnsname !== '' ? $sdnsname : (strtolower($sesname) . '.hotspot.local'),
      'currency' => $scurrency,
      'auto_reload' => $sreload,
      'interface' => $siface,
      'infolp' => $sinfolp,
      'live_report' => $slivereport,
      'status' => 'online',
      'created_at' => isset($nasList[$sesname]['created_at']) ? $nasList[$sesname]['created_at'] : date('Y-m-d H:i:s')
    );

    // Link VPN tunnel if selected or matching IP
    foreach ($vpnTunnels as $idx => $tun) {
      if (($tunId !== '' && $tun['id'] === $tunId) || ($tunId === '' && trim((string)$tun['remote_ip']) === $siphost)) {
        $vpnTunnels[$idx]['nas_id'] = $sesname;
        $vpnTunnels[$idx]['radius_secret'] = $radSecretPlain;
        $vpnTunnels[$idx]['coa_port'] = $coaPort;
        $vpnTunnels[$idx]['hotspot_name'] = $shotspotname;
        saveVpnTunnels($vpnTunnels);
        break;
      }
    }

    saveRadiusNasList($nasList);
    loadRadiusDb();

    $_SESSION['connect'] = '';
    $_SESSION['router_settings_notice'] = 'RADIUS NAS settings saved successfully for ' . $sesname . ' (VPN IP: ' . $siphost . ').';
    session_write_close();
    echo '<script>window.location.replace(' . json_encode('./admin.php?id=settings&session=' . rawurlencode($sesname), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ')</script>';
    exit;
  }

  $currentNas = isset($nasList[$session]) ? $nasList[$session] : array(
    'id' => $session,
    'nas_name' => $hotspotname,
    'nas_ip' => $iphost !== '' ? $iphost : '10.10.10.2',
    'radius_secret' => $passwdhost !== '' ? decrypt($passwdhost) : 'radius123',
    'coa_port' => 3799,
    'vpn_tunnel_id' => '',
    'ros_version' => 7,
    'dns_name' => $dnsname,
    'currency' => $currency !== '' ? $currency : 'BDT'
  );

  $display_iphost = $currentNas['nas_ip'];
  $display_secret = $currentNas['radius_secret'];
  $display_coa = isset($currentNas['coa_port']) ? (int)$currentNas['coa_port'] : 3799;
  $display_ros = isset($currentNas['ros_version']) ? (int)$currentNas['ros_version'] : 7;
  $display_tun_id = isset($currentNas['vpn_tunnel_id']) ? (string)$currentNas['vpn_tunnel_id'] : '';

  $matchedTunnelForScript = array(
    'name' => $currentNas['nas_name'],
    'hotspot_name' => $currentNas['nas_name'],
    'protocol' => 'l2tp',
    'username' => 'vpn_' . strtolower($session),
    'password' => 'vpnpass123',
    'ipsec_secret' => $serverCfg['l2tp_ipsec_secret'],
    'server_ip' => $serverCfg['vpn_gateway_ip'],
    'remote_ip' => $display_iphost
  );
  foreach ($vpnTunnels as $tCheck) {
    if (($display_tun_id !== '' && $tCheck['id'] === $display_tun_id) || trim((string)$tCheck['remote_ip']) === $display_iphost) {
      $matchedTunnelForScript = $tCheck;
      $display_tun_id = $tCheck['id'];
      break;
    }
  }
  $generatedScripts = buildMikrotikRadiusVpnScripts($matchedTunnelForScript, $currentNas, $serverCfg);
}
?>
<script>
function PassMk(){
  var x = document.getElementById('passmk');
  x.type = (x.type === 'password') ? 'text' : 'password';
}
function onSelectSettingVpnTunnel(sel) {
  var opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.value) return;
  var ip = opt.getAttribute('data-ip') || '';
  var name = opt.getAttribute('data-name') || '';
  var secret = opt.getAttribute('data-secret') || '';
  if (ip) document.getElementById('ipmik_input').value = ip;
  if (secret) document.getElementById('passmk').value = secret;
  var hsInput = document.querySelector('input[name="hotspotname"]');
  if (name && hsInput && !hsInput.value) hsInput.value = name;
}
</script>

<form autocomplete="off" method="post" action="" name="settings">
<input type="hidden" name="usermik" value="radius">
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
        <h3 class="card-title" style="margin:0;"><i class="fa fa-gear"></i> <?= $_session_settings ?> (RADIUS NAS) &nbsp; | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer" title="Reload data"></i></h3>
        <a href="./admin.php?id=vpn" class="btn bg-info" style="padding:4px 10px; font-size:12px; font-weight:bold;">
          <i class="fa fa-shield"></i> <?= (isset($langid) && $langid === 'bn') ? 'বিল্ট-ইন VPN সার্ভার ম্যানেজার' : 'Built-in VPN Server Manager'; ?>
        </a>
      </div>
      <div class="card-body">
        <?php if (!empty($_SESSION['router_settings_notice'])) { ?>
          <div class="alert alert-success" role="status"><?= htmlspecialchars($_SESSION['router_settings_notice'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php unset($_SESSION['router_settings_notice']); } ?>
        <div class="row">
          <div class="col-6">
            <div class="col-12">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title">NAS Identifier (<?= $_session ?>)</h3>
                </div>
                <div class="card-body">
                  <table class="table">
                    <tr>
                      <td>NAS / <?= $_session_name ?></td>
                      <td><input class="form-control" id="sessname" type="text" name="sessname" title="NAS Session Name" value="<?= explode("-", $session)[0] == "new" ? "" : htmlspecialchars($session); ?>" required="1"/></td>
                    </tr>
                  </table>
                </div>
              </div>
            </div>
            <div class="col-12">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title"><i class="fa fa-shield"></i> RADIUS NAS & VPN Local IP <?= $_SESSION["connect"]; ?></h3>
                </div>
                <div class="card-body">
                  <table class="table table-sm">
                    <tr>
                      <td class="align-middle" style="width:36%;">VPN Tunnel</td>
                      <td>
                        <select name="vpn_tunnel_id" class="form-control" onchange="onSelectSettingVpnTunnel(this)">
                          <option value="">-- Select Built-in VPN Tunnel (or enter VPN IP) --</option>
                          <?php foreach ($vpnTunnels as $tun) { ?>
                            <option value="<?= htmlspecialchars($tun['id']); ?>" data-ip="<?= htmlspecialchars($tun['remote_ip']); ?>" data-name="<?= htmlspecialchars($tun['name']); ?>" data-secret="<?= htmlspecialchars(isset($tun['radius_secret']) ? $tun['radius_secret'] : 'radius123'); ?>" <?= ($display_tun_id === $tun['id']) ? 'selected' : ''; ?>>
                              <?= htmlspecialchars($tun['name']); ?> — <?= htmlspecialchars($tun['remote_ip']); ?> (<?= strtoupper($tun['protocol']); ?>)
                            </option>
                          <?php } ?>
                        </select>
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle"><b>VPN Local IP (NAS IP)</b></td>
                      <td>
                        <input class="form-control" id="ipmik_input" type="text" size="15" name="ipmik" style="font-family:monospace; font-weight:bold;" title="MikroTik VPN Local IP Address (e.g. 10.10.10.2)" value="<?= htmlspecialchars($display_iphost); ?>" required="1"/>
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle"><b>RADIUS Secret</b></td>
                      <td>
                        <div class="input-group">
                          <div class="input-group-11 col-box-10">
                            <input class="group-item group-item-l" id="passmk" type="password" name="passmik" style="font-family:monospace;" title="RADIUS Shared Secret" value="<?= htmlspecialchars($display_secret); ?>" required="1"/>
                          </div>
                          <div class="input-group-1 col-box-2">
                            <div class="group-item group-item-r pd-2p5 text-center align-middle">
                              <input title="Show/Hide Secret" type="checkbox" onclick="PassMk()">
                            </div>
                          </div>
                        </div>
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle">CoA Disconnect Port</td>
                      <td>
                        <input class="form-control" type="number" name="coa_port" style="font-family:monospace;" value="<?= (int)$display_coa; ?>" required="1"/>
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle">Client RouterOS</td>
                      <td>
                        <select name="ros_version" class="form-control">
                          <option value="7" <?= $display_ros === 7 ? 'selected' : ''; ?>>RouterOS v7 (7.x)</option>
                          <option value="6" <?= $display_ros === 6 ? 'selected' : ''; ?>>RouterOS v6 (6.x)</option>
                        </select>
                      </td>
                    </tr>
                    <tr>
                      <td colspan="2">
                        <div class="input-group-5">
                          <input class="group-item group-item-l" type="submit" style="cursor: pointer; font-weight:bold;" name="save" value="Save NAS"/>
                        </div>
                        <div class="input-group-5">
                          <a class="group-item group-item-md pd-2p5 text-center align-middle" href="./admin.php?id=sessions" style="font-weight:bold; display:block;">NAS List</a>
                        </div>
                        <div class="input-group-2">
                          <div style="cursor: pointer;" class="group-item group-item-r pd-2p5 text-center" onclick="location.reload();" title="Reload Data"><i class="fa fa-refresh"></i></div>
                        </div>
                      </td>
                    </tr>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="col-6">
            <div class="col-12">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title">Hotspot & NAS Metadata</h3>
                </div>
                <div class="card-body">
                  <table class="table table-sm">
                    <tr>
                      <td class="align-middle"><?= $_hotspot_name ?></td>
                      <td><input class="form-control" type="text" size="15" maxlength="50" name="hotspotname" title="Hotspot Name" value="<?= htmlspecialchars($hotspotname); ?>" required="1"/></td>
                    </tr>
                    <tr>
                      <td class="align-middle"><?= $_dns_name ?></td>
                      <td><input class="form-control" type="text" size="15" maxlength="500" name="dnsname" title="DNS Name" value="<?= htmlspecialchars($dnsname !== '' ? $dnsname : (strtolower($session) . '.hotspot.local')); ?>" required="1"/></td>
                    </tr>
                    <tr>
                      <td class="align-middle"><?= $_currency ?></td>
                      <td><input class="form-control" type="text" size="3" maxlength="4" name="currency" title="currency" value="<?= htmlspecialchars($currency !== '' ? $currency : 'BDT'); ?>" required="1"/></td>
                    </tr>
                    <tr>
                      <td class="align-middle"><?= $_auto_reload ?></td>
                      <td>
                        <div class="input-group">
                          <div class="input-group-10">
                            <input class="group-item group-item-l" type="number" min="10" max="3600" name="areload" value="<?= htmlspecialchars($areload !== '' ? $areload : '10'); ?>" required="1"/>
                          </div>
                          <div class="input-group-2">
                            <span class="group-item group-item-r pd-2p5 text-center align-middle"><?= $_sec ?></span>
                          </div>
                        </div>
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle"><?= $_traffic_interface ?></td>
                      <td><input class="form-control" type="number" min="1" max="99" name="iface" value="<?= htmlspecialchars($iface !== '' ? $iface : '1'); ?>" required="1"/></td>
                    </tr>
                    <tr>
                      <td><?= $_live_report ?></td>
                      <td>
                        <select class="form-control" name="livereport">
                          <option value="<?= htmlspecialchars($livereport !== '' ? $livereport : 'enable'); ?>"><?= ucfirst($livereport !== '' ? $livereport : 'enable'); ?></option>
                          <option value="enable">Enable</option>
                          <option value="disable">Disable</option>
                        </select>
                      </td>
                    </tr>
                  </table>
                </div>
              </div>
            </div>

            <div class="col-12">
              <div class="card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                  <h3 class="card-title" style="margin:0;"><i class="fa fa-terminal"></i> MikroTik VPN + RADIUS Script (ROS v<?= $display_ros; ?>)</h3>
                  <button type="button" class="btn bg-success" style="padding:3px 10px; font-size:12px; cursor:pointer;" onclick="var t=document.getElementById('nasInlineScript'); t.select(); document.execCommand('copy'); this.textContent='Copied!';">
                    <i class="fa fa-copy"></i> Copy Script
                  </button>
                </div>
                <div class="card-body">
                  <textarea id="nasInlineScript" class="form-control" style="width:100%; height:170px; font-family:monospace; font-size:11px; background:#0d1117; color:#e6edf3;" readonly><?= htmlspecialchars($display_ros === 6 ? $generatedScripts['ros6'] : $generatedScripts['ros7']); ?></textarea>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</form>

<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
session_start();
// hide all error
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {

  include ('./include/version.php');
  include_once ('./include/reseller_acl.php');
  include_once ('./include/radius_engine.php');

  $menuSession = (!empty($session) && $session !== 'new') ? $session : getDefaultCentralRadiusSession();
  $nasListForMenu = loadRadiusNasList();
  $nasCountForMenu = is_array($nasListForMenu) ? count($nasListForMenu) : 0;

  $btnmenuactive = "font-weight: bold;background-color: #f9f9f9; color: #000000";
  if ($id == "" && ($hotspot == "dashboard" || ($hotspot == "" && $hotspotuser == "" && $userprofile == "" && $report == "" && $sys == "" && $minterface == "" && $ppp == "" && $removeuseractive == "" && $removehost == "" && $removecookie == "" && $removeipbinding == "" && $removehotspotuser == "" && $removehotspotusers == "" && $removeuserprofile == "" && $resethotspotuser == "" && $removehotspotuserbycomment == "" && $removeexpiredhotspotuser == ""))) {
    $shome = "active";
    $mpage = $_dashboard;
  } elseif ($hotspot == "quick-print" || $hotspot == "list-quick-print") {
    $squick = "active";
    $mpage = $_quick_print;   
  } elseif ($hotspot == "users" || $userbyprofile != "" || $hotspot == "export-users" || $removehotspotuserbycomment != "" || $removehotspotuser != "" || $removehotspotusers != "" || $disablehotspotuser || $enablehotspotuser != "") {
    $susersl = "active";
    $susers = "active";
    $mpage = $_users;
    $umenu = "menu-open";
  } elseif ($hotspotuser == "add") {
    $sadduser = "active";
    $mpage = $_users;
    $susers = "active";
    $umenu = "menu-open";
  } elseif ($hotspotuser == "generate") {
    $sgenuser = "active";
    $mpage = $_users;
    $susers = "active";
    $umenu = "menu-open";
  } elseif ($userbyname != ""  || $resethotspotuser != "") {
    $susers = "active";
    $mpage = $_users;
    $umenu = "menu-open";
  } elseif ($hotspot == "user-profiles") {
    $suserprofiles = "active";
    $suserprof = "active";
    $mpage = $_user_profile;
    $upmenu = "menu-open";
  } elseif ($hotspot == "active" || $removeuseractive != "") {
    $sactive = "active";
    $mpage = $_hotspot_active;
    $hamenu = "menu-open";
  } elseif ($hotspot == "hosts" || $hotspot == "hostp" || $hotspot == "hosta" || $removehost != "") {
    $shosts = "active";
    $mpage = $_hosts;
    $hmenu = "menu-open";
  } elseif ($hotspot == "dhcp-leases") {
    $slease = "active";
    $mpage = $_dhcp_leases;
  } elseif ($minterface == "traffic-monitor") {
    $strafficmonitor = "active";
    $mpage = $_traffic_monitor;  
  } elseif ($hotspot == "ipbinding" || $hotspot == "binding" || $removeipbinding != "" || $enableipbinding != "" || $disableipbinding != "") {
    $sipbind = "active";
    $mpage = $_ip_bindings;
    $ibmenu = "menu-open";
  } elseif ($hotspot == "template-editor") {
    $ssett = "active";
    $teditor = "active";
    $seditor = "active";
    $mpage = $_template_editor;
    $settmenu = "menu-open";
  } elseif ($hotspot == "uplogo") {
    $ssett = "active";
    $uplogo = "active";
    $mpage = $_upload_logo;
    $settmenu = "menu-open";
  } elseif ($hotspot == "cookies" || $removecookie != "") {
    $scookies = "active";
    $mpage = $_hotspot_cookies;
    $cmenu = "menu-open";
  } elseif ($hotspot == "log") {
    $log = "active";
    $slog = "active";
    $mpage = $_hotspot_log;
    $lmenu = "menu-open";
  } elseif ($report == "userlog") {
    $log = "active";
    $sulog = "active";
    $mpage = $_user_log;
    $lmenu = "menu-open";
  } elseif ($ppp == "secrets" || $ppp == "addsecret" || $enablesecr != "" || $disablesecr != "" || $removesecr != "" || $secretbyname != "") {
    $mppp = "active";
    $ssecrets = "active";
    $mpage = $_ppp_secrets;
    $pppmenu = "menu-open";
  } elseif ($ppp == "profiles" || $removepprofile != "" || $ppp == "add-profile" || $ppp == "edit-profile"  ) {
    $mppp = "active";
    $spprofile = "active";
    $mpage = $_ppp_profiles;
    $pppmenu = "menu-open";
  } elseif ($ppp == "active" || $removepactive != "") {
    $mppp = "active";
    $spactive = "active";
    $mpage = $_ppp_active;
    $pppmenu = "menu-open";
  } elseif ($sys == "scheduler" || $enablesch != "" || $disablesch != "" || $removesch != "") {
    $sysmenu = "active";
    $ssch = "active";
    $mpage = $_system_scheduler;
    $schmenu = "menu-open";
  } elseif ($report == "selling" || $report == "resume-report") {
    $sselling = "active";
    $mpage = $_report;
  } elseif ($userprofile == "add") {
    $suserprof = "active";
    $sadduserprof = "active";
    $mpage = $_user_profile;
    $upmenu = "menu-open";
  } elseif ($userprofilebyname != "") {
    $suserprof = "active";
    $mpage = $_user_profile;
    $upmenu = "menu-open";
  } elseif ($hotspot == "users-by-profile") {
    $susersbp = "active";
    $mpage = $_vouchers;
  } elseif ($userbyname != "") {
    $mpage = $_users;
    $susers = "active";
  } elseif ($hotspot == "about") {
    $mpage = $_about;
    $sabout = "active";
  } elseif ($hotspot == "resellers" || $id == "resellers") {
    $sresellers = "active";
    $mpage = isset($_reseller) ? $_reseller : "Reseller";
  } elseif ($id == "sessions" || $id == "remove") {
    $ssesslist = "active";
    $mpage = "NAS";
  } elseif ($id == "admin-settings" || $hotspot == "admin-settings") {
    $sadmsettings = "active";
    $ssett = "active";
    $settmenu = "menu-open";
    $mpage = $_settings;
  } elseif (($id == "settings" && $session == "new") || ($id == "settings" && !empty($router))) {
    $snsettings = "active";
    $mpage = "Add NAS";
  } elseif ($id == "settings" || $id == "connect") {
    $ssesslist = "active";
    $mpage = "NAS Settings";
  } elseif ($id == "about") {
    $sabout = "active";
    $mpage = $_about;
  } elseif ($id == "uplogo") {
    $suplogo = "active";
    $mpage = $_upload_logo;
  } elseif ($id == "editor") {
    $seditor = "active";
    $mpage = $_template_editor;
  } elseif ($hotspot == "reset-voucher" || $id == "reset-voucher") {
    $sresetvoucher = "active";
    $mpage = isset($_reset_voucher) ? $_reset_voucher : "Reset Voucher";
  } elseif ($hotspot == "vpn" || $id == "vpn") {
    $svpn = "active";
    $mpage = isset($_vpn) ? $_vpn : "VPN";
  } elseif ($hotspot == "system-update" || $id == "system-update") {
    $ssysupdate = "active";
    $ssett = "active";
    $settmenu = "menu-open";
    $mpage = isset($_system_update) ? $_system_update : "System Update";
  }
}

$idleto = "5";
$didleto = 'display:block;';
$ngwHandshake = !empty($GLOBALS['NGW_LOGIN_HANDSHAKE']) || !empty($_SESSION['mikhmon_login_handshake']);
if (!empty($_SESSION['mikhmon_login_handshake'])) {
  unset($_SESSION['mikhmon_login_handshake']);
}
$isLogoutReq = ($id === 'logout' || $hotspot === 'logout');
?>
<span style="display:none;" id="idto"><?= $idleto ;?></span>
<?php if (!$isLogoutReq) { ?>
<script>
(function(){
  var MAX_IDLE_MS = 300000; // 5 minutes (300,000 ms)
  var isHandshake = <?= $ngwHandshake ? 'true' : 'false'; ?>;
  var logoutUrl = './admin.php?id=logout';
  try {
    if (isHandshake) {
      sessionStorage.setItem('ngw_tab_auth', '1');
      sessionStorage.setItem('ngw_last_act', String(Date.now()));
    } else {
      var tabAuth = sessionStorage.getItem('ngw_tab_auth');
      if (tabAuth !== '1') {
        window.location.replace(logoutUrl);
        return;
      }
      var prevAct = parseInt(sessionStorage.getItem('ngw_last_act') || '0', 10);
      if (prevAct > 0 && (Date.now() - prevAct) > MAX_IDLE_MS) {
        sessionStorage.removeItem('ngw_tab_auth');
        sessionStorage.removeItem('ngw_last_act');
        window.location.replace(logoutUrl);
        return;
      }
      sessionStorage.setItem('ngw_last_act', String(Date.now()));
    }
  } catch (e) {}

  var lastActTs = Date.now();
  function recordUserActivity() {
    var now = Date.now();
    if ((now - lastActTs) > MAX_IDLE_MS) {
      triggerAutoLogout();
      return;
    }
    lastActTs = now;
    try {
      sessionStorage.setItem('ngw_last_act', String(now));
    } catch (e) {}
  }

  function triggerAutoLogout() {
    try {
      sessionStorage.removeItem('ngw_tab_auth');
      sessionStorage.removeItem('ngw_last_act');
    } catch (e) {}
    window.location.replace(logoutUrl);
  }

  function checkIdleExpiration() {
    var storedTs = lastActTs;
    try {
      var s = parseInt(sessionStorage.getItem('ngw_last_act') || '0', 10);
      if (s > storedTs) storedTs = s;
    } catch (e) {}
    if ((Date.now() - storedTs) >= MAX_IDLE_MS) {
      triggerAutoLogout();
    }
  }

  var events = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'];
  for (var i = 0; i < events.length; i++) {
    window.addEventListener(events[i], recordUserActivity, { passive: true });
  }
  // Successful voucher batch steps are active work, including long batches.
  window.addEventListener('ngw-voucher-progress', recordUserActivity);
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
      checkIdleExpiration();
    }
  });
  window.addEventListener('focus', checkIdleExpiration);
  setInterval(checkIdleExpiration, 2000);
})();
</script>
<?php } ?>

<div id="navbar" class="navbar">
  <div class="navbar-left">
    <a id="brand" class="text-center" style="font-size:13px;" href="./?session=<?= urlencode($menuSession); ?>">NextGan WiFi Solution</a>

    <a id="openNav" class="navbar-hover" href="javascript:void(0)"><i class="fa fa-bars"></i></a>
    <a id="closeNav" class="navbar-hover" href="javascript:void(0)"><i class="fa fa-bars"></i></a>
    <a id="cpage" class="navbar-left" href="javascript:void(0)"><?= $mpage; ?></a>
    <?php if (isMikhmonAdmin()) { ?>
    <span class="navbar-left" style="display:inline-flex; align-items:center; gap:12px; padding:10px 10px; user-select:none;" title="Server CPU &amp; RAM">
      <span style="display:inline-flex; align-items:center; gap:5px;" title="Server CPU">
        <i id="ngw-cpu-icon" class="fa fa-microchip" style="font-size:13px; color:#2ea043; transition:color 0.3s ease;"></i>
        <span style="display:inline-block; width:32px; height:6px; background:rgba(150,150,150,0.28); border-radius:3px; overflow:hidden; vertical-align:middle;">
          <span id="ngw-cpu-bar" style="display:block; height:100%; width:12%; background:#2ea043; border-radius:3px; transition:width 0.3s ease, background-color 0.3s ease;"></span>
        </span>
        <span id="ngw-cpu-pct" style="font-size:11px; font-weight:bold; min-width:26px; display:inline-block;">--%</span>
      </span>
      <span style="display:inline-flex; align-items:center; gap:5px;" title="Server RAM">
        <svg id="ngw-ram-icon" width="14" height="13" viewBox="0 0 16 14" fill="none" style="vertical-align:middle; color:#2ea043; transition:color 0.3s ease;">
          <rect x="1" y="3" width="14" height="7" rx="1.2" stroke="currentColor" stroke-width="1.5"/>
          <rect x="3.5" y="5" width="2" height="3" fill="currentColor"/>
          <rect x="7" y="5" width="2" height="3" fill="currentColor"/>
          <rect x="10.5" y="5" width="2" height="3" fill="currentColor"/>
          <path d="M3.5 10V12.5M6.5 10V12.5M9.5 10V12.5M12.5 10V12.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
        </svg>
        <span style="display:inline-block; width:32px; height:6px; background:rgba(150,150,150,0.28); border-radius:3px; overflow:hidden; vertical-align:middle;">
          <span id="ngw-ram-bar" style="display:block; height:100%; width:28%; background:#2ea043; border-radius:3px; transition:width 0.3s ease, background-color 0.3s ease;"></span>
        </span>
        <span id="ngw-ram-pct" style="font-size:11px; font-weight:bold; min-width:26px; display:inline-block;">--%</span>
      </span>
    </span>
    <?php } ?>
  </div>
  <div class="navbar-right">
    <?php if (!empty($_SESSION['mikhmon_impersonator_admin'])) { ?>
    <a href="./admin.php?id=return-to-admin" style="background:#1f6feb; color:#fff; border-radius:3px; padding:4px 8px; margin-right:6px; font-weight:bold; font-size:12px;" title="Return to Admin Panel"><i class="fa fa-user-secret mr-1"></i> <?= htmlspecialchars(isset($_back_to_admin_btn) ? $_back_to_admin_btn : 'Admin Panel'); ?></a>
    <?php } ?>
    <a id="logout" href="./admin.php?id=logout"><i class="fa fa-sign-out mr-1"></i> <?= $_logout ?></a>
    <?php $urlSep = (strpos($url, '?') !== false) ? '&' : '?'; ?>
    <select class="stheme ses text-right mr-t-10 pd-5">
      <option> <?= $_theme ?></option>
      <?php for ($i = 0; $i < count($mtheme); $i++) {
        echo '<option value="'.$url.$urlSep.'set-theme='.$mtheme[$i],'">'.ucfirst($mtheme[$i]),'</option>';
      }
      ?>
    </select>
    <select class="slang ses text-right mr-t-10 pd-5">
      <option> <?= $language ?></option>
      <?php 
        $fileList = glob('lang/*');
        foreach($fileList as $filename){
          if(is_file($filename)){
            $filename = substr(explode("/",$filename)[1],0,-4);
            if($filename == "isocodelang"){}else{
              echo '<option value="'.$url.$urlSep.'setlang=' . $filename . '">'. $isocodelang[$filename]. '</option>'; 
           }   
          }
        }
      ?>
    </select>
    <a href="./admin.php?id=sessions" title="Registered RADIUS NAS Routers" style="display:inline-block; padding:4px 8px; font-size:12px; font-weight:bold;">
      <i class="fa fa-server mr-1"></i> NAS (<?= (int)$nasCountForMenu; ?>)
    </a>
    <a title="Idle Timeout (5m)" style="<?= $didleto; ?>"><span style="width:70px;" class="pd-5 radius-3"><i class="fa fa-clock-o mr-1"></i> <span class="mr-1" id="timer"></span></span></a>
  </div>
</div>

<div id="sidenav" class="sidenav">
  <?php if (isResetOnlyReseller()) { ?>
  <a href="./admin.php?id=reset-voucher&session=<?= urlencode($menuSession); ?>" class="menu <?= $sresetvoucher; ?>"><i class="fa fa-refresh"></i> <?= isset($_reset_voucher) ? $_reset_voucher : 'Reset Voucher'; ?></a>
  <a href="./admin.php?id=logout" class="menu"><i class="fa fa-sign-out"></i> <?= $_logout ?></a>
  <?php } else { ?>
  <div class="menu text-center align-middle card-header" style="border-radius:0;">
    <h3 id="MikhmonSession" style="margin:0; font-size:14px;"><i class="fa fa-bullseye text-green"></i> Central RADIUS</h3>
  </div>
  <?php if (hasResellerPermission('view_dashboard')) { ?>
  <a href="./?session=<?= urlencode($menuSession); ?>" class="menu <?= $shome; ?>"><i class="fa fa-dashboard"></i> <?= $_dashboard ?></a>
  <?php } ?>
  <!--hotspot-->
  <?php if (hasResellerPermission('view_users') || hasResellerPermission('add_user') || hasResellerPermission('generate_voucher') || hasResellerPermission('view_profiles') || hasResellerPermission('add_profile') || hasResellerPermission('view_active') || hasResellerPermission('view_hosts') || hasResellerPermission('view_ipbinding') || hasResellerPermission('view_cookies')) { ?>
  <div class="dropdown-btn <?= $susers . $suserprof . $sactive . $shosts . $sipbind . $scookies; ?>"><i class="fa fa-wifi"></i> Hotspot
    <i class="fa fa-caret-down"></i>
  </div>
  <div class="dropdown-container <?= $umenu . $upmenu . $hamenu . $hmenu . $ibmenu . $cmenu; ?>">
    <!--users-->
    <?php if (hasResellerPermission('view_users') || hasResellerPermission('add_user') || hasResellerPermission('generate_voucher')) { ?>
    <div class="dropdown-btn <?= $susers; ?>"><i class="fa fa-users"></i> <?= $_users ?>
      <i class="fa fa-caret-down"></i>
    </div>
    <div class="dropdown-container <?= $umenu; ?>">
      <?php if (hasResellerPermission('view_users')) { ?>
      <a href="./?hotspot=users&profile=all&session=<?= urlencode($menuSession); ?>" class="<?= $susersl; ?>"> &nbsp;&nbsp;&nbsp;<i class="fa fa-list "></i> <?= $_user_list ?> </a>
      <?php } ?>
      <?php if (hasResellerPermission('add_user')) { ?>
      <a href="./?hotspot-user=add&session=<?= urlencode($menuSession); ?>" class="<?= $sadduser; ?>"> &nbsp;&nbsp;&nbsp;<i class="fa fa-user-plus "></i> <?= $_add_user ?> </a>
      <?php } ?>
      <?php if (hasResellerPermission('generate_voucher')) { ?>
      <a href="./?hotspot-user=generate&session=<?= urlencode($menuSession); ?>" class="<?= $sgenuser; ?>"> &nbsp;&nbsp;&nbsp;<i class="fa fa-user-plus"></i> <?= $_generate ?> </a>
      <?php } ?>
    </div>
    <?php } ?>
    <!--profile-->
    <?php if (hasResellerPermission('view_profiles') || hasResellerPermission('add_profile')) { ?>
    <div class="dropdown-btn <?= $suserprof; ?>"><i class=" fa fa-pie-chart"></i> <?= $_user_profile ?>
      <i class="fa fa-caret-down"></i>
    </div>
    <div class="dropdown-container <?= $upmenu; ?>">
      <?php if (hasResellerPermission('view_profiles')) { ?>
      <a href="./?hotspot=user-profiles&session=<?= urlencode($menuSession); ?>" class=" <?= $suserprofiles; ?>"> &nbsp;&nbsp;&nbsp;<i class="fa fa-list "></i> <?= $_user_profile_list ?> </a>
      <?php } ?>
      <?php if (hasResellerPermission('add_profile')) { ?>
      <a href="./?user-profile=add&session=<?= urlencode($menuSession); ?>" class=" <?= $sadduserprof; ?>"> &nbsp;&nbsp;&nbsp;<i class="fa fa-plus-square "></i> <?= $_add_user_profile ?> </a>
      <?php } ?>
    </div>
    <?php } ?>
    <!--active-->
    <?php if (hasResellerPermission('view_active')) { ?>
    <a href="./?hotspot=active&session=<?= urlencode($menuSession); ?>" class="menu <?= $sactive; ?>"><i class=" fa fa-wifi"></i> <?= $_hotspot_active ?></a>
    <?php } ?>
    <!--hosts-->
    <?php if (hasResellerPermission('view_hosts')) { ?>
    <a href="./?hotspot=hosts&session=<?= urlencode($menuSession); ?>" class="menu <?= $shosts; ?>"><i class=" fa fa-laptop"></i> <?= $_hosts ?></a>
    <?php } ?>
    <!--ip bindings-->
    <?php if (hasResellerPermission('view_ipbinding')) { ?>
    <a href="./?hotspot=ipbinding&session=<?= urlencode($menuSession); ?>" class="menu <?= $sipbind; ?>"><i class=" fa fa-address-book"></i> <?= $_ip_bindings ?></a>
    <?php } ?>
    <!--cookies-->
    <?php if (hasResellerPermission('view_cookies')) { ?>
    <a href="./?hotspot=cookies&session=<?= urlencode($menuSession); ?>" class="menu <?= $scookies; ?>"><i class=" fa fa-hourglass"></i> <?= $_hotspot_cookies ?></a>
    <?php } ?>
  </div>
  <?php } ?>
  <!--quick print-->
  <?php if (hasResellerPermission('print_voucher')) { ?>
  <a href="./?hotspot=quick-print&session=<?= urlencode($menuSession); ?>" class="menu <?= $squick; ?>"> <i class="fa fa-print"></i> <?= $_quick_print ?> </a>
  <?php } ?>
  <!--vouchers-->
  <?php if (hasResellerPermission('view_users')) { ?>
  <a href="./?hotspot=users-by-profile&session=<?= urlencode($menuSession); ?>" class="menu <?= $susersbp; ?>"> <i class="fa fa-ticket"></i> <?= $_vouchers ?> </a>
  <?php } ?>
  <!--reset voucher-->
  <?php if (hasResellerPermission('reset_voucher')) { ?>
  <a href="./?hotspot=reset-voucher&session=<?= urlencode($menuSession); ?>" class="menu <?= $sresetvoucher; ?>"> <i class="fa fa-refresh"></i> <?= isset($_reset_voucher) ? $_reset_voucher : 'Reset Voucher'; ?> </a>
  <?php } ?>
  <!--log-->
  <?php if (hasResellerPermission('view_logs')) { ?>
  <div class="dropdown-btn <?= $log; ?>"><i class=" fa fa-align-justify"></i> <?= $_log ?>
    <i class="fa fa-caret-down"></i>
  </div>
  <div class="dropdown-container <?= $lmenu; ?>">
    <a href="./?hotspot=log&session=<?= urlencode($menuSession); ?>" class="<?= $slog; ?>"> <i class="fa fa-wifi "></i> <?= $_hotspot_log ?> </a>
    <a href="./?report=userlog&idbl=<?= strtolower(date("M")) . date("Y"); ?>&session=<?= urlencode($menuSession); ?>" class=" <?= $sulog; ?>"> <i class="fa fa-users "></i> <?= $_user_log ?> </a>
  </div>
  <?php } ?>
  <!--system-->
  <?php if (hasResellerPermission('view_scheduler') || (hasResellerPermission('export_users') && hasResellerPermission('generate_voucher'))) { ?>
  <div class="dropdown-btn <?= $sysmenu; ?>"><i class=" fa fa-gear"></i> <?= $_system ?>
    <i class="fa fa-caret-down"></i> &nbsp;
  </div>
  <div class="dropdown-container <?= $schmenu; ?>">
    <?php if (hasResellerPermission('export_users') && hasResellerPermission('generate_voucher')) { ?>
    <a href="./hotspot/usertransfer.php?session=<?= rawurlencode($menuSession); ?>"><i class="fa fa-exchange"></i> User Transfer</a>
    <?php } ?>
    <?php if (hasResellerPermission('view_scheduler')) { ?>
    <a href="./?system=scheduler&session=<?= urlencode($menuSession); ?>" class="<?= $ssch; ?>"> <i class="fa fa-clock-o "></i> <?= $_system_scheduler ?> </a>
    <?php } ?>
  </div>
  <?php } ?>
  <!--dhcp leases-->
  <?php if (hasResellerPermission('view_dhcp')) { ?>
  <a href="./?hotspot=dhcp-leases&session=<?= urlencode($menuSession); ?>" class="menu <?= $slease; ?>"><i class=" fa fa-sitemap"></i> <?= $_dhcp_leases ?></a>
  <?php } ?>
  <!--traffic monitor-->
  <?php if (hasResellerPermission('view_traffic')) { ?>
  <a href="./?interface=traffic-monitor&session=<?= urlencode($menuSession); ?>" class="menu <?= $strafficmonitor; ?>"><i class=" fa fa-area-chart"></i> <?= $_traffic_monitor ?></a>
  <?php } ?>
  <!--report-->
  <?php if (hasResellerPermission('view_report')) { ?>
  <a href="./?report=selling&idbl=<?= strtolower(date("M")) . date("Y"); ?>&session=<?= urlencode($menuSession); ?>" class="menu <?= $sselling; ?>"><i class="nav-icon fa fa-money"></i> <?= $_report ?></a>
  <?php } ?>
  <!--NAS & Add NAS-->
  <?php if (isMikhmonAdmin() || hasResellerPermission('manage_router_settings')) { ?>
  <a href="./admin.php?id=sessions" class="menu <?= $ssesslist; ?>"><i class="fa fa-server"></i> NAS</a>
  <a href="./admin.php?id=settings&router=new-<?= rand(1111,9999) ?>" class="menu <?= $snsettings ?>"><i class="fa fa-plus"></i> Add NAS</a>
  <?php } ?>
  <!--VPN, Reseller, Settings, Template Editor, System Update-->
  <?php if (isMikhmonAdmin()) { ?>
  <a href="./admin.php?id=vpn" class="menu <?= $svpn; ?>"><i class="fa fa-shield"></i> <?= isset($_vpn) ? $_vpn : 'VPN'; ?></a>
  <a href="./admin.php?id=resellers" class="menu <?= $sresellers; ?>"><i class="fa fa-users"></i> <?= isset($_reseller) ? $_reseller : 'Reseller'; ?></a>
  <a href="./admin.php?id=editor" class="menu <?= $seditor; ?>"><i class="fa fa-edit"></i> <?= isset($_template_editor) ? $_template_editor : 'Template Editor'; ?></a>
  <a href="./admin.php?id=admin-settings" class="menu <?= $sadmsettings; ?>"><i class="fa fa-gear"></i> <?= $_settings ?></a>
  <a href="./admin.php?id=system-update" class="menu <?= $ssysupdate; ?>"><i class="fa fa-cloud-upload"></i> <?= isset($_system_update) ? $_system_update : 'System Update'; ?></a>
  <?php } else { ?>
  <?php if (hasResellerPermission('edit_own_template') || hasResellerPermission('save_own_template') || hasResellerPermission('preview_assigned_template') || hasResellerPermission('use_assigned_template') || hasResellerPermission('print_voucher')) { ?>
  <a href="./admin.php?id=editor" class="menu <?= $seditor; ?>"><i class="fa fa-edit"></i> <?= isset($_template_editor) ? $_template_editor : 'Template Editor'; ?></a>
  <?php } ?>
  <?php } ?>
  <!--about-->
  <a href="./admin.php?id=about" class="menu <?= $sabout; ?>"><i class="fa fa-info-circle"></i> <?= $_about ?></a>
  <!--logout-->
  <a href="./admin.php?id=logout" class="menu"><i class="fa fa-sign-out"></i> <?= $_logout ?></a>
  <?php } ?>
</div>
<script>
$(document).ready(function(){
  $(".stheme").change(function(){
    notify("<?= $_loading_theme ?>");
    stheme(this.value)
  });
  $(".slang").change(function(){
    notify("<?= $_loading ?>");
    stheme(this.value)
  });
});
</script>
<div id="notify"><div class="message"></div></div>
<div id="temp"></div>
<?php if (isMikhmonAdmin()) { ?>
<script>
(function(){
  var metricsInFlight = false;
  var metricsXhr = null;
  var metricsInterval = null;
  var pageUnloading = false;

  window.addEventListener('beforeunload', function(){
    pageUnloading = true;
    if (metricsInterval) clearInterval(metricsInterval);
    if (metricsXhr && metricsXhr.readyState !== 4) {
      try { metricsXhr.abort(); } catch(e) {}
    }
  });

  function pickBarColor(pct) {
    if (pct >= 85) return '#f85149';
    if (pct >= 61) return '#f0ad4e';
    return '#2ea043';
  }
  function updateServerMetricsBar() {
    if (pageUnloading || !window.jQuery || metricsInFlight) return;
    metricsInFlight = true;
    metricsXhr = $.ajax({
      url: './status/server_metrics.php',
      dataType: 'json',
      timeout: 2500,
      success: function(res) {
        if (pageUnloading || !res || !res.ok) return;
        var cpu = Math.max(1, Math.min(100, parseInt(res.cpu, 10) || 1));
        var ram = Math.max(1, Math.min(100, parseInt(res.ram, 10) || 1));
        var cpuCol = pickBarColor(cpu);
        var ramCol = pickBarColor(ram);
        var cBar = document.getElementById('ngw-cpu-bar');
        var cIco = document.getElementById('ngw-cpu-icon');
        var cPct = document.getElementById('ngw-cpu-pct');
        var rBar = document.getElementById('ngw-ram-bar');
        var rIco = document.getElementById('ngw-ram-icon');
        var rPct = document.getElementById('ngw-ram-pct');
        if (cBar) { cBar.style.width = Math.max(6, cpu) + '%'; cBar.style.backgroundColor = cpuCol; }
        if (cIco) { cIco.style.color = cpuCol; }
        if (cPct) { cPct.textContent = cpu + '%'; }
        if (rBar) { rBar.style.width = Math.max(6, ram) + '%'; rBar.style.backgroundColor = ramCol; }
        if (rIco) { rIco.style.color = ramCol; }
        if (rPct) { rPct.textContent = ram + '%'; }
      },
      complete: function() {
        metricsInFlight = false;
        metricsXhr = null;
      }
    });
  }
  $(document).ready(function(){
    updateServerMetricsBar();
    metricsInterval = setInterval(updateServerMetricsBar, 1000);
  });
})();
</script>
<?php } ?>

<div id="main">  
<div id="loading" class="lds-dual-ring"></div>
<?php if($hotspot == 'template-editor' || $id == 'editor'){
echo '<div class="main-container">';
}else{
  echo '<div class="main-container" style="display:none">';
}
?>

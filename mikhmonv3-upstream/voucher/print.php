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

error_reporting(0);

ob_start("ob_gzhandler");

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  
  date_default_timezone_set($_SESSION['timezone']);
  
// load session MikroTik
  $session = $_GET['session'];
  include_once('../include/reseller_acl.php');
  if (!canAccessRouterSession($session) || !hasResellerPermission('print_voucher')) {
    echo "Access Denied: You do not have permission to print vouchers for this router session.";
    exit;
  }

// load config
  include('../include/config.php');
  include('../include/readcfg.php');

  include('../lib/formatbytesbites.php');

  $id = $_GET['id'];
  $qr = $_GET['qr'];
  $small = $_GET['small'];
  $userp = $_GET['user'];

  require('../lib/routeros_api.class.php');
  $API = new RouterosAPI();
  $API->debug = false;
  $API->connect($iphost, $userhost, decrypt($passwdhost));

  

  if ($userp != "") {
    $usermode = explode('-', $userp)[0];
    $pulluser = explode('-', $userp);
    $iuser = count($pulluser);
    $prefix = explode('-', $userp)[$iuser - 2];
    $user = explode('-', $userp)[$iuser - 1];
    if ($iuser == 3) {
      $user = $prefix . "-" . $user;
    } else {
      $user = $user;
    }
    $getuser = $API->comm("/ip/hotspot/user/print", array("?name" => "$user"));
    $TotalReg = count($getuser);
  } elseif ($id != "") {
    $usermode = explode('-', $id)[0];
    $getuser = $API->comm('/ip/hotspot/user/print', array("?comment" => "$id", "?uptime" => "0s"));
    $TotalReg = count($getuser);
  }
  $getuprofile = $getuser[0]['profile'];


  $getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$getuprofile"));
  $getsharedu = $getprofile[0]['shared-users'];
  $ponlogin = $getprofile[0]['on-login'];
  $validity = explode(",", $ponlogin)[3];
  $getprice = explode(",", $ponlogin)[2];
  $getsprice = explode(",", $ponlogin)[4];

 
  
    if($getsprice == "0" && $getprice != "0"){
      if ($currency == in_array($currency, $cekindo['indo'])) {
        $price = $currency . " " . number_format((float)$getprice, 0, ",", ".");
      } else {
        $price = $currency . " " . number_format((float)$getprice, 2);
      }
    }else if($getsprice != "0"){
      if ($currency == in_array($currency, $cekindo['indo'])) {
        $price = $currency . " " . number_format((float)$getsprice, 0, ",", ".");
      } else {
        $price = $currency . " " . number_format((float)$getsprice, 2);
      }
    }else if ($getsprice == "0") {
      $price = "";
    }

    
  

  // Admin printing must use the explicitly assigned reseller's own template/logo.
  // For reseller sessions, retain the authenticated reseller's own assignment.
  $printResellerId = null;
  if (isMikhmonAdmin()) {
    $assignedPrintReseller = findResellerAssignedToRouterSession($session);
    if (is_array($assignedPrintReseller) && isset($assignedPrintReseller['id'])) {
      $printResellerId = (string)$assignedPrintReseller['id'];
    }
  }
  $logo = resolveActiveVoucherLogo($session, '../', true, $printResellerId);

  $requestedVariant = 'assigned';
  if ($userp != "") {
    $requestedVariant = 'thermal';
  } elseif ($small == "yes") {
    $requestedVariant = 'small';
  }
  $resolvedTemplatePath = resolveActiveVoucherTemplatePath($requestedVariant, $printResellerId);

}
?>
<!DOCTYPE html>
<html>
	<head>
		<title>Voucher-<?= $hotspotname . "-" . $getuprofile . "-" . $id; ?></title>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<meta http-equiv="pragma" content="no-cache" />
		<link rel="icon" type="image/png" href="../img/favicon.png?v=nextgan" />
		<script src="../js/qrious.min.js"></script>
		<style>
body {
  color: #000000;
  background-color: #FFFFFF;
  font-size: 14px;
  font-family:  'Helvetica', arial, sans-serif;
  margin: 0px;
  -webkit-print-color-adjust: exact;
}
table.voucher {
  display: inline-block;
  border: 2px solid black;
  margin: 2px;
}
@page
{
  size: auto;
  margin-left: 7mm;
  margin-right: 3mm;
  margin-top: 9mm;
  margin-bottom: 3mm;
}
@media print
{
  table { page-break-after:auto }
  tr    { page-break-inside:avoid; page-break-after:auto }
  td    { page-break-inside:avoid; page-break-after:auto }
  thead { display:table-header-group }
  tfoot { display:table-footer-group }
}
#num {
  float:right;
  display:inline-block;
}
.qrc {
  width:30px;
  height:30px;
  margin-top:1px;
}
		</style>
	</head>
	<body onload="window.print()">

<?php for ($i = 0; $i < $TotalReg; $i++) {;
  $regtable = $getuser[$i];
  $uid = str_replace("=","",base64_encode($regtable['.id']));
  $idqr = str_replace("=","",base64_encode(($regtable['.id']."qr")));
  $username = $regtable['name'];
  $password = isset($regtable['password']) ? (string)$regtable['password'] : '';
  $profile = $regtable['profile'];
  $timelimit = $regtable['limit-uptime'];
  $getdatalimit = $regtable['limit-bytes-total'];
  $comment = isset($regtable['comment']) ? (string)$regtable['comment'] : '';
  if ($password === '' && ($usermode === 'vc' || substr($comment, 0, 3) === 'vc-')) {
    $password = $username;
  }
  if ($getdatalimit == 0) {
    $datalimit = "";
  } else {
    $datalimit = formatBytes($getdatalimit, 2);
  }
  
  $urilogin = "http://$dnsname/login?username=$username&password=$password";
  $qrcode = "
	<canvas class='qrcode' id='".$uid."'></canvas>
    <script>
      (function() {
        var ".$uid." = new QRious({
          element: document.getElementById('".$uid."'),
          value: '".$urilogin."',
          size:'256'
        });

      })();
    </script>
	";
 
  $num = $i + 1;
  ?>
<?php
include($resolvedTemplatePath);
?>
<?php 
} ?>

	
</body>
</html>

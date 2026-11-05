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
// load session MikroTik
  $session = $_GET['session'];
  include_once('../include/reseller_acl.php');
  if (!canAccessRouterSession($session) || !hasResellerPermission('view_report')) {
    exit;
  }
// set  timezone
date_default_timezone_set($_SESSION['timezone']);

// lang
include('../include/lang.php');
$selected_langid = (isset($langid) && $langid !== '') ? $langid : 'en';
include('../lang/en.php');
$langid = $selected_langid;
if ($langid !== 'en' && is_file('../lang/'.$langid.'.php')) {
  include('../lang/'.$langid.'.php');
}


// load config
  include('../include/config.php');
  include('../include/readcfg.php');

// routeros api
  session_write_close();
  include_once('../lib/routeros_api.class.php');
  include_once('../lib/formatbytesbites.php');
  $API = new RouterosAPI();
  $API->debug = false;
  $API->connect($iphost, $userhost, decrypt($passwdhost));

  if ($livereport == "disable") {
    $logh = "457px";
    $lreport = "style='display:none;'";
  } else {
    $logh = "350px";
    $lreport = "style='display:block;'";
// get selling report
    $thisD = date("d");
    $thisM = strtolower(date("M"));
    $thisY = date("Y");

    if (strlen($thisD) == 1) {
      $thisD = "0" . $thisD;
    } else {
      $thisD = $thisD;
    }

    $idhr = $thisM . "/" . $thisD . "/" . $thisY;
    $idbl = $thisM . $thisY;

    $_SESSION[$session.'idhr'] = $idhr;

    include_once('../include/ros_version.php');
    $idhrIso = $thisY . "-" . date("m") . "-" . $thisD;
    $getSRBl = $API->comm("/system/script/print", array(
      "?comment" => "mikhmon",
    ));
    $TotalRBl = 0;
    $TotalRHr = 0;
    $tHr = 0;
    $tBl = 0;
    foreach($getSRBl as $row){
      $parts = explode("-|-", $row['name']);
      $recDateRaw = isset($parts[0]) ? $parts[0] : '';
      $recPrice = isset($parts[3]) ? (float)$parts[3] : 0;
      $parsedRec = parseRouterOSDate($recDateRaw);
      if ($parsedRec['valid'] && $parsedRec['idbl'] === $idbl) {
        $TotalRBl++;
        $tBl += $recPrice;
        if ($parsedRec['legacy'] === $idhr || $parsedRec['iso'] === $idhrIso) {
          $TotalRHr++;
          $tHr += $recPrice;
        }
      } elseif ($recDateRaw === $idhr || $recDateRaw === $idhrIso) {
        $TotalRHr++;
        $tHr += $recPrice;
      }
    }
    $_SESSION[$session.'totalBl'] = (string)$TotalRBl;
    $_SESSION[$session.'totalHr'] = (string)$TotalRHr;
  }
}
?>

            <div id="r_4" class="row">
              <div <?= $lreport; ?> class="box bmh-75 box-bordered">
                <div class="box-group">
                  <div class="box-group-icon"><i class="fa fa-money"></i></div>
                    <div class="box-group-area">
                      <span >
                        <div id="reloadLreport">
                        <?php 
                          if ($currency == in_array($currency, $cekindo['indo'])) {
                            $dincome = number_format((float)$tHr, 0, ",", ".");
                            $mincome = number_format((float)$tBl, 0, ",", ".");
                            $_SESSION[$session.'dincome'] = $dincome;
                            $_SESSION[$session.'mincome'] = $mincome;
                          }else{
                            $dincome = number_format((float)$tHr, 2);
                            $mincome = number_format((float)$tBl, 2);
                            $_SESSION[$session.'dincome'] = $dincome;
                            $_SESSION[$session.'mincome'] = $mincome;
                          }
                            echo $_income."<br/>" . "
                          ".$_today." " . $TotalRHr . "vcr : " . $currency . " " . $dincome . "<br/>
                          ".$_this_month." " . $TotalRBl . "vcr : " . $currency . " " . $mincome;
                          ?>
                        </div>
                    </span>
                </div>
              </div>
            </div>
            </div>
            
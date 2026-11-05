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

// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
	echo '
<html>
<head><title>403 Forbidden</title></head>
<body bgcolor="white">
<center><h1>403 Forbidden</h1></center>
<hr><center>nginx/1.14.0</center>
</body>
</html>
';
} else {
	include_once('./include/ros_version.php');
	include_once('./include/reseller_acl.php');
	$routerOsMajor = detectRouterOSMajorVersion($API);
	$routerClockRow = array();
	if ($routerOsMajor === 7) {
		$routerClock = $API->comm("/system/clock/print");
		$routerClockRow = (is_array($routerClock) && isset($routerClock[0]) && is_array($routerClock[0])) ? $routerClock[0] : array();
	}

	if ((isset($_GET['migrate-ros7']) && $_GET['migrate-ros7'] == '1') || (isset($_GET['sync_ros7']) && $_GET['sync_ros7'] == '1')) {
		requireResellerPermission('edit_profile', './?hotspot=user-profiles&session=' . $session);
		$allProfs = $API->comm("/ip/hotspot/user/profile/print");
		$allScheds = $API->comm("/system/scheduler/print");
		$schedMap = array();
		if (is_array($allScheds)) {
			foreach ($allScheds as $sc) {
				if (isset($sc['name'])) {
					$schedMap[$sc['name']] = $sc;
				}
			}
		}
		if (is_array($allProfs)) {
			foreach ($allProfs as $pr) {
				$pname = isset($pr['name']) ? $pr['name'] : '';
				$ponlogin = isset($pr['on-login']) ? $pr['on-login'] : '';
				if ($pname === '' || $ponlogin === '' || strpos($ponlogin, ':put (",') === false) {
					continue;
				}
				$meta = explode(',', $ponlogin);
				$expmode = isset($meta[1]) ? trim($meta[1]) : '0';
				$price = isset($meta[2]) ? trim($meta[2]) : '0';
				$validity = isset($meta[3]) ? trim($meta[3]) : '';
				$sprice = isset($meta[4]) ? trim($meta[4]) : '0';
				$getlock = isset($meta[6]) ? trim($meta[6]) : 'Disable';
				if ($expmode === 'rem' || $expmode === 'ntf' || $expmode === 'remc' || $expmode === 'ntfc') {
					$uScripts = buildUniversalMikhmonScripts($pname, $expmode, $price, $validity, $sprice, $getlock, $routerOsMajor);
					$API->comm("/ip/hotspot/user/profile/set", array(
						".id" => $pr['.id'],
						"on-login" => $uScripts['onlogin'],
					));
					$randstarttime = "0" . rand(1, 5) . ":" . rand(10, 59) . ":" . rand(10, 59);
					$randinterval = "00:02:" . rand(10, 59);
					if ($routerOsMajor === 7) {
						$randstarttime = isset($routerClockRow["time"]) ? $routerClockRow["time"] : date("H:i:s");
						$randinterval = "00:02:00";
					}
					if (!isset($schedMap[$pname])) {
						$schedulerParams = array(
							"name" => $pname,
							"start-time" => $randstarttime,
							"interval" => $randinterval,
							"on-event" => $uScripts['bgservice'],
							"disabled" => "no",
							"comment" => "Monitor Profile $pname",
						);
						if ($routerOsMajor === 7 && isset($routerClockRow["date"])) $schedulerParams["start-date"] = $routerClockRow["date"];
						$API->comm("/system/scheduler/add", $schedulerParams);
					} else {
						$schedulerParams = array(
							".id" => $schedMap[$pname]['.id'],
							"on-event" => $uScripts['bgservice'],
							"disabled" => "no",
							"comment" => "Monitor Profile $pname",
						);
						if ($routerOsMajor === 7) {
							$schedulerParams["interval"] = "00:02:00";
							if (isset($routerClockRow["time"])) $schedulerParams["start-time"] = $routerClockRow["time"];
							if (isset($routerClockRow["date"])) $schedulerParams["start-date"] = $routerClockRow["date"];
						}
						$API->comm("/system/scheduler/set", $schedulerParams);
					}
				}
			}
		}
		echo "<script>window.location='./?hotspot=user-profiles&session=" . $session . "'</script>";
		exit;
	}

// get user profile
	$getprofile = $API->comm("/ip/hotspot/user/profile/print");
	$TotalReg = is_array($getprofile) ? count($getprofile) : 0;
// count user profile
	$countprofile = $TotalReg;
}
?>
<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header align-middle">
    <h3><i class=" fa fa-pie-chart"></i> User Profile 
    <?php if (hasResellerPermission('add_profile')) { ?>
    &nbsp; | &nbsp; <a href="./?user-profile=add&session=<?= $session; ?>" title="Add User"><i class="fa fa-user-plus"></i> Add</a>
    <?php } ?>
    <?php if (hasResellerPermission('edit_profile')) { ?>
    &nbsp; | &nbsp; <a href="./?hotspot=user-profiles&migrate-ros7=1&session=<?= $session; ?>" title="Sync Universal ROS6/ROS7 Expiry Scripts"><i class="fa fa-refresh"></i> Sync ROS6/ROS7 Expiry</a>
    <?php } ?>
	</h3>
</div>
<!-- /.card-header -->
<div class="card-body">
<div class="overflow box-bordered" style="max-height: 75vh"> 			   
<table id="tFilter" class="table table-bordered table-hover text-nowrap">
  <thead>
  <tr> 
		<th style="min-width:50px;" class="text-center" >
		<?php
	if ($countprofile < 2) {
		echo "$countprofile item  ";
	} elseif ($countprofile > 1) {
		echo "$countprofile items   ";
	}
	?></th>
		<th class="align-middle"><?= $_name ?></th>
		<th class="align-middle">Shared<br>Users</th>
		<th class="align-middle">Rate<br>Limit</th>
		<th class="align-middle"><?= $_expired_mode ?></th>
		<th class="align-middle"><?= $_validity ?></th>
		<th class="text-right align-middle" > <?= $_price." ".$currency; ?></th>
		<th class="text-right align-middle" > <?= $_selling_price." ".$currency; ?></th>
		<th class="align-middle"><?= $_lock_user ?></th>
    </tr>
  </thead>
  <tbody>
<?php

for ($i = 0; $i < $TotalReg; $i++) {

	$profiledetalis = $getprofile[$i];
	$pid = $profiledetalis['.id'];
	$pname = $profiledetalis['name'];
	$psharedu = $profiledetalis['shared-users'];
	$pratelimit = $profiledetalis['rate-limit'];
	$ponlogin = $profiledetalis['on-login'];
	$getmonexpired = $API->comm("/system/scheduler/print", array(
    "?name" => "$pname",
  ));
  $monexpired = $getmonexpired[0];
  $monid = $monexpired['.id'];
	$pmon = $monexpired['name'];
	$chkpmon = $monexpired['disabled'];
	if(empty($pmon) || $chkpmon == "true"){$moncolor = "text-orange";}else{$moncolor = "text-green";}
	echo "<tr>";
	?>
  <td style='text-align:center;'>
  <?php if (hasResellerPermission('delete_profile')) { ?>
  <i class='fa fa-minus-square text-danger pointer' onclick="if(confirm('Are you sure to delete profile (<?= $pname; ?>)?')){loadpage('./?remove-user-profile=<?= $pid; ?>&pname=<?= $pname ?>&session=<?= $session; ?>')}else{}" title='Remove <?= $pname; ?>'></i>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
  <?php } ?>
  <?php
	if (hasResellerPermission('view_users')) {
		echo "<a title='Open User by profile " . $pname . "'  href='./?hotspot=users&profile=" . $pname . "&session=" . $session . "'><i class='fa fa-users'></i></a>";
	}
	echo "</td>";
	if (hasResellerPermission('edit_profile')) {
		echo "<td><a title='Open User Profile " . $pname . "' href='./?user-profile=" . $pid . "&session=" . $session . "'><i class='fa fa-edit'></i> <i class='fa fa-ci fa-circle ".$moncolor."'></i> $pname</a></td>";
	} else {
		echo "<td><i class='fa fa-ci fa-circle ".$moncolor."'></i> $pname</td>";
	}
//$profiledetalis = $ARRAY[$i];echo "<td>" . $profiledetalis['name'];echo "</td>";
	echo "<td>" . $psharedu;
	echo "</td>";
	echo "<td>" . $pratelimit;
	echo "</td>";

	echo "<td>";
	$getexpmode = explode(",", $ponlogin);
// get expired mode
	$expmode = $getexpmode[1];
	if ($expmode == "rem") {
		echo "Remove";
	} elseif ($expmode == "ntf") {
		echo "Notice";
	} elseif ($expmode == "remc") {
		echo "Remove & Record";
	} elseif ($expmode == "ntfc") {
		echo "Notice & Record";
	} else {

	}
	echo "</td>";
	echo "<td>";
// get validity
	$getvalid = explode(",", $ponlogin);
	echo $getvalid[3];

	echo "</td>";

	echo "<td style='text-align:right;'>";
// get price
	$getprice = explode(",", $ponlogin);
	$price = trim($getprice[2]);
	if ($price == "" || $price == "0") {
		echo "";
	} else {
		if ($currency == in_array($currency, $cekindo['indo'])) {
			echo number_format((float)$price, 0, ",", ".");
		} else {
			echo number_format((float)$price, 2);
		}
	}

	echo "</td>";
	echo "<td style='text-align:right;'>";
// get price
	$getsprice = explode(",", $ponlogin);
	$price = trim($getsprice[4]);
	if ($price == "" || $price == "0") {
		echo "";
	} else {
		if ($currency == in_array($currency, $cekindo['indo'])) {
			echo number_format((float)$price, 0, ",", ".");
		} else {
			echo number_format((float)$price, 2);
		}
	}

	echo "</td>";
	echo "<td>";

	$getgracep = explode(",", $ponlogin);
	echo $getgracep[6];
	echo "</td>";
	echo "</tr>";
}
?>
  </tbody>
</table>
</div>
</div>
</div>
</div>
</div>

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

if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -15) == "ros_version.php") {
    header("Location:./");
    exit;
}

/**
 * Detect RouterOS major version (6 or 7) from /system/resource/print
 */
function detectRouterOSMajorVersion($API) {
    $res = $API->comm("/system/resource/print");
    $verStr = isset($res[0]['version']) ? trim($res[0]['version']) : '6.49.10';
    if (strpos($verStr, '7.') === 0 || strpos($verStr, '7') === 0) {
        return 7;
    }
    return 6;
}

/**
 * Normalize any RouterOS date string (v6 "oct/23/2026" or v7 "2026-10-23")
 */
function parseRouterOSDate($dateStr) {
    $dateStr = strtolower(trim((string)$dateStr));
    $months = array(
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
        'may' => '05', 'jun' => '06', 'jul' => '07', 'aug' => '08',
        'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
    );
    $revMonths = array_flip($months);

    // Format 1: ROS 7 ISO YYYY-MM-DD (or YYYY/MM/DD)
    if (preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})/', $dateStr, $m)) {
        $y = $m[1];
        $mo = $m[2];
        $d = $m[3];
        if (isset($revMonths[$mo])) {
            $mName = $revMonths[$mo];
            return array(
                'iso' => "$y-$mo-$d",
                'legacy' => "$mName/$d/$y",
                'idbl' => "$mName$y",
                'iso_month' => "$y-$mo",
                'int' => (int)("$y$mo$d"),
                'year' => (int)$y,
                'month' => (int)$mo,
                'day' => (int)$d,
                'valid' => checkdate((int)$mo, (int)$d, (int)$y)
            );
        }
    }

    // Format 2: ROS 6 legacy mmm/dd/yyyy
    if (preg_match('/^([a-z]{3})\/(\d{2})\/(\d{4})/', $dateStr, $m)) {
        $mName = $m[1];
        $d = $m[2];
        $y = $m[3];
        if (isset($months[$mName])) {
            $mo = $months[$mName];
            return array(
                'iso' => "$y-$mo-$d",
                'legacy' => "$mName/$d/$y",
                'idbl' => "$mName$y",
                'iso_month' => "$y-$mo",
                'int' => (int)("$y$mo$d"),
                'year' => (int)$y,
                'month' => (int)$mo,
                'day' => (int)$d,
                'valid' => checkdate((int)$mo, (int)$d, (int)$y)
            );
        }
    }

    return array(
        'iso' => '',
        'legacy' => '',
        'idbl' => '',
        'iso_month' => '',
        'int' => 0,
        'valid' => false
    );
}

/**
 * Check if a Hotspot user comment starts with an activated expiry timestamp
 * Supports both ROS 6 ("oct/23/2026 14:30:00...") and ROS 7 ("2026-10-23 14:30:00...")
 */
function parseExpiryFromComment($comment) {
    $comment = trim((string)$comment);
    // Match ROS 7: YYYY-MM-DD HH:MM:SS [optional note]
    if (preg_match('/^(\d{4}-\d{1,2}-\d{1,2})\s+(\d{1,2}:\d{2}(?::\d{2})?)(?:\s+(.*))?$/', $comment, $m)) {
        $d = parseRouterOSDate($m[1]);
        if ($d['valid']) {
            $t = (strlen($m[2]) === 5) ? ($m[2] . ':00') : $m[2];
            return array(
                'is_expiry' => true,
                'format' => 'ros7',
                'date_str' => $m[1],
                'time_str' => $t,
                'datetime' => $m[1] . ' ' . $t,
                'iso_datetime' => $d['iso'] . ' ' . $t,
                'legacy_datetime' => $d['legacy'] . ' ' . $t,
                'note' => isset($m[3]) ? $m[3] : '',
                'date_int' => $d['int']
            );
        }
    }
    // Match ROS 6: mmm/dd/yyyy HH:MM:SS [optional note]
    if (preg_match('/^([a-zA-Z]{3}\/\d{1,2}\/\d{2,4})\s+(\d{1,2}:\d{2}(?::\d{2})?)(?:\s+(.*))?$/', $comment, $m)) {
        $d = parseRouterOSDate($m[1]);
        if ($d['valid']) {
            $t = (strlen($m[2]) === 5) ? ($m[2] . ':00') : $m[2];
            return array(
                'is_expiry' => true,
                'format' => 'ros6',
                'date_str' => strtolower($m[1]),
                'time_str' => $t,
                'datetime' => strtolower($m[1]) . ' ' . $t,
                'iso_datetime' => $d['iso'] . ' ' . $t,
                'legacy_datetime' => $d['legacy'] . ' ' . $t,
                'note' => isset($m[3]) ? $m[3] : '',
                'date_int' => $d['int']
            );
        }
    }
    // Match numeric mm/dd/yyyy or dd/mm/yyyy HH:MM:SS
    if (preg_match('/^(\d{1,2}\/\d{1,2}\/\d{4})\s+(\d{1,2}:\d{2}(?::\d{2})?)(?:\s+(.*))?$/', $comment, $m)) {
        $t = (strlen($m[2]) === 5) ? ($m[2] . ':00') : $m[2];
        return array(
            'is_expiry' => true,
            'format' => 'numeric',
            'date_str' => $m[1],
            'time_str' => $t,
            'datetime' => $m[1] . ' ' . $t,
            'iso_datetime' => $m[1] . ' ' . $t,
            'legacy_datetime' => $m[1] . ' ' . $t,
            'note' => isset($m[3]) ? $m[3] : '',
            'date_int' => 20260101
        );
    }
    return array(
        'is_expiry' => false,
        'format' => 'none',
        'datetime' => '',
        'note' => $comment
    );
}

/**
 * Build universal dual-stack (RouterOS 6.x + RouterOS 7.x) on-login and scheduler scripts.
 * 1. Stamped comment matches ROS6 format (mmm/dd/yyyy HH:MM:SS) across both ROS6 and ROS7.
 * 2. Unlocks/locks client device MAC address on initial activation.
 * 3. Schedules and performs 100% reliable background user expiration check.
 */
function buildUniversalMikhmonScripts($name, $expmode, $price, $validity, $sprice, $getlock, $routerOsMajor = 6) {
    if ($getlock == "Enable") {
        $lock = '; :local mac $"mac-address"; :if ([:len $mac] > 0) do={ /ip hotspot user set mac-address=$mac [find where name="$user"] }';
    } else {
        $lock = "";
    }

    // Universal sales record script snippet: adds script into /system script with comment="mikhmon"
    $record = '; :local time [/system clock get time]; :if ([:len [/system script find where comment="mikhmon" and name~("\\-\\|\\-" . $user . "\\-\\|\\-")]] = 0) do={ /system script add name=("$curdate6-|-$time-|-$user-|-' . $price . '-|-$address-|-$mac-|-' . $validity . '-|-' . $name . '-|-$comment") owner=("$curmonth$curyear") source="$curdate6" comment="mikhmon" }';

    // ROS7 follows the user's supplied MikroTik calendar arithmetic on-login script.
    // The configured profile validity is injected dynamically (e.g. 3h, 1d, 7d, 30d).
    // ROS6 keeps its original temporary-scheduler next-run implementation below.
    if ((int)$routerOsMajor === 7) {
        $ros7LockMac = ($getlock == "Enable") ? "YES" : "NO";
        $onloginBase = ':put (",' . $expmode . ',' . $price . ',' . $validity . ',' . $sprice . ',,' . $getlock . ',"); {' . "\n" . str_replace(
            array('__VALIDITY__', '__LOCK__'),
            array(addslashes((string)$validity), $ros7LockMac),
            <<<'ROS7'
:local comment [/ip hotspot user get [/ip hotspot user find where name=$user] comment];
:local cdate [/system clock get date];
:local ctime [/system clock get time];
:local validity "__VALIDITY__";
:local lockmac "__LOCK__";
:local addsales "__ADDSALES__";
:local price "__PRICE__";
:local tlimit "__TLIMIT__";
:local hsProfile [/ip hotspot user get [/ip hotspot user find where name=$user] profile];
:local mac $"mac-address";
:local originalComment $comment;
:local alreadyActivated ([:pick $comment 3 4] = "/" and [:pick $comment 6 7] = "/");
:if ($alreadyActivated) do={} else={
    :local mdays {31;28;31;30;31;30;31;31;30;31;30;31};
    :local monthr {"jan";"feb";"mar";"apr";"may";"jun";"jul";"aug";"sep";"oct";"nov";"dec"};
    :local tday [:tonum [:pick $cdate 8 10]];
    :local tyear [:tonum [:pick $cdate 0 4]];
    :local month [:pick $cdate 5 7];
    :local yy $tyear;
    :local mm [:tonum $month];
    :local dd $tday;
    :local addtime ($ctime + $validity);
    :local days ($addtime / 24h);
    :local ndays ($days * 24h);
    :local exptime ($addtime - $ndays);
    :set dd ($dd + $days);
    :local dm [:pick $mdays ($mm - 1)];
    :if (($mm = 2) && ((($yy % 4) = 0) && ((($yy % 100) != 0) || (($yy % 400) = 0)))) do={ :set dm 29; };
    :while ($dd > $dm) do={
        :set dd ($dd - $dm);
        :set mm ($mm + 1);
        :if ($mm > 12) do={ :set mm 1; :set yy ($yy + 1); };
        :set dm [:pick $mdays ($mm - 1)];
        :if (($mm = 2) && ((($yy % 4) = 0) && ((($yy % 100) != 0) || (($yy % 400) = 0)))) do={ :set dm 29; };
    };
    :local vdate ([:pick $monthr ($mm - 1)] . "/");
    :if ($dd < 10) do={ :set vdate ($vdate . "0"); };
    :set vdate ($vdate . $dd . "/" . $yy);
    :local expTime [:tostr $exptime];
    :if ([:pick $expTime 1 2] = ":") do={ :set expTime ("0" . $expTime); };
    :local expComment ($vdate . " " . $expTime);
    /ip hotspot user set comment=$expComment [find where name=$user];
    :if (($lockmac = "YES") && ([:len $mac] > 0)) do={
        /ip hotspot user set mac-address=$mac [find where name=$user];
    };
    :local curmonth [:pick $monthr ($mm - 1)];
    :local curyear [:tostr $yy];
    :local curdate6 ($curmonth . "/" . $dd . "/" . $curyear);
    :local Tmonth [:pick $monthr ([:tonum $month] - 1)];
    :local zero "";
    :if ($tday < 10) do={ :set zero "0"; };
    :local salesDate ($Tmonth . "/" . $zero . $tday . "/" . $tyear);
    :if ($addsales = "YES") do={
        :if ([:len [/system script find where comment="mikhmon" and name~("\\-\\|\\-" . $user . "\\-\\|\\-")]] = 0) do={
            /system script add name=("$salesDate-|-$ctime-|-$user-|-$price-|-$address-|-$mac-|-$tlimit-|-$hsProfile-|-$originalComment") owner=("$Tmonth$tyear") source=$salesDate comment="mikhmon";
        };
    };
};
ROS7
        );
        $onloginBase = str_replace(
            array('"__ADDSALES__"', '"__PRICE__"', '"__TLIMIT__"'),
            array(
                '"' . (($expmode == "remc" || $expmode == "ntfc") ? "YES" : "NO") . '"',
                '"' . addslashes((string)$price) . '"',
                '"' . addslashes((string)$validity) . '"'
            ),
            $onloginBase
        );
        // MAC locking and sales recording are performed inside the first-login block.
        $lock = "";
        $record = "";
    } else {
        // Existing ROS6 implementation: intentionally preserved.
        $onloginBase = ':put (",' . $expmode . ',' . $price . ',' . $validity . ',' . $sprice . ',,' . $getlock . ',"); {:local comment [/ip hotspot user get [/ip hotspot user find where name="$user"] comment]; :local ucode [:pick $comment 0 2]; :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={ :local cdate [/system clock get date]; :local ctime [/system clock get time]; :local marray ("jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec"); :local curyear ""; :local curmonth ""; :local curday ""; :if ([:pick $cdate 4 5] = "-") do={ :set curyear [:pick $cdate 0 4]; :local mnum [:tonum [:pick $cdate 5 7]]; :set curmonth [:pick $marray ($mnum - 1)]; :set curday [:pick $cdate 8 10]; } else={ :set curyear [:pick $cdate 7 11]; :set curmonth [:pick $cdate 0 3]; :set curday [:pick $cdate 4 6]; }; :local curdate6 ($curmonth . "/" . $curday . "/" . $curyear); :local expFinal ""; /system scheduler remove [find where name="$user"]; /system scheduler add name="$user" disabled=no start-date=$cdate start-time=$ctime interval="' . $validity . '" on-event=""; :delay 2s; :local exp [/system scheduler get [/system scheduler find where name="$user"] next-run]; /system scheduler remove [find where name="$user"]; :local getxp [:len $exp]; :if ($getxp > 0) do={ :if ([:pick $exp 4 5] = "-") do={ :local ey [:pick $exp 0 4]; :local emn [:tonum [:pick $exp 5 7]]; :local em [:pick $marray ($emn - 1)]; :local ed [:pick $exp 8 10]; :local et [:pick $exp 11 19]; :set expFinal ($em . "/" . $ed . "/" . $ey . " " . $et); } else={ :if ([:pick $exp 6 7] = "/") do={ :set expFinal $exp; } else={ :if ([:pick $exp 5 6] = "/") do={ :set expFinal $exp; } else={ :if ([:pick $exp 3 4] = "/") do={ :local space [:find $exp " "]; :if ([:typeof $space] != "nil") do={ :local d [:pick $exp 0 $space]; :local t [:pick $exp ($space + 1) [:len $exp]]; :set expFinal ($d . "/" . $curyear . " " . $t); }; } else={ :if ($getxp = 8) do={ :set expFinal ($curdate6 . " " . $exp); } else={ :set expFinal $exp; }; }; }; }; }; }; :if ([:len $expFinal] = 0) do={ :set expFinal ($curdate6 . " " . $ctime); }; /ip hotspot user set comment="$expFinal" [find where name="$user"]';
    }
    $mode = "";
    // ROS7's supplied first-login IF/ELSE block is already closed inside
    // $onloginBase; only the outer wrapper remains open. ROS6 retains its
    // legacy two-brace closing sequence.
    $onloginClosing = ((int)$routerOsMajor === 7) ? "}" : "}}";
    if ($expmode == "rem") {
        $onlogin = $onloginBase . $lock . $onloginClosing;
        $mode = "remove";
    } elseif ($expmode == "ntf") {
        $onlogin = $onloginBase . $lock . $onloginClosing;
        $mode = "set limit-uptime=1s";
    } elseif ($expmode == "remc") {
        $onlogin = $onloginBase . $lock . $record . $onloginClosing;
        $mode = "remove";
    } elseif ($expmode == "ntfc") {
        $onlogin = $onloginBase . $lock . $record . $onloginClosing;
        $mode = "set limit-uptime=1s";
    } elseif ($expmode == "0" && $price != "") {
        $onlogin = ':put (",,' . $price . ',,,noexp,' . $getlock . ',")' . $lock;
    } else {
        $onlogin = "";
    }

    // Universal background scheduler monitor script:
    // Periodically checks all users in profile $name, compares comment expiration date/time
    // with current router clock on both ROS6 and ROS7, and terminates expired vouchers.
    $guardNotAlreadyExpired = ($mode == "set limit-uptime=1s") ? ' and $limit != "1s" and $limit != "00:00:01"' : '';
    $bgservice = ':local dateint do={ :local montharray ("jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec"); :if ([:pick $d 4 5] = "-") do={ :local yr [:pick $d 0 4]; :local mo [:pick $d 5 7]; :local dy [:pick $d 8 10]; :return [:tonum ("$yr$mo$dy")]; } else={ :local days [:pick $d 4 6]; :local month [:pick $d 0 3]; :local year [:pick $d 7 11]; :local monthint ([:find $montharray $month]); :if ([:len $monthint] > 0) do={ :local mnum ($monthint + 1); :if ($mnum < 10) do={ :return [:tonum ("$year" . "0" . "$mnum$days")]; } else={ :return [:tonum ("$year$mnum$days")]; }; } else={ :return 0; }; }; }; :local timeint do={ :local hours [:tonum [:pick $t 0 2]]; :local minutes [:tonum [:pick $t 3 5]]; :local seconds [:tonum [:pick $t 6 8]]; :return ($hours * 3600 + $minutes * 60 + $seconds); }; :local date [/system clock get date]; :local time [/system clock get time]; :local today [$dateint d=$date]; :local curtime [$timeint t=$time]; :if ($today > 20200000) do={ :foreach i in=[/ip hotspot user find where profile="' . $name . '"] do={ :local comment [/ip hotspot user get $i comment]; :local uname [/ip hotspot user get $i name]; :local limit [/ip hotspot user get $i limit-uptime]; :local expd 0; :local expt 0; :if ([:pick $comment 4 5] = "-") do={ :set expd [$dateint d=[:pick $comment 0 10]]; :set expt [$timeint t=[:pick $comment 11 19]]; } else={ :if ([:pick $comment 3 4] = "/" and [:pick $comment 6 7] = "/") do={ :set expd [$dateint d=[:pick $comment 0 11]]; :set expt [$timeint t=[:pick $comment 12 20]]; }; }; :if ($expd > 20200000) do={ :if (($expd < $today) or ($expd = $today and $expt <= $curtime)' . $guardNotAlreadyExpired . ') do={ [ /ip hotspot user ' . $mode . ' $i ]; [ /ip hotspot active remove [find where user=$uname] ]; [ /ip hotspot cookie remove [find where user=$uname] ]; }; }; }; }';

    return array(
        'onlogin' => $onlogin,
        'on_login' => $onlogin,
        'bgservice' => $bgservice,
        'mode' => $mode
    );
}

/**
 * Query Mikhmon sales/userlog scripts with cross-version ROS6 (mmm/dd/yyyy) and ROS7 (YYYY-MM-DD) support.
 * Also normalizes the date segment in $row['name'] to mmm/dd/yyyy so Resume Report works identically.
 */
function queryMikhmonReportScripts($API, $idhr = '', $idbl = '') {
    $allScripts = $API->comm("/system/script/print", array(
        "?comment" => "mikhmon",
    ));
    if (!is_array($allScripts)) {
        return array();
    }
    $idhr = strtolower(trim((string)$idhr));
    $idbl = strtolower(trim((string)$idbl));
    $targetHr = $idhr !== '' ? parseRouterOSDate($idhr) : null;

    $results = array();
    foreach ($allScripts as $row) {
        $parts = explode("-|-", isset($row['name']) ? $row['name'] : '');
        $rawDate = isset($parts[0]) ? trim($parts[0]) : '';
        $parsed = parseRouterOSDate($rawDate);

        if ($parsed['valid']) {
            $parts[0] = $parsed['legacy'];
            $row['name'] = implode("-|-", $parts);
        }

        if ($idhr !== '') {
            $matchHr = false;
            if (strtolower($rawDate) === $idhr || (isset($row['source']) && strtolower($row['source']) === $idhr)) {
                $matchHr = true;
            } elseif ($parsed['valid'] && ($parsed['legacy'] === $idhr || ($targetHr && $targetHr['valid'] && $parsed['iso'] === $targetHr['iso']))) {
                $matchHr = true;
            }
            if ($matchHr) {
                $results[] = $row;
            }
        } elseif ($idbl !== '') {
            $matchBl = false;
            if (isset($row['owner']) && strtolower($row['owner']) === $idbl) {
                $matchBl = true;
            } elseif ($parsed['valid'] && $parsed['idbl'] === $idbl) {
                $matchBl = true;
            }
            if ($matchBl) {
                $results[] = $row;
            }
        } else {
            $results[] = $row;
        }
    }
    return $results;
}

/**
 * Remove Mikhmon sales report scripts matching $idhr or $idbl across ROS 6 and ROS 7 formats.
 */
function removeMikhmonReportScripts($API, $idhr = '', $idbl = '') {
    $matching = queryMikhmonReportScripts($API, $idhr, $idbl);
    foreach ($matching as $row) {
        if (!empty($row['.id'])) {
            $API->comm("/system/script/remove", array(
                ".id" => $row['.id'],
            ));
        }
    }
    return count($matching);
}

/**
 * Verify if a Hotspot user comment represents an activated ("Used") voucher.
 * Follows the activation/expiry date stamped by RouterOS v6 & v7 on-login scripts:
 * Matches formats like 'mmm/dd/yyyy HH:MM:SS' (ROS6/ROS7), 'YYYY-MM-DD HH:MM:SS', etc.
 * Excludes unactivated vouchers (empty, 'vc-...', 'up-...').
 */
function isUsedActivatedVoucherComment($comment) {
    $comment = trim((string)$comment);
    if ($comment === '') {
        return false;
    }
    $prefix2 = strtolower(substr($comment, 0, 2));
    if ($prefix2 === 'vc' || $prefix2 === 'up') {
        return false;
    }
    $parsed = parseExpiryFromComment($comment);
    if (empty($parsed['is_expiry']) || empty($parsed['time_str'])) {
        return false;
    }
    $tParts = explode(':', $parsed['time_str']);
    if (count($tParts) < 2 || count($tParts) > 3) {
        return false;
    }
    $hh = (int)$tParts[0];
    $mm = (int)$tParts[1];
    $ss = isset($tParts[2]) ? (int)$tParts[2] : 0;
    if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59 || $ss < 0 || $ss > 59) {
        return false;
    }
    return true;
}

/**
 * Read-only calculation of Used vouchers from RouterOS /ip/hotspot/user/print array.
 * Never modifies router data, deduplicates by username, and handles invalid/empty arrays safely.
 */
function countUsedHotspotVouchers($users) {
    if (!is_array($users)) {
        return 0;
    }
    $seenNames = array();
    $usedCount = 0;
    foreach ($users as $u) {
        if (!is_array($u)) {
            continue;
        }
        $uname = isset($u['name']) ? trim((string)$u['name']) : '';
        if ($uname === '' || $uname === 'default-trial') {
            continue;
        }
        if (isset($u['disabled']) && ($u['disabled'] === 'true' || $u['disabled'] === true)) {
            continue;
        }
        if (isset($seenNames[$uname])) {
            continue;
        }
        $seenNames[$uname] = true;
        $ucomment = isset($u['comment']) ? (string)$u['comment'] : '';
        if (isUsedActivatedVoucherComment($ucomment)) {
            $usedCount++;
        }
    }
    return $usedCount;
}

/**
 * Convert RouterOS validity string (e.g. "1d", "7d", "30d", "12h", "2w1d", "02:30:00") into seconds.
 */
function parseRouterOSValiditySeconds($validityStr) {
    $v = strtolower(trim((string)$validityStr));
    if ($v === '' || $v === '0' || $v === 'none') {
        return 0;
    }
    $total = 0;
    if (preg_match('/(\d+)\s*w/', $v, $m)) {
        $total += ((int)$m[1]) * 7 * 86400;
    }
    if (preg_match('/(\d+)\s*d/', $v, $m)) {
        $total += ((int)$m[1]) * 86400;
    }
    if (preg_match('/(\d+)\s*h/', $v, $m)) {
        $total += ((int)$m[1]) * 3600;
    }
    if (preg_match('/(\d+)\s*m(?!s)/', $v, $m)) {
        $total += ((int)$m[1]) * 60;
    }
    if (preg_match('/(\d+)\s*s/', $v, $m)) {
        $total += (int)$m[1];
    }
    if (preg_match('/(\d{1,2}):(\d{2}):(\d{2})/', $v, $m)) {
        $total += ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + ((int)$m[3]);
    }
    if ($total === 0 && is_numeric($v)) {
        $total = ((int)$v) * 86400;
    }
    return $total;
}

/**
 * Calculate calendar expiry date & time from a router script record's login date, time, validity, and comment.
 */
function calculateExpiryFromRecordParts($loginDateStr, $loginTimeStr, $validityStr, $recordComment = '') {
    $parsedComment = parseExpiryFromComment($recordComment);
    if (!empty($parsedComment['is_expiry'])) {
        return array(
            'has_expiry' => true,
            'iso_datetime' => $parsedComment['iso_datetime'],
            'legacy_datetime' => $parsedComment['legacy_datetime'],
            'display_datetime' => $parsedComment['iso_datetime'] . ' (' . $parsedComment['legacy_datetime'] . ')',
            'source' => 'comment'
        );
    }

    $dInfo = parseRouterOSDate($loginDateStr);
    $timeClean = trim((string)$loginTimeStr);
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $timeClean)) {
        $timeClean = '00:00:00';
    }

    if ($dInfo['valid']) {
        $loginTs = strtotime($dInfo['iso'] . ' ' . $timeClean);
        $valSec = parseRouterOSValiditySeconds($validityStr);
        if ($loginTs !== false && $valSec > 0) {
            $expTs = $loginTs + $valSec;
            $isoExp = date('Y-m-d H:i:s', $expTs);
            $legExp = strtolower(date('M/d/Y', $expTs)) . ' ' . date('H:i:s', $expTs);
            return array(
                'has_expiry' => true,
                'iso_datetime' => $isoExp,
                'legacy_datetime' => $legExp,
                'display_datetime' => $isoExp . ' (' . $legExp . ')',
                'source' => 'calculated'
            );
        }
    }

    return array(
        'has_expiry' => false,
        'iso_datetime' => '',
        'legacy_datetime' => '',
        'display_datetime' => $recordComment !== '' ? $recordComment : 'N/A',
        'source' => 'none'
    );
}

/**
 * Search Mikhmon /system/script records (comment="mikhmon") for a specific voucher code, MAC address, or IP,
 * even after the voucher has expired and been removed from /ip/hotspot/user.
 */
function findVoucherRecordsInScripts($API, $searchQuery) {
    $q = strtolower(trim((string)$searchQuery));
    if ($q === '') {
        return array();
    }
    $qMacNorm = str_replace('-', ':', $q);
    $allScripts = queryMikhmonReportScripts($API);
    $matches = array();
    foreach ($allScripts as $row) {
        $rawName = isset($row['name']) ? (string)$row['name'] : '';
        $parts = explode("-|-", $rawName);
        if (count($parts) >= 3) {
            $loginDate = isset($parts[0]) ? trim($parts[0]) : '';
            $loginTime = isset($parts[1]) ? trim($parts[1]) : '';
            $recUser   = isset($parts[2]) ? trim($parts[2]) : '';
            $price     = isset($parts[3]) ? trim($parts[3]) : '';
            $ip        = isset($parts[4]) ? trim($parts[4]) : '';
            $mac       = isset($parts[5]) ? trim($parts[5]) : '';
            $validity  = isset($parts[6]) ? trim($parts[6]) : '';
            $profile   = isset($parts[7]) ? trim($parts[7]) : '';
            $comment   = isset($parts[8]) ? trim($parts[8]) : '';

            $macNorm = str_replace('-', ':', strtolower($mac));
            if (strtolower($recUser) === $q || ($macNorm !== '' && $macNorm === $qMacNorm) || ($ip !== '' && strtolower($ip) === $q)) {
                $parsedDate = parseRouterOSDate($loginDate);
                $loginIso = ($parsedDate['valid'] ? $parsedDate['iso'] : $loginDate) . ($loginTime !== '' ? ' ' . $loginTime : '');
                $expCalc = calculateExpiryFromRecordParts($loginDate, $loginTime, $validity, $comment);

                $matches[] = array(
                    'id'               => isset($row['.id']) ? $row['.id'] : '',
                    'voucher'          => $recUser,
                    'login_date'       => $loginDate,
                    'login_time'       => $loginTime,
                    'login_datetime'   => $loginIso,
                    'price'            => $price,
                    'ip'               => $ip,
                    'mac'              => $mac,
                    'validity'         => $validity,
                    'profile'          => $profile,
                    'comment'          => $comment,
                    'has_expiry'       => $expCalc['has_expiry'],
                    'expiry_iso'       => $expCalc['iso_datetime'],
                    'expiry_legacy'    => $expCalc['legacy_datetime'],
                    'expiry_display'   => $expCalc['display_datetime'],
                );
            }
        }
    }
    return $matches;
}

/**
 * Detect if a MAC address is a Locally Administered (Randomized / Private) address.
 * In IEEE 802, bit 1 of byte 0 is 1 for locally administered addresses.
 * In hexadecimal, the second digit of the first octet will be 2, 6, A, or E.
 */
function isRandomizedMacAddress($mac) {
    $mac = trim((string)$mac);
    if (strlen($mac) < 2) return false;
    $firstByte = substr($mac, 0, 2);
    return (bool)preg_match('/^[0-9a-fA-F][26aAeE]$/', $firstByte);
}

function renderRandomizedMacBadge($mac) {
    if (!isRandomizedMacAddress($mac)) {
        return '';
    }
    return ' <span class="text-warning" style="font-size:10px; font-weight:bold; padding:1px 5px; border-radius:3px; background:rgba(255,193,7,0.18); border:1px solid rgba(255,193,7,0.4); vertical-align:middle; display:inline-block;" title="Locally Administered / Private Randomized MAC"><i class="fa fa-shield"></i> Random MAC</span>';
}
?>


<?php
/*
 *  NextGan WiFi Solution — Dedicated Reset Voucher Module (Two-Step Verification & Multi-Field Search)
 *  Fully localized via lang/en.php (default English) and lang/bn.php (Bengali)
 */
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include_once('./include/config.php');
include_once('./include/ros_version.php');
include_once('./include/reseller_acl.php');
include_once('./lib/routeros_api.class.php');
include_once('./lib/formatbytesbites.php');

requireResellerPermission('reset_voucher', './admin.php?id=sessions');

/**
 * Multi-field voucher lookup on RouterOS:
 * Matches by Username (name), Password (password), MAC Address (mac-address), or Active IP/MAC.
 */
function findHotspotVoucherByAnyField($API, $query) {
    $q = trim((string)$query);
    if ($q === '') {
        return array('user' => null, 'active' => null, 'matched_by' => '');
    }
    $qLower = strtolower($q);
    $qMacNorm = str_replace('-', ':', $qLower);

    // 1. Exact match by name (Fast path: <0.1s on real router)
    $byName = $API->comm("/ip/hotspot/user/print", array("?name" => $q));
    if (is_array($byName) && !empty($byName) && isset($byName[0]['.id'])) {
        $u = $byName[0];
        $act = $API->comm("/ip/hotspot/active/print", array("?user" => $u['name']));
        $activeRow = (is_array($act) && !empty($act) && isset($act[0]['.id'])) ? $act[0] : null;
        return array('user' => $u, 'active' => $activeRow, 'matched_by' => 'username');
    }

    // 2. Direct MAC query if query matches MAC address pattern
    if (strpos($q, ':') !== false || strpos($q, '-') !== false) {
        $byMac = $API->comm("/ip/hotspot/user/print", array("?mac-address" => $qMacNorm));
        if (is_array($byMac) && !empty($byMac) && isset($byMac[0]['.id'])) {
            $u = $byMac[0];
            $act = $API->comm("/ip/hotspot/active/print", array("?user" => $u['name']));
            $activeRow = (is_array($act) && !empty($act) && isset($act[0]['.id'])) ? $act[0] : null;
            return array('user' => $u, 'active' => $activeRow, 'matched_by' => 'mac');
        }
    }

    // 3. Fast Active query by user name
    $actDirect = $API->comm("/ip/hotspot/active/print", array("?user" => $q));
    if (is_array($actDirect) && !empty($actDirect) && isset($actDirect[0]['.id'])) {
        $activeRow = $actDirect[0];
        $byName = $API->comm("/ip/hotspot/user/print", array("?name" => $activeRow['user']));
        if (is_array($byName) && !empty($byName) && isset($byName[0]['.id'])) {
            return array('user' => $byName[0], 'active' => $activeRow, 'matched_by' => 'active_session');
        }
    }

    // 4. Check active sessions (small list, fast)
    $allActive = $API->comm("/ip/hotspot/active/print");
    $matchedActiveRow = null;
    if (is_array($allActive)) {
        foreach ($allActive as $ar) {
            if (!is_array($ar) || !isset($ar['.id'])) continue;
            $aUser = isset($ar['user']) ? strtolower(trim($ar['user'])) : '';
            $aMac  = isset($ar['mac-address']) ? str_replace('-', ':', strtolower(trim($ar['mac-address']))) : '';
            $aIp   = isset($ar['address']) ? strtolower(trim($ar['address'])) : '';
            if ($aUser === $qLower || ($aMac !== '' && $aMac === $qMacNorm) || ($aIp !== '' && $aIp === $qLower)) {
                $matchedActiveRow = $ar;
                break;
            }
        }
    }

    // 3. Scan /ip/hotspot/user for case-insensitive name, password, or mac-address
    $allUsers = $API->comm("/ip/hotspot/user/print");
    if (is_array($allUsers)) {
        $targetUserFromActive = ($matchedActiveRow !== null && isset($matchedActiveRow['user'])) ? strtolower(trim($matchedActiveRow['user'])) : '';
        foreach ($allUsers as $u) {
            if (!is_array($u) || !isset($u['.id'])) continue;
            $uName = isset($u['name']) ? trim((string)$u['name']) : '';
            if ($uName === '' || $uName === 'default-trial') continue;

            $uNameLow = strtolower($uName);
            $uPassLow = isset($u['password']) ? strtolower(trim((string)$u['password'])) : '';
            $uMacNorm = isset($u['mac-address']) ? str_replace('-', ':', strtolower(trim((string)$u['mac-address']))) : '';

            $matchedField = '';
            if ($uNameLow === $qLower) {
                $matchedField = 'username';
            } elseif ($uPassLow !== '' && $uPassLow === $qLower) {
                $matchedField = 'password';
            } elseif ($uMacNorm !== '' && $uMacNorm !== '00:00:00:00:00:00' && $uMacNorm === $qMacNorm) {
                $matchedField = 'mac';
            } elseif ($targetUserFromActive !== '' && $uNameLow === $targetUserFromActive) {
                $matchedField = 'active_session';
            }

            if ($matchedField !== '') {
                if ($matchedActiveRow === null || strtolower(isset($matchedActiveRow['user']) ? $matchedActiveRow['user'] : '') !== $uNameLow) {
                    $act = $API->comm("/ip/hotspot/active/print", array("?user" => $uName));
                    $matchedActiveRow = (is_array($act) && !empty($act) && isset($act[0]['.id'])) ? $act[0] : null;
                }
                return array('user' => $u, 'active' => $matchedActiveRow, 'matched_by' => $matchedField);
            }
        }
    }

    return array('user' => null, 'active' => $matchedActiveRow, 'matched_by' => '');
}


/**
 * Keep matching voucher activation records from the last 30 days.
 * RouterOS 6 (mmm/dd/yyyy) and RouterOS 7 (YYYY-MM-DD) dates are normalized by parseRouterOSDate().
 */
function filterVoucherRecordsLast30Days($records) {
    if (!is_array($records)) return array();
    $cutoff = time() - (30 * 86400);
    $now = time();
    $recent = array();
    foreach ($records as $record) {
        $dateText = isset($record['login_date']) ? trim((string)$record['login_date']) : '';
        $timeText = isset($record['login_time']) ? trim((string)$record['login_time']) : '00:00:00';
        $parsed = parseRouterOSDate($dateText);
        if (empty($parsed['valid'])) continue;
        if (!preg_match('/^\\d{1,2}:\\d{2}(?::\\d{2})?$/', $timeText)) $timeText = '00:00:00';
        if (strlen($timeText) === 5) $timeText .= ':00';
        $stamp = strtotime($parsed['iso'] . ' ' . $timeText);
        if ($stamp === false || $stamp < $cutoff || $stamp > $now) continue;
        $record['_timestamp'] = $stamp;
        $recent[] = $record;
    }
    usort($recent, function($a, $b) {
        return (isset($a['_timestamp']) ? $a['_timestamp'] : 0) <=> (isset($b['_timestamp']) ? $b['_timestamp'] : 0);
    });
    return $recent;
}

// Build list of allowed servers for this user (Admin or Reseller)
$allowedServers = array();
if (isset($data) && is_array($data)) {
    foreach ($data as $sKey => $sArr) {
        if ($sKey === '' || $sKey === 'mikhmon') {
            continue;
        }
        if (canAccessRouterSession($sKey)) {
            $hsName = isset($sArr[4]) && strpos($sArr[4], '%') !== false ? explode('%', $sArr[4])[1] : $sKey;
            $ipRaw  = isset($sArr[1]) && strpos($sArr[1], '!') !== false ? explode('!', $sArr[1])[1] : '';
            $uRaw   = isset($sArr[2]) && strpos($sArr[2], '@|@') !== false ? explode('@|@', $sArr[2])[1] : '';
            $pRaw   = isset($sArr[3]) && strpos($sArr[3], '#|#') !== false ? explode('#|#', $sArr[3])[1] : '';
            $curr   = isset($sArr[6]) && strpos($sArr[6], '&') !== false ? explode('&', $sArr[6])[1] : 'Rp';
            $allowedServers[$sKey] = array(
                'session'     => $sKey,
                'hotspotname' => $hsName !== '' ? $hsName : $sKey,
                'iphost'      => $ipRaw,
                'userhost'    => $uRaw,
                'passwdhost'  => $pRaw,
                'currency'    => $curr,
            );
        }
    }
}

$selectedServer = '';
if (isset($_POST['server_session']) && isset($allowedServers[$_POST['server_session']])) {
    $selectedServer = $_POST['server_session'];
} elseif (!empty($session) && isset($allowedServers[$session])) {
    $selectedServer = $session;
} elseif (!empty($allowedServers)) {
    $keys = array_keys($allowedServers);
    $selectedServer = $keys[0];
}

$voucherCode   = isset($_POST['voucher_code']) ? trim((string)$_POST['voucher_code']) : '';
$targetVoucher = isset($_POST['target_voucher']) ? trim((string)$_POST['target_voucher']) : '';
$rvAction      = isset($_POST['rv_action']) ? trim((string)$_POST['rv_action']) : '';

$actionAlertHtml = '';
$resultCardHtml  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rvAction !== '') {
    $lookupKey = ($targetVoucher !== '' && $rvAction !== 'verify') ? $targetVoucher : $voucherCode;

    if ($selectedServer === '' || !isset($allowedServers[$selectedServer])) {
        $actionAlertHtml = "<div class='bg-danger pd-10 radius-3 mr-b-10'><i class='fa fa-ban'></i> " . htmlspecialchars($_rv_err_select_server) . "</div>";
    } elseif ($lookupKey === '') {
        $actionAlertHtml = "<div class='bg-danger pd-10 radius-3 mr-b-10'><i class='fa fa-ban'></i> " . htmlspecialchars($_rv_err_enter_code) . "</div>";
    } else {
        $srvCfg = $allowedServers[$selectedServer];
        $rvAPI = new RouterosAPI();
        $rvAPI->debug = false;

        if (!$rvAPI->connect($srvCfg['iphost'], $srvCfg['userhost'], decrypt($srvCfg['passwdhost']))) {
            $actionAlertHtml = "<div class='bg-danger pd-10 radius-3 mr-b-10'><i class='fa fa-plug'></i> " . htmlspecialchars($_rv_err_conn_failed) . " <b>" . htmlspecialchars($srvCfg['hotspotname']) . "</b></div>";
        } else {
            // Search voucher across username, password, or MAC address
            $foundInfo = findHotspotVoucherByAnyField($rvAPI, $lookupKey);
            $foundLiveUser = $foundInfo['user'];

            if ($foundLiveUser !== null) {
                $uid = $foundLiveUser['.id'];
                $uname = isset($foundLiveUser['name']) ? $foundLiveUser['name'] : $lookupKey;

                // Execute action if user clicked Reset / Enable / Disable / Check Expiry
                if ($rvAction === 'reset') {
                    // 1. Clear MAC address binding
                    $rvAPI->comm("/ip/hotspot/user/set", array(
                        ".id" => $uid,
                        "mac-address" => "00:00:00:00:00:00",
                    ));
                    $rvAPI->comm("/ip/hotspot/user/set", array(
                        ".id" => $uid,
                        "mac-address" => "",
                    ));

                    // 2. Reset uptime and data counters
                    $rvAPI->comm("/ip/hotspot/user/reset-counters", array(
                        ".id" => $uid,
                    ));

                    // 3. Remove active sessions
                    $activeRows = $rvAPI->comm("/ip/hotspot/active/print", array(
                        "?user" => $uname,
                    ));
                    if (is_array($activeRows)) {
                        foreach ($activeRows as $ar) {
                            if (!empty($ar['.id'])) {
                                $rvAPI->comm("/ip/hotspot/active/remove", array(
                                    ".id" => $ar['.id'],
                                ));
                            }
                        }
                    }

                    // 4. Remove hotspot cookies
                    $cookieRows = $rvAPI->comm("/ip/hotspot/cookie/print", array(
                        "?user" => $uname,
                    ));
                    if (is_array($cookieRows)) {
                        foreach ($cookieRows as $cr) {
                            if (!empty($cr['.id'])) {
                                $rvAPI->comm("/ip/hotspot/cookie/remove", array(
                                    ".id" => $cr['.id'],
                                ));
                            }
                        }
                    }

                    $actionAlertHtml = "<div class='bg-green pd-10 radius-3 mr-b-10'><i class='fa fa-check-circle'></i> " . htmlspecialchars($_rv_voucher_prefix) . " <b>" . htmlspecialchars($uname) . "</b> " . htmlspecialchars($_rv_msg_reset_ok) . "</div>";
                } elseif ($rvAction === 'enable') {
                    $rvAPI->comm("/ip/hotspot/user/set", array(
                        ".id" => $uid,
                        "disabled" => "no",
                    ));
                    $actionAlertHtml = "<div class='bg-green pd-10 radius-3 mr-b-10'><i class='fa fa-unlock'></i> " . htmlspecialchars($_rv_voucher_prefix) . " <b>" . htmlspecialchars($uname) . "</b> " . htmlspecialchars($_rv_msg_enable_ok) . "</div>";
                } elseif ($rvAction === 'disable') {
                    $rvAPI->comm("/ip/hotspot/user/set", array(
                        ".id" => $uid,
                        "disabled" => "yes",
                    ));
                    $activeRows = $rvAPI->comm("/ip/hotspot/active/print", array(
                        "?user" => $uname,
                    ));
                    if (is_array($activeRows)) {
                        foreach ($activeRows as $ar) {
                            if (!empty($ar['.id'])) {
                                $rvAPI->comm("/ip/hotspot/active/remove", array(
                                    ".id" => $ar['.id'],
                                ));
                            }
                        }
                    }
                    $actionAlertHtml = "<div class='bg-warning pd-10 radius-3 mr-b-10'><i class='fa fa-lock'></i> " . htmlspecialchars($_rv_voucher_prefix) . " <b>" . htmlspecialchars($uname) . "</b> " . htmlspecialchars($_rv_msg_disable_ok) . "</div>";
                } elseif ($rvAction === 'verify') {
                    $actionAlertHtml = "<div class='bg-green pd-10 radius-3 mr-b-10'><i class='fa fa-check-circle'></i> " . htmlspecialchars($_rv_voucher_prefix) . " <b>" . htmlspecialchars($uname) . "</b> " . htmlspecialchars($_rv_msg_found_ok) . "</div>";
                }

                // Re-fetch updated user & active session details
                $refreshed = $rvAPI->comm("/ip/hotspot/user/print", array(
                    "?name" => $uname,
                ));
                if (is_array($refreshed) && !empty($refreshed) && isset($refreshed[0]['.id'])) {
                    $foundLiveUser = $refreshed[0];
                }
                $liveActive = $rvAPI->comm("/ip/hotspot/active/print", array(
                    "?user" => $uname,
                ));
                $activeRow = (is_array($liveActive) && !empty($liveActive) && isset($liveActive[0]['.id'])) ? $liveActive[0] : null;
                $isOnline  = ($activeRow !== null);

                // Avoid loading the router's entire script history on every search.
                // A full history scan is only needed when the expiry comment is missing/invalid
                // or when the voucher has already expired and recent history should be displayed.
                $uComment = isset($foundLiveUser['comment']) ? trim((string)$foundLiveUser['comment']) : '';
                $preParsedExp = parseExpiryFromComment($uComment);
                $preCalendarExpired = false;
                if (!empty($preParsedExp['is_expiry']) && !empty($preParsedExp['iso_datetime'])) {
                    $preExpTs = strtotime($preParsedExp['iso_datetime']);
                    $preCalendarExpired = ($preExpTs !== false && $preExpTs <= time());
                }
                $scriptRecs = array();
                $recentScriptRecs = array();
                $latestRec = null;
                $needsHistory = empty($preParsedExp['is_expiry']) || $preCalendarExpired || $rvAction === 'check_expiry';
                if ($needsHistory) {
                    $scriptRecs = findVoucherRecordsInScripts($rvAPI, $uname);
                    $recentScriptRecs = filterVoucherRecordsLast30Days($scriptRecs);
                    $latestRec = !empty($recentScriptRecs)
                        ? $recentScriptRecs[count($recentScriptRecs) - 1]
                        : (!empty($scriptRecs) ? $scriptRecs[count($scriptRecs) - 1] : null);
                }

                $uPass     = isset($foundLiveUser['password']) ? $foundLiveUser['password'] : $uname;
                $uProfile  = isset($foundLiveUser['profile']) ? $foundLiveUser['profile'] : '-';
                $uUptime   = ($isOnline && !empty($activeRow['uptime'])) ? $activeRow['uptime'] : (isset($foundLiveUser['uptime']) ? $foundLiveUser['uptime'] : '00:00:00');
                $uTimeLeft = ($isOnline && !empty($activeRow['session-time-left'])) ? $activeRow['session-time-left'] : '';
                $uLimitUp  = isset($foundLiveUser['limit-uptime']) ? $foundLiveUser['limit-uptime'] : '-';
                $rawIn     = ($isOnline && isset($activeRow['bytes-in']) && (int)$activeRow['bytes-in'] > (int)(isset($foundLiveUser['bytes-in']) ? $foundLiveUser['bytes-in'] : 0))
                    ? (int)$activeRow['bytes-in']
                    : (isset($foundLiveUser['bytes-in']) ? (int)$foundLiveUser['bytes-in'] : 0);
                $rawOut    = ($isOnline && isset($activeRow['bytes-out']) && (int)$activeRow['bytes-out'] > (int)(isset($foundLiveUser['bytes-out']) ? $foundLiveUser['bytes-out'] : 0))
                    ? (int)$activeRow['bytes-out']
                    : (isset($foundLiveUser['bytes-out']) ? (int)$foundLiveUser['bytes-out'] : 0);
                $uBytesIn  = formatBytes($rawIn, 2);
                $uBytesOut = formatBytes($rawOut, 2);

                // Resolve IP Address (from active session or script record)
                $uIpAddress = '-';
                if ($isOnline && !empty($activeRow['address'])) {
                    $uIpAddress = $activeRow['address'] . ' (' . $_active_tag . ')';
                } elseif ($latestRec !== null && !empty($latestRec['ip'])) {
                    $uIpAddress = $latestRec['ip'] . ' (' . $_last_known_tag . ')';
                }

                // Resolve MAC Address (from user binding, active session, or script record)
                $uMac = $_not_bound;
                if (isset($foundLiveUser['mac-address']) && $foundLiveUser['mac-address'] !== '' && $foundLiveUser['mac-address'] !== '00:00:00:00:00:00') {
                    $uMac = $foundLiveUser['mac-address'];
                } elseif ($isOnline && !empty($activeRow['mac-address'])) {
                    $uMac = $activeRow['mac-address'];
                } elseif ($latestRec !== null && !empty($latestRec['mac'])) {
                    $uMac = $latestRec['mac'];
                }

                $uDisabled = (isset($foundLiveUser['disabled']) && ($foundLiveUser['disabled'] === 'true' || $foundLiveUser['disabled'] === 'yes'));

                $parsedExp = $preParsedExp;
                $isLimitExpired = ($uLimitUp === '1s' || $uLimitUp === '00:00:01');
                $effectiveExpiry = null;
                if ($parsedExp['is_expiry'] && !empty($parsedExp['iso_datetime'])) {
                    $effectiveExpiry = array(
                        'iso_datetime' => $parsedExp['iso_datetime'],
                        'legacy_datetime' => $parsedExp['legacy_datetime'],
                        'is_expiry' => true
                    );
                } elseif ($latestRec !== null && !empty($latestRec['has_expiry'])) {
                    $effectiveExpiry = array(
                        'iso_datetime' => $latestRec['expiry_iso'],
                        'legacy_datetime' => $latestRec['expiry_legacy'],
                        'is_expiry' => true
                    );
                }
                $isCalendarExpired = false;
                if ($effectiveExpiry !== null) {
                    $expTs = strtotime($effectiveExpiry['iso_datetime']);
                    if ($expTs !== false && $expTs <= time()) $isCalendarExpired = true;
                }

                if ($uDisabled) {
                    $statusBadge = "<span class='bg-danger pd-5 radius-3'><i class='fa fa-lock'></i> " . htmlspecialchars($_status_disabled) . "</span>";
                } elseif ($isLimitExpired || $isCalendarExpired) {
                    $statusBadge = "<span class='bg-danger pd-5 radius-3'><i class='fa fa-clock-o'></i> " . htmlspecialchars($_status_expired) . "</span>";
                } elseif ($isOnline) {
                    $statusBadge = "<span class='bg-green pd-5 radius-3'><i class='fa fa-wifi'></i> " . htmlspecialchars($_status_active_online) . "</span>";
                } elseif ($parsedExp['is_expiry']) {
                    $statusBadge = "<span class='bg-primary pd-5 radius-3'><i class='fa fa-check'></i> " . htmlspecialchars($_status_enabled) . "</span>";
                } else {
                    $statusBadge = "<span class='bg-info pd-5 radius-3'><i class='fa fa-ticket'></i> " . htmlspecialchars($_status_unused) . "</span>";
                }

                // Always show expiry on the search result; no separate expiry action is needed.
                $expiryRowHtml = '';
                if ($effectiveExpiry !== null) {
                    $expText = "<b style='font-size:15px;' class='" . (($isLimitExpired || $isCalendarExpired) ? "text-danger" : "text-green") . "'>"
                        . htmlspecialchars($effectiveExpiry['iso_datetime'])
                        . "</b> <small>(" . htmlspecialchars($effectiveExpiry['legacy_datetime']) . ")</small>";
                } else {
                    $expText = "<span class='text-warning'>" . htmlspecialchars($_not_activated_yet) . "</span>";
                }
                $expiryRowHtml = "
                  <tr style='background: rgba(23, 162, 184, 0.14);'>
                    <th>" . htmlspecialchars($_expiry_date) . "</th>
                    <td>" . $expText . "</td>
                  </tr>";

                $recentHistoryHtml = '';
                if (($isLimitExpired || $isCalendarExpired) && !empty($recentScriptRecs)) {
                    $historyRows = '';
                    foreach ($recentScriptRecs as $historyRec) {
                        $historyExpiry = !empty($historyRec['has_expiry'])
                            ? htmlspecialchars($historyRec['expiry_iso'] . ' (' . $historyRec['expiry_legacy'] . ')')
                            : htmlspecialchars($historyRec['comment'] !== '' ? $historyRec['comment'] : '-');
                        $historyRows .= "<tr>"
                            . "<td>" . htmlspecialchars($historyRec['login_datetime']) . "</td>"
                            . "<td>" . htmlspecialchars($historyRec['profile'] !== '' ? $historyRec['profile'] : '-') . "</td>"
                            . "<td>" . htmlspecialchars($historyRec['validity'] !== '' ? $historyRec['validity'] : '-') . "</td>"
                            . "<td>" . $historyExpiry . "</td>"
                            . "<td>" . htmlspecialchars($historyRec['ip'] !== '' ? $historyRec['ip'] : '-') . "</td>"
                            . "<td>" . htmlspecialchars($historyRec['mac'] !== '' ? $historyRec['mac'] : '-') . "</td>"
                            . "</tr>";
                    }
                    $recentHistoryHtml = "<tr><th colspan='6'>Voucher records — last 30 days</th></tr>"
                        . "<tr><th>Login time</th><th>Profile</th><th>Validity</th><th>Expiry</th><th>IP</th><th>MAC</th></tr>"
                        . $historyRows;
                }

                $resultCardHtml = "
                <div class='box box-bordered pd-10 mr-t-10'>
                  <h4 style='margin-top:0; margin-bottom:10px;'><i class='fa fa-id-card-o'></i> " . htmlspecialchars($_voucher_details) . "</h4>
                  <table class='table table-sm table-bordered table-hover'>
                    <tr>
                      <th style='width:35%;'>" . htmlspecialchars($_hotspot_name) . "</th>
                      <td><b>" . htmlspecialchars($srvCfg['hotspotname']) . "</b></td>
                    </tr>
                    <tr>
                      <th>" . htmlspecialchars($_user_name) . "</th>
                      <td><code style='font-size:15px; font-weight:bold;'>" . htmlspecialchars($uname) . "</code></td>
                    </tr>
                    <tr>
                      <th>" . htmlspecialchars($_password) . "</th>
                      <td><code>" . htmlspecialchars($uPass) . "</code></td>
                    </tr>
                    <tr>
                      <th>" . htmlspecialchars($_status) . "</th>
                      <td>" . $statusBadge . "</td>
                    </tr>
                    <tr>
                      <th>" . htmlspecialchars($_ip_address) . "</th>
                      <td><code>" . htmlspecialchars($uIpAddress) . "</code></td>
                    </tr>
                    <tr>
                      <th>" . htmlspecialchars($_mac_address) . "</th>
                      <td><code>" . htmlspecialchars($uMac) . "</code>" . renderRandomizedMacBadge($uMac) . "</td>
                    </tr>
                    <tr>
                      <th>" . htmlspecialchars($_profile) . "</th>
                      <td><b>" . htmlspecialchars($uProfile) . "</b>" . ($uLimitUp !== '-' ? " <small>(" . htmlspecialchars($_time_limit) . ": " . htmlspecialchars($uLimitUp) . ")</small>" : "") . "</td>
                    </tr>
                    <tr>
                      <th>" . htmlspecialchars($_uptime) . "</th>
                      <td><b>" . htmlspecialchars($uUptime) . "</b>" . ($uTimeLeft !== '' ? " &nbsp;|&nbsp; Time Left: <b>" . htmlspecialchars($uTimeLeft) . "</b>" : "") . "</td>
                    </tr>
                    <tr>
                      <th>" . htmlspecialchars($_data_usage) . "</th>
                      <td>Download: <b>" . htmlspecialchars($uBytesOut) . "</b> &nbsp;|&nbsp; Upload: <b>" . htmlspecialchars($uBytesIn) . "</b></td>
                    </tr>
                    " . $expiryRowHtml . "
                    " . $recentHistoryHtml . "
                  </table>

                  <form method='post' action='' autocomplete='off' class='mr-t-10'>
                    <input type='hidden' name='server_session' value='" . htmlspecialchars($selectedServer, ENT_QUOTES) . "'>
                    <input type='hidden' name='voucher_code' value='" . htmlspecialchars($voucherCode !== '' ? $voucherCode : $uname, ENT_QUOTES) . "'>
                    <input type='hidden' name='target_voucher' value='" . htmlspecialchars($uname, ENT_QUOTES) . "'>
                    <button type='submit' name='rv_action' value='reset' class='btn bg-primary mr-t-5' style='margin-right: 6px;'>
                      <i class='fa fa-refresh'></i> " . htmlspecialchars($_reset_voucher) . "
                    </button>
                    " . (!$uDisabled
                        ? "<button type='submit' name='rv_action' value='disable' class='btn bg-warning mr-t-5' style='margin-right: 6px;'><i class='fa fa-lock'></i> " . htmlspecialchars($_disable) . "</button>"
                        : "<button type='submit' name='rv_action' value='enable' class='btn bg-green mr-t-5' style='margin-right: 6px;'><i class='fa fa-unlock'></i> " . htmlspecialchars($_enable) . "</button>") . "
                  </form>
                </div>";
            } else {
                // Voucher NOT found in /ip/hotspot/user — check Mikhmon /system/script historical records
                $scriptRecs = findVoucherRecordsInScripts($rvAPI, $lookupKey);
                $recentScriptRecs = filterVoucherRecordsLast30Days($scriptRecs);
                if (!empty($scriptRecs)) {
                    $rec = !empty($recentScriptRecs)
                        ? $recentScriptRecs[count($recentScriptRecs) - 1]
                        : $scriptRecs[count($scriptRecs) - 1];
                    $expDateText = !empty($rec['has_expiry'])
                        ? ($rec['expiry_iso'] . " (" . $rec['expiry_legacy'] . ")")
                        : ($rec['comment'] !== '' ? $rec['comment'] : $_status_expired);
                    $actionAlertHtml = "<div class='bg-warning pd-10 radius-3 mr-b-10'><i class='fa fa-history'></i> " . htmlspecialchars($_rv_voucher_prefix) . " <b>" . htmlspecialchars($rec['voucher']) . "</b> " . htmlspecialchars($_rv_msg_removed_found) . "</div>";

                    $expiryRowHtml = "
                      <tr style='background: rgba(220, 53, 69, 0.15);'>
                        <th>" . htmlspecialchars($_expiry_date) . "</th>
                        <td><b class='text-danger' style='font-size:15px;'><i class='fa fa-calendar-times-o'></i> " . htmlspecialchars($expDateText) . "</b></td>
                      </tr>";
                    $historyRows = '';
                    $displayHistory = !empty($recentScriptRecs) ? $recentScriptRecs : array($rec);
                    foreach ($displayHistory as $historyRec) {
                        $historyExpiry = !empty($historyRec['has_expiry'])
                            ? htmlspecialchars($historyRec['expiry_iso'] . ' (' . $historyRec['expiry_legacy'] . ')')
                            : htmlspecialchars($historyRec['comment'] !== '' ? $historyRec['comment'] : '-');
                        $historyRows .= "<tr>"
                            . "<td>" . htmlspecialchars($historyRec['voucher']) . "</td>"
                            . "<td>" . htmlspecialchars($historyRec['login_datetime']) . "</td>"
                            . "<td>" . htmlspecialchars($historyRec['profile'] !== '' ? $historyRec['profile'] : '-') . "</td>"
                            . "<td>" . htmlspecialchars($historyRec['validity'] !== '' ? $historyRec['validity'] : '-') . "</td>"
                            . "<td>" . $historyExpiry . "</td>"
                            . "<td>" . htmlspecialchars($historyRec['ip'] !== '' ? $historyRec['ip'] : '-') . "</td>"
                            . "<td>" . htmlspecialchars($historyRec['mac'] !== '' ? $historyRec['mac'] : '-') . "</td>"
                            . "</tr>";
                    }
                    $recentHistoryHtml = "<tr><th colspan='7'>Voucher records — last 30 days</th></tr>"
                        . "<tr><th>Voucher</th><th>Login time</th><th>Profile</th><th>Validity</th><th>Expiry</th><th>IP</th><th>MAC</th></tr>"
                        . $historyRows;

                    $resultCardHtml = "
                    <div class='box box-bordered pd-10 mr-t-10'>
                      <h4 style='margin-top:0; margin-bottom:10px;'><i class='fa fa-archive text-warning'></i> " . htmlspecialchars($_removed_voucher_record) . "</h4>
                      <table class='table table-sm table-bordered table-hover'>
                        <tr>
                          <th style='width:35%;'>" . htmlspecialchars($_hotspot_name) . "</th>
                          <td><b>" . htmlspecialchars($srvCfg['hotspotname']) . "</b></td>
                        </tr>
                        <tr>
                          <th>" . htmlspecialchars($_voucher_code) . "</th>
                          <td><code style='font-size:15px; font-weight:bold;'>" . htmlspecialchars($rec['voucher']) . "</code></td>
                        </tr>
                        <tr>
                          <th>" . htmlspecialchars($_status) . "</th>
                          <td><span class='bg-danger pd-5 radius-3'><i class='fa fa-trash'></i> " . htmlspecialchars($_status_removed_from_server) . "</span></td>
                        </tr>
                        <tr>
                          <th>" . htmlspecialchars($_first_login_time) . "</th>
                          <td><b>" . htmlspecialchars($rec['login_datetime']) . "</b></td>
                        </tr>
                        <tr>
                          <th>" . htmlspecialchars($_profile) . " / " . htmlspecialchars($_validity) . "</th>
                          <td>" . htmlspecialchars($_profile) . ": <b>" . htmlspecialchars($rec['profile'] !== '' ? $rec['profile'] : '-') . "</b> &nbsp;|&nbsp; " . htmlspecialchars($_validity) . ": <b>" . htmlspecialchars($rec['validity'] !== '' ? $rec['validity'] : '-') . "</b></td>
                        </tr>
                        <tr>
                          <th>" . htmlspecialchars($_last_ip_mac) . "</th>
                          <td>IP: <code>" . htmlspecialchars($rec['ip'] !== '' ? $rec['ip'] : '-') . "</code> &nbsp;|&nbsp; MAC: <code>" . htmlspecialchars($rec['mac'] !== '' ? $rec['mac'] : '-') . "</code>" . renderRandomizedMacBadge($rec['mac']) . "</td>
                        </tr>
                        " . $expiryRowHtml . "
                        " . $recentHistoryHtml . "
                      </table>
                    </div>";
                } else {
                    $actionAlertHtml = "<div class='bg-danger pd-10 radius-3 mr-b-10'><i class='fa fa-times-circle'></i> " . htmlspecialchars($_rv_msg_not_found) . " <b>" . htmlspecialchars($srvCfg['hotspotname']) . "</b> (<code>" . htmlspecialchars($lookupKey) . "</code>)</div>";
                }
            }
            $rvAPI->disconnect();
        }
    }
}

$formActionUrl = (isset($id) && $id === 'reset-voucher')
    ? './admin.php?id=reset-voucher' . ($selectedServer !== '' ? '&session=' . urlencode($selectedServer) : '')
    : './?hotspot=reset-voucher&session=' . urlencode($selectedServer !== '' ? $selectedServer : $session);
?>

<div class="row">
  <div class="col-8">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-refresh"></i> <?= htmlspecialchars($_reset_voucher); ?></h3>
      </div>
      <div class="card-body">
        <?php if ($actionAlertHtml !== '') { echo $actionAlertHtml; } ?>

        <?php if (empty($allowedServers)) { ?>
          <div class="bg-danger pd-10 radius-3">
            <i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($_rv_no_server_assigned); ?>
          </div>
        <?php } else { ?>
          <form method="post" action="<?= htmlspecialchars($formActionUrl, ENT_QUOTES); ?>" autocomplete="off">
            <div class="box box-bordered pd-10">
              <table class="table table-sm">
                <tr>
                  <td class="align-middle" style="width: 30%;"><b><?= htmlspecialchars($_select_server); ?></b></td>
                  <td>
                    <select class="form-control" name="server_session" required="1">
                      <?php foreach ($allowedServers as $sKey => $sInfo) {
                        $isSel = ($sKey === $selectedServer) ? 'selected' : '';
                      ?>
                        <option value="<?= htmlspecialchars($sKey); ?>" <?= $isSel; ?>>
                          <?= htmlspecialchars($sInfo['hotspotname']); ?>
                        </option>
                      <?php } ?>
                    </select>
                  </td>
                </tr>
                <tr>
                  <td class="align-middle"><b><?= htmlspecialchars($_voucher_code); ?></b></td>
                  <td>
                    <input class="form-control" type="text" name="voucher_code" id="voucher_code_input" value="<?= htmlspecialchars($voucherCode); ?>" placeholder="<?= htmlspecialchars($_voucher_search_placeholder); ?>" required="1" autofocus>
                  </td>
                </tr>
                <tr>
                  <td class="align-middle"></td>
                  <td>
                    <button type="submit" name="rv_action" value="verify" class="btn bg-primary">
                      <i class="fa fa-search"></i> <?= htmlspecialchars($_check_voucher); ?>
                    </button>
                  </td>
                </tr>
              </table>
            </div>
          </form>
        <?php } ?>

        <?= $resultCardHtml; ?>
      </div>
    </div>
  </div>
</div>

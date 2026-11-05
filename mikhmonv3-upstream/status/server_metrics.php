<?php
/*
 *  NextGan WiFi Solution — Real-Time Host Server (VPS) CPU & RAM Metrics Endpoint
 *  Returns live CPU and RAM usage ratios for the top navbar symbol indicator (Admin only).
 */
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (isset($_GET['health'])) {
    echo json_encode(array('ok' => true, 'status' => 'up', 'ts' => time()));
    exit;
}

session_start();

if (!isset($_SESSION["mikhmon"])) {
    session_write_close();
    echo json_encode(array('ok' => false));
    exit;
}

include_once('../include/reseller_acl.php');
if (!isMikhmonAdmin()) {
    session_write_close();
    echo json_encode(array('ok' => false));
    exit;
}

// Release session lock immediately so concurrent requests never wait
session_write_close();

function readLinuxCpuCounters() {
    if (!is_readable('/proc/stat')) {
        return null;
    }
    $fp = @fopen('/proc/stat', 'r');
    if (!$fp) {
        return null;
    }
    $line = @fgets($fp);
    @fclose($fp);
    if (!$line || strpos($line, 'cpu ') !== 0) {
        return null;
    }
    $parts = preg_split('/\s+/', trim($line));
    if (count($parts) < 5) {
        return null;
    }
    $user = (float)$parts[1];
    $nice = (float)$parts[2];
    $system = (float)$parts[3];
    $idle = (float)$parts[4];
    $iowait = isset($parts[5]) ? (float)$parts[5] : 0.0;
    $irq = isset($parts[6]) ? (float)$parts[6] : 0.0;
    $softirq = isset($parts[7]) ? (float)$parts[7] : 0.0;
    $steal = isset($parts[8]) ? (float)$parts[8] : 0.0;

    $idleAll = $idle + $iowait;
    $total = $user + $nice + $system + $idleAll + $irq + $softirq + $steal;
    return array('total' => $total, 'idle' => $idleAll, 'ts' => microtime(true));
}

function getHostCpuPercent() {
    $cacheFile = sys_get_temp_dir() . '/ngw_host_cpu_stat.json';
    $curr = readLinuxCpuCounters();
    if ($curr !== null) {
        $prev = null;
        if (is_file($cacheFile)) {
            $prev = @json_decode((string)@file_get_contents($cacheFile), true);
        }
        @file_put_contents($cacheFile, json_encode($curr));

        if (is_array($prev) && isset($prev['total'], $prev['idle'], $prev['ts']) && ($curr['ts'] - $prev['ts']) < 20) {
            $totalDiff = $curr['total'] - (float)$prev['total'];
            $idleDiff = $curr['idle'] - (float)$prev['idle'];
            if ($totalDiff > 0) {
                $pct = round((($totalDiff - $idleDiff) / $totalDiff) * 100);
                return max(1, min(100, (int)$pct));
            }
        }

        // First sample fallback: brief 50ms delta
        usleep(50000);
        $curr2 = readLinuxCpuCounters();
        if ($curr2 !== null) {
            @file_put_contents($cacheFile, json_encode($curr2));
            $totalDiff = $curr2['total'] - $curr['total'];
            $idleDiff = $curr2['idle'] - $curr['idle'];
            if ($totalDiff > 0) {
                $pct = round((($totalDiff - $idleDiff) / $totalDiff) * 100);
                return max(1, min(100, (int)$pct));
            }
        }
    }

    if (function_exists('sys_getloadavg')) {
        $load = @sys_getloadavg();
        if (is_array($load) && isset($load[0])) {
            $pct = round($load[0] * 25);
            return max(1, min(100, (int)$pct));
        }
    }

    return 5;
}

function getHostRamPercent() {
    if (is_readable('/proc/meminfo')) {
        $meminfo = (string)@file_get_contents('/proc/meminfo');
        if ($meminfo !== '') {
            $total = 0;
            $avail = 0;
            $free = 0;
            $buffers = 0;
            $cached = 0;
            if (preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $m)) {
                $total = (float)$m[1];
            }
            if (preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $m)) {
                $avail = (float)$m[1];
            }
            if (preg_match('/^MemFree:\s+(\d+)/m', $meminfo, $m)) {
                $free = (float)$m[1];
            }
            if (preg_match('/^Buffers:\s+(\d+)/m', $meminfo, $m)) {
                $buffers = (float)$m[1];
            }
            if (preg_match('/^Cached:\s+(\d+)/m', $meminfo, $m)) {
                $cached = (float)$m[1];
            }
            if ($total > 0) {
                $effectiveAvail = ($avail > 0) ? $avail : ($free + $buffers + $cached);
                $used = max(0, $total - $effectiveAvail);
                $pct = round(($used / $total) * 100);
                return max(5, min(100, (int)$pct));
            }
        }
    }

    $memUsed = memory_get_usage(true);
    $pct = round(($memUsed / (128 * 1024 * 1024)) * 100);
    return max(8, min(100, (int)$pct));
}

echo json_encode(array(
    'ok' => true,
    'cpu' => getHostCpuPercent(),
    'ram' => getHostRamPercent()
));

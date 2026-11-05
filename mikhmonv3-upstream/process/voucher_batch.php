<?php
session_start();
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../include/reseller_acl.php';
require_once __DIR__ . '/../include/voucher_batch.php';

function voucherBatchReply($data, $status = 200) {
    http_response_code($status);
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    echo json_encode($data);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { voucherBatchReply(array('error'=>'POST required'), 405); }
if (!isset($_SESSION['mikhmon'])) { voucherBatchReply(array('error'=>'Please sign in again.', 'retryable'=>false), 401); }
// Background steps count as activity, but cannot revive an already expired login.
if (isset($_SESSION['mikhmon_last_activity']) && time() - (int)$_SESSION['mikhmon_last_activity'] > 300) {
    voucherBatchReply(array('error'=>'Your session expired. Please sign in again.', 'retryable'=>false), 401);
}
$router = isset($_POST['router']) && is_string($_POST['router']) ? $_POST['router'] : '';
$id = isset($_POST['job']) && is_string($_POST['job']) ? $_POST['job'] : '';
$token = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
if (!canAccessRouterSession($router) || !hasResellerPermission('generate_voucher')) {
    voucherBatchReply(array('error'=>'Access denied for this router.', 'retryable'=>false), 403);
}
if (empty($_SESSION['voucher_batch_csrf']) || !hash_equals($_SESSION['voucher_batch_csrf'], $token) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
    voucherBatchReply(array('error'=>'Reload the generation page and try again.', 'retryable'=>false), 403);
}
$_SESSION['mikhmon_last_activity'] = time();
date_default_timezone_set(isset($_SESSION['timezone']) ? $_SESSION['timezone'] : 'UTC');
$action = isset($_POST['action']) ? $_POST['action'] : '';
if (!isset($_SESSION['voucher_batches'])) { $_SESSION['voucher_batches'] = array(); }
try {
    if ($action === 'start' && !isset($_SESSION['voucher_batches'][$id])) {
        foreach ($_SESSION['voucher_batches'] as $key => $old) {
            if (time() - $old['created_at'] > 172800) { unset($_SESSION['voucher_batches'][$key]); }
        }
        $activeCount = 0;
        foreach ($_SESSION['voucher_batches'] as $old) { if ($old['completed'] < $old['total']) { $activeCount++; } }
        if ($activeCount >= 5) { throw new InvalidArgumentException('Resume an existing batch before starting another one.'); }
        $_SESSION['voucher_batches'][$id] = voucherBatchCreate($_POST, $router, $id);
    }
    if (!isset($_SESSION['voucher_batches'][$id]) || $_SESSION['voucher_batches'][$id]['router'] !== $router) {
        voucherBatchReply(array('error'=>'This batch is unavailable in the current session. Check Hotspot Users before starting again.', 'retryable'=>false), 404);
    }
    $job =& $_SESSION['voucher_batches'][$id];
    if ($action === 'step' && $job['completed'] < $job['total']) {
        require __DIR__ . '/../include/config.php';
        require_once __DIR__ . '/../lib/routeros_api.class.php';
        if (!isset($data[$router])) { throw new InvalidArgumentException('Router configuration was removed.'); }
        $host = explode('!', $data[$router][1], 2);
        $login = explode('@|@', $data[$router][2], 2);
        $pass = explode('#|#', $data[$router][3], 2);
        $api = new RouterosAPI();
        $api->timeout = 5;
        $api->attempts = 1;
        if (!$api->connect($host[1], $login[1], decrypt($pass[1]))) { throw new RuntimeException('Router connection failed. Resume the same batch when the router is reachable.'); }
        // The session lock serializes duplicate step requests for this job.
        voucherBatchStep($job, $api);
        $api->disconnect();
    } elseif ($action !== 'start' && $action !== 'step' && $action !== 'status') {
        throw new InvalidArgumentException('Unknown batch action.');
    }
    $done = $job['completed'] >= $job['total'];
    if ($done) {
        // Keep a small completed-job receipt so replaying start cannot add a new batch.
        $job['users'] = array();
        $_SESSION['voucher_generation_cache'][$router]['summary'] = $job['summary'];
        $_SESSION['voucher_generation_cache'][$router]['completed'] = $job['completed'];
        $_SESSION['ubp'] = $job['profile'];
    }
    voucherBatchReply(array('job'=>$id, 'completed'=>$job['completed'], 'total'=>$job['total'], 'done'=>$done,
        'redirect'=>'./?hotspot-user=generate&session=' . rawurlencode($router) . '&voucher-result=1'));
} catch (Exception $e) {
    voucherBatchReply(array('error'=>$e->getMessage(), 'retryable'=>!($e instanceof InvalidArgumentException),
        'completed'=>isset($job) ? $job['completed'] : 0, 'total'=>isset($job) ? $job['total'] : 0), 400);
}

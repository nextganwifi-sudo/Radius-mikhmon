<?php
require_once __DIR__ . '/../mikhmonv3-upstream/include/voucher_request.php';

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$resultQuery = array('hotspot-user' => 'generate', 'voucher-result' => '1');
check(isLocalVoucherResultRequest('GET', $resultQuery), 'Result GET must be local');
check(!isLocalVoucherResultRequest('POST', $resultQuery), 'Repeat generation POST must connect');
check(!isLocalVoucherResultRequest('GET', array('hotspot-user' => 'generate')), 'Initial form must connect');
check(!isLocalVoucherResultRequest('GET', array('hotspot' => 'users', 'voucher-result' => '1')), 'Other pages must connect');
check(!isLocalVoucherResultRequest('GET', $resultQuery + array('hotspot' => 'users')), 'Conflicting route must connect');
check(!isLocalVoucherResultRequest('GET', array('hotspot-user' => 'someone', 'voucher-result' => '1')), 'User detail must connect');

// Render the actual result template with an API double that rejects all calls.
// An old genprof query must not trigger a profile lookup on an offline result.
class NoRouterCalls {
    public function comm($command, $args = array()) {
        throw new RuntimeException('Unexpected result-page API call: ' . $command);
    }
}
function decrypt($value) { return 'vc-123-10.31.26-note|~router-b-profile~1d~10!12~0~0~Disable'; }
function formatBytes($value, $precision) { return (string)$value; }

session_start();
$_SESSION = array(
    'mikhmon' => 'admin', 'timezone' => 'UTC', 'ubp' => '', 'vcr' => '',
    'voucher_generation_cache' => array(
        'router-a' => array('servers' => array(array('name' => 'router-a-server')), 'profiles' => array(array('name' => 'router-a-profile'))),
        'router-b' => array('servers' => array(array('name' => 'router-b-server')), 'profiles' => array(array('name' => 'router-b-profile'))),
    ),
);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = $resultQuery + array('genprof' => 'old-profile');
$_POST = array();
$session = 'router-b';
$API = new NoRouterCalls();
$currency = 'USD';
$cekindo = array('indo' => array('Rp'));
chdir(__DIR__ . '/../mikhmonv3-upstream');
ob_start();
include './hotspot/generateuser.php';
$html = ob_get_clean();
check(strpos($html, '<option>router-b-server</option>') !== false, 'Selected router server missing');
check(strpos($html, '<option>router-b-profile</option>') !== false, 'Selected router profile missing');
check(strpos($html, 'router-a-server') === false, 'Other router server leaked into form');
check(strpos($html, 'router-a-profile') === false, 'Other router profile leaked into form');
check(strpos($html, 'method="post" action="./?hotspot-user=generate&amp;session=router-b"') !== false, 'Form must POST to a clean generation URL');
session_write_close();
echo "PASS: voucher result routing, offline rendering, router cache isolation and repeat-generation form\n";

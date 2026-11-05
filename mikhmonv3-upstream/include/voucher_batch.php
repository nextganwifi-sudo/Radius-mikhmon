<?php
// Batch state contains precomputed names so a lost HTTP response never creates
// a fresh set of vouchers when the same job is resumed.
function voucherBatchCreate($input, $router, $id) {
    foreach (array('qty','user','userl','char','profile','server','prefix','timelimit','datalimit','mbgb','adcomment') as $key) {
        if (isset($input[$key]) && !is_scalar($input[$key])) {
            throw new InvalidArgumentException('Invalid voucher field: ' . $key);
        }
    }
    $qty = filter_var(isset($input['qty']) ? $input['qty'] : '', FILTER_VALIDATE_INT);
    $length = (int)(isset($input['userl']) ? $input['userl'] : 0);
    $mode = isset($input['user']) ? $input['user'] : '';
    $char = isset($input['char']) ? $input['char'] : '';
    $alphabets = array('lower'=>'abcdefghijkmnprstuvwxyz', 'upper'=>'ABCDEFGHJKLMNPRSTUVWXYZ',
        'upplow'=>'ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz',
        'mix'=>'23456789abcdefghijkmnprstuvwxyz', 'mix1'=>'23456789ABCDEFGHJKLMNPRSTUVWXYZ',
        'mix2'=>'23456789ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz', 'num'=>'23456789');
    if (!$qty || $qty < 1 || $qty > 500 || $length < 3 || $length > 8 || !in_array($mode, array('up','vc'), true)
        || !isset($alphabets[$char]) || ($mode === 'up' && $char === 'num')) {
        throw new InvalidArgumentException('Choose 1–500 vouchers, a length of 3–8, and a valid character mode.');
    }
    $profile = trim(isset($input['profile']) ? $input['profile'] : '');
    $server = trim(isset($input['server']) ? $input['server'] : 'all');
    $prefix = isset($input['prefix']) ? $input['prefix'] : '';
    $note = isset($input['adcomment']) ? $input['adcomment'] : '';
    $time = trim(isset($input['timelimit']) ? $input['timelimit'] : '');
    $data = trim(isset($input['datalimit']) ? (string)$input['datalimit'] : '');
    $unit = isset($input['mbgb']) ? (string)$input['mbgb'] : '1048576';
    if ($profile === '' || strlen($profile) > 100 || strlen($server) > 100 || strlen($prefix) > 6
        || strlen($note) > 200 || strlen($time) > 50 || ($data !== '' && (!is_numeric($data) || $data < 0 || $data > 9999))
        || !in_array($unit, array('1048576','1073741824'), true)) {
        throw new InvalidArgumentException('Invalid profile, prefix, time limit or data limit.');
    }
    $random = function ($alphabet, $size) {
        $text = '';
        for ($i = 0; $i < $size; $i++) { $text .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        return $text;
    };
    $users = array(); $seen = array(); $tries = 0;
    $digits = array(3=>1, 4=>2, 5=>2, 6=>3, 7=>3, 8=>4);
    while (count($users) < $qty) {
        if (++$tries > 50000) { throw new InvalidArgumentException('Use a longer voucher length for this quantity.'); }
        if ($mode === 'vc' && in_array($char, array('lower','upper','upplow'), true)) {
            $name = $prefix . $random($alphabets[$char], $length - $digits[$length]) . $random('23456789', $digits[$length]);
        } else {
            $name = $prefix . $random($alphabets[$char], $length);
        }
        if (isset($seen[$name])) { continue; }
        $seen[$name] = true;
        $users[] = array('name'=>$name, 'password'=>$mode === 'vc' ? $name : $random('23456789', $length));
    }
    $comment = $mode . '-' . substr($id, 0, 12) . '-' . date('m.d.y') . '-' . $note;
    return array('id'=>$id, 'router'=>$router, 'created_at'=>time(), 'users'=>$users, 'total'=>$qty, 'completed'=>0,
        'profile'=>$profile, 'server'=>$server, 'comment'=>$comment, 'summary'=>null,
        'timelimit'=>$time === '' ? '0' : $time, 'datalimit'=>(string)($data === '' ? 0 : $data * (int)$unit));
}

function voucherBatchApiOk($api, $response) {
    return $api->connected && !(is_array($response) && (isset($response['!trap']) || isset($response['!fatal'])));
}

function voucherBatchStep(&$job, $api, $maxUsers = 3, $budgetSeconds = 6) {
    $started = microtime(true);
    if ($job['completed'] >= $job['total']) { return; }
    if ($job['summary'] === null) {
        $profiles = $api->comm('/ip/hotspot/user/profile/print', array('?name'=>$job['profile']));
        if (!voucherBatchApiOk($api, $profiles) || !is_array($profiles)) { throw new RuntimeException('Could not read the selected profile. Resume this batch after checking the router connection.'); }
        if (!isset($profiles[0]['name']) || $profiles[0]['name'] !== $job['profile']) { throw new InvalidArgumentException('The selected profile no longer exists on this router.'); }
        $parts = explode(',', isset($profiles[0]['on-login']) ? $profiles[0]['on-login'] : '');
        $job['summary'] = $job['comment'] . '|~' . $job['profile'] . '~' . (isset($parts[3]) ? $parts[3] : '')
            . '~' . (isset($parts[2]) ? $parts[2] : '0') . '!' . (isset($parts[4]) ? $parts[4] : '0')
            . '~' . $job['timelimit'] . '~' . $job['datalimit'] . '~' . (isset($parts[6]) ? $parts[6] : '');
    }
    for ($n = 0; $n < $maxUsers && $job['completed'] < $job['total']; $n++) {
        if ($n > 0 && microtime(true) - $started >= $budgetSeconds) { break; }
        $user = $job['users'][$job['completed']];
        // Always reconcile first: PHP may have stopped after RouterOS accepted
        // the add, or the previous JSON response may never have reached the client.
        $existing = $api->comm('/ip/hotspot/user/print', array('?name'=>$user['name'], '.proplist'=>'name,comment,profile'));
        if (!voucherBatchApiOk($api, $existing) || !is_array($existing)) { throw new RuntimeException('Could not verify the current voucher. Resume the same batch; do not start a new one.'); }
        if (count($existing)) {
            if (!isset($existing[0]['comment'], $existing[0]['profile']) || $existing[0]['comment'] !== $job['comment'] || $existing[0]['profile'] !== $job['profile']) {
                throw new InvalidArgumentException('A generated username already belongs to another batch. Check Hotspot Users before starting a new batch.');
            }
        } else {
            $response = $api->comm('/ip/hotspot/user/add', array('name'=>$user['name'], 'password'=>$user['password'],
                'server'=>$job['server'], 'profile'=>$job['profile'], 'limit-uptime'=>$job['timelimit'],
                'limit-bytes-total'=>$job['datalimit'], 'comment'=>$job['comment']));
            if (!voucherBatchApiOk($api, $response)) {
                if (!$api->connected) { throw new RuntimeException('Router response was interrupted. Resume to verify this voucher and continue the same batch.'); }
                $reason = isset($response['!trap'][0]['message']) ? $response['!trap'][0]['message'] : 'RouterOS rejected the voucher';
                throw new InvalidArgumentException('RouterOS: ' . $reason);
            }
        }
        $job['completed']++;
    }
}

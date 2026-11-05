<?php
function transferUser($row, $profile, $server = 'all') {
    foreach (array('name', 'password', 'comment') as $key) {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new InvalidArgumentException('Missing ' . $key . '; export requires password access.');
        }
    }
    if ($row['name'] === '' || strlen($row['name']) > 255) {
        throw new InvalidArgumentException('Invalid username.');
    }
    $out = array('name' => $row['name'], 'password' => $row['password'],
        'profile' => $profile, 'comment' => $row['comment']);
    if (isset($row['mac-address']) && is_string($row['mac-address']) && $row['mac-address'] !== ''
        && $row['mac-address'] !== '00:00:00:00:00:00') {
        $out['mac-address'] = $row['mac-address'];
    }
    return $out;
}

function transferRscQuote($value) {
    $out = '"';
    for ($i = 0; $i < strlen($value); $i++) {
        $byte = ord($value[$i]);
        if ($byte < 32 || $byte > 126) { $out .= '\\' . strtoupper(bin2hex($value[$i])); }
        elseif ($value[$i] === '"' || $value[$i] === '\\' || $value[$i] === '$') { $out .= '\\' . $value[$i]; }
        else { $out .= $value[$i]; }
    }
    return $out . '"';
}
function transferRsc($users, $target) {
    $lines = array('# Hotspot users - RouterOS ' . (int)$target, '# Existing usernames are skipped. Matching profiles must already exist.');
    foreach ($users as $row) {
        $user = transferUser($row, $row['profile']);
        $args = array();
        foreach ($user as $key => $value) { $args[] = $key . '=' . transferRscQuote($value); }
        $name = transferRscQuote($user['name']);
        $profile = transferRscQuote($user['profile']);
        $lines[] = ':if ([:len [/ip hotspot user profile find where name=' . $profile . ']] = 0) do={ :put "Skipped: destination profile missing"; } else={ :if ([:len [/ip hotspot user find where name=' . $name . ']] = 0) do={ /ip hotspot user add ' . implode(' ', $args) . '; } else={ :put "Skipped: username already exists"; }; };';
    }
    return implode("\r\n", $lines) . "\r\n";
}

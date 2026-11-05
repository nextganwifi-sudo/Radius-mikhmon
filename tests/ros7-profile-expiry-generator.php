<?php
// Focused generator regression checks for RouterOS 7 Hotspot profile expiry.
// These tests validate generated scripts; they do not emulate RouterOS execution.
$_SERVER["REQUEST_URI"] = "/tests/ros7-profile-expiry-generator.php";
require __DIR__ . "/../mikhmonv3-upstream/include/ros_version.php";

function assertGenerated($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$ros7 = buildUniversalMikhmonScripts("30Days-30SR", "remc", "30", "30d", "0", "Enable", 7);
assertGenerated(strpos($ros7["onlogin"], ':local validity "30d";') !== false, "ROS7 validity was not injected");
assertGenerated(strpos($ros7["onlogin"], '$alreadyActivated') !== false, "ROS7 first-activation guard missing");
assertGenerated(strpos($ros7["onlogin"], '$expComment') !== false, "ROS7 calendar expiry comment missing");
assertGenerated(strpos($ros7["onlogin"], 'mac-address=$mac') !== false, "ROS7 MAC lock logic missing");
assertGenerated(strpos($ros7["onlogin"], 'comment="mikhmon"') !== false, "ROS7 sales record logic missing");
assertGenerated(strpos($ros7["bgservice"], 'profile="30Days-30SR"') !== false, "Scheduler profile filter is not dynamic");
assertGenerated(strpos($ros7["bgservice"], '/ip hotspot active remove') !== false, "Active session removal missing");
assertGenerated(strpos($ros7["bgservice"], '/ip hotspot cookie remove') !== false, "Hotspot cookie removal missing");

$ros7Hours = buildUniversalMikhmonScripts("3Hours-Test", "rem", "0", "3h", "0", "Disable", 7);
assertGenerated(strpos($ros7Hours["onlogin"], ':local validity "3h";') !== false, "ROS7 hour validity was not injected");
assertGenerated(strpos($ros7Hours["onlogin"], '__VALIDITY__') === false, "Unexpanded validity placeholder remains");

$ros6 = buildUniversalMikhmonScripts("Legacy-ROS6", "rem", "0", "30d", "0", "Disable", 6);
assertGenerated(strpos($ros6["onlogin"], ':local ucode') !== false, "ROS6 legacy on-login implementation changed");
assertGenerated(strpos($ros6["onlogin"], '$alreadyActivated') === false, "ROS7 activation code leaked into ROS6");

echo "PASS: ROS7 day/hour validity generation, first-login guard, MAC lock, sales record, profile-specific monitor, active/cookie cleanup, and ROS6 isolation\n";

<?php
if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
} else {
    $vStateFile = __DIR__ . '/version_state.json';
    $vNum = '1.0';
    if (is_file($vStateFile)) {
        $vData = @json_decode((string)@file_get_contents($vStateFile), true);
        if (is_array($vData) && !empty($vData['current_version'])) {
            $vNum = (string)$vData['current_version'];
        }
    }
    $_SESSION["v"] = $vNum;
}

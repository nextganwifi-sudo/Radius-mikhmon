<?php
/*
 *  NextGan WiFi Solution — Minimal 1-Click GitHub Safe Update + Software Version & 10-Step Rollback
 *  Connects to a GitHub Repository URL, pulls code updates, increments Software Version,
 *  and maintains at most 10 rollback snapshots (auto-deleting older ones to keep VPS storage clean)
 *  while strictly preserving all live routers, resellers, templates, and logos.
 */
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include_once('./include/reseller_acl.php');

if (!isMikhmonAdmin()) {
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
}

$appRoot = realpath(__DIR__ . '/..');
$workspaceRoot = (is_dir(dirname($appRoot) . '/mikhmonv3-upstream') && is_file(dirname($appRoot) . '/package.json'))
    ? dirname($appRoot)
    : $appRoot;

$defaultGitRepoUrl = 'https://github.com/nextganwifi-sudo/mikhmon.git';
$gitRepoConfigFile = $appRoot . '/include/github_repo.txt';
$savedGitRepoUrl = is_file($gitRepoConfigFile) ? trim((string)@file_get_contents($gitRepoConfigFile)) : '';
if ($savedGitRepoUrl === '') {
    $savedGitRepoUrl = $defaultGitRepoUrl;
}

$gitTokenConfigFile = $appRoot . '/include/github_token.txt';
$savedGitToken = is_file($gitTokenConfigFile) ? trim((string)@file_get_contents($gitTokenConfigFile)) : '';
if ($savedGitToken === '') {
    $envToken = getenv('GITHUB_TOKEN') ? trim((string)getenv('GITHUB_TOKEN')) : (getenv('GH_TOKEN') ? trim((string)getenv('GH_TOKEN')) : '');
    if ($envToken !== '') {
        $savedGitToken = $envToken;
    }
}

$versionStateFile = $appRoot . '/include/version_state.json';
$versionSnapDir = $appRoot . '/backups/version-history';
if (!is_dir($versionSnapDir)) {
    @mkdir($versionSnapDir, 0755, true);
}

/**
 * Load current software version & rollback history (max 10 entries)
 */
function loadVersionState($stateFile) {
    $default = array(
        'current_version' => '1.0',
        'updated_at' => date('Y-m-d H:i'),
        'history' => array()
    );
    if (!is_file($stateFile)) {
        @file_put_contents($stateFile, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }
    $decoded = @json_decode((string)@file_get_contents($stateFile), true);
    if (!is_array($decoded)) {
        return $default;
    }
    if (empty($decoded['current_version'])) {
        $decoded['current_version'] = '1.0';
    }
    if (empty($decoded['updated_at'])) {
        $decoded['updated_at'] = date('Y-m-d H:i');
    }
    if (!isset($decoded['history']) || !is_array($decoded['history'])) {
        $decoded['history'] = array();
    }
    return $decoded;
}

/**
 * Save version state & strictly prune history to maximum 10 snapshots on disk
 */
function saveAndPruneVersionState($stateFile, $snapDir, $state) {
    $maxSnapshots = 10;
    $history = isset($state['history']) && is_array($state['history']) ? array_values($state['history']) : array();

    // Remove entries beyond the 10th slot and delete their archive files
    if (count($history) > $maxSnapshots) {
        for ($i = $maxSnapshots; $i < count($history); $i++) {
            if (!empty($history[$i]['file'])) {
                $oldFile = $snapDir . '/' . basename($history[$i]['file']);
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }
        }
        $history = array_slice($history, 0, $maxSnapshots);
    }

    // Also remove any orphan files inside backups/version-history/ not referenced by the kept 10 entries
    $keptFiles = array();
    foreach ($history as $item) {
        if (!empty($item['file'])) {
            $keptFiles[basename($item['file'])] = true;
        }
    }
    if (is_dir($snapDir)) {
        foreach (glob($snapDir . '/*') as $f) {
            if (is_file($f) && !isset($keptFiles[basename($f)])) {
                @unlink($f);
            }
        }
    }

    $state['history'] = $history;
    @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    $_SESSION["v"] = $state['current_version'];
    return $state;
}

/**
 * Collect all protected user data files (relative path => binary content)
 * Ensures 0% data loss during Git updates or version rollbacks.
 */
function collectProtectedDataFiles($rootDir) {
    $protectedFiles = array();
    $fixedRelPaths = array(
        'include/config.php',
        'include/backup_admin.json',
        'include/resellers.json',
        'include/vpn_routers.json',
        'include/vpn_tunnels.json',
        'include/vpn_server_config.json',
        'include/radius_nas.json',
        'include/radius_db.json',
        'include/permission_groups.json',
        'include/quickbt.php',
        'include/github_repo.txt',
        'include/github_token.txt',
        'include/lang.php',
        'include/version_state.json',
        'voucher/template.php',
        'voucher/template-small.php',
        'voucher/template-thermal.php',
    );
    foreach ($fixedRelPaths as $rel) {
        $abs = $rootDir . '/' . $rel;
        if (is_file($abs)) {
            $protectedFiles[$rel] = @file_get_contents($abs);
        }
    }

    // Per-reseller templates & logos inside uploads/
    $uploadsDir = $rootDir . '/uploads';
    if (is_dir($uploadsDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            if ($item->isFile()) {
                $full = $item->getRealPath();
                $rel = 'uploads/' . str_replace('\\', '/', substr($full, strlen($uploadsDir) + 1));
                $protectedFiles[$rel] = @file_get_contents($full);
            }
        }
    }

    // Session & Global Logos inside img/ and images/
    foreach (array('img', 'images') as $imgFolder) {
        $dirPath = $rootDir . '/' . $imgFolder;
        if (is_dir($dirPath)) {
            foreach (glob($dirPath . '/logo*.png') as $logoFile) {
                if (is_file($logoFile)) {
                    $rel = $imgFolder . '/' . basename($logoFile);
                    $protectedFiles[$rel] = @file_get_contents($logoFile);
                }
            }
        }
    }
    return $protectedFiles;
}

/**
 * Restore all protected user data files from an in-memory map
 */
function restoreProtectedDataFiles($rootDir, $protectedMap) {
    foreach ($protectedMap as $rel => $content) {
        if ($content === false) continue;
        $dest = $rootDir . '/' . $rel;
        $parent = dirname($dest);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        @file_put_contents($dest, $content);
    }
}

/**
 * Check if a relative path belongs to the Protected Data Locklist
 */
function isProtectedDataRelPath($relPath) {
    $norm = ltrim(str_replace('\\', '/', $relPath), '/');
    if (strpos($norm, 'mikhmonv3-upstream/') === 0) {
        $norm = substr($norm, strlen('mikhmonv3-upstream/'));
    }
    $exactLocked = array(
        'include/config.php',
        'include/backup_admin.json',
        'include/resellers.json',
        'include/vpn_routers.json',
        'include/vpn_tunnels.json',
        'include/vpn_server_config.json',
        'include/radius_nas.json',
        'include/radius_db.json',
        'include/permission_groups.json',
        'include/quickbt.php',
        'include/github_repo.txt',
        'include/github_token.txt',
        'include/lang.php',
        'include/version_state.json',
        'voucher/template.php',
        'voucher/template-small.php',
        'voucher/template-thermal.php',
    );
    if (in_array($norm, $exactLocked, true)) {
        return true;
    }
    if (strpos($norm, 'uploads/reseller-templates/') === 0 || strpos($norm, 'uploads/reseller-logos/') === 0) {
        return true;
    }
    if (strpos($norm, 'backups/') === 0) {
        return true;
    }
    if (preg_match('#^(img|images)/logo-.*\.(png|jpg|jpeg|webp)$#i', $norm)) {
        return true;
    }
    return false;
}

/**
 * Build an authenticated HTTPS Git URL using a GitHub Personal Access Token (never persisted to .git/config)
 */
function buildAuthenticatedGitUrl($repoUrl, $token) {
    $cleanUrl = trim((string)$repoUrl);
    $cleanToken = trim((string)$token);
    if ($cleanToken === '' || $cleanUrl === '') {
        return $cleanUrl;
    }
    // Strip any existing embedded credentials from https://...
    $cleanUrl = preg_replace('#^https://[^/@]+@#i', 'https://', $cleanUrl);
    if (preg_match('#^https://github\.com/(.+)$#i', $cleanUrl, $m)) {
        return 'https://x-access-token:' . rawurlencode($cleanToken) . '@github.com/' . $m[1];
    }
    return $cleanUrl;
}

/**
 * Mask secret token from command output before displaying to the user
 */
function maskGitSecrets($text, $token) {
    $out = (string)$text;
    if ($token !== '') {
        $out = str_replace($token, '***', $out);
        $out = str_replace(rawurlencode($token), '***', $out);
    }
    $out = preg_replace('#https://[^/@\s]+@github\.com/#i', 'https://***@github.com/', $out);
    return $out;
}

/**
 * Ensure the workspace has a valid .git directory tracking the remote repository's main branch
 * without altering any working tree files.
 */
function ensureWorkspaceGitInitialized($wsRoot, $repoUrl) {
    if (!function_exists('shell_exec') || $repoUrl === '') {
        return '';
    }
    $gitPath = $wsRoot . '/.git';
    if (!is_dir($gitPath)) {
        @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($wsRoot) . ' init -b main 2>&1');
        @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($wsRoot) . ' remote add origin ' . escapeshellarg($repoUrl) . ' 2>&1');
        @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($wsRoot) . ' fetch --depth 1 origin main 2>&1');
        @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($wsRoot) . ' reset --mixed FETCH_HEAD 2>&1');
    } else {
        $curRemote = trim((string)@shell_exec('git -c safe.directory=* -C ' . escapeshellarg($wsRoot) . ' remote get-url origin 2>/dev/null'));
        if ($curRemote === '') {
            @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($wsRoot) . ' remote add origin ' . escapeshellarg($repoUrl) . ' 2>/dev/null');
        }
        $headRev = trim((string)@shell_exec('git -c safe.directory=* -C ' . escapeshellarg($wsRoot) . ' rev-parse --verify HEAD 2>/dev/null'));
        if ($headRev === '') {
            @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($wsRoot) . ' fetch --depth 1 origin main 2>&1');
            @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($wsRoot) . ' reset --mixed FETCH_HEAD 2>&1');
        }
    }
    return is_dir($gitPath) ? $wsRoot : '';
}

/**
 * Unstage protected runtime files so they are never committed or pushed to GitHub
 */
function unstageProtectedPaths($wsRoot) {
    $protectedPaths = array(
        'mikhmonv3-upstream/include/config.php',
        'mikhmonv3-upstream/include/github_token.txt',
        'mikhmonv3-upstream/include/github_repo.txt',
        'mikhmonv3-upstream/include/resellers.json',
        'mikhmonv3-upstream/include/backup_admin.json',
        'mikhmonv3-upstream/include/vpn_routers.json',
        'mikhmonv3-upstream/include/vpn_tunnels.json',
        'mikhmonv3-upstream/include/vpn_server_config.json',
        'mikhmonv3-upstream/include/radius_nas.json',
        'mikhmonv3-upstream/include/radius_db.json',
        'mikhmonv3-upstream/include/version_state.json',
        'mikhmonv3-upstream/backups',
        'include/config.php',
        'include/github_token.txt',
        'include/github_repo.txt',
        'include/resellers.json',
        'include/backup_admin.json',
        'include/vpn_routers.json',
        'include/vpn_tunnels.json',
        'include/vpn_server_config.json',
        'include/radius_nas.json',
        'include/radius_db.json',
        'include/version_state.json',
        'backups',
        '.aistudio',
        'bun.lock'
    );
    foreach ($protectedPaths as $p) {
        @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($wsRoot) . ' reset -q HEAD -- ' . escapeshellarg($p) . ' 2>/dev/null');
    }
}

/**
 * List modified/untracked files in workspace ready to be pushed to GitHub
 */
function getPendingGitChanges($wsRoot) {
    if (!function_exists('shell_exec') || !is_dir($wsRoot . '/.git')) {
        return array();
    }
    $raw = (string)@shell_exec('git -c safe.directory=* -C ' . escapeshellarg($wsRoot) . ' status --porcelain 2>/dev/null');
    $lines = preg_split('/\r\n|\r|\n/', trim($raw));
    $changes = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $status = substr($line, 0, 2);
        $file = trim(substr($line, 2));
        $file = trim($file, '"');
        if (
            $file === '.aistudio' || strpos($file, '.aistudio/') === 0 ||
            $file === 'bun.lock' ||
            strpos($file, 'node_modules/') === 0 ||
            strpos($file, 'dist/') === 0 ||
            isProtectedDataRelPath($file)
        ) {
            continue;
        }
        $changes[] = array(
            'status' => trim($status),
            'file' => $file
        );
    }
    return $changes;
}

/**
 * Create a lightweight code snapshot archive of current software before updating
 */
function createVersionSnapshotArchive($rootDir, $snapDir, $versionLabel) {
    $safeVer = preg_replace('/[^a-zA-Z0-9._-]/', '_', $versionLabel);
    $snapId = 'ver_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);

    if (class_exists('ZipArchive')) {
        $fileName = 'snap_v' . $safeVer . '_' . $snapId . '.zip';
        $fullPath = $snapDir . '/' . $fileName;
        $zip = new ZipArchive();
        if ($zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                if ($item->isFile()) {
                    $full = $item->getRealPath();
                    $rel = str_replace('\\', '/', substr($full, strlen($rootDir) + 1));
                    if (strpos($rel, '.git/') === 0 || strpos($rel, 'backups/') === 0 || isProtectedDataRelPath($rel)) {
                        continue;
                    }
                    $zip->addFile($full, $rel);
                }
            }
            $zip->close();
            if (is_file($fullPath)) {
                return array(
                    'id' => $snapId,
                    'version' => $versionLabel,
                    'saved_at' => date('Y-m-d H:i:s'),
                    'file' => $fileName
                );
            }
        }
    }

    if (function_exists('shell_exec')) {
        $fileName = 'snap_v' . $safeVer . '_' . $snapId . '.tar.gz';
        $fullPath = $snapDir . '/' . $fileName;
        $cmd = 'tar -czf ' . escapeshellarg($fullPath)
            . ' --exclude=.git --exclude=backups --exclude=uploads/reseller-templates --exclude=uploads/reseller-logos'
            . ' -C ' . escapeshellarg($rootDir) . ' . 2>/dev/null';
        @shell_exec($cmd);
        if (is_file($fullPath)) {
            return array(
                'id' => $snapId,
                'version' => $versionLabel,
                'saved_at' => date('Y-m-d H:i:s'),
                'file' => $fileName
            );
        }
    }

    return null;
}

/**
 * Restore code files from a version snapshot archive while keeping protected data untouched
 */
function extractVersionSnapshotArchive($rootDir, $snapFile) {
    if (!is_file($snapFile)) {
        return false;
    }
    if (substr($snapFile, -4) === '.zip' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($snapFile) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rel = str_replace('\\', '/', $zip->getNameIndex($i));
                if (substr($rel, -1) === '/' || strpos($rel, '..') !== false) {
                    continue;
                }
                if (strpos($rel, '.git/') === 0 || strpos($rel, 'backups/') === 0 || isProtectedDataRelPath($rel)) {
                    continue;
                }
                $stream = $zip->getStream($zip->getNameIndex($i));
                if ($stream) {
                    $content = stream_get_contents($stream);
                    fclose($stream);
                    $dest = $rootDir . '/' . $rel;
                    if (!is_dir(dirname($dest))) {
                        @mkdir(dirname($dest), 0755, true);
                    }
                    @file_put_contents($dest, $content);
                }
            }
            $zip->close();
            return true;
        }
    } elseif (substr($snapFile, -7) === '.tar.gz' && function_exists('shell_exec')) {
        $tmpExtract = sys_get_temp_dir() . '/ngw_ver_restore_' . uniqid();
        @mkdir($tmpExtract, 0755, true);
        @shell_exec('tar -xzf ' . escapeshellarg($snapFile) . ' -C ' . escapeshellarg($tmpExtract) . ' 2>/dev/null');
        if (is_file($tmpExtract . '/admin.php')) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmpExtract, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                if ($item->isFile()) {
                    $full = $item->getRealPath();
                    $rel = str_replace('\\', '/', substr($full, strlen($tmpExtract) + 1));
                    if (strpos($rel, '.git/') === 0 || strpos($rel, 'backups/') === 0 || isProtectedDataRelPath($rel)) {
                        continue;
                    }
                    $dest = $rootDir . '/' . $rel;
                    if (!is_dir(dirname($dest))) {
                        @mkdir(dirname($dest), 0755, true);
                    }
                    @copy($full, $dest);
                }
            }
            @shell_exec('rm -rf ' . escapeshellarg($tmpExtract));
            return true;
        }
        @shell_exec('rm -rf ' . escapeshellarg($tmpExtract));
    }
    return false;
}

/**
 * Calculate next incremented version string (e.g. 1.0 -> 1.1 -> 1.2)
 */
function incrementVersionNumber($verStr) {
    $clean = preg_replace('/[^0-9.]/', '', (string)$verStr);
    if ($clean === '' || !is_numeric($clean)) {
        return '1.1';
    }
    $next = round(((float)$clean) + 0.1, 1);
    return number_format($next, 1, '.', '');
}

$versionState = loadVersionState($versionStateFile);
$updateNotice = '';
$updateError = '';

// Ensure workspace git tracking is initialized so modified files and direct push work seamlessly
ensureWorkspaceGitInitialized($workspaceRoot, $savedGitRepoUrl);

// Handle Rollback / Switch to a Previous Version
if (isset($_POST['rollback_version_id'])) {
    $targetSnapId = trim((string)$_POST['rollback_version_id']);
    $foundEntry = null;
    $foundIdx = -1;
    foreach ($versionState['history'] as $idx => $entry) {
        if (isset($entry['id']) && $entry['id'] === $targetSnapId) {
            $foundEntry = $entry;
            $foundIdx = $idx;
            break;
        }
    }

    if ($foundEntry !== null && !empty($foundEntry['file'])) {
        $snapPath = $versionSnapDir . '/' . basename($foundEntry['file']);
        if (is_file($snapPath)) {
            $protectedBefore = collectProtectedDataFiles($appRoot);
            $ok = extractVersionSnapshotArchive($appRoot, $snapPath);
            restoreProtectedDataFiles($appRoot, $protectedBefore);

            if ($ok) {
                // Remove the restored snapshot from history and set it as current version
                @unlink($snapPath);
                array_splice($versionState['history'], $foundIdx, 1);
                $versionState['current_version'] = (string)$foundEntry['version'];
                $versionState['updated_at'] = date('Y-m-d H:i');
                $versionState = saveAndPruneVersionState($versionStateFile, $versionSnapDir, $versionState);

                $rbMsg = isset($_rollback_success_msg) ? $_rollback_success_msg : 'Successfully switched software to version';
                $updateNotice = htmlspecialchars($rbMsg) . ' <b>v' . htmlspecialchars($versionState['current_version']) . '</b>';
            } else {
                $updateError = 'Could not restore the selected version snapshot.';
            }
        } else {
            $updateError = 'Snapshot archive file not found on server.';
        }
    }
}

// Handle Direct Push to GitHub
if (isset($_POST['apply_git_push'])) {
    $gitRepoUrl = isset($_POST['git_repo_url']) ? trim((string)$_POST['git_repo_url']) : '';
    if ($gitRepoUrl !== '') {
        @file_put_contents($gitRepoConfigFile, $gitRepoUrl);
        $savedGitRepoUrl = $gitRepoUrl;
    } else {
        $gitRepoUrl = $savedGitRepoUrl;
    }

    $postedToken = isset($_POST['git_token']) ? trim((string)$_POST['git_token']) : '';
    if ($postedToken !== '') {
        @file_put_contents($gitTokenConfigFile, $postedToken);
        @chmod($gitTokenConfigFile, 0600);
        $savedGitToken = $postedToken;
    }

    $commitMsg = isset($_POST['git_commit_msg']) ? trim((string)$_POST['git_commit_msg']) : '';
    if ($commitMsg === '') {
        $commitMsg = 'Update from NextGan WiFi Solution (' . date('Y-m-d H:i') . ')';
    }

    if ($gitRepoUrl === '') {
        $updateError = isset($_git_url_required) ? $_git_url_required : 'Please enter your GitHub Repository URL.';
    } elseif ($savedGitToken === '' && strpos($gitRepoUrl, '@github.com') === false) {
        $updateError = isset($_git_token_required) ? $_git_token_required : 'Please enter your GitHub Personal Access Token (PAT) to push changes directly to GitHub.';
    } elseif (function_exists('shell_exec')) {
        $gitDir = ensureWorkspaceGitInitialized($workspaceRoot, $gitRepoUrl);
        if ($gitDir !== '') {
            @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($gitDir) . ' config user.name "NextGan WiFi Solution" 2>/dev/null');
            @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($gitDir) . ' config user.email "nextganwifi@users.noreply.github.com" 2>/dev/null');
            @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($gitDir) . ' remote set-url origin ' . escapeshellarg($gitRepoUrl) . ' 2>/dev/null');

            // Stage modified/new files and strictly unstage protected runtime data
            @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($gitDir) . ' add -A 2>&1');
            unstageProtectedPaths($gitDir);

            $pendingAfterStage = getPendingGitChanges($gitDir);
            if (!empty($pendingAfterStage)) {
                $commitOut = @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($gitDir) . ' commit -m ' . escapeshellarg($commitMsg) . ' 2>&1');
            }

            $authUrl = buildAuthenticatedGitUrl($gitRepoUrl, $savedGitToken);
            $pushCmd = 'GIT_TERMINAL_PROMPT=0 git -c safe.directory=* -C ' . escapeshellarg($gitDir) . ' push ' . escapeshellarg($authUrl) . ' HEAD:main 2>&1';
            $pushOut = (string)@shell_exec($pushCmd);
            $maskedOut = maskGitSecrets($pushOut, $savedGitToken);

            if (
                strpos($pushOut, '-> main') !== false ||
                strpos($pushOut, 'Everything up-to-date') !== false
            ) {
                @shell_exec('GIT_TERMINAL_PROMPT=0 git -c safe.directory=* -C ' . escapeshellarg($gitDir) . ' fetch ' . escapeshellarg($authUrl) . ' main 2>/dev/null');
                if (strpos($pushOut, 'Everything up-to-date') !== false && empty($pendingAfterStage)) {
                    $msgNoChanges = isset($_git_push_no_changes) ? $_git_push_no_changes : 'No new code changes found to push — GitHub is already up to date.';
                    $updateNotice = htmlspecialchars($msgNoChanges);
                } else {
                    $msgPushOk = isset($_git_push_success) ? $_git_push_success : 'All changes have been pushed directly to your GitHub repository!';
                    $updateNotice = htmlspecialchars($msgPushOk) . ' (<b>' . count($pendingAfterStage) . ' files</b> — <code>' . htmlspecialchars($commitMsg) . '</code>)<br><small>' . htmlspecialchars($maskedOut) . '</small>';
                }
            } else {
                $msgPushFail = isset($_git_push_failed) ? $_git_push_failed : 'Failed to push to GitHub. Please verify your GitHub Personal Access Token and URL.';
                $updateError = htmlspecialchars($msgPushFail) . '<br><small><code>' . htmlspecialchars($maskedOut) . '</code></small>';
            }
        }
    }
}

// Handle Git Safe Update (Pull)
if (isset($_POST['apply_git_safe_update'])) {
    $gitRepoUrl = isset($_POST['git_repo_url']) ? trim((string)$_POST['git_repo_url']) : '';
    if ($gitRepoUrl !== '') {
        @file_put_contents($gitRepoConfigFile, $gitRepoUrl);
        $savedGitRepoUrl = $gitRepoUrl;
    }

    $protectedBefore = collectProtectedDataFiles($appRoot);
    $gitDir = is_dir($appRoot . '/.git') ? $appRoot : (is_dir(dirname($appRoot) . '/.git') ? dirname($appRoot) : '');

    if ($gitRepoUrl !== '' && function_exists('shell_exec')) {
        // 1. Save current software state as a rollback snapshot BEFORE updating
        $snapEntry = createVersionSnapshotArchive($appRoot, $versionSnapDir, $versionState['current_version']);

        $tmpClone = sys_get_temp_dir() . '/ngw_git_sync_' . uniqid();
        $authCloneUrl = buildAuthenticatedGitUrl($gitRepoUrl, $savedGitToken);
        $cmd = 'GIT_TERMINAL_PROMPT=0 git clone --depth 1 ' . escapeshellarg($authCloneUrl) . ' ' . escapeshellarg($tmpClone) . ' 2>&1';
        $out = maskGitSecrets((string)@shell_exec($cmd), $savedGitToken);
        $srcRoot = is_dir($tmpClone . '/mikhmonv3-upstream') ? ($tmpClone . '/mikhmonv3-upstream') : $tmpClone;
        if (is_file($srcRoot . '/admin.php') && is_file($srcRoot . '/index.php')) {
            $updatedCount = 0;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                if ($item->isFile()) {
                    $full = $item->getRealPath();
                    $rel = str_replace('\\', '/', substr($full, strlen($srcRoot) + 1));
                    if (strpos($rel, '.git/') === 0 || isProtectedDataRelPath($rel)) {
                        continue;
                    }
                    $dest = $appRoot . '/' . $rel;
                    if (!is_dir(dirname($dest))) {
                        @mkdir(dirname($dest), 0755, true);
                    }
                    if (@copy($full, $dest)) {
                        $updatedCount++;
                    }
                }
            }
            restoreProtectedDataFiles($appRoot, $protectedBefore);
            @shell_exec('rm -rf ' . escapeshellarg($tmpClone));

            // 2. Push snapshot to history, increment version, and auto-prune beyond 10 versions
            if ($snapEntry !== null) {
                array_unshift($versionState['history'], $snapEntry);
            }
            $versionState['current_version'] = incrementVersionNumber($versionState['current_version']);
            $versionState['updated_at'] = date('Y-m-d H:i');
            $versionState = saveAndPruneVersionState($versionStateFile, $versionSnapDir, $versionState);

            $msgDone = isset($_git_update_success) ? $_git_update_success : 'Git Safe Update completed successfully! Updated code files from GitHub while keeping all routers, resellers, templates, and logos 100% intact.';
            $updateNotice = $msgDone . ' (<b>v' . htmlspecialchars($versionState['current_version']) . '</b> — ' . (int)$updatedCount . ' files synced)';
        } else {
            // Update failed; delete the temporary pre-update snapshot so no extra version is created
            if ($snapEntry !== null && !empty($snapEntry['file'])) {
                @unlink($versionSnapDir . '/' . basename($snapEntry['file']));
            }
            restoreProtectedDataFiles($appRoot, $protectedBefore);
            @shell_exec('rm -rf ' . escapeshellarg($tmpClone));
            $updateError = (isset($_git_update_failed) ? $_git_update_failed : 'Could not fetch application files from the provided GitHub URL.') . ' ' . htmlspecialchars((string)$out);
        }
    } elseif ($gitDir !== '' && function_exists('shell_exec')) {
        $snapEntry = createVersionSnapshotArchive($appRoot, $versionSnapDir, $versionState['current_version']);
        $out = @shell_exec('git -c safe.directory=* -C ' . escapeshellarg($gitDir) . ' pull --ff-only 2>&1');
        restoreProtectedDataFiles($appRoot, $protectedBefore);

        if ($snapEntry !== null) {
            array_unshift($versionState['history'], $snapEntry);
        }
        $versionState['current_version'] = incrementVersionNumber($versionState['current_version']);
        $versionState['updated_at'] = date('Y-m-d H:i');
        $versionState = saveAndPruneVersionState($versionStateFile, $versionSnapDir, $versionState);

        $msgDone = isset($_git_update_success) ? $_git_update_success : 'Git Safe Update completed successfully! All routers, resellers, templates, and logos are 100% intact.';
        $updateNotice = $msgDone . ' (<b>v' . htmlspecialchars($versionState['current_version']) . '</b>)<br><small>' . htmlspecialchars((string)$out) . '</small>';
    } else {
        restoreProtectedDataFiles($appRoot, $protectedBefore);
        $updateError = isset($_git_url_required) ? $_git_url_required : 'Please enter your GitHub Repository URL to pull updates.';
    }
}

$pendingGitChanges = getPendingGitChanges($workspaceRoot);
?>

<div class="row">
  <div class="col-6">
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;">
        <h3 class="card-title" style="margin:0;">
          <i class="fa fa-github"></i> <?= htmlspecialchars(isset($_system_update) ? $_system_update : 'System Update'); ?>
        </h3>
        <span style="font-size:12px; padding:3px 10px; border-radius:4px; background:#2ea043; color:#fff; font-weight:bold;">
          <i class="fa fa-tag"></i> <?= htmlspecialchars(isset($_software_version_label) ? $_software_version_label : 'Software Version'); ?>: v<?= htmlspecialchars($versionState['current_version']); ?>
        </span>
      </div>
      <div class="card-body">
        <?php if ($updateNotice !== '') { ?>
          <div class="bg-green pd-10 radius-3 mr-b-10"><i class="fa fa-check-circle"></i> <?= $updateNotice; ?></div>
        <?php } ?>
        <?php if ($updateError !== '') { ?>
          <div class="bg-danger pd-10 radius-3 mr-b-10"><i class="fa fa-ban"></i> <?= $updateError; ?></div>
        <?php } ?>

        <form method="post" action="" autocomplete="off">
          <table class="table table-sm" style="margin-bottom: 12px;">
            <tr>
              <td class="align-middle" style="width: 32%;"><?= htmlspecialchars(isset($_github_repo_url_label) ? $_github_repo_url_label : 'GitHub URL'); ?></td>
              <td>
                <input type="text" name="git_repo_url" class="form-control" value="<?= htmlspecialchars($savedGitRepoUrl); ?>" placeholder="https://github.com/username/repository.git" required="1">
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= htmlspecialchars(isset($_github_token_label) ? $_github_token_label : 'GitHub Token (PAT)'); ?></td>
              <td>
                <input type="password" name="git_token" class="form-control" value="" placeholder="<?= htmlspecialchars($savedGitToken !== '' ? (isset($_github_token_saved_hint) ? $_github_token_saved_hint : 'Token saved (leave blank to keep)') : (isset($_github_token_placeholder) ? $_github_token_placeholder : 'ghp_xxxxxxxxxxxxxxxxxxxx')); ?>">
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= htmlspecialchars(isset($_github_commit_msg_label) ? $_github_commit_msg_label : 'Commit Message'); ?></td>
              <td>
                <input type="text" name="git_commit_msg" class="form-control" value="" placeholder="<?= htmlspecialchars(isset($_github_commit_msg_placeholder) ? $_github_commit_msg_placeholder : 'Describe your changes (optional)'); ?>">
              </td>
            </tr>
            <tr>
              <td></td>
              <td style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="submit" name="apply_git_push" class="btn bg-success pd-10" style="cursor:pointer; font-weight:bold;">
                  <i class="fa fa-cloud-upload"></i> <?= htmlspecialchars(isset($_btn_git_push_changes) ? $_btn_git_push_changes : 'Push Changes to GitHub'); ?>
                </button>
                <button type="submit" name="apply_git_safe_update" class="btn bg-primary pd-10" style="cursor:pointer; font-weight:bold;">
                  <i class="fa fa-cloud-download"></i> <?= htmlspecialchars(isset($_btn_git_pull_update) ? $_btn_git_pull_update : 'Git Safe Update (Pull)'); ?>
                </button>
              </td>
            </tr>
          </table>
        </form>

        <div style="border-top: 1px solid rgba(150,150,150,0.25); padding-top: 10px; margin-top: 6px; margin-bottom: 10px;">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
            <b style="font-size:13px;"><i class="fa fa-code-fork"></i> <?= htmlspecialchars(isset($_github_pending_changes_label) ? $_github_pending_changes_label : 'Modified Files Ready to Push'); ?></b>
            <span class="badge" style="background:#1f6feb; color:#fff; padding:2px 8px; border-radius:4px; font-size:11px;"><?= count($pendingGitChanges); ?></span>
          </div>
          <?php if (empty($pendingGitChanges)) { ?>
            <div style="font-size:12px; opacity:0.8; padding:4px 0;">
              <i class="fa fa-check"></i> <?= htmlspecialchars(isset($_github_no_pending_changes) ? $_github_no_pending_changes : 'All files are currently in sync with GitHub (no pending changes).'); ?>
            </div>
          <?php } else { ?>
            <div style="max-height:140px; overflow-y:auto; background:rgba(0,0,0,0.15); padding:6px 8px; border-radius:4px; font-family:monospace; font-size:12px;">
              <?php foreach ($pendingGitChanges as $chg) { ?>
                <div style="padding:2px 0;">
                  <span style="display:inline-block; width:28px; font-weight:bold; color:#58a6ff;"><?= htmlspecialchars($chg['status']); ?></span>
                  <span><?= htmlspecialchars($chg['file']); ?></span>
                </div>
              <?php } ?>
            </div>
          <?php } ?>
        </div>

        <div style="border-top: 1px solid rgba(150,150,150,0.25); padding-top: 10px; margin-top: 6px;">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
            <b style="font-size:13px;"><i class="fa fa-history"></i> <?= htmlspecialchars(isset($_version_history_title) ? $_version_history_title : 'Version List (Max 10 Previous Versions)'); ?></b>
            <small style="opacity:0.75;"><?= count($versionState['history']); ?> / 10</small>
          </div>

          <?php if (empty($versionState['history'])) { ?>
            <div style="font-size:12px; opacity:0.8; padding:6px 0;">
              <?= htmlspecialchars(isset($_no_previous_versions) ? $_no_previous_versions : 'No previous version snapshots yet. A snapshot will be saved automatically on your next Git Safe Update.'); ?>
            </div>
          <?php } else { ?>
            <table class="table table-bordered table-sm" style="margin-bottom:6px;">
              <thead>
                <tr>
                  <th><?= htmlspecialchars(isset($_software_version_label) ? $_software_version_label : 'Software Version'); ?></th>
                  <th><?= htmlspecialchars(isset($_date) ? $_date : 'Date'); ?></th>
                  <th class="text-right"><?= htmlspecialchars(isset($_action) ? $_action : 'Action'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($versionState['history'] as $hItem) { ?>
                  <tr>
                    <td class="align-middle"><b>v<?= htmlspecialchars($hItem['version']); ?></b></td>
                    <td class="align-middle"><small><?= htmlspecialchars($hItem['saved_at']); ?></small></td>
                    <td class="align-middle text-right">
                      <form method="post" action="" style="margin:0; display:inline;" onsubmit="return confirm('<?= htmlspecialchars(isset($_rollback_confirm) ? $_rollback_confirm : 'Are you sure you want to switch back to version', ENT_QUOTES); ?> v<?= htmlspecialchars($hItem['version'], ENT_QUOTES); ?>?');">
                        <input type="hidden" name="rollback_version_id" value="<?= htmlspecialchars($hItem['id']); ?>">
                        <button type="submit" class="btn bg-warning pd-5" style="cursor:pointer; font-size:12px; font-weight:bold;">
                          <i class="fa fa-undo"></i> <?= htmlspecialchars(isset($_btn_rollback_version) ? $_btn_rollback_version : 'Switch to this Version'); ?> (v<?= htmlspecialchars($hItem['version']); ?>)
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          <?php } ?>
          <div style="font-size:11px; opacity:0.7;">
            <i class="fa fa-shield"></i> <?= htmlspecialchars(isset($_max_4_versions_note) ? $_max_4_versions_note : 'Auto-keeps maximum 10 versions (the oldest version is automatically removed on the 11th update to save VPS storage).'); ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

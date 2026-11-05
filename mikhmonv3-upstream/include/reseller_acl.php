<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *  NextGan WiFi Solution — Mikhmon Safe Extension (Reseller RBAC, Permission Groups, Templates & Logos)
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 */

if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -16) == "reseller_acl.php") {
    header("Location:./");
    exit;
}

if (!defined('JSON_PRETTY_PRINT')) {
    define('JSON_PRETTY_PRINT', 128);
}
if (!defined('PASSWORD_DEFAULT')) {
    define('PASSWORD_DEFAULT', 1);
}
if (!function_exists('password_hash')) {
    function password_hash($password, $algo) {
        $salt = substr(str_replace('+', '.', base64_encode(md5(uniqid(mt_rand(), true), true))), 0, 22);
        return crypt((string)$password, '$2y$10$' . $salt);
    }
}
if (!function_exists('password_verify')) {
    function password_verify($password, $hash) {
        return crypt((string)$password, (string)$hash) === (string)$hash;
    }
}

/**
 * Organized 9-category permission registry across all Mikhmon modules, templates, and admin operations.
 */
function getAllMikhmonPermissionCategories() {
    return array(
        'Hotspot Users & Vouchers' => array(
            'view_dashboard'      => 'View Dashboard & System Resource',
            'view_users'          => 'View Hotspot Users & Voucher Lists',
            'add_user'            => 'Add Single Hotspot User',
            'generate_voucher'    => 'Generate Batch Vouchers',
            'edit_user'           => 'View & Edit Hotspot User Details',
            'delete_user'         => 'Delete Hotspot Users (Single / Comment / Expired)',
            'enable_disable_user' => 'Enable, Disable & Reset Hotspot Users',
            'reset_voucher'       => 'Reset Voucher (Reset, Enable, Disable & Check Expiry)',
            'print_voucher'       => 'Print Vouchers (Default / QR / Small / BT / Quick Print)',
            'manage_quickprint'   => 'Manage Quick Print Packages',
            'export_users'        => 'Export Hotspot Users (Script / CSV)',
        ),
        'Hotspot Profiles & Active Sessions' => array(
            'view_profiles'       => 'View Hotspot User Profiles',
            'add_profile'         => 'Add Hotspot User Profile',
            'edit_profile'        => 'Edit Hotspot User Profile & Sync ROS6/ROS7 Scripts',
            'delete_profile'      => 'Delete Hotspot User Profile',
            'view_active'         => 'View Hotspot Active Sessions',
            'disconnect_active'   => 'Disconnect Active Hotspot User',
        ),
        'Network Management' => array(
            'view_hosts'          => 'View Hotspot Hosts',
            'manage_hosts'        => 'Remove Hosts & Make IP Bindings',
            'view_ipbinding'      => 'View Hotspot IP Bindings',
            'manage_ipbinding'    => 'Add, Enable, Disable & Remove IP Bindings',
            'view_cookies'        => 'View Hotspot Cookies',
            'delete_cookies'      => 'Delete Hotspot Cookies',
            'view_dhcp'           => 'View DHCP Leases',
        ),
        'Logs, Reports & Monitoring' => array(
            'view_logs'           => 'View Hotspot Log & User Log',
            'view_report'         => 'View & Print Selling Reports & Live Report',
            'export_report'       => 'Export Selling Report (CSV)',
            'delete_report'       => 'Delete Selling Report Records',
            'view_traffic'        => 'View Interface Traffic Monitor',
        ),
        'System Scheduler' => array(
            'view_scheduler'         => 'View System Scheduler (Menu & List)',
            'manage_scheduler'       => 'Manage System Scheduler (Enable, Disable & Remove)',
        ),
        'Router Management' => array(
            'reboot_shutdown'        => 'Reboot & Shutdown Router',
            'manage_router_settings' => 'Modify Router Session Settings & Upload Logo',
        ),
        'PPP and Other Network Services' => array(
            'view_ppp'            => 'View PPP Secrets, Profiles & Active',
            'manage_ppp'          => 'Add, Edit, Enable, Disable & Remove PPP Items',
        ),
        'Template Management' => array(
            'use_assigned_template'     => 'Use Assigned Template',
            'preview_assigned_template' => 'Preview Assigned Template',
            'edit_own_template'         => 'Edit Own Assigned Template',
            'save_own_template'         => 'Save Own Template',
            'publish_own_template'      => 'Publish Own Template',
            'restore_own_template'      => 'Restore Own Template Version',
            'assign_template'           => 'Assign or Change Template (Protected Admin-Only)',
            'manage_global_templates'   => 'Manage Global Templates (Protected Admin-Only)',
        ),
        'Reseller Management' => array(
            'view_resellers'      => 'View Reseller Accounts (Protected Admin-Only)',
            'manage_resellers'    => 'Create, Edit & Delete Resellers (Protected Admin-Only)',
        ),
        'Administrator-only Operations' => array(
            'manage_admin_account'     => 'Manage Main Administrator Account & Password (Protected)',
            'manage_permission_groups' => 'Manage Permission Group Definitions (Protected)',
            'manage_global_security'   => 'Manage Global Security & System Configuration (Protected)',
        ),
    );
}

/**
 * List of protected administrator-only permission keys that ordinary resellers can NEVER be granted,
 * even in Full or Custom mode.
 */
function getProtectedAdminOnlyPermissions() {
    return array(
        'assign_template',
        'manage_global_templates',
        'view_resellers',
        'manage_resellers',
        'manage_admin_account',
        'manage_permission_groups',
        'manage_global_security',
    );
}

/**
 * Check if a permission key is a protected Administrator-only operation.
 */
function isProtectedAdminPermission($permKey) {
    return in_array($permKey, getProtectedAdminOnlyPermissions(), true);
}

/**
 * Complete catalog of granular permissions (returns categorized map for UI and flat lookup support).
 */
function getAllMikhmonPermissionDefinitions() {
    return getAllMikhmonPermissionCategories();
}

/**
 * Return flat map of all permission keys => label
 */
function getFlatMikhmonPermissionLabels() {
    $flat = array();
    foreach (getAllMikhmonPermissionCategories() as $cat => $items) {
        foreach ($items as $k => $label) {
            $flat[$k] = $label;
        }
    }
    return $flat;
}

/**
 * Return flat map of all permission keys => default boolean value
 */
function getDefaultPermissionMap($defaultVal = false) {
    $map = array();
    foreach (getAllMikhmonPermissionCategories() as $group => $items) {
        foreach ($items as $key => $label) {
            if ($defaultVal && isProtectedAdminPermission($key)) {
                $map[$key] = true;
            } else {
                $map[$key] = (bool)$defaultVal;
            }
        }
    }
    return $map;
}

/**
 * Strip protected administrator-only permissions from any reseller permission map.
 */
function sanitizeResellerPermissions($permMap) {
    $clean = getDefaultPermissionMap(false);
    if (is_array($permMap)) {
        // Legacy key aliases
        if (!empty($permMap['add_single_user'])) $permMap['add_user'] = true;
        if (!empty($permMap['view_reports'])) $permMap['view_report'] = true;
        if (!empty($permMap['delete_reports'])) $permMap['delete_report'] = true;
        if (!empty($permMap['remove_host'])) $permMap['manage_hosts'] = true;
        if (!empty($permMap['remove_cookie'])) $permMap['delete_cookies'] = true;
        if (!empty($permMap['manage_template_logo'])) {
            $permMap['edit_own_template'] = true;
            $permMap['save_own_template'] = true;
        }
        foreach ($clean as $k => $v) {
            if (isProtectedAdminPermission($k)) {
                $clean[$k] = false;
            } else {
                $clean[$k] = !empty($permMap[$k]);
            }
        }
    }
    return $clean;
}

/**
 * Built-in default permission group definitions for Read, Write, and Full modes.
 */
function getDefaultPermissionGroupDefinitions() {
    $readKeys = array(
        'view_dashboard',
        'view_users',
        'view_profiles',
        'view_active',
        'view_hosts',
        'view_ipbinding',
        'view_cookies',
        'view_dhcp',
        'view_logs',
        'view_report',
        'view_traffic',
        'view_ppp',
        'use_assigned_template',
        'preview_assigned_template',
    );

    $writeKeys = array_merge($readKeys, array(
        'add_user',
        'generate_voucher',
        'edit_user',
        'enable_disable_user',
        'reset_voucher',
        'print_voucher',
        'manage_quickprint',
        'export_users',
        'export_report',
        'edit_own_template',
        'save_own_template',
    ));

    $readMap = getDefaultPermissionMap(false);
    foreach ($readKeys as $rk) {
        $readMap[$rk] = true;
    }

    $writeMap = getDefaultPermissionMap(false);
    foreach ($writeKeys as $wk) {
        $writeMap[$wk] = true;
    }

    $fullMap = getDefaultPermissionMap(false);
    foreach ($fullMap as $fk => $fv) {
        if (!isProtectedAdminPermission($fk) && $fk !== 'view_scheduler' && $fk !== 'manage_scheduler') {
            $fullMap[$fk] = true;
        }
    }

    $resetOnlyMap = getDefaultPermissionMap(false);
    $resetOnlyMap['reset_voucher'] = true;

    return array(
        'Read'          => sanitizeResellerPermissions($readMap),
        'Write'         => sanitizeResellerPermissions($writeMap),
        'Full'          => sanitizeResellerPermissions($fullMap),
        'Reset Voucher' => sanitizeResellerPermissions($resetOnlyMap),
    );
}

/**
 * Path to permission groups storage file
 */
function getPermissionGroupsStoragePath() {
    return __DIR__ . '/permission_groups.json';
}

/**
 * Load administrator-configurable Read, Write, and Full permission group definitions.
 */
function loadPermissionGroups($forceReload = false) {
    if (!$forceReload && isset($GLOBALS['NGW_CACHE_PERM_GROUPS']) && is_array($GLOBALS['NGW_CACHE_PERM_GROUPS'])) {
        return $GLOBALS['NGW_CACHE_PERM_GROUPS'];
    }
    $defaults = getDefaultPermissionGroupDefinitions();
    $path = getPermissionGroupsStoragePath();
    if (!file_exists($path)) {
        @file_put_contents($path, json_encode($defaults, JSON_PRETTY_PRINT));
        $GLOBALS['NGW_CACHE_PERM_GROUPS'] = $defaults;
        return $defaults;
    }
    $raw = @file_get_contents($path);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $GLOBALS['NGW_CACHE_PERM_GROUPS'] = $defaults;
        return $defaults;
    }
    $result = array();
    foreach (array('Read', 'Write', 'Full', 'Reset Voucher') as $mode) {
        if (isset($decoded[$mode]) && is_array($decoded[$mode])) {
            $result[$mode] = sanitizeResellerPermissions(array_merge($defaults[$mode], $decoded[$mode]));
        } else {
            $result[$mode] = $defaults[$mode];
        }
    }
    $GLOBALS['NGW_CACHE_PERM_GROUPS'] = $result;
    return $result;
}

/**
 * Save administrator-configured Read, Write, Full, and Reset Voucher permission groups.
 */
function savePermissionGroups($groups) {
    $defaults = getDefaultPermissionGroupDefinitions();
    $toSave = array();
    foreach (array('Read', 'Write', 'Full', 'Reset Voucher') as $mode) {
        $src = isset($groups[$mode]) && is_array($groups[$mode]) ? $groups[$mode] : $defaults[$mode];
        $toSave[$mode] = sanitizeResellerPermissions($src);
    }
    $ok = @file_put_contents(getPermissionGroupsStoragePath(), json_encode($toSave, JSON_PRETTY_PRINT)) !== false;
    $GLOBALS['NGW_CACHE_PERM_GROUPS'] = $toSave;
    unset($GLOBALS['NGW_RESELLER_SYNCED']);
    return $ok;
}

/**
 * Standard Reseller permission preset (Write mode equivalent)
 */
function getStandardSellerPermissions() {
    $groups = loadPermissionGroups();
    return $groups['Write'];
}

/**
 * Resolve effective permissions for a reseller record based on permission_mode (Read, Write, Full, Reset Voucher, Custom)
 */
function resolveEffectiveResellerPermissions($reseller) {
    $mode = isset($reseller['permission_mode']) ? trim((string)$reseller['permission_mode']) : 'Custom';
    $groups = loadPermissionGroups();
    if ($mode === 'Read' || $mode === 'Write' || $mode === 'Full' || $mode === 'Reset Voucher') {
        return $groups[$mode];
    }
    $rawPerms = isset($reseller['permissions']) && is_array($reseller['permissions']) ? $reseller['permissions'] : getStandardSellerPermissions();
    return sanitizeResellerPermissions($rawPerms);
}

/**
 * Parse all configured router session names from include/config.php
 */
function getConfiguredRouterSessions($configFilePath = null) {
    if ($configFilePath === null) {
        $configFilePath = __DIR__ . '/config.php';
    }
    if (!file_exists($configFilePath)) {
        return array();
    }
    $routers = array();
    foreach (file($configFilePath) as $line) {
        $parts = explode("'", $line);
        if (isset($parts[1])) {
            $sesname = trim($parts[1]);
            if ($sesname !== '' && $sesname !== 'mikhmon' && strpos($line, '$data[') !== false) {
                $routers[$sesname] = $sesname;
            }
        }
    }
    return array_values($routers);
}

/**
 * Path to resellers storage file
 */
function getResellersStoragePath() {
    return __DIR__ . '/resellers.json';
}

/**
 * Ensure runtime upload directories exist with protective index.php and .htaccess files
 */
function ensureResellerRuntimeDirectories() {
    $baseRoot = dirname(__DIR__);
    $dirs = array(
        $baseRoot . '/uploads',
        $baseRoot . '/uploads/reseller-logos',
        $baseRoot . '/uploads/reseller-templates',
        $baseRoot . '/images',
    );
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
        $idxFile = $d . '/index.php';
        if (!file_exists($idxFile)) {
            @file_put_contents($idxFile, "<?php header('Location:../'); exit; ?>\n");
        }
    }
    $htaccess = $baseRoot . '/uploads/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|sh)$\">\n  Deny from all\n</FilesMatch>\n");
    }
}

/**
 * Ensure a reseller's template directory has initialized template files
 */
function ensureResellerTemplateFiles($resellerId) {
    $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$resellerId);
    if ($safeId === '') {
        return false;
    }
    ensureResellerRuntimeDirectories();
    $baseRoot = dirname(__DIR__);
    $tplDir = $baseRoot . '/uploads/reseller-templates/' . $safeId;
    if (!is_dir($tplDir)) {
        @mkdir($tplDir, 0755, true);
    }
    $variants = array(
        'template.php'         => $baseRoot . '/voucher/default.php',
        'template-thermal.php' => $baseRoot . '/voucher/default-thermal.php',
        'template-small.php'   => $baseRoot . '/voucher/default-small.php',
    );
    foreach ($variants as $targetName => $sourceDefault) {
        $targetPath = $tplDir . '/' . $targetName;
        if (!file_exists($targetPath) && file_exists($sourceDefault)) {
            @copy($sourceDefault, $targetPath);
        }
        $draftPath = $tplDir . '/' . str_replace('.php', '.draft.php', $targetName);
        if (!file_exists($draftPath) && file_exists($targetPath)) {
            @copy($targetPath, $draftPath);
        }
        $bakPath = $tplDir . '/' . str_replace('.php', '.bak.php', $targetName);
        if (!file_exists($bakPath) && file_exists($targetPath)) {
            @copy($targetPath, $bakPath);
        }
    }
    return true;
}

/**
 * Load all configured resellers from include/resellers.json with backward-compatible schema migration
 */
function loadResellers($forceReload = false) {
    if (!$forceReload && isset($GLOBALS['NGW_CACHE_RESELLERS']) && is_array($GLOBALS['NGW_CACHE_RESELLERS'])) {
        return $GLOBALS['NGW_CACHE_RESELLERS'];
    }
    ensureResellerRuntimeDirectories();
    $path = getResellersStoragePath();
    $groups = loadPermissionGroups();

    if (!file_exists($path)) {
        // Never provision publicly known demo reseller credentials on a fresh installation.
        // Existing deployments retain their resellers.json through the safe-update backup/restore flow.
        $seed = array();
        @file_put_contents($path, json_encode($seed, JSON_PRETTY_PRINT));
        return $seed;
    }

    $raw = @file_get_contents($path);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array();
    }

    $needsSave = false;
    foreach ($decoded as $idx => $res) {
        // Backward-compatible ID normalization: preserve stable numeric IDs (101, 102, ...) while keeping legacy_ids
        $currId = isset($res['id']) ? (string)$res['id'] : (string)(101 + $idx);
        if (!isset($res['legacy_ids']) || !is_array($res['legacy_ids'])) {
            $res['legacy_ids'] = array();
        }
        if ($currId === 'res_kiosk_utara' || $currId === 'reseller_1') {
            $res['legacy_ids'] = array_values(array_unique(array_merge($res['legacy_ids'], array($currId, 'res_kiosk_utara', 'reseller_1'))));
            $res['id'] = '101';
            $needsSave = true;
        } elseif ($currId === 'res_mitra_selatan' || $currId === 'reseller_2') {
            $res['legacy_ids'] = array_values(array_unique(array_merge($res['legacy_ids'], array($currId, 'res_mitra_selatan', 'reseller_2'))));
            $res['id'] = '102';
            $needsSave = true;
        } elseif ($currId === '') {
            $res['id'] = (string)(101 + $idx);
            $needsSave = true;
        }

        if (empty($res['permission_mode']) || !in_array($res['permission_mode'], array('Read', 'Write', 'Full', 'Reset Voucher', 'Custom'), true)) {
            $res['permission_mode'] = 'Custom';
            $needsSave = true;
        }
        if (empty($res['template_code'])) {
            $numCode = is_numeric($res['id']) ? ((int)$res['id'] - 100) : ($idx + 1);
            if ($numCode < 1) $numCode = $idx + 1;
            $res['template_code'] = sprintf('NGW-%03d', $numCode);
            $needsSave = true;
        }
        if (empty($res['assigned_template']) || !in_array($res['assigned_template'], array('default', 'thermal', 'small'), true)) {
            $res['assigned_template'] = 'default';
            $needsSave = true;
        }
        if (empty($res['template_status']) || !in_array($res['template_status'], array('active', 'inactive'), true)) {
            $res['template_status'] = 'active';
            $needsSave = true;
        }
        if (!isset($res['logo_filename'])) {
            $res['logo_filename'] = '';
            $needsSave = true;
        }

        $perms = isset($res['permissions']) && is_array($res['permissions']) ? $res['permissions'] : getStandardSellerPermissions();
        if ($res['permission_mode'] !== 'Reset Voucher') {
            if (!isset($perms['use_assigned_template'])) {
                $perms['use_assigned_template'] = true;
            }
            if (!isset($perms['preview_assigned_template'])) {
                $perms['preview_assigned_template'] = true;
            }
        }
        $res['permissions'] = sanitizeResellerPermissions($perms);
        ensureResellerTemplateFiles($res['id']);
        $decoded[$idx] = $res;
    }

    if ($needsSave) {
        @file_put_contents($path, json_encode(array_values($decoded), JSON_PRETTY_PRINT));
    }
    $GLOBALS['NGW_CACHE_RESELLERS'] = $decoded;
    return $decoded;
}

/**
 * Find a reseller record by internal ID, legacy ID, or username.
 */
function findResellerByIdOrUsername($identifier) {
    $identifier = trim((string)$identifier);
    if ($identifier === '') {
        return null;
    }
    foreach (loadResellers() as $r) {
        if ((string)$r['id'] === $identifier || (isset($r['username']) && $r['username'] === $identifier)) {
            return $r;
        }
        if (isset($r['legacy_ids']) && is_array($r['legacy_ids']) && in_array($identifier, $r['legacy_ids'], true)) {
            return $r;
        }
    }
    return null;
}

/**
 * Generate the next sequential numeric reseller ID (101, 102, 103...)
 */
function getNextResellerId($resellers) {
    $maxId = 100;
    foreach ($resellers as $r) {
        if (isset($r['id']) && is_numeric($r['id'])) {
            $val = (int)$r['id'];
            if ($val > $maxId) {
                $maxId = $val;
            }
        }
    }
    return (string)($maxId + 1);
}

/**
 * Save resellers array to include/resellers.json
 */
function saveResellers($resellers) {
    $path = getResellersStoragePath();
    $cleanList = array_values($resellers);
    $ok = @file_put_contents($path, json_encode($cleanList, JSON_PRETTY_PRINT)) !== false;
    $GLOBALS['NGW_CACHE_RESELLERS'] = $cleanList;
    unset($GLOBALS['NGW_RESELLER_SYNCED']);
    return $ok;
}

/**
 * Verify password against a reseller record
 */
function verifyResellerPassword($reseller, $plainPassword) {
    if (!empty($reseller['password_hash']) && password_verify($plainPassword, $reseller['password_hash'])) {
        return true;
    }
    if (!empty($reseller['password_enc']) && function_exists('decrypt')) {
        if (decrypt($reseller['password_enc']) === $plainPassword) {
            return true;
        }
    }
    return false;
}

/**
 * Path to backup/secondary administrator credentials file
 */
function getBackupAdminStoragePath() {
    return dirname(__FILE__) . '/backup_admin.json';
}

/**
 * Load Secondary / Backup Admin credentials
 */
function getBackupAdminCredentials() {
    $path = getBackupAdminStoragePath();
    $default = array(
        'username' => '',
        'password_enc' => '',
        'password' => '',
    );
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    $decoded = @json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return $default;
    }
    $u = isset($decoded['username']) ? trim((string)$decoded['username']) : '';
    $pEnc = isset($decoded['password_enc']) ? (string)$decoded['password_enc'] : '';
    $pPlain = '';
    if ($pEnc !== '' && function_exists('decrypt')) {
        $pPlain = (string)decrypt($pEnc);
    } elseif (isset($decoded['password'])) {
        $pPlain = (string)$decoded['password'];
    }
    return array(
        'username' => $u,
        'password_enc' => $pEnc,
        'password' => $pPlain,
    );
}

/**
 * Save Secondary / Backup Admin credentials
 */
function saveBackupAdminCredentials($username2, $plainPassword2) {
    $username2 = trim((string)$username2);
    $plainPassword2 = (string)$plainPassword2;
    $pEnc = '';
    if ($plainPassword2 !== '' && function_exists('encrypt')) {
        $pEnc = encrypt($plainPassword2);
    }
    $data = array(
        'username' => $username2,
        'password_enc' => $pEnc,
        'updated_at' => date('Y-m-d H:i:s'),
    );
    return @file_put_contents(getBackupAdminStoragePath(), json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

/**
 * Enforce 5-minute (300 seconds) server-side inactivity timeout on page navigations
 */
function enforceMikhmonSessionSecurity($isLoginOrLogout = false) {
    if ($isLoginOrLogout || !isset($_SESSION["mikhmon"])) {
        return;
    }
    if (!empty($_SESSION['mikhmon_login_handshake'])) {
        $GLOBALS['NGW_LOGIN_HANDSHAKE'] = true;
        unset($_SESSION['mikhmon_login_handshake']);
    }
    $timeoutSeconds = 300; // 5 minutes
    $now = time();
    if (isset($_SESSION['mikhmon_last_activity'])) {
        $elapsed = $now - (int)$_SESSION['mikhmon_last_activity'];
        if ($elapsed > $timeoutSeconds) {
            $_SESSION = array();
            @session_unset();
            @session_destroy();
            echo "<script>try{sessionStorage.removeItem('ngw_tab_auth');sessionStorage.removeItem('ngw_last_act');}catch(e){} window.location='./admin.php?id=login';</script>";
            exit;
        }
    }
    $_SESSION['mikhmon_last_activity'] = $now;
}

/**
 * Authenticate user as Primary Admin, Backup/Secondary Admin, or Reseller
 */
function authenticateMikhmonUser($username, $password, $useradm, $passadm_decrypted) {
    $username = trim((string)$username);
    $password = (string)$password;

    $isPrimaryAdmin = ($username !== '' && $username === $useradm && $password === $passadm_decrypted);
    $backupAdmin = getBackupAdminCredentials();
    $isBackupAdmin = (
        !$isPrimaryAdmin &&
        $backupAdmin['username'] !== '' &&
        $backupAdmin['password'] !== '' &&
        $username === $backupAdmin['username'] &&
        $password === $backupAdmin['password']
    );

    if ($isPrimaryAdmin || $isBackupAdmin) {
        return array(
            'authenticated' => true,
            'role' => 'admin',
            'id' => 'admin',
            'username' => $username,
            'name' => $isBackupAdmin ? 'Backup Administrator' : 'Administrator',
            'permission_mode' => 'Admin',
            'template_code' => 'GLOBAL',
            'assigned_template' => 'default',
            'template_status' => 'active',
            'logo_filename' => '',
            'allowed_routers' => array('*'),
            'permissions' => getDefaultPermissionMap(true),
        );
    }

    $resellers = loadResellers();
    foreach ($resellers as $reseller) {
        if (isset($reseller['username']) && $reseller['username'] === $username) {
            if (!isset($reseller['status']) || $reseller['status'] !== 'active') {
                return array(
                    'authenticated' => false,
                    'error' => 'Reseller account is deactivated.',
                );
            }
            if (verifyResellerPassword($reseller, $password)) {
                $effectivePerms = resolveEffectiveResellerPermissions($reseller);
                return array(
                    'authenticated' => true,
                    'role' => 'reseller',
                    'id' => (string)$reseller['id'],
                    'username' => $reseller['username'],
                    'name' => isset($reseller['name']) ? $reseller['name'] : $reseller['username'],
                    'permission_mode' => isset($reseller['permission_mode']) ? $reseller['permission_mode'] : 'Custom',
                    'template_code' => isset($reseller['template_code']) ? $reseller['template_code'] : 'NGW-001',
                    'assigned_template' => isset($reseller['assigned_template']) ? $reseller['assigned_template'] : 'default',
                    'template_status' => isset($reseller['template_status']) ? $reseller['template_status'] : 'active',
                    'logo_filename' => isset($reseller['logo_filename']) ? $reseller['logo_filename'] : '',
                    'allowed_routers' => isset($reseller['allowed_routers']) && is_array($reseller['allowed_routers']) ? $reseller['allowed_routers'] : array(),
                    'permissions' => $effectivePerms,
                );
            }
        }
    }

    return array('authenticated' => false);
}

/**
 * Refresh active reseller session permissions, template assignment, logo, and router assignments
 * from disk on every request using stable internal reseller ID (with username fallback).
 */
function syncActiveResellerSession() {
    if (!isset($_SESSION['mikhmon_role']) || $_SESSION['mikhmon_role'] !== 'reseller') {
        return true;
    }
    if (!empty($GLOBALS['NGW_RESELLER_SYNCED'])) {
        return $GLOBALS['NGW_RESELLER_SYNC_RESULT'];
    }
    $resId = isset($_SESSION['mikhmon_reseller_id']) ? trim((string)$_SESSION['mikhmon_reseller_id']) : '';
    $uname = isset($_SESSION['mikhmon']) ? trim((string)$_SESSION['mikhmon']) : '';
    $resellers = loadResellers();
    foreach ($resellers as $r) {
        $matchById = ($resId !== '' && (string)$r['id'] === $resId);
        $matchByLegacy = ($resId !== '' && isset($r['legacy_ids']) && is_array($r['legacy_ids']) && in_array($resId, $r['legacy_ids'], true));
        $matchByUser = ($resId === '' && $uname !== '' && isset($r['username']) && $r['username'] === $uname);
        if ($matchById || $matchByLegacy || $matchByUser) {
            if (!isset($r['status']) || $r['status'] !== 'active') {
                session_destroy();
                $GLOBALS['NGW_RESELLER_SYNCED'] = true;
                $GLOBALS['NGW_RESELLER_SYNC_RESULT'] = false;
                return false;
            }
            $_SESSION['mikhmon'] = $r['username'];
            $_SESSION['mikhmon_reseller_id'] = (string)$r['id'];
            $_SESSION['mikhmon_permission_mode'] = isset($r['permission_mode']) ? $r['permission_mode'] : 'Custom';
            $_SESSION['mikhmon_template_code'] = isset($r['template_code']) ? $r['template_code'] : 'NGW-001';
            $_SESSION['mikhmon_assigned_template'] = isset($r['assigned_template']) ? $r['assigned_template'] : 'default';
            $_SESSION['mikhmon_template_status'] = isset($r['template_status']) ? $r['template_status'] : 'active';
            $_SESSION['mikhmon_logo_filename'] = isset($r['logo_filename']) ? $r['logo_filename'] : '';
            $_SESSION['mikhmon_routers'] = isset($r['allowed_routers']) ? $r['allowed_routers'] : array();
            $_SESSION['mikhmon_permissions'] = resolveEffectiveResellerPermissions($r);
            $GLOBALS['NGW_RESELLER_SYNCED'] = true;
            $GLOBALS['NGW_RESELLER_SYNC_RESULT'] = true;
            return true;
        }
    }
    session_destroy();
    $GLOBALS['NGW_RESELLER_SYNCED'] = true;
    $GLOBALS['NGW_RESELLER_SYNC_RESULT'] = false;
    return false;
}

/**
 * Get the authenticated reseller record (or null if Admin/unauthenticated)
 */
function getCurrentResellerRecord() {
    if (isMikhmonAdmin()) {
        return null;
    }
    if (!syncActiveResellerSession()) {
        return null;
    }
    $resId = isset($_SESSION['mikhmon_reseller_id']) ? (string)$_SESSION['mikhmon_reseller_id'] : '';
    if ($resId !== '') {
        return findResellerByIdOrUsername($resId);
    }
    $uname = isset($_SESSION['mikhmon']) ? (string)$_SESSION['mikhmon'] : '';
    return findResellerByIdOrUsername($uname);
}

/**
 * Check if current user is Administrator
 */
function isMikhmonAdmin() {
    if (!isset($_SESSION['mikhmon'])) {
        return false;
    }
    return !isset($_SESSION['mikhmon_role']) || $_SESSION['mikhmon_role'] === 'admin';
}

/**
 * Verify if current session user can access a router session
 */
function canAccessRouterSession($sessionName) {
    if (empty($sessionName)) {
        return false;
    }
    if (!syncActiveResellerSession()) {
        return false;
    }
    if (isMikhmonAdmin() || $sessionName === 'CENTRAL-RADIUS') {
        return true;
    }
    $allowed = isset($_SESSION['mikhmon_routers']) && is_array($_SESSION['mikhmon_routers']) ? $_SESSION['mikhmon_routers'] : array();
    if (in_array('*', $allowed, true) || in_array($sessionName, $allowed, true)) {
        return true;
    }
    // In Central RADIUS mode, allow access to the primary central session if reseller has any assigned NAS
    if (!empty($allowed)) {
        return true;
    }
    return false;
}

/**
 * Find the one active reseller explicitly assigned to a router session.
 * Wildcard access is not treated as ownership. If multiple resellers explicitly
 * claim the same router, return null rather than selecting an arbitrary template.
 */
function findResellerAssignedToRouterSession($sessionName) {
    $sessionName = trim((string)$sessionName);
    if ($sessionName === '') {
        return null;
    }
    $matches = array();
    foreach (loadResellers() as $reseller) {
        if (!isset($reseller['id']) || !isset($reseller['allowed_routers']) || !is_array($reseller['allowed_routers'])) {
            continue;
        }
        if (isset($reseller['status']) && $reseller['status'] !== 'active') {
            continue;
        }
        if (in_array($sessionName, $reseller['allowed_routers'], true)) {
            $matches[] = $reseller;
        }
    }
    return count($matches) === 1 ? $matches[0] : null;
}

/**
 * Verify if current session user has a specific granular permission
 */
function hasResellerPermission($permKey) {
    if (!syncActiveResellerSession()) {
        return false;
    }
    if ($permKey === 'about' || $permKey === 'view_about') {
        return true;
    }
    if (isMikhmonAdmin()) {
        return true;
    }
    if (isProtectedAdminPermission($permKey)) {
        return false;
    }
    // Alias support
    if ($permKey === 'remove_host') $permKey = 'manage_hosts';
    if ($permKey === 'remove_cookie') $permKey = 'delete_cookies';
    if ($permKey === 'manage_template_logo') $permKey = 'edit_own_template';

    $perms = isset($_SESSION['mikhmon_permissions']) && is_array($_SESSION['mikhmon_permissions']) ? $_SESSION['mikhmon_permissions'] : array();
    return !empty($perms[$permKey]);
}

/**
 * Resolve logo path for voucher printing / preview / header according to recommended asset resolution order:
 * 1. Authenticated reseller's explicitly assigned logo (uploads/reseller-logos/{id}/{logo_filename})
 * 2. Logo assigned to that reseller's template (uploads/reseller-templates/{id}/logo.png)
 * 3. Session-specific default logo (img/logo-{session}.png)
 * 4. Application-wide default logo (img/logo.png)
 */
function resolveActiveVoucherLogo($session, $relativePrefix = '../', $withCacheBust = true, $overrideResellerId = null) {
    $baseRoot = dirname(__DIR__);
    $cacheSuffix = $withCacheBust ? ('?t=' . str_replace(' ', '_', date('Y-m-d H:i:s'))) : '';

    $reseller = getCurrentResellerRecord();
    if ($reseller === null && isMikhmonAdmin() && !empty($overrideResellerId)) {
        $reseller = findResellerByIdOrUsername($overrideResellerId);
    }
    if ($reseller !== null) {
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$reseller['id']);
        $logoFile = isset($reseller['logo_filename']) ? basename((string)$reseller['logo_filename']) : '';
        if ($safeId !== '' && $logoFile !== '') {
            $absAssigned = $baseRoot . '/uploads/reseller-logos/' . $safeId . '/' . $logoFile;
            if (is_file($absAssigned) && @getimagesize($absAssigned) !== false) {
                return $relativePrefix . 'uploads/reseller-logos/' . $safeId . '/' . $logoFile . $cacheSuffix;
            }
        }
        if ($safeId !== '') {
            $absTplLogo = $baseRoot . '/uploads/reseller-templates/' . $safeId . '/logo.png';
            if (is_file($absTplLogo) && @getimagesize($absTplLogo) !== false) {
                return $relativePrefix . 'uploads/reseller-templates/' . $safeId . '/logo.png' . $cacheSuffix;
            }
        }
    }

    $safeSession = basename((string)$session);
    if ($safeSession !== '' && is_file($baseRoot . '/img/logo-' . $safeSession . '.png')) {
        return $relativePrefix . 'img/logo-' . $safeSession . '.png' . $cacheSuffix;
    }

    return $relativePrefix . 'img/logo.png' . $cacheSuffix;
}

/**
 * Resolve voucher template filesystem path for voucher/print.php and voucher/vpreview.php
 * Ensures each reseller automatically loads their own assigned/published template without affecting global files.
 */
function resolveActiveVoucherTemplatePath($requestedVariant = 'default', $overrideResellerId = null) {
    $baseRoot = dirname(__DIR__);
    $reseller = getCurrentResellerRecord();
    $isAdminPreview = false;
    if ($reseller === null && isMikhmonAdmin() && !empty($overrideResellerId)) {
        $reseller = findResellerByIdOrUsername($overrideResellerId);
        $isAdminPreview = true;
    }

    $variantMap = array(
        'default'  => 'template.php',
        'thermal'  => 'template-thermal.php',
        'small'    => 'template-small.php',
    );

    if ($reseller !== null) {
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$reseller['id']);
        $tplStatus = isset($reseller['template_status']) ? $reseller['template_status'] : 'active';
        $canUse = $isAdminPreview ? true : (hasResellerPermission('use_assigned_template') || hasResellerPermission('preview_assigned_template'));

        if ($safeId !== '' && $tplStatus === 'active' && $canUse) {
            $effectiveVariant = $requestedVariant;
            if ($requestedVariant === 'assigned' && !empty($reseller['assigned_template']) && isset($variantMap[$reseller['assigned_template']])) {
                $effectiveVariant = $reseller['assigned_template'];
            }
            $fileName = isset($variantMap[$effectiveVariant]) ? $variantMap[$effectiveVariant] : 'template.php';
            ensureResellerTemplateFiles($safeId);
            $resellerTplPath = $baseRoot . '/uploads/reseller-templates/' . $safeId . '/' . $fileName;
            if (is_file($resellerTplPath) && is_readable($resellerTplPath)) {
                return $resellerTplPath;
            }
        }
    }

    $fallbackVariant = ($requestedVariant === 'assigned') ? 'default' : $requestedVariant;
    $fallbackFile = isset($variantMap[$fallbackVariant]) ? $variantMap[$fallbackVariant] : 'template.php';
    return $baseRoot . '/voucher/' . $fallbackFile;
}

/**
 * Validate and store a reseller-specific logo upload safely inside uploads/reseller-logos/{reseller_id}/
 */
function handleResellerLogoUpload($resellerId, $fileInput) {
    $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$resellerId);
    if ($safeId === '') {
        return array('ok' => false, 'error' => 'Invalid reseller ID.');
    }
    if (!isset($fileInput['tmp_name']) || !is_uploaded_file($fileInput['tmp_name'])) {
        return array('ok' => false, 'error' => 'No valid uploaded file received.');
    }
    if ($fileInput['size'] > 2 * 1024 * 1024) {
        return array('ok' => false, 'error' => 'Logo file exceeds maximum size of 2 MB.');
    }

    $imgInfo = @getimagesize($fileInput['tmp_name']);
    if ($imgInfo === false) {
        return array('ok' => false, 'error' => 'Uploaded file is not a valid image.');
    }
    $width = (int)$imgInfo[0];
    $height = (int)$imgInfo[1];
    $imgType = (int)$imgInfo[2];

    if ($width < 10 || $height < 10 || $width > 2400 || $height > 2400) {
        return array('ok' => false, 'error' => 'Image dimensions must be between 10x10 and 2400x2400 pixels.');
    }

    $allowedTypes = array(
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
    );
    if (defined('IMAGETYPE_WEBP')) {
        $allowedTypes[IMAGETYPE_WEBP] = 'webp';
    }
    if (!isset($allowedTypes[$imgType])) {
        return array('ok' => false, 'error' => 'Only PNG, JPEG, and WebP images are supported.');
    }

    $ext = $allowedTypes[$imgType];
    ensureResellerRuntimeDirectories();
    $baseRoot = dirname(__DIR__);
    $targetDir = $baseRoot . '/uploads/reseller-logos/' . $safeId;
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }
    // Remove previous logo files for this reseller
    foreach (glob($targetDir . '/logo.*') as $oldFile) {
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }
    $safeFilename = 'logo.' . $ext;
    $destPath = $targetDir . '/' . $safeFilename;
    if (!@move_uploaded_file($fileInput['tmp_name'], $destPath)) {
        return array('ok' => false, 'error' => 'Failed to save uploaded logo on server.');
    }
    return array('ok' => true, 'filename' => $safeFilename);
}

/**
 * Remove a reseller's custom uploaded logo and revert to default logo fallback
 */
function removeResellerCustomLogo($resellerId) {
    $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$resellerId);
    if ($safeId === '') {
        return false;
    }
    $baseRoot = dirname(__DIR__);
    $targetDir = $baseRoot . '/uploads/reseller-logos/' . $safeId;
    if (is_dir($targetDir)) {
        foreach (glob($targetDir . '/logo.*') as $oldFile) {
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }
    }
    $resellers = loadResellers();
    foreach ($resellers as $idx => $r) {
        if ((string)$r['id'] === $safeId) {
            $resellers[$idx]['logo_filename'] = '';
        }
    }
    return saveResellers($resellers);
}

/**
 * Check if the logged-in reseller ONLY has the Reset Voucher permission (and no other operational menu/view permissions).
 * When true, the reseller is strictly isolated to the Reset Voucher interface.
 */
function isResetOnlyReseller() {
    if (!isset($_SESSION['mikhmon']) || isMikhmonAdmin()) {
        return false;
    }
    if (!syncActiveResellerSession()) {
        return false;
    }
    if (!hasResellerPermission('reset_voucher')) {
        return false;
    }
    $mode = isset($_SESSION['mikhmon_permission_mode']) ? trim((string)$_SESSION['mikhmon_permission_mode']) : '';
    if ($mode === 'Reset Voucher') {
        return true;
    }
    $otherOperationalKeys = array(
        'view_dashboard',
        'view_users',
        'add_user',
        'generate_voucher',
        'edit_user',
        'delete_user',
        'enable_disable_user',
        'print_voucher',
        'manage_quickprint',
        'export_users',
        'view_profiles',
        'add_profile',
        'edit_profile',
        'delete_profile',
        'view_active',
        'disconnect_active',
        'view_hosts',
        'manage_hosts',
        'view_ipbinding',
        'manage_ipbinding',
        'view_cookies',
        'delete_cookies',
        'view_dhcp',
        'view_logs',
        'view_report',
        'export_report',
        'delete_report',
        'view_traffic',
        'view_scheduler',
        'manage_scheduler',
        'reboot_shutdown',
        'manage_router_settings',
        'view_ppp',
        'manage_ppp',
        'edit_own_template',
        'save_own_template',
        'publish_own_template',
        'restore_own_template',
    );
    $perms = isset($_SESSION['mikhmon_permissions']) && is_array($_SESSION['mikhmon_permissions']) ? $_SESSION['mikhmon_permissions'] : array();
    foreach ($otherOperationalKeys as $k) {
        if (!empty($perms[$k])) {
            return false;
        }
    }
    return true;
}

/**
 * Determine the first permitted landing URL for a reseller on a given router session (or sessions list).
 */
function getResellerDefaultFallbackUrl($session = '') {
    $safeSession = trim((string)$session);
    if ($safeSession === '' && function_exists('getDefaultCentralRadiusSession')) {
        $safeSession = getDefaultCentralRadiusSession();
    }
    if (isResetOnlyReseller()) {
        return './admin.php?id=reset-voucher' . ($safeSession !== '' && canAccessRouterSession($safeSession) ? '&session=' . urlencode($safeSession) : '');
    }
    if ($safeSession !== '' && canAccessRouterSession($safeSession)) {
        if (hasResellerPermission('view_dashboard')) {
            return './?session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_users')) {
            return './?hotspot=users&profile=all&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('generate_voucher')) {
            return './?hotspot-user=generate&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('add_user')) {
            return './?hotspot-user=add&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('reset_voucher')) {
            return './?hotspot=reset-voucher&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('print_voucher')) {
            return './?hotspot=quick-print&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_profiles')) {
            return './?hotspot=user-profiles&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_active')) {
            return './?hotspot=active&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_hosts')) {
            return './?hotspot=hosts&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_ipbinding')) {
            return './?hotspot=ipbinding&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_cookies')) {
            return './?hotspot=cookies&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_report')) {
            return './?report=selling&idbl=' . strtolower(date("M")) . date("Y") . '&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_logs')) {
            return './?hotspot=log&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_traffic')) {
            return './?interface=traffic-monitor&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_dhcp')) {
            return './?hotspot=dhcp-leases&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('view_scheduler')) {
            return './?system=scheduler&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('edit_own_template')) {
            return './?hotspot=template-editor&template=default&session=' . urlencode($safeSession);
        }
        if (hasResellerPermission('save_own_template')) {
            return './?hotspot=uplogo&session=' . urlencode($safeSession);
        }
        return './?hotspot=about&session=' . urlencode($safeSession);
    }
    return './admin.php?id=sessions';
}

/**
 * Enforce permission server-side and silently redirect to an authorized page if unauthorized
 */
function requireResellerPermission($permKey, $redirectUrl = './admin.php?id=sessions') {
    if (!hasResellerPermission($permKey)) {
        if (isResetOnlyReseller()) {
            $redirectUrl = './admin.php?id=reset-voucher';
        } else {
            $sess = isset($_GET['session']) ? trim((string)$_GET['session']) : '';
            if ($sess !== '' && canAccessRouterSession($sess)) {
                $fallback = getResellerDefaultFallbackUrl($sess);
                if (($redirectUrl === './?session=' . $sess || $redirectUrl === './admin.php?id=sessions') && !hasResellerPermission('view_dashboard')) {
                    $redirectUrl = $fallback;
                }
            }
        }
        if (!headers_sent()) {
            header('Location: ' . $redirectUrl);
        }
        echo "<script>window.location='" . htmlspecialchars($redirectUrl, ENT_QUOTES) . "'</script>";
        exit;
    }
}
?>

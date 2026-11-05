<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *  NextGan WiFi Solution — Unified Template Editor & Integrated Logo Manager
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 */
session_start();
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
	header("Location:../admin.php?id=login");
	exit;
}

// Load router configuration and access control before resolving a preview session.
$session = isset($_GET['session']) ? trim((string)$_GET['session']) : '';
include_once('./include/config.php');
include_once('./include/reseller_acl.php');
$isAdminUser = isMikhmonAdmin();

// Template previews need a real, accessible RouterOS session for router-specific
// variables. Preserve the selected session; otherwise pick the first accessible one.
if (!$isAdminUser && $session !== '' && !canAccessRouterSession($session)) {
    $session = '';
}
if ($session === '' && isset($data) && is_array($data)) {
    foreach (array_keys($data) as $candidateSession) {
        if ($candidateSession === 'mikhmon' || !isset($data[$candidateSession]) || !is_array($data[$candidateSession])) {
            continue;
        }
        if ($isAdminUser || canAccessRouterSession((string)$candidateSession)) {
            $session = (string)$candidateSession;
            break;
        }
    }
}
include_once('./include/readcfg.php');

$url = $_SERVER['REQUEST_URI'];
$telplate = isset($_GET['template']) && $_GET['template'] !== '' ? $_GET['template'] : 'default';

$isAdminUser = isMikhmonAdmin();
$canEditTemplate = $isAdminUser || hasResellerPermission('edit_own_template') || hasResellerPermission('save_own_template');
$currentReseller = getCurrentResellerRecord();
$targetReseller = null;

if (!$isAdminUser) {
	if (!hasResellerPermission('edit_own_template') && !hasResellerPermission('preview_assigned_template')) {
		requireResellerPermission('edit_own_template', './?session=' . urlencode($session));
	}
	$targetReseller = $currentReseller;
} elseif (!empty($_GET['reseller_id']) && $_GET['reseller_id'] !== 'global') {
	$targetReseller = findResellerByIdOrUsername($_GET['reseller_id']);
}

$targetResId = ($targetReseller !== null && isset($targetReseller['id']))
	? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$targetReseller['id'])
	: '';

if ($targetResId !== '') {
	ensureResellerTemplateFiles($targetResId);
}

$resQuerySuffix = ($isAdminUser && $targetResId !== '') ? ('&reseller_id=' . urlencode($targetResId)) : '';
$editorRouteBase = (isset($_GET['hotspot']) && $_GET['hotspot'] === 'template-editor')
	? './?hotspot=template-editor'
	: './admin.php?id=editor';

if ($telplate == "thermal") {
	$telplatet = "template-thermal";
	$defaultSource = "default-thermal";
	$popup = "./voucher/vpreview.php?usermode=up&user=m&qr=no&variant=thermal&session=" . urlencode($session) . $resQuerySuffix;
	$popupQR = "./voucher/vpreview.php?usermode=up&user=m&qr=yes&variant=thermal&session=" . urlencode($session) . $resQuerySuffix;
} elseif ($telplate == "small") {
	$telplatet = "template-small";
	$defaultSource = "default-small";
	$popup = "./voucher/vpreview.php?usermode=up&small=yes&qr=no&variant=small&session=" . urlencode($session) . $resQuerySuffix;
	$popupQR = "./voucher/vpreview.php?usermode=up&small=yes&qr=yes&variant=small&session=" . urlencode($session) . $resQuerySuffix;
} else {
	$telplate = "default";
	$telplatet = "template";
	$defaultSource = "default";
	$popup = "./voucher/vpreview.php?usermode=up&qr=no&variant=default&session=" . urlencode($session) . $resQuerySuffix;
	$popupQR = "./voucher/vpreview.php?usermode=up&qr=yes&variant=default&session=" . urlencode($session) . $resQuerySuffix;
}

$editorMsg = '';
$editorErr = '';

// ============================================================================
// 1. ACTION HANDLER: RESET TO SYSTEM DEFAULT (TEMPLATE & LOGO)
// ============================================================================
if (isset($_POST['reset_to_default'])) {
	if ($targetResId !== '') {
		// Reseller scope reset
		$canReset = $isAdminUser || hasResellerPermission('edit_own_template');
		if (!$canReset) {
			$editorErr = 'Access Denied: You do not have permission to reset template.';
		} else {
			$resTplDir = './uploads/reseller-templates/' . $targetResId;
			if (!is_dir($resTplDir)) {
				@mkdir($resTplDir, 0755, true);
			}
			// Overwrite all templates for this reseller with clean system defaults
			@copy('./voucher/default.php', $resTplDir . '/template.php');
			@copy('./voucher/default-thermal.php', $resTplDir . '/template-thermal.php');
			@copy('./voucher/default-small.php', $resTplDir . '/template-small.php');
			// Clean backup / draft files
			@unlink($resTplDir . '/template.bak.php');
			@unlink($resTplDir . '/template-thermal.bak.php');
			@unlink($resTplDir . '/template-small.bak.php');
			@unlink($resTplDir . '/logo.png');

			// Remove reseller custom logo
			removeResellerCustomLogo($targetResId);
			if (isset($_SESSION['mikhmon_reseller_id']) && (string)$_SESSION['mikhmon_reseller_id'] === (string)$targetResId) {
				$_SESSION['mikhmon_logo_filename'] = '';
			}
			$targetReseller = findResellerByIdOrUsername($targetResId);
			$editorMsg = 'Reseller template and logo successfully reset to default!';
		}
	} else {
		// Global scope reset (Admin only)
		if (!$isAdminUser || !hasResellerPermission('manage_global_templates')) {
			$editorErr = 'Access Denied: Only Administrator can reset global templates.';
		} else {
			@copy('./voucher/default.php', './voucher/template.php');
			@copy('./voucher/default-thermal.php', './voucher/template-thermal.php');
			@copy('./voucher/default-small.php', './voucher/template-small.php');
			$editorMsg = 'Global template files reset to system default!';
		}
	}
}

// ============================================================================
// 2. ACTION HANDLER: INLINE LOGO UPLOAD
// ============================================================================
elseif (isset($_POST['upload_logo']) && isset($_FILES['voucher_logo'])) {
	if ($targetResId !== '') {
		$canUpload = $isAdminUser || hasResellerPermission('edit_own_template') || hasResellerPermission('save_own_template');
		if (!$canUpload) {
			$editorErr = 'Access Denied: You do not have permission to upload logo.';
		} else {
			$upRes = handleResellerLogoUpload($targetResId, $_FILES['voucher_logo']);
			if ($upRes['ok']) {
				$allRes = loadResellers();
				foreach ($allRes as $idx => $rItem) {
					if ((string)$rItem['id'] === (string)$targetResId) {
						$allRes[$idx]['logo_filename'] = $upRes['filename'];
					}
				}
				saveResellers($allRes);
				if (isset($_SESSION['mikhmon_reseller_id']) && (string)$_SESSION['mikhmon_reseller_id'] === (string)$targetResId) {
					$_SESSION['mikhmon_logo_filename'] = $upRes['filename'];
				}
				$targetReseller = findResellerByIdOrUsername($targetResId);
				$editorMsg = 'Logo (' . htmlspecialchars($upRes['filename']) . ') successfully uploaded for reseller ' . htmlspecialchars($targetReseller['name']) . '!';
			} else {
				$editorErr = $upRes['error'];
			}
		}
	} else {
		if (!$isAdminUser) {
			$editorErr = 'Access Denied: Only Administrator can modify global logo.';
		} else {
			$logoFile = $_FILES['voucher_logo'];
			$imgInfo = @getimagesize($logoFile['tmp_name']);
			if ($imgInfo === false) {
				$editorErr = 'Uploaded file is not a valid image.';
			} elseif ($logoFile['size'] > 2 * 1024 * 1024) {
				$editorErr = 'Logo file size exceeds 2 MB limit.';
			} else {
				$dest = !empty($session) ? ('./img/logo-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $session) . '.png') : './img/logo.png';
				if (@move_uploaded_file($logoFile['tmp_name'], $dest)) {
					if (is_dir('./images')) {
						@copy($dest, './images/' . basename($dest));
					}
					$editorMsg = 'Global/Session logo successfully updated!';
				} else {
					$editorErr = 'Failed to save uploaded logo on server.';
				}
			}
		}
	}
}

// ============================================================================
// 3. ACTION HANDLER: REMOVE CUSTOM LOGO
// ============================================================================
elseif (isset($_POST['remove_logo'])) {
	if (!$isAdminUser && !hasResellerPermission('edit_own_template') && !hasResellerPermission('save_own_template')) {
		$editorErr = 'Access Denied: You do not have permission to remove logos.';
	} elseif ($targetResId !== '') {
		removeResellerCustomLogo($targetResId);
		@unlink('./uploads/reseller-templates/' . $targetResId . '/logo.png');
		if (isset($_SESSION['mikhmon_reseller_id']) && (string)$_SESSION['mikhmon_reseller_id'] === (string)$targetResId) {
			$_SESSION['mikhmon_logo_filename'] = '';
		}
		$targetReseller = findResellerByIdOrUsername($targetResId);
		$editorMsg = 'Custom logo removed. Reverted to default system logo.';
	} else {
		if (!empty($session) && is_file('./img/logo-' . $session . '.png')) {
			@unlink('./img/logo-' . $session . '.png');
			$editorMsg = 'Session logo removed. Reverted to global default logo.';
		} else {
			$editorMsg = 'Using global default logo.';
		}
	}
}

// ============================================================================
// 4. ACTION HANDLER: SAVE TEMPLATE
// ============================================================================
elseif (isset($_POST['save'])) {
	$data = isset($_POST['editor']) ? $_POST['editor'] : '';
	if ($targetResId !== '') {
		$canSave = $isAdminUser || hasResellerPermission('save_own_template') || hasResellerPermission('edit_own_template');
		if (!$canSave) {
			$editorErr = 'Access Denied: You do not have save_own_template permission.';
		} else {
			$resTplDir = './uploads/reseller-templates/' . $targetResId;
			if (!is_dir($resTplDir)) {
				@mkdir($resTplDir, 0755, true);
			}
			$activePath = $resTplDir . '/' . $telplatet . '.php';
			if (@file_put_contents($activePath, $data) !== false) {
				$editorMsg = 'Template saved for reseller ' . htmlspecialchars($targetReseller['name']) . ' (' . htmlspecialchars($targetReseller['template_code']) . ').';
			} else {
				$editorErr = 'Cannot write template file: ' . htmlspecialchars($activePath);
			}
		}
	} else {
		if (!$isAdminUser || !hasResellerPermission('manage_global_templates')) {
			$editorErr = 'Access Denied: Only Administrator can modify global voucher templates.';
		} else {
			$template = './voucher/' . $telplatet . '.php';
			$handle = @fopen($template, 'w');
			if ($handle) {
				fwrite($handle, $data);
				fclose($handle);
				$editorMsg = 'Global template (' . htmlspecialchars($telplatet) . '.php) saved.';
			} else {
				$editorErr = 'Cannot open file: ' . htmlspecialchars($template);
			}
		}
	}
}

// Resolve active content to load in editor
$loadedTemplateContent = '';
if ($targetResId !== '') {
	$resFile = './uploads/reseller-templates/' . $targetResId . '/' . $telplatet . '.php';
	if (is_file($resFile)) {
		$loadedTemplateContent = @file_get_contents($resFile);
	} else {
		$loadedTemplateContent = @file_get_contents('./voucher/' . $telplatet . '.php');
	}
} else {
	$loadedTemplateContent = @file_get_contents('./voucher/' . $telplatet . '.php');
}
if ($loadedTemplateContent === false || $loadedTemplateContent === '') {
	$loadedTemplateContent = @file_get_contents('./voucher/' . $defaultSource . '.php');
}

// Resolve active logo and custom status
$activeLogoUrl = resolveActiveVoucherLogo($session, './', true, $targetResId);
$hasCustomLogo = false;
if ($targetResId !== '') {
	$resLogoFile = isset($targetReseller['logo_filename']) ? (string)$targetReseller['logo_filename'] : '';
	if ($resLogoFile !== '' && is_file('./uploads/reseller-logos/' . $targetResId . '/' . $resLogoFile)) {
		$hasCustomLogo = true;
	} elseif (is_file('./uploads/reseller-templates/' . $targetResId . '/logo.png')) {
		$hasCustomLogo = true;
	}
} elseif (!empty($session) && is_file('./img/logo-' . $session . '.png')) {
	$hasCustomLogo = true;
}
?>

<!-- CodeMirror & styles -->
<link rel="stylesheet" href="./css/editor.min.css">
<script src="./js/editor.min.js"></script>

<style>
.CodeMirror {
  border: 1px solid #2f353a;
  height: 520px;
}
textarea {
  font-size: 12px;
  border: 1px solid #2f353a;
}
.tpl-logo-preview-box {
  background: #f8f9fa;
  border: 1px dashed #ced4da;
  border-radius: 6px;
  padding: 10px;
  text-align: center;
  min-height: 80px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 8px;
}
</style>

<div class="row">
	<div class="col-9">
		<div class="card">
			<div class="card-header">
				<h3>
					<i class="fa fa-edit"></i> <?= isset($_template_editor) ? $_template_editor : 'Template Editor'; ?>
					<?php if ($targetReseller !== null) { ?>
						<span style="font-size:12px; margin-left:8px; padding:3px 10px; border-radius:4px; background:#1f6feb; color:#fff; font-weight:normal;">
							Reseller #<?= htmlspecialchars($targetReseller['id']); ?> | <?= htmlspecialchars($targetReseller['name']); ?> (<?= htmlspecialchars($targetReseller['template_code']); ?>)
						</span>
					<?php } else { ?>
						<span style="font-size:12px; margin-left:8px; padding:3px 10px; border-radius:4px; background:#2ea043; color:#fff; font-weight:normal;">
							Global Scope
						</span>
					<?php } ?>
				</h3>
			</div>

			<div class="card-body">
				<?php if ($editorMsg !== '') { ?>
					<div style="padding:10px 14px; margin-bottom:12px; border-radius:4px; color:#fff;" class="bg-success">
						<i class="fa fa-check-circle"></i> <?= $editorMsg; ?>
					</div>
				<?php } ?>
				<?php if ($editorErr !== '') { ?>
					<div style="padding:10px 14px; margin-bottom:12px; border-radius:4px; color:#fff;" class="bg-danger">
						<i class="fa fa-ban"></i> <?= $editorErr; ?>
					</div>
				<?php } ?>

				<!-- Top Action Toolbar -->
				<form autocomplete="off" method="post" action="" enctype="multipart/form-data">
					<table class="table" style="margin-bottom:10px;">
						<tr>
							<td style="padding:4px 0;">
								<div class="row" style="align-items:center;">
									<div class="col-6 col-box-12" style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
										<?php if ($isAdminUser || hasResellerPermission('save_own_template') || hasResellerPermission('edit_own_template')) { ?>
											<button type="submit" title="Save Template" class="btn bg-primary" name="save">
												<i class="fa fa-save"></i> <?= $_save ?>
											</button>
										<?php } ?>

										<?php if ($canEditTemplate) { ?>
<button type="submit" name="reset_to_default" class="btn bg-danger" onclick="return confirm('Are you sure you want to reset this template and logo to clean system defaults?');" title="Reset template and logo to system defaults">
											<i class="fa fa-refresh"></i> Reset to Default
										</button>
<?php } ?>

										<a class="btn bg-green" href="<?= htmlspecialchars($popup, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" title="Preview voucher with Logo">
											<i class="fa fa-image"></i> Voucher
										</a>
										<a class="btn bg-green" href="<?= htmlspecialchars($popupQR, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" title="Preview voucher with QR">
											<i class="fa fa-qrcode"></i> QR
										</a>
									</div>

									<div class="col-6 col-box-12 pd-t-5 pd-b-5">
										<div class="input-group" style="justify-content:flex-end;">
											<?php if ($isAdminUser) {
												$allResList = loadResellers();
											?>
												<div class="input-group-4">
													<select style="padding:5px;" class="group-item group-item-l" onchange="window.location.href=this.value;">
														<option value="<?= $editorRouteBase; ?>&template=<?= urlencode($telplate); ?>&session=<?= urlencode($session); ?>&reseller_id=global" <?= $targetResId === '' ? 'selected' : ''; ?>>Scope: Global</option>
														<?php foreach ($allResList as $rOpt) { ?>
															<option value="<?= $editorRouteBase; ?>&template=<?= urlencode($telplate); ?>&session=<?= urlencode($session); ?>&reseller_id=<?= urlencode($rOpt['id']); ?>" <?= (string)$rOpt['id'] === $targetResId ? 'selected' : ''; ?>>
																#<?= htmlspecialchars($rOpt['id']); ?> <?= htmlspecialchars($rOpt['username']); ?> (<?= htmlspecialchars($rOpt['template_code']); ?>)
															</option>
														<?php } ?>
													</select>
												</div>
											<?php } ?>

											<div class="input-group-2">
												<div class="group-item group-item-m pd-2p5 text-center" style="font-size:12px;">Template</div>
											</div>
											<div class="input-group-3">
												<select style="padding:5px;" class="group-item group-item-r" onchange="window.location.href=this.value+'&session=<?= urlencode($session) . $resQuerySuffix; ?>';">
													<option value="<?= $editorRouteBase; ?>&template=default" <?= $telplate === 'default' ? 'selected' : ''; ?>>Default</option>
													<option value="<?= $editorRouteBase; ?>&template=thermal" <?= $telplate === 'thermal' ? 'selected' : ''; ?>>Thermal</option>
													<option value="<?= $editorRouteBase; ?>&template=small" <?= $telplate === 'small' ? 'selected' : ''; ?>>Small</option>
												</select>
											</div>
										</div>
									</div>
								</div>
							</td>
						</tr>
					</table>

					<!-- CodeMirror Editor Textarea -->
					<textarea class="bg-dark" id="editotMikhmon" name="editor" style="width:100%" height="700" <?= $canEditTemplate ? '' : 'readonly'; ?>><?= htmlspecialchars($loadedTemplateContent); ?></textarea>
				</form>
			</div>
		</div>
	</div>

	<!-- Right Column: Integrated Logo Manager & Variable Reference -->
	<div class="col-3">
		<!-- Integrated Logo Upload & Preview Panel -->
		<div class="card" style="margin-bottom:15px;">
			<div class="card-header" style="background:#202a35; color:#fff;">
				<h3 style="font-size:14px; margin:0;"><i class="fa fa-picture-o"></i> Voucher Logo Manager</h3>
			</div>
			<div class="card-body" style="padding:12px;">
				<div class="tpl-logo-preview-box">
					<img src="<?= htmlspecialchars($activeLogoUrl); ?>" alt="Voucher Logo Preview" style="max-height:65px; max-width:100%; object-fit:contain;">
				</div>
				<div style="font-size:11px; margin-bottom:10px; text-align:center;">
					<?php if ($hasCustomLogo) { ?>
						<span style="background:#1f6feb; color:#fff; padding:2px 8px; border-radius:3px; font-weight:bold;">Custom Logo Active</span>
					<?php } else { ?>
						<span style="background:#6c757d; color:#fff; padding:2px 8px; border-radius:3px;">System Default Logo</span>
					<?php } ?>
				</div>

				<form autocomplete="off" method="post" action="" enctype="multipart/form-data">
					<div style="margin-bottom:8px;">
						<input type="file" name="voucher_logo" accept="image/png,image/jpeg,image/webp" style="font-size:11px; width:100%; padding:4px;" required>
						<span style="font-size:10px; color:#888;">PNG, JPG, or WebP (Max 2MB)</span>
					</div>
					<div style="display:flex; gap:5px; flex-direction:column;">
						<button type="submit" name="upload_logo" class="btn bg-purple" style="width:100%; font-size:12px; padding:6px;">
							<i class="fa fa-upload"></i> Upload Logo
						</button>
						<?php if ($hasCustomLogo) { ?>
							<button type="submit" name="remove_logo" class="btn bg-warning" onclick="return confirm('Remove custom logo and revert to system default?');" style="width:100%; font-size:11px; padding:4px;">
								<i class="fa fa-trash"></i> Remove Custom Logo
							</button>
						<?php } ?>
					</div>
				</form>
			</div>
		</div>

		<!-- Variable Reference Card -->
		<div class="card">
			<div class="card-header">
				<h3 style="font-size:14px; margin:0;"><i class="fa fa-code"></i> Variables</h3>
			</div>
			<div class="card-body" style="padding:10px;">
				<textarea id="var" class="bg-dark" readonly rows="22" style="width:100%; font-size:11px;" disabled><?= htmlspecialchars(file_get_contents('./voucher/variable.php')); ?></textarea>
			</div>
		</div>
	</div>
</div>

<script>
(function(){
  if (typeof (Storage) !== 'undefined') {
    var _msEl = document.getElementById('MikhmonSession');
    if (_msEl) {
      sessionStorage.setItem('MikhmonSession', _msEl.innerHTML);
    }
  }
  var textareaEl = document.getElementById('editotMikhmon') || document.getElementById('editorMikhmon');
  if (textareaEl && typeof CodeMirror !== 'undefined') {
    try {
      var editor = CodeMirror.fromTextArea(textareaEl, {
        lineNumbers: true,
        matchBrackets: true,
        mode: 'application/x-httpd-php',
        indentUnit: 4,
        indentWithTabs: true,
        lineWrapping: true,
        viewportMargin: Infinity,
        matchTags: { bothTags: true },
        extraKeys: { 'Ctrl-J': 'toMatchingTag' }
      });
      if (editor && typeof editor.setOption === 'function') {
        editor.setOption('theme', 'material');
      }
    } catch(err) {
      console.warn('CodeMirror failed to initialize:', err);
    }
  }
})();
</script>

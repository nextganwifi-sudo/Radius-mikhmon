<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *  NextGan WiFi Solution — Global & Per-Reseller Logo Upload Manager
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 */

// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  include_once('./include/reseller_acl.php');
  $isAdminUser = isMikhmonAdmin();
  $currentReseller = getCurrentResellerRecord();
  $allResellersList = $isAdminUser ? loadResellers() : array();

  $uplogoRouteBase = (isset($_GET['hotspot']) && $_GET['hotspot'] === 'uplogo')
    ? './?hotspot=uplogo'
    : './admin.php?id=uplogo';
  $editorRouteBase = (isset($_GET['hotspot']) && $_GET['hotspot'] === 'uplogo')
    ? './?hotspot=template-editor'
    : './admin.php?id=editor';

  $targetReseller = null;
  if (!$isAdminUser) {
    $targetReseller = $currentReseller;
  } elseif (!empty($_GET['reseller_id']) && $_GET['reseller_id'] !== 'global') {
    $targetReseller = findResellerByIdOrUsername($_GET['reseller_id']);
  }

  $targetResId = ($targetReseller !== null && isset($targetReseller['id']))
    ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$targetReseller['id'])
    : '';

  if ($targetResId !== '') {
    // Reseller-isolated logo upload handler (works for both Admin managing a reseller and Reseller managing own logo)
    if (isset($_POST["submit"]) && isset($_FILES["UploadLogo"])) {
      $upRes = handleResellerLogoUpload($targetResId, $_FILES["UploadLogo"]);
      if ($upRes['ok']) {
        $allRes = loadResellers();
        foreach ($allRes as $idx => $rItem) {
          if ((string)$rItem['id'] === (string)$targetResId) {
            $allRes[$idx]['logo_filename'] = $upRes['filename'];
          }
        }
        saveResellers($allRes);
        $targetReseller = findResellerByIdOrUsername($targetResId);
        if (!$isAdminUser) {
          $currentReseller = getCurrentResellerRecord();
        }
        $allResellersList = $isAdminUser ? loadResellers() : array();
        $galat = '<div class="box bg-success"><i class="fa fa-check-circle"></i> Success! Reseller logo (<b>' . htmlspecialchars($upRes['filename']) . '</b>) uploaded for <b>' . htmlspecialchars($targetReseller['name']) . '</b> (#' . htmlspecialchars($targetResId) . ').</div>';
      } else {
        $galat = '<div class="box bg-danger"><i class="fa fa-ban"></i> Alert!<br> ' . htmlspecialchars($upRes['error']) . '</div>';
      }
    } elseif (isset($_POST["reset_reseller_logo"])) {
      removeResellerCustomLogo($targetResId);
      $targetReseller = findResellerByIdOrUsername($targetResId);
      if (!$isAdminUser) {
        $currentReseller = getCurrentResellerRecord();
      }
      $allResellersList = $isAdminUser ? loadResellers() : array();
      $galat = '<div class="box bg-success"><i class="fa fa-check-circle"></i> Reseller custom logo removed. Reverting to default session/global logo.</div>';
    }
  } else {
    // Administrator session/global logo upload handler (img/logo-{session}.png)
    if (isset($_POST["submit"]) && isset($_FILES["UploadLogo"])) {
      $logo_dir = "./img/";
      $uploadedName = basename($_FILES["UploadLogo"]["name"]);
      // Automatically normalize filename to logo-{session}.png if another image name was selected
      if (substr($uploadedName, 0, 5) === "logo-" && strtolower(substr($uploadedName, -4)) === ".png") {
        $finalLogoName = $uploadedName;
      } else {
        $finalLogoName = "logo-" . $session . ".png";
      }
      $logo_file = $logo_dir . $finalLogoName;
      $uploadOk = 1;

      $check = @getimagesize($_FILES["UploadLogo"]["tmp_name"]);
      if ($check !== false) {
        $uploadOk = 1;
      } else {
        $galat = '<div class="box bg-danger"><i class="fa fa-ban"></i> Alert!<br> File is not a valid image.</div>';
        $uploadOk = 0;
      }

      if ($_FILES["UploadLogo"]["size"] > 2000000) {
        $galat = '<div class="box bg-danger"><i class="fa fa-ban"></i> Alert!<br> File is too large (Max 2 MB).</div>';
        $uploadOk = 0;
      }

      if ($uploadOk != 0) {
        if (@move_uploaded_file($_FILES["UploadLogo"]["tmp_name"], $logo_file)) {
          if (is_dir('./images')) {
            @copy($logo_file, './images/' . $finalLogoName);
          }
          $galat = '<div class="box bg-success"><i class="fa fa-check-circle"></i> Success! Logo saved as <b>' . htmlspecialchars($finalLogoName) . '</b>.</div>';
        } else {
          $galat = '<div class="box bg-danger"><i class="fa fa-ban"></i> Alert!<br> There was an error uploading your file.</div>';
        }
      }
    }
  }
}
?>
<div class="row">
<div class="col-12">
  <div class="card">
    <div class="card-header">
        <h3 class="card-title">
          <i class="fa fa-upload"></i> <?= $_upload_logo ?>
          <?php if ($targetReseller !== null) { ?>
            <span style="font-size:12px; margin-left:8px; padding:2px 8px; border-radius:3px; background:#1f6feb; color:#fff;">
              Reseller #<?= htmlspecialchars($targetReseller['id']); ?> | <?= htmlspecialchars($targetReseller['name']); ?> (<?= htmlspecialchars($targetReseller['template_code']); ?>)
            </span>
          <?php } else { ?>
            <span style="font-size:12px; margin-left:8px; padding:2px 8px; border-radius:3px; background:#2ea043; color:#fff;">
              Global / Router Session (logo-<?= htmlspecialchars($session); ?>.png)
            </span>
          <?php } ?>
        </h3>
    </div>
    <div class="card-body">
      <div>
      <?= $galat; ?>

      <?php if ($isAdminUser) { ?>
      <div class="row mr-b-10">
        <div class="col-6 col-box-12">
          <div class="input-group">
            <div class="input-group-4">
              <div class="group-item group-item-l pd-2p5 text-center"><b><?= htmlspecialchars(isset($_select_reseller_scope) ? $_select_reseller_scope : 'Target Scope'); ?></b></div>
            </div>
            <div class="input-group-8">
              <select class="group-item group-item-r" onchange="window.location.href=this.value;">
                <option value="<?= $uplogoRouteBase; ?>&session=<?= urlencode($session); ?>&reseller_id=global" <?= $targetResId === '' ? 'selected' : ''; ?>>
                  Global / Session Logo (logo-<?= htmlspecialchars($session); ?>.png)
                </option>
                <?php foreach ($allResellersList as $rOpt) { ?>
                  <option value="<?= $uplogoRouteBase; ?>&session=<?= urlencode($session); ?>&reseller_id=<?= urlencode($rOpt['id']); ?>" <?= (string)$rOpt['id'] === $targetResId ? 'selected' : ''; ?>>
                    Reseller #<?= htmlspecialchars($rOpt['id']); ?> — <?= htmlspecialchars($rOpt['username']); ?> (<?= htmlspecialchars($rOpt['name']); ?>)
                  </option>
                <?php } ?>
              </select>
            </div>
          </div>
        </div>
        <div class="col-6 col-box-12">
          <a class="btn bg-primary" href="<?= $editorRouteBase; ?>&template=default&session=<?= urlencode($session); ?><?= $targetResId !== '' ? '&reseller_id=' . urlencode($targetResId) : ''; ?>">
            <i class="fa fa-code"></i> <?= htmlspecialchars($_template_editor); ?><?= $targetReseller ? ' (#' . htmlspecialchars($targetReseller['id']) . ')' : ''; ?>
          </a>
          <?php if ($targetResId !== '') {
            $previewLogoPopup = "javascript:window.open('./voucher/vpreview.php?usermode=up&qr=no&variant=default&session=" . urlencode($session) . "&reseller_id=" . urlencode($targetResId) . "','_blank','width=310,height=310')";
          ?>
          <a class="btn bg-green" href="<?= $previewLogoPopup; ?>"><i class="fa fa-eye"></i> Preview Voucher</a>
          <?php } ?>
        </div>
      </div>
      <?php } ?>

      <form action="" method="post" enctype="multipart/form-data">
          <?php if ($targetReseller !== null) { ?>
            <div class="pd-10">
              <b>Upload Logo for Reseller #<?= htmlspecialchars($targetReseller['id']); ?> (<?= htmlspecialchars($targetReseller['name']); ?>)</b> — Any PNG, JPG, or WebP file (Max 2 MB). Stored isolated in <code>uploads/reseller-logos/<?= htmlspecialchars($targetReseller['id']); ?>/</code>
            </div>
          <?php } else { ?>
            <div class="pd-10"><?= $_format_file_name ?> : <code>logo-<?= htmlspecialchars($session); ?>.png</code> (Any PNG image selected below will automatically be saved as <code>logo-<?= htmlspecialchars($session); ?>.png</code>)</div>
          <?php } ?>
          <div class="input-group">
            <div class="input-group-6 col-box-8">
                <input style="cursor: pointer;" type="file" class="group-item group-item-l" name="UploadLogo" accept="image/png,image/jpeg,image/webp" required="1">
            </div>
            <div class="input-group-3 col-box-4">
                <button style="cursor: pointer; font-size: 14px; padding:6px 12px; width:100%;" class="btn bg-primary" type="submit" title="Upload logo" name="submit"><i class="fa fa-upload"></i> <?= $_upload ?></button>
            </div>
          </div>
      </form>
    </div>

      <div class="mr-t-10">
      <?php if ($targetReseller !== null) {
        $activeLogoUrl = resolveActiveVoucherLogo($session, './', true, $targetReseller['id']);
      ?>
      <table class="table table-bordered table-hover">
        <thead>
        <tr>
          <th>Reseller</th>
          <th>Active Voucher Logo</th>
          <th>Storage Path</th>
          <th><?= $_action ?></th>
        </tr>
        </thead>
        <tbody>
          <tr>
            <td>
              <b>#<?= htmlspecialchars($targetReseller['id']); ?> <?= htmlspecialchars($targetReseller['username']); ?></b><br>
              <small><?= htmlspecialchars($targetReseller['name']); ?> (<?= htmlspecialchars($targetReseller['template_code']); ?>)</small>
            </td>
            <td><img height="45px" src="<?= htmlspecialchars($activeLogoUrl); ?>" alt="Reseller Logo"></td>
            <td>
              <?php if (!empty($targetReseller['logo_filename'])) { ?>
                <code>uploads/reseller-logos/<?= htmlspecialchars($targetReseller['id']); ?>/<?= htmlspecialchars($targetReseller['logo_filename']); ?></code>
              <?php } else { ?>
                <span>Default Session / Global Logo Fallback</span>
              <?php } ?>
            </td>
            <td>
              <?php if (!empty($targetReseller['logo_filename'])) { ?>
                <form action="" method="post" style="display:inline;">
                  <button type="submit" name="reset_reseller_logo" value="1" class="btn bg-danger"><i class="fa fa-trash"></i> Reset to Default Logo</button>
                </form>
              <?php } else { ?>
                <span style="color:#8b949e;">Using Default</span>
              <?php } ?>
              &nbsp;
              <a class="btn bg-primary" href="<?= $editorRouteBase; ?>&template=default&session=<?= urlencode($session); ?>&reseller_id=<?= urlencode($targetReseller['id']); ?>">
                <i class="fa fa-code"></i> Edit HTML Template
              </a>
            </td>
          </tr>
        </tbody>
      </table>
      <?php } else { ?>
      <table class="table table-bordered table-hover">
        <thead>
        <tr>
          <th><?= $_list_logo ?> (Session / Global)</th>
          <th><?= $_action ?></th>
        </tr>
      </thead>
      <tbody>
        <?php
        $dir = "./img";
        if (is_dir($dir)) {
          if ($dh = opendir($dir)) {
            while (($file = readdir($dh)) !== false) {
              if ($file != "." && $file != "..") {
                if (substr($file, 0, 5) != "logo-" ||
                  substr($file, -5) == ".html" ||
                  substr($file, -4) == ".php" ||
                  substr($file, -4) == ".jpg" ||
                  substr($file, -4) == ".bak") {
                } else { ?>
              <tr>
                <td><a href="javascript:window.open('./img/<?= $file; ?>','_blank','width=300,height=300')"><img height="30px" src="./img/<?= $file; ?>?t=<?= time(); ?>" title="Open <?= $file; ?>"></a><br><span><?= $file; ?></span></td>
                <td><a class="btn bg-danger" href="javascript:void(0)" onclick="if(confirm('Sure to delete <?= $file; ?> ?')){window.location='./admin.php?id=remove-logo&logo=<?= $file; ?>&session=<?= $session ?>'}else{}"><i class="fa fa-trash"></i> <?= $_delete ?></a>
                </td>
              </tr>
          <?php 
        }
      }
    }
    closedir($dh);
  }
}
?>
      </tbody>
    </table>

    <?php if ($isAdminUser && !empty($allResellersList)) { ?>
    <div class="mr-t-10">
      <h4 style="margin:12px 0 8px 0;"><i class="fa fa-users"></i> Per-Reseller Logos & HTML Templates Overview</h4>
      <table class="table table-bordered table-hover table-sm">
        <thead>
          <tr>
            <th>ID</th>
            <th>Reseller</th>
            <th>Template Code</th>
            <th>Current Logo</th>
            <th>Logo Source</th>
            <th><?= $_action ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allResellersList as $rRow) {
            $rLogoUrl = resolveActiveVoucherLogo($session, './', true, $rRow['id']);
            $hasCustomLogo = !empty($rRow['logo_filename']);
          ?>
          <tr>
            <td><code>#<?= htmlspecialchars($rRow['id']); ?></code></td>
            <td><b><?= htmlspecialchars($rRow['username']); ?></b> <small>(<?= htmlspecialchars($rRow['name']); ?>)</small></td>
            <td><code><?= htmlspecialchars($rRow['template_code']); ?></code></td>
            <td><img height="32px" src="<?= htmlspecialchars($rLogoUrl); ?>" alt="Logo"></td>
            <td>
              <?php if ($hasCustomLogo) { ?>
                <span class="text-green"><i class="fa fa-check-circle"></i> Custom (<code><?= htmlspecialchars($rRow['logo_filename']); ?></code>)</span>
              <?php } else { ?>
                <span style="color:#8b949e;">Default Fallback</span>
              <?php } ?>
            </td>
            <td>
              <a class="btn bg-primary" href="<?= $uplogoRouteBase; ?>&session=<?= urlencode($session); ?>&reseller_id=<?= urlencode($rRow['id']); ?>">
                <i class="fa fa-upload"></i> Upload / Change Logo
              </a>
              &nbsp;
              <a class="btn bg-blue" href="<?= $editorRouteBase; ?>&template=default&session=<?= urlencode($session); ?>&reseller_id=<?= urlencode($rRow['id']); ?>">
                <i class="fa fa-code"></i> Edit HTML Template
              </a>
            </td>
          </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
    <?php } ?>
    <?php } ?>
  </div>
  
  </div>
</div>
</div>
</div>
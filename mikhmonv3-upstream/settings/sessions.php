<?php
/*
 * NextGan WiFi Solution — RADIUS NAS Router List
 */
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  include_once('./include/reseller_acl.php');
  include_once('./include/radius_engine.php');
  $nasList = loadRadiusNasList();

  $color = array('1' => 'bg-blue', 'bg-indigo', 'bg-purple', 'bg-pink', 'bg-red', 'bg-yellow', 'bg-green', 'bg-teal', 'bg-cyan', 'bg-grey', 'bg-light-blue');
}
?>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
        <h3 class="card-title" style="margin:0;">
          <i class="fa fa-server"></i> NAS List (RADIUS Clients) <small id="router-count" style="font-size:12px;font-weight:normal;"></small>
          &nbsp; | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer" title="Reload data"></i>
          <input type="search" id="router-search" placeholder="Search NAS" aria-label="Search NAS" style="display:inline-block;width:170px;max-width:45%;margin-left:10px;padding:4px 7px;font-size:13px;font-weight:normal;">
        </h3>
        <?php if (isMikhmonAdmin() || hasResellerPermission('manage_router_settings')) { ?>
          <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <a href="./admin.php?id=settings&router=new-<?= rand(1111,9999); ?>" class="btn bg-primary" style="padding:4px 10px; font-size:12px; font-weight:bold;">
              <i class="fa fa-plus"></i> Add NAS
            </a>
            <a href="./admin.php?id=vpn" class="btn bg-success" style="padding:4px 10px; font-size:12px; font-weight:bold;">
              <i class="fa fa-shield"></i> <?= (isset($langid) && $langid === 'bn') ? '+ VPN ও অটো NAS তৈরি' : '+ Create VPN & Auto-NAS'; ?>
            </a>
          </div>
        <?php } ?>
      </div>
      <div class="card-body">
        <?php if (!empty($_SESSION['router_settings_notice'])) { ?>
          <div class="alert alert-success" role="status" style="margin-bottom:12px;">
            <?= htmlspecialchars($_SESSION['router_settings_notice'], ENT_QUOTES, 'UTF-8'); ?>
          </div>
          <?php unset($_SESSION['router_settings_notice']); ?>
        <?php } ?>
        <div class="row">
          <?php
          foreach (file('./include/config.php') as $line) {
            $value = explode("'", $line)[1];
            if ($value == "" || $value == "mikhmon" || !canAccessRouterSession($value)) {
            } else {
              $nasIpDisplay = isset($nasList[$value]['nas_ip']) ? $nasList[$value]['nas_ip'] : explode('!', $data[$value][1])[1];
              $coaPortDisplay = isset($nasList[$value]['coa_port']) ? (int)$nasList[$value]['coa_port'] : 3799;
              $radSecretDisplay = isset($nasList[$value]['radius_secret']) ? $nasList[$value]['radius_secret'] : 'radius123';
              $hsNameDisplay = explode('%', $data[$value][4])[1];
          ?>
              <div class="col-6" data-router-card>
                <div class="box bmh-75 box-bordered <?= $color[rand(1, 11)]; ?>">
                  <div class="box-group">
                    <div class="box-group-icon">
                      <i class="fa fa-server"></i>
                    </div>
                    <div class="box-group-area">
                      <span>
                        NAS Name : <b><?= htmlspecialchars($hsNameDisplay); ?></b> &nbsp;<span style="font-size:11px; padding:1px 6px; border-radius:3px; background:rgba(0,0,0,0.22);">RADIUS Connected</span><br>
                        NAS ID : <b><?= htmlspecialchars($value); ?></b> &nbsp;·&nbsp; <span style="font-family:monospace;">VPN IP: <b><?= htmlspecialchars($nasIpDisplay); ?></b> · Secret: <b><?= htmlspecialchars($radSecretDisplay); ?></b> · CoA: <b><?= $coaPortDisplay; ?></b></span><br>
                        <?php if (hasResellerPermission('manage_router_settings')) { ?>
                        <a href="./admin.php?id=settings&session=<?= urlencode($value); ?>"><i class="fa fa-edit"></i> Edit NAS</a>&nbsp; | &nbsp;
                        <a href="./admin.php?id=vpn"><i class="fa fa-terminal"></i> VPN / ROS Script</a>&nbsp; | &nbsp;
                        <a href="javascript:void(0)" onclick="if(confirm('Are you sure to delete NAS <?= htmlspecialchars($value, ENT_QUOTES); ?> (<?= htmlspecialchars($hsNameDisplay, ENT_QUOTES); ?>)?')){loadpage('./admin.php?id=remove-session&session=<?= urlencode($value); ?>')}else{}"><i class="fa fa-remove"></i> <?= $_delete ?></a>
                        <?php } ?>
                      </span>
                    </div>
                  </div>
                </div>
              </div>
          <?php
            }
          }
          ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var search = document.getElementById('router-search');
  var cards = document.querySelectorAll('[data-router-card]');
  document.getElementById('router-count').textContent = '(' + cards.length + ')';
  search.addEventListener('input', function () {
    var query = search.value.trim().toLowerCase();
    for (var i = 0; i < cards.length; i++) {
      cards[i].style.display = cards[i].textContent.toLowerCase().indexOf(query) !== -1 ? '' : 'none';
    }
  });
})();
</script>

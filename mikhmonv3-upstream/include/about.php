<?php
/*
 *  NextGan WiFi Solution
 *  Hotspot & Reseller Management System for MikroTik RouterOS (v6 & v7)
 */
session_start();
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
}
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-info-circle"></i> <?= htmlspecialchars($_about); ?> NextGan WiFi Solution</h3>
      </div>
      <div class="card-body">
        <div style="display:flex; align-items:center; gap:15px; margin-bottom:15px; flex-wrap:wrap;">
          <img src="./img/favicon.png?v=nextgan" alt="NextGan WiFi Solution" style="width:72px; height:72px; border-radius:50%; object-fit:cover; border:2px solid #20a8d8;" />
          <div>
            <h3 style="margin:0 0 5px 0; font-weight:bold;">NextGan WiFi Solution</h3>
            <span style="font-size:13px; opacity:0.85;"><?= htmlspecialchars(isset($_about_subtitle) ? $_about_subtitle : 'Advanced MikroTik Hotspot, Voucher & Multi-Reseller Management System'); ?> (V<?= htmlspecialchars(isset($_SESSION['v']) ? $_SESSION['v'] : '3.20'); ?>)</span>
          </div>
        </div>
        <p>
          <?= isset($_about_desc) ? $_about_desc : '<b>NextGan WiFi Solution</b> is a modern and complete MikroTik Hotspot and WiFi Voucher Management Software designed for WiFi hotspot operators and ISP entrepreneurs.'; ?>
        </p>
        <p>
          <ul>
            <li><b>Software Name :</b> NextGan WiFi Solution</li>
            <li><b>Edition :</b> Enterprise Hotspot &amp; Reseller Edition (V<?= htmlspecialchars(isset($_SESSION['v']) ? $_SESSION['v'] : '3.20'); ?>)</li>
            <li><b>RouterOS Compatibility :</b> MikroTik RouterOS v6.x &amp; v7.x Dual-Stack Support</li>
            <li><b>Core Engine :</b> RouterOS API with Universal Expiration &amp; Sales Reporting</li>
            <li><b>Platform :</b> Standalone Windows Server &amp; Web Hosting Ready</li>
          </ul>
        </p>
        <p>
          <?= isset($_about_thanks) ? $_about_thanks : 'Thank you for using <b>NextGan WiFi Solution</b>. Wishing continued success for your hotspot business.'; ?>
        </p>
        <div style="margin-top:10px; padding-top:10px; border-top:1px solid rgba(150,150,150,0.2);">
          <i>Copyright &copy; <?= date('Y'); ?> <b>NextGan WiFi Solution</b>. All Rights Reserved.</i>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3><i class="fa fa-star"></i> <?= htmlspecialchars(isset($_about_feat_title) ? $_about_feat_title : 'NextGan WiFi Solution — Key Features & Capabilities'); ?></h3>
      </div>
      <div class="card-body">
        <div class="row">
          <div class="col-6">
            <h4 style="margin-top:0;"><i class="fa fa-check-circle text-green"></i> Hotspot &amp; RouterOS Management</h4>
            <ul>
              <li><b>RouterOS v6 &amp; v7 Universal Support:</b> <?= htmlspecialchars(isset($_about_f1) ? $_about_f1 : ''); ?></li>
              <li><b>Fast Voucher Generation &amp; Print:</b> <?= htmlspecialchars(isset($_about_f2) ? $_about_f2 : ''); ?></li>
              <li><b>Real-Time Traffic &amp; Active Monitor:</b> <?= htmlspecialchars(isset($_about_f3) ? $_about_f3 : ''); ?></li>
              <li><b>Automated Selling Report:</b> <?= htmlspecialchars(isset($_about_f4) ? $_about_f4 : ''); ?></li>
            </ul>
          </div>
          <div class="col-6">
            <h4 style="margin-top:0;"><i class="fa fa-shield text-blue"></i> Advanced Multi-Reseller &amp; Security</h4>
            <ul>
              <li><b>Granular Reseller Permission System:</b> <?= htmlspecialchars(isset($_about_f5) ? $_about_f5 : ''); ?></li>
              <li><b>Smart UI Auto-Hiding:</b> <?= htmlspecialchars(isset($_about_f6) ? $_about_f6 : ''); ?></li>
              <li><b>Reseller Custom Voucher &amp; Logo:</b> <?= htmlspecialchars(isset($_about_f7) ? $_about_f7 : ''); ?></li>
              <li><b>1-Click Standalone Server Package:</b> <?= htmlspecialchars(isset($_about_f8) ? $_about_f8 : ''); ?></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

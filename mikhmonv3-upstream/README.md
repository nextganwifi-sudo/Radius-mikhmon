# NextGan WiFi Solution (Based on Mikhmon v3.20)

**NextGan WiFi Solution** is a production-ready, backward-compatible extension of **MikroTik Hotspot Monitor (Mikhmon v3.20)** by Laksamadi Guko (GPLv2). It preserves 100% of the original Mikhmon v3 interface, dark theme, navigation, voucher workflow, and RouterOS API architecture while adding:

1. **Universal MikroTik RouterOS 6.x & RouterOS 7.x Expiration & Reporting Compatibility**:
   - Supports both RouterOS 6 (`mmm/dd/yyyy`, e.g., `oct/24/2026 14:30:00`) and RouterOS 7.10+ (`YYYY-MM-DD`, e.g., `2026-10-24 14:30:00`) date formats in profile `on-login` scripts, background expiration schedulers, and sales reports.
   - Includes a one-click **Fix ROS6/ROS7 Expiration & Reports** tool on the Dashboard to safely upgrade existing routers without deleting or resetting any active hotspot users, vouchers, or sales records.
2. **Dashboard Used Voucher Counter**:
   - Displays `X items / Used: Y` inside the existing green **Hotspot User** dashboard card (`dashboard/home.php` and `dashboard/aload.php`), counting unique activated vouchers whose comment contains a verified activation/expiration date (`YYYY-MM-DD HH:MM:SS` or `mmm/dd/yyyy HH:MM:SS`) and the `X` activation marker.
3. **Reseller Management with `Read`, `Write`, `Full`, and `Custom` Permission Modes**:
   - Assign resellers to specific MikroTik router sessions and configure permissions using **Admin-Managed Permission Groups** (`Read`, `Write`, `Full`) or **Custom** individual permissions across 9 categories.
   - When `Read`, `Write`, or `Full` is selected, the UI hides the individual checklist and shows a concise group summary; when `Custom` is selected, the categorized checklist is displayed.
   - Protected Administrator-only operations (`manage_resellers`, `assign_reseller_routers`, `manage_reseller_permissions`, `manage_permission_groups`, `assign_template`, `manage_global_templates`, `manage_router_settings`, `manage_scheduler`, `reboot_shutdown`) are never granted by `Full` or `Custom` reseller modes.
4. **Reseller-Specific Voucher Templates & Logos**:
   - Each reseller has a stable numeric ID (`101`, `102`, `103`, ...) and a unique template code (`NGW-001`, `NGW-002`, `NGW-003`, ...).
   - Isolated per-reseller voucher templates are stored in `uploads/reseller-templates/{reseller_id}/` with `Save`, `Publish`, and `Restore` capabilities.
   - Isolated per-reseller logos are stored in `uploads/reseller-logos/{reseller_id}/` with strict image validation (`getimagesize()`, PNG/JPEG/WebP, max 2 MB) while preserving the original `img/` and `images/` directories intact.

---

## System Requirements

- **PHP**: PHP 7.4, 8.0, 8.1, 8.2, or 8.3
- **Required PHP Extensions**: `sockets` or `fsockopen` (standard stream sockets), `gd` (for `getimagesize()` logo validation), `json`, `session`, `mbstring`
- **MikroTik RouterOS**: RouterOS v6.x or RouterOS v7.x with the API service enabled (`/ip service enable api`, default TCP port `8728`)
- **Web Server**: Apache (XAMPP / Laragon / WAMP), Nginx + PHP-FPM, or the built-in PHP CLI web server

---

## Local Installation Steps

### 1. Clone the Repository

```bash
git clone <your-github-repo-url> nextgan-wifi-solution
cd nextgan-wifi-solution
```

*(If running from the repository root wrapper, the PHP application lives in `mikhmonv3-upstream/` or at the web root depending on your deployment target.)*

### 2. Configure `include/config.php`

If `include/config.php` does not exist or you want a clean starting configuration, copy `include/config.example.php`:

```bash
cp include/config.example.php include/config.php
```

Ensure the web server has write permissions for:
- `include/config.php`
- `include/resellers.json`
- `include/permission_groups.json`
- `uploads/reseller-logos/`
- `uploads/reseller-templates/`

```bash
chmod -R 755 include uploads img images
```

### 3. Run Locally

#### Option A: Built-in PHP Server (Linux / macOS / Windows)

```bash
php -S 0.0.0.0:8080
```

Then open `http://localhost:8080/admin.php?id=login` in your browser.

#### Option B: XAMPP / Laragon / WAMP / Apache

1. Copy the application folder into `htdocs/nextgan-wifi` (XAMPP) or `www/nextgan-wifi` (Laragon/WAMP).
2. Open `http://localhost/nextgan-wifi/admin.php?id=login`.

---

## First-Login Instructions

- **Default Administrator Account**:
  - **Username**: `mikhmon`
  - **Password**: `1234`
- **Preconfigured Demo Reseller Accounts** (can be edited or removed in **Admin -> Reseller**):
  - `reseller_utara` / `reseller123` (ID `#101`, Mode: `Write`, Template: `NGW-001`)
  - `reseller_selatan` / `reseller123` (ID `#102`, Mode: `Full`, Template: `NGW-002`)
  - `audit_viewer` / `viewer123` (ID `#103`, Mode: `Read`, Template: `NGW-003`)

> **Security Recommendation**: Immediately after first login at `/admin.php?id=sessions`, update the Administrator username and password under **Admin Settings** and change or disable any default reseller accounts.

---

## Reseller Permission Modes & Template / Logo Customization

### Permission Modes (`Admin -> Reseller`)
1. **`Read` Mode**: Grants read-only access to dashboard, hotspot users, profiles, active sessions, logs, and reports for assigned routers.
2. **`Write` Mode**: Grants operational voucher seller permissions (add/edit users, generate/print vouchers, preview/use assigned templates, disconnect active users) without destructive router/profile deletion.
3. **`Full` Mode**: Grants full operational hotspot, network, and own-template management (`edit_own_template`, `save_own_template`, `publish_own_template`, `restore_own_template`) while strictly blocking protected Administrator-only settings.
4. **`Custom` Mode**: Reveals the 9-category granular permission checklist for fine-grained per-reseller customization.
5. **Permission Groups Editor (`Admin -> Reseller -> Permission Groups`)**: Allows the Administrator to customize which permissions belong to the `Read`, `Write`, and `Full` groups.

### Reseller Templates & Logos
- **Template Storage**: `uploads/reseller-templates/{reseller_id}/template.php` (`template-thermal.php`, `template-small.php`), with automatic `.bak.php` backup and `.published.php` snapshots.
- **Logo Storage**: `uploads/reseller-logos/{reseller_id}/logo.{png|jpg|webp}`.
- **Asset Resolution Order**:
  1. Authenticated reseller's explicitly uploaded logo (`uploads/reseller-logos/{id}/logo.*`)
  2. Reseller template logo (`uploads/reseller-templates/{id}/logo.png`)
  3. Router session logo (`img/logo-{session}.png`)
  4. Global default logo (`img/logo.png`)

---

## Backup & Restore Instructions

### Creating a Backup
Back up your configuration, reseller accounts, permission groups, and custom reseller assets:

```bash
tar -czf nextgan-backup-$(date +%Y%m%d).tar.gz \
  include/config.php \
  include/resellers.json \
  include/permission_groups.json \
  uploads/reseller-logos \
  uploads/reseller-templates \
  img/
```

### Restoring from Backup

```bash
tar -xzf nextgan-backup-YYYYMMDD.tar.gz
```

---

## License & Attribution

- Original Application: **MIKHMON v3** — Copyright (C) 2018 Laksamadi Guko
- License: [GNU General Public License v2.0 (GPLv2)](LICENSE)

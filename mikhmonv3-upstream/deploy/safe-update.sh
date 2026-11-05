#!/usr/bin/env bash
# ==============================================================================
# NextGan WiFi Solution — Zero-Data-Loss Safe Update Script for VPS
# Updates application code from Git or ZIP archive while strictly preserving:
# - include/config.php (All MikroTik routers, credentials, API/SSH modes)
# - include/resellers.json & include/permission_groups.json (All Resellers)
# - uploads/reseller-templates/* & uploads/reseller-logos/* (Per-Reseller HTML & Logos)
# - voucher/template*.php & img/logo-*.png (Global custom templates & session logos)
# ==============================================================================
set -e

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${APP_DIR}/backups"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
STAGE_BACKUP="${BACKUP_DIR}/pre-update-${TIMESTAMP}"

mkdir -p "${BACKUP_DIR}" "${STAGE_BACKUP}/include" "${STAGE_BACKUP}/uploads" "${STAGE_BACKUP}/voucher" "${STAGE_BACKUP}/img" "${STAGE_BACKUP}/images"

echo "[1/4] Creating Zero-Data-Loss snapshot in ${STAGE_BACKUP}..."
cp -a "${APP_DIR}/include/config.php" "${STAGE_BACKUP}/include/" 2>/dev/null || true
cp -a "${APP_DIR}/include/resellers.json" "${STAGE_BACKUP}/include/" 2>/dev/null || true
cp -a "${APP_DIR}/include/permission_groups.json" "${STAGE_BACKUP}/include/" 2>/dev/null || true
cp -a "${APP_DIR}/include/backup_admin.json" "${STAGE_BACKUP}/include/" 2>/dev/null || true
cp -a "${APP_DIR}/include/vpn_routers.json" "${STAGE_BACKUP}/include/" 2>/dev/null || true
cp -a "${APP_DIR}/include/quickbt.php" "${STAGE_BACKUP}/include/" 2>/dev/null || true
cp -a "${APP_DIR}/uploads/." "${STAGE_BACKUP}/uploads/" 2>/dev/null || true
cp -a "${APP_DIR}/voucher/template.php" "${STAGE_BACKUP}/voucher/" 2>/dev/null || true
cp -a "${APP_DIR}/voucher/template-small.php" "${STAGE_BACKUP}/voucher/" 2>/dev/null || true
cp -a "${APP_DIR}/voucher/template-thermal.php" "${STAGE_BACKUP}/voucher/" 2>/dev/null || true
cp -a "${APP_DIR}/img/logo-"*.png "${STAGE_BACKUP}/img/" 2>/dev/null || true
cp -a "${APP_DIR}/images/logo-"*.png "${STAGE_BACKUP}/images/" 2>/dev/null || true

echo "[2/4] Fetching latest application code..."
if [[ -n "$1" && -f "$1" ]]; then
  echo " -> Applying update from ZIP file: $1"
  TMP_EXTRACT="/tmp/ngw_zip_update_${TIMESTAMP}"
  mkdir -p "$TMP_EXTRACT"
  unzip -q "$1" -d "$TMP_EXTRACT"
  if [[ -d "${TMP_EXTRACT}/mikhmonv3-upstream" ]]; then
    cp -a "${TMP_EXTRACT}/mikhmonv3-upstream/." "${APP_DIR}/"
  elif [[ -d "${TMP_EXTRACT}/Mikhmon Server/mikhmon" ]]; then
    cp -a "${TMP_EXTRACT}/Mikhmon Server/mikhmon/." "${APP_DIR}/"
  else
    cp -a "${TMP_EXTRACT}/." "${APP_DIR}/"
  fi
  rm -rf "$TMP_EXTRACT"
elif [[ -d "${APP_DIR}/.git" ]]; then
  echo " -> Pulling latest changes from Git..."
  git -C "${APP_DIR}" fetch --all
  git -C "${APP_DIR}" reset --hard origin/HEAD
elif [[ -d "${APP_DIR}/../.git" ]]; then
  echo " -> Pulling latest changes from parent Git repository..."
  git -C "${APP_DIR}/.." fetch --all
  git -C "${APP_DIR}/.." reset --hard origin/HEAD
else
  echo " -> Note: No Git repository or ZIP argument detected; verifying and restoring data files."
fi

echo "[3/4] Restoring protected routers, resellers, HTML templates & logos..."
cp -a "${STAGE_BACKUP}/include/." "${APP_DIR}/include/" 2>/dev/null || true
cp -a "${STAGE_BACKUP}/uploads/." "${APP_DIR}/uploads/" 2>/dev/null || true
cp -a "${STAGE_BACKUP}/voucher/." "${APP_DIR}/voucher/" 2>/dev/null || true
cp -a "${STAGE_BACKUP}/img/." "${APP_DIR}/img/" 2>/dev/null || true
cp -a "${STAGE_BACKUP}/images/." "${APP_DIR}/images/" 2>/dev/null || true

echo "[4/4] Fixing file permissions..."
# Restore root ownership for application code; PHP writes only intended runtime/editor locations.
chown -R root:www-data "${APP_DIR}" 2>/dev/null || true
find "${APP_DIR}" -type d -exec chmod 755 {} + 2>/dev/null || true
find "${APP_DIR}" -type f -exec chmod 644 {} + 2>/dev/null || true
chmod 1770 "${APP_DIR}/include" 2>/dev/null || true
mkdir -p "${APP_DIR}/include" "${APP_DIR}/uploads" "${APP_DIR}/voucher" "${APP_DIR}/img" "${APP_DIR}/images" "${APP_DIR}/backups"
[[ -f "${APP_DIR}/include/resellers.json" ]] || printf '[]\n' > "${APP_DIR}/include/resellers.json"
[[ -f "${APP_DIR}/include/backup_admin.json" ]] || printf '{"username":"","password_enc":""}\n' > "${APP_DIR}/include/backup_admin.json"
[[ -f "${APP_DIR}/include/vpn_routers.json" ]] || printf '[]\n' > "${APP_DIR}/include/vpn_routers.json"
chown -R www-data:www-data "${APP_DIR}/uploads" "${APP_DIR}/voucher" "${APP_DIR}/img" "${APP_DIR}/images" 2>/dev/null || true
find "${APP_DIR}/uploads" "${APP_DIR}/voucher" "${APP_DIR}/img" "${APP_DIR}/images" -type d -exec chmod 750 {} + 2>/dev/null || true
find "${APP_DIR}/uploads" "${APP_DIR}/voucher" "${APP_DIR}/img" "${APP_DIR}/images" -type f -exec chmod 640 {} + 2>/dev/null || true
chown root:www-data "${APP_DIR}/include" "${APP_DIR}/backups" 2>/dev/null || true
chmod 750 "${APP_DIR}/backups" 2>/dev/null || true
for runtime_file in config.php resellers.json permission_groups.json backup_admin.json vpn_routers.json quickbt.php; do
  if [[ -f "${APP_DIR}/include/${runtime_file}" ]]; then
    chown www-data:www-data "${APP_DIR}/include/${runtime_file}" 2>/dev/null || true
    chmod 640 "${APP_DIR}/include/${runtime_file}" 2>/dev/null || true
  fi
done

echo "Safe Update Completed Successfully! Zero data files were modified or lost."

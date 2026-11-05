#!/usr/bin/env bash
# ==============================================================================
# NextGan WiFi Solution — LightNode / Linux VPS + Custom Domain Auto-Installer
# Supports Ubuntu 20.04 / 22.04 / 24.04 & Debian 11 / 12
# Installs Nginx, PHP-FPM, PHP SSH2 / sshpass (for MikroTik SSH & API), Git,
# and configures Custom Domain + Let's Encrypt SSL with Zero-Data-Loss protection.
# ==============================================================================
set -e

DOMAIN=""
GIT_REPO=""
APP_DIR="/var/www/nextgan-wifi"
ENABLE_SSL="yes"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain)
      DOMAIN="$2"
      shift 2
      ;;
    --repo)
      GIT_REPO="$2"
      shift 2
      ;;
    --dir)
      APP_DIR="$2"
      shift 2
      ;;
    --no-ssl)
      ENABLE_SSL="no"
      shift 1
      ;;
    *)
      shift 1
      ;;
  esac
done

echo "=================================================================="
echo "   NextGan WiFi Solution — VPS & Custom Domain Auto-Installer"
echo "=================================================================="

if [[ $EUID -ne 0 ]]; then
  echo "[!] Please run this script as root: sudo bash vps-install.sh --domain yourdomain.com"
  exit 1
fi

if [[ -z "$DOMAIN" ]]; then
  read -r -p "Enter your Custom Domain (e.g. wifi.yourdomain.com) or VPS IP: " DOMAIN
fi

if [[ -z "$DOMAIN" ]]; then
  DOMAIN="_"
fi

echo "[1/6] Updating system packages and installing Nginx, PHP-FPM, SSH tools, Git, Unzip..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y nginx git unzip curl sshpass openssh-client certbot python3-certbot-nginx \
  php-fpm php-cli php-curl php-mbstring php-xml php-zip php-gd php-ssh2 || \
apt-get install -y nginx git unzip curl sshpass openssh-client certbot python3-certbot-nginx \
  php-fpm php-cli php-curl php-mbstring php-xml php-zip php-gd

PHP_SOCK=$(find /run/php/ -name "php*-fpm.sock" | head -n 1)
if [[ -z "$PHP_SOCK" ]]; then
  PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
  systemctl enable "php${PHP_VER}-fpm" || true
  systemctl restart "php${PHP_VER}-fpm" || true
  PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
fi

echo "[2/6] Preparing application directory at ${APP_DIR} (with Zero-Data-Loss protection)..."
mkdir -p "${APP_DIR}"
BACKUP_TMP="/tmp/ngw_preinstall_backup_$(date +%s)"

if [[ -f "${APP_DIR}/include/config.php" ]]; then
  echo " -> Existing installation detected! Backing up live data before updating code..."
  mkdir -p "${BACKUP_TMP}/include" "${BACKUP_TMP}/uploads" "${BACKUP_TMP}/voucher" "${BACKUP_TMP}/img"
  cp -a "${APP_DIR}/include/config.php" "${BACKUP_TMP}/include/" 2>/dev/null || true
  cp -a "${APP_DIR}/include/resellers.json" "${BACKUP_TMP}/include/" 2>/dev/null || true
  cp -a "${APP_DIR}/include/permission_groups.json" "${BACKUP_TMP}/include/" 2>/dev/null || true
  cp -a "${APP_DIR}/include/backup_admin.json" "${BACKUP_TMP}/include/" 2>/dev/null || true
  cp -a "${APP_DIR}/include/vpn_routers.json" "${BACKUP_TMP}/include/" 2>/dev/null || true
  cp -a "${APP_DIR}/include/quickbt.php" "${BACKUP_TMP}/include/" 2>/dev/null || true
  cp -a "${APP_DIR}/uploads/." "${BACKUP_TMP}/uploads/" 2>/dev/null || true
  cp -a "${APP_DIR}/voucher/template.php" "${BACKUP_TMP}/voucher/" 2>/dev/null || true
  cp -a "${APP_DIR}/voucher/template-small.php" "${BACKUP_TMP}/voucher/" 2>/dev/null || true
  cp -a "${APP_DIR}/voucher/template-thermal.php" "${BACKUP_TMP}/voucher/" 2>/dev/null || true
  cp -a "${APP_DIR}/img/logo-"*.png "${BACKUP_TMP}/img/" 2>/dev/null || true
fi

SCRIPT_SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ -f "${SCRIPT_SRC_DIR}/admin.php" && -f "${SCRIPT_SRC_DIR}/index.php" ]]; then
  echo " -> Copying application files from ${SCRIPT_SRC_DIR} to ${APP_DIR}..."
  cp -a "${SCRIPT_SRC_DIR}/." "${APP_DIR}/"
elif [[ -n "$GIT_REPO" ]]; then
  echo " -> Cloning/updating from Git repository ${GIT_REPO}..."
  TMP_CLONE="/tmp/ngw_git_clone_$(date +%s)"
  git clone --depth 1 "$GIT_REPO" "$TMP_CLONE"
  if [[ -d "${TMP_CLONE}/mikhmonv3-upstream" ]]; then
    cp -a "${TMP_CLONE}/mikhmonv3-upstream/." "${APP_DIR}/"
  else
    cp -a "${TMP_CLONE}/." "${APP_DIR}/"
  fi
  rm -rf "$TMP_CLONE"
fi

if [[ -d "$BACKUP_TMP" ]]; then
  echo " -> Restoring protected live data (routers, resellers, templates, logos)..."
  cp -a "${BACKUP_TMP}/include/." "${APP_DIR}/include/" 2>/dev/null || true
  cp -a "${BACKUP_TMP}/uploads/." "${APP_DIR}/uploads/" 2>/dev/null || true
  cp -a "${BACKUP_TMP}/voucher/." "${APP_DIR}/voucher/" 2>/dev/null || true
  cp -a "${BACKUP_TMP}/img/." "${APP_DIR}/img/" 2>/dev/null || true
  rm -rf "$BACKUP_TMP"
fi

echo "[3/6] Setting directory permissions for web-based Template Editor, Logo Upload & Safe Updater..."
# Create a runtime config only when this is a fresh install; never overwrite live router credentials.
if [[ ! -f "${APP_DIR}/include/config.php" && -f "${APP_DIR}/include/config.example.php" ]]; then
  cp "${APP_DIR}/include/config.example.php" "${APP_DIR}/include/config.php"
  # Generate unique initial admin credentials instead of deploying the public demo login.
  ADMIN_USER="admin_$(openssl rand -hex 4)"
  ADMIN_PASS="$(openssl rand -hex 12)"
  ADMIN_ENC="$(php -r '$s=$argv[1];$key="128";$r="";for($i=0;$i<strlen($s);$i++){$r.=chr(ord($s[$i])+ord(substr($key,($i%strlen($key))-1,1)));}echo base64_encode($r);' "$ADMIN_PASS")"
  APP_CONFIG="${APP_DIR}/include/config.php" ADMIN_USER="$ADMIN_USER" ADMIN_ENC="$ADMIN_ENC" php <<'PHP'
<?php
$p = getenv('APP_CONFIG');
$user = getenv('ADMIN_USER');
$enc = getenv('ADMIN_ENC');
$s = @file_get_contents($p);
if ($s === false || $user === '' || $enc === '') {
    fwrite(STDERR, "Unable to read config or generate initial administrator credentials.\n");
    exit(1);
}
$pattern = '/^\$data\[[\'"]mikhmon[\'"]\]\s*=\s*array\s*\(.*\);\s*$/m';
$replacement = "\$data['mikhmon'] = array ('1'=>'mikhmon<|<" . $user . "','mikhmon>|>" . $enc . "');";
$count = 0;
$updated = preg_replace($pattern, $replacement, $s, 1, $count);
if ($updated === null || $count !== 1) {
    fwrite(STDERR, "Unable to locate the administrator config entry; refusing to install with a known default credential.\n");
    exit(1);
}
if (@file_put_contents($p, $updated) === false) {
    fwrite(STDERR, "Unable to save initial administrator credentials.\n");
    exit(1);
}
PHP
  CREDENTIAL_FILE="/root/nextgan-initial-credentials.txt"
  umask 077
  printf 'NextGan initial administrator login\nUsername: %s\nPassword: %s\nChange this password immediately after login.\n' "$ADMIN_USER" "$ADMIN_PASS" > "$CREDENTIAL_FILE"
  chmod 600 "$CREDENTIAL_FILE"
  echo "[!] Initial admin credentials were generated and saved to $CREDENTIAL_FILE"
  echo "    Username: $ADMIN_USER"
  echo "    Password: $ADMIN_PASS"
fi
mkdir -p "${APP_DIR}/uploads/reseller-templates" "${APP_DIR}/uploads/reseller-logos" "${APP_DIR}/backups" "${APP_DIR}/images"
# Ensure runtime JSON stores exist before restricting the include directory.
[[ -f "${APP_DIR}/include/resellers.json" ]] || printf '[]\n' > "${APP_DIR}/include/resellers.json"
[[ -f "${APP_DIR}/include/backup_admin.json" ]] || printf '{"username":"","password_enc":""}\n' > "${APP_DIR}/include/backup_admin.json"
[[ -f "${APP_DIR}/include/vpn_routers.json" ]] || printf '[]\n' > "${APP_DIR}/include/vpn_routers.json"
# Keep application source root-owned; PHP can write only intended runtime/editor files.
chown -R root:www-data "${APP_DIR}"
find "${APP_DIR}" -type d -exec chmod 755 {} +
find "${APP_DIR}" -type f -exec chmod 644 {} +
chmod 1770 "${APP_DIR}/include"
chown -R www-data:www-data "${APP_DIR}/uploads" "${APP_DIR}/voucher" "${APP_DIR}/img" "${APP_DIR}/images"
find "${APP_DIR}/uploads" "${APP_DIR}/voucher" "${APP_DIR}/img" "${APP_DIR}/images" -type d -exec chmod 750 {} +
find "${APP_DIR}/uploads" "${APP_DIR}/voucher" "${APP_DIR}/img" "${APP_DIR}/images" -type f -exec chmod 640 {} +
chown root:www-data "${APP_DIR}/include"
for runtime_file in config.php resellers.json permission_groups.json backup_admin.json vpn_routers.json quickbt.php; do
  if [[ -f "${APP_DIR}/include/${runtime_file}" ]]; then
    chown www-data:www-data "${APP_DIR}/include/${runtime_file}"
    chmod 640 "${APP_DIR}/include/${runtime_file}"
  fi
done
chown root:www-data "${APP_DIR}/backups"
chmod 750 "${APP_DIR}/backups"

echo "[4/6] Configuring Nginx Virtual Host for domain: ${DOMAIN}..."
cat > /etc/nginx/sites-available/nextgan-wifi <<EOF
server {
    listen 80;
    server_name ${DOMAIN} www.${DOMAIN};
    root ${APP_DIR};
    index index.php admin.php index.html;

    client_max_body_size 64M;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    # Never expose runtime configuration, backups, deployment files, Git metadata, or environment files.
    location ~* ^/(include/|backups/|deploy/|\.git/|\.aistudio/|\.env) {
        deny all;
        return 404;
    }

    # Uploaded templates may be included internally, but must not execute via direct HTTP requests.
    location ~* ^/(uploads|voucher)/.*\.(php[0-9]?|phtml|phar|cgi|pl|sh)$ {
        deny all;
        return 404;
    }

    location ~ /\.(?!well-known/) {
        deny all;
        return 404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_read_timeout 180;
    }
}
EOF

ln -sf /etc/nginx/sites-available/nextgan-wifi /etc/nginx/sites-enabled/nextgan-wifi
if [[ "$DOMAIN" == "_" ]]; then
  rm -f /etc/nginx/sites-enabled/default
fi
nginx -t && systemctl reload nginx

if [[ "$ENABLE_SSL" == "yes" && "$DOMAIN" != "_" && ! "$DOMAIN" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "[5/6] Attempting automatic Let's Encrypt HTTPS SSL certificate setup for ${DOMAIN}..."
  certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos --register-unsafely-without-email --redirect || \
    echo " -> Note: SSL setup skipped (make sure DNS A record for ${DOMAIN} points to this VPS IP, then run: certbot --nginx -d ${DOMAIN})"
else
  echo "[5/6] Skipping SSL setup (IP address or --no-ssl specified)."
fi

echo "[6/6] Installation Complete!"
echo "=================================================================="
echo " NextGan WiFi Solution is now live!"
echo " URL: http://${DOMAIN}/admin.php?id=login"
echo " Web Root: ${APP_DIR}"
echo " Safe Update CLI: sudo bash ${APP_DIR}/deploy/safe-update.sh"
echo "=================================================================="

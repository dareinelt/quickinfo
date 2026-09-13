#!/usr/bin/env bash
# =============================================================================
#  quickinfo – One-Line-Deployment für Ubuntu Bare-Metal-Server
#
#  Aufruf (als root oder mit sudo):   bash install.sh
#
#  Installiert Nginx + PHP-FPM + MySQL/MariaDB + lm-sensors + sysstat, richtet
#  HTTPS (Self-Signed) ein, legt Datenbank/Schema/Admin an, kopiert die
#  Anwendung nach /var/www/html/quickinfo und startet den Collector als
#  systemd-Timer (minütlich). Das Skript ist idempotent und kann erneut
#  ausgeführt werden (Update); dabei wird das Admin-Passwort neu gesetzt.
# =============================================================================
set -euo pipefail

APP_NAME="quickinfo"
APP_DIR="/var/www/html/${APP_NAME}"
CONF_DIR="/etc/${APP_NAME}"
CONF_FILE="${CONF_DIR}/config.php"
SSL_DIR="/etc/ssl/${APP_NAME}"
DB_NAME="${QI_DB_NAME:-quickinfo}"
DB_USER="${QI_DB_USER:-quickinfo}"
ADMIN_USER="${QI_ADMIN_USER:-admin}"
HTTPS_PORT="${QI_HTTPS_PORT:-443}"
HTTP_PORT="${QI_HTTP_PORT:-80}"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

c_bold=$'\033[1m'; c_green=$'\033[32m'; c_yellow=$'\033[33m'; c_red=$'\033[31m'; c_cyan=$'\033[36m'; c_reset=$'\033[0m'
step()  { printf '\n%s==> %s%s\n' "${c_cyan}${c_bold}" "$*" "${c_reset}"; }
ok()    { printf '   %s✔%s %s\n' "${c_green}" "${c_reset}" "$*"; }
warn()  { printf '   %s!%s %s\n' "${c_yellow}" "${c_reset}" "$*"; }
die()   { printf '%s✖ %s%s\n' "${c_red}" "$*" "${c_reset}" >&2; exit 1; }

# -----------------------------------------------------------------------------
# Vorprüfungen
# -----------------------------------------------------------------------------
[[ "${EUID}" -eq 0 ]] || die "Bitte als root ausführen (sudo bash install.sh)."
[[ -f "${SRC_DIR}/db.sql" && -d "${SRC_DIR}/public" && -d "${SRC_DIR}/src" && -d "${SRC_DIR}/collector" ]] \
    || die "Quelldateien nicht gefunden. install.sh muss aus dem Projektverzeichnis ausgeführt werden."
command -v apt-get >/dev/null 2>&1 || die "Dieses Skript unterstützt nur Ubuntu/Debian (apt-get)."

randpw() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "${1:-24}"; echo; }

# -----------------------------------------------------------------------------
# 1) Pakete
# -----------------------------------------------------------------------------
step "Pakete installieren"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx php-fpm php-mysql php-cli php-json php-mbstring lm-sensors sysstat openssl ca-certificates >/dev/null
if ! apt-get install -y -qq mysql-server >/dev/null 2>&1; then
    warn "mysql-server nicht verfügbar – installiere mariadb-server"
    apt-get install -y -qq mariadb-server >/dev/null
fi
ok "Pakete installiert"

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_FPM_SERVICE="php${PHP_VER}-fpm"
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
ok "PHP ${PHP_VER} (${PHP_FPM_SERVICE})"

if systemctl list-unit-files --no-legend 2>/dev/null | grep -q '^mariadb\.service'; then
    DB_SERVICE="mariadb"
else
    DB_SERVICE="mysql"
fi
systemctl enable --now "${DB_SERVICE}" >/dev/null 2>&1 || true
systemctl enable --now "${PHP_FPM_SERVICE}" >/dev/null 2>&1 || true
systemctl enable --now nginx >/dev/null 2>&1 || true

# sysstat-Datensammlung aktivieren (mpstat/sar)
if [[ -f /etc/default/sysstat ]]; then
    sed -i 's/^ENABLED=.*/ENABLED="true"/' /etc/default/sysstat
    systemctl enable --now sysstat >/dev/null 2>&1 || true
fi

# -----------------------------------------------------------------------------
# 2) lm-sensors automatisch erkennen
# -----------------------------------------------------------------------------
step "Hardware-Sensoren erkennen (lm-sensors)"
if command -v sensors-detect >/dev/null 2>&1; then
    if sensors-detect --auto >/tmp/sensors-detect.log 2>&1; then
        ok "sensors-detect abgeschlossen"
    else
        warn "sensors-detect meldete Probleme (siehe /tmp/sensors-detect.log) – fahre fort"
    fi
    # erkannte Kernelmodule sofort laden
    if [[ -f /etc/modules ]]; then
        while read -r mod; do
            [[ -z "${mod}" || "${mod}" == \#* ]] && continue
            modprobe "${mod}" 2>/dev/null || true
        done < /etc/modules
    fi
    systemctl restart systemd-modules-load.service >/dev/null 2>&1 || true
    modprobe coretemp 2>/dev/null || modprobe k10temp 2>/dev/null || true
fi
SENSOR_COUNT="$(ls /sys/class/hwmon/hwmon*/temp*_input 2>/dev/null | wc -l || true)"
ok "${SENSOR_COUNT} Temperatursensor(en) unter /sys/class/hwmon gefunden"

# -----------------------------------------------------------------------------
# 3) Anwendung kopieren
# -----------------------------------------------------------------------------
step "Anwendung nach ${APP_DIR} kopieren"
mkdir -p "${APP_DIR}"
rm -rf "${APP_DIR}/public" "${APP_DIR}/src" "${APP_DIR}/collector"
cp -a "${SRC_DIR}/public" "${SRC_DIR}/src" "${SRC_DIR}/collector" "${APP_DIR}/"
cp -a "${SRC_DIR}/db.sql" "${APP_DIR}/db.sql"
ok "Dateien kopiert"

# -----------------------------------------------------------------------------
# 4) Datenbank
# -----------------------------------------------------------------------------
step "Datenbank einrichten"
DB_PASS=""
if [[ -f "${CONF_FILE}" ]]; then
    # Bestehendes DB-Passwort aus vorhandener Konfiguration weiterverwenden
    DB_PASS="$(php -r '$c = require $argv[1]; echo $c["db"]["password"] ?? "";' "${CONF_FILE}" 2>/dev/null || true)"
fi
[[ -n "${DB_PASS}" ]] || DB_PASS="$(randpw 32)"

mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
mysql --protocol=socket -uroot "${DB_NAME}" < "${SRC_DIR}/db.sql"
ok "Datenbank '${DB_NAME}' und Benutzer '${DB_USER}' angelegt, Schema importiert"

# Datenbankdienst als überwachten Dienst eintragen
mysql --protocol=socket -uroot "${DB_NAME}" <<SQL
INSERT IGNORE INTO services (name, display_name, sort_order, created_at)
VALUES ('${DB_SERVICE}', '$( [[ "${DB_SERVICE}" == "mariadb" ]] && echo MariaDB || echo MySQL )', 30, UNIX_TIMESTAMP());
INSERT IGNORE INTO services (name, display_name, sort_order, created_at)
VALUES ('${PHP_FPM_SERVICE}', 'PHP-FPM', 25, UNIX_TIMESTAMP());
SQL

# Admin-Benutzer mit zufälligem Passwort
ADMIN_PASS="$(randpw 20)"
ADMIN_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "${ADMIN_PASS}")"
mysql --protocol=socket -uroot "${DB_NAME}" <<SQL
INSERT INTO users (username, password_hash, created_at)
VALUES ('${ADMIN_USER}', '${ADMIN_HASH}', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);
SQL
ok "Admin-Benutzer '${ADMIN_USER}' angelegt"

# -----------------------------------------------------------------------------
# 5) Konfiguration
# -----------------------------------------------------------------------------
step "Konfiguration schreiben (${CONF_FILE})"
mkdir -p "${CONF_DIR}"
DB_SOCKET=""
for s in /var/run/mysqld/mysqld.sock /run/mysqld/mysqld.sock; do
    [[ -S "${s}" ]] && { DB_SOCKET="${s}"; break; }
done
cat > "${CONF_FILE}" <<PHP
<?php
// quickinfo – automatisch erzeugt von install.sh am $(date -Is)
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => '${DB_NAME}',
        'user'     => '${DB_USER}',
        'password' => '${DB_PASS}',
        'socket'   => $( [[ -n "${DB_SOCKET}" ]] && echo "'${DB_SOCKET}'" || echo null ),
    ],
    'retention' => [
        'raw_days' => 4,
        'agg_days' => 30,
        'log_days' => 30,
    ],
    'collector' => [
        'root_fs'       => '/',
        'cpu_sample_ms' => 1000,
        'nvidia_smi'    => 'nvidia-smi',
        'sensors'       => 'sensors',
    ],
    'auth' => [
        'max_attempts'     => 5,
        'lockout_seconds'  => 900,
        'session_lifetime' => 43200,
        'session_name'     => 'quickinfo_sid',
    ],
];
PHP
chown root:www-data "${CONF_FILE}"
chmod 640 "${CONF_FILE}"
chmod 750 "${CONF_DIR}"
chown root:www-data "${CONF_DIR}"
ok "Konfiguration geschrieben"

# -----------------------------------------------------------------------------
# 6) Dateirechte
# -----------------------------------------------------------------------------
step "Dateirechte setzen"
chown -R root:www-data "${APP_DIR}"
find "${APP_DIR}" -type d -exec chmod 750 {} +
find "${APP_DIR}" -type f -exec chmod 640 {} +
chmod 750 "${APP_DIR}/collector/collector.php"
ok "Rechte gesetzt (root:www-data, 750/640)"

# -----------------------------------------------------------------------------
# 7) HTTPS – Self-Signed Zertifikat
# -----------------------------------------------------------------------------
step "Self-Signed TLS-Zertifikat erzeugen"
mkdir -p "${SSL_DIR}"
HOST_FQDN="$(hostname -f 2>/dev/null || hostname)"
HOST_SHORT="$(hostname)"
PRIMARY_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
SAN="DNS:${HOST_FQDN},DNS:${HOST_SHORT},DNS:localhost,IP:127.0.0.1"
[[ -n "${PRIMARY_IP}" ]] && SAN="${SAN},IP:${PRIMARY_IP}"
if [[ ! -s "${SSL_DIR}/${APP_NAME}.crt" || ! -s "${SSL_DIR}/${APP_NAME}.key" ]]; then
    openssl req -x509 -nodes -newkey rsa:2048 -sha256 -days 3650 \
        -keyout "${SSL_DIR}/${APP_NAME}.key" -out "${SSL_DIR}/${APP_NAME}.crt" \
        -subj "/CN=${HOST_FQDN}/O=quickinfo" -addext "subjectAltName=${SAN}" >/dev/null 2>&1
    chmod 600 "${SSL_DIR}/${APP_NAME}.key"
    ok "Zertifikat erzeugt (CN=${HOST_FQDN}, gültig 10 Jahre)"
else
    ok "Vorhandenes Zertifikat wird weiterverwendet"
fi
if [[ ! -s /etc/nginx/dhparam.pem ]]; then
    openssl dhparam -out /etc/nginx/dhparam.pem 2048 >/dev/null 2>&1 || true
fi

# -----------------------------------------------------------------------------
# 8) Nginx
# -----------------------------------------------------------------------------
step "Nginx konfigurieren"
cat > "/etc/nginx/sites-available/${APP_NAME}" <<NGINX
# quickinfo – automatisch erzeugt von install.sh
server {
    listen ${HTTP_PORT} default_server;
    listen [::]:${HTTP_PORT} default_server;
    server_name _;
    return 301 https://\$host\$request_uri;
}

server {
    listen ${HTTPS_PORT} ssl default_server;
    listen [::]:${HTTPS_PORT} ssl default_server;
    http2 on;
    server_name _;

    ssl_certificate     ${SSL_DIR}/${APP_NAME}.crt;
    ssl_certificate_key ${SSL_DIR}/${APP_NAME}.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;
$( [[ -s /etc/nginx/dhparam.pem ]] && echo "    ssl_dhparam /etc/nginx/dhparam.pem;" )

    root ${APP_DIR}/public;
    index index.html;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy no-referrer always;
    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'" always;

    access_log /var/log/nginx/${APP_NAME}.access.log;
    error_log  /var/log/nginx/${APP_NAME}.error.log;

    location = / { try_files /index.html =404; }

    location /assets/ {
        expires 7d;
        add_header Cache-Control "public, max-age=604800";
        try_files \$uri =404;
    }

    location /api/ {
        try_files \$uri /api/index.php\$is_args\$args;
    }

    location = /api/index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_read_timeout 60s;
    }

    location ~ \\.php\$ { return 404; }
    location ~ /\\. { deny all; }
}
NGINX
ln -sf "/etc/nginx/sites-available/${APP_NAME}" "/etc/nginx/sites-enabled/${APP_NAME}"
rm -f /etc/nginx/sites-enabled/default

# "http2 on;" gibt es erst ab Nginx 1.25.1 – ältere Versionen nutzen die listen-Direktive
NGINX_VER="$(nginx -v 2>&1 | sed -E 's/.*nginx\/([0-9.]+).*/\1/')"
if [[ "$(printf '%s\n%s\n' "1.25.1" "${NGINX_VER}" | sort -V | head -n1)" != "1.25.1" ]]; then
    sed -i -e '/^    http2 on;$/d' -e "s/listen ${HTTPS_PORT} ssl default_server;/listen ${HTTPS_PORT} ssl http2 default_server;/" \
           -e "s/listen \[::\]:${HTTPS_PORT} ssl default_server;/listen [::]:${HTTPS_PORT} ssl http2 default_server;/" \
           "/etc/nginx/sites-available/${APP_NAME}"
fi

# PHP-FPM: robuste Standardwerte
PHP_INI="/etc/php/${PHP_VER}/fpm/php.ini"
if [[ -f "${PHP_INI}" ]]; then
    sed -i -e 's/^;\?expose_php\s*=.*/expose_php = Off/' \
           -e 's/^;\?session.cookie_httponly\s*=.*/session.cookie_httponly = 1/' \
           -e 's/^;\?session.use_strict_mode\s*=.*/session.use_strict_mode = 1/' "${PHP_INI}"
fi

nginx -t >/dev/null 2>&1 || { nginx -t; die "Nginx-Konfiguration fehlerhaft."; }
systemctl restart "${PHP_FPM_SERVICE}"
systemctl reload nginx || systemctl restart nginx
ok "Nginx: HTTP :${HTTP_PORT} → HTTPS :${HTTPS_PORT}, PHP-FPM via ${PHP_SOCK}"

# -----------------------------------------------------------------------------
# 9) Collector als systemd-Timer (minütlich)
# -----------------------------------------------------------------------------
step "Collector als systemd-Timer einrichten"
cat > "/etc/systemd/system/${APP_NAME}-collector.service" <<UNIT
[Unit]
Description=quickinfo – Systemmetriken erfassen
After=network.target ${DB_SERVICE}.service
Wants=${DB_SERVICE}.service

[Service]
Type=oneshot
User=root
Nice=10
IOSchedulingClass=idle
ExecStart=/usr/bin/php ${APP_DIR}/collector/collector.php
TimeoutStartSec=90
UNIT

cat > "/etc/systemd/system/${APP_NAME}-collector.timer" <<UNIT
[Unit]
Description=quickinfo – Collector jede Minute ausführen

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=true
Unit=${APP_NAME}-collector.service

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now "${APP_NAME}-collector.timer" >/dev/null 2>&1
ok "Timer ${APP_NAME}-collector.timer aktiv"

# Erste Messung sofort durchführen
if php "${APP_DIR}/collector/collector.php" --verbose --maintenance; then
    ok "Erste Messung erfolgreich"
else
    warn "Erste Messung fehlgeschlagen – bitte 'php ${APP_DIR}/collector/collector.php --verbose' prüfen"
fi

# -----------------------------------------------------------------------------
# 10) Firewall (optional)
# -----------------------------------------------------------------------------
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
    step "UFW-Regeln"
    ufw allow "${HTTP_PORT}/tcp" >/dev/null 2>&1 || true
    ufw allow "${HTTPS_PORT}/tcp" >/dev/null 2>&1 || true
    ok "Ports ${HTTP_PORT} und ${HTTPS_PORT} freigegeben"
fi

# -----------------------------------------------------------------------------
# Zusammenfassung
# -----------------------------------------------------------------------------
URL_HOST="${PRIMARY_IP:-${HOST_SHORT}}"
URL="https://${URL_HOST}"
[[ "${HTTPS_PORT}" != "443" ]] && URL="${URL}:${HTTPS_PORT}"

printf '\n%s╔══════════════════════════════════════════════════════════════╗%s\n' "${c_green}${c_bold}" "${c_reset}"
printf '%s║  quickinfo wurde erfolgreich installiert                     ║%s\n' "${c_green}${c_bold}" "${c_reset}"
printf '%s╚══════════════════════════════════════════════════════════════╝%s\n\n' "${c_green}${c_bold}" "${c_reset}"
printf '  %sURL:%s            %s\n' "${c_bold}" "${c_reset}" "${URL}"
printf '  %sBenutzer:%s       %s\n' "${c_bold}" "${c_reset}" "${ADMIN_USER}"
printf '  %sPasswort:%s       %s\n\n' "${c_bold}" "${c_reset}" "${ADMIN_PASS}"
printf '  Konfiguration:  %s\n' "${CONF_FILE}"
printf '  Webroot:        %s/public\n' "${APP_DIR}"
printf '  Collector:      systemctl status %s-collector.timer\n' "${APP_NAME}"
printf '  Logs:           journalctl -u %s-collector.service -n 50\n\n' "${APP_NAME}"
printf '  %sHinweis:%s Das Zertifikat ist selbst signiert – der Browser zeigt beim ersten Aufruf eine Warnung.\n' "${c_yellow}" "${c_reset}"
printf '  %sHinweis:%s Bitte das Passwort notieren; es wird nicht gespeichert und kann über die Weboberfläche geändert werden.\n\n' "${c_yellow}" "${c_reset}"

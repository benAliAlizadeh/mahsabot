#!/usr/bin/env bash

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

INSTALL_DIR="/var/www/mahsabot"
DB_NAME="mahsabot_db"
DB_USER="mahsabot_user"
REPO_URL="https://github.com/benAliAlizadeh/mahsabot.git"
ARCHIVE_URL="https://github.com/benAliAlizadeh/mahsabot/archive/refs/heads/main.zip"

BOT_TOKEN=""
ADMIN_ID=""
BOT_USERNAME=""
BOT_DOMAIN=""
BOT_URL=""
DB_PASS=""

print_banner() {
    clear
    echo "=============================================================="
    echo "                       MahsaBot Installer"
    echo "             Telegram VPN Subscription Management"
    echo "              github.com/benAliAlizadeh/mahsabot"
    echo "=============================================================="
    echo ""
}

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

log_step() {
    echo ""
    echo -e "${BLUE}==> $1${NC}"
    echo ""
}

abort() {
    log_error "$1"
    exit 1
}

is_safe_db_password() {
    local pass="$1"
    [[ "${pass}" =~ ^[A-Za-z0-9@#%+=:.,/_-]+$ ]]
}

sync_database_credentials() {
    local db_name="$1"
    local db_user="$2"
    local db_pass="$3"

    mysql -e "CREATE DATABASE IF NOT EXISTS \`${db_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '${db_user}'@'localhost' IDENTIFIED BY '${db_pass}';"
    mysql -e "ALTER USER '${db_user}'@'localhost' IDENTIFIED BY '${db_pass}';"
    mysql -e "GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${db_user}'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"
}

check_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        abort "This script must run as root. Use: sudo bash mahsabot.sh"
    fi
}

check_os() {
    if [[ ! -f /etc/os-release ]]; then
        log_warn "Cannot verify operating system."
        return
    fi
    if ! grep -qiE 'ubuntu|debian' /etc/os-release; then
        log_warn "This installer is optimized for Ubuntu/Debian."
    fi
}

read_non_empty() {
    local prompt="$1"
    local value=""
    while [[ -z "${value}" ]]; do
        read -r -p "${prompt}" value
    done
    echo "${value}"
}

sanitize_domain() {
    local raw="$1"
    raw="${raw#http://}"
    raw="${raw#https://}"
    raw="${raw%%/*}"
    echo "${raw}"
}

install_dependencies() {
    log_step "Installing dependencies"

    apt-get update -y
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        apache2 mysql-server php php-mysql php-curl php-xml php-soap php-gd \
        php-mbstring php-gmp php-bcmath unzip curl git certbot python3-certbot-apache

    systemctl enable apache2 mysql
    systemctl start apache2 mysql

    log_info "Dependencies installed and services started."
}

setup_database() {
    log_step "Configuring database"

    while true; do
        read -r -s -p "Enter database password for ${DB_USER} (leave empty to auto-generate): " DB_PASS
        echo ""

        if [[ -z "${DB_PASS}" ]]; then
            local chars='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
            DB_PASS=""
            for ((i = 0; i < 24; i++)); do
                DB_PASS+="${chars:RANDOM % ${#chars}:1}"
            done
            log_warn "Generated database password: ${DB_PASS}"
            log_warn "Save this password in a secure place."
            break
        fi

        if ! is_safe_db_password "${DB_PASS}"; then
            log_error "Invalid database password characters."
            echo "Allowed characters: A-Z a-z 0-9 @ # % + = : . , / _ -"
            echo "Please enter a safe ASCII password."
            continue
        fi

        break
    done

    sync_database_credentials "${DB_NAME}" "${DB_USER}" "${DB_PASS}"

    log_info "Database and user are ready."
}

download_bot() {
    log_step "Downloading MahsaBot files"

    if [[ -d "${INSTALL_DIR}" ]]; then
        log_warn "${INSTALL_DIR} already exists."
        read -r -p "Overwrite existing installation? (y/N): " confirm
        if [[ "${confirm}" != "y" && "${confirm}" != "Y" ]]; then
            abort "Installation canceled by user."
        fi
        rm -rf "${INSTALL_DIR}"
    fi

    if git clone --depth 1 "${REPO_URL}" "${INSTALL_DIR}"; then
        log_info "Repository cloned successfully."
    else
        log_warn "git clone failed. Trying GitHub archive fallback."

        local tmp_dir
        tmp_dir="$(mktemp -d)"
        if ! curl -fL "${ARCHIVE_URL}" -o "${tmp_dir}/main.zip"; then
            rm -rf "${tmp_dir}"
            abort "Failed to download repository archive."
        fi
        if ! unzip -q "${tmp_dir}/main.zip" -d "${tmp_dir}"; then
            rm -rf "${tmp_dir}"
            abort "Failed to extract repository archive."
        fi
        if [[ ! -d "${tmp_dir}/mahsabot-main" ]]; then
            rm -rf "${tmp_dir}"
            abort "Archive content is invalid."
        fi

        mkdir -p "${INSTALL_DIR}"
        cp -a "${tmp_dir}/mahsabot-main/." "${INSTALL_DIR}/"
        rm -rf "${tmp_dir}"
        log_info "Archive fallback download succeeded."
    fi

    local required_files=(
        "bot.php"
        "core/bootstrap.php"
        "setup/schema.php"
        "config.sample.php"
    )
    local missing=0
    for required_file in "${required_files[@]}"; do
        if [[ ! -f "${INSTALL_DIR}/${required_file}" ]]; then
            log_error "Missing required file: ${required_file}"
            missing=1
        fi
    done
    if [[ "${missing}" -ne 0 ]]; then
        abort "Installation files are incomplete."
    fi

    chown -R www-data:www-data "${INSTALL_DIR}"
    chmod -R 755 "${INSTALL_DIR}"
    log_info "Project files are ready in ${INSTALL_DIR}."
}

configure_bot() {
    log_step "Writing bot configuration"

    BOT_TOKEN="$(read_non_empty 'Enter Telegram Bot Token: ')"
    ADMIN_ID="$(read_non_empty 'Enter Telegram Admin ID (numeric): ')"
    if [[ ! "${ADMIN_ID}" =~ ^[0-9]+$ ]]; then
        abort "Admin ID must be numeric."
    fi
    BOT_USERNAME="$(read_non_empty 'Enter Bot Username (without @): ')"

    local raw_domain
    raw_domain="$(read_non_empty 'Enter domain (example.com): ')"
    BOT_DOMAIN="$(sanitize_domain "${raw_domain}")"
    if [[ -z "${BOT_DOMAIN}" ]]; then
        abort "Domain is invalid."
    fi

    BOT_URL="https://${BOT_DOMAIN}/"

    cat >"${INSTALL_DIR}/config.php" <<PHPEOF
<?php
// MahsaBot Configuration - generated by installer
define('ESI_DB_HOST', 'localhost');
define('ESI_DB_NAME', '${DB_NAME}');
define('ESI_DB_USER', '${DB_USER}');
define('ESI_DB_PASS', '${DB_PASS}');

define('ESI_BOT_TOKEN', '${BOT_TOKEN}');
define('ESI_ADMIN_ID', ${ADMIN_ID});
define('ESI_BOT_USERNAME', '${BOT_USERNAME}');
define('ESI_BOT_URL', '${BOT_URL}');
define('ESI_BOT_DOMAIN', '${BOT_DOMAIN}');
define('ESI_WEBHOOK_SECURITY', 'proxy'); // off | strict | proxy

date_default_timezone_set('Asia/Tehran');
define('ESI_DEBUG', false);
PHPEOF

    chown www-data:www-data "${INSTALL_DIR}/config.php"
    chmod 640 "${INSTALL_DIR}/config.php"
    log_info "config.php created."
}

load_runtime_config() {
    if [[ ! -r "${INSTALL_DIR}/config.php" ]]; then
        abort "config.php not found in ${INSTALL_DIR}"
    fi

    BOT_TOKEN="$(php -r "require '${INSTALL_DIR}/config.php'; echo ESI_BOT_TOKEN;")"
    BOT_URL="$(php -r "require '${INSTALL_DIR}/config.php'; echo ESI_BOT_URL;")"
    BOT_DOMAIN="$(php -r "require '${INSTALL_DIR}/config.php'; echo ESI_BOT_DOMAIN;")"
    DB_NAME="$(php -r "require '${INSTALL_DIR}/config.php'; echo ESI_DB_NAME;")"
    DB_USER="$(php -r "require '${INSTALL_DIR}/config.php'; echo ESI_DB_USER;")"
    DB_PASS="$(php -r "require '${INSTALL_DIR}/config.php'; echo ESI_DB_PASS;")"

    if [[ -n "${BOT_URL}" && "${BOT_URL}" != */ ]]; then
        BOT_URL="${BOT_URL}/"
    fi
}

sync_database_credentials_from_config() {
    log_step "Repairing database credentials from config.php"

    load_runtime_config

    if ! is_safe_db_password "${DB_PASS}"; then
        abort "Database password in config.php contains unsupported characters for auto-repair. Use a safe ASCII password and rerun installer."
    fi

    sync_database_credentials "${DB_NAME}" "${DB_USER}" "${DB_PASS}"
    log_info "Database credentials synced from config.php."
}

verify_database_connection() {
    log_step "Verifying database connection"

    if [[ ! -r "${INSTALL_DIR}/config.php" ]]; then
        abort "config.php not found in ${INSTALL_DIR}"
    fi

    local verify_result
    verify_result="$(php -r "
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    require '${INSTALL_DIR}/config.php';
    \$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
    \$db->set_charset('utf8mb4');
    \$db->close();
    echo 'DB_OK';
} catch (Throwable \$e) {
    fwrite(STDERR, \$e->getMessage());
    exit(1);
}
" 2>&1 || true)"

    if [[ "${verify_result}" != "DB_OK" ]]; then
        load_runtime_config
        abort "Database connection failed using config.php: ${verify_result}
Repair hint:
1) Read password from ${INSTALL_DIR}/config.php (ESI_DB_PASS)
2) Run:
mysql -e \"ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '<PASSWORD_FROM_CONFIG>'; FLUSH PRIVILEGES;\""
    fi

    log_info "Database connection via config.php is valid."
}

configure_apache() {
    log_step "Configuring Apache"

    if [[ -z "${BOT_DOMAIN}" ]]; then
        load_runtime_config
    fi

    cat >/etc/apache2/sites-available/mahsabot.conf <<APEOF
<VirtualHost *:80>
    ServerName ${BOT_DOMAIN}
    DocumentRoot ${INSTALL_DIR}

    <Directory ${INSTALL_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Deny direct access to internal application layers
    <DirectoryMatch "^${INSTALL_DIR}/(core|handlers|locale|panels|setup)/">
        Require all denied
    </DirectoryMatch>

    # Only expose subscription endpoint under /services
    <Directory ${INSTALL_DIR}/services>
        <Files "subscription.php">
            Require all granted
        </Files>
        <FilesMatch "^(?!subscription\\.php$).*\\.php$">
            Require all denied
        </FilesMatch>
    </Directory>

    # Only expose gateway entry points
    <Directory ${INSTALL_DIR}/gateway>
        <FilesMatch "^(initiate|callback)\\.php$">
            Require all granted
        </FilesMatch>
        <FilesMatch "^(?!initiate\\.php$|callback\\.php$).*\\.php$">
            Require all denied
        </FilesMatch>
    </Directory>

    # Deny common sensitive files
    <FilesMatch "^(config\\.php|config\\.sample\\.php|backup\\.sh|mahsabot\\.sh|README(\\-fa)?\\.md|LICENSE)$">
        Require all denied
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/mahsabot_error.log
    CustomLog \${APACHE_LOG_DIR}/mahsabot_access.log combined
</VirtualHost>
APEOF

    a2enmod rewrite headers ssl >/dev/null 2>&1
    a2ensite mahsabot.conf >/dev/null 2>&1
    a2dissite 000-default >/dev/null 2>&1 || true

    if ! apache2ctl -t >/dev/null 2>&1; then
        abort "Apache configuration test failed. Check /etc/apache2/sites-available/mahsabot.conf"
    fi

    systemctl reload apache2
    log_info "Apache is configured."
}

setup_ssl() {
    log_step "SSL setup"

    read -r -p "Enable Let's Encrypt SSL now? (Y/n): " ssl_confirm
    if [[ "${ssl_confirm}" == "n" || "${ssl_confirm}" == "N" ]]; then
        log_warn "Skipping SSL setup."
        return
    fi

    local le_email
    le_email="$(read_non_empty 'Enter email for Let'"'"'s Encrypt: ')"
    if certbot --apache -d "${BOT_DOMAIN}" --non-interactive --agree-tos -m "${le_email}"; then
        log_info "SSL certificate installed."
    else
        log_warn "SSL installation failed. Continue without SSL for now."
    fi
}

create_database_tables() {
    log_step "Creating database schema"

    local runner
    runner="$(mktemp /tmp/mahsabot-schema-XXXX.php)"

    cat >"${runner}" <<PHP
<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    require_once '${INSTALL_DIR}/config.php';
    require_once '${INSTALL_DIR}/core/database.php';
    require_once '${INSTALL_DIR}/setup/schema.php';

    \$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
    \$db->set_charset('utf8mb4');

    if (!function_exists('esi_create_schema') || !function_exists('esi_seed_defaults')) {
        throw new RuntimeException('Schema functions were not loaded.');
    }

    if (!esi_create_schema(\$db)) {
        throw new RuntimeException('esi_create_schema returned false.');
    }

    esi_seed_defaults(\$db);

    \$required = ['esi_members', 'esi_options'];
    foreach (\$required as \$tableName) {
        \$stmt = \$db->prepare('SHOW TABLES LIKE ?');
        \$stmt->bind_param('s', \$tableName);
        \$stmt->execute();
        \$exists = \$stmt->get_result()->num_rows > 0;
        \$stmt->close();
        if (!\$exists) {
            throw new RuntimeException('Required table missing: ' . \$tableName);
        }
    }

    \$db->close();
    echo "SCHEMA_OK";
} catch (Throwable \$e) {
    fwrite(STDERR, \$e->getMessage() . PHP_EOL);
    exit(1);
}
PHP

    if ! php "${runner}" >/tmp/mahsabot-schema.log 2>&1; then
        local schema_err
        schema_err="$(cat /tmp/mahsabot-schema.log 2>/dev/null || true)"
        rm -f "${runner}" /tmp/mahsabot-schema.log
        abort "Database schema setup failed. ${schema_err}"
    fi

    rm -f "${runner}" /tmp/mahsabot-schema.log
    log_info "Database schema verified."
}

setup_cron_jobs() {
    log_step "Configuring cron jobs"

    cat >/etc/cron.d/mahsabot <<CRONEOF
# MahsaBot cron jobs
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

* * * * * www-data php ${INSTALL_DIR}/services/broadcaster.php >> /var/log/mahsabot_cron.log 2>&1
*/2 * * * * www-data php ${INSTALL_DIR}/services/tron_verifier.php >> /var/log/mahsabot_cron.log 2>&1
0 */6 * * * www-data php ${INSTALL_DIR}/services/expiry_monitor.php >> /var/log/mahsabot_cron.log 2>&1
*/5 * * * * www-data php ${INSTALL_DIR}/services/gift_distributor.php >> /var/log/mahsabot_cron.log 2>&1
0 8 * * * www-data php ${INSTALL_DIR}/services/report_sender.php >> /var/log/mahsabot_cron.log 2>&1
CRONEOF

    chmod 644 /etc/cron.d/mahsabot
    log_info "Cron jobs written to /etc/cron.d/mahsabot."
}

set_webhook() {
    log_step "Setting Telegram webhook"

    if [[ -z "${BOT_TOKEN}" || -z "${BOT_URL}" ]]; then
        load_runtime_config
    fi

    local webhook_url
    webhook_url="${BOT_URL}bot.php"

    local set_result
    set_result="$(curl -fsS "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?url=${webhook_url}" || true)"
    if ! echo "${set_result}" | grep -q '"ok":true'; then
        abort "setWebhook failed: ${set_result}"
    fi
    log_info "Webhook set to ${webhook_url}"

    local info_result
    info_result="$(curl -fsS "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo" || true)"
    if echo "${info_result}" | grep -q '"ok":true'; then
        local actual_url
        local last_error
        actual_url="$(echo "${info_result}" | sed -n 's/.*"url":"\([^"]*\)".*/\1/p')"
        last_error="$(echo "${info_result}" | sed -n 's/.*"last_error_message":"\([^"]*\)".*/\1/p')"

        log_info "Webhook info url: ${actual_url:-unknown}"
        if [[ -n "${last_error}" ]]; then
            log_warn "Telegram webhook last_error_message: ${last_error}"
        else
            log_info "Telegram reports no webhook error."
        fi
    else
        log_warn "Could not read getWebhookInfo response: ${info_result}"
    fi
}

post_install_checks() {
    log_step "Running post-install checks"

    if systemctl is-active --quiet apache2; then
        log_info "Apache service is active."
    else
        abort "Apache service is not active."
    fi

    if systemctl is-active --quiet mysql; then
        log_info "MySQL service is active."
    else
        abort "MySQL service is not active."
    fi

    if [[ -r "${INSTALL_DIR}/config.php" ]]; then
        log_info "config.php exists and is readable."
    else
        abort "config.php is missing or unreadable."
    fi

    local table_check
    table_check="$(php -r "
require '${INSTALL_DIR}/config.php';
\$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
if (\$db->connect_error) { exit(2); }
\$needed = ['esi_members', 'esi_options'];
foreach (\$needed as \$t) {
    \$stmt = \$db->prepare('SHOW TABLES LIKE ?');
    \$stmt->bind_param('s', \$t);
    \$stmt->execute();
    if (\$stmt->get_result()->num_rows === 0) { exit(3); }
    \$stmt->close();
}
echo 'OK';
" 2>/dev/null || true)"

    if [[ "${table_check}" != "OK" ]]; then
        abort "Required tables are missing or database connection failed."
    fi
    log_info "Required tables exist."

    if [[ -f /etc/cron.d/mahsabot ]]; then
        log_info "Cron file exists: /etc/cron.d/mahsabot"
    else
        abort "Cron file is missing."
    fi
}

show_summary() {
    echo ""
    echo "=============================================================="
    echo "Installation completed"
    echo "=============================================================="
    echo "Install path : ${INSTALL_DIR}"
    echo "Bot URL      : ${BOT_URL}"
    echo "Database     : ${DB_NAME}"
    echo "DB user      : ${DB_USER}"
    echo ""
    echo "Next checks:"
    echo "1) systemctl status apache2 mysql"
    echo "2) cat /etc/cron.d/mahsabot"
    echo "3) curl -s https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
    echo "4) send /start to your bot in Telegram"
    echo ""
    echo "Logs:"
    echo "- /var/log/apache2/mahsabot_error.log"
    echo "- /var/log/mahsabot_cron.log"
    echo "=============================================================="
}

update_installation() {
    log_step "Updating existing installation"

    if [[ ! -d "${INSTALL_DIR}" ]]; then
        abort "Install directory not found: ${INSTALL_DIR}"
    fi

    if [[ -d "${INSTALL_DIR}/.git" ]]; then
        git -C "${INSTALL_DIR}" pull --ff-only || abort "git pull failed."
    else
        log_warn "No .git directory detected. Using archive refresh."
        local tmp_dir
        tmp_dir="$(mktemp -d)"
        if ! curl -fL "${ARCHIVE_URL}" -o "${tmp_dir}/main.zip"; then
            rm -rf "${tmp_dir}"
            abort "Archive refresh failed: download error."
        fi
        if ! unzip -q "${tmp_dir}/main.zip" -d "${tmp_dir}"; then
            rm -rf "${tmp_dir}"
            abort "Archive refresh failed: unzip error."
        fi
        if [[ ! -d "${tmp_dir}/mahsabot-main" ]]; then
            rm -rf "${tmp_dir}"
            abort "Archive refresh failed: invalid content."
        fi
        cp -a "${tmp_dir}/mahsabot-main/." "${INSTALL_DIR}/"
        rm -rf "${tmp_dir}"
    fi

    chown -R www-data:www-data "${INSTALL_DIR}"
    chmod -R 755 "${INSTALL_DIR}"

    sync_database_credentials_from_config
    verify_database_connection
    configure_apache
    create_database_tables
    setup_cron_jobs
    set_webhook
    post_install_checks
    show_summary
}

backup_database() {
    log_step "Creating database backup"

    load_runtime_config
    local backup_file
    backup_file="/root/mahsabot_backup_$(date +%Y%m%d_%H%M%S).sql"

    if mysqldump -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" >"${backup_file}"; then
        log_info "Backup saved to ${backup_file}"
    else
        abort "Backup failed."
    fi
}

uninstall() {
    print_banner
    echo "WARNING: This will remove MahsaBot files, database, and web config."
    read -r -p "Continue uninstall? (y/N): " confirm

    if [[ "${confirm}" != "y" && "${confirm}" != "Y" ]]; then
        log_info "Uninstall canceled."
        return
    fi

    rm -rf "${INSTALL_DIR}"
    rm -f /etc/cron.d/mahsabot
    rm -f /etc/apache2/sites-available/mahsabot.conf
    a2dissite mahsabot.conf >/dev/null 2>&1 || true
    systemctl reload apache2 || true

    mysql -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`;" >/dev/null 2>&1 || true
    mysql -e "DROP USER IF EXISTS '${DB_USER}'@'localhost';" >/dev/null 2>&1 || true

    log_info "MahsaBot uninstalled."
}

install_flow() {
    install_dependencies
    setup_database
    download_bot
    configure_bot
    verify_database_connection
    configure_apache
    setup_ssl
    create_database_tables
    setup_cron_jobs
    set_webhook
    post_install_checks
    show_summary
}

reset_webhook_flow() {
    load_runtime_config
    set_webhook
}

main_menu() {
    check_root
    check_os
    print_banner

    echo "Choose an action:"
    echo "1) Full install MahsaBot"
    echo "2) Update/repair existing install"
    echo "3) Uninstall MahsaBot"
    echo "4) Reset webhook only"
    echo "5) Backup database"
    echo "0) Exit"
    echo ""
    read -r -p "Your choice: " choice

    case "${choice}" in
        1) install_flow ;;
        2) update_installation ;;
        3) uninstall ;;
        4) reset_webhook_flow ;;
        5) backup_database ;;
        0) exit 0 ;;
        *) abort "Invalid menu choice." ;;
    esac
}

main_menu "$@"

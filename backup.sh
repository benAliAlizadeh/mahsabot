#!/bin/bash
# MahsaBot Database Backup
# Usage: bash backup.sh

INSTALL_DIR="/var/www/mahsabot"
BACKUP_DIR="/root/mahsabot_backups"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

if [[ ! -f "${INSTALL_DIR}/config.php" ]]; then
    echo "❌ config.php not found"
    exit 1
fi

DB_NAME=$(php -r "require '${INSTALL_DIR}/config.php'; echo ESI_DB_NAME;")
DB_USER=$(php -r "require '${INSTALL_DIR}/config.php'; echo ESI_DB_USER;")
DB_PASS=$(php -r "require '${INSTALL_DIR}/config.php'; echo ESI_DB_PASS;")

BACKUP_FILE="${BACKUP_DIR}/mahsabot_${DATE}.sql.gz"
mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$BACKUP_FILE"

if [[ $? -eq 0 ]]; then
    echo "✅ Backup created: ${BACKUP_FILE}"
    # Keep only last 10 backups
    ls -t ${BACKUP_DIR}/mahsabot_*.sql.gz | tail -n +11 | xargs -r rm
    echo "🗑️ Old backups cleaned"
else
    echo "❌ Backup failed"
    exit 1
fi

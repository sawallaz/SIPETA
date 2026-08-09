#!/usr/bin/env bash
#
# scripts/backup.sh — Database backup helper (Phase 1.5)
#
# Dumps the SIPETA MySQL database to:
#   storage/app/backups/sipeta_<YYYY-MM-DD_HHMMSS>.sql.gz
#
# Credentials are read from .env (via php artisan tinker) so the password is
# never embedded in this script. Uses mysqldump --single-transaction so the
# dump does not lock the (single-operator) database for long.
#
# Optional automatic scheduling (install on the operator PC only, NOT here):
#   0 2 * * * /home/awa/Documents/SIPETA/scripts/backup.sh >> /var/log/sipeta-backup.log 2>&1
#
# Requirement: the `mysqldump` client must be installed (part of MySQL server/client).
set -euo pipefail

cd "$(dirname "$0")/.."

BACKUP_DIR="storage/app/backups"
mkdir -p "$BACKUP_DIR"

# Read DB credentials from .env via artisan tinker (no password in this file).
DB_DATABASE="$(php artisan tinker --execute='echo config("database.connections.mysql.database");' 2>/dev/null | tail -1)"
DB_USERNAME="$(php artisan tinker --execute='echo config("database.connections.mysql.username");' 2>/dev/null | tail -1)"
DB_PASSWORD="$(php artisan tinker --execute='echo config("database.connections.mysql.password");' 2>/dev/null | tail -1)"
DB_HOST="$(php artisan tinker --execute='echo config("database.connections.mysql.host");' 2>/dev/null | tail -1)"
DB_PORT="$(php artisan tinker --execute='echo config("database.connections.mysql.port");' 2>/dev/null | tail -1)"

if [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ]; then
  echo "ERROR: Could not read DB credentials from .env (is the app configured?)" >&2
  exit 1
fi

STAMP="$(date +%Y-%m-%d_%H%M%S)"
OUT="$BACKUP_DIR/sipeta_${STAMP}.sql.gz"

echo "==> Backing up database '$DB_DATABASE' -> $OUT"

if [ -n "$DB_PASSWORD" ]; then
  mysqldump --single-transaction --host="$DB_HOST" --port="$DB_PORT" \
    --user="$DB_USERNAME" --password="$DB_PASSWORD" "$DB_DATABASE" | gzip > "$OUT"
else
  mysqldump --single-transaction --host="$DB_HOST" --port="$DB_PORT" \
    --user="$DB_USERNAME" "$DB_DATABASE" | gzip > "$OUT"
fi

echo "==> Backup complete: $OUT ($(du -h "$OUT" | cut -f1))"

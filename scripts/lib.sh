# Shared helpers for install.sh and update.sh. Source this file; do not run it.
# shellcheck shell=bash

APP_DIR=/var/www/html/fax2email
BACKUP_DIR=/var/www/html/fax2email-backups
WEB_USER=www-data
LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

die() { echo "Error: $*" >&2; exit 1; }
step() { echo "==> $*"; }

# Read KEY from the app's .env (first argument), stripping surrounding quotes.
env_value() {
    grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/' || true
}

# Set KEY=VALUE in the app's .env, quoting the value when needed.
set_env() {
    php "$LIB_DIR/set-env.php" "$APP_DIR/.env" "$1" "$2"
}

artisan() { runuser -u "$WEB_USER" -- php "$APP_DIR/artisan" "$@"; }

php_fpm_service() {
    systemctl list-unit-files --type=service --no-legend 'php*-fpm.service' | awk '{print $1}' | head -n1
}

# Sync a release tree into the app directory, keeping .env and user data.
sync_into_app() {
    mkdir -p "$APP_DIR"
    rsync -a --delete \
        --exclude=/.env --exclude=/storage/ --exclude=/public/storage \
        "$1/" "$APP_DIR/"
    # Add any storage folders a new release introduces, without touching existing data.
    rsync -a --ignore-existing "$1/storage/" "$APP_DIR/storage/"
}

fix_permissions() {
    chown -R "$WEB_USER:$WEB_USER" "$APP_DIR"
    find "$APP_DIR" -type d -exec chmod 2775 {} +
    find "$APP_DIR" -type f -exec chmod 664 {} +
    chmod 775 "$APP_DIR/artisan" "$APP_DIR"/scripts/*.sh
    [[ -f "$APP_DIR/.env" ]] && chmod 640 "$APP_DIR/.env"
    if command -v restorecon >/dev/null; then restorecon -R "$APP_DIR"; fi
}

# Copy the app's systemd units into place and reload systemd.
install_units() {
    install -m 644 "$APP_DIR"/deploy/systemd/fax2email-* /etc/systemd/system/
    systemctl daemon-reload
}

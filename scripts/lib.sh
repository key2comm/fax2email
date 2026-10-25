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

SYSTEM_LIB=/usr/local/lib/fax2email

# Install the scripts and config that run as root. They are copied from a
# root-owned release tree ($1), never from $APP_DIR, which www-data can write:
# otherwise a compromised website could change code that later runs as root.
install_system_files() {
    local src="$1"
    install -d -m 755 "$SYSTEM_LIB"
    install -m 644 "$src/scripts/lib.sh" "$src/scripts/set-env.php" "$src/deploy/nftables/fax2email.nft" "$SYSTEM_LIB/"
    install -m 755 "$src/scripts/update.sh" /usr/local/sbin/fax2email-update
    install -m 755 "$src/scripts/firewall-apply.sh" /usr/local/sbin/fax2email-firewall-apply
    # Rocky's sudo does not search /usr/local, so make the update command findable there too.
    [[ -d /usr/sbin && ! -e /usr/sbin/fax2email-update ]] && ln -s /usr/local/sbin/fax2email-update /usr/sbin/fax2email-update
    # Older installs linked this to a script inside $APP_DIR.
    rm -f /usr/local/bin/fax2email-update
    if [[ -d /etc/fail2ban ]]; then
        install -m 644 "$src/deploy/fail2ban/action.d/fax2email.conf" /etc/fail2ban/action.d/
        install -m 644 "$src"/deploy/fail2ban/filter.d/fax2email-*.conf /etc/fail2ban/filter.d/
    fi
    if command -v restorecon >/dev/null; then
        restorecon -R "$SYSTEM_LIB" /usr/local/sbin/fax2email-* /etc/fail2ban 2>/dev/null || true
    fi
    install -m 644 "$src"/deploy/systemd/fax2email-* /etc/systemd/system/
    systemctl daemon-reload
    install_freeswitch_config "$src"
}

# Copy FreeSWITCH's static configuration (not vars.xml, which holds the secrets).
# Sets FREESWITCH_CONFIG_CHANGED=1 when a file changed, so callers restart it only then.
FREESWITCH_CONFIG_CHANGED=0
install_freeswitch_config() {
    local src="$1" file dest
    getent group freeswitch >/dev/null || return 0
    install -d -o root -g freeswitch -m 750 /etc/freeswitch /etc/freeswitch/autoload_configs
    while IFS= read -r file; do
        dest="/etc/freeswitch/${file#"$src/deploy/freeswitch/"}"
        if ! cmp -s "$file" "$dest"; then
            install -o root -g freeswitch -m 640 "$file" "$dest"
            FREESWITCH_CONFIG_CHANGED=1
        fi
    done < <(find "$src/deploy/freeswitch" -type f -name '*.xml')
}

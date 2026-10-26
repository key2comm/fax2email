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

# Rocky's Nginx package keeps request bodies (e.g. PDF uploads) in /var/lib/nginx,
# owned by its own nginx user. We run Nginx as www-data, so hand that over too;
# a package upgrade can reset it, so updates call this again.
fix_nginx_dirs() {
    [[ -d /var/lib/nginx ]] && id nginx >/dev/null 2>&1 || return 0
    chown -R "$WEB_USER:$WEB_USER" /var/lib/nginx
}

SYSTEM_LIB=/usr/local/lib/fax2email
NGINX_SITE=/etc/nginx/conf.d/fax2email.conf

php_fpm_socket() {
    local socket
    for socket in /run/php-fpm/www.sock /run/php/php*-fpm.sock; do
        [[ -S "$socket" || -e "$socket" ]] && { echo "$socket"; return; }
    done
    # Not running yet: the distro's default path.
    [[ -d /etc/php-fpm.d ]] && echo /run/php-fpm/www.sock || echo "/run/php/php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm.sock"
}

# Write the portal's Nginx site to $NGINX_SITE from the root-owned templates.
#   write_nginx_config                                    HTTP only
#   write_nginx_config <domain> <cert> <key> <redirect>   HTTP and HTTPS (redirect: 1 = send HTTP to HTTPS)
# Never built from $APP_DIR, so the website cannot inject Nginx settings.
write_nginx_config() {
    local domain="${1:-}" cert="${2:-}" key="${3:-}" redirect="${4:-0}" socket app
    socket="$(php_fpm_socket)"
    app="$(sed "s#__PHP_FPM_SOCKET__#$socket#" "$SYSTEM_LIB/nginx/app.conf")"
    {
        echo "# Written by fax2email; changes are overwritten. Use the portal (Settings > HTTPS) instead."
        echo "server {"
        echo "    listen 80 default_server;"
        echo "    listen [::]:80 default_server;"
        # No catch-all "_" name: default_server already catches every other name, and
        # Rocky's stock nginx.conf claims "_" too (Nginx would warn about the clash).
        [[ -n "$domain" ]] && echo "    server_name $domain;"
        if [[ -n "$domain" && "$redirect" == 1 ]]; then
            echo "    # Let's Encrypt renewals are checked over plain HTTP."
            echo "    location ^~ /.well-known/acme-challenge/ {"
            echo "        root $APP_DIR/public;"
            echo "    }"
            echo "    # FreeSWITCH fetches its trunks and dialplan here over plain HTTP from this"
            echo "    # server itself, so this one URL is not redirected (and only local requests get in)."
            echo "    location = /freeswitch/xml {"
            echo "        allow 127.0.0.1;"
            echo "        allow ::1;"
            echo "        deny all;"
            echo "        fastcgi_pass unix:$socket;"
            echo "        include fastcgi_params;"
            echo "        fastcgi_param SCRIPT_FILENAME $APP_DIR/public/index.php;"
            echo "        fastcgi_param SCRIPT_NAME /index.php;"
            echo "    }"
            echo "    location / {"
            echo "        return 301 https://$domain\$request_uri;"
            echo "    }"
        else
            echo "${app//__FASTCGI_HTTPS__/}"
        fi
        echo "}"
        if [[ -n "$domain" ]]; then
            echo
            echo "server {"
            echo "    listen 443 ssl default_server;"
            echo "    listen [::]:443 ssl default_server;"
            echo "    http2 on;"
            echo "    server_name $domain;"
            echo "    ssl_certificate $cert;"
            echo "    ssl_certificate_key $key;"
            echo "    ssl_protocols TLSv1.2 TLSv1.3;"
            echo "    ssl_prefer_server_ciphers off;"
            echo "    ssl_session_cache shared:fax2email:10m;"
            echo "    ssl_session_timeout 1d;"
            echo "${app//__FASTCGI_HTTPS__/fastcgi_param HTTPS on;}"
            echo "}"
        fi
    } > "$NGINX_SITE"
    command -v restorecon >/dev/null && restorecon "$NGINX_SITE"
    return 0
}

# Install the scripts and config that run as root. They are copied from a
# root-owned release tree ($1), never from $APP_DIR, which www-data can write:
# otherwise a compromised website could change code that later runs as root.
install_system_files() {
    local src="$1"
    install -d -m 755 "$SYSTEM_LIB"
    install -m 644 "$src/scripts/lib.sh" "$src/scripts/set-env.php" "$src/deploy/nftables/fax2email.nft" "$SYSTEM_LIB/"
    install -m 755 "$src/scripts/update.sh" /usr/local/sbin/fax2email-update
    install -m 755 "$src/scripts/firewall-apply.sh" /usr/local/sbin/fax2email-firewall-apply
    install -m 755 "$src/scripts/https-apply.sh" /usr/local/sbin/fax2email-https-apply
    install -d -m 755 "$SYSTEM_LIB/nginx"
    install -m 644 "$src/deploy/nginx/app.conf" "$SYSTEM_LIB/nginx/"
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
    install_selinux_module "$src"
    install -m 644 "$src"/deploy/systemd/fax2email-* /etc/systemd/system/
    systemctl daemon-reload
    install_freeswitch_config "$src"
}

# Load our SELinux rules (deploy/selinux), only when they changed: semodule is slow.
install_selinux_module() {
    local src="$1/deploy/selinux/fax2email.cil" copy="$SYSTEM_LIB/selinux/fax2email.cil"
    command -v selinuxenabled >/dev/null && selinuxenabled && command -v semodule >/dev/null || return 0
    [[ -f "$src" ]] || return 0
    cmp -s "$src" "$copy" && semodule -l | grep -qx fax2email && return 0
    semodule -i "$src"
    install -D -m 644 "$src" "$copy"
    # Re-create the watches that SELinux refused before.
    systemctl try-restart fax2email-firewall.path fax2email-https.path 2>/dev/null || true
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

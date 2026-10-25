#!/usr/bin/env bash
#
# Install fax2email on a fresh Debian 13+ or Rocky Linux 10+ server.
#
# Run it from an unpacked release archive (see README.md):
#   sudo ./fax2email-v0.1.0/scripts/install.sh [--server-name fax.example.com]
#
# Options:
#   --server-name NAME   Hostname or IP the portal is reached at (default: this server's first IP)
#   --admin-name NAME    Name for the first Admin login (prompted if omitted)
#   --admin-email EMAIL  Email for the first Admin login (prompted if omitted)
#   --admin-password-file FILE
#                        Read the first Admin's password from FILE instead of prompting,
#                        for unattended installs. Delete the file afterwards.
#
# Everything is installed under /var/www/html. Re-running it is safe: it keeps the
# existing .env, database and Admin, and repairs anything that is missing.
#
set -Eeuo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$SRC_DIR/scripts/lib.sh"

DEFAULT_REPOSITORY=key2comm/fax2email
DB_NAME=fax2email
DB_USER=fax2email

trap 'echo "Error: install failed at line $LINENO. Fix the problem above and run the installer again." >&2' ERR

detect_os() {
    # shellcheck source=/dev/null
    . /etc/os-release
    local major="${VERSION_ID%%.*}"
    case "$ID" in
        debian)
            (( major >= 13 )) || die "Debian 13 or newer is required (found $PRETTY_NAME)."
            OS=debian ;;
        rocky | almalinux | rhel)
            (( major >= 10 )) || die "Rocky Linux 10 or newer is required (found $PRETTY_NAME)."
            OS=rhel ;;
        *) die "Unsupported OS: $PRETTY_NAME. Use Debian 13+ or Rocky Linux 10+." ;;
    esac
    echo "    $PRETTY_NAME"
}

install_packages() {
    if [[ "$OS" == debian ]]; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -q
        apt-get install -y -q nginx postgresql postgresql-client ca-certificates curl rsync tar \
            php-fpm php-cli php-pgsql php-mbstring php-xml php-intl php-zip php-bcmath php-gd php-curl
    else
        dnf install -y -q nginx postgresql-server postgresql curl rsync tar policycoreutils-python-utils \
            php-fpm php-cli php-pgsql php-mbstring php-xml php-intl php-pecl-zip php-bcmath php-gd \
            php-opcache php-process
    fi
}

# Debian already runs Nginx and PHP-FPM as www-data. Rocky uses nginx/apache,
# so create www-data there and switch both services to it, keeping the two
# distros identical.
configure_web_user() {
    if [[ "$OS" == debian ]]; then
        local version
        version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
        FPM_SERVICE="php$version-fpm"
        FPM_SOCKET="/run/php/php$version-fpm.sock"
        return
    fi

    FPM_SERVICE=php-fpm
    FPM_SOCKET=/run/php-fpm/www.sock
    getent group "$WEB_USER" >/dev/null || groupadd -r "$WEB_USER"
    getent passwd "$WEB_USER" >/dev/null || useradd -r -g "$WEB_USER" -M -d /var/www -s /sbin/nologin "$WEB_USER"
    sed -i -E 's/^user\s+[^;]+;/user www-data;/' /etc/nginx/nginx.conf
    sed -i -E \
        -e 's/^user = .*/user = www-data/' -e 's/^group = .*/group = www-data/' \
        -e 's/^;?listen.owner = .*/listen.owner = www-data/' \
        -e 's/^;?listen.group = .*/listen.group = www-data/' \
        -e 's/^listen.acl_users = .*/;listen.acl_users =/' \
        /etc/php-fpm.d/www.conf
    local dir
    for dir in /var/lib/php/session /var/lib/php/wsdlcache /var/lib/php/opcache; do
        [[ -d "$dir" ]] && chgrp -R "$WEB_USER" "$dir"
    done
    return 0
}

configure_postgres() {
    if [[ "$OS" == rhel ]]; then
        [[ -f /var/lib/pgsql/data/PG_VERSION ]] || postgresql-setup --initdb
        # Rocky defaults local TCP logins to "ident"; Laravel needs password logins.
        sed -i -E 's/^(host\s+all\s+all\s+(127\.0\.0\.1\/32|::1\/128)\s+)ident\s*$/\1scram-sha-256/' \
            /var/lib/pgsql/data/pg_hba.conf
    fi
    systemctl enable --now postgresql
    systemctl reload postgresql

    # Reuse the password from an earlier run so a re-run keeps working.
    DB_PASSWORD="$( [[ -f "$APP_DIR/.env" ]] && env_value DB_PASSWORD || true )"
    [[ -n "$DB_PASSWORD" ]] || DB_PASSWORD="$(php -r 'echo bin2hex(random_bytes(24));')"

    # SQL goes in on stdin so the password never shows up in the process list.
    psql_admin() { (cd / && runuser -u postgres -- psql -v ON_ERROR_STOP=1 -tAq); }
    if [[ "$(psql_admin <<<"SELECT 1 FROM pg_roles WHERE rolname = '$DB_USER'")" != 1 ]]; then
        psql_admin <<<"CREATE ROLE $DB_USER LOGIN"
    fi
    psql_admin <<<"ALTER ROLE $DB_USER PASSWORD '$DB_PASSWORD'"
    if [[ "$(psql_admin <<<"SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'")" != 1 ]]; then
        psql_admin <<<"CREATE DATABASE $DB_NAME OWNER $DB_USER"
    fi
}

configure_env() {
    if [[ ! -f "$APP_DIR/.env" ]]; then
        cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    fi
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "http://$SERVER_NAME"
    set_env LOG_LEVEL info
    set_env DB_CONNECTION pgsql
    set_env DB_HOST 127.0.0.1
    set_env DB_PORT 5432
    set_env DB_DATABASE "$DB_NAME"
    set_env DB_USERNAME "$DB_USER"
    set_env DB_PASSWORD "$DB_PASSWORD"
    [[ -n "$(env_value UPDATE_REPOSITORY)" ]] || set_env UPDATE_REPOSITORY "$DEFAULT_REPOSITORY"
    fix_permissions
    [[ -n "$(env_value APP_KEY)" ]] || artisan key:generate --force
}

configure_selinux() {
    [[ "$OS" == rhel ]] && command -v selinuxenabled >/dev/null && selinuxenabled || return 0

    local path
    for path in "$APP_DIR/storage(/.*)?" "$APP_DIR/bootstrap/cache(/.*)?"; do
        semanage fcontext -a -t httpd_sys_rw_content_t "$path" 2>/dev/null \
            || semanage fcontext -m -t httpd_sys_rw_content_t "$path"
    done
    restorecon -R "$APP_DIR"
    # PHP connects to PostgreSQL over TCP and sends mail over SMTP (25, 465, 587, plus 2525).
    setsebool -P httpd_can_network_connect_db 1 httpd_can_sendmail 1
    semanage port -a -t smtp_port_t -p tcp 2525 2>/dev/null || semanage port -m -t smtp_port_t -p tcp 2525
}

setup_app() {
    systemctl enable --now "$FPM_SERVICE"
    artisan migrate --force
    artisan storage:link >/dev/null 2>&1 || true
    artisan optimize
}

configure_nginx() {
    sed -e "s#__PHP_FPM_SOCKET__#$FPM_SOCKET#" -e "s#server_name _;#server_name $SERVER_NAME _;#" \
        "$APP_DIR/deploy/nginx/fax2email.conf" > /etc/nginx/conf.d/fax2email.conf
    # Debian's stock site also claims default_server on port 80.
    rm -f /etc/nginx/sites-enabled/default
    command -v restorecon >/dev/null && restorecon /etc/nginx/conf.d/fax2email.conf
    nginx -t
    systemctl enable nginx
    systemctl restart nginx
    systemctl restart "$FPM_SERVICE"
}

configure_services() {
    install_units
    systemctl enable --now fax2email-queue.service fax2email-scheduler.timer
    ln -sf "$APP_DIR/scripts/update.sh" /usr/local/bin/fax2email-update
}

configure_firewall() {
    if systemctl is-active --quiet firewalld; then
        firewall-cmd --quiet --permanent --add-service=http --add-service=https
        firewall-cmd --quiet --reload
    elif command -v ufw >/dev/null && ufw status | grep -q '^Status: active'; then
        ufw allow 80/tcp >/dev/null
        ufw allow 443/tcp >/dev/null
    fi
}

create_admin() {
    local args=(--skip-if-exists)
    [[ -n "$ADMIN_NAME" ]] && args+=(--name "$ADMIN_NAME")
    [[ -n "$ADMIN_EMAIL" ]] && args+=(--email "$ADMIN_EMAIL")

    if [[ -n "$ADMIN_PASSWORD_FILE" ]]; then
        [[ -n "$ADMIN_NAME" && -n "$ADMIN_EMAIL" ]] || die "--admin-password-file also needs --admin-name and --admin-email."
        artisan fax2email:create-admin "${args[@]}" --password-stdin <"$ADMIN_PASSWORD_FILE"
        return
    fi

    local attempt
    for attempt in 1 2 3; do
        if artisan fax2email:create-admin "${args[@]}" </dev/tty; then
            return 0
        fi
        echo "Please try again."
    done
    die "Could not create the Admin. Run: sudo -u www-data php $APP_DIR/artisan fax2email:create-admin"
}

main() {
    SERVER_NAME="" ADMIN_NAME="" ADMIN_EMAIL="" ADMIN_PASSWORD_FILE=""
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --server-name) SERVER_NAME="$2"; shift 2 ;;
            --admin-name) ADMIN_NAME="$2"; shift 2 ;;
            --admin-email) ADMIN_EMAIL="$2"; shift 2 ;;
            --admin-password-file) ADMIN_PASSWORD_FILE="$(readlink -f "$2")"; shift 2 ;;
            -h | --help) sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
            *) die "Unknown option: $1 (see --help)" ;;
        esac
    done

    [[ $EUID -eq 0 ]] || die "Run this with sudo."
    [[ -f "$SRC_DIR/artisan" && -d "$SRC_DIR/vendor" && -f "$SRC_DIR/public/build/manifest.json" ]] \
        || die "Run this from an unpacked release archive, not a source checkout."
    [[ "$SRC_DIR" != "$APP_DIR" ]] || die "This copy is already installed. Use: sudo fax2email-update"
    [[ -d "$APP_DIR/.git" ]] && die "$APP_DIR is a development checkout. Install on a different server."
    if [[ -z "$ADMIN_PASSWORD_FILE" ]] && ! { : </dev/tty; } 2>/dev/null; then
        die "The installer needs a terminal to ask for the Admin password (or use --admin-password-file)."
    fi
    [[ -z "$ADMIN_PASSWORD_FILE" || -r "$ADMIN_PASSWORD_FILE" ]] || die "Cannot read $ADMIN_PASSWORD_FILE."
    cd /

    step "Checking the operating system"
    detect_os
    [[ -n "$SERVER_NAME" ]] || SERVER_NAME="$(hostname -I | awk '{print $1}')"
    echo "    Portal address: http://$SERVER_NAME"

    step "Installing packages (Nginx, PHP, PostgreSQL)"
    install_packages
    step "Configuring the www-data web user"
    configure_web_user
    step "Setting up the PostgreSQL database"
    configure_postgres
    step "Copying fax2email v$(tr -d '[:space:]' < "$SRC_DIR/VERSION") to $APP_DIR"
    sync_into_app "$SRC_DIR"
    step "Writing settings to $APP_DIR/.env"
    configure_env
    step "Applying SELinux rules"
    configure_selinux
    step "Creating database tables"
    setup_app
    step "Configuring Nginx"
    configure_nginx
    step "Starting the queue worker and scheduler"
    configure_services
    step "Opening the firewall for HTTP and HTTPS"
    configure_firewall
    step "Creating the first Admin login"
    create_admin

    cat <<EOF

fax2email is installed.

  Portal:   http://$SERVER_NAME
  Files:    $APP_DIR
  Settings: $APP_DIR/.env
  Update:   sudo fax2email-update

Next steps:
  1. Log in and set up the outgoing mail server under Mail server.
  2. While the GitHub repository is private, add UPDATE_GITHUB_TOKEN to .env
     so 'fax2email-update' can download new releases.
EOF
}

main "$@"

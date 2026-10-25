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
            php-fpm php-cli php-pgsql php-mbstring php-xml php-intl php-zip php-bcmath php-gd php-curl \
            nftables fail2ban python3-systemd openssl
    else
        # fail2ban comes from EPEL. Only the server, journal and SELinux parts are
        # installed; the firewalld and sendmail add-ons are not needed.
        dnf install -y -q epel-release
        dnf install -y -q nginx postgresql-server postgresql curl rsync tar policycoreutils-python-utils \
            php-fpm php-cli php-pgsql php-mbstring php-xml php-intl php-pecl-zip php-bcmath php-gd \
            php-opcache php-process nftables fail2ban-server fail2ban-systemd fail2ban-selinux openssl
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
    install_system_files "$SRC_DIR"
    systemctl enable --now fax2email-queue.service fax2email-scheduler.timer
    systemctl enable fax2email-freeswitch.service
    systemctl restart fax2email-freeswitch.service
    systemctl enable fail2ban fax2email-firewall.service
    systemctl enable --now fax2email-firewall.path fax2email-firewall.timer
    # Creates the nftables table, writes the fail2ban jails and starts fail2ban.
    systemctl start fax2email-firewall.service
}

# Opens web, SIP and media ports in the distro firewall. fax2email's own nftables
# table then limits the SIP ports to provider trunks (see deploy/nftables).
configure_firewall() {
    if systemctl is-active --quiet firewalld; then
        firewall-cmd --quiet --permanent --add-service=http --add-service=https \
            --add-port=5060/udp --add-port=5060/tcp --add-port=5061/tcp --add-port=16384-32768/udp
        firewall-cmd --quiet --reload
    elif command -v ufw >/dev/null && ufw status | grep -q '^Status: active'; then
        local port
        for port in 80/tcp 443/tcp 5060/udp 5060/tcp 5061/tcp 16384:32768/udp; do
            ufw allow "$port" >/dev/null
        done
    fi
}

# Builds FreeSWITCH from source (see build-freeswitch.sh) and sets it up to run as
# the freeswitch user, fetching its SIP configuration from the app.
install_freeswitch() {
    "$SRC_DIR/scripts/build-freeswitch.sh"

    getent group freeswitch >/dev/null || groupadd -r freeswitch
    getent passwd freeswitch >/dev/null || useradd -r -g freeswitch -M -d /var/lib/freeswitch -s /sbin/nologin freeswitch
    local dir
    for dir in /var/log/freeswitch /var/lib/freeswitch /var/lib/freeswitch/db /var/lib/freeswitch/fax \
        /var/lib/freeswitch/storage /var/lib/freeswitch/recordings /var/cache/freeswitch; do
        install -d -o freeswitch -g freeswitch -m 750 "$dir"
    done
    # fail2ban's SIP jail only starts once this log exists.
    [[ -f /var/log/freeswitch/freeswitch.log ]] || install -o freeswitch -g freeswitch -m 640 /dev/null /var/log/freeswitch/freeswitch.log
    install -d -o "$WEB_USER" -g "$WEB_USER" -m 2775 "$APP_DIR/storage/app/faxes" "$APP_DIR/storage/app/faxes/incoming"

    # Secrets shared by FreeSWITCH (vars.xml) and the app (.env). Reused on re-runs.
    local esl_password xml_password
    esl_password="$(env_value FREESWITCH_ESL_PASSWORD)"
    xml_password="$(env_value FREESWITCH_XML_PASSWORD)"
    [[ -n "$esl_password" ]] || esl_password="$(php -r 'echo bin2hex(random_bytes(20));')"
    [[ -n "$xml_password" ]] || xml_password="$(php -r 'echo bin2hex(random_bytes(20));')"
    set_env FREESWITCH_ESL_PASSWORD "$esl_password"
    set_env FREESWITCH_XML_PASSWORD "$xml_password"

    install_freeswitch_config "$SRC_DIR"
    cat > /etc/freeswitch/vars.xml <<EOF
<!-- Written by the fax2email installer. Holds secrets; do not share. -->
<include>
  <X-PRE-PROCESS cmd="set" data="esl_password=$esl_password"/>
  <X-PRE-PROCESS cmd="set" data="xml_curl_credentials=freeswitch:$xml_password"/>
  <X-PRE-PROCESS cmd="set" data="default_password=$(php -r 'echo bin2hex(random_bytes(16));')"/>
</include>
EOF
    chown root:freeswitch /etc/freeswitch/vars.xml
    chmod 640 /etc/freeswitch/vars.xml

    # Self-signed certificate for SIP over TLS (port 5061), valid 10 years.
    install -d -o root -g freeswitch -m 750 /etc/freeswitch/tls
    if [[ ! -f /etc/freeswitch/tls/agent.pem ]]; then
        openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=$SERVER_NAME" \
            -keyout /etc/freeswitch/tls/key.pem -out /etc/freeswitch/tls/cert.pem 2>/dev/null
        cat /etc/freeswitch/tls/cert.pem /etc/freeswitch/tls/key.pem > /etc/freeswitch/tls/agent.pem
        cp /etc/freeswitch/tls/cert.pem /etc/freeswitch/tls/cafile.pem
        rm -f /etc/freeswitch/tls/key.pem
        chown root:freeswitch /etc/freeswitch/tls/*.pem
        chmod 640 /etc/freeswitch/tls/*.pem
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
    step "Installing FreeSWITCH (built from source; this takes a few minutes)"
    install_freeswitch
    step "Starting FreeSWITCH, the queue worker, scheduler, firewall and fail2ban"
    configure_services
    step "Opening the firewall for HTTP, HTTPS, SIP and fax media"
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

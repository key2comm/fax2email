#!/usr/bin/env bash
#
# Install fax2email on a fresh Debian 13+ or Rocky Linux 10+ server.
#
# Run it from an unpacked release archive (see README.md):
#   sudo ./fax2email-v0.1.0/scripts/install.sh
#
# It walks you through a few questions (portal address, first Admin, email-to-fax
# domain), shows a summary to confirm, then installs without further prompts.
#
# For automated installs, give the answers as options and add --unattended:
#   --unattended         Don't ask anything; use the options below and existing settings.
#   --server-name NAME   Hostname or IP the portal is reached at (default: this server's first IP)
#   --admin-name NAME    Name for the first Admin login
#   --admin-email EMAIL  Email for the first Admin login
#   --fax-email-domain DOMAIN
#                        Turn on email-to-fax: PDFs emailed to <number>@DOMAIN are faxed.
#                        Point DOMAIN's MX record at this server. Kept on re-runs.
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
            nftables fail2ban python3-systemd openssl ghostscript certbot
    else
        # fail2ban comes from EPEL. Only the server, journal and SELinux parts are
        # installed; the firewalld and sendmail add-ons are not needed.
        dnf install -y -q epel-release
        dnf install -y -q nginx postgresql-server postgresql curl rsync tar policycoreutils-python-utils \
            php-fpm php-cli php-pgsql php-mbstring php-xml php-intl php-pecl-zip php-bcmath php-gd \
            php-opcache php-process nftables fail2ban-server fail2ban-systemd fail2ban-selinux openssl ghostscript certbot
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
    fix_nginx_dirs
    return 0
}

# PHP's default upload limits (2 MB per file) are too small for faxing PDFs.
configure_php_limits() {
    local ini
    if [[ "$OS" == debian ]]; then
        ini="/etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/fpm/conf.d/99-fax2email.ini"
    else
        ini=/etc/php.d/99-fax2email.ini
    fi
    cat > "$ini" <<EOF
; Written by the fax2email installer: room for faxing several PDFs at once.
upload_max_filesize = 20M
post_max_size = 60M
max_file_uploads = 20
EOF
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
    # From bootstrap.sh or the walk-through: lets fax2email-update download releases from the private repo.
    [[ -z "$UPDATE_TOKEN" ]] || set_env UPDATE_GITHUB_TOKEN "$UPDATE_TOKEN"
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
    setsebool -P httpd_can_network_connect_db=1 httpd_can_sendmail=1
    semanage port -a -t smtp_port_t -p tcp 2525 2>/dev/null || semanage port -m -t smtp_port_t -p tcp 2525
}

setup_app() {
    systemctl enable --now "$FPM_SERVICE"
    artisan migrate --force
    artisan storage:link >/dev/null 2>&1 || true
    artisan optimize
}

configure_nginx() {
    # Plain HTTP for now; the HTTPS service (started below) switches to HTTPS if the
    # portal's HTTPS settings say so, e.g. on a re-run.
    install_system_files "$SRC_DIR"
    write_nginx_config
    # Debian's stock site also claims default_server on port 80.
    rm -f /etc/nginx/sites-enabled/default
    nginx -t
    systemctl enable nginx
    systemctl restart nginx
    systemctl restart "$FPM_SERVICE"
}

configure_services() {
    install_system_files "$SRC_DIR"
    systemctl enable --now fax2email-scheduler.timer
    # Restart (not just start) the long-running workers, so a re-run of the installer
    # replaces any worker still running the previous version's code and routes.
    systemctl enable fax2email-queue.service fax2email-fax-events.service
    systemctl restart fax2email-queue.service fax2email-fax-events.service
    systemctl enable fax2email-freeswitch.service
    systemctl restart fax2email-freeswitch.service
    systemctl enable fail2ban fax2email-firewall.service
    systemctl enable --now fax2email-firewall.path fax2email-firewall.timer
    systemctl enable --now fax2email-https.path fax2email-https.timer
    # certbot's own renewal timer would ignore the portal's auto-renew setting; fax2email's daily check renews instead.
    systemctl disable --now certbot.timer certbot-renew.timer 2>/dev/null || true
    # Restores HTTPS on a re-run (does nothing until HTTPS is turned on in the portal).
    systemctl start fax2email-https.service || true
    if [[ -n "$FAX_EMAIL_DOMAIN" ]]; then
        systemctl enable fax2email-opendkim postfix fax2email-email-in.path
        systemctl restart fax2email-opendkim postfix
        systemctl start fax2email-email-in.path
    fi
    # Creates the nftables table, writes the fail2ban jails and starts fail2ban.
    systemctl start fax2email-firewall.service
}

# Opens web, SIP and media ports in the distro firewall. fax2email's own nftables
# table then limits the SIP ports to provider trunks (see deploy/nftables).
configure_firewall() {
    if systemctl is-active --quiet firewalld; then
        firewall-cmd --quiet --permanent --add-service=http --add-service=https \
            --add-port=5060/udp --add-port=5060/tcp --add-port=5061/tcp --add-port=16384-32768/udp \
            ${FAX_EMAIL_DOMAIN:+--add-service=smtp}
        firewall-cmd --quiet --reload
    elif command -v ufw >/dev/null && ufw status | grep -q '^Status: active'; then
        local port
        for port in 80/tcp 443/tcp 5060/udp 5060/tcp 5061/tcp 16384:32768/udp ${FAX_EMAIL_DOMAIN:+25/tcp}; do
            ufw allow "$port" >/dev/null
        done
    fi
}

# Email-to-fax: Postfix accepts mail for the fax domain (number addresses only)
# and drops it in a spool that the app processes; OpenDKIM checks signatures and
# marks its results with a secret AuthservID only the app knows.
configure_email_to_fax() {
    local domain="$1" uid gid authserv escaped
    if [[ "$OS" == debian ]]; then
        echo "postfix postfix/main_mailer_type select No configuration" | debconf-set-selections
        apt-get install -y -q postfix opendkim
    else
        dnf install -y -q postfix opendkim
    fi

    authserv="$(env_value FAX_EMAIL_AUTHSERV_ID)"
    [[ -n "$authserv" ]] || authserv="fax2email-$(php -r 'echo bin2hex(random_bytes(12));')"
    set_env FAX_EMAIL_DOMAIN "$domain"
    set_env FAX_EMAIL_AUTHSERV_ID "$authserv"

    install -d -m 755 /etc/fax2email
    cat > /etc/fax2email/opendkim.conf <<EOF
# Written by the fax2email installer. Verify-only: checks DKIM signatures on
# email-to-fax messages and reports the result for the app.
Syslog              yes
Mode                v
Socket              inet:8891@127.0.0.1
AuthservID          $authserv
AlwaysAddARHeader   yes
SoftwareHeader      no
MinimumKeyBits      1024
EOF
    # Debian's OpenDKIM resolves DNS itself (libunbound) from the root servers. Send its
    # lookups to this server's nameservers instead, so DKIM sees the same DNS as SPF and
    # works where outbound DNS must go through a local resolver.
    local servers
    servers="$(awk '/^nameserver/ {print "    forward-addr: " $2}' /etc/resolv.conf)"
    if ldd /usr/sbin/opendkim 2>/dev/null | grep -q libunbound && [[ -n "$servers" ]]; then
        printf 'server:\n    module-config: "iterator"\nforward-zone:\n    name: "."\n%s\n' "$servers" > /etc/fax2email/opendkim-unbound.conf
        echo "ResolverConfiguration /etc/fax2email/opendkim-unbound.conf" >> /etc/fax2email/opendkim.conf
    fi
    runuser -u opendkim -- /usr/sbin/opendkim -n -x /etc/fax2email/opendkim.conf

    # Only fax2email's own OpenDKIM instance runs (the distro's would want the same port).
    systemctl disable --now opendkim 2>/dev/null || true

    # Postfix's own TLS certificate, so senders can deliver over an encrypted connection.
    if [[ ! -f /etc/fax2email/smtpd.pem ]]; then
        openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=$domain" \
            -keyout /etc/fax2email/smtpd.key -out /etc/fax2email/smtpd.pem 2>/dev/null
        chmod 600 /etc/fax2email/smtpd.key
    fi

    install -d -o "$WEB_USER" -g "$WEB_USER" -m 750 /var/spool/fax2email
    uid="$(id -u "$WEB_USER")"; gid="$(id -g "$WEB_USER")"
    escaped="$(printf '%s' "$domain" | sed 's/\./\\./g')"
    cat > /etc/postfix/fax2email-recipients.regexp <<EOF
/^[0-9]{7,15}@$escaped\$/ OK
/@$escaped\$/ REJECT Send to <fax number>@$domain
EOF

    # Debian's "No configuration" install leaves no main.cf; postconf fills in an empty one.
    [[ -f /etc/postfix/main.cf ]] || install -m 644 /dev/null /etc/postfix/main.cf
    postconf -e \
        "compatibility_level = 3.6" \
        "myhostname = $domain" \
        "mydestination = localhost" \
        "inet_interfaces = all" \
        "inet_protocols = all" \
        "mynetworks = 127.0.0.0/8 [::1]/128" \
        "smtpd_banner = \$myhostname ESMTP" \
        "smtpd_helo_required = yes" \
        "disable_vrfy_command = yes" \
        "biff = no" \
        "virtual_mailbox_domains = $domain" \
        "virtual_mailbox_base = /var/spool/fax2email" \
        "virtual_mailbox_maps = inline:{ @$domain=Maildir/ }" \
        "virtual_uid_maps = static:$uid" \
        "virtual_gid_maps = static:$gid" \
        "virtual_minimum_uid = 1" \
        "virtual_mailbox_limit = 0" \
        "message_size_limit = 62914560" \
        "smtpd_recipient_restrictions = permit_mynetworks, check_recipient_access regexp:/etc/postfix/fax2email-recipients.regexp, reject_unauth_destination" \
        "smtpd_milters = inet:127.0.0.1:8891" \
        "non_smtpd_milters = inet:127.0.0.1:8891" \
        "milter_default_action = accept" \
        "smtpd_tls_cert_file = /etc/fax2email/smtpd.pem" \
        "smtpd_tls_key_file = /etc/fax2email/smtpd.key" \
        "smtpd_tls_security_level = may"

    if [[ "$OS" == rhel ]] && command -v selinuxenabled >/dev/null && selinuxenabled; then
        semanage fcontext -a -t mail_spool_t "/var/spool/fax2email(/.*)?" 2>/dev/null \
            || semanage fcontext -m -t mail_spool_t "/var/spool/fax2email(/.*)?"
        restorecon -R /var/spool/fax2email /etc/fax2email /etc/postfix
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

    # The FreeSWITCH secrets were just added to .env; rebuild the config cache so the
    # app accepts FreeSWITCH's configuration requests.
    artisan optimize >/dev/null
}

create_admin() {
    # Re-runs keep the existing Admin; check first so nothing needs a terminal.
    if artisan fax2email:create-admin --check </dev/null; then
        echo "    An Admin already exists."
        return
    fi
    [[ $UNATTENDED == 0 || -n "$ADMIN_PASSWORD_FILE" ]] || die "There is no Admin yet: pass --admin-name, --admin-email and --admin-password-file."

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

# --- Guided setup -------------------------------------------------------------

# Read one line from the terminal (stdin may be the piped installer itself).
tty_read() {
    local reply
    if [[ "${2:-}" == secret ]]; then
        IFS= read -r -s -p "$1" reply </dev/tty
        echo >/dev/tty
    else
        IFS= read -r -p "$1" reply </dev/tty
    fi
    printf '%s' "$reply"
}

# ask <question> <default> <check function> <error message>: repeat until the answer passes.
ask() {
    local answer
    while true; do
        answer="$(tty_read "  $1${2:+ [$2]}: ")"
        answer="${answer:-$2}"
        if "$3" "$answer"; then
            printf '%s' "$answer"
            return
        fi
        echo "    $4" >/dev/tty
    done
}

valid_host() { local h="${1,,}"; [[ "$h" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]*[a-z0-9])?$ || "$h" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; }
valid_name() { [[ -n "${1// /}" && ${#1} -le 255 ]]; }
valid_email() { [[ "$1" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; }
valid_fax_domain() { local d="${1,,}"; [[ -z "$d" || "$d" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$ ]]; }

walk_through() {
    local fresh=0
    [[ -f "$APP_DIR/.env" ]] || fresh=1
    local default_server default_domain
    default_server="$SERVER_NAME"
    [[ -n "$default_server" || $fresh == 1 ]] || default_server="$(env_value APP_URL | sed -E 's#^https?://##; s#/.*$##')"
    [[ -n "$default_server" ]] || default_server="$(hostname -I | awk '{print $1}')"
    default_domain="$FAX_EMAIL_DOMAIN"
    [[ -n "$default_domain" || $fresh == 1 ]] || default_domain="$(env_value FAX_EMAIL_DOMAIN)"

    cat >/dev/tty <<EOF

fax2email setup
Answer a few questions; press Enter to accept the value in [brackets].
Nothing is installed until you confirm at the end.

1. Portal address
   The hostname or IP address people will use to open the portal.
EOF
    SERVER_NAME="$(ask "Portal address" "$default_server" valid_host "Enter a hostname like fax.example.com or an IP like 203.0.113.10.")"
    SERVER_NAME="${SERVER_NAME,,}"

    if [[ $fresh == 1 && -z "$ADMIN_PASSWORD_FILE" ]]; then
        cat >/dev/tty <<EOF

2. First Admin login
   The person who will manage the portal. They can invite everyone else.
EOF
        ADMIN_NAME="$(ask "Admin name" "$ADMIN_NAME" valid_name "Enter a name.")"
        ADMIN_EMAIL="$(ask "Admin email" "$ADMIN_EMAIL" valid_email "Enter a valid email address.")"
        local password confirm
        while true; do
            password="$(tty_read "  Admin password (at least 8 characters): " secret)"
            if (( ${#password} < 8 )); then echo "    Too short." >/dev/tty; continue; fi
            confirm="$(tty_read "  Type it again: " secret)"
            [[ "$password" == "$confirm" ]] && break
            echo "    The passwords don't match." >/dev/tty
        done
        # Handed to the create-admin step through a private file that is removed afterwards.
        ADMIN_PASSWORD_FILE="$(mktemp -p /run fax2email-admin.XXXXXX)"
        chmod 600 "$ADMIN_PASSWORD_FILE"
        printf '%s' "$password" > "$ADMIN_PASSWORD_FILE"
        WIZARD_PASSWORD_FILE="$ADMIN_PASSWORD_FILE"
    fi

    cat >/dev/tty <<EOF

$([[ $fresh == 1 ]] && echo 3 || echo 2). Email to fax (optional)
   People can fax by emailing PDFs to <fax number>@<this domain>, for example
   15551234567@fax.yourdomain.com. Use a domain (or subdomain) you control and
   point its MX record at this server. Leave blank to skip; you can run the
   installer again later to turn it on.
EOF
    FAX_EMAIL_DOMAIN="$(ask "Email-to-fax domain, e.g. fax.yourdomain.com" "$default_domain" valid_fax_domain "Enter a domain like fax.yourdomain.com, or leave it blank.")"
    FAX_EMAIL_DOMAIN="${FAX_EMAIL_DOMAIN,,}"

    if [[ -z "$UPDATE_TOKEN" && -z "$( [[ -f "$APP_DIR/.env" ]] && env_value UPDATE_GITHUB_TOKEN )" ]]; then
        cat >/dev/tty <<EOF

Updates
   While the fax2email GitHub repository is private, updates need a GitHub token
   with read access to it. Leave blank to skip; you can add it to .env later.
EOF
        UPDATE_TOKEN="$(tty_read "  GitHub token (input hidden): " secret)"
    fi

    cat >/dev/tty <<EOF

Summary
   Portal address:   http://$SERVER_NAME
EOF
    [[ -n "$ADMIN_EMAIL" ]] && echo "   First Admin:      $ADMIN_NAME <$ADMIN_EMAIL>" >/dev/tty
    [[ -n "$UPDATE_TOKEN" ]] && echo "   Updates:          GitHub token provided" >/dev/tty
    if [[ -n "$FAX_EMAIL_DOMAIN" ]]; then
        echo "   Email to fax:     <fax number>@$FAX_EMAIL_DOMAIN" >/dev/tty
    else
        echo "   Email to fax:     off" >/dev/tty
    fi
    echo >/dev/tty
    local go
    go="$(tty_read "Install now? [Y/n] ")"
    if [[ "${go,,}" == n* ]]; then
        echo "Nothing was changed."
        exit 0
    fi
    echo
}

main() {
    SERVER_NAME="" ADMIN_NAME="" ADMIN_EMAIL="" ADMIN_PASSWORD_FILE="" FAX_EMAIL_DOMAIN="" UNATTENDED=0 WIZARD_PASSWORD_FILE=""
    UPDATE_TOKEN="${FAX2EMAIL_UPDATE_TOKEN:-}"
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --unattended) UNATTENDED=1; shift ;;
            --server-name) SERVER_NAME="$2"; shift 2 ;;
            --admin-name) ADMIN_NAME="$2"; shift 2 ;;
            --admin-email) ADMIN_EMAIL="$2"; shift 2 ;;
            --fax-email-domain) FAX_EMAIL_DOMAIN="${2,,}"; shift 2 ;;
            --admin-password-file) ADMIN_PASSWORD_FILE="$(readlink -f "$2")"; shift 2 ;;
            -h | --help) sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
            *) die "Unknown option: $1 (see --help)" ;;
        esac
    done

    [[ $EUID -eq 0 ]] || die "Run this with sudo."
    [[ -f "$SRC_DIR/artisan" && -d "$SRC_DIR/vendor" && -f "$SRC_DIR/public/build/manifest.json" ]] \
        || die "Run this from an unpacked release archive, not a source checkout."
    [[ "$SRC_DIR" != "$APP_DIR" ]] || die "This copy is already installed. Use: sudo fax2email-update"
    [[ -d "$APP_DIR/.git" ]] && die "$APP_DIR is a development checkout. Install on a different server."
    if [[ $UNATTENDED == 0 ]] && ! { : </dev/tty; } 2>/dev/null; then
        die "The installer asks a few questions and needs a terminal. For automated installs use --unattended (see --help)."
    fi
    if [[ $UNATTENDED == 1 && ! -f "$APP_DIR/.env" ]] && [[ -z "$ADMIN_NAME" || -z "$ADMIN_EMAIL" || -z "$ADMIN_PASSWORD_FILE" ]]; then
        die "--unattended on a new server also needs --admin-name, --admin-email and --admin-password-file."
    fi
    [[ -z "$ADMIN_PASSWORD_FILE" || -r "$ADMIN_PASSWORD_FILE" ]] || die "Cannot read $ADMIN_PASSWORD_FILE."
    cd /

    step "Checking the operating system"
    detect_os
    if [[ $UNATTENDED == 0 ]]; then
        trap '[[ -n "$WIZARD_PASSWORD_FILE" ]] && rm -f "$WIZARD_PASSWORD_FILE"' EXIT
        walk_through
    fi
    [[ -n "$SERVER_NAME" ]] || SERVER_NAME="$(hostname -I | awk '{print $1}')"
    echo "    Portal address: http://$SERVER_NAME"

    step "Installing packages (Nginx, PHP, PostgreSQL)"
    install_packages
    step "Configuring the www-data web user"
    configure_web_user
    configure_php_limits
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
    # An unattended re-run without --fax-email-domain keeps the domain set last time.
    [[ -n "$FAX_EMAIL_DOMAIN" || $UNATTENDED == 0 ]] || FAX_EMAIL_DOMAIN="$(env_value FAX_EMAIL_DOMAIN)"
    if [[ -n "$FAX_EMAIL_DOMAIN" ]]; then
        [[ "$FAX_EMAIL_DOMAIN" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$ ]] || die "Invalid --fax-email-domain: $FAX_EMAIL_DOMAIN"
        step "Setting up email-to-fax for $FAX_EMAIL_DOMAIN (Postfix and OpenDKIM)"
        configure_email_to_fax "$FAX_EMAIL_DOMAIN"
    elif [[ -n "$(env_value FAX_EMAIL_DOMAIN)" ]]; then
        # Left blank in the walk-through on a server that had it on: turn it off.
        step "Turning off email-to-fax"
        set_env FAX_EMAIL_DOMAIN ""
        systemctl disable --now fax2email-email-in.path fax2email-opendkim postfix 2>/dev/null || true
    fi
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
  1. Log in and set up the outgoing mail server under Settings > Mail server.
  - Turn on HTTPS under Settings > HTTPS once the portal's domain points at this server.${FAX_EMAIL_DOMAIN:+
  - Email-to-fax: point the MX record for $FAX_EMAIL_DOMAIN at this server (port 25 is open).}
  2. While the GitHub repository is private, add UPDATE_GITHUB_TOKEN to .env
     so 'fax2email-update' can download new releases.
EOF
}

main "$@"

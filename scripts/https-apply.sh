#!/usr/bin/env bash
#
# Apply the portal's HTTPS settings (Settings > HTTPS): get or install the
# certificate, write the Nginx site, and report the result to the portal.
#
# Runs as root from fax2email-https.service, started by a path unit when an
# admin changes a setting and by a daily timer, which also renews Let's Encrypt
# certificates that are due. Installed (root-owned) as
# /usr/local/sbin/fax2email-https-apply.
#
# The web app runs as www-data and is not trusted: its settings are checked
# against strict patterns, and Nginx is never left with a config it rejects.
#
set -Eeuo pipefail

LIB=/usr/local/lib/fax2email
[[ -f "$LIB/lib.sh" ]] || LIB="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
# shellcheck source=lib.sh
source "$LIB/lib.sh"

STATE_DIR=/etc/fax2email
TLS_DIR="$STATE_DIR/tls"
LE_NAME=fax2email
TRIGGER="$APP_DIR/storage/app/https/request"
UPLOAD_DIR="$APP_DIR/storage/app/https"
WORK="$(mktemp -d -p /run fax2email-https.XXXXXX)"
touch "$WORK/started"
trap 'rm -rf "$WORK"' EXIT

report() {
    # report <state> <message> [domain issuer expires issued]
    artisan fax2email:https status --state="$1" ${2:+--message="$2"} ${3:+--domain="$3"} ${4:+--issuer="$4"} ${5:+--expires="$5"} ${6:+--issued="$6"} >/dev/null || true
}
fail() {
    report error "$1"
    echo "Error: $1" >&2
    exit 1
}
trap 'report error "The HTTPS service failed at line $LINENO. See: journalctl -u fax2email-https"' ERR

# Optional root-only settings, e.g. a test ACME server: ACME_SERVER=https://...  ACME_NO_VERIFY=1
ACME_SERVER="" ACME_NO_VERIFY=""
if [[ -f "$STATE_DIR/https.conf" ]]; then
    ACME_SERVER="$(sed -n 's#^ACME_SERVER=\(https://[A-Za-z0-9.:/_-]*\)$#\1#p' "$STATE_DIR/https.conf" | tail -n1)"
    ACME_NO_VERIFY="$(sed -n 's/^ACME_NO_VERIFY=\(1\)$/\1/p' "$STATE_DIR/https.conf" | tail -n1)"
fi

# --- Read and validate the desired settings -----------------------------------
mode=none domain="" email="" redirect=0 auto_renew=1 renew_days=30 force_renew=0
while read -r key value; do
    case "$key" in
        mode) [[ "$value" =~ ^(none|letsencrypt|custom)$ ]] && mode="$value" ;;
        domain) [[ "$value" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$ && ${#value} -le 253 ]] && domain="$value" ;;
        email) [[ "$value" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] && email="$value" ;;
        redirect) [[ "$value" == 1 ]] && redirect=1 ;;
        auto_renew) [[ "$value" == 0 ]] && auto_renew=0 ;;
        renew_days) [[ "$value" =~ ^(30|45|60)$ ]] && renew_days="$value" ;;
        force_renew) [[ "$value" == 1 ]] && force_renew=1 ;;
    esac
done < <(artisan fax2email:https export)

[[ "$mode" == none || -n "$domain" ]] || fail "No valid domain is set."

# --- Get the certificate -----------------------------------------------------
cert="" key=""
case "$mode" in
    letsencrypt)
        [[ -n "$email" ]] || fail "No valid email address is set for Let's Encrypt."
        # Let's Encrypt checks the domain over HTTP; make sure the portal answers on port 80.
        [[ -f "$NGINX_SITE" ]] || { write_nginx_config; systemctl reload nginx; }

        # A different domain needs a fresh certificate rather than a renewal.
        if [[ -f "$STATE_DIR/https-le-domain" && "$(cat "$STATE_DIR/https-le-domain")" != "$domain" ]]; then
            certbot delete --cert-name "$LE_NAME" --non-interactive >/dev/null 2>&1 || true
        fi

        # When to renew: "Renew now", or automatically once the certificate is renew_days old.
        # With auto-renew off an existing certificate is left alone (only a missing one is fetched).
        live="/etc/letsencrypt/live/$LE_NAME/fullchain.pem"
        renewal=(--keep-until-expiring)
        if [[ -f "$live" && "$(cat "$STATE_DIR/https-le-domain" 2>/dev/null)" == "$domain" ]]; then
            issued="$(date -d "$(openssl x509 -in "$live" -noout -startdate | cut -d= -f2)" +%s)"
            age_days=$(( ($(date +%s) - issued) / 86400 ))
            if [[ "$force_renew" == 1 ]] || [[ "$auto_renew" == 1 && "$age_days" -ge "$renew_days" ]]; then
                renewal=(--force-renewal)
            elif [[ "$auto_renew" == 0 ]]; then
                renewal=()
            fi
        fi

        args=(certonly --webroot -w "$APP_DIR/public" --cert-name "$LE_NAME" -d "$domain" -m "$email"
              --agree-tos --non-interactive --no-eff-email "${renewal[@]}")
        [[ -n "$ACME_SERVER" ]] && args+=(--server "$ACME_SERVER")
        [[ -n "$ACME_NO_VERIFY" ]] && args+=(--no-verify-ssl)
        if [[ ${#renewal[@]} -eq 0 ]]; then
            : # Auto-renew is off and the certificate is current: nothing to fetch.
        elif ! certbot "${args[@]}" >"$WORK/certbot.log" 2>&1; then
            detail="$(grep -E 'Detail:|Error|error:' "$WORK/certbot.log" | tail -n 3 | tr '\n' ' ' | sed 's/  */ /g')"
            still=""
            grep -q "listen 443" "$NGINX_SITE" 2>/dev/null && still=" The current certificate is still in use."
            fail "Let's Encrypt could not issue a certificate for $domain. ${detail:-See /var/log/letsencrypt/letsencrypt.log.} Check that $domain points at this server and that port 80 is reachable from the internet.$still"
        fi
        echo "$domain" > "$STATE_DIR/https-le-domain"
        cert="/etc/letsencrypt/live/$LE_NAME/fullchain.pem"
        key="/etc/letsencrypt/live/$LE_NAME/privkey.pem"
        ;;
    custom)
        install -d -m 700 "$TLS_DIR"
        if [[ -f "$UPLOAD_DIR/custom.crt" && -f "$UPLOAD_DIR/custom.key" ]]; then
            # Same checks as the portal made, done again here because the files came from www-data.
            openssl x509 -in "$UPLOAD_DIR/custom.crt" -noout 2>/dev/null || fail "The uploaded certificate is not valid."
            [[ "$(openssl x509 -in "$UPLOAD_DIR/custom.crt" -pubkey -noout 2>/dev/null)" == "$(openssl pkey -in "$UPLOAD_DIR/custom.key" -pubout 2>/dev/null)" ]] \
                || fail "The uploaded private key does not match the certificate."
            install -m 644 "$UPLOAD_DIR/custom.crt" "$TLS_DIR/portal.crt"
            install -m 600 "$UPLOAD_DIR/custom.key" "$TLS_DIR/portal.key"
            rm -f "$UPLOAD_DIR/custom.crt" "$UPLOAD_DIR/custom.key"
        fi
        [[ -f "$TLS_DIR/portal.crt" && -f "$TLS_DIR/portal.key" ]] || fail "No certificate has been uploaded."
        command -v restorecon >/dev/null && restorecon -R "$TLS_DIR"
        cert="$TLS_DIR/portal.crt"
        key="$TLS_DIR/portal.key"
        ;;
esac

# --- Write the Nginx site, keeping the old one if Nginx rejects the new one ---
[[ -f "$NGINX_SITE" ]] && cp -p "$NGINX_SITE" "$WORK/site.previous"
if [[ "$mode" == none ]]; then
    write_nginx_config
else
    write_nginx_config "$domain" "$cert" "$key" "$redirect"
fi
if ! nginx -t >"$WORK/nginx.log" 2>&1; then
    [[ -f "$WORK/site.previous" ]] && cp -p "$WORK/site.previous" "$NGINX_SITE"
    fail "Nginx rejected the new configuration, so the previous one was kept: $(grep -m1 emerg "$WORK/nginx.log" || tail -n1 "$WORK/nginx.log")"
fi

# Reload when the site changed or the certificate was renewed (by us or by certbot's own timer).
fingerprint=""
[[ -n "$cert" ]] && fingerprint="$(openssl x509 -in "$cert" -noout -fingerprint -sha256 2>/dev/null || true)"
if ! cmp -s "$NGINX_SITE" "$WORK/site.previous" || [[ "$fingerprint" != "$(cat "$STATE_DIR/https-fingerprint" 2>/dev/null || true)" ]]; then
    systemctl reload nginx
    echo "$fingerprint" > "$STATE_DIR/https-fingerprint"
fi

# --- Tell the app its address, then report --------------------------------------
current_url="$(env_value APP_URL)"
if [[ "$mode" == none ]]; then
    wanted_url="http://${domain:-${current_url#*://}}"
    wanted_url="${wanted_url%/}"
else
    wanted_url="https://$domain"
fi
if [[ "$current_url" != "$wanted_url" ]]; then
    set_env APP_URL "$wanted_url"
    artisan optimize >/dev/null
    artisan queue:restart >/dev/null
fi

if [[ "$mode" == none ]]; then
    report off ""
else
    expires="$(date -u -d "$(openssl x509 -in "$cert" -noout -enddate | cut -d= -f2)" +%Y-%m-%dT%H:%M:%SZ)"
    issued="$(date -u -d "$(openssl x509 -in "$cert" -noout -startdate | cut -d= -f2)" +%Y-%m-%dT%H:%M:%SZ)"
    issuer="$(openssl x509 -in "$cert" -noout -issuer -nameopt multiline | sed -n 's/^ *organizationName *= *//p;s/^ *commonName *= *//p' | head -n1)"
    report active "" "$domain" "${issuer:-Unknown}" "$expires" "$issued"
fi

# A change saved while this run was busy only reaches systemd as a start request
# for the job already running, which it drops. Run again so it is not missed.
RERUNS="${FAX2EMAIL_HTTPS_RERUNS:-0}"
if [[ "$TRIGGER" -nt "$WORK/started" && "$RERUNS" -lt 3 ]]; then
    rm -rf "$WORK"
    FAX2EMAIL_HTTPS_RERUNS=$((RERUNS + 1)) exec "$0"
fi

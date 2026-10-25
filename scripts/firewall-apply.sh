#!/usr/bin/env bash
#
# Apply the firewall rules from the fax2email portal to nftables and fail2ban,
# then copy fail2ban's current bans back into the portal.
#
# Runs as root from fax2email-firewall.service, which is started by a path unit
# when an admin changes a rule and by a timer every minute. Installed (root-owned)
# to /usr/local/sbin/fax2email-firewall-apply.
#
# The web app runs as www-data and is not trusted: everything it exports is
# checked against strict patterns before it reaches nftables or fail2ban.
#
set -Eeuo pipefail

LIB=/usr/local/lib/fax2email
[[ -f "$LIB/lib.sh" ]] || LIB="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
# shellcheck source=lib.sh
source "$LIB/lib.sh"

JAILS=(f2e_sip f2e_portal f2e_ssh)
RECIDIVE=f2e_recidive
SIP_LOG=/var/log/freeswitch/freeswitch.log
WORK="$(mktemp -d -p /run fax2email-firewall.XXXXXX)"
TRIGGER="$APP_DIR/storage/app/firewall/changed"
# Marks when this run started, to notice portal changes made while it runs.
touch "$WORK/started"
FAIL2BAN_RUNNING=0

report() {
    artisan fax2email:firewall status --fail2ban="$FAIL2BAN_RUNNING" ${1:+--error="$1"} >/dev/null || true
}

trap 'rm -rf "$WORK"' EXIT
trap 'report "The firewall service failed at line $LINENO. See: journalctl -u fax2email-firewall"' ERR

IPV4='^([0-9]{1,3}\.){3}[0-9]{1,3}(/([0-9]|[12][0-9]|3[0-2]))?$'
IPV6='^[0-9a-fA-F:]*:[0-9a-fA-F:.]*(/([0-9]{1,2}|1[01][0-9]|12[0-8]))?$'
INT='^[0-9]{1,7}$'

family() { [[ "$1" == *:* ]] && echo inet6 || echo inet4; }
valid_ip() { [[ "$1" =~ $IPV4 || "$1" =~ $IPV6 ]]; }
valid_jail() { local j; for j in "${JAILS[@]}" "$RECIDIVE"; do [[ "$1" == "$j" ]] && return 0; done; return 1; }

# --- Read and validate the desired state ------------------------------------
declare -A ALLOW=([inet4]="" [inet6]="") BLOCK=([inet4]="" [inet6]="") JAIL_ON=() SETTING=()
UNBANS=()

while read -r kind a b _; do
    case "$kind" in
        allow) valid_ip "$a" && ALLOW[$(family "$a")]+="$a, " ;;
        block)
            valid_ip "$a" && [[ "$b" =~ $INT ]] || continue
            if [[ "$b" == 0 ]]; then BLOCK[$(family "$a")]+="$a, "; else BLOCK[$(family "$a")]+="$a timeout ${b}s, "; fi ;;
        unban) valid_jail "$a" && valid_ip "$b" && UNBANS+=("$a $b") ;;
        jail) valid_jail "$a" && [[ "$b" == 0 || "$b" == 1 ]] && JAIL_ON[$a]="$b" ;;
        setting) [[ "$a" =~ ^[a-z_]{1,40}$ && "$b" =~ $INT ]] && SETTING[$a]="$b" ;;
    esac
done < <(artisan fax2email:firewall export)

setting() { echo "${SETTING[$1]:?missing firewall setting $1}"; }

# --- nftables ------------------------------------------------------------------
nft -f "$LIB/fax2email.nft"
{
    for fam in inet4 inet6; do
        echo "flush set inet fax2email allow_$fam"
        echo "flush set inet fax2email block_$fam"
        [[ -n "${ALLOW[$fam]}" ]] && echo "add element inet fax2email allow_$fam { ${ALLOW[$fam]%, } }"
        [[ -n "${BLOCK[$fam]}" ]] && echo "add element inet fax2email block_$fam { ${BLOCK[$fam]%, } }"
    done
} > "$WORK/rules.nft"
nft -f "$WORK/rules.nft"

# --- fail2ban --------------------------------------------------------------------
if command -v fail2ban-client >/dev/null; then
    ignoreip="127.0.0.1/8 ::1 $(echo "${ALLOW[inet4]}${ALLOW[inet6]}" | tr -d ',')"
    bool() { [[ "${JAIL_ON[$1]:-0}" == 1 ]] && echo true || echo false; }
    sip_enabled="$(bool f2e_sip)"
    # fail2ban refuses to start a jail whose log file is missing; FreeSWITCH creates it.
    [[ -f "$SIP_LOG" ]] || sip_enabled=false

    limits="action = fax2email
maxretry = $(setting max_retry)
findtime = $(( $(setting find_time_minutes) * 60 ))
bantime = $(( $(setting ban_time_hours) * 3600 ))"

    # Only ignoreip is global, so other jails an admin adds keep their own settings.
    cat > "$WORK/jail.local" <<EOF
# Written by fax2email-firewall-apply from the portal's Firewall settings. Do not edit.

[DEFAULT]
ignoreip = $ignoreip

# Debian enables its own SSH jail; f2e_ssh replaces it.
[sshd]
enabled = false

[f2e_ssh]
enabled = $(bool f2e_ssh)
filter = sshd
backend = systemd
$limits

[f2e_portal]
enabled = $(bool f2e_portal)
filter = fax2email-portal
backend = systemd
$limits

[f2e_sip]
enabled = $sip_enabled
filter = fax2email-sip
logpath = $SIP_LOG
backend = auto
$limits

[$RECIDIVE]
enabled = true
filter = recidive[_jailname=$RECIDIVE]
logpath = /var/log/fail2ban.log
backend = auto
action = fax2email
maxretry = $(setting recidive_max_retry)
findtime = $(( $(setting recidive_find_time_days) * 86400 ))
bantime = $(( $(setting recidive_ban_time_days) * 86400 ))
EOF

    cat > "$WORK/fail2ban.local" <<EOF
# Written by fax2email-firewall-apply. Do not edit.
[Definition]
logtarget = /var/log/fail2ban.log
# Keep ban history long enough to restore repeat-offender bans after a restart.
dbpurgeage = $(( ($(setting recidive_ban_time_days) + 1) * 86400 ))
EOF

    mkdir -p /etc/fail2ban/jail.d /etc/fail2ban/fail2ban.d
    # The repeat-offender jail reads fail2ban's own log, which Rocky does not create
    # until fail2ban is told to log there (by fail2ban.local below).
    if [[ ! -f /var/log/fail2ban.log ]]; then
        install -m 600 /dev/null /var/log/fail2ban.log
        command -v restorecon >/dev/null && restorecon /var/log/fail2ban.log
    fi
    changed=0
    for pair in "jail.local:/etc/fail2ban/jail.d/fax2email.local" "fail2ban.local:/etc/fail2ban/fail2ban.d/fax2email.local"; do
        src="$WORK/${pair%%:*}" dest="${pair#*:}"
        if ! cmp -s "$src" "$dest"; then
            [[ -f "$dest" ]] && cp -p "$dest" "$WORK/$(basename "$dest").previous"
            install -m 644 "$src" "$dest"
            changed=1
        fi
    done

    if [[ "$changed" == 1 ]] && ! fail2ban-client -t >"$WORK/test.log" 2>&1; then
        # Never leave fail2ban with a configuration it cannot load.
        for dest in /etc/fail2ban/jail.d/fax2email.local /etc/fail2ban/fail2ban.d/fax2email.local; do
            if [[ -f "$WORK/$(basename "$dest").previous" ]]; then cp -p "$WORK/$(basename "$dest").previous" "$dest"; else rm -f "$dest"; fi
        done
        report "fail2ban rejected the new settings: $(tail -n 3 "$WORK/test.log" | tr '\n' ' ')"
        exit 1
    fi

    if ! systemctl is-active --quiet fail2ban; then
        systemctl enable --now fail2ban
    elif [[ "$changed" == 1 ]]; then
        fail2ban-client reload >/dev/null
    fi

    if fail2ban-client ping >/dev/null 2>&1; then
        FAIL2BAN_RUNNING=1

        for unban in "${UNBANS[@]}"; do
            fail2ban-client set ${unban% *} unbanip "${unban#* }" >/dev/null 2>&1 || true
        done

        # Lines look like "203.0.113.7 	2026-10-25 20:00:00 + 86400 = 2026-10-26 20:00:00".
        : > "$WORK/bans"
        for jail in "${JAILS[@]}" "$RECIDIVE"; do
            fail2ban-client status "$jail" >/dev/null 2>&1 || continue
            while IFS= read -r line; do
                ip="${line%%[[:space:]]*}"
                ends="${line##*= }"
                valid_ip "$ip" || continue
                epoch="$(date -d "$ends" +%s 2>/dev/null)" || continue
                echo "$jail $ip $epoch" >> "$WORK/bans"
            done < <(fail2ban-client get "$jail" banip --with-time 2>/dev/null)
        done
        artisan fax2email:firewall import-bans < "$WORK/bans" >/dev/null
    fi
fi

report ""

# A change saved while this run was busy only reaches systemd as a start request
# for the job already running, which it drops. Run again so it is not missed.
RERUNS="${FAX2EMAIL_FIREWALL_RERUNS:-0}"
if [[ "$TRIGGER" -nt "$WORK/started" && "$RERUNS" -lt 3 ]]; then
    rm -rf "$WORK"
    FAX2EMAIL_FIREWALL_RERUNS=$((RERUNS + 1)) exec "$0"
fi

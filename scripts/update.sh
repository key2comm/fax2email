#!/usr/bin/env bash
#
# Update an installed server to the latest GitHub release (or a given version).
#
#   sudo fax2email-update            # update to the latest release
#   sudo fax2email-update 0.3.0      # update (or roll back) to a specific release
#   sudo fax2email-update --check    # only report whether an update is available
#
# Each update backs up the code and database to /var/www/html/fax2email-backups
# first, and restores them automatically if any step fails.
#
# While the GitHub repository is private, set UPDATE_GITHUB_TOKEN in .env to a
# fine-grained token with read access to the repository's contents.
#
set -Eeuo pipefail

# Installed (root-owned) as /usr/local/sbin/fax2email-update; its helpers live in
# /usr/local/lib/fax2email, outside the www-data-writable app directory.
LIB=/usr/local/lib/fax2email
[[ -f "$LIB/lib.sh" ]] || LIB="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
# shellcheck source=lib.sh
source "$LIB/lib.sh"

# Root-owned files outside the app directory that an update replaces.
SYSTEM_PATHS=(usr/local/lib/fax2email usr/local/sbin/fax2email-update usr/local/sbin/fax2email-firewall-apply
    usr/local/sbin/fax2email-https-apply etc/nginx/conf.d/fax2email.conf
    etc/fail2ban/action.d/fax2email.conf etc/fail2ban/filter.d/fax2email-portal.conf
    etc/fail2ban/filter.d/fax2email-sip.conf)

KEEP_BACKUPS=5
DEFAULT_REPOSITORY=key2comm/fax2email

github() {
    local accept="$1"; shift
    curl -fsSL -H "Accept: $accept" -H "X-GitHub-Api-Version: 2022-11-28" \
        ${TOKEN:+-H "Authorization: Bearer $TOKEN"} "$@"
}

# Print "<tag> <archive asset id> <checksum asset id>" for a release JSON document on stdin.
parse_release() {
    php -r '
        $r = json_decode(stream_get_contents(STDIN), true);
        $ids = [];
        foreach ($r["assets"] ?? [] as $a) { $ids[$a["name"]] = $a["id"]; }
        $base = "fax2email-".$r["tag_name"].".tar.gz";
        echo $r["tag_name"], " ", $ids[$base] ?? "", " ", $ids["$base.sha256"] ?? "", "\n";
    '
}

db_env() {
    export PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE
    PGHOST="$(env_value DB_HOST)"; PGPORT="$(env_value DB_PORT)"
    PGUSER="$(env_value DB_USERNAME)"; PGPASSWORD="$(env_value DB_PASSWORD)"
    PGDATABASE="$(env_value DB_DATABASE)"
}

finish_deploy() {
    artisan storage:link >/dev/null 2>&1 || true
    artisan optimize
    local fpm; fpm="$(php_fpm_service)"
    [[ -n "$fpm" ]] && systemctl reload "$fpm"
    artisan queue:restart
    systemctl restart fax2email-queue.service fax2email-fax-events.service || true
    systemctl start fax2email-firewall.service || true
    systemctl start fax2email-https.service || true
    # Restarting FreeSWITCH drops calls in progress, so only do it when it changed.
    if [[ "$FREESWITCH_CONFIG_CHANGED" == 1 || "${FREESWITCH_REBUILT:-0}" == 1 ]]; then
        systemctl restart fax2email-freeswitch.service || true
    fi
    artisan up
}

rollback() {
    trap - ERR
    echo
    echo "!!! Update failed. Restoring v$CURRENT from $BACKUP_PATH ..." >&2
    local restore="$STAGING/restore"
    mkdir -p "$restore"
    tar -xzf "$BACKUP_PATH/files.tar.gz" -C "$restore"
    sync_into_app "$restore/fax2email"
    tar -xzf "$BACKUP_PATH/system.tar.gz" -C /
    systemctl daemon-reload
    if [[ "$MIGRATING" == 1 ]]; then
        db_env
        pg_restore --clean --if-exists --no-owner -d "$PGDATABASE" "$BACKUP_PATH/database.dump"
    fi
    fix_permissions
    finish_deploy
    echo "!!! Restored v$CURRENT. The site is back up. Nothing was changed." >&2
    exit 1
}

main() {
    [[ $EUID -eq 0 ]] || die "Run this with sudo."
    [[ -f "$APP_DIR/.env" ]] || die "$APP_DIR is not installed."
    [[ -d "$APP_DIR/.git" ]] && die "$APP_DIR is a development checkout. Use git pull instead."
    for cmd in curl php rsync tar pg_dump pg_restore runuser; do
        command -v "$cmd" >/dev/null || die "Missing required command: $cmd"
    done

    REPOSITORY="$(env_value UPDATE_REPOSITORY)"; REPOSITORY="${REPOSITORY:-$DEFAULT_REPOSITORY}"
    TOKEN="$(env_value UPDATE_GITHUB_TOKEN)"
    CURRENT="$(tr -d '[:space:]' < "$APP_DIR/VERSION")"

    local check_only=0 requested=""
    case "${1:-}" in
        --check) check_only=1 ;;
        "") ;;
        *) requested="${1#v}" ;;
    esac

    local endpoint="releases/latest"
    [[ -n "$requested" ]] && endpoint="releases/tags/v$requested"

    step "Checking $REPOSITORY for ${requested:+v$requested}${requested:-the latest release}"
    local release tag archive_id checksum_id
    release="$(github application/vnd.github+json "https://api.github.com/repos/$REPOSITORY/$endpoint")" \
        || die "Could not reach GitHub. If the repository is private, set UPDATE_GITHUB_TOKEN in $APP_DIR/.env."
    read -r tag archive_id checksum_id <<<"$(parse_release <<<"$release")"
    [[ -n "$tag" ]] || die "GitHub returned no release."
    local target="${tag#v}"

    echo "    Installed: v$CURRENT"
    echo "    Available: v$target"

    if [[ "$target" == "$CURRENT" ]]; then
        echo "Already up to date."
        exit 0
    fi
    if [[ -z "$requested" && "$(printf '%s\n%s\n' "$CURRENT" "$target" | sort -V | tail -n1)" == "$CURRENT" ]]; then
        echo "Installed version is newer than the latest release. Nothing to do."
        exit 0
    fi
    [[ "$check_only" == 1 ]] && exit 0
    [[ -n "$archive_id" && -n "$checksum_id" ]] || die "Release $tag has no fax2email archive attached."

    mkdir -p "$BACKUP_DIR"
    chmod 750 "$BACKUP_DIR"
    STAGING="$(mktemp -d -p "$BACKUP_DIR" .staging.XXXXXX)"
    trap 'rm -rf "$STAGING"' EXIT

    step "Downloading $tag"
    local archive="fax2email-$tag.tar.gz"
    github application/octet-stream -o "$STAGING/$archive" \
        "https://api.github.com/repos/$REPOSITORY/releases/assets/$archive_id"
    github application/octet-stream -o "$STAGING/$archive.sha256" \
        "https://api.github.com/repos/$REPOSITORY/releases/assets/$checksum_id"
    (cd "$STAGING" && sha256sum --quiet -c "$archive.sha256") || die "Checksum mismatch. The download is corrupt."
    tar -xzf "$STAGING/$archive" -C "$STAGING"
    local release_dir="$STAGING/fax2email-$tag"
    [[ -f "$release_dir/artisan" ]] || die "Archive does not contain the application."

    step "Backing up v$CURRENT"
    BACKUP_PATH="$BACKUP_DIR/$(date +%Y%m%d-%H%M%S)-v$CURRENT"
    mkdir -p "$BACKUP_PATH"
    MIGRATING=0
    trap 'trap - ERR; artisan up; rm -rf "$BACKUP_PATH"; die "Backup failed. Nothing was changed."' ERR
    artisan down --retry=60
    tar -C "$(dirname "$APP_DIR")" -czf "$BACKUP_PATH/files.tar.gz" \
        --exclude=fax2email/storage "$(basename "$APP_DIR")"
    local existing=() path
    for path in "${SYSTEM_PATHS[@]}" $(cd / && compgen -G 'etc/systemd/system/fax2email-*'); do
        [[ -e "/$path" ]] && existing+=("$path")
    done
    tar -C / -czf "$BACKUP_PATH/system.tar.gz" "${existing[@]}"
    db_env
    pg_dump -Fc -f "$BACKUP_PATH/database.dump"
    trap rollback ERR

    step "Installing v$target"
    sync_into_app "$release_dir"
    install_system_files "$release_dir"
    if getent group freeswitch >/dev/null; then
        local before; before="$(/usr/local/bin/freeswitch -version 2>/dev/null || true)"
        "$release_dir/scripts/build-freeswitch.sh"
        [[ "$(/usr/local/bin/freeswitch -version 2>/dev/null)" != "$before" ]] && FREESWITCH_REBUILT=1
    fi
    fix_permissions
    # Older lib.sh copies (before v0.1.7) don't have this yet.
    declare -F fix_nginx_dirs >/dev/null && fix_nginx_dirs

    step "Running database migrations"
    MIGRATING=1
    artisan migrate --force

    step "Restarting services"
    finish_deploy
    trap - ERR

    step "Removing old backups (keeping $KEEP_BACKUPS)"
    find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '*-v*' -printf '%T@ %p\n' \
        | sort -rn | tail -n +$((KEEP_BACKUPS + 1)) | cut -d' ' -f2- | xargs -r rm -rf

    echo "Updated v$CURRENT -> v$target. Backup saved in $BACKUP_PATH"
}

# Everything runs inside main(), which bash reads in full before starting, so
# replacing this file during the update cannot corrupt the running script.
main "$@"
exit

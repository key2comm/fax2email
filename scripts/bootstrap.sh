#!/usr/bin/env bash
#
# Download the latest fax2email release and start the installer.
#
# While the repository is private, pass a GitHub token with read access to it:
#
#   curl -fsSL -H "Authorization: Bearer <token>" -H "Accept: application/vnd.github.raw" \
#     https://api.github.com/repos/key2comm/fax2email/contents/scripts/bootstrap.sh \
#     | sudo FAX2EMAIL_TOKEN=<token> bash
#
# Once the repository is public:
#
#   curl -fsSL https://raw.githubusercontent.com/key2comm/fax2email/main/scripts/bootstrap.sh | sudo bash
#
# Optional: FAX2EMAIL_VERSION=0.2.0 installs that release instead of the latest.
# The token is saved in the app's .env so `sudo fax2email-update` can fetch updates.
#
set -Eeuo pipefail

REPOSITORY="${FAX2EMAIL_REPOSITORY:-key2comm/fax2email}"
TOKEN="${FAX2EMAIL_TOKEN:-}"
VERSION="${FAX2EMAIL_VERSION:-}"
WORK=/var/tmp/fax2email-bootstrap

die() { echo "Error: $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run this with sudo."
for cmd in curl tar sha256sum; do
    command -v "$cmd" >/dev/null || die "Missing $cmd. Install it first (apt install $cmd / dnf install $cmd)."
done

github() {
    curl -fsSL -H "Accept: $1" -H "X-GitHub-Api-Version: 2022-11-28" ${TOKEN:+-H "Authorization: Bearer $TOKEN"} "${@:2}"
}

endpoint="releases/latest"
[[ -n "$VERSION" ]] && endpoint="releases/tags/v${VERSION#v}"

echo "==> Finding the ${VERSION:+v${VERSION#v}}${VERSION:-latest} release of $REPOSITORY"
release="$(github application/vnd.github+json "https://api.github.com/repos/$REPOSITORY/$endpoint" | tr -d '\n')" \
    || die "Could not read the release from GitHub. While the repository is private, set FAX2EMAIL_TOKEN."

# Pull the tag and the archive/checksum asset ids out of the JSON without needing jq or PHP.
tag="$(grep -o '"tag_name": *"[^"]*"' <<<"$release" | head -n1 | sed 's/.*"\([^"]*\)"$/\1/')"
[[ -n "$tag" ]] || die "GitHub returned no release."
asset_id() {
    grep -o "\"url\": *\"[^\"]*/releases/assets/[0-9]*\"[^}]*\"name\": *\"$1\"" <<<"$release" | head -n1 | grep -o 'assets/[0-9]*' | cut -d/ -f2
}
archive="fax2email-$tag.tar.gz"
archive_id="$(asset_id "$archive")"
checksum_id="$(asset_id "$archive.sha256")"
[[ -n "$archive_id" && -n "$checksum_id" ]] || die "Release $tag has no $archive attached."

rm -rf "$WORK"
mkdir -p "$WORK"
cd "$WORK"
echo "==> Downloading $archive"
github application/octet-stream -o "$archive" "https://api.github.com/repos/$REPOSITORY/releases/assets/$archive_id"
github application/octet-stream -o "$archive.sha256" "https://api.github.com/repos/$REPOSITORY/releases/assets/$checksum_id"
sha256sum --quiet -c "$archive.sha256" || die "Checksum mismatch. The download is corrupt."
tar -xzf "$archive"

echo "==> Starting the installer"
# The installer reads its questions from the terminal, so piping this script into bash is fine.
FAX2EMAIL_UPDATE_TOKEN="$TOKEN" exec "./fax2email-$tag/scripts/install.sh" "$@"

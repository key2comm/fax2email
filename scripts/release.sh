#!/usr/bin/env bash
#
# Cut a new release: bump VERSION, commit, tag, and push. GitHub Actions then
# builds the release archive that servers download with `fax2email-update`.
#
# Usage: scripts/release.sh 0.2.0
#
set -euo pipefail

cd "$(dirname "$0")/.."

version="${1:-}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Usage: $0 <major.minor.patch>   (current: $(cat VERSION))" >&2
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo "Commit or stash your changes before releasing." >&2
    exit 1
fi

if git rev-parse "v$version" >/dev/null 2>&1; then
    echo "Tag v$version already exists." >&2
    exit 1
fi

echo "$version" > VERSION
git add VERSION
git commit -m "Release v$version"
git tag -a "v$version" -m "v$version"
git push origin HEAD "v$version"

echo "Pushed v$version. Watch the build with: gh run watch"

#!/usr/bin/env bash
#
# Build a release archive: dist/fax2email-v<VERSION>.tar.gz plus a .sha256.
# The archive contains everything a server needs to run, including vendor/
# and the compiled frontend, so servers never need Composer or Node.
#
# Used by the GitHub release workflow, and locally to test the installer:
#   scripts/build-release.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."

version="$(tr -d '[:space:]' < VERSION)"
name="fax2email-v$version"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Build from a clean copy of the committed files, never the working tree,
# so local .env files, logs and uncommitted changes cannot leak into a release.
git archive --format=tar HEAD | tar -x -C "$work"

(
    cd "$work"
    composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress
    npm ci --no-audit --no-fund
    npm run build
    rm -rf node_modules tests .github
)

mkdir -p dist
rm -rf "dist/$name" "dist/$name.tar.gz" "dist/$name.tar.gz.sha256"
mv "$work" "dist/$name"
trap - EXIT
tar -C dist -czf "dist/$name.tar.gz" "$name"
rm -rf "dist/$name"
(cd dist && sha256sum "$name.tar.gz" > "$name.tar.gz.sha256")

echo "Built dist/$name.tar.gz"

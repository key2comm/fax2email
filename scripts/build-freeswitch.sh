#!/usr/bin/env bash
#
# Build and install FreeSWITCH from source, with only the modules fax2email needs.
# Used by install.sh; safe to re-run (skips the build if this version is installed).
#
#   sudo scripts/build-freeswitch.sh
#
# Installs to /usr/local (binaries and modules), /etc/freeswitch (config),
# /var/log/freeswitch and /var/lib/freeswitch. Builds in /var/tmp and removes
# the build tree afterwards. Source archives are checked against pinned SHA-256
# sums, so a tampered download is never built.
#
set -Eeuo pipefail

FREESWITCH_VERSION=1.11.3
FREESWITCH_SHA256=6ab49ae26f45e35d3b88ae12b5d915a10aeabafcc0fd24a427c3bab4012c9e55
SOFIA_VERSION=1.13.18
SOFIA_SHA256=d2ad4e64753a7c9843b766b8de8081d9c1d7acfaeb53c12b3aed7fdb9235766c
SPANDSP_VERSION=3.1.1
SPANDSP_SHA256=71b3f1492cd5ca3dacdaa7eb6b54f5d14e0e2267ae0a1df09a87ca93cfeb1401

BUILD_DIR=/var/tmp/fax2email-freeswitch-build
JOBS="$(nproc)"

die() { echo "Error: $*" >&2; exit 1; }
step() { echo "==> $*"; }

[[ $EUID -eq 0 ]] || die "Run this with sudo."

if [[ -x /usr/local/bin/freeswitch ]] && /usr/local/bin/freeswitch -version 2>/dev/null | grep -q "$FREESWITCH_VERSION"; then
    echo "FreeSWITCH $FREESWITCH_VERSION is already installed."
    exit 0
fi

# shellcheck source=/dev/null
. /etc/os-release

step "Installing build dependencies"
if [[ "$ID" == debian ]]; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y -q build-essential autoconf automake libtool libtool-bin pkg-config \
        libssl-dev libcurl4-openssl-dev libpcre2-dev libspeex-dev libspeexdsp-dev libsqlite3-dev libedit-dev \
        libtiff-dev libjpeg-dev uuid-dev zlib1g-dev libsndfile1-dev
else
    # Several -devel packages live in the CodeReady Builder (crb) repository.
    dnf install -y -q 'dnf-command(config-manager)'
    dnf config-manager --set-enabled crb
    dnf install -y -q gcc gcc-c++ make autoconf automake libtool pkgconf-pkg-config which \
        openssl-devel libcurl-devel pcre2-devel speex-devel speexdsp-devel sqlite-devel libedit-devel \
        libtiff-devel libjpeg-turbo-devel libuuid-devel zlib-devel libsndfile-devel
fi

mkdir -p "$BUILD_DIR"
cd "$BUILD_DIR"
trap 'cd /; rm -rf "$BUILD_DIR"' EXIT

fetch() {
    local repo="$1" tag="$2" sum="$3" file="$4"
    curl -fsSL -o "$file" "https://github.com/$repo/archive/refs/tags/$tag.tar.gz"
    echo "$sum  $file" | sha256sum --quiet -c - || die "Checksum mismatch for $file. Refusing to build it."
    tar -xzf "$file"
}

export PKG_CONFIG_PATH=/usr/local/lib/pkgconfig:/usr/local/lib64/pkgconfig
# Rocky does not search /usr/local/lib for shared libraries by default.
echo /usr/local/lib > /etc/ld.so.conf.d/fax2email-freeswitch.conf

step "Building sofia-sip $SOFIA_VERSION (SIP stack)"
fetch freeswitch/sofia-sip "v$SOFIA_VERSION" "$SOFIA_SHA256" sofia-sip.tar.gz
(cd "sofia-sip-$SOFIA_VERSION" && ./bootstrap.sh >/dev/null && ./configure -q --prefix=/usr/local && make -s -j"$JOBS" && make -s install)
ldconfig

step "Building spandsp $SPANDSP_VERSION (fax and T.38)"
fetch freeswitch/spandsp "v$SPANDSP_VERSION" "$SPANDSP_SHA256" spandsp.tar.gz
(cd "spandsp-$SPANDSP_VERSION" && ./bootstrap.sh >/dev/null && ./configure -q --prefix=/usr/local && make -s -j"$JOBS" && make -s install)
ldconfig

step "Building FreeSWITCH $FREESWITCH_VERSION (this takes a while)"
fetch signalwire/freeswitch "v$FREESWITCH_VERSION" "$FREESWITCH_SHA256" freeswitch.tar.gz
cd "freeswitch-$FREESWITCH_VERSION"
# The release archive lacks the git-generated version file that bootstrap expects.
[[ -f .version ]] || echo "$FREESWITCH_VERSION" > .version
cat > modules.conf <<'EOF'
applications/mod_commands
applications/mod_db
applications/mod_dptools
applications/mod_expr
applications/mod_hash
applications/mod_spandsp
dialplans/mod_dialplan_xml
endpoints/mod_loopback
endpoints/mod_sofia
event_handlers/mod_event_socket
formats/mod_native_file
formats/mod_sndfile
formats/mod_tone_stream
loggers/mod_console
loggers/mod_logfile
xml_int/mod_xml_curl
EOF
./bootstrap.sh -j >/dev/null
./configure -q --prefix=/usr/local --sysconfdir=/etc --localstatedir=/var \
    --disable-libvpx --disable-libyuv --without-erlang --without-python3
make -s -j"$JOBS"
make -s install
ldconfig

/usr/local/bin/freeswitch -version

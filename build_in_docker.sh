#!/bin/bash
# shellcheck disable=SC1091
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
[ -f "${SCRIPT_DIR}/versions.env" ] && source "${SCRIPT_DIR}/versions.env"
[ -z "$COMPOSE_VERSION" ] && COMPOSE_VERSION=5.5.0
[ -z "$PKG_VERSION" ] && PKG_VERSION="$(date +%Y.%m.%d)"
[ -z "$PKG_BUILD" ] && PKG_BUILD="$(date +%H%M)"
mkdir -p "$PWD/archive/.build-cache"
docker run --rm --tmpfs /tmp \
    -v "$PWD/archive:/mnt/output:rw" \
    -v "$PWD/archive/.build-cache:/mnt/cache:rw" \
    -e TZ="America/New_York" \
    -e COMPOSE_VERSION="$COMPOSE_VERSION" \
    -e PKG_VERSION="$PKG_VERSION" \
    -e PKG_BUILD="$PKG_BUILD" \
    -e OUTPUT_FOLDER="/mnt/output" \
    -e DOWNLOAD_CACHE_DIR="/mnt/cache" \
    -v "$PWD/source:/mnt/source:ro" \
    vbatts/slackware:latest \
    /mnt/source/pkg_build.sh "$UI_VERSION_LETTER"

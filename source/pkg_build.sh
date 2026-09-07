#!/bin/bash
[ -z "$OUTPUT_FOLDER" ] && echo "Output Folder not set" && exit 1
[ -z "$COMPOSE_VERSION" ] && echo "Compose Version not set" && exit 2
[ -z "$RESVG_VERSION" ]  && echo "RESVG Version not set"   && exit 2
[ -z "$PKG_VERSION" ] && echo "Package Version not set" && exit 5
[ -z "$PKG_BUILD" ] && PKG_BUILD=$(date +%H%M)
tmpdir=/tmp/tmp.$((RANDOM * 19318203981230 + 40))
version=$PKG_VERSION
build=$PKG_BUILD

shopt -s extglob
set -euo pipefail
set +x

LOG_FILE=/tmp/build.log
: > "$LOG_FILE"

# Discover and validate CA bundle for wget in container.
# Prefer explicit CA_CERT, then common system locations.
get_ca_cert_path() {
  if [[ -n "${CA_CERT:-}" && -f "$CA_CERT" ]]; then
    echo "$CA_CERT"
    return 0
  fi

  for candidate in "/etc/ssl/certs/ca-certificates.crt" "/etc/pki/tls/certs/ca-bundle.crt" "/etc/ssl/cert.pem"; do
    if [[ -f "$candidate" ]]; then
      echo "$candidate"
      return 0
    fi
  done

  echo ""  # no cert found
  return 1
}

CA_CERT=$(get_ca_cert_path || true)
if [[ -z "$CA_CERT" ]]; then
  echo "WARNING: No CA_CERT available; wget will fall back to --no-check-certificate (insecure)." | tee -a "$LOG_FILE"
  CA_CERT=""
fi

run_quiet() {
  # run the command, stream stdout/stderr to terminal and log file
  "$@" 2>&1 | tee -a "$LOG_FILE"
}

# Note: run_quiet uses tee so log is live and stays in /tmp/build.log.

download_file_quiet() {
  local url="$1"
  local output_path="$2"
  local label="$3"

  # Keep network transfer output out of console; preserve details in build log on failure.
  # shellcheck disable=SC2046
  if ! wget $(wget_args) -q -O "$output_path" "$url" >>"$LOG_FILE" 2>&1; then
    echo "Download failed for ${label}: ${url}" | tee -a "$LOG_FILE"
    exit 9
  fi
}

DOWNLOAD_CACHE_DIR="${DOWNLOAD_CACHE_DIR:-}"
if [[ -n "$DOWNLOAD_CACHE_DIR" ]]; then
  mkdir -p "$DOWNLOAD_CACHE_DIR"
fi

sha256_file() {
  sha256sum "$1" | awk '{print $1}'
}

download_with_sha_cache() {
  local artifact_url="$1"
  local checksum_url="$2"
  local artifact_name="$3"
  local expected_sha="${4:-}"
  local checksum_name="${artifact_name}.sha256"
  local cache_file=""

  if [[ -z "$expected_sha" ]]; then
    # Always refresh checksum so cache validation tracks upstream updates.
    echo "Fetching checksum for $artifact_name..." | tee -a "$LOG_FILE"
    download_file_quiet "$checksum_url" "$checksum_name" "$artifact_name checksum"
    expected_sha="$(awk 'NF {print $1; exit}' "$checksum_name")"
    if [[ -z "$expected_sha" ]]; then
      echo "Failed to parse SHA256 from $checksum_name" | tee -a "$LOG_FILE"
      exit 7
    fi
  else
    echo "Using pinned checksum for $artifact_name..." | tee -a "$LOG_FILE"
  fi

  cache_file="${DOWNLOAD_CACHE_DIR%/}/${artifact_name}"
  if [[ -n "$DOWNLOAD_CACHE_DIR" && -f "$cache_file" ]]; then
    local cached_sha
    cached_sha="$(sha256_file "$cache_file")"
    if [[ "$cached_sha" == "$expected_sha" ]]; then
      echo "Reusing cached $artifact_name (SHA256 match: $cached_sha) from $cache_file" | tee -a "$LOG_FILE"
      run_quiet cp "$cache_file" "$artifact_name"
    else
      echo "Cached $artifact_name SHA mismatch (cached=$cached_sha expected=$expected_sha); re-downloading." | tee -a "$LOG_FILE"
      run_quiet rm -f "$cache_file"
    fi
  fi

  if [[ ! -f "$artifact_name" ]]; then
    echo "Downloading $artifact_name..." | tee -a "$LOG_FILE"
    download_file_quiet "$artifact_url" "$artifact_name" "$artifact_name"
  fi

  local artifact_sha
  artifact_sha="$(sha256_file "$artifact_name")"
  if [[ "$artifact_sha" != "$expected_sha" ]]; then
    echo "Downloaded $artifact_name SHA mismatch; expected $expected_sha got $artifact_sha" | tee -a "$LOG_FILE"
    exit 8
  fi

  if [[ -n "$DOWNLOAD_CACHE_DIR" ]]; then
    run_quiet cp "$artifact_name" "$cache_file"
  fi
}

wget_args() {
  local args=("--https-only" "--secure-protocol=TLSv1_2")
  if [[ -n "$CA_CERT" && -f "$CA_CERT" ]]; then
    args+=("--ca-certificate=$CA_CERT")
  else
    args+=("--no-check-certificate")
  fi
  echo "${args[@]}"
}

resolve_latest_slackware_package() {
  local package_prefix="$1"
  local package_index
  local package_name=""

  package_index="$(mktemp)"
  if ! wget $(wget_args) -q -O "$package_index" "https://slackware.osuosl.org/slackware64-current/slackware64/a/"; then
    echo "Failed to fetch Slackware package index while resolving ${package_prefix}." | tee -a "$LOG_FILE"
    rm -f "$package_index"
    exit 9
  fi

  package_name="$(grep -Eo "${package_prefix}[^\"'[:space:]]+\\.txz" "$package_index" | sort -V | tail -n 1 || true)"
  rm -f "$package_index"

  if [[ -z "$package_name" ]]; then
    echo "No ${package_prefix} package found in the Slackware current package index." | tee -a "$LOG_FILE"
    exit 10
  fi

  echo "$package_name"
}

echo "Installing unzip dependency..."
INFOZIP_PKG="$(resolve_latest_slackware_package "infozip")"
INFOZIP_URL="https://slackware.osuosl.org/slackware64-current/slackware64/a/${INFOZIP_PKG}"

echo "Resolved latest infozip package: ${INFOZIP_PKG}" | tee -a "$LOG_FILE"
download_file_quiet "$INFOZIP_URL" "$INFOZIP_PKG" "infozip package"
run_quiet upgradepkg --install-new "${INFOZIP_PKG}"

echo "Creating temporary package structure at $tmpdir..."
run_quiet mkdir -p "$tmpdir"

echo "Copying source plugin files into temp structure..."
mkdir -p $tmpdir/usr/local/emhttp/plugins/compose.manager
run_quiet cp -RT /mnt/source/compose.manager/ $tmpdir/usr/local/emhttp/plugins/compose.manager/

echo "Entering temp directory and setting file permissions..."
cd $tmpdir || exit 1

echo "Marking plugin scripts and PHP executable..."
run_quiet chmod -R +x "$tmpdir/usr/local/emhttp/plugins/compose.manager/event/"
run_quiet chmod -R +x "$tmpdir/usr/local/emhttp/plugins/compose.manager/scripts/"
run_quiet chmod -R +x "$tmpdir/usr/local/emhttp/plugins/compose.manager/include/"
run_quiet chmod -R +x "$tmpdir/usr/local/emhttp/plugins/compose.manager/nchan/"

echo "Downloading Docker Compose CLI plugin v${COMPOSE_VERSION}..."
download_with_sha_cache \
  "https://github.com/docker/compose/releases/download/v${COMPOSE_VERSION}/docker-compose-linux-x86_64" \
  "https://github.com/docker/compose/releases/download/v${COMPOSE_VERSION}/docker-compose-linux-x86_64.sha256" \
  "docker-compose-linux-x86_64"
run_quiet rm docker-compose-linux-x86_64.sha256

echo "Installing Docker Compose CLI plugin v${COMPOSE_VERSION}..."
run_quiet mkdir -p "$tmpdir/usr/lib/docker/cli-plugins/"
run_quiet cp docker-compose-linux-x86_64 "$tmpdir/usr/lib/docker/cli-plugins/docker-compose"
run_quiet chmod -R +x "$tmpdir/usr/lib/docker/cli-plugins/"
run_quiet rm docker-compose-linux-x86_64

echo "Downloading resvg v${RESVG_VERSION}..."
download_with_sha_cache \
  "https://github.com/linebender/resvg/releases/download/v${RESVG_VERSION}/resvg-linux-x86_64.tar.gz" \
  "" \
  "resvg-linux-x86_64.tar.gz" \
  "${RESVG_SHA256:-}"

echo "Installing resvg v${RESVG_VERSION}..."
run_quiet mkdir -p "$tmpdir/usr/local/emhttp/plugins/compose.manager/bin/"
tar -xzf resvg-linux-x86_64.tar.gz resvg
run_quiet cp resvg "$tmpdir/usr/local/emhttp/plugins/compose.manager/bin/resvg"
run_quiet chmod +x "$tmpdir/usr/local/emhttp/plugins/compose.manager/bin/resvg"
run_quiet rm resvg resvg-linux-x86_64.tar.gz


echo "Creating package description (slack-desc)..."
run_quiet mkdir -p $tmpdir/install
cat > $tmpdir/install/slack-desc << 'EOF'
compose.manager: Compose Manager Plus - Docker Compose management for unRAID
compose.manager:
compose.manager: A plugin for managing Docker Compose stacks on unRAID.
compose.manager: Provides a web UI to create, manage, and monitor your
compose.manager: compose stacks directly from the unRAID dashboard.
compose.manager:
compose.manager: Features: Docker Compose CLI, web-based stack management,
compose.manager: autostart support, environment file support, profiles,
compose.manager: built-in YAML editor, and Docker UI integration patches.
compose.manager:
compose.manager: https://github.com/mstrhakr/compose_plugin
EOF

# Build the package (Slackware convention: NAME-VERSION-ARCH-BUILD)
# Force a non-interactive yes for makepkg prompts in CI/container builds.
run_quiet bash -lc "yes y | makepkg -l y -c y \"$OUTPUT_FOLDER/compose.manager-${version}-noarch-${build}.txz\""

# Copy build log into output folder for debugging archives
if [ -d "$OUTPUT_FOLDER" ]; then
  run_quiet cp "$LOG_FILE" "$OUTPUT_FOLDER/build.log" 2>/dev/null || true
fi

# Change to root
cd /

# Calculate the MD5 checksum of the package
MD5=$(md5sum "$OUTPUT_FOLDER/compose.manager-${version}-noarch-${build}.txz")

# Write release info to a file in the output folder
{
  echo "MD5: $MD5"
  echo "Compose v${COMPOSE_VERSION}"
  echo "resvg v${RESVG_VERSION}"
  echo ""
  echo "MD5: $(echo "$MD5" | head -n1 | awk '{print $1;}')"
} >> "$OUTPUT_FOLDER/release_info"

echo "Build log preserved at $OUTPUT_FOLDER/build.log"

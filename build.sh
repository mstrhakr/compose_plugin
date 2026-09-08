#!/bin/bash
set -e

# Default values
VERSION=""
DEV=false
SKIP_TESTS=false
COMPOSE_VERSION=""
RESVG_VERSION=""
RESVG_SHA256=""
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_PATH="$SCRIPT_DIR/archive"
PLG_FILE="$SCRIPT_DIR/compose.manager.plg"
VERSIONS_FILE="$SCRIPT_DIR/versions.env"
BUILD_ENV_FILE="${BUILD_ENV_FILE:-$SCRIPT_DIR/.env}"

load_env_file() {
    local env_file="$1"
    if [[ -n "$env_file" && -f "$env_file" ]]; then
        # shellcheck disable=SC1090
        set -a
        source "$env_file"
        set +a
    fi
}

load_env_file "$VERSIONS_FILE"
load_env_file "$BUILD_ENV_FILE"

# Environment override knobs for nested Docker / Unraid host setups.
HOST_WORKSPACE_ROOT="${HOST_WORKSPACE_ROOT:-}"
HOST_SOURCE_PATH="${HOST_SOURCE_PATH:-}"
HOST_ARCHIVE_PATH_OVERRIDE="${HOST_ARCHIVE_PATH:-}"
HOST_CACHE_PATH_OVERRIDE="${HOST_CACHE_PATH:-}"

# Argument parsing
while [[ $# -gt 0 ]]; do
    case $1 in
        -Version|--Version)
            VERSION="$2"; shift 2;;
        -Dev|--Dev)
            DEV=true; shift;;
        -SkipTests|--SkipTests)
            SKIP_TESTS=true; shift;;
        -ComposeVersion|--ComposeVersion)
            COMPOSE_VERSION="$2"; shift 2;;
        *)
            echo "Unknown argument: $1"; exit 1;;
    esac
done

# Run tests unless skipped
test_script="$SCRIPT_DIR/test.sh"
if [[ -f "$test_script" && "$SKIP_TESTS" = false ]]; then
    echo -e "\033[1;33mRunning tests...\033[0m"
    bash "$test_script"
fi

# Read pinned versions from versions.env if not supplied on CLI
if [[ -f "$VERSIONS_FILE" ]]; then
    while IFS= read -r line; do
        [[ "$line" =~ ^COMPOSE_VERSION=(.+)$ ]] && [[ -z "$COMPOSE_VERSION" ]] && COMPOSE_VERSION="${BASH_REMATCH[1]}"
        [[ "$line" =~ ^RESVG_VERSION=(.+)$ ]]  && [[ -z "$RESVG_VERSION" ]]  && RESVG_VERSION="${BASH_REMATCH[1]}"
        [[ "$line" =~ ^RESVG_SHA256=(.+)$ ]]   && [[ -z "$RESVG_SHA256" ]]   && RESVG_SHA256="${BASH_REMATCH[1]}"
    done < "$VERSIONS_FILE"
fi
: "${COMPOSE_VERSION:=5.5.0}"
: "${RESVG_VERSION:=0.48.1}"

# Generate dev version with timestamp if requested
if [[ "$DEV" = true ]]; then
    VERSION="$(date +'%Y.%m.%d.%H%M')"
    echo -e "\033[1;36mGenerated dev version: $VERSION\033[0m"
fi

# If no version specified, read from .plg file
if [[ -z "$VERSION" && -f "$PLG_FILE" ]]; then
    VERSION=$(grep -oP 'ENTITY version\s+"\K[^"]+' "$PLG_FILE" | head -n1)
    if [[ -n "$VERSION" ]]; then
        echo -e "\033[1;36mUsing version from .plg file: $VERSION\033[0m"
    else
        echo "Could not determine version. Please specify -Version parameter."; exit 1
    fi
fi

# Set build number
if [[ "$VERSION" =~ [0-9]{4}\.[0-9]{2}\.[0-9]{2}\.(\d{4})$ ]]; then
    BUILD_NUM="${BASH_REMATCH[1]}"
else
    BUILD_NUM="$(date +'%H%M')"
fi
PACKAGE_BASENAME="compose.manager-$VERSION-noarch-$BUILD_NUM"
PACKAGE_NAME="$PACKAGE_BASENAME.txz"

# Ensure output directory exists
mkdir -p "$OUTPUT_PATH"

# Generate temporary plugin manifest
TEMP_PLG="$OUTPUT_PATH/compose.manager.plg"
if [[ ! -f "$PLG_FILE" ]]; then
    echo "ERROR: .plg manifest not found: $PLG_FILE"
    exit 1
fi

# Ensure the version/build in the plugin file matches the generated package.
# NOTE: Use a heredoc sed script to avoid shell quoting issues with double quotes in regex.
SED_SCRIPT=$(mktemp)
cat > "$SED_SCRIPT" << SEDEOF
s|^\s*<!ENTITY[[:space:]]+version[[:space:]]+"[^"]*"|<!ENTITY version "$VERSION"|
s|^\s*<!ENTITY[[:space:]]+packageVER[[:space:]]+"[^"]*"|<!ENTITY packageVER "$VERSION"|
s|^\s*<!ENTITY[[:space:]]+pkgBUILD[[:space:]]+"[^"]*"|<!ENTITY pkgBUILD "$BUILD_NUM"|
s|^\s*<!ENTITY[[:space:]]+packageName[[:space:]]+"[^"]*"|<!ENTITY packageName "$PACKAGE_BASENAME"|
s|^\s*<!ENTITY[[:space:]]+packagefile[[:space:]]+"[^"]*"|<!ENTITY packagefile "$PACKAGE_NAME"|
s|^\s*<!ENTITY[[:space:]]+packageURL[[:space:]]+"[^"]*"|<!ENTITY packageURL "file:///tmp/$PACKAGE_NAME"|
# Only rewrite the package-install FILE block (the one with upgradepkg), not Ace or other FILE blocks
s|^\s*<FILE[[:space:]]+Name=['"]?[^'">]+['"]?[[:space:]]+Run=['"]upgradepkg[^'"]*['"]>|<FILE Name='/tmp/$PACKAGE_NAME' Run='upgradepkg --install-new'>|
/upgradepkg/,/<\/FILE>/{ s|^\s*<URL>.*</URL>|<URL>file:///tmp/$PACKAGE_NAME</URL>| }
SEDEOF
sed -E -f "$SED_SCRIPT" "$PLG_FILE" > "$TEMP_PLG"
rm -f "$SED_SCRIPT"

echo -e "\033[1;36mGenerated temporary plugin manifest for build: $TEMP_PLG\033[0m"

# Build in Docker
# Detect whether script is running inside a container or on the host.
in_container=false
if [[ -f "/.dockerenv" ]] || grep -qE '/docker|/lxc|/kubepods' /proc/1/cgroup 2>/dev/null; then
  in_container=true
fi

# For dev container usage, plugin code and output are in the workspace under /code.
ARCHIVE_PATH="$OUTPUT_PATH"
mkdir -p "$ARCHIVE_PATH"

# Host path for docker socket operations should be the actual unRAID path.
HOST_ARCHIVE_PATH="$ARCHIVE_PATH"
if [[ -n "$HOST_ARCHIVE_PATH_OVERRIDE" ]]; then
  HOST_ARCHIVE_PATH="$HOST_ARCHIVE_PATH_OVERRIDE"
elif [[ -n "$HOST_WORKSPACE_ROOT" ]]; then
  HOST_ARCHIVE_PATH="${HOST_WORKSPACE_ROOT%/}/compose_plugin/archive"
elif [[ "$in_container" == true && -d "/mnt/user/code" ]]; then
  HOST_ARCHIVE_PATH="/mnt/user/code/compose_plugin/archive"
elif [[ "$in_container" == true && -d "/code" ]]; then
  # Map /code inside container to host path for Docker bind mounts (normally /mnt/user/code).
  read -r host_root host_source < <(awk '$5=="/code" {for(i=1;i<=NF;i++){if($i=="-"){print $4, $(i+2); exit}}}' /proc/self/mountinfo 2>/dev/null || true)
  if [[ -n "$host_root" && -n "$host_source" ]]; then
    if [[ "$host_source" == "fuse.shfs" || "$host_source" == "shfs" ]]; then
      if [[ "$host_root" =~ ^/appdata(/.*)$ ]]; then
        host_code_root="/mnt/user/appdata${BASH_REMATCH[1]}"
      elif [[ "$host_root" =~ ^/mnt/user(/.*)$ ]]; then
        host_code_root="$host_root"
      else
        host_code_root="/mnt/user${host_root}"
      fi
    else
      host_code_root="${host_source%/}${host_root}"
    fi
    HOST_ARCHIVE_PATH="$host_code_root${ARCHIVE_PATH#/code}"
    echo "Detected code root=$host_root source=$host_source -> HOST_ARCHIVE_PATH=$HOST_ARCHIVE_PATH"
  fi
fi

# Fallback for legacy /config mount paths (if /code mapping failed).
if [[ "$in_container" == true && -d "/config" && "$HOST_ARCHIVE_PATH" == "$ARCHIVE_PATH" ]]; then
  read -r host_root host_source < <(awk '$5=="/config" {for(i=1;i<=NF;i++){if($i=="-"){print $4, $(i+2); exit}}}' /proc/self/mountinfo 2>/dev/null || true)
  if [[ -n "$host_root" && -n "$host_source" ]]; then
    if [[ "$host_source" == "fuse.shfs" || "$host_source" == "shfs" ]]; then
      if [[ "$host_root" =~ ^/appdata(/.*)$ ]]; then
        host_code_root="/mnt/user/appdata${BASH_REMATCH[1]}"
      elif [[ "$host_root" =~ ^/mnt/user(/.*)$ ]]; then
        host_code_root="$host_root"
      else
        host_code_root="/mnt/user${host_root}"
      fi
    else
      host_code_root="${host_source%/}${host_root}"
    fi
    HOST_ARCHIVE_PATH="$host_code_root${ARCHIVE_PATH#/config}"
    echo "Detected config root=$host_root source=$host_source -> HOST_ARCHIVE_PATH=$HOST_ARCHIVE_PATH"
  fi
fi

mkdir -p "$HOST_ARCHIVE_PATH" 2>/dev/null || true

CACHE_PATH="$ARCHIVE_PATH/.build-cache"
HOST_CACHE_PATH="$HOST_ARCHIVE_PATH/.build-cache"
if [[ -n "$HOST_CACHE_PATH_OVERRIDE" ]]; then
  HOST_CACHE_PATH="$HOST_CACHE_PATH_OVERRIDE"
fi

SOURCE_PATH="$SCRIPT_DIR/source"
if [[ -n "$HOST_SOURCE_PATH" ]]; then
  SOURCE_PATH="$HOST_SOURCE_PATH"
elif [[ -n "$HOST_WORKSPACE_ROOT" ]]; then
  SOURCE_PATH="${HOST_WORKSPACE_ROOT%/}/compose_plugin/source"
elif [[ "$in_container" == true && -d "/mnt/user/code/compose_plugin/source" ]]; then
  SOURCE_PATH="/mnt/user/code/compose_plugin/source"
fi

mkdir -p "$ARCHIVE_PATH"
mkdir -p "$HOST_ARCHIVE_PATH"
mkdir -p "$CACHE_PATH"
mkdir -p "$HOST_CACHE_PATH"

# CA bundle setup
CONTAINER_CA_CERT="/etc/ssl/certs/ca-certificates.crt"
DEFAULT_CA_CERT_PATH="/tmp/cacert.pem"

# When this script runs inside a container, Docker bind mounts are resolved by
# the host daemon. Stage the CA bundle in the workspace archive so the host can
# mount the same file into the Slackware build container.
if [[ "$in_container" == true ]]; then
  DEFAULT_CA_CERT_PATH="$ARCHIVE_PATH/cacert.pem"
fi

SOURCE_CA_CERT="${CA_CERT_PATH:-$DEFAULT_CA_CERT_PATH}"
HOST_CA_CERT="$SOURCE_CA_CERT"

if [[ "$in_container" == true ]]; then
  STAGED_CA_CERT="$ARCHIVE_PATH/cacert.pem"
  HOST_CA_CERT="$HOST_ARCHIVE_PATH/cacert.pem"

  if [[ -f "$SOURCE_CA_CERT" && "$SOURCE_CA_CERT" != "$STAGED_CA_CERT" ]]; then
    cp "$SOURCE_CA_CERT" "$STAGED_CA_CERT"
  fi

  if [[ ! -f "$STAGED_CA_CERT" ]]; then
    echo -e "\033[1;33mCA bundle not found at $SOURCE_CA_CERT. Downloading fresh bundle to $STAGED_CA_CERT...\033[0m"
    curl -fsSL -o "$STAGED_CA_CERT" 'https://curl.se/ca/cacert.pem' || { echo "Failed to download CA bundle."; exit 1; }
  fi
else
  if [[ ! -f "$HOST_CA_CERT" ]]; then
    echo -e "\033[1;33mCA bundle not found at $HOST_CA_CERT. Downloading fresh bundle...\033[0m"
    curl -fsSL -o "$HOST_CA_CERT" 'https://curl.se/ca/cacert.pem' || { echo "Failed to download CA bundle."; exit 1; }
  fi
fi

echo "Using CA bundle host path: $HOST_CA_CERT"

# Candidate locations for pkg_build.sh
CANDIDATES=(
  "$SOURCE_PATH/pkg_build.sh"
  "$SCRIPT_DIR/source/compose.manager/pkg_build.sh"
  "$SCRIPT_DIR/../source/pkg_build.sh"
  "$SCRIPT_DIR/../source/compose.manager/pkg_build.sh"
  "$SCRIPT_DIR/source/./pkg_build.sh"
)

SOURCE_PATH_RESOLVED=""
for cand in "${CANDIDATES[@]}"; do
  if [[ -f "$cand" ]]; then
    SOURCE_PATH_RESOLVED=$(dirname "$cand")
    break
  fi
done

if [[ -z "$SOURCE_PATH_RESOLVED" ]]; then
  echo "Unable to locate pkg_build.sh in any candidate location:";
  printf '  %s\n' "${CANDIDATES[@]}"
  echo "PWD=$(pwd)";
  echo "SCRIPT_DIR=$SCRIPT_DIR";
  echo "Listing possible directories:";
  ls -ld "$SCRIPT_DIR" "$SCRIPT_DIR/source" "$SCRIPT_DIR/source/compose.manager" "$SCRIPT_DIR/../source" "$SCRIPT_DIR/../source/compose.manager" 2>/dev/null || true
  exit 1
fi

SOURCE_PATH="$SOURCE_PATH_RESOLVED"

if [[ ! -f "$SOURCE_PATH/pkg_build.sh" ]]; then
    echo "Unable to locate pkg_build.sh under SOURCE_PATH=$SOURCE_PATH"; exit 1
fi

echo "Using SOURCE_PATH=$SOURCE_PATH"
echo "pkg_build.sh exists: $(ls -l "$SOURCE_PATH/pkg_build.sh")"

# Workaround for Docker daemon path visibility issues in nested container setups:
# copy source tree to /tmp where bind mount access is more likely to work.
TMP_SOURCE_PATH="/tmp/compose_plugin_build_source"
rm -rf "$TMP_SOURCE_PATH"
mkdir -p "$TMP_SOURCE_PATH"
cp -a "$SOURCE_PATH/." "$TMP_SOURCE_PATH/"
SOURCE_PATH="$TMP_SOURCE_PATH"

echo "Docker will mount SOURCE_PATH=$SOURCE_PATH"

# Determine a strategy to provide SOURCE_PATH to the container.
# In nested Docker and CI, direct bind mounts are usually not visible to the daemon,
# so prefer the tar-stream path unless we are on a native host with a visible workspace.
USE_DIRECT_BIND=false
if [[ "$in_container" != true && -z "${CI:-}" && -d "$SOURCE_PATH" ]]; then
  USE_DIRECT_BIND=true
fi

if [[ "$USE_DIRECT_BIND" == true ]]; then
  build_cmd_direct=(docker run --rm --tmpfs /tmp \
      -v "$HOST_ARCHIVE_PATH:/mnt/output:rw" \
      -v "$HOST_CACHE_PATH:/mnt/cache:rw" \
      -v "$SOURCE_PATH:/mnt/source:ro" \
      -v "$HOST_CA_CERT:$CONTAINER_CA_CERT:ro" \
      -e TZ=America/New_York \
      -e COMPOSE_VERSION="$COMPOSE_VERSION" \
      -e RESVG_VERSION="$RESVG_VERSION" \
      -e RESVG_SHA256="$RESVG_SHA256" \
      -e OUTPUT_FOLDER=/mnt/output \
      -e DOWNLOAD_CACHE_DIR=/mnt/cache \
      -e PKG_VERSION="$VERSION" \
      -e PKG_BUILD="$BUILD_NUM" \
      -e CA_CERT="$CONTAINER_CA_CERT" \
      vbatts/slackware:latest \
      sh -c 'test -f /mnt/source/pkg_build.sh')

  if "${build_cmd_direct[@]}"; then
    echo "Direct source mount works. Running build via direct mount..."
    if ! docker run --rm --tmpfs /tmp \
        -v "$HOST_ARCHIVE_PATH:/mnt/output:rw" \
        -v "$HOST_CACHE_PATH:/mnt/cache:rw" \
        -v "$SOURCE_PATH:/mnt/source:ro" \
        -v "$HOST_CA_CERT:$CONTAINER_CA_CERT:ro" \
        -e TZ=America/New_York \
        -e COMPOSE_VERSION="$COMPOSE_VERSION" \
        -e RESVG_VERSION="$RESVG_VERSION" \
        -e RESVG_SHA256="$RESVG_SHA256" \
        -e OUTPUT_FOLDER=/mnt/output \
        -e DOWNLOAD_CACHE_DIR=/mnt/cache \
        -e PKG_VERSION="$VERSION" \
        -e PKG_BUILD="$BUILD_NUM" \
        -e CA_CERT="$CONTAINER_CA_CERT" \
        vbatts/slackware:latest \
        /mnt/source/pkg_build.sh; then
      echo "Docker build failed."; exit 1
    fi
  else
    echo "Direct bind-mount probe failed. Docker is remote or nested; falling back to tar-stream upload."
  fi
fi

if [[ "$USE_DIRECT_BIND" != true || ! "${build_cmd_direct[@]}" ]]; then
  echo "Using tar-stream upload for Docker build (safe for nested Docker and CI)."
  if ! tar -C "$SOURCE_PATH" -cf - . | docker run --rm --tmpfs /tmp -i \
      -v "$HOST_ARCHIVE_PATH:/mnt/output:rw" \
      -v "$HOST_CACHE_PATH:/mnt/cache:rw" \
      -v "$HOST_CA_CERT:$CONTAINER_CA_CERT:ro" \
      -e TZ=America/New_York \
      -e COMPOSE_VERSION="$COMPOSE_VERSION" \
      -e RESVG_VERSION="$RESVG_VERSION" \
      -e RESVG_SHA256="$RESVG_SHA256" \
      -e OUTPUT_FOLDER=/mnt/output \
      -e DOWNLOAD_CACHE_DIR=/mnt/cache \
      -e PKG_VERSION="$VERSION" \
      -e PKG_BUILD="$BUILD_NUM" \
      -e CA_CERT="$CONTAINER_CA_CERT" \
      vbatts/slackware:latest \
      sh -c 'mkdir -p /mnt/source && tar -C /mnt/source -xf - && /mnt/source/pkg_build.sh'; then
    echo "Docker build failed."; exit 1
  fi
fi

PACKAGE_PATH="$OUTPUT_PATH/$PACKAGE_NAME"
if [[ -f "$PACKAGE_PATH" ]]; then
    MD5=$(md5sum "$PACKAGE_PATH" | awk '{print $1}')
    echo -e "\n\033[1;32mBuild successful!\033[0m"
    echo -e "  \033[1;36mPackage: $PACKAGE_PATH\033[0m"
    echo -e "  \033[1;36mMD5: $MD5\033[0m"

    # Update packageMD5 in the temporary plugin manifest
    SED_MD5=$(mktemp)
    cat > "$SED_MD5" << SEDEOF
s|^\s*<!ENTITY[[:space:]]+packageMD5[[:space:]]+"[^"]*"|<!ENTITY packageMD5 "$MD5"|
/upgradepkg/,/<\/FILE>/{ s|^\s*<MD5>.*</MD5>|<MD5>$MD5</MD5>| }
SEDEOF
    sed -i -E -f "$SED_MD5" "$TEMP_PLG"
    rm -f "$SED_MD5"


fi

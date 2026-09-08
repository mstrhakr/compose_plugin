#!/bin/bash
set -euo pipefail
trap 'echo "ERROR: command failed on line $LINENO" >&2' ERR

# Default values
VERSION=""
DEV=false
REMOTE_HOSTS=()
USER_NAME="${USER_NAME:-root}"
REMOTE_DIR="${REMOTE_DIR:-/tmp}"
PACKAGE_PATH=""
SKIP_BUILD=false
COMPOSE_VERSION=""
QUICK=false

if [[ -n "${REMOTE_HOSTS:-}" ]]; then
  IFS=',' read -r -a REMOTE_HOSTS <<< "${REMOTE_HOSTS}"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARCHIVE_DIR="$SCRIPT_DIR/archive"
ENV_FILE="$SCRIPT_DIR/.env"

if [[ -f "$SCRIPT_DIR/versions.env" ]]; then
  # shellcheck disable=SC1091
  set -a
  source "$SCRIPT_DIR/versions.env"
  set +a
fi

if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  set -a
  source "$ENV_FILE"
  set +a
fi

if [[ -z "${COMPOSE_VERSION:-}" ]]; then
  COMPOSE_VERSION="5.1.2"
fi

in_container=false
if [[ -f "/.dockerenv" ]] || grep -qE '/docker|/lxc|/kubepods' /proc/1/cgroup 2>/dev/null; then
  in_container=true
fi

# Prefer the workspace archive in /code when running in a container.
ARCHIVE_DIR="$SCRIPT_DIR/archive"
if [[ "$in_container" == true && -d "/code" ]]; then
  ARCHIVE_DIR="$SCRIPT_DIR/archive"
fi

usage() {
  cat <<EOF
Usage: $0 [options]
  -Version <version>
  -Dev
  -RemoteHost <host1,host2,...>
  -User <ssh-user>
  -RemoteDir <remote-dir>
  -PackagePath <package.txz>
  -SkipBuild
  -ComposeVersion <compose-version>
  -Quick
  -Help
EOF
  exit 1
}

# parse arguments
while [[ $# -gt 0 ]]; do
  case "$1" in
    -Version|--Version)
      VERSION="$2"; shift 2;;
    -Dev|--Dev)
      DEV=true; shift;;
    -RemoteHost|--RemoteHost)
      IFS=',' read -r -a REMOTE_HOSTS <<< "$2"; shift 2;;
    -User|--User)
      USER_NAME="$2"; shift 2;;
    -RemoteDir|--RemoteDir)
      REMOTE_DIR="$2"; shift 2;;
    -PackagePath|--PackagePath)
      PACKAGE_PATH="$2"; shift 2;;
    -SkipBuild|--SkipBuild)
      SKIP_BUILD=true; shift;;
    -ComposeVersion|--ComposeVersion)
      COMPOSE_VERSION="$2"; shift 2;;
    -Quick|--Quick)
      QUICK=true; shift;;
    -Help|--Help|-h)
      usage;;
    *)
      echo "Unknown option $1"; usage;;
  esac
done

if [[ "$QUICK" == true ]]; then
  if [[ ${#REMOTE_HOSTS[@]} -eq 0 ]]; then
    echo "RemoteHost is required when using -Quick"; exit 1
  fi

  REPO_ROOT=$(git -C "$SCRIPT_DIR" rev-parse --show-toplevel 2>/dev/null || true)
  if [[ -z "$REPO_ROOT" ]]; then
    echo "Unable to resolve git repository root from $SCRIPT_DIR"; exit 1
  fi

  QUICK_PREFIX="source/compose.manager/"
  QUICK_REMOTE_ROOT="/usr/local/emhttp/plugins/compose.manager"

  mapfile -t UNSTAGED < <(git -C "$REPO_ROOT" diff --name-only --diff-filter=ACMR -- "$QUICK_PREFIX")
  mapfile -t STAGED < <(git -C "$REPO_ROOT" diff --cached --name-only --diff-filter=ACMR -- "$QUICK_PREFIX")

  mapfile -t CHANGED_FILES < <(printf '%s\n' "${UNSTAGED[@]}" "${STAGED[@]}" | sort -u)
  if [[ ${#CHANGED_FILES[@]} -eq 0 ]]; then
    echo "No tracked staged/unstaged file changes found under source/compose.manager.";
    exit 0
  fi

  echo "Files queued for quick sync (${#CHANGED_FILES[@]}):"
  printf '  %s\n' "${CHANGED_FILES[@]}"

  for host in "${REMOTE_HOSTS[@]}"; do
    target="$USER_NAME@$host"
    printf '\nQuick deploy to %s :\n' "$target"
    for relative in "${CHANGED_FILES[@]}"; do
      [[ $relative == $QUICK_PREFIX* ]] || continue
      subpath=${relative#"$QUICK_PREFIX"}
      local_path="$REPO_ROOT/$relative"
      if [[ ! -f "$local_path" ]]; then
        echo "Skipping missing local file: $relative"; continue
      fi

      remote_file="$QUICK_REMOTE_ROOT/$subpath"
      remote_parent=$(dirname "$remote_file")

      ssh "$target" mkdir -p "$remote_parent"
      scp "$local_path" "$target:$remote_file"
    done
  done

  echo "Quick deployment complete."
  exit 0
fi

if [[ -n "$PACKAGE_PATH" ]]; then
  if [[ ! -f "$PACKAGE_PATH" ]]; then
    echo "PackagePath not found: $PACKAGE_PATH"; exit 1
  fi
  PACKAGE_PATH=$(realpath "$PACKAGE_PATH")
else
  if [[ "$SKIP_BUILD" == true ]]; then
    shopt -s nullglob
    files=("$ARCHIVE_DIR"/compose.manager-*-noarch-*.txz)
    shopt -u nullglob
    if [[ ${#files[@]} -eq 0 ]]; then
      echo "No package found in archive"; exit 1
    fi
    PACKAGE_PATH=$(printf '%s\n' "${files[@]}" | sort -r | head -n1 || true)
    if [[ -z "$PACKAGE_PATH" ]]; then
      echo "No package found in archive"; exit 1
    fi
  else
    build_args=()
    [[ -n "$VERSION" ]] && build_args+=("-Version" "$VERSION")
    [[ "$DEV" == true ]] && build_args+=("-Dev")
    [[ -n "$COMPOSE_VERSION" ]] && build_args+=("-ComposeVersion" "$COMPOSE_VERSION")

    echo "Building package via build.sh..."
    bash "$SCRIPT_DIR/build.sh" "${build_args[@]}"

    # Use nullglob and ls -t to avoid xargs running with no input (which lists current directory)
    shopt -s nullglob
    files=("$ARCHIVE_DIR"/compose.manager-*-noarch-*.txz)
    shopt -u nullglob
    if [[ ${#files[@]} -eq 0 ]]; then
      echo "Archive directory content:"
      ls -al "$ARCHIVE_DIR" 2>/dev/null || echo "Cannot list archive directory"
      echo "Archive directory: $ARCHIVE_DIR"
      echo "Build completed but package not found"; exit 1
    fi
    PACKAGE_PATH=$(printf '%s\n' "${files[@]}" | sort -r | head -n1 || true)
    echo "Candidate package path: $PACKAGE_PATH"
    if [[ -z "$PACKAGE_PATH" || ! -f "$PACKAGE_PATH" ]]; then
      echo "Build completed but package not found or package path is invalid"
      echo "Archive dir content:"
      ls -al "$ARCHIVE_DIR"
      exit 1
    fi
    PACKAGE_PATH=$(realpath "$PACKAGE_PATH")
    echo "Resolved package path: $PACKAGE_PATH"
  fi
fi

PACKAGE_NAME=$(basename "$PACKAGE_PATH")

echo "Remote hosts: ${REMOTE_HOSTS[*]:-none}"
if [[ ${#REMOTE_HOSTS[@]} -eq 0 ]]; then
  echo "No RemoteHost specified — build only, skipping deploy. Package: $PACKAGE_PATH"
  exit 0
fi

for cmd in ssh scp; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "Required command not found: $cmd" >&2
    exit 1
  fi
 done

PLUGIN_PATH="$SCRIPT_DIR/archive/compose.manager.plg"
if [[ ! -f "$PLUGIN_PATH" ]]; then
  PLUGIN_PATH="$SCRIPT_DIR/compose.manager.plg"
fi
if [[ ! -f "$PLUGIN_PATH" ]]; then
  echo "Plugin file not found"; exit 1
fi

INSTALL_SCRIPT_LOCAL="$SCRIPT_DIR/install.sh"
if [[ ! -f "$INSTALL_SCRIPT_LOCAL" ]]; then
  echo "Install script not found: $INSTALL_SCRIPT_LOCAL"; exit 1
fi

for host in "${REMOTE_HOSTS[@]}"; do
  target="$USER_NAME@$host"
  remote_package="$REMOTE_DIR/$PACKAGE_NAME"
  remote_plugin="$REMOTE_DIR/$(basename "$PLUGIN_PATH")"
  remote_install_script="$REMOTE_DIR/install.sh"

  printf '\nDeploying to %s :\n' "$target"
  echo "  Local package : $PACKAGE_PATH"
  echo "  Local .plg    : $PLUGIN_PATH"
  echo "  Local install : $INSTALL_SCRIPT_LOCAL"
  echo "  Remote target : $target:$REMOTE_DIR"

  echo "Uploading package, .plg and install.sh via SCP..."
  scp "$PACKAGE_PATH" "$target:$REMOTE_DIR/"
  scp "$PLUGIN_PATH" "$target:$REMOTE_DIR/"
  scp "$INSTALL_SCRIPT_LOCAL" "$target:$remote_install_script"

  echo "Executing remote install script..."
  # shellcheck disable=SC2029
  ssh "$target" bash "$remote_install_script" "$remote_package" "$remote_plugin" && ssh "$target" rm -f "$remote_install_script"

  echo "Deployment complete to $host"
done

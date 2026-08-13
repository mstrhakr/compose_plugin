#!/bin/bash
# Compose auto-update runner
# Args: <compose_yml_path> <project_name>
#   or: <project_name> with COMPOSE_FILE_LIST / COMPOSE_ENV_FILE set.

# shellcheck disable=SC1091
. "$(dirname "$0")/common.sh"

COMPOSE_FILE_ARG="$1"
PROJECT_NAME="$2"
COMPOSE_FILE_LIST="${COMPOSE_FILE_LIST:-}"
COMPOSE_ENV_FILE="${COMPOSE_ENV_FILE:-}"
COMPOSE_PROJECT_DIR="${COMPOSE_PROJECT_DIR:-}"
COMPOSE_FILE="${COMPOSE_FILE_ARG:-${COMPOSE_FILE:-}}"

# If this script is invoked by the background runner, the first positional
# argument is the project name and compose files are supplied through env vars.
if [ -z "$PROJECT_NAME" ] && [ -n "$COMPOSE_FILE_LIST" ]; then
  PROJECT_NAME="$COMPOSE_FILE_ARG"
  COMPOSE_FILE_ARG=""
fi

# If the script is invoked with a single arg and COMPOSE_FILE is set via
# the environment, treat the first arg as project name.
if [ -z "$PROJECT_NAME" ] && [ -n "$COMPOSE_FILE" ]; then
  PROJECT_NAME="$COMPOSE_FILE_ARG"
  COMPOSE_FILE_ARG=""
fi

NOTIFY="/usr/local/emhttp/webGui/scripts/notify"
LOCK_DIR="/var/run/compose.manager"
LOCK_TIMEOUT=${COMPOSE_LOCK_TIMEOUT:-30}
COMMAND_TIMEOUT=${COMPOSE_COMMAND_TIMEOUT:-1800}

trim() {
  local var="$*"
  # Remove leading whitespace
  var="${var#"${var%%[![:space:]]*}"}"
  # Remove trailing whitespace
  var="${var%"${var##*[![:space:]]}"}"
  echo "$var"
}

compose_file_args=()
env_file_args=()
project_dir_args=()
build_compose_file_args() {
  local file_spec
  file_spec="$(trim "$1")"
  local sep="${COMPOSE_PATH_SEPARATOR:-:}"
  local parts

  if [ -z "$file_spec" ]; then
    return 0
  fi

  case "$file_spec" in
    *"$sep"*)
      IFS="$sep" read -r -a parts <<< "$file_spec"
      ;;
    *)
      parts=("$file_spec")
      ;;
  esac

  for file in "${parts[@]}"; do
    file="$(trim "$file")"
    if [ -n "$file" ]; then
      compose_file_args+=("-f" "$file")
    fi
  done
}

build_env_file_args() {
  local path
  path="$(trim "$1")"
  if [ -n "$path" ] && [ -f "$path" ]; then
    env_file_args+=(--env-file "$path")
  fi
}

if [ -z "$PROJECT_NAME" ]; then
  echo "Usage: $0 <compose_yml_path> <project_name>" >&2
  echo "   or: COMPOSE_FILE_LIST=... COMPOSE_ENV_FILE=... $0 <project_name>" >&2
  exit 2
fi

if [ -n "$COMPOSE_FILE_LIST" ]; then
  build_compose_file_args "$COMPOSE_FILE_LIST"
elif [ -n "$COMPOSE_FILE_ARG" ]; then
  build_compose_file_args "$COMPOSE_FILE_ARG"
fi

build_env_file_args "$COMPOSE_ENV_FILE"

if [ -n "$COMPOSE_PROJECT_DIR" ] && [ -d "$COMPOSE_PROJECT_DIR" ]; then
  project_dir_args=("--project-directory" "$COMPOSE_PROJECT_DIR")
fi

if [ ${#compose_file_args[@]} -eq 0 ] && [ ${#project_dir_args[@]} -eq 0 ]; then
  echo "No compose file paths or project directory were provided" >&2
  exit 2
fi

# Create lock directory if needed
mkdir -p "$LOCK_DIR" 2>/dev/null || true

# Acquire lock to prevent concurrent operations on the same stack
LOCK_FILE="$LOCK_DIR/${PROJECT_NAME}.lock"
exec 9>"$LOCK_FILE"

waited=0
while ! flock -n 9 2>/dev/null; do
    if [ "$waited" -ge "$LOCK_TIMEOUT" ]; then
        echo "Could not acquire lock for $PROJECT_NAME after ${LOCK_TIMEOUT}s - another operation may be in progress" >&2
        exit 1
    fi
    sleep 1
    waited=$((waited + 1))
done

# Write lock info
echo "{\"pid\":$$,\"command\":\"autoupdate\",\"time\":\"$(date -Iseconds)\"}" >&9

OUT=$(mktemp 2>/dev/null || echo "")
if [ -z "$OUT" ]; then
  echo "Failed to create temporary output file" >&2
  exit 1
fi
RC=0

summarize_output() {
  # Keep notifications readable and avoid huge untrusted payloads.
  tail -n 40 "$OUT" 2>/dev/null | tr -cd '[:print:]\n\t'
}

# Get current image digests before pull
# Uses docker compose images to list service images, then inspects each for RepoDigests
get_image_digests() {
  docker compose "${project_dir_args[@]}" "${compose_file_args[@]}" "${env_file_args[@]}" -p "$PROJECT_NAME" images -q 2>/dev/null | while read -r img_id; do
    if [ -n "$img_id" ]; then
      docker inspect --format='{{.Id}}' "$img_id" 2>/dev/null || echo "$img_id"
    fi
  done | sort
}

OLD_DIGESTS=$(get_image_digests || true)

# Run pull and capture output (timeout prevents indefinite hangs on unresponsive registries)
# --ignore-buildable: skip services with build sections (they should be rebuilt, not pulled)
timeout "$COMMAND_TIMEOUT" docker compose "${project_dir_args[@]}" "${compose_file_args[@]}" "${env_file_args[@]}" -p "$PROJECT_NAME" pull --ignore-buildable > "$OUT" 2>&1 || RC=$?

if [ "$RC" -ne 0 ]; then
  ERRMSG="Auto-update pull failed for '$PROJECT_NAME'. Recent output: $(summarize_output)"
  composeLogger "$ERRMSG" error autoupdate daemon
  echo "$ERRMSG" >&2
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Auto-update failed: $PROJECT_NAME" -d "$ERRMSG" -i 'warning'
  fi
  rm -f "$OUT"
  exit 1
fi

# Get new image digests after pull
NEW_DIGESTS=$(get_image_digests || true)

# Compare digests to determine if any images were updated
if [ "$OLD_DIGESTS" != "$NEW_DIGESTS" ]; then
  # Images changed - run recreate/up
  timeout "$COMMAND_TIMEOUT" docker compose "${project_dir_args[@]}" "${compose_file_args[@]}" "${env_file_args[@]}" -p "$PROJECT_NAME" up -d >> "$OUT" 2>&1 || RC=$?
  
  if [ "$RC" -ne 0 ]; then
    ERRMSG="Auto-update up failed for '$PROJECT_NAME'. Recent output: $(summarize_output)"
    composeLogger "$ERRMSG" error autoupdate daemon
    echo "$ERRMSG" >&2
    if [ -x "$NOTIFY" ]; then
      "$NOTIFY" -e 'Compose Manager' -s "Auto-update failed: $PROJECT_NAME" -d "$ERRMSG" -i 'warning'
    fi
    rm -f "$OUT"
    exit 1
  fi
  
  MSG="Stack '$PROJECT_NAME' was updated successfully."
  composeLogger "$MSG" info autoupdate daemon
  echo "$MSG"
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Stack updated: $PROJECT_NAME" -d "$MSG"
  fi
else
  MSG="No updates for stack '$PROJECT_NAME'."
  composeLogger "$MSG" info autoupdate daemon
  echo "$MSG"
fi

rm -f "$OUT"
exit 0

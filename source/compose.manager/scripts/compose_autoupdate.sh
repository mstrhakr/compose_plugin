#!/bin/bash
# Notification adapter. All Docker operations and stack locking belong to compose.sh.
# Preferred args: the shared builder's compose.sh update arguments.
# Legacy args: <compose_file> <project_name>, or <project_name> with COMPOSE_FILE_LIST.

# shellcheck disable=SC1091
. "$(dirname "$0")/common.sh"

COMPOSE_PARALLEL_LIMIT=${COMPOSE_PARALLEL_LIMIT:-4}
case "$COMPOSE_PARALLEL_LIMIT" in
  ''|*[!0-9]*) COMPOSE_PARALLEL_LIMIT=4 ;;
esac
if [ "$COMPOSE_PARALLEL_LIMIT" -lt 1 ] || [ "$COMPOSE_PARALLEL_LIMIT" -gt 16 ]; then
  COMPOSE_PARALLEL_LIMIT=4
fi
export COMPOSE_PARALLEL_LIMIT

if [ "${1:-}" != -cupdate ]; then
  project_name="${2:-${1:-}}"
  file_list="${COMPOSE_FILE_LIST:-${COMPOSE_FILE:-}}"
  if [ -n "${2:-}" ]; then file_list="$1"; fi
  if [ -z "$project_name" ] || { [ -z "$file_list" ] && [ -z "${COMPOSE_PROJECT_DIR:-}" ]; }; then
    echo "Usage: $0 -cupdate <compose.sh arguments>, or <compose_file> <project_name>" >&2
    exit 2
  fi
  args=(-cupdate "-p$project_name")
  if [ -n "$file_list" ]; then
    IFS="${COMPOSE_PATH_SEPARATOR:-:}" read -r -a files <<< "$file_list"
    for file in "${files[@]}"; do args+=("-f$file"); done
  elif [ -n "${COMPOSE_PROJECT_DIR:-}" ]; then
    args+=("-w$COMPOSE_PROJECT_DIR")
  fi
  if [ -n "${COMPOSE_ENV_FILE:-}" ]; then args+=("-e$COMPOSE_ENV_FILE"); fi
  if [ -n "${COMPOSE_CREDENTIAL_ID:-}" ]; then args+=(--credential-id "$COMPOSE_CREDENTIAL_ID"); fi
  set -- "${args[@]}"
fi

project_name=""
for arg in "$@"; do
  case "$arg" in -p?*) project_name="${arg#-p}" ;; esac
done

if ! output_dir=$(mktemp -d); then
  composeLogger "Could not create auto-update output files" error autoupdate daemon
  exit 1
fi
trap 'rm -f "$output_dir/output" "$output_dir/outcome"; rmdir "$output_dir"' EXIT

bash "$(dirname "$0")/compose.sh" "$@" --automatic-update \
  --update-outcome-file "$output_dir/outcome" > "$output_dir/output" 2>&1
rc=$?
outcome=""
if [ "$rc" -eq 0 ]; then
  if [ -f "$output_dir/outcome" ]; then
    outcome=$(cat "$output_dir/outcome")
  fi
  case "$outcome" in
    updated|unchanged|skipped|partial) ;;
    *)
      echo "Auto-update returned an invalid outcome: $outcome" >> "$output_dir/output"
      rc=1
      ;;
  esac
fi
cat "$output_dir/output"
notify="${COMPOSE_NOTIFY_SCRIPT:-/usr/local/emhttp/webGui/scripts/notify}"
if [ "$rc" -ne 0 ]; then
  message="Auto-update failed for '$project_name'. Recent output: $(tail -n 40 "$output_dir/output" | tr -cd '[:print:]\n\t')"
  composeLogger "$message" error autoupdate daemon
  if [ -x "$notify" ]; then
    "$notify" -e 'Compose Manager' -s "Auto-update failed: $project_name" -d "$message" -i warning
  fi
  exit "$rc"
fi

case "$outcome" in
  partial)
    if [ -x "$notify" ]; then
      "$notify" -e 'Compose Manager' -s "Stack update incomplete: $project_name" -d "Some services in '$project_name' were skipped for safety. Review the update log." -i warning
    fi
    ;;
  updated)
    if [ -x "$notify" ]; then
      "$notify" -e 'Compose Manager' -s "Stack updated: $project_name" -d "Stack '$project_name' was updated successfully."
    fi
    ;;
  unchanged|skipped) ;;
esac
exit 0

#!/bin/sh
# Compose Manager - Background command runner
# Runs a compose command fully in background, captures output to last_cmd.log,
# and sends an Unraid system notification on success or failure.
#
# Args: <compose_sh_args...>
#   Must include -s <stack_path> so the log and result are written correctly.
#   All other args are passed verbatim to compose.sh.

# shellcheck disable=SC1091
. "$(dirname "$0")/common.sh"

COMPOSE_SH="$(dirname "$0")/compose.sh"
NOTIFY="/usr/local/emhttp/webGui/scripts/notify"

# Parse stack path (-s) from the argument list so we know where to write the log
STACK_PATH=""
OPERATION=""
NEXT_S=""
NEXT_C=""
for i in "$@"; do
  if [ -n "$NEXT_S" ]; then STACK_PATH="$i"; NEXT_S=""; continue; fi
  if [ -n "$NEXT_C" ]; then OPERATION="$i"; NEXT_C=""; continue; fi
  case "$i" in
    -s)  NEXT_S=1 ;;
    -s*) STACK_PATH="${i#-s}" ;;
    -c)  NEXT_C=1 ;;
    -c*) OPERATION="${i#-c}" ;;
  esac
done

LOG_FILE="${STACK_PATH}/last_cmd.log"
LOCK_FILE="${STACK_PATH}/.compose.lock"
PROJECT_NAME=$(basename "$STACK_PATH")

# Ensure the stack directory exists before writing
if [ -z "$STACK_PATH" ] || [ ! -d "$STACK_PATH" ]; then
  composeLogger "Background command failed: stack path not found ($STACK_PATH)" error compose daemon
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Background command failed: $PROJECT_NAME" \
      -d "Could not determine stack path for background operation." -i 'warning'
  fi
  exit 1
fi

# Use flock to queue operations per-stack — if another background command is
# already running on this stack, we wait for it to finish before starting.
exec 9>"$LOCK_FILE"
if ! flock -w 600 9; then
  MSG="Timed out waiting for lock on '$PROJECT_NAME' after 10 minutes."
  composeLogger "$MSG" error compose daemon
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Background $OPERATION timed out: $PROJECT_NAME" -d "$MSG" -i 'warning'
  fi
  exit 1
fi

# Create a readable header in the log
{
  echo "=== Compose Manager Background Run ==="
  echo "Stack:     $PROJECT_NAME"
  echo "Operation: $OPERATION"
  echo "Started:   $(date)"
  echo "======================================="
  echo ""
} > "$LOG_FILE"

# Run compose.sh, capturing all output (stdout + stderr) and appending to the log
composeLogger "Background $OPERATION starting for $PROJECT_NAME" info compose daemon
sh "$COMPOSE_SH" "$@" >> "$LOG_FILE" 2>&1
RC=$?

{
  echo ""
  echo "======================================="
  echo "Finished:  $(date)"
  echo "Exit code: $RC"
  echo "======================================="
} >> "$LOG_FILE"

# Lock is released automatically when fd 9 closes (script exits)

# Send Unraid notification
if [ $RC -eq 0 ]; then
  MSG="Stack '$PROJECT_NAME' ($OPERATION) completed successfully."
  composeLogger "$MSG" info compose daemon
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Stack $OPERATION complete: $PROJECT_NAME" -d "$MSG"
  fi
elif [ "$OPERATION" = "gitdeploy" ] && [ $RC -eq "$GIT_DEPLOY_EXIT_SKIPPED" ]; then
  # An automatic deploy that no longer applied changed nothing; the scheduled check
  # that started it notifies, if anything needs saying, at its next pass.
  composeLogger "Stack '$PROJECT_NAME' ($OPERATION) skipped: the automatic deploy no longer applied." info compose daemon
else
  # Include last few lines of output in the notification for quick diagnosis
  TAIL=$(tail -n 10 "$LOG_FILE" 2>/dev/null | tr -cd '[:print:]\n\t')
  MSG="Stack '$PROJECT_NAME' ($OPERATION) failed (exit $RC). Recent output: $TAIL"
  composeLogger "$MSG" error compose
  if [ -x "$NOTIFY" ]; then
    "$NOTIFY" -e 'Compose Manager' -s "Stack $OPERATION failed: $PROJECT_NAME" -d "$MSG" -i 'warning'
  fi
fi

exit $RC

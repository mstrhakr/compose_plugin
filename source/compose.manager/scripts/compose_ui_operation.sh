#!/bin/sh
# A receipt belongs to one dispatch, including time spent waiting in a bulk queue.
set -u

# Invoked by the EXIT trap.
# shellcheck disable=SC2317
finish() {
    file="$1"
    code="$2"
    if [ -n "${COMPOSE_TERMINAL_CANCEL_FILE:-}" ] && [ -f "$COMPOSE_TERMINAL_CANCEL_FILE" ]; then
        return
    fi
    temporary="${file}.$$"
    printf '{"state":"completed","exitCode":%s}\n' "$code" > "$temporary" &&
        mv -f "$temporary" "$file"
}

COMPOSE_UI_OPERATION_FILE="$1"
export COMPOSE_UI_OPERATION_FILE
shift
temporary="${COMPOSE_UI_OPERATION_FILE}.$$"
printf '{"state":"running"}\n' > "$temporary" &&
    mv -f "$temporary" "$COMPOSE_UI_OPERATION_FILE" || exit 1
trap 'code=$?; finish "$COMPOSE_UI_OPERATION_FILE" "$code" || echo "Unable to save Compose operation receipt" >&2' EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
"$@"
exit $?

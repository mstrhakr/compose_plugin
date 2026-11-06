#!/bin/bash
set -u
if [ "$1" = --follow ]; then
    touch "$4" "$2.viewed" || exit 1
    if [ "$3" -gt 1 ]; then
        exec tail --pid="$3" -n +1 -F "$2"
    fi
    exec cat "$2"
fi

socket=$1
output=$2
session_pid=$3
connected="$output.connected"
ttyd -R -o -i "$socket" bash "$0" --follow "$output" "$session_pid" "$connected" &
viewer=$!

# The listener is disposable, but the action's process session is not.
# shellcheck disable=SC2317
cleanup() {
    if kill -0 "$viewer" 2>/dev/null; then
        kill -TERM "$viewer"
    fi
    wait "$viewer" 2>/dev/null || true
    rm -f "$socket" "$connected"
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 129' HUP
for ((attempt=0; attempt<300; attempt++)); do
    if [ -f "$connected" ]; then
        wait "$viewer"
        exit $?
    fi
    if ! kill -0 "$viewer" 2>/dev/null; then
        wait "$viewer"
        exit $?
    fi
    sleep 0.1
done
echo "Terminal viewer was not connected within 30 seconds; closing its listener." >&2
exit 1

#!/bin/bash
# The command owns a session; ttyd is only a disposable output viewer.
set -uo pipefail
receipt=$1
command=$2
log=$3
shift 3
operations=("$@")
COMPOSE_TERMINAL_CANCEL_FILE="$receipt.cancel"
export COMPOSE_TERMINAL_CANCEL_FILE
cancelled=false

# Invoked only during cancellation.
# shellcheck disable=SC2317
group_members() {
    members=()
    local file pid stat rest
    local -a fields
    for file in /proc/[0-9]*/stat; do
        read -r stat < "$file" 2>/dev/null || continue
        pid=${stat%% *}
        rest=${stat##*) }
        read -r -a fields <<< "$rest"
        if [ "$pid" != "$$" ] && [ "${fields[2]}" = "$$" ] &&
            [ "${fields[0]}" != Z ] && [ "${fields[0]}" != X ]; then
            members+=("$pid")
        fi
    done
}

# Invoked by signal/exit traps.
# shellcheck disable=SC2317
finish() {
    local code=$?
    trap - EXIT
    local file
    # A cancelled bulk run must not leave undispatched operations queued forever.
    for file in "${operations[@]}"; do
        if grep -Eq '"state":"(queued|running)"' "$file"; then
            local operation_code=$code
            if [ "$cancelled" = true ]; then operation_code=143; fi
            if [ "$operation_code" -eq 0 ]; then operation_code=1; fi
            printf '{"state":"completed","exitCode":%s}\n' "$operation_code" > "$file.$$" &&
                mv -f "$file.$$" "$file" || echo "Unable to cancel queued operation receipt" >&2
        fi
    done
    printf '{"state":"completed","exitCode":%s}\n' "$code" > "$receipt.$$" &&
        mv -f "$receipt.$$" "$receipt" || echo "Unable to save terminal receipt" >&2
    rm -f "$COMPOSE_TERMINAL_CANCEL_FILE"
}

# shellcheck disable=SC2317
stop() {
    trap '' TERM
    cancelled=true
    if ! touch "$COMPOSE_TERMINAL_CANCEL_FILE"; then
        echo "Unable to register terminal cancellation" >&2
        exit 1
    fi
    # Signal the entire owned session group, including nested Compose/build processes.
    kill -TERM -- "-$$"
    for ((attempt=0; ; attempt++)); do
        group_members
        if [ "${#members[@]}" -eq 0 ]; then break; fi
        if [ "$attempt" -ge 100 ]; then
            echo "Command did not stop within 10 seconds; terminating remaining session processes." >&2
            # The supervisor must remain alive to finalize receipts.
            kill -KILL -- "${members[@]}" 2>/dev/null || true
        fi
        sleep 0.1
    done
    if [ -n "$child" ]; then wait "$child" || true; fi
    exit 143
}

trap finish EXIT
child=''
trap stop TERM
# /proc stat's comm field may contain spaces or parentheses.
stat=$(cat "/proc/$$/stat")
rest=${stat##*) }
read -r -a fields <<< "$rest"
printf '{"state":"running","pid":%s,"started":"%s"}\n' "$$" "${fields[19]}" > "$receipt.$$" &&
    mv -f "$receipt.$$" "$receipt" || exit 1
# No interactive stdin is promised for managed compose actions.
if [ -n "$log" ]; then
    (bash -c "$command" 2>&1 | tee "$log") &
else
    bash -c "$command" &
fi
child=$!
wait "$child"
code=$?
wait
exit "$code"

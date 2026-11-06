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
declare -A owned_processes=()
declare -A pty_leaders=()

# Invoked only during cancellation.
# shellcheck disable=SC2317
session_members() {
    members=()
    local file pid stat rest
    local -a fields
    local -A parents=() starts=() sessions=()
    pty_leaders=()
    for file in /proc/[0-9]*/stat; do
        read -r stat < "$file" 2>/dev/null || continue
        pid=${stat%% *}
        rest=${stat##*) }
        read -r -a fields <<< "$rest"
        if [ "${fields[0]}" = Z ] || [ "${fields[0]}" = X ]; then continue; fi
        parents[$pid]=${fields[1]}
        starts[$pid]=${fields[19]}
        sessions[$pid]=${fields[3]}
    done
    for pid in "${!sessions[@]}"; do
        if [ "${sessions[$pid]}" != "$$" ] && [ "${sessions[$pid]}" != "$pid" ] &&
            [ -n "${starts[${sessions[$pid]}]:-}" ]; then
            pty_leaders[${sessions[$pid]}]=${starts[${sessions[$pid]}]}
        fi
    done
    # PTY capture creates another session. Retain descendant identities across
    # reparenting so cancellation still drains them after their parent exits.
    local changed=true
    while [ "$changed" = true ]; do
        changed=false
        for pid in "${!parents[@]}"; do
            [ "$pid" != "$$" ] || continue
            if [ "${sessions[$pid]}" = "$$" ] || [ "${parents[$pid]}" = "$$" ] ||
                { [ -n "${owned_processes[${parents[$pid]}]:-}" ] &&
                    [ "${owned_processes[${parents[$pid]}]}" = "${starts[${parents[$pid]}]:-}" ]; }; then
                if [ -z "${owned_processes[$pid]:-}" ]; then
                    owned_processes[$pid]=${starts[$pid]}
                    changed=true
                fi
            fi
        done
    done
    for pid in "${!owned_processes[@]}"; do
        if [ "${owned_processes[$pid]}" = "${starts[$pid]:-}" ]; then members+=("$pid"); fi
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
    local pid process_name
    local -A signalled=()
    # Other process groups (for example GNU timeout) can belong to this session.
    session_members
    for ((attempt=0; ; attempt++)); do
        session_members
        if [ "${#members[@]}" -eq 0 ]; then break; fi
        for pid in "${members[@]}"; do
            # Let the PTY bridge drain naturally; killing it sends HUP to the
            # command before its TERM cleanup can finish.
            if read -r process_name < "/proc/$pid/comm" 2>/dev/null && [ "$process_name" = script ]; then continue; fi
            if [ "${pty_leaders[$pid]:-}" = "${owned_processes[$pid]}" ]; then continue; fi
            if [ -z "${signalled[$pid]:-}" ]; then
                kill -TERM -- "$pid" 2>/dev/null || true
                signalled[$pid]=true
            fi
        done
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
(SHELL=/bin/bash TERM=xterm-256color script -qefc "$command" /dev/null < /dev/null |
    (trap '' TERM; exec php "$(dirname "$0")/compose_terminal_output.php" "$receipt.log" "$log")) &
child=$!
wait "$child"
code=$?
wait
exit "$code"

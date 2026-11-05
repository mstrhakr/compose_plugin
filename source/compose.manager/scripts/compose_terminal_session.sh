#!/bin/bash
# The command owns a session; ttyd is only a disposable output viewer.
set -uo pipefail
receipt=$1
command=$2
log=$3
shift 3
operations=("$@")

# Invoked by signal/exit traps.
# shellcheck disable=SC2317
finish() {
    local code=$?
    trap - EXIT
    local file
    # A cancelled bulk run must not leave undispatched operations queued forever.
    for file in "${operations[@]}"; do
        if grep -qx '{"state":"queued"}' "$file"; then
            printf '{"state":"completed","exitCode":143}\n' > "$file.$$" &&
                mv -f "$file.$$" "$file" || echo "Unable to cancel queued operation receipt" >&2
        fi
    done
    printf '{"state":"completed","exitCode":%s}\n' "$code" > "$receipt.$$" &&
        mv -f "$receipt.$$" "$receipt" || echo "Unable to save terminal receipt" >&2
}

# shellcheck disable=SC2317
stop() {
    trap '' TERM
    # Signal the entire owned session group, including nested Compose/build processes.
    kill -TERM -- "-$$"
    if [ -n "$child" ]; then wait "$child" || true; fi
    exit 143
}

trap finish EXIT
child=''
trap stop TERM
# /proc stat's comm field may contain spaces or parentheses.
stat=$(cat "/proc/$$/stat")
fields=${stat##*) }
read -r -a fields <<< "$fields"
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

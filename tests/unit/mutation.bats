#!/usr/bin/env bats
load '../bats_setup.bash'

COMPOSE_SCRIPT="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose.sh"

test_setup() {
    unset -f docker php 2>/dev/null || true
    setup_mutation_php
    export COMPOSE_LOCK_DIR="$TEST_TEMP_DIR/locks" COMPOSE_LOCK_TIMEOUT=0
    export COMPOSE_MANAGER_CFG_FILE="$TEST_TEMP_DIR/settings"
    export MUTATION_READY="$TEST_TEMP_DIR/ready" MUTATION_RELEASE="$TEST_TEMP_DIR/release"
    export MUTATION_RESULT=0
    mkdir -p "$TMPDIR/compose_manager_boot_config"
    printf '%s\n' '{"Display Folder":1}' > "$TMPDIR/compose_manager_boot_config/pending-recheck.json"
    mkdir -p "$TEST_TEMP_DIR/bin" "$TEST_TEMP_DIR/Display Folder"
    cat > "$TEST_TEMP_DIR/bin/docker" <<'DOCKER'
#!/bin/bash
touch "$MUTATION_READY"
while [ ! -f "$MUTATION_RELEASE" ]; do sleep 0.02; done
exit "$MUTATION_RESULT"
DOCKER
    chmod +x "$TEST_TEMP_DIR/bin/docker"
    export PATH="$TEST_TEMP_DIR/bin:$PATH"
    MUTATION_PID=""
}

test_teardown() {
    touch "$MUTATION_RELEASE"
    if [ -n "$MUTATION_PID" ] && kill -0 "$MUTATION_PID" 2>/dev/null; then
        kill "$MUTATION_PID"
        wait "$MUTATION_PID" || true
    fi
}

start_mutation() {
    bash "$COMPOSE_SCRIPT" -c stop -p legacy___runtime-- -s "$TEST_TEMP_DIR/Display Folder" > "$TEST_TEMP_DIR/output" 2>&1 &
    MUTATION_PID=$!
    local attempts=0
    while [ ! -f "$MUTATION_READY" ]; do
        if ! kill -0 "$MUTATION_PID" 2>/dev/null || [ "$attempts" -ge 250 ]; then
            cat "$TEST_TEMP_DIR/output"
            return 1
        fi
        sleep 0.02
        attempts=$((attempts + 1))
    done
}

@test "shell success and failure renew pending evidence at both locked boundaries" {
    for result in 0 7; do
        rm -f "$MUTATION_READY" "$MUTATION_RELEASE"
        export MUTATION_RESULT="$result"
        start_mutation
        local epoch pending
        epoch=$(cat "$COMPOSE_LOCK_DIR/legacy___runtime--.epoch")
        pending=$(cat "$TMPDIR/compose_manager_boot_config/pending-recheck.json")
        [[ "$pending" = *'"Display Folder"'* ]]
        [[ "$pending" != *'"legacy___runtime--"'* ]]
        ! flock -n "$COMPOSE_LOCK_DIR/legacy___runtime--.lock" true
        touch "$MUTATION_RELEASE"
        local status=0
        wait "$MUTATION_PID" || status=$?
        MUTATION_PID=""
        [ "$status" -eq "$result" ]
        [ "$epoch" != "$(cat "$COMPOSE_LOCK_DIR/legacy___runtime--.epoch")" ]
        [ "$pending" != "$(cat "$TMPDIR/compose_manager_boot_config/pending-recheck.json")" ]
        flock -n "$COMPOSE_LOCK_DIR/legacy___runtime--.lock" true
    done
}

@test "interrupted mutation retains its begin epoch and pending mark" {
    start_mutation
    local epoch pending
    epoch=$(cat "$COMPOSE_LOCK_DIR/legacy___runtime--.epoch")
    pending=$(cat "$TMPDIR/compose_manager_boot_config/pending-recheck.json")
    kill -9 "$MUTATION_PID"
    wait "$MUTATION_PID" || true
    MUTATION_PID=""
    touch "$MUTATION_RELEASE"
    local attempts=0
    while ! flock -n "$COMPOSE_LOCK_DIR/legacy___runtime--.lock" true; do
        [ "$attempts" -lt 250 ]
        sleep 0.02
        attempts=$((attempts + 1))
    done
    [ "$epoch" = "$(cat "$COMPOSE_LOCK_DIR/legacy___runtime--.epoch")" ]
    [ "$pending" = "$(cat "$TMPDIR/compose_manager_boot_config/pending-recheck.json")" ]
}

@test "terminated mutation renews pending before releasing its operation lock" {
    start_mutation
    local epoch pending
    epoch=$(cat "$COMPOSE_LOCK_DIR/legacy___runtime--.epoch")
    pending=$(cat "$TMPDIR/compose_manager_boot_config/pending-recheck.json")
    kill -TERM "$MUTATION_PID"
    touch "$MUTATION_RELEASE"
    local status=0
    wait "$MUTATION_PID" || status=$?
    MUTATION_PID=""
    [ "$status" -eq 143 ]
    [ "$epoch" != "$(cat "$COMPOSE_LOCK_DIR/legacy___runtime--.epoch")" ]
    [ "$pending" != "$(cat "$TMPDIR/compose_manager_boot_config/pending-recheck.json")" ]
    flock -n "$COMPOSE_LOCK_DIR/legacy___runtime--.lock" true
}

@test "failed end bookkeeping returns failure and retains the begin mark" {
    cat > "$TEST_TEMP_DIR/bin/php" <<'PHP'
#!/bin/bash
if [[ "${1:-}" = */mutation_boundary.php ]]; then
    if [ -e "$TMPDIR/boundary-started" ]; then exit 1; fi
    touch "$TMPDIR/boundary-started"
fi
exec "$MUTATION_PHP_SHIM/php" "$@"
PHP
    chmod +x "$TEST_TEMP_DIR/bin/php"
    start_mutation
    local pending
    pending=$(cat "$TMPDIR/compose_manager_boot_config/pending-recheck.json")
    touch "$MUTATION_RELEASE"
    local status=0
    wait "$MUTATION_PID" || status=$?
    MUTATION_PID=""
    [ "$status" -eq 1 ]
    [ "$pending" = "$(cat "$TMPDIR/compose_manager_boot_config/pending-recheck.json")" ]
    grep -F '"result":"failed","exit_code":1' "$TEST_TEMP_DIR/Display Folder/last_result.json"
    flock -n "$COMPOSE_LOCK_DIR/legacy___runtime--.lock" true
}

@test "failed begin bookkeeping refuses Docker mutation" {
    cat > "$TEST_TEMP_DIR/bin/php" <<'PHP'
#!/bin/bash
exit 1
PHP
    chmod +x "$TEST_TEMP_DIR/bin/php"
    run bash "$COMPOSE_SCRIPT" -c stop -p legacy___runtime-- -s "$TEST_TEMP_DIR/Display Folder"
    assert_failure
    [ ! -e "$MUTATION_READY" ]
}

@test "ordinary lifecycle commands renew existing marks but do not create new ones" {
    touch "$MUTATION_RELEASE"
    for command in up down stop; do
        printf '{}\n' > "$TMPDIR/compose_manager_boot_config/pending-recheck.json"
        run bash "$COMPOSE_SCRIPT" -c "$command" -p legacy___runtime-- -s "$TEST_TEMP_DIR/Display Folder"
        assert_success
        [ "$(cat "$TMPDIR/compose_manager_boot_config/pending-recheck.json")" = '[]' ]
        printf '%s\n' '{"Display Folder":1}' > "$TMPDIR/compose_manager_boot_config/pending-recheck.json"
        run bash "$COMPOSE_SCRIPT" -c "$command" -p legacy___runtime-- -s "$TEST_TEMP_DIR/Display Folder"
        assert_success
        grep -F '"Display Folder"' "$TMPDIR/compose_manager_boot_config/pending-recheck.json"
        ! grep -F '"Display Folder": 1' "$TMPDIR/compose_manager_boot_config/pending-recheck.json"
    done
}

@test "image preparation commands explicitly create pending marks" {
    touch "$MUTATION_RELEASE"
    for command in pull up; do
        printf '{}\n' > "$TMPDIR/compose_manager_boot_config/pending-recheck.json"
        local options=()
        if [ "$command" = up ]; then options+=(--prepare-images); fi
        # A later preparation failure still leaves the authoritative begin mark.
        run bash "$COMPOSE_SCRIPT" -c "$command" -p legacy___runtime-- -s "$TEST_TEMP_DIR/Display Folder" "${options[@]}"
        grep -F '"Display Folder"' "$TMPDIR/compose_manager_boot_config/pending-recheck.json"
    done
}

#!/usr/bin/env bats
load '../bats_setup.bash'

COMPOSE_SCRIPT="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose.sh"

test_setup() {
    unset -f docker php timeout 2>/dev/null || true
    export CALLS="$TEST_TEMP_DIR/calls"
    export UPDATE_STATE="$TEST_TEMP_DIR/changed" UPDATE_UP="$TEST_TEMP_DIR/up"
    export OLD_ID="sha256:$(printf 'a%.0s' {1..64})" NEW_ID="sha256:$(printf 'b%.0s' {1..64})"
    export COMPOSE_LOCK_DIR="$TEST_TEMP_DIR/locks" COMPOSE_LOCK_TIMEOUT=0
    export COMPOSE_MANAGER_CFG_FILE="$TEST_TEMP_DIR/settings"
    export CHANGE=true BUILD_CHANGE=false STOPPED=false
    export PS_EXIT=0 INSPECT_EXIT=0 IMAGE_INSPECT_EXIT=0 PULL_EXIT=0 BUILD_PULL_EXIT=0 BUILD_EXIT=0 UP_EXIT=0
    : > "$CALLS"
    mkdir -p "$TEST_TEMP_DIR/bin" "$TEST_TEMP_DIR/stack"
    cp "$BATS_TEST_DIRNAME/../fixtures/update-docker.sh" "$TEST_TEMP_DIR/bin/docker"
    cp "$BATS_TEST_DIRNAME/../fixtures/update-notify.sh" "$TEST_TEMP_DIR/bin/notify"
    chmod +x "$TEST_TEMP_DIR/bin/docker" "$TEST_TEMP_DIR/bin/notify"
    export COMPOSE_NOTIFY_SCRIPT="$TEST_TEMP_DIR/bin/notify" NOTIFICATIONS="$TEST_TEMP_DIR/notifications"
    : > "$NOTIFICATIONS"
    export PATH="$TEST_TEMP_DIR/bin:$PATH"
}

run_update() {
    run bash "$COMPOSE_SCRIPT" -c update -p stack -s "$TEST_TEMP_DIR/stack" \
        --update-outcome-file "$TEST_TEMP_DIR/outcome" "$@"
}

run_auto_adapter() {
    run bash "$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose_autoupdate.sh" \
        -cupdate -pstack -s "$TEST_TEMP_DIR/stack" "$@"
}

@test "automatic notification adapter delegates to the same locked update engine" {
    export CHANGE=false BUILD_CHANGE=true
    run_auto_adapter --build -g dev
    assert_success
    grep -F 'compose --profile dev -p stack build --pull' "$CALLS"
    grep -F 'compose --profile dev -p stack up -d --pull never --no-build' "$CALLS"
    grep -F '"result":"success"' "$TEST_TEMP_DIR/stack/last_result.json"
    grep -F 'Stack updated: stack' "$NOTIFICATIONS"
}

@test "automatic adapter propagates build failure instead of reporting success" {
    export BUILD_PULL_EXIT=1 BUILD_EXIT=8
    run_auto_adapter --build
    [ "$status" = 8 ]
    assert_output_contains "Failed to build"
    ! grep -E ' (up|rmi)( |$)' "$CALLS"
    grep -F 'Auto-update failed: stack' "$NOTIFICATIONS"
}

@test "automatic adapter sends no success notification for unchanged or stopped stacks" {
    export CHANGE=false
    run_auto_adapter
    assert_success
    [ ! -s "$NOTIFICATIONS" ]
    export STOPPED=true
    run_auto_adapter
    assert_success
    [ ! -s "$NOTIFICATIONS" ]
}

@test "automatic adapter preserves the legacy file and project invocation" {
    run bash "$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose_autoupdate.sh" \
        "$TEST_TEMP_DIR/a compose.yaml" stack
    assert_success
    grep -F "compose -f $TEST_TEMP_DIR/a compose.yaml -p stack pull --ignore-buildable" "$CALLS"
}

@test "unchanged images are never passed to cleanup" {
    export CHANGE=false
    run_update
    assert_success
    ! grep -F rmi "$CALLS"
}

@test "failure to enumerate containers fails before pulling" {
    export PS_EXIT=1
    run_update --automatic-update
    assert_failure
    ! grep -E ' (pull|build|up|rmi)( |$)' "$CALLS"
}

@test "relative script invocation still loads the shared engine after changing workdir" {
    run bash "${COMPOSE_SCRIPT#"$PWD/"}" -cupdate -pstack -w"$TEST_TEMP_DIR/stack"
    assert_success
    grep -F 'compose -p stack up -d --pull never' "$CALLS"
}

@test "manual updates reconcile even when image IDs are unchanged" {
    export CHANGE=false
    run_update
    assert_success
    grep -F 'up -d --pull never' "$CALLS"
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = updated ]
}

@test "successful manual pull does not rebuild published buildable images without opt-in" {
    run_update
    assert_success
    grep -Fx 'compose -p stack pull' "$CALLS"
    ! grep -F ' build' "$CALLS"
}

@test "cleanup never forces removal and reports images still used by other stacks" {
    export RMI_EXIT=1
    run_update --debug
    assert_success
    grep -F "rmi ${OLD_ID#sha256:}" "$CALLS"
    ! grep -F 'rmi -f' "$CALLS"
    assert_output_contains "Some superseded images"
}

@test "force update forwards force-recreate to the shared up command" {
    run_update --recreate
    assert_success
    grep -F 'up -d --force-recreate --pull never' "$CALLS"
}

@test "automatic update skips unchanged images without recreation" {
    export CHANGE=false
    run_update --automatic-update
    assert_success
    ! grep -F ' up ' "$CALLS"
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = unchanged ]
}

@test "automatic update detects an image pulled before this run" {
    export CHANGE=false
    touch "$UPDATE_STATE"
    run_update --automatic-update
    assert_success
    grep -F 'up -d --pull never --no-build' "$CALLS"
    grep -F "rmi ${OLD_ID#sha256:}" "$CALLS"
}

@test "automatic update never pulls builds or starts a stopped stack" {
    export STOPPED=true
    run_update --automatic-update --build
    assert_success
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = skipped ]
    ! grep -E ' (pull|build|up|rmi)( |$)' "$CALLS"
}

@test "automatic build updates use the same pull build up and cleanup engine" {
    export CHANGE=false BUILD_CHANGE=true
    run_update --automatic-update --build -g dev
    assert_success
    grep -F 'compose --profile dev -p stack pull --ignore-buildable' "$CALLS"
    grep -F 'compose --profile dev -p stack build --pull' "$CALLS"
    grep -F 'compose --profile dev -p stack up -d --pull never --no-build' "$CALLS"
    [ "$(cat "$TEST_TEMP_DIR/stack/running_profiles")" = dev ]
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = updated ]
}

@test "unchanged cached build does not restart automatic stacks" {
    export CHANGE=false
    run_update --automatic-update --build
    assert_success
    grep -F 'build --pull' "$CALLS"
    ! grep -F ' up ' "$CALLS"
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = unchanged ]
}

@test "both update modes retry local-only base images" {
    export BUILD_PULL_EXIT=1
    for mode in manual automatic; do
        local args=(--build)
        [ "$mode" != automatic ] || args+=(--automatic-update)
        run_update "${args[@]}"
        assert_success
        grep -Fx 'compose -p stack build' "$CALLS"
    done
}

@test "automatic updates do not enable build fallback when rebuilding is disabled" {
    export PULL_EXIT=7
    run_update --automatic-update
    [ "$status" = 7 ]
    ! grep -E ' (build|up|rmi)( |$)' "$CALLS"
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = failed ]
}

@test "manual pull fallback preserves support for local build tags" {
    export PULL_EXIT=1 FALLBACK_PULL=true
    run_update
    assert_success
    grep -F 'pull --ignore-buildable' "$CALLS"
    grep -F 'build --pull' "$CALLS"
}

@test "build failure leaves containers and old images intact" {
    export BUILD_PULL_EXIT=1 BUILD_EXIT=8
    run_update --automatic-update --build
    [ "$status" = 8 ]
    ! grep -E ' (up|rmi)( |$)' "$CALLS"
    grep -F '"result":"failed"' "$TEST_TEMP_DIR/stack/last_result.json"
}

@test "up failure never removes superseded images" {
    export UP_EXIT=9
    run_update
    [ "$status" = 9 ]
    ! grep -F rmi "$CALLS"
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = failed ]
}

@test "inspection failure is not reported as unchanged" {
    export IMAGE_INSPECT_EXIT=1
    run_update --automatic-update
    assert_failure
    ! grep -E ' (up|rmi)( |$)' "$CALLS"
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = failed ]
}

@test "update lock prevents a second execution" {
    mkdir -p "$COMPOSE_LOCK_DIR"
    exec 8>"$COMPOSE_LOCK_DIR/stack.lock"
    flock 8
    run_update
    assert_failure
    [ ! -s "$CALLS" ]
    flock -u 8
    exec 8>&-
}

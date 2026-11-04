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
    unset RUNTIME_ROWS CONFIG_SERVICES CONFIG_EXIT CONFIG_PROFILES TARGET_IMAGE TARGET_CONFIG COMPOSE_COMPATIBILITY
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
    grep -F 'compose --profile dev -p stack build --pull -- app' "$CALLS"
    grep -F 'up -d --scale app=1 --pull never --no-deps --no-build -- app' "$CALLS"
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
    grep -F "compose -f $TEST_TEMP_DIR/a compose.yaml -p stack pull --ignore-buildable -- app" "$CALLS"
}

@test "unchanged images are never passed to cleanup" {
    export CHANGE=false
    run_update
    assert_success
    ! grep -F rmi "$CALLS"
}

@test "legacy automatic files retain an explicit Compose project directory" {
    export COMPOSE_PROJECT_DIR="$TEST_TEMP_DIR/stack"
    run bash "$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose_autoupdate.sh" \
        "$TEST_TEMP_DIR/a compose.yaml" stack
    assert_success
    grep -F "compose -f $TEST_TEMP_DIR/a compose.yaml --project-directory $COMPOSE_PROJECT_DIR -p stack pull --ignore-buildable -- app" "$CALLS"
}

@test "legacy automatic file lists retain an explicit Compose project directory" {
    export COMPOSE_PROJECT_DIR="$TEST_TEMP_DIR/stack"
    export COMPOSE_FILE_LIST="$TEST_TEMP_DIR/first.yaml:$TEST_TEMP_DIR/second.yaml"
    run bash "$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose_autoupdate.sh" stack
    assert_success
    grep -F "compose -f $TEST_TEMP_DIR/first.yaml -f $TEST_TEMP_DIR/second.yaml --project-directory $COMPOSE_PROJECT_DIR -p stack pull --ignore-buildable -- app" "$CALLS"
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
    grep -F 'up -d --scale app=1 --pull never --no-deps -- app' "$CALLS"
}

@test "relative workdir is forwarded as the absolute Compose project directory" {
    run bash -c 'cd "$1" && bash "$2" -cupdate -pstack -wstack' \
        _ "$TEST_TEMP_DIR" "$COMPOSE_SCRIPT"
    assert_success
    grep -F "compose --project-directory $TEST_TEMP_DIR/stack -p stack pull -- app" "$CALLS"
    ! grep -F 'project-directory stack ' "$CALLS"
}

@test "legacy automatic relative project directory is resolved only once" {
    export COMPOSE_PROJECT_DIR=stack
    run bash -c 'cd "$1" && bash "$2" "$1/input.yaml" stack' \
        _ "$TEST_TEMP_DIR" "$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose_autoupdate.sh"
    assert_success
    grep -F "compose -f $TEST_TEMP_DIR/input.yaml --project-directory $TEST_TEMP_DIR/stack -p stack pull --ignore-buildable -- app" "$CALLS"
}

@test "manual updates reconcile even when image IDs are unchanged" {
    export CHANGE=false
    run_update
    assert_success
    grep -F 'up -d --scale app=1 --pull never --no-deps -- app' "$CALLS"
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = updated ]
}

@test "manual updates do not inspect historical image tags before reconciling" {
    export IMAGE_INSPECT_EXIT=1
    run_update
    assert_success
    ! grep -F 'image inspect' "$CALLS"
    grep -F ' up ' "$CALLS"
}

@test "automatic updates resolve the configured target instead of the historical tag" {
    export TARGET_IMAGE=registry.example/app:new-tag
    run_update --automatic-update
    assert_success
    grep -Fx "image inspect --format={{.Id}} $TARGET_IMAGE" "$CALLS"
    ! grep -Fx 'image inspect --format={{.Id}} stack-app' "$CALLS"
    grep -F ' up ' "$CALLS"
}

@test "automatic target resolution ignores unselected dependency images" {
    export TARGET_CONFIG='{"name":"stack","services":{"app":{"image":"app:new-tag"},"db":{"image":"db:latest"}}}'
    run_update --automatic-update
    assert_success
    grep -Fx 'image inspect --format={{.Id}} app:new-tag' "$CALLS"
    ! grep -F 'image inspect --format={{.Id}} db:latest' "$CALLS"
}

@test "build only targets use Compose project naming including compatibility mode" {
    export TARGET_CONFIG='{"name":"stack","services":{"app":{"build":{"context":"."}}}}'
    run_update --automatic-update
    assert_success
    grep -Fx 'image inspect --format={{.Id}} stack-app' "$CALLS"
    : > "$CALLS"
    export COMPOSE_COMPATIBILITY=true
    run_update --automatic-update
    assert_success
    grep -Fx 'image inspect --format={{.Id}} stack_app' "$CALLS"
}

@test "malformed target config fails explicitly before recreating" {
    export TARGET_CONFIG=invalid-json
    run_update --automatic-update
    assert_failure
    assert_output_contains 'Failed to resolve target images'
    ! grep -F ' up ' "$CALLS"
}

@test "replicas share one runtime snapshot and one target image inspection" {
    export RUNTIME_ROWS=$'id1|app|running|False\nid2|app|running|False\nid3|web|running|False'
    export CONFIG_SERVICES=$'app\nweb'
    run_update --automatic-update
    assert_success
    [ "$(grep -c '^inspect ' "$CALLS")" = 1 ]
    [ "$(grep -c '^image inspect ' "$CALLS")" = 1 ]
    grep -F -- '--scale app=2 --scale web=1' "$CALLS"
}

@test "successful manual pull does not rebuild published buildable images without opt-in" {
    run_update
    assert_success
    grep -Fx 'compose -p stack pull -- app' "$CALLS"
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
    grep -F 'up -d --force-recreate --scale app=1 --pull never --no-deps -- app' "$CALLS"
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
    grep -F 'up -d --scale app=1 --pull never --no-deps --no-build -- app' "$CALLS"
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
    grep -F 'compose --profile dev -p stack pull --ignore-buildable -- app' "$CALLS"
    grep -F 'compose --profile dev -p stack build --pull -- app' "$CALLS"
    grep -F 'up -d --scale app=1 --pull never --no-deps --no-build -- app' "$CALLS"
    [ ! -f "$TEST_TEMP_DIR/stack/running_profiles" ]
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
        grep -Fx 'compose -p stack build -- app' "$CALLS"
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

@test "automatic updates target deployed profiled services without starting stopped siblings" {
    export RUNTIME_ROWS=$'web-id|web|running|False\nbackup-id|backup|exited|False'
    export CONFIG_SERVICES=$'web\nbackup\nundeployed'
    export CONFIG_PROFILES=deployed
    run_update --automatic-update --build -g wrong-default
    assert_success
    grep -Fx 'compose --profile wrong-default --profile deployed -p stack pull --ignore-buildable -- web' "$CALLS"
    grep -F 'build --pull -- web' "$CALLS"
    grep -F 'up -d --scale web=1 --pull never --no-deps --no-build -- web' "$CALLS"
    ! grep -E ' (pull|build|up).* (backup|undeployed)( |$)' "$CALLS"
}

@test "manual updates leave stopped services alone by default including force mode" {
    export RUNTIME_ROWS=$'web-id|web|running|False\nbackup-id|backup|exited|False'
    export CONFIG_SERVICES=$'web\nbackup'
    run_update --recreate
    assert_success
    grep -F 'up -d --force-recreate --scale web=1 --pull never --no-deps -- web' "$CALLS"
    ! grep -E ' (pull|build|up).* backup( |$)' "$CALLS"
}

@test "manual include stopped explicitly redeploys existing stopped services only" {
    export RUNTIME_ROWS=$'web-id|web|running|False\nbackup-id|backup|exited|False'
    export CONFIG_SERVICES=$'web\nbackup\nundeployed'
    run_update --include-stopped
    assert_success
    grep -F 'pull -- web backup' "$CALLS"
    grep -F 'up -d --scale web=1 --scale backup=1 --pull never --no-deps -- web backup' "$CALLS"
    ! grep -E ' (pull|build|up).* undeployed( |$)' "$CALLS"
}

@test "automatic updates ignore manual include stopped option" {
    export STOPPED=true
    run_update --automatic-update --include-stopped
    assert_success
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = skipped ]
    ! grep -E ' (pull|build|up)( |$)' "$CALLS"
}

@test "fully stopped manual stack requires explicit inclusion" {
    export STOPPED=true
    run_update
    assert_success
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = skipped ]
    ! grep -E ' (pull|build|up)( |$)' "$CALLS"
    run_update --include-stopped
    assert_success
    grep -F 'up -d --scale app=1 --pull never --no-deps -- app' "$CALLS"
}

@test "mixed replicas are skipped and reported while other services update" {
    export RUNTIME_ROWS=$'web-1|web|running|False\nweb-2|web|exited|False\napp-1|app|running|False'
    export CONFIG_SERVICES=$'web\napp'
    run_update --automatic-update
    assert_success
    assert_output_contains "Leaving service 'web' unchanged"
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = partial ]
    grep -F 'up -d --scale app=1 --pull never --no-deps --no-build -- app' "$CALLS"
    ! grep -E ' (pull|build|up).* web( |$)' "$CALLS"
}

@test "explicit inclusion permits mixed stopped replicas and preserves replica count" {
    export RUNTIME_ROWS=$'web-1|web|running|False\nweb-2|web|exited|False'
    export CONFIG_SERVICES=web
    run_update --include-stopped
    assert_success
    grep -F 'up -d --scale web=2 --pull never --no-deps -- web' "$CALLS"
}

@test "running replicas retain their deployed count instead of configured scale" {
    export RUNTIME_ROWS=$'web-1|web|running|False\nweb-2|web|running|False\njob|web|running|True'
    export CONFIG_SERVICES=web
    run_update --automatic-update
    assert_success
    grep -F 'up -d --scale web=2 --pull never --no-deps --no-build -- web' "$CALLS"
}

@test "paused restarting and dead services are skipped even with inclusion enabled" {
    for state in paused restarting dead removing; do
        export RUNTIME_ROWS="web-1|web|$state|False"
        export CONFIG_SERVICES=web
        run_update --include-stopped --recreate
        assert_success
        assert_output_contains "container state $state requires attention"
        [ "$(cat "$TEST_TEMP_DIR/outcome")" = partial ]
        ! grep -E ' (pull|build|up)( |$)' "$CALLS"
    done
}

@test "one off containers and removed services never become update targets" {
    export RUNTIME_ROWS=$'job|backup|running|True\nold|removed|running|False'
    export CONFIG_SERVICES=backup
    run_update --include-stopped
    assert_success
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = partial ]
    ! grep -E ' (pull|build|up)( |$)' "$CALLS"
}

@test "service resolution or metadata failures prevent mutations" {
    export CONFIG_EXIT=1
    run_update
    assert_failure
    ! grep -E ' (pull|build|up)( |$)' "$CALLS"
    export CONFIG_EXIT=0 RUNTIME_ROWS='bad|<no value>|running|False'
    run_update
    assert_failure
    ! grep -E ' (pull|build|up)( |$)' "$CALLS"
}

@test "valid punctuation prefixed services remain explicit targets in both update modes" {
    export RUNTIME_ROWS=$'a|_worker|running|False\nb|.worker|running|False\nc|-worker|running|False\nd|app|running|False'
    export CONFIG_SERVICES=$'_worker\n.worker\n-worker\napp'
    for mode in manual automatic; do
        : > "$CALLS"
        rm -f "$UPDATE_STATE" "$UPDATE_UP"
        if [ "$mode" = automatic ]; then
            run_update --automatic-update --build
        else
            export PULL_EXIT=1 FALLBACK_PULL=true
            run_update
            export PULL_EXIT=0
        fi
        assert_success
        grep -F 'pull --ignore-buildable -- _worker .worker -worker app' "$CALLS"
        grep -F 'build --pull -- _worker .worker -worker app' "$CALLS"
        grep -F 'up -d --scale _worker=1 --scale .worker=1 --scale -worker=1 --scale app=1 --pull never --no-deps --no-build -- _worker .worker -worker app' "$CALLS"
        grep -F 'images -q -- _worker .worker -worker app' "$CALLS"
        if [ "$mode" = automatic ]; then
            grep -F 'config --format json -- _worker .worker -worker app' "$CALLS"
        fi
    done
}

@test "partial automatic update produces an incomplete notification not success" {
    export RUNTIME_ROWS=$'app|app|running|False\nweb|web|paused|False'
    export CONFIG_SERVICES=$'app\nweb'
    run_auto_adapter
    assert_success
    grep -F 'Stack update incomplete: stack' "$NOTIFICATIONS"
    ! grep -F 'Stack updated: stack' "$NOTIFICATIONS"
}

@test "update targeting does not overwrite deployment profile history" {
    printf '%s\n' deployed-profile > "$TEST_TEMP_DIR/stack/running_profiles"
    run_update -g different-profile
    assert_success
    [ "$(cat "$TEST_TEMP_DIR/stack/running_profiles")" = deployed-profile ]
}

@test "automatic unchanged eligible services still report exceptional skips" {
    export CHANGE=false RUNTIME_ROWS=$'app|app|running|False\nweb|web|restarting|False'
    export CONFIG_SERVICES=$'app\nweb'
    run_update --automatic-update
    assert_success
    [ "$(cat "$TEST_TEMP_DIR/outcome")" = partial ]
    ! grep -F ' up ' "$CALLS"
}

@test "configured profiles are resolved explicitly without wildcard mutation commands" {
    export CONFIG_PROFILES=$'production\nmaintenance'
    run_update
    assert_success
    grep -Fx 'compose --profile production --profile maintenance -p stack pull -- app' "$CALLS"
    ! grep -E 'compose .*--profile \*.* (pull|build|up)( |$)' "$CALLS"
}

@test "scoped updates reject orphan removal instead of deleting excluded containers" {
    run_update --remove-orphans
    assert_failure
    assert_output_contains "Scoped updates cannot remove unrelated orphan containers"
    ! grep -E ' (pull|build|up)( |$)' "$CALLS"
}

@test "manual update modal preserves defaults and transmits per-operation scope" {
    command -v node >/dev/null || skip "Node.js is required for update modal regression tests"
    run node "$BATS_TEST_DIRNAME/../fixtures/update-ui.cjs"
    assert_success
}

@test "background manual updates report safety skips instead of full success" {
    export RUNTIME_ROWS=$'app|app|running|False\nweb|web|paused|False'
    export CONFIG_SERVICES=$'app\nweb'
    run bash "$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose_background.sh" \
        -cupdate -pstack -s "$TEST_TEMP_DIR/stack"
    assert_success
    grep -F 'Stack update incomplete: stack' "$NOTIFICATIONS"
    ! grep -F 'completed successfully' "$NOTIFICATIONS"
}

@test "background manual updates report fully stopped stacks as skipped" {
    export STOPPED=true
    run bash "$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose_background.sh" \
        -cupdate -pstack -s "$TEST_TEMP_DIR/stack"
    assert_success
    grep -F 'Stack update skipped: stack' "$NOTIFICATIONS"
    ! grep -E ' (pull|build|up)( |$)' "$CALLS"
}

@test "background manual inclusion starts selected stopped services" {
    export STOPPED=true
    run bash "$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose_background.sh" \
        -cupdate -pstack -s "$TEST_TEMP_DIR/stack" --include-stopped
    assert_success
    grep -F 'up -d --scale app=1 --pull never --no-deps -- app' "$CALLS"
    grep -F 'Stack update complete: stack' "$NOTIFICATIONS"
}

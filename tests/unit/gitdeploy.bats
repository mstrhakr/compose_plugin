#!/usr/bin/env bats
#
# compose.sh gitdeploy: the order of steps, and that nothing touches a
# container when an earlier step fails. git_stack.php (tested in PHPUnit) and
# docker are replaced by scripts on PATH that record every call.
#

load '../bats_setup.bash'

COMPOSE_SCRIPT="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose.sh"

test_setup() {
    # The framework exports mock docker/php functions; these tests use scripts on PATH instead.
    unset -f docker php 2>/dev/null || true
    export COMPOSE_LOCK_TIMEOUT=5
    # /var/run is not writable when the tests run without root (as in CI).
    export COMPOSE_LOCK_DIR="$TEST_TEMP_DIR/locks"
    REAL_PHP="$(command -v php)"
    export REAL_PHP
    export CALLS="$TEST_TEMP_DIR/calls.log"
    export IMAGES_CALLS="$TEST_TEMP_DIR/images-calls"
    : > "$CALLS"
    echo 0 > "$IMAGES_CALLS"
    export PREPARE_EXIT=0 PULL_EXIT=0 BUILD_EXIT=0 UP_EXIT=0 UP_ARGUMENTS_EXIT=0 UP_ARGUMENTS=""
    export COMPOSE_ARGS_EXIT=0 RESTORE_EXIT=0 COMPOSE_FOLDER_EXIT=0
    export COMPOSE_FOLDER="$TEST_TEMP_DIR/clone/whoami"
    mkdir -p "$COMPOSE_FOLDER"
    export PREPARE_OUTPUT="0000000000000000000000000000000000000001"
    # The plugin's settings: none, so Create Missing External Networks is off unless a test turns it on.
    export COMPOSE_MANAGER_CFG_FILE="$TEST_TEMP_DIR/compose.manager.cfg"
    # What "docker compose config --format json" prints, whether "docker network inspect" finds the network,
    # and whether "docker network create" works.
    export CONFIG_JSON='{}' NETWORK_INSPECT_EXIT=0 NETWORK_CREATE_EXIT=0

    STACK="$TEST_TEMP_DIR/whoami"
    mkdir -p "$STACK" "$TEST_TEMP_DIR/bin"
    echo '{}' > "$STACK/git.json"

    # php: answer for git_stack.php, pass everything else to the real php.
    cat > "$TEST_TEMP_DIR/bin/php" <<'SHIM'
#!/bin/bash
if [ "$1" = "-d" ]; then
  shift 2
fi
case "$1" in
  */credential_config.php)
    # The registry credential's DOCKER_CONFIG folder and its label.
    echo "$CREDENTIAL_DOCKER_CONFIG"
    echo "registry login"
    exit 0
    ;;
  */git_stack.php)
    shift
    echo "git_stack $*" >> "$CALLS"
    case "$1" in
      prepare)
        [ "$PREPARE_EXIT" = 0 ] || { echo "✗ check failed" >&2; exit "$PREPARE_EXIT"; }
        echo "$PREPARE_OUTPUT"
        ;;
      compose-args)
        [ "$COMPOSE_ARGS_EXIT" = 0 ] || { echo "✗ no compose file" >&2; exit "$COMPOSE_ARGS_EXIT"; }
        printf -- '-f\0/clone/whoami/compose.yaml\0--env-file\0/stack/.env\0'
        ;;
      compose-folder)
        [ "$COMPOSE_FOLDER_EXIT" = 0 ] || { echo "✗ no compose file" >&2; exit "$COMPOSE_FOLDER_EXIT"; }
        echo "$COMPOSE_FOLDER"
        ;;
      restore)
        exit "$RESTORE_EXIT"
        ;;
      finish)
        exit "${FINISH_EXIT:-0}"
        ;;
      up-arguments)
        [ "$UP_ARGUMENTS_EXIT" = 0 ] || { echo "✗ could not compare commits" >&2; exit "$UP_ARGUMENTS_EXIT"; }
        printf '%s' "$UP_ARGUMENTS"
        ;;
    esac
    exit 0
    ;;
esac
exec "$REAL_PHP" "$@"
SHIM

    # docker: record the call; images -q answers "before" then "after".
    cat > "$TEST_TEMP_DIR/bin/docker" <<'SHIM'
#!/bin/bash
# compose.sh runs docker compose with only the checks' environment, so the
# settings above reach this script through a file (see run_gitdeploy).
here="$(dirname "$0")"
for arg in "$@"; do
  case "$arg" in
    up|rmi|create)
      env > "$here/$arg-environment"
      pwd > "$here/$arg-folder"
      ;;
  esac
done
. "$here/test-settings.sh"
echo "docker $*" >> "$CALLS"
for arg in "$@"; do
  case "$arg" in
    images)
      n=$(cat "$IMAGES_CALLS"); echo $((n + 1)) > "$IMAGES_CALLS"
      if [ "$n" = 0 ]; then printf 'img-old\nimg-kept\n'; else printf 'img-kept\nimg-new\n'; fi
      exit 0 ;;
    pull) exit "$PULL_EXIT" ;;
    build) exit "$BUILD_EXIT" ;;
    up) exit "$UP_EXIT" ;;
    config) printf '%s' "$CONFIG_JSON"; exit 0 ;;
    inspect) exit "$NETWORK_INSPECT_EXIT" ;;
    create) exit "$NETWORK_CREATE_EXIT" ;;
  esac
done
exit 0
SHIM
    chmod +x "$TEST_TEMP_DIR/bin/php" "$TEST_TEMP_DIR/bin/docker"
    export PATH="$TEST_TEMP_DIR/bin:$PATH"
}

run_gitdeploy() {
    declare -p CALLS IMAGES_CALLS PULL_EXIT BUILD_EXIT UP_EXIT CONFIG_JSON NETWORK_INSPECT_EXIT NETWORK_CREATE_EXIT \
        > "$TEST_TEMP_DIR/bin/test-settings.sh"
    run bash "$COMPOSE_SCRIPT" -c gitdeploy -p whoami -s "$STACK" "$@"
}

calls_matching() {
    grep -c -- "$1" "$CALLS" || true
}

@test "gitdeploy refuses a stack that is not a git stack" {
    rm "$STACK/git.json"
    run_gitdeploy
    [ "$status" -eq 1 ]
    [[ "$output" == *"not a git stack"* ]]
    [ "$(calls_matching 'docker')" -eq 0 ]
}

@test "gitdeploy waits for the stack's lock and changes nothing when it stays taken" {
    # Another operation on the stack (a Start from the web UI, say) holds its lock.
    mkdir -p "$COMPOSE_LOCK_DIR"
    exec 8>"$COMPOSE_LOCK_DIR/whoami.lock"
    flock 8
    export COMPOSE_LOCK_TIMEOUT=1
    run_gitdeploy
    exec 8>&-
    [ "$status" -eq 1 ]
    [[ "$output" == *"Another operation is already in progress"* ]]
    [ "$(calls_matching 'git_stack')" -eq 0 ]
    [ "$(calls_matching 'docker')" -eq 0 ]
}

@test "gitdeploy stops before any container when the git checks fail" {
    export PREPARE_EXIT=1
    run_gitdeploy
    [ "$status" -eq 1 ]
    [[ "$output" == *"No container was changed"* ]]
    [ "$(calls_matching 'docker')" -eq 0 ]
    grep -q '"result":"failed"' "$STACK/last_result.json"
}

@test "gitdeploy puts the previous commit back when the pull fails, and never runs up" {
    export PULL_EXIT=1
    run_gitdeploy
    [ "$status" -eq 1 ]
    grep -q "git_stack restore $STACK 0000000000000000000000000000000000000001" "$CALLS"
    [ "$(calls_matching ' up ')" -eq 0 ]
    [ "$(calls_matching 'finish')" -eq 0 ]
}

@test "gitdeploy puts the previous commit back when the build fails, and never runs up" {
    export BUILD_EXIT=1
    run_gitdeploy
    [ "$status" -eq 1 ]
    [[ "$output" == *"Building images for whoami failed"* ]]
    grep -q "git_stack restore $STACK 0000000000000000000000000000000000000001" "$CALLS"
    [ "$(calls_matching ' up ')" -eq 0 ]
    [ "$(calls_matching 'finish')" -eq 0 ]
}

@test "gitdeploy says so when the previous commit cannot be put back" {
    export PULL_EXIT=1 RESTORE_EXIT=1
    run_gitdeploy
    [ "$status" -eq 1 ]
    [[ "$output" == *"could not be put back"* ]]
    [[ "$output" != *"was put back"* ]]
    [ "$(calls_matching ' up ')" -eq 0 ]
}

@test "gitdeploy stops when the git checks print something other than a commit id" {
    export PREPARE_OUTPUT="PHP Notice: something"
    run_gitdeploy
    [ "$status" -eq 1 ]
    [ "$(calls_matching 'docker')" -eq 0 ]
    [ "$(calls_matching 'restore')" -eq 0 ]
}

@test "gitdeploy stops before pulling when the compose files cannot be read" {
    export COMPOSE_ARGS_EXIT=1
    run_gitdeploy
    [ "$status" -eq 1 ]
    [ "$(calls_matching 'docker')" -eq 0 ]
    grep -q "git_stack restore $STACK 0000000000000000000000000000000000000001" "$CALLS"
}

@test "gitdeploy stops before pulling when the compose file's folder cannot be found" {
    export COMPOSE_FOLDER_EXIT=1
    run_gitdeploy
    [ "$status" -eq 1 ]
    [ "$(calls_matching 'docker')" -eq 0 ]
    grep -q "git_stack restore $STACK 0000000000000000000000000000000000000001" "$CALLS"
}

@test "gitdeploy runs docker in the compose file's folder with only the checks' environment" {
    # As exported in the shell, web server or cron job that started the deploy.
    export PORT=8080 DOCKER_HOST=tcp://elsewhere:2375 COMPOSE_PROFILES=debug TERM=xterm
    # A missing network to create, so docker network create runs too; img-old is removed after up.
    echo 'CREATE_MISSING_EXTERNAL_NETWORKS="true"' > "$COMPOSE_MANAGER_CFG_FILE"
    export CONFIG_JSON='{"networks":{"proxy":{"name":"zz-proxy","external":true}}}' NETWORK_INSPECT_EXIT=1
    run_gitdeploy
    [ "$status" -eq 0 ]
    grep -q "docker network create zz-proxy$" "$CALLS"
    grep -q "docker rmi img-old$" "$CALLS"

    for step in up create rmi; do
        [ "$(cat "$TEST_TEMP_DIR/bin/$step-folder")" = "$COMPOSE_FOLDER" ]
        grep -qx "PWD=$COMPOSE_FOLDER" "$TEST_TEMP_DIR/bin/$step-environment"
        grep -qx "LC_ALL=C" "$TEST_TEMP_DIR/bin/$step-environment"
        grep -qx "TERM=xterm" "$TEST_TEMP_DIR/bin/$step-environment"
        run grep -E '^(PORT|DOCKER_HOST|COMPOSE_PROFILES)=' "$TEST_TEMP_DIR/bin/$step-environment"
        [ "$status" -eq 1 ]
    done
}

@test "gitdeploy passes the registry credential's DOCKER_CONFIG on to docker" {
    export CREDENTIAL_DOCKER_CONFIG="$TEST_TEMP_DIR/docker-config"
    run_gitdeploy --credential-id registry-1
    [ "$status" -eq 0 ]
    grep -qx "DOCKER_CONFIG=$CREDENTIAL_DOCKER_CONFIG" "$TEST_TEMP_DIR/bin/up-environment"
}

@test "gitdeploy records a failed up and does not roll back or remove images" {
    export UP_EXIT=3
    run_gitdeploy
    [ "$status" -eq 3 ]
    grep -q "git_stack finish $STACK failed" "$CALLS"
    [ "$(calls_matching 'restore')" -eq 0 ]
    [ "$(calls_matching 'rmi')" -eq 0 ]
    [[ "$output" == *"The commit is recorded as failed"* ]]
}

@test "gitdeploy says so when a successful deploy cannot be recorded, and still succeeds" {
    export FINISH_EXIT=1
    run_gitdeploy
    [ "$status" -eq 0 ]
    [[ "$output" == *"the deployed commit could not be recorded"* ]]
    [[ "$output" == *"deployed successfully"* ]]
}

@test "gitdeploy still reports a failed up when the failure cannot be recorded" {
    export UP_EXIT=3 FINISH_EXIT=1
    run_gitdeploy
    [ "$status" -eq 3 ]
    [[ "$output" == *"failed to start"* ]]
    [ "$(calls_matching 'restore')" -eq 0 ]
}

@test "gitdeploy runs the steps in order and removes only superseded images" {
    run_gitdeploy
    [ "$status" -eq 0 ]
    [[ "$output" == *"deployed successfully"* ]]

    prepare_line=$(grep -n 'git_stack prepare' "$CALLS" | cut -d: -f1)
    pull_line=$(grep -n ' pull --ignore-buildable' "$CALLS" | cut -d: -f1)
    build_line=$(grep -n ' build$' "$CALLS" | cut -d: -f1)
    up_line=$(grep -n ' up ' "$CALLS" | cut -d: -f1)
    finish_line=$(grep -n 'git_stack finish' "$CALLS" | cut -d: -f1)
    [ "$prepare_line" -lt "$pull_line" ]
    [ "$pull_line" -lt "$build_line" ]
    [ "$build_line" -lt "$up_line" ]
    [ "$up_line" -lt "$finish_line" ]

    grep -q "docker compose -f /clone/whoami/compose.yaml --env-file /stack/.env -p whoami up -d --remove-orphans --no-build --pull missing$" "$CALLS"
    grep -q "git_stack finish $STACK success" "$CALLS"
    grep -q "docker rmi img-old$" "$CALLS"
    grep -q '"operation":"gitdeploy"' "$STACK/last_result.json"
}

@test "gitdeploy passes the commit, save-local-changes and profiles to the git checks" {
    run_gitdeploy --git-commit 0123456789abcdef0123456789abcdef01234567 --save-local-changes -g media
    [ "$status" -eq 0 ]
    grep -q "git_stack prepare $STACK whoami --commit 0123456789abcdef0123456789abcdef01234567 --save-local-changes --profile media" "$CALLS"
    grep -q -- "--profile media -p whoami up" "$CALLS"
}

@test "gitdeploy waits for healthy containers when asked" {
    run_gitdeploy --wait --wait-timeout 90
    [ "$status" -eq 0 ]
    grep -q -- "up -d --remove-orphans --no-build --pull missing --wait --wait-timeout 90" "$CALLS"
}

@test "gitdeploy recreates every container when the stack's folder changed" {
    export UP_ARGUMENTS=$'--force-recreate\n'
    run_gitdeploy --wait
    [ "$status" -eq 0 ]
    grep -q "git_stack up-arguments $STACK 0000000000000000000000000000000000000001" "$CALLS"
    grep -q -- "up -d --remove-orphans --no-build --pull missing --wait --force-recreate$" "$CALLS"
}

@test "gitdeploy puts the previous commit back when it cannot tell whether to recreate, and never runs up" {
    export UP_ARGUMENTS_EXIT=1
    run_gitdeploy
    [ "$status" -eq 1 ]
    grep -q "git_stack restore $STACK 0000000000000000000000000000000000000001" "$CALLS"
    [ "$(calls_matching ' up ')" -eq 0 ]
    [ "$(calls_matching 'finish')" -eq 0 ]
}

@test "gitdeploy never passes unexpected up-arguments output to up" {
    export UP_ARGUMENTS=$'PHP Notice: something\n--force-recreate\n'
    run_gitdeploy
    [ "$status" -eq 1 ]
    grep -q "git_stack restore $STACK 0000000000000000000000000000000000000001" "$CALLS"
    [ "$(calls_matching ' up ')" -eq 0 ]
}

@test "gitdeploy tells up never to build and to pull only missing images" {
    # A service's pull_policy (build or always) would otherwise build or pull again during up,
    # after the point where the previous commit can be put back.
    run_gitdeploy --build
    [ "$status" -eq 0 ]
    [ "$(grep -c -- ' up .*--build' "$CALLS" || true)" -eq 0 ]
    grep -q -- ' up .*--no-build --pull missing' "$CALLS"
}

@test "gitdeploy creates a missing external network after the build and before up when the setting is on" {
    echo 'CREATE_MISSING_EXTERNAL_NETWORKS="true"' > "$COMPOSE_MANAGER_CFG_FILE"
    export CONFIG_JSON='{"networks":{"proxy":{"name":"zz-proxy","external":true}}}' NETWORK_INSPECT_EXIT=1
    run_gitdeploy
    [ "$status" -eq 0 ]
    [[ "$output" == *"Created missing external network: zz-proxy"* ]]

    build_line=$(grep -n ' build$' "$CALLS" | cut -d: -f1)
    create_line=$(grep -n 'docker network create zz-proxy$' "$CALLS" | cut -d: -f1)
    up_line=$(grep -n ' up ' "$CALLS" | cut -d: -f1)
    [ "$build_line" -lt "$create_line" ]
    [ "$create_line" -lt "$up_line" ]
}

@test "gitdeploy puts the previous commit back when a missing network cannot be created, and never runs up" {
    echo 'CREATE_MISSING_EXTERNAL_NETWORKS="true"' > "$COMPOSE_MANAGER_CFG_FILE"
    export CONFIG_JSON='{"networks":{"proxy":{"name":"zz-proxy","external":true}}}' NETWORK_INSPECT_EXIT=1
    export NETWORK_CREATE_EXIT=1
    run_gitdeploy
    [ "$status" -eq 1 ]
    [[ "$output" == *"a missing external network could not be created"* ]]
    grep -q "git_stack restore $STACK 0000000000000000000000000000000000000001" "$CALLS"
    [ "$(calls_matching ' up ')" -eq 0 ]
    [ "$(calls_matching 'finish')" -eq 0 ]
}

@test "gitdeploy creates no network when the setting is off" {
    export CONFIG_JSON='{"networks":{"proxy":{"name":"zz-proxy","external":true}}}' NETWORK_INSPECT_EXIT=1
    run_gitdeploy
    [ "$status" -eq 0 ]
    [ "$(calls_matching 'network create')" -eq 0 ]
}

@test "gitdeploy creates no network when it stops before up" {
    echo 'CREATE_MISSING_EXTERNAL_NETWORKS="true"' > "$COMPOSE_MANAGER_CFG_FILE"
    export CONFIG_JSON='{"networks":{"proxy":{"name":"zz-proxy","external":true}}}' NETWORK_INSPECT_EXIT=1
    export BUILD_EXIT=1
    run_gitdeploy
    [ "$status" -eq 1 ]
    [ "$(calls_matching 'network create')" -eq 0 ]
}

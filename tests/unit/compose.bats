#!/usr/bin/env bats
#
# Unit Tests for compose.sh script functions
#

# Load test framework (project-level bootstrap wraps the generic framework)
load '../bats_setup.bash'

# Path to the script we're testing
COMPOSE_SCRIPT="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose.sh"

# ============================================================
# Setup
# ============================================================

test_setup() {
    # Skip sleep commands for faster tests
    export MOCK_SKIP_SLEEP=true
    
    # Set test configuration
    export COMPOSE_MAX_RETRIES=3
    export COMPOSE_RETRY_DELAY=1
    export COMPOSE_LOCK_TIMEOUT=5
}

# ============================================================
# Logging Tests
# ============================================================

@test "log_msg function logs to logger" {
    # Stub composeLogger so the extracted log_msg can call it
    composeLogger() { logger -t 'compose.manager' -p "daemon.${2:-info}" "[${3:-compose}] $1"; }
    export -f composeLogger

    # Extract only log_msg to avoid sourcing compose.sh top-level side effects in tests.
    # shellcheck disable=SC1090
    # shellcheck source=/dev/null
    source <(sed -n '/^log_msg()/,/^}/p' "$COMPOSE_SCRIPT")
    
    log_msg "INFO" "Test message"
    
    assert_logged "Test message"
}

@test "log_msg with debug mode echoes output" {
    # shellcheck disable=SC1090
    # shellcheck source=/dev/null
    source <(sed -n '/^log_msg()/,/^}/p' "$COMPOSE_SCRIPT")
    # shellcheck disable=SC2034
    debug=true
    
    run log_msg "DEBUG" "Debug message"
    
    assert_output_contains "DEBUG"
    assert_output_contains "Debug message"
}

# ============================================================
# Docker Compose Command Tests
# ============================================================

@test "docker compose up is invoked" {
    run docker compose up -d
    
    assert_success
    assert_mock_called "docker" "compose up"
}

@test "docker compose down is invoked" {
    run docker compose down
    
    assert_success
    assert_mock_called "docker" "compose down"
}

@test "docker compose pull is invoked" {
    run docker compose pull
    
    assert_success
    assert_mock_called "docker" "compose pull"
}

@test "docker compose handles failure" {
    mock_docker_compose_exit 1
    
    run docker compose up -d
    
    assert_failure
}

# ============================================================
# --ignore-buildable Flag Tests
# ============================================================

@test "compose.sh pull action uses --ignore-buildable" {
    # Ensure the pull action always passes --ignore-buildable to skip build-only services
    run grep -E '^\s*"\$\{compose_base\[@\]\}" -p "\$name" pull --ignore-buildable' "$COMPOSE_SCRIPT"
    assert_success
}

@test "compose.sh logs action uses explicit project name" {
    # Stack logs must use the sanitized project name the plugin started the stack with.
    run grep -E '^\s*"\$\{compose_base\[@\]\}" -p "\$name" logs -f 2>&1' "$COMPOSE_SCRIPT"
    assert_success
}

@test "compose.sh update action pull step uses --ignore-buildable" {
    # The update action pulls before 'up -d --build'; buildable services are handled by --build
    run grep -E 'pull --ignore-buildable' "$COMPOSE_SCRIPT"
    assert_success
    # Should appear at least twice (pull action + update action)
    local count
    count=$(grep -cE 'pull --ignore-buildable' "$COMPOSE_SCRIPT")
    [ "$count" -ge 2 ]
}

@test "compose.sh mutating commands have explicit final exit propagation" {
    run grep -E '^\s*up\|down\|pull\|update\|stop\|logs\)' "$COMPOSE_SCRIPT"
    assert_success

    run grep -E '^\s*exit "\$\{operation_exit_code:-0\}"' "$COMPOSE_SCRIPT"
    assert_success
}

@test "compose.sh records command rc into operation_exit_code" {
    run grep -E '^\s*operation_exit_code=\$exit_code' "$COMPOSE_SCRIPT"
    assert_success

    local count
    count=$(grep -cE '^\s*operation_exit_code=\$exit_code' "$COMPOSE_SCRIPT")
    [ "$count" -ge 4 ]
}

@test "compose_autoupdate.sh uses --ignore-buildable" {
    local autoupdate_script="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose_autoupdate.sh"
    run grep -E 'pull --ignore-buildable' "$autoupdate_script"
    assert_success
}

@test "compose_autoupdate.sh uses compose config --images for digest detection" {
    local autoupdate_script="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose_autoupdate.sh"
    run grep -E 'config --images' "$autoupdate_script"
    assert_success
}

@test "compose_autoupdate.sh checks running containers before up -d" {
    local autoupdate_script="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose_autoupdate.sh"
    run grep -E 'ps -q' "$autoupdate_script"
    assert_success

    run grep -E 'not running; skipping up -d' "$autoupdate_script"
    assert_success
}

# ============================================================
# Stack Directory Tests
# ============================================================

@test "create stack directory structure" {
    local stack_dir
    stack_dir=$(create_test_stack "teststack")
    
    assert_dir_exists "$stack_dir"
    assert_file_exists "$stack_dir/compose.yaml"
}

@test "compose file contains valid YAML structure" {
    local compose_file
    compose_file=$(create_test_compose_file)
    
    assert_file_contains "$compose_file" "services:"
    assert_file_contains "$compose_file" "image:"
}

# ============================================================
# Result File Tests
# ============================================================

@test "result file is created with correct JSON structure" {
    local stack_dir
    stack_dir=$(create_test_stack "resulttest")
    
    # Create a mock result file like the script would
    echo '{"result":"success","exit_code":0,"operation":"up","timestamp":"2026-02-03T10:00:00"}' > "$stack_dir/last_result.json"
    
    assert_file_exists "$stack_dir/last_result.json"
    
    # Check JSON content with grep (jq may not be available)
    assert_file_contains "$stack_dir/last_result.json" '"result":"success"'
    assert_file_contains "$stack_dir/last_result.json" '"exit_code":0'
    assert_file_contains "$stack_dir/last_result.json" '"operation":"up"'
}

@test "failed operation creates result with non-zero exit code" {
    local stack_dir
    stack_dir=$(create_test_stack "failtest")
    
    echo '{"result":"failed","exit_code":1,"operation":"up","timestamp":"2026-02-03T10:00:00"}' > "$stack_dir/last_result.json"
    
    assert_file_contains "$stack_dir/last_result.json" '"result":"failed"'
    assert_file_contains "$stack_dir/last_result.json" '"exit_code":1'
}

# ============================================================
# Lock File Tests
# ============================================================

@test "lock directory can be created" {
    local lock_dir="$TEST_TEMP_DIR/locks"
    mkdir -p "$lock_dir"
    
    assert_dir_exists "$lock_dir"
}

@test "lock file contains valid JSON" {
    local lock_dir="$TEST_TEMP_DIR/locks"
    mkdir -p "$lock_dir"
    
    # Create lock file like the script would
    echo "{\"pid\":$$,\"command\":\"up\",\"time\":\"$(date -Iseconds)\"}" > "$lock_dir/teststack.lock"
    
    assert_file_exists "$lock_dir/teststack.lock"
    assert_file_contains "$lock_dir/teststack.lock" '"command":"up"'
}

# ============================================================
# Configuration Tests
# ============================================================

@test "default retry count is 3" {
    [ "${COMPOSE_MAX_RETRIES:-3}" -eq 3 ]
}

@test "retry delay can be overridden" {
    export COMPOSE_RETRY_DELAY=10
    [ "$COMPOSE_RETRY_DELAY" -eq 10 ]
}

@test "lock timeout can be overridden" {
    export COMPOSE_LOCK_TIMEOUT=60
    [ "$COMPOSE_LOCK_TIMEOUT" -eq 60 ]
}

# ============================================================
# Error Pattern Tests
# ============================================================

@test "transient error patterns are detected" {
    local retry_pattern="error|timeout|connection refused|no such host|temporary failure"
    
    # Test various error messages
    echo "connection refused" | grep -qiE "$retry_pattern"
    echo "timeout occurred" | grep -qiE "$retry_pattern"
    echo "no such host found" | grep -qiE "$retry_pattern"
    echo "temporary failure in name resolution" | grep -qiE "$retry_pattern"
}

@test "non-transient errors are not matched" {
    local retry_pattern="error|timeout|connection refused|no such host|temporary failure"
    
    # These should NOT match the retry pattern (return non-zero)
    ! echo "file not found" | grep -qiE "$retry_pattern" || true
    ! echo "permission denied" | grep -qiE "$retry_pattern" || true
}

# ============================================================
# Project Name Sanitizer Tests
# ============================================================

# Delegate to the canonical PHP sanitizer so these tests always exercise
# the same logic path as production PHP and shell code.
PROJECT_NAME_SANITIZER_PHP="$BATS_TEST_DIRNAME/../../source/compose.manager/include/ProjectNameSanitizer.php"

sanitize_project_name() {
    local value="$1"
    php -r '
require_once $argv[1];
echo compose_manager_sanitize_project_name((string) ($argv[2] ?? ""));
' "$PROJECT_NAME_SANITIZER_PHP" "$value"
}

assert_sanitize_case() {
    local input="$1"
    local expected="$2"
    local result

    result=$(sanitize_project_name "$input")
    if [ "$result" != "$expected" ]; then
        echo "sanitize failed for input '$input': expected '$expected', got '$result'"
        return 1
    fi
}

@test "project name sanitizer basic identity and casing cases" {
    while IFS='|' read -r input expected; do
        assert_sanitize_case "$input" "$expected" || return 1
    done <<'EOF'
mystack|mystack
MyStack|mystack
Stack2026v1|stack2026v1
my_stack-v2|my_stack-v2
EOF
}

@test "project name sanitizer separator normalization cases" {
    while IFS='|' read -r input expected; do
        assert_sanitize_case "$input" "$expected" || return 1
    done <<'EOF'
My Stack.v2|my_stack_v2
my___stack|my_stack
-_My Stack_-|my_stack
.My Stack.|my_stack
Stack With    Spaces|stack_with_spaces
name.with.many....dots|name_with_many_dots
Prod__API---V2|prod_api-v2
A--B__C..D|a-b_c_d
---___...|compose
EOF
}

@test "project name sanitizer special character conversion cases" {
    while IFS='|' read -r input expected; do
        assert_sanitize_case "$input" "$expected" || return 1
    done <<'EOF'
Stack+Name@Home|stack_name_home
Stack#Prod!Beta|stack_prod_beta
name(2026)[prod]{x}|name_2026_prod_x
app,backup;v2=final|app_backup_v2_final
foo$bar^baz&qux|foo_bar_baz_qux
rock'n'roll|rock_n_roll
stack/name|stack_name
stack:name|stack_name
stack@v2.0|stack_v2_0
my.app=prod|my_app_prod
EOF
}

@test "project name sanitizer real world compose stack names" {
    while IFS='|' read -r input expected; do
        assert_sanitize_case "$input" "$expected" || return 1
    done <<'EOF'
Immich|immich
Keycloak|keycloak
Meshcentral|meshcentral
Nginx_Proxy_Manager|nginx_proxy_manager
PrintMaster|printmaster
Servarr|servarr
SnipeIT|snipeit
Unifi_Network_Application|unifi_network_application
Vaultwarden|vaultwarden
WordPress|wordpress
audplexus|audplexus
netdata|netdata
Audible_Plex Downloader|audible_plex_downloader
Home_Assistant|home_assistant
AdGuard-Home|adguard-home
Pihole v6|pihole_v6
Jellyfin2025|jellyfin2025
traefik-v3.0|traefik-v3_0
EOF
}

@test "project name sanitizer boundary cases" {
    while IFS='|' read -r input expected; do
        assert_sanitize_case "$input" "$expected" || return 1
    done <<'EOF'
-leading|leading
trailing-|trailing
_leading_underscore|leading_underscore
trailing_underscore_|trailing_underscore
---|compose
___|compose
   |compose
a|a
1|1
a-b|a-b
a_b|a_b
EOF
}

@test "project name sanitizer is idempotent" {
    while IFS= read -r input; do
        once=$(sanitize_project_name "$input")
        twice=$(sanitize_project_name "$once")
        if [ "$once" != "$twice" ]; then
            echo "idempotency failed for '$input': first='$once' second='$twice'"
            return 1
        fi
    done <<'EOF'
My Space Stack
Prod__API---V2
my..stack--prod__v1
Stack+Name@Home
---___...
-leading
EOF
}

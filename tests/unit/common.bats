#!/usr/bin/env bats
#
# Unit Tests for common.sh shared functions
#

load '../bats_setup.bash'

COMMON_SCRIPT="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/common.sh"

# ============================================================
# composeLogger Tests
# ============================================================

@test "composeLogger logs message to syslog mock" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    composeLogger "test log entry" "info" "general"

    assert_logged "test log entry"
}

@test "composeLogger defaults to info level" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    composeLogger "default level"

    assert_logged "default level"
}

@test "composeLogger includes category prefix" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    composeLogger "category msg" "warning" "cron"

    assert_logged "\[WARN\] \[cron\] category msg"
}

# ============================================================
# canonicalize_project_name Tests
# ============================================================

# Helper: source common.sh, run canonicalize_project_name, assert output matches.
assert_canonical_case() {
    local input="$1"
    local expected="$2"

    run canonicalize_project_name "$input"
    [[ "$status" -eq 0 ]]
    if [[ "$output" != "$expected" ]]; then
        echo "canonicalize_project_name failed for '$input': expected '$expected', got '$output'"
        return 1
    fi
}
@test "canonicalize_project_name lowercases and replaces spaces/dots with underscores, preserves dashes" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    run canonicalize_project_name "My Stack-Name.1"

    [[ "$status" -eq 0 ]]
    [[ "$output" == "my_stack-name_1" ]]
}

@test "canonicalize_project_name preserves already-valid project names" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    run canonicalize_project_name "stack-one"

    [[ "$status" -eq 0 ]]
    [[ "$output" == "stack-one" ]]
}

@test "canonicalize_project_name collapses repeated separators" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    run canonicalize_project_name "Stack---Name__1"

    [[ "$status" -eq 0 ]]
    [[ "$output" == "stack-name_1" ]]
}

@test "canonicalize_project_name trims leading and trailing separators" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    run canonicalize_project_name "..-My Stack-Name-.."

    [[ "$status" -eq 0 ]]
    [[ "$output" == "my_stack-name" ]]
}

@test "canonicalize_project_name falls back to compose for empty input" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    run canonicalize_project_name "   "

    [[ "$status" -eq 0 ]]
    [[ "$output" == "compose" ]]
}

@test "canonicalize_project_name handles broad style matrix stably" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    while IFS='|' read -r input expected; do
        assert_canonical_case "$input" "$expected" || return 1
    done <<'EOF'
mystack|mystack
MyStack|mystack
Stack2026v1|stack2026v1
my_stack-v2|my_stack-v2
My Stack-Name.1|my_stack-name_1
My Stack.v2|my_stack_v2
my___stack|my_stack
-_My Stack_-|my_stack
.My Stack.|my_stack
---___...|compose
Stack With    Spaces|stack_with_spaces
name.with.many....dots|name_with_many_dots
Prod__API---V2|prod_api-v2
A--B__C..D|a-b_c_d
MIXED_case-123|mixed_case-123
stack/name|stack_name
stack:name|stack_name
stack@v2.0|stack_v2_0
Stack+Name@Home|stack_name_home
Stack#Prod!Beta|stack_prod_beta
name(2026)[prod]{x}|name_2026_prod_x
app,backup;v2=final|app_backup_v2_final
foo$bar^baz&qux|foo_bar_baz_qux
rock'n'roll|rock_n_roll
-leading|leading
trailing-|trailing
_leading_underscore|leading_underscore
trailing_underscore_|trailing_underscore
---|compose
___|compose
   |compose
Immich|immich
AdGuard-Home|adguard-home
Pihole v6|pihole_v6
traefik-v3.0|traefik-v3_0
Nginx_Proxy_Manager|nginx_proxy_manager
WordPress|wordpress
Audible_Plex Downloader|audible_plex_downloader
EOF
}

@test "canonicalize_project_name is idempotent" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    while IFS= read -r input; do
        local once twice
        run canonicalize_project_name "$input"
        once="$output"
        run canonicalize_project_name "$once"
        twice="$output"
        if [[ "$once" != "$twice" ]]; then
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

# ============================================================
# External network helper Tests
# ============================================================

# `docker compose config --format json` output for a stack with two external networks
# (one renamed with `name:`) and one network compose creates itself.
NETWORKS_CONFIG_JSON='{"networks":{"internalnet":{"name":"stack_internalnet"},"svc":{"name":"svc-real-name","external":true},"web":{"name":"web","external":true}}}'

@test "plugin_setting_enabled reads true and false settings from the config file" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"
    export COMPOSE_MANAGER_CFG_FILE="$TEST_TEMP_DIR/compose.manager.cfg"
    printf 'CREATE_MISSING_EXTERNAL_NETWORKS="true"\nOTHER_SETTING="false"\n' > "$COMPOSE_MANAGER_CFG_FILE"

    run plugin_setting_enabled CREATE_MISSING_EXTERNAL_NETWORKS
    assert_success
    run plugin_setting_enabled OTHER_SETTING
    assert_failure
    run plugin_setting_enabled MISSING_SETTING
    assert_failure
}

@test "external_network_names lists only external networks, by their real name" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    run external_network_names <<< "$NETWORKS_CONFIG_JSON"

    assert_success
    [[ "$output" == $'svc-real-name\nweb' ]]
}

@test "create_missing_external_networks creates only the external networks that are missing" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"
    # Fake docker: "web" already exists, "svc-real-name" does not; record every create.
    docker() {
        case "$*" in
            "compose -p teststack config --format json") echo "$NETWORKS_CONFIG_JSON" ;;
            "network inspect web") return 0 ;;
            "network inspect "*) return 1 ;;
            "network create "*) echo "$3" >> "$TEST_TEMP_DIR/created" ;;
        esac
    }

    run create_missing_external_networks docker compose -p teststack

    assert_success
    assert_output_contains "Created missing external network: svc-real-name"
    [[ "$(cat "$TEST_TEMP_DIR/created")" == "svc-real-name" ]]
}

@test "create_missing_external_networks does nothing when the compose config does not parse" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"
    docker() {
        case "$*" in
            "compose -p teststack config --format json") return 1 ;;
            "network inspect "*) return 1 ;;
            "network create "*) echo "$3" >> "$TEST_TEMP_DIR/created" ;;
        esac
    }

    run create_missing_external_networks docker compose -p teststack

    assert_success
    [[ ! -e "$TEST_TEMP_DIR/created" ]]
}

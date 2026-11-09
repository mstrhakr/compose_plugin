#!/usr/bin/env bats
load '../bats_setup.bash'

RETRY_PARSER="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/registry_retry.php"

test_setup() {
    unset -f php 2>/dev/null || true
}

@test "registry retry parser handles missing hint numeric seconds and Docker duration units" {
    local hint expected
    while IFS='|' read -r hint expected; do
        printf 'toomanyrequests: %s\n' "$hint" > "$TEST_TEMP_DIR/error"
        run php "$RETRY_PARSER" "$TEST_TEMP_DIR/error"
        assert_success
        [ "$output" = "$expected" ]
    done <<'CASES'
no hint|3
retry-after: 533.64µs, allowed: 44000/minute|3
retry-after: 533.64μs, allowed: 44000/minute|3
retry-after: 200ns|3
retry-after: 4000ms|4
retry-after: 6.01s|7
retry-after: 0.1m|6
retry-after: 1h2m3s|31
Retry-After: 12|12
Retry-After: 30|30
Retry-After: 31|31
Retry-After: 9999999999999999999999999999999999999999|31
CASES
}

@test "registry retry parser accepts HTTP dates and selects the longest reported delay" {
    printf 'HTTP 429\nRetry-After: %s\n' "$(LC_ALL=C date -u -d '+20 seconds' '+%a, %d %b %Y %H:%M:%S GMT')" > "$TEST_TEMP_DIR/error"
    run php "$RETRY_PARSER" "$TEST_TEMP_DIR/error"
    assert_success
    [ "$output" -ge 18 ] && [ "$output" -le 20 ]
    printf 'toomanyrequests\nRetry-After: 4s\nRetry-After: 30s\n' > "$TEST_TEMP_DIR/error"
    run php "$RETRY_PARSER" "$TEST_TEMP_DIR/error"
    assert_success
    [ "$output" = 30 ]
}

@test "registry retry parser requires rate limit evidence and reads a bounded output tail" {
    printf 'unauthorized: authentication required\nRetry-After: 3\n' > "$TEST_TEMP_DIR/error"
    run php "$RETRY_PARSER" "$TEST_TEMP_DIR/error"
    [ "$status" = 1 ]
    [ -z "$output" ]
    printf 'toomanyrequests\n' > "$TEST_TEMP_DIR/error"
    head -c 70000 /dev/zero >> "$TEST_TEMP_DIR/error"
    printf 'unrelated download failure\n' >> "$TEST_TEMP_DIR/error"
    run php "$RETRY_PARSER" "$TEST_TEMP_DIR/error"
    [ "$status" = 1 ]
    printf '\033[31mtoomanyrequests\033[0m: retry-after: 4s\n' >> "$TEST_TEMP_DIR/error"
    run php "$RETRY_PARSER" "$TEST_TEMP_DIR/error"
    assert_success
    [ "$output" = 4 ]
}

@test "registry retry parser surfaces unreadable diagnostics instead of inventing a delay" {
    run php "$RETRY_PARSER" "$TEST_TEMP_DIR/missing"
    [ "$status" = 2 ]
    assert_output_contains "Unable to inspect registry failure output"
}

@test "unrecognized retry hints fail explicitly instead of retrying before a possible longer delay" {
    printf 'toomanyrequests: retry-after: tomorrow\n' > "$TEST_TEMP_DIR/error"
    run php "$RETRY_PARSER" "$TEST_TEMP_DIR/error"
    [ "$status" = 2 ]
    assert_output_contains "refusing an automatic retry"
}

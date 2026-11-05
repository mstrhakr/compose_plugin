#!/usr/bin/env bats

load '../bats_setup.bash'

REPO_ROOT="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"

@test "unconnected terminal viewer expires and cleans its exact listener and socket" {
    mkdir "$TEST_TEMP_DIR/bin"
    cat > "$TEST_TEMP_DIR/bin/ttyd" <<'EOF'
#!/bin/bash
echo "$$" > "$VIEWER_TEST_PID"
touch "$4"
exec sleep 60
EOF
    chmod +x "$TEST_TEMP_DIR/bin/ttyd"
    export VIEWER_TEST_PID="$TEST_TEMP_DIR/viewer.pid"
    run env PATH="$TEST_TEMP_DIR/bin:$PATH" bash "$REPO_ROOT/source/compose.manager/scripts/compose_terminal_viewer.sh" \
        "$TEST_TEMP_DIR/viewer.sock" "$TEST_TEMP_DIR/output" 0
    [ "$status" -eq 1 ]
    [[ "$output" == *"not connected within 30 seconds"* ]]
    [ ! -e "$TEST_TEMP_DIR/viewer.sock" ]
    ! kill -0 "$(cat "$VIEWER_TEST_PID")" 2>/dev/null
}

@test "connected terminal viewer follows completed output and cleans its socket" {
    mkdir "$TEST_TEMP_DIR/bin"
    cat > "$TEST_TEMP_DIR/bin/ttyd" <<'EOF'
#!/bin/bash
touch "$4"
shift 4
exec "$@"
EOF
    chmod +x "$TEST_TEMP_DIR/bin/ttyd"
    printf 'completed output' > "$TEST_TEMP_DIR/output"
    run env PATH="$TEST_TEMP_DIR/bin:$PATH" bash "$REPO_ROOT/source/compose.manager/scripts/compose_terminal_viewer.sh" \
        "$TEST_TEMP_DIR/viewer.sock" "$TEST_TEMP_DIR/output" 0
    [ "$status" -eq 0 ]
    [ "$output" = "completed output" ]
    [ ! -e "$TEST_TEMP_DIR/viewer.sock" ]
    [ ! -e "$TEST_TEMP_DIR/output.connected" ]
}

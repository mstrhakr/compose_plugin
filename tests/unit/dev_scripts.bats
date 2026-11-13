#!/usr/bin/env bats
#
# Unit tests for top-level dev scripts (build/deploy/install/test helpers).
#

load '../bats_setup.bash'

REPO_ROOT="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"

setup_script_workspace() {
    local workspace="$TEST_TEMP_DIR/workspace"
    mkdir -p "$workspace"

    cp "$REPO_ROOT/build.sh" "$workspace/build.sh"
    cp "$REPO_ROOT/deploy.sh" "$workspace/deploy.sh"
    cp "$REPO_ROOT/install.sh" "$workspace/install.sh"
    cp "$REPO_ROOT/build_in_docker.sh" "$workspace/build_in_docker.sh"
    cp "$REPO_ROOT/test.sh" "$workspace/test.sh"

    mkdir -p "$workspace/archive" "$workspace/source"
    chmod +x "$workspace"/*.sh

    echo "$workspace"
}

setup_mock_bin() {
    local mock_bin="$TEST_TEMP_DIR/mock-bin"
    mkdir -p "$mock_bin"

    cat > "$mock_bin/docker" <<'EOF'
#!/usr/bin/env bash
echo "docker $*" >> "$MOCK_LOG_FILE"
exit 0
EOF

    cat > "$mock_bin/git" <<'EOF'
#!/usr/bin/env bash
echo "git $*" >> "$MOCK_LOG_FILE"
if [[ "$1" == "-C" && "$3" == "rev-parse" ]]; then
  echo "$2"
  exit 0
fi
exit 0
EOF

    cat > "$mock_bin/ssh" <<'EOF'
#!/usr/bin/env bash
echo "ssh $*" >> "$MOCK_LOG_FILE"
exit 0
EOF

    cat > "$mock_bin/scp" <<'EOF'
#!/usr/bin/env bash
echo "scp $*" >> "$MOCK_LOG_FILE"
exit 0
EOF

    cat > "$mock_bin/plugin" <<'EOF'
#!/usr/bin/env bash
echo "plugin $*" >> "$MOCK_LOG_FILE"
exit 0
EOF

    cat > "$mock_bin/removepkg" <<'EOF'
#!/usr/bin/env bash
echo "removepkg $*" >> "$MOCK_LOG_FILE"
exit 0
EOF

    cat > "$mock_bin/upgradepkg" <<'EOF'
#!/usr/bin/env bash
echo "upgradepkg $*" >> "$MOCK_LOG_FILE"
exit 0
EOF

    chmod +x "$mock_bin"/*
    echo "$mock_bin"
}

@test "build.sh rejects unknown argument" {
    local workspace
    workspace=$(setup_script_workspace)

    run "$workspace/build.sh" --NotARealFlag

    assert_failure
    assert_output_contains "Unknown argument"
}

@test "deploy.sh quick mode requires remote host" {
    local workspace
    workspace=$(setup_script_workspace)

    run "$workspace/deploy.sh" -Quick

    assert_failure
    assert_output_contains "RemoteHost is required when using -Quick"
}

@test "deploy.sh skip-build uses latest archive package and exits without remote deploy" {
    local workspace
    workspace=$(setup_script_workspace)
    local mock_bin
    mock_bin=$(setup_mock_bin)

    touch "$workspace/archive/compose.manager-2026.01.01.0101-noarch-0101.txz"
    touch "$workspace/archive/compose.manager-2026.01.02.0202-noarch-0202.txz"

    PATH="$mock_bin:$PATH" run "$workspace/deploy.sh" -SkipBuild

    assert_success
    assert_output_contains "No RemoteHost specified"
    assert_output_contains "compose.manager-2026.01.02.0202-noarch-0202.txz"
}

@test "deploy.sh forwards skip-tests only when explicitly selected" {
    local workspace
    workspace=$(setup_script_workspace)
    cat > "$workspace/build.sh" <<'EOF'
#!/bin/bash
printf '%s\n' "$@" > "$(dirname "$0")/build-args"
touch "$(dirname "$0")/archive/compose.manager-2026.01.01-noarch-0101.txz"
EOF

    run "$workspace/deploy.sh" -Dev -Version 2026.01.01
    assert_success
    ! grep -Fx -- '-SkipTests' "$workspace/build-args"
    grep -Fx -- '-Dev' "$workspace/build-args"
    grep -Fx -- '2026.01.01' "$workspace/build-args"

    local option
    for option in -SkipTests --SkipTests --skip-tests; do
        run "$workspace/deploy.sh" "$option" -Dev
        assert_success
        grep -Fx -- '-SkipTests' "$workspace/build-args"
        grep -Fx -- '-Dev' "$workspace/build-args"
        assert_output_contains "No RemoteHost specified"
    done
}

@test "install.sh shows usage when required args missing" {
    local workspace
    workspace=$(setup_script_workspace)

    run "$workspace/install.sh"

    assert_failure
    assert_exit_code 2
    assert_output_contains "Usage:"
}

@test "test.sh help returns usage" {
    local workspace
    workspace=$(setup_script_workspace)

    run "$workspace/test.sh" --help

    assert_success
    assert_output_contains "Usage:"
}

@test "build_in_docker.sh invokes docker with pkg_build.sh" {
    local workspace
    workspace=$(setup_script_workspace)
    local mock_bin
    mock_bin=$(setup_mock_bin)

    PATH="$mock_bin:$PATH" run "$workspace/build_in_docker.sh"

    assert_success
    assert_mock_called "docker" "run --rm --tmpfs /tmp"
    assert_mock_called "docker" "run .* vbatts/slackware:latest /mnt/source/pkg_build.sh"
}

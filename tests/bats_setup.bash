#!/usr/bin/env bash
#
# Compose Manager - BATS Test Setup
#
# Project-specific helpers layered on top of the plugin-tests framework.
# Source this file instead of loading the framework setup directly.
#

# Load the generic plugin-tests framework first
BATS_SETUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
load "${BATS_SETUP_DIR}/framework/bats/setup"

# Execute the real mutation bookkeeping against the same isolated PHP paths as
# PHPUnit, without requiring the Unraid platform on the test host.
setup_mutation_php() {
    export TMPDIR="$TEST_TEMP_DIR"
    export MUTATION_REAL_PHP
    MUTATION_REAL_PHP="$(command -v php)"
    export MUTATION_TEST_BOOTSTRAP="$BATS_SETUP_DIR/bootstrap.php"
    export MUTATION_PHP_SHIM="$TEST_TEMP_DIR/mutation-bin"
    mkdir -p "$MUTATION_PHP_SHIM"
    cat > "$MUTATION_PHP_SHIM/php" <<'SHIM'
#!/bin/bash
if [[ "${1:-}" = */mutation_boundary.php ]]; then
    exec "$MUTATION_REAL_PHP" -d "auto_prepend_file=$MUTATION_TEST_BOOTSTRAP" "$@"
fi
exec "$MUTATION_REAL_PHP" "$@"
SHIM
    chmod +x "$MUTATION_PHP_SHIM/php"
    export PATH="$MUTATION_PHP_SHIM:$PATH"
}

# ============================================================
# Compose Manager Helpers (override / extend framework defaults)
# ============================================================

# Create a test stack directory with a compose file using Docker Compose
# spec priority naming (compose.yaml).
# Usage: create_test_stack [name]
create_test_stack() {
    local name="${1:-teststack}"
    local stack_dir="$TEST_TEMP_DIR/$name"

    mkdir -p "$stack_dir"
    create_test_compose_file "$stack_dir/compose.yaml" > /dev/null

    echo "$stack_dir"
}

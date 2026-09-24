#!/bin/bash
# Compose Manager - Shared shell functions
# Sourced by event scripts and other shell scripts that need common utilities.

# Shared logging function matching the PHP composeLogger() convention.
# Usage: composeLogger "message" [level] [category] [type]
#   level: info (default), debug, error, warning
#   category: optional tag (e.g. autoupdate, backup, compose)
#   type: optional log type (user, daemon) defaults to user
composeLogger() {
    local msg="$1"
    local level="${2:-info}"
    local category="${3:-}"
    local type="${4:-user}"
    local priority="${type}.info"
    local displayLevel="[INFO]"
    local debugMode="false"

    if [ "${debug:-false}" = true ] || [ "${DEBUG_TO_LOG:-false}" = true ]; then
        debugMode="true"
    fi

    case "$level" in
        debug)
            priority="${type}.debug"
            displayLevel="[DEBUG]"
            ;;
        error|err)
            priority="${type}.err"
            displayLevel="[ERROR]"
            ;;
        warning|warn)
            priority="${type}.warning"
            displayLevel="[WARN]"
            ;;
        info|*)
            priority="${type}.info"
            displayLevel="[INFO]"
            ;;
    esac

    if [ "$debugMode" = true ]; then
        local prefix="[${priority}]"
    else
        local prefix="${displayLevel}"
    fi
    if [ -n "$category" ]; then
        prefix="${prefix} [${category}]"
    fi
    logger -t 'compose.manager' -p "$priority" "${prefix} ${msg}"
}

# Canonical Docker Compose project-name sanitizer.
# Delegates to the dependency-free PHP helper so every code path shares one
# implementation.
canonicalize_project_name() {
    local raw_name="${1-}"
    local php_cmd="${COMPOSE_MANAGER_PHP:-php}"
    local helper_file
    helper_file="$(dirname "${BASH_SOURCE[0]}")/../include/ProjectNameSanitizer.php"

    local canonical_name
    # shellcheck disable=SC2016  # $argv[] are PHP variables, not shell — single quotes are intentional.
    canonical_name=$("$php_cmd" -r '
require_once $argv[1];
echo compose_manager_sanitize_project_name((string) ($argv[2] ?? ""));
' "$helper_file" "$raw_name" 2>/dev/null)

    if [ -z "$canonical_name" ]; then
        composeLogger "Failed to canonicalize project name: $raw_name" error compose
        return 1
    fi

    echo "$canonical_name"
}

# Find the compose file in a directory using Docker Compose spec priority.
# Returns the full path to the first matching file, or exits with 1 if none found.
# Priority: compose.yaml > compose.yml > docker-compose.yaml > docker-compose.yml
# See: https://docs.docker.com/compose/intro/compose-application-model/#the-compose-file
find_compose_file() {
    local dir="$1"
    for name in compose.yaml compose.yml docker-compose.yaml docker-compose.yml; do
        if [ -f "$dir/$name" ]; then
            echo "$dir/$name"
            return 0
        fi
    done
    return 1
}


find_compose_override_file() {
    local dir="$1"
    for name in compose.override.yaml compose.override.yml docker-compose.override.yaml docker-compose.override.yml; do
        if [ -f "$dir/$name" ]; then
            echo "$dir/$name"
            return 0
        fi
    done
    return 1
}

# Check if a directory has any compose file
has_compose_file() {
    find_compose_file "$1" > /dev/null 2>&1
}

# Resolve effective env file for a stack.
# Order:
#  1) <stack>/envpath when it points to a readable file
#  2) <compose source>/.env (local fallback)
#
# Compose source is the indirect directory when <stack>/indirect exists,
# otherwise the stack directory itself.
# Empty envpath values are treated as unset.
# Invalid envpath values are ignored and fallback is attempted.
# Prints the resolved env file path when found and returns 0.
# Returns 1 when no usable env file is available.
resolve_stack_env_file() {
    local stack_dir="$1"
    local compose_source="$stack_dir"

    if [ -f "$stack_dir/indirect" ]; then
        local indirect
        indirect=$(< "$stack_dir/indirect")
        if [ -n "$indirect" ]; then
            compose_source="$indirect"
        fi
    fi

    if [ -f "$stack_dir/envpath" ]; then
        local envpath
        envpath=$(< "$stack_dir/envpath")
        envpath="$(echo "$envpath" | xargs)"
        if [ -n "$envpath" ] && [ -f "$envpath" ]; then
            echo "$envpath"
            return 0
        fi
        if [ -n "$envpath" ]; then
            composeLogger "Ignoring invalid envpath for stack '$stack_dir': $envpath" warning stack
        fi
    fi

    local local_env="$compose_source/.env"
    if [ -f "$local_env" ]; then
        echo "$local_env"
        return 0
    fi

    return 1
}

# Load compose command args for a stack/action from the PHP builder.
#
# Populates global variables:
#   COMPOSE_SPEC_PROJECT_NAME
#   COMPOSE_SPEC_STACK_PATH
#   COMPOSE_SPEC_PROJECT_DIR
#   COMPOSE_SPEC_USE_DEFAULT_FILE_DISCOVERY
#   COMPOSE_SPEC_ENV_FILE_PATH
#   COMPOSE_SPEC_CREDENTIAL_ID
#   COMPOSE_SPEC_COMPOSE_FILES (array)
#   COMPOSE_SPEC_PROFILES (array)
#
# Returns 0 on success, 1 on parse/build failure.
load_compose_action_spec() {
    local compose_root="$1"
    local project="$2"
    local action="$3"
    local stack_path="${4:-}"

    local php_cmd="${COMPOSE_MANAGER_PHP:-php}"
    local args_script="/usr/local/emhttp/plugins/compose.manager/scripts/compose_args.php"

    local -a cmd=(
        "$php_cmd"
        "$args_script"
        --compose-root "$compose_root"
        --project "$project"
        --action "$action"
        --format tsv
    )
    if [ -n "$stack_path" ]; then
        cmd+=(--stack-path "$stack_path")
    fi

    COMPOSE_SPEC_ERROR_MESSAGE=""

    local output=""
    local stderr_file=""
    local stderr_output=""
    local command_status=0

    stderr_file=$(mktemp /tmp/compose_args_stderr.XXXXXX 2>/dev/null || true)
    if [ -n "$stderr_file" ]; then
        output="$("${cmd[@]}" 2>"$stderr_file")"
        command_status=$?
        if [ -s "$stderr_file" ]; then
            stderr_output=$(tr '\n' ' ' < "$stderr_file" | sed 's/[[:space:]]\+/ /g; s/^ //; s/ $//')
        fi
        rm -f "$stderr_file"
    else
        output="$("${cmd[@]}" 2>&1)"
        command_status=$?
    fi

    if [ $command_status -ne 0 ]; then
        if [ -n "$stderr_output" ]; then
            COMPOSE_SPEC_ERROR_MESSAGE="$stderr_output"
        elif [ -n "$output" ]; then
            COMPOSE_SPEC_ERROR_MESSAGE=$(echo "$output" | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g; s/^ //; s/ $//')
        else
            COMPOSE_SPEC_ERROR_MESSAGE="compose args provider command failed"
        fi

        composeLogger "Failed to resolve compose args for '$project' action '$action': $COMPOSE_SPEC_ERROR_MESSAGE" warning compose-args
        return 1
    fi

    COMPOSE_SPEC_PROJECT_NAME=""
    COMPOSE_SPEC_STACK_PATH=""
    # shellcheck disable=SC2034  # Populated here, consumed by scripts that source common.sh.
    COMPOSE_SPEC_PROJECT_DIR=""
    # shellcheck disable=SC2034  # Populated here, consumed by scripts that source common.sh.
    COMPOSE_SPEC_USE_DEFAULT_FILE_DISCOVERY="false"
    # shellcheck disable=SC2034  # Populated here, consumed by scripts that source common.sh.
    COMPOSE_SPEC_ENV_FILE_PATH=""
    COMPOSE_SPEC_CREDENTIAL_ID=""
    COMPOSE_SPEC_ERROR_MESSAGE=""
    COMPOSE_SPEC_COMPOSE_FILES=()
    COMPOSE_SPEC_PROFILES=()

    local result=""
    while IFS=$'\t' read -r key value; do
        case "$key" in
            result)
                result="$value"
                ;;
            message)
                COMPOSE_SPEC_ERROR_MESSAGE="$value"
                ;;
            projectName)
                COMPOSE_SPEC_PROJECT_NAME="$value"
                ;;
            stackPath)
                COMPOSE_SPEC_STACK_PATH="$value"
                ;;
            projectDirectory)
                # shellcheck disable=SC2034  # Value consumed by scripts after load_compose_action_spec returns.
                COMPOSE_SPEC_PROJECT_DIR="$value"
                ;;
            useDefaultFileDiscovery)
                COMPOSE_SPEC_USE_DEFAULT_FILE_DISCOVERY="$value"
                ;;
            envFilePath)
                # shellcheck disable=SC2034  # Populated here, consumed by scripts that source common.sh.
                COMPOSE_SPEC_ENV_FILE_PATH="$value"
                ;;
            credentialId)
                # shellcheck disable=SC2034  # Populated here, consumed by scripts that source common.sh.
                COMPOSE_SPEC_CREDENTIAL_ID="$value"
                ;;
            composeFile)
                COMPOSE_SPEC_COMPOSE_FILES+=("$value")
                ;;
            profile)
                COMPOSE_SPEC_PROFILES+=("$value")
                ;;
        esac
    done <<< "$output"

    if [ "$result" != "success" ]; then
        if [ -z "$COMPOSE_SPEC_ERROR_MESSAGE" ]; then
            COMPOSE_SPEC_ERROR_MESSAGE="compose args provider returned an invalid response"
        fi
        composeLogger "Compose args resolution failed for '$project': $COMPOSE_SPEC_ERROR_MESSAGE" warning compose-args
        return 1
    fi

    if [ -z "$COMPOSE_SPEC_PROJECT_NAME" ]; then
        composeLogger "Compose args resolution returned incomplete data for '$project'" warning compose-args
        return 1
    fi

    if [ "${COMPOSE_SPEC_USE_DEFAULT_FILE_DISCOVERY}" != "true" ] && [ ${#COMPOSE_SPEC_COMPOSE_FILES[@]} -eq 0 ]; then
        composeLogger "Compose args resolution returned no compose files for '$project'" warning compose-args
        return 1
    fi

    if [ -z "$COMPOSE_SPEC_STACK_PATH" ]; then
        COMPOSE_SPEC_STACK_PATH="$stack_path"
    fi

    return 0
}

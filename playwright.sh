#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
E2E_DIR="$SCRIPT_DIR/tests/e2e"
SERVERS_ENV_FILE="$E2E_DIR/.auth/servers.env"
AUTH_ENV_FILE="$E2E_DIR/.auth/auth.env"

REMOTE_HOST=""
USER_NAME="root"
REMOTE_DIR="/tmp"
BASE_URL=""
STORAGE_STATE="$E2E_DIR/.auth/storage-state.json"
COMPOSE_PATH="/Docker/Compose"
IGNORE_HTTPS_ERRORS="1"
TEST_STACK=""
ENABLE_MUTATION_TESTS="0"
TEST_STACK_PREFIX="pw-e2e"
EXTERNAL_TEST_DIR=""
COMPOSE_VERSION=""
SKIP_DEPLOY=false

PLAYWRIGHT_ARGS=()

load_env_file() {
  local env_file="$1"
  if [[ -f "$env_file" ]]; then
    set -a
    # shellcheck disable=SC1090
    # shellcheck source=/dev/null
    source "$env_file"
    set +a
  fi
}

load_env_file "$SERVERS_ENV_FILE"
load_env_file "$AUTH_ENV_FILE"

# Honor pre-set environment values from sourced .env files unless overridden by CLI flags.
if [[ -n "${E2E_ENABLE_MUTATION_TESTS:-}" ]]; then
  ENABLE_MUTATION_TESTS="$E2E_ENABLE_MUTATION_TESTS"
fi
if [[ -n "${E2E_TEST_STACK_PREFIX:-}" ]]; then
  TEST_STACK_PREFIX="$E2E_TEST_STACK_PREFIX"
fi
if [[ -n "${E2E_EXTERNAL_TEST_DIR:-}" ]]; then
  EXTERNAL_TEST_DIR="$E2E_EXTERNAL_TEST_DIR"
fi

usage() {
  cat <<'EOF'
Usage: ./playwright.sh [options] [-- <playwright args>]

Options:
  -RemoteHost <host>           Required unless -SkipDeploy is used.
  -User <ssh-user>             SSH user for deploy (default: root)
  -RemoteDir <remote-dir>      Remote temp directory for deploy (default: /tmp)
  -BaseURL <url>               E2E base URL (default: https://<RemoteHost>)
  -StorageState <path>         Playwright auth state JSON path
  -ComposePath <path>          Compose page path (default: /Docker/Compose)
  -IgnoreHttpsErrors <0|1>     Playwright TLS ignore toggle (default: 1)
  -TestStack <name>            Optional stack name to target for single-stack read-only tests
  -EnableMutationTests <0|1>   Enable GUID isolated create/edit/start/stop/delete tests (default: 0)
  -TestStackPrefix <prefix>    Prefix for generated GUID stack name (default: pw-e2e)
  -ExternalTestDir <path>      Enables external-path mutation tests and sets their target directory
  -ComposeVersion <version>    Optional compose version forwarded to deploy.sh
  -SkipDeploy                  Skip deploy.sh -Dev and run tests only
  -Help, -h                    Show this help

Examples:
  ./playwright.sh -RemoteHost saturn
  ./playwright.sh -RemoteHost saturn -- --headed
  ./playwright.sh -SkipDeploy -BaseURL https://tower.local -- --project firefox
EOF
  exit 1
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --)
      shift
      PLAYWRIGHT_ARGS+=("$@")
      break
      ;;
    -RemoteHost|--RemoteHost)
      REMOTE_HOST="$2"
      shift 2
      ;;
    -User|--User)
      USER_NAME="$2"
      shift 2
      ;;
    -RemoteDir|--RemoteDir)
      REMOTE_DIR="$2"
      shift 2
      ;;
    -BaseURL|--BaseURL)
      BASE_URL="$2"
      shift 2
      ;;
    -StorageState|--StorageState)
      STORAGE_STATE="$2"
      shift 2
      ;;
    -ComposePath|--ComposePath)
      COMPOSE_PATH="$2"
      shift 2
      ;;
    -IgnoreHttpsErrors|--IgnoreHttpsErrors)
      IGNORE_HTTPS_ERRORS="$2"
      shift 2
      ;;
    -TestStack|--TestStack)
      TEST_STACK="$2"
      shift 2
      ;;
    -EnableMutationTests|--EnableMutationTests)
      ENABLE_MUTATION_TESTS="$2"
      shift 2
      ;;
    -TestStackPrefix|--TestStackPrefix)
      TEST_STACK_PREFIX="$2"
      shift 2
      ;;
    -ExternalTestDir|--ExternalTestDir)
      EXTERNAL_TEST_DIR="$2"
      shift 2
      ;;
    -ComposeVersion|--ComposeVersion)
      COMPOSE_VERSION="$2"
      shift 2
      ;;
    -SkipDeploy|--SkipDeploy)
      SKIP_DEPLOY=true
      shift
      ;;
    -Help|--Help|-h)
      usage
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      ;;
  esac
done

if [[ "$SKIP_DEPLOY" == false && -z "$REMOTE_HOST" ]]; then
  echo "RemoteHost is required unless -SkipDeploy is used." >&2
  usage
fi

if [[ -z "$BASE_URL" ]]; then
  if [[ -n "$REMOTE_HOST" ]]; then
    BASE_URL="https://$REMOTE_HOST"
  else
    echo "BaseURL is required when using -SkipDeploy without -RemoteHost." >&2
    usage
  fi
fi

if [[ "$SKIP_DEPLOY" == false ]]; then
  deploy_cmd=(bash "$SCRIPT_DIR/deploy.sh" -Dev -RemoteHost "$REMOTE_HOST" -User "$USER_NAME" -RemoteDir "$REMOTE_DIR")
  if [[ -n "$COMPOSE_VERSION" ]]; then
    deploy_cmd+=(-ComposeVersion "$COMPOSE_VERSION")
  fi

  echo "Running deploy first (includes build/test/static checks):"
  printf '  %q' "${deploy_cmd[@]}"
  printf '\n'
  "${deploy_cmd[@]}"
fi

if ! command -v npm >/dev/null 2>&1; then
  echo "npm is required to run Playwright tests." >&2
  exit 1
fi

if ! command -v npx >/dev/null 2>&1; then
  echo "npx is required to run Playwright tests." >&2
  exit 1
fi

if [[ ! -d "$E2E_DIR" ]]; then
  echo "E2E directory not found: $E2E_DIR" >&2
  exit 1
fi

if [[ ! -d "$E2E_DIR/node_modules" ]]; then
  echo "Installing Playwright dependencies in $E2E_DIR ..."
  (cd "$E2E_DIR" && npm install)
fi

if [[ ! -f "$STORAGE_STATE" ]]; then
  if [[ -n "${AUTH_USERNAME:-}" && -n "${AUTH_PASSWORD:-}" ]]; then
    echo "Storage state not found. Attempting headless auth-state generation..."
    (
      cd "$E2E_DIR"
      E2E_BASE_URL="$BASE_URL" \
      E2E_STORAGE_STATE="$STORAGE_STATE" \
      E2E_IGNORE_HTTPS_ERRORS="$IGNORE_HTTPS_ERRORS" \
      AUTH_USERNAME="$AUTH_USERNAME" \
      AUTH_PASSWORD="$AUTH_PASSWORD" \
      node ./scripts/create-storage-state.mjs
    )
  fi

  if [[ ! -f "$STORAGE_STATE" ]]; then
    echo "Storage state not found: $STORAGE_STATE" >&2
    echo "Create it with one of:" >&2
    echo "  1) Fill tests/e2e/.auth/auth.env (AUTH_USERNAME/AUTH_PASSWORD) and re-run ./playwright.sh" >&2
    echo "  2) cd $E2E_DIR && E2E_BASE_URL=\"$BASE_URL\" npx playwright codegen \"$BASE_URL\" --save-storage=.auth/storage-state.json" >&2
    exit 1
  fi
fi

run_cmd=(npx playwright test)
if [[ ${#PLAYWRIGHT_ARGS[@]} -gt 0 ]]; then
  run_cmd+=("${PLAYWRIGHT_ARGS[@]}")
fi

echo "Running Playwright E2E against $BASE_URL"
(
  cd "$E2E_DIR"
  E2E_BASE_URL="$BASE_URL" \
  E2E_STORAGE_STATE="$STORAGE_STATE" \
  E2E_COMPOSE_PATH="$COMPOSE_PATH" \
  E2E_IGNORE_HTTPS_ERRORS="$IGNORE_HTTPS_ERRORS" \
  E2E_TEST_STACK="$TEST_STACK" \
  E2E_ENABLE_MUTATION_TESTS="$ENABLE_MUTATION_TESTS" \
  E2E_TEST_STACK_PREFIX="$TEST_STACK_PREFIX" \
  E2E_EXTERNAL_TEST_DIR="$EXTERNAL_TEST_DIR" \
  "${run_cmd[@]}"
)

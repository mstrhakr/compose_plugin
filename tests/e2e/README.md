# Playwright E2E (Live Server, Read-Only)

This suite is designed for **non-destructive** smoke testing against a real Unraid server.

## Safety Model

- Tests are read-only by design.
- A network guard blocks **all POST requests** to `Exec.php` and `ComposeUtil.php`.
- If a blocked action is attempted, the test fails.
- Tests run single-worker to reduce production load (`workers: 1`).

This is still live-prod testing. Treat it as smoke validation, not full mutation testing.

## Setup

1. Install dependencies:

```bash
cd /code/compose_plugin/tests/e2e
npm install
npx playwright install chromium firefox
```

1. Create authenticated storage state (one-time or when session expires):

```bash
cd /code/compose_plugin/tests/e2e
E2E_BASE_URL="https://your-unraid-host" npx playwright codegen "$E2E_BASE_URL" --save-storage=.auth/storage-state.json
```

Use the opened browser to log in, then close it to save state.

If you cannot launch a headed browser in your environment, use headless login state generation:

```bash
cd /code/compose_plugin/tests/e2e
set -a
source .auth/auth.env
set +a
E2E_BASE_URL="https://your-unraid-host" \
E2E_STORAGE_STATE=".auth/storage-state.json" \
E2E_IGNORE_HTTPS_ERRORS="1" \
node ./scripts/create-storage-state.mjs
```

## Run

```bash
cd /code/compose_plugin/tests/e2e
E2E_BASE_URL="https://your-unraid-host" \
E2E_STORAGE_STATE=".auth/storage-state.json" \
E2E_COMPOSE_PATH="/Docker/Compose" \
E2E_IGNORE_HTTPS_ERRORS="1" \
E2E_TEST_STACK="my-safe-test-stack" \
npm test
```

Run GUID-isolated mutation lifecycle tests (off by default):

```bash
cd /code/compose_plugin
./playwright.sh -SkipDeploy \
    -EnableMutationTests 1 \
    -TestStackPrefix pw-e2e \
    -- --project=chromium tests/compose-isolated-lifecycle.spec.ts
```

Run external-path mutation checks (explicit opt-in only):

```bash
cd /code/compose_plugin
./playwright.sh -SkipDeploy \
    -EnableMutationTests 1 \
    -ExternalTestDir /mnt/user/e2e-compose-external \
    -- --project=chromium tests/compose-isolated-lifecycle.spec
    ts
```

## Environment Variables

- `E2E_BASE_URL` (required): Unraid server base URL, e.g. `https://tower.local`
- `E2E_STORAGE_STATE` (optional): auth state file (default `.auth/storage-state.json`)
- `E2E_COMPOSE_PATH` (optional): Compose page path (default `/Docker/Compose`)
- `E2E_IGNORE_HTTPS_ERRORS` (optional): `1` to allow self-signed certs
- `E2E_TEST_STACK` (optional): target exactly one stack by visible row text
- `E2E_ENABLE_MUTATION_TESTS` (optional): `1` enables create/edit/start/stop/delete lifecycle spec
- `E2E_TEST_STACK_PREFIX` (optional): prefix for generated GUID stack names (default `pw-e2e`)
- `E2E_EXTERNAL_TEST_DIR` (optional): required to enable external-path mutation tests; if unset, those tests are skipped

## Why A Test Might Be Skipped

- The interaction test skips when there are no stack rows.
- If `E2E_TEST_STACK` is set, tests skip/fail when that stack is not present.
- Mutation lifecycle tests skip unless `E2E_ENABLE_MUTATION_TESTS=1`.
- External-path tests skip unless `E2E_EXTERNAL_TEST_DIR` is explicitly set.
- This behavior is intentional to avoid touching unknown stacks.

## Mutation Safety Model

- Mutation tests use a unique stack name each run: `<prefix>-<guid>`.
- All mutating calls reference only that generated stack/project.
- Cleanup runs in `finally`: `composeDown` then `deleteStack`.
- Default is safe-off (`E2E_ENABLE_MUTATION_TESTS=0`).

## Current Scope

- Compose page loads for authenticated user
- Key table elements render
- Basic row interaction remains read-only

## Next Safe Expansions

- Add dashboard tile read-only checks
- Add icon fallback assertions for broken icon URLs
- Add explicit mixed-content observation checks (no action triggers)

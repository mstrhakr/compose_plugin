# Playwright E2E

The suite includes local deterministic regressions, live read-only smoke tests,
and explicitly enabled live mutation tests. A passing local run is not proof
that live-host gates passed; report skips separately.

## Safety Model

- Read-only specs block **all POST requests** to `Exec.php` and `ComposeUtil.php`.
- If a blocked action is attempted, the test fails.
- Tests run single-worker to reduce production load (`workers: 1`).

This is still live-prod testing. Treat it as smoke validation, not full mutation testing.

## Isolated host stabilization gate

This gate is **off by default**, even if older mutation suites were enabled.
It requires the exact host origin as a second acknowledgement. Missing or
mismatched acknowledgement fails configuration rather than producing a green
skipped gate:

```bash
cd tests/e2e
E2E_BASE_URL="https://your-unraid-host" \
E2E_HOST_TEST_ACK="https://your-unraid-host" \
E2E_ENABLE_MUTATION_TESTS=1 \
E2E_HOST_SSH="root@your-unraid-host" \
E2E_STORAGE_STATE=".auth/storage-state.json" \
E2E_COMPOSE_PATH="/Docker/Compose" \
npm run test:host
```

This command does not deploy the plugin. First explicitly arrange installation
of the candidate being validated. Do not use the deploying wrapper without
`-SkipDeploy` on a shared server. Prefer Chromium first: each additional browser
repeats real mutations.

The gate creates generated `pw-host-<random>` stacks, verifies each returned
identity/path, and targets only those stacks. The mixed-input dispatch case uses
two disposable stacks. Fixtures use no published ports,
host paths, external networks, privileged mode, restart policies, or named
volumes; running services have memory/CPU/PID limits. They use Alpine and small
inline builds. Image downloads and build cache/local test images may remain;
there is deliberately **no image prune or global cleanup**.

Coverage includes selected/default/excluded profiles for stopped/uncreated
Pull/Build & Up; successful inline build and failed preparation; exact operation
receipt/exit status; foreground Continue in background, explicit Stop command,
and completion-only Done without navigation; unchanged restart
indicator/menu recovery; a deliberately aborted health request; and a delayed
real Dashboard snapshot arriving after action completion. Recreate checks verify
that fresh container IDs reach the live row without a page reload; they do not
claim that Nchan delivers fresh metric samples.

Browser endpoint writes are allowlisted to the generated stack/container.
Terminal status/stop requests additionally require an exact session ID returned
by that stack's tracked dispatch; an arbitrary terminal cannot be stopped.
Unrelated automatic browser actions (including global checks and publisher
startup) are blocked and listed in a test attachment. API requests use fixed,
scoped helper payloads; browser route guards do not intercept APIRequestContext.
Tests override wait-for-health **per request**, not in server settings.
Terminal-control cases explicitly wait up to 30 seconds for a disposable
service's delayed readiness, so the controls are exercised during an active
command rather than after a fast detached Up has already finished.
No global preferences, autostart, scheduled updates, existing stacks, or
publisher processes are changed. Cleanup waits for the exact Down receipt and
container removal before deleting metadata; failures are explicit and leave
evidence rather than attempting broader cleanup.

The review regression cases require `E2E_HOST_SSH` with noninteractive SSH access.
They run the installed wrapper's global `list`/`ps` read-only commands, inspect
the Maintenance report without changing identities, verify a saved override in
effective Compose configuration, and induce registration/missing-env failures
only in generated fixture metadata. Original fixture metadata is restored before
cleanup. The registration test checks unchanged file contents, revision and
receipt; mixed bulk dispatch checks a healthy fixture receipt and no deployment
of the bad-input fixture. No cache-clear control is clicked.
Normal fixture saves wait for operation-lock release, independently of receipt
completion. The explicit busy-save regression still asserts rejection while an
operation is active.

`E2E_DASHBOARD_PATH` optionally selects the Dashboard URL (default `/Dashboard`).
The Dashboard Compose tile must be enabled already; tests do not alter it.
This gate does not yet cover bulk actions, a 30-stack host fixture, metrics
publisher recovery, real registry-change readiness, reinstall/reboot, migration,
or scheduler/shutdown. Keep those release gates open. Build failure uses a tiny
intentional failing build, not a deliberately unavailable external registry.

## Setup

1. Install dependencies:

```bash
cd /code/compose_plugin/tests/e2e
npm install
npx playwright install-deps
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
- `E2E_GIT_TEST_REPO` and `E2E_GIT_TEST_COMPOSE_PATH` (optional): required to enable the git stack mutation test: a repository the server can clone, and the path of a compose file in it that starts without a `.env` and uses no port another stack holds. `E2E_GIT_TEST_BRANCH` defaults to `main`. Deleting the test's git stack leaves its clone under the git clones folder; remove those by hand

## Why A Test Might Be Skipped

- The interaction test skips when there are no stack rows.
- If `E2E_TEST_STACK` is set, tests skip/fail when that stack is not present.
- Mutation lifecycle tests skip unless `E2E_ENABLE_MUTATION_TESTS=1`.
- External-path tests skip unless `E2E_EXTERNAL_TEST_DIR` is explicitly set.
- The git stack test skips unless `E2E_GIT_TEST_REPO` and `E2E_GIT_TEST_COMPOSE_PATH` are set.
- This behavior is intentional to avoid touching unknown stacks.

## Mutation Safety Model

- Mutation tests use a unique stack name each run: `<prefix>-<guid>`.
- All mutating calls reference only that generated stack/project.
- Cleanup runs in `finally`: `composeDown` then `deleteStack`.
- Default is safe-off (`E2E_ENABLE_MUTATION_TESTS=0`).

## Current Scope

- Read-only smoke: authenticated page loads, table rendering, basic interaction.
- Deterministic regressions: isolated production-function behavior and layouts.
- Opt-in generated-stack lifecycle, Git, and host stabilization cases as
  documented above. Enabling one suite does not establish coverage for another.

## Next Safe Expansions

- Add dashboard tile read-only checks
- Add icon fallback assertions for broken icon URLs
- Add explicit mixed-content observation checks (no action triggers)

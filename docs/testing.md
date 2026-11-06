# Testing Guide for Compose Plugin

This document describes the testing infrastructure for the Compose Manager plugin.

Source ownership regressions cover editor/template/settings writes, real Git
conversion/dry-run/reclone, failed writes, canonical project contention, and
acknowledgement invalidation. Container regressions verify immutable ID execution,
native containers, lock contention, identity changes, and real CLI exit errors.
The isolated Dashboard suite checks error/malformed/network responses and
duplicate requests in Chromium and Firefox.

Compose is pinned to 5.7.0, whose required-health-deadline handling replaces the
false-success path observed in 5.5.0. The official checksum-verified Linux x86_64
binary passed standalone version/config and Engine project-enumeration probes
on Unraid 7.3.2 / Docker 29.5.3 (API 1.40–1.54), without replacing the installed
binary or changing containers. These read-only probes are not a substitute for
the guarded candidate gate: it asserts a five-second required health deadline
produces both a nonzero operation receipt and a failed saved command result.
Other Unraid/Engine combinations have not been live-tested by this probe.
The bundled Moby client negotiates API versions down to 1.40; Compose shapes
requests using the negotiated version rather than its SDK's maximum of 1.56.

## Quick Start

### Running PHP Tests (Native)

```powershell
# Run all PHP unit tests
php vendor/bin/phpunit

# Run with detailed output
php vendor/bin/phpunit --testdox

# Run specific test file
php vendor/bin/phpunit tests/unit/UtilTest.php

# Run with code coverage (requires Xdebug)
php vendor/bin/phpunit --coverage-html tests/coverage/html
```

### Running BATS Tests (Docker)

```powershell
# Using the helper script
.\run-bats.cmd

# Or directly with Docker
docker run --rm -v "${PWD}:/code" -w /code bats/bats:latest tests/unit/*.bats
```

### Running Playwright E2E (Live Server, Read-Only)

The E2E suite is intentionally designed for non-destructive smoke checks against a real Unraid server.

```bash
cd tests/e2e
npm install
npx playwright install chromium firefox
```

Create or refresh auth state:

```bash
cd tests/e2e
E2E_BASE_URL="https://your-unraid-host" npx playwright codegen "$E2E_BASE_URL" --save-storage=.auth/storage-state.json
```

Run the suite:

```bash
cd tests/e2e
E2E_BASE_URL="https://your-unraid-host" \
E2E_STORAGE_STATE=".auth/storage-state.json" \
E2E_COMPOSE_PATH="/Docker/Compose" \
E2E_IGNORE_HTTPS_ERRORS="1" \
npm test
```

See `tests/e2e/README.md` for details and safety guardrails.

The opt-in `npm run test:host` stabilization gate requires
`E2E_HOST_TEST_ACK` matching the exact `E2E_BASE_URL` origin in addition to
mutation permission. It creates only generated disposable stacks and does not
deploy the plugin or alter global settings. See the E2E README for prerequisites,
coverage and intentionally unautomated gates. Do not count configuration skips
as live validation; these tests must be run separately on the installed candidate.

### Image Update State and Action Refresh

Known image-check results replace older results, including `hasUpdate: false` and cleared digest fields. Unknown results can retain a previous check only for the same image reference; changing a tag must not inherit another tag's badge. Clearing the update cache removes update fields from the persistent container metadata without removing icons or runtime metadata.

An image without a conclusive check is shown as **unchecked**, not **up-to-date**. Incomplete checks log whether the local or remote config digest was unavailable. Each container's actual image ID is compared with the remote config digest, so pulling a tag without recreating the container cannot hide its pending update. Registry checks are batched by image reference, but replicas are compared separately. The checker compares platform-specific image config digests, so a changed multi-architecture index alone does not imply an update for this host. Digest-pinned image behavior is unchanged.

Review regressions cover explicit automatic project directories with single files
and file lists; inspected references when Docker lists short image IDs; application
registry failure preserving pending evidence; pinned bases without fabricated
remote IDs; multiple heredocs; and image-backed versus unsupported named contexts.
Platform tests exercise real local manifest/config files for amd64 and arm64,
FROM/Compose/ARG platform selection, stage aliases, both platform-keyed caches,
missing architectures and ARM variants, single-architecture mismatches, and
isolation from Unraid's host-only shared cache. These are deterministic fixture
checks, not a live cross-architecture Docker build.

Second-review regressions cover relative workdirs in direct and legacy automatic
invocations; punctuation-prefixed service names with positional option delimiters
in manual/automatic pull, build, config, up, and cleanup paths; strict enumeration
errors preserving the saved file byte-for-byte; and successful empty snapshots
clearing removed-container evidence. Individual and bulk checks use the same
Docker rows even when an older Compose fixture omits labels. One-off jobs remain
visible through repeated expansion, but cannot regain pending status from shared
or saved evidence, overwrite service metadata, or contribute to browser update
totals. Regular services retain their normal update behavior.

Compose checks neither read nor write Unraid's native image-update cache.
The plugin's saved per-container results are authoritative for Compose badges;
without them, details remain unchecked. Clear Update Cache affects only plugin
evidence. Regression tests preserve native cache bytes across checks, details,
and clearing, including legacy native entries.

Presentation metadata has its own transactional project/service store. Tests
interleave unrelated publications, newer collectors, deletion/recreation, and
explicit clearing; stale collectors cannot resurrect removed metadata. Readers
and all writers wait on the same inode lock. Corrupt storage is preserved and
reported until explicit clearing. Both metadata endpoints expose the existing
project/service map, not the internal ownership envelope.

Mutation regressions use real canonical-project locks and deterministic epoch
changes: busy checks defer before Docker enumeration, checks spanning operations
cannot publish evidence or metadata, and old acknowledgements cannot consume
renewed marks. Shell cases cover success, failure, interruption, failed boundary
bookkeeping, canonical/folder identity, and ordinary Up/down/stop preserving
their no-new-mark contract. Detail collection spanning a mutation cannot commit
stale presentation metadata. Browser tests cover visible metadata-load failures,
retaining the previous snapshot, and successful empty-cache responses.

Single-stack and Check All update checks use each stack's attached registry
credential for that registry only, including Docker Hub aliases. Other
registries retain Unraid's normal authentication. Check All keeps credentials
scoped per stack even when stacks use different accounts at the same registry.
Repository-only git credentials cannot be used as registry logins. Missing or
unreadable attached credentials are logged and leave the check unknown; rejected
registry authentication also leaves it unknown rather than up-to-date.

The update-check tests exercise Basic and Bearer authentication against a local
fixture registry with synthetic credentials, including rejected credentials,
registry/account isolation, and both update-action entry points. No real private
registry credentials or external registry access are required.

Run the focused PHP regressions and isolated browser tests without a live Unraid server:

```bash
vendor/bin/phpunit --no-coverage --no-logging tests/unit/ContainerInfoTest.php tests/unit/DockerUpdateTest.php tests/unit/UpdateCheckTest.php tests/unit/ExecActionsTest.php
cd tests/e2e
npx playwright test tests/compose-update-state.spec.ts --project chromium
```

The scheduled auto-update runner also checks running containers against their current image tags after pulling, including tags pulled earlier by another process. Its focused shell regressions run with `bats tests/unit/compose.bats`; stopped stacks remain stopped.

The browser regressions cover known/unknown merges, image changes, incomplete-check presentation, polling retries after request/parse failures, failed action dispatch, and runtime-state ordering during row refresh.

### Update UI Pre-PR and Integration Gates

Build-metadata inspection enables all profiles with `config --no-consistency`
because mutually exclusive profiles may legitimately reuse container names.
This is read-only inspection, not permission to deploy conflicting profiles;
deployment retains normal Compose validation. Parse/resolution failures still
fail explicitly. `GitStackInfoTest` covers this distinction.

Check All isolates per-stack build-configuration failures: its `partial`
response carries successful `stacks` and a stack-keyed `failures` map. Failed
stacks retain saved evidence and pending rechecks; only their rows show failure.
Shared runtime-enumeration and persistence failures still abort the request.

Run the update browser suites together, without a live host or saved credentials:

```bash
cd tests/e2e
npm test -- tests/compose-update-state.spec.ts tests/compose-update-evidence-flow.spec.ts tests/compose-update-layout.spec.ts
```

These execute production frontend functions using isolated DOM/transport adapters:

- The Node fixture (`node tests/fixtures/update-ui.cjs`, also run by update Bats)
  deterministically completes details/check responses in both orders for published
  and build-base images. Fresh unknown results must win over older browser history.
- Browser evidence flows check readiness through check, expand, collapse, re-expand,
  and a real browser reload using mocked server-owned evidence. Recreating the
  application image during an in-flight check must not attach old-image evidence
  to the replacement, even when its service/name/tag are unchanged.
- PHP endpoint regressions cover Docker ps returning a short application image ID
  while inspection returns its original tag. The exact pending-base case runs the
  real layer comparison with a fixture remote base, persists the Rebuild result,
  then repeatedly reads saved status and expanded containers. Both must retain
  pending state, build-base kind, readiness and image evidence without rechecking.
- Layout tests cover light/dark theme rules, long image references, 1/2/18-service
  stacks, desktop/phone viewports, and a reduced 854x480 viewport approximating the
  available CSS space at 150% desktop zoom. This is not a native browser-zoom test.
  The fixture reproduces SweetAlert's inline `display:block` content style.
  Service scrolling must not move the footer or make the dialog itself scroll.
  At constrained heights the content body may also scroll to expose options;
  that scroll must likewise leave the footer fixed outside the content.

The adapters do **not** substitute for running the complete page and the actual
Unraid SweetAlert library together. Retain the following live-host gates:

- [ ] Confirm checkbox defaults and confirmation payload, Cancel/Escape without
  dispatch, keyboard focus/Tab behavior, and reopening details after Changelog.
- [ ] Verify actual light/dark themes and native browser zoom, including footer
  visibility and keyboard access through long service lists.
- [ ] Check normal-image and build-only stacks, a stopped sibling, mixed replicas,
  and a long background build. Checks must remain read-only; updates must mutate
  only eligible existing services. A terminal reconnect alone is not proof of a
  command timeout: inspect the last command log and operation result on failure.

After rebasing `fix/ui-runtime-consistency` onto the merged update branch, add
combined-code tests rather than copying the other branch's runtime owner here:

- [ ] Cross runtime-request generations with update-check revisions in both
  response orders. Stale runtime data must not replace newer runtime state, stale
  evidence must not replace newer checks, and neither guard may discard valid
  independent changes owned by the other.
- [ ] Queued/running dispatch receipts must not imply completion. Completed
  actions must reconcile affected stacks and invalidate obsolete evidence even
  after terminal close/reconnect; failed completion must never imply success.
- [ ] Update stack A while checking stack B. Preserve B's progress, evidence,
  and expanded/collapsed state.
- [ ] Failed dispatch, runtime refresh, and update checks must release busy
  controls, retain truthful status, and permit retry.
- [ ] Run both branches' update-state, runtime-consistency, and operation-state
  suites together, followed by canonical `bash test.sh`.

PR handoff: describe the update-evidence/runtime-reconciliation ownership boundary,
the above deferred gates, and actual test scope in the PR body. The last checked
merge preview against `fix/ui-runtime-consistency` conflicted in
`composeManagerMain.js`, `Helpers.php`, and `ExecActionsTest.php`; this is a snapshot,
not a guarantee about future tips. Resolve deliberately, preserving both freshness
guards and completion receipts. A clean textual merge is not a behavior test.

### Navigation and Docker Visibility

`bats tests/unit/navigation.bats` runs production JavaScript functions with
deterministic transport/DOM adapters. Coverage includes single, Recreate, bulk
start/stop/update and Dashboard action terminals; plain and JSON-string terminal
URLs; explicit close callbacks; and a still-locked operation completing after
early close. Close must refresh without clearing busy state or cancelling polls.
Dashboard coverage also exercises its direct Shadowbox fallback and globally
accessible callback.
The post-operation runtime regression replaces both container IDs, checks DOM
and jQuery metric membership, and follows health from starting to healthy (or
unhealthy) through real targeted refresh callbacks. Health polling must stop
when settled and must not multiply when close and completion both refresh.

Docker visibility tests run without any inline Compose table. They cover delayed
Docker table insertion, row replacement, removed stacks, case-sensitive names,
other plugins' visibility classes, coalesced requests, and explicit error reporting.
PHP tests render the filter bootstrap for all eight header/page-hide/dashboard-hide
combinations and exercise the real names endpoint with saved project identities,
running/stopped/job containers, unrelated projects, empty runtime, and failures.

Live-host gates (not replaced by these adapter tests):

- [ ] On the actual Unraid modal, close a running single/bulk operation early;
  no page navigation occurs and completion still refreshes affected rows.
- [ ] Verify Dashboard close and Recreate, including failed operations.
- [ ] Save both header-menu and Docker-hide settings, reopen settings, then visit
  Docker and Compose. Only managed containers disappear from Docker; Compose
  stacks remain visible. Check both tabbed and tabless Docker layouts.
- [ ] Reload the Docker table after creating/removing a container and verify
  filtering remains correct. Confirm Dashboard hiding remains independent.
Run `tests/compose-runtime-consistency.spec.ts` alongside
`tests/compose-update-state.spec.ts` in the same Playwright invocation to cover
out-of-order runtime responses, invalidated detail prefetches, recreated container
identities, empty action responses, and metrics publisher recovery. The broader
rollout and live-host validation gates are in the
[UI runtime consistency plan](ui-runtime-plan.md).

The runtime suite also covers superseded list metadata/rows, deletion during
progressive loading, coalesced detail refreshes, collapsed-row failures, and
prefetch waiters invalidated by actions.

### UI stabilization live-testing checkpoint

Exercise out-of-order Dashboard list/detail responses and early terminal close
followed by completion. Repeated actions must retain the original operation
owner. Restart a container whose rendered fields do not change: its spinner
must clear and menu actions become available again. Interrupt a starting-health
snapshot and confirm polling resumes, then stops when health settles or the
stack is removed. With many expanded stacks, detail refreshes must not reorder
unrelated tables; metrics and column preferences must remain intact.

For `Pull/Build & Up`, verify a never-created stack and an entirely stopped
stack, default-only and selected/all profiles, mixed published/build-only
services, registry credentials, foreground/background receipts, and configured
wait-for-health. Failed pull/build must not start containers. Compare ordinary
Compose Up and Update to confirm their existing scopes are unchanged.

Run all isolated UI stabilization regressions together:

```bash
cd tests/e2e
./node_modules/.bin/playwright test tests/compose-update-state.spec.ts tests/compose-runtime-consistency.spec.ts tests/compose-operation-state.spec.ts --project chromium
./node_modules/.bin/tsc --noEmit
```

Operation tests cover superseded dispatch/poll callbacks, progress reapplied to
replacement rows, bulk cleanup without clearing newer actions, rejected Update
All preparation, and malformed/empty/failed container responses. They also cover
queued/running/completed receipts, malformed or missing completion evidence,
foreground single/bulk dispatches, explicit nonzero exits, and completion updating
both parent state and expanded container details from the same fresh snapshot.
The isolated `compose-terminal-controls.spec.ts` exercises the production
terminal controller in both browsers: continuing without a stop request,
confirmed cancellation, completion-only Done, and failed-status retry.
`TerminalSessionTest.php` executes real disposable process sessions locally:
viewer termination leaves a command alive, nonzero exits and output are
preserved, cancellation stops nested commands and queued bulk receipts, and
altered process identity refuses signalling. It also checks the 1 MiB live-spool
limit against a byte-complete stack log, output-write failures, refused launches,
and accepted commands whose viewer cannot start. `terminal_session.bats` uses
a fake ttyd to test connected cleanup and the real 30-second connection deadline.
`UiOperationTest.php` verifies output/receipt retention and throttled housekeeping.
These local tests do not invoke Docker or real ttyd.
The host stabilization gate separately covers the installed terminal controls.

These tests control browser callbacks; they do not prove real Docker or ttyd
completion.

The navigation Node fixture also exercises Dashboard early close followed by
receipt completion through both native and fallback modals, rejected/malformed
dispatches, missing tracking evidence, transport failure, and nonzero exits.
Both Dashboard and Compose load the shared completion poller before their page
adapter. Verify this script ordering and final Dashboard reconciliation on Unraid.

`PendingRechecksTest.php` exercises the real shared/exclusive transaction locks,
concurrent successful marks, legacy timestamp entries, same-second renewals,
token-scoped acknowledgement, corruption preservation, and invalid input.
Endpoint tests verify the unchanged public timestamp map, additive image-check
token, strict acknowledgement errors, and explicit persistence failures.
The navigation recheck fixture checks stale responses after a newer action,
newer-check ownership, busy deferral, and observable read/acknowledgement errors.
No live Docker calls are needed for these regressions.

Publication-generation tests force newer-success, newer-failure, deletion,
clear, and same-name recreation between reservation and commit. Full-check tests
verify conditional pruning and preservation of unrelated newer results, including
empty scopes and prototype/numeric names. Endpoint tests invalidate a reservation
during fake Docker inspection and require an explicit superseded response.
Legacy evidence remains visible through the public reader after storage migration.

The guarded host suite additionally checks token renewal/acknowledgement on an
unstarted disposable stack and delays a browser-only saved snapshot while a real
scoped image check completes. It never runs Check All, clears global caches, or
checks existing application stacks. Candidate installation and rollback backup
require explicit permission before live validation.

The backend receipt regressions execute the actual shell wrapper with synthetic
commands, verify per-dispatch/per-stack isolation and exit status, test the receipt
endpoint, and verify opt-in foreground dispatch preserves legacy URL responses:

```bash
vendor/bin/phpunit --no-coverage --no-logging tests/unit/UiOperationTest.php tests/unit/ComposeUtilTest.php tests/unit/ExecActionsTest.php
```

Deploy the complete branch, including its image-update backend prerequisites.
From the repository root, the existing deployment helper builds a development
package, runs its normal checks, and installs it on the explicitly named host:

```bash
bash deploy.sh -Dev -RemoteHost YOUR_UNRAID_HOST
```

Use a noncritical test stack and save the currently installed package/version
for rollback. Do not use `-Quick` for a committed checkpoint: that option copies
only tracked staged/unstaged source changes, not committed branch differences.
After installation, hard-refresh the browser so it loads the new JavaScript.
No live deployment is performed by the isolated test command.

Check the following on the real host, recording browser console errors and
plugin logs if any row disagrees with Docker:

1. Start, stop, restart, and update a disposable stack, foreground and background.
   Confirm final parent/detail state without a browser refresh. For foreground
   actions, test both waiting for completion before Done and closing early.
   Progress should end when the finite command actually exits, not when the
   terminal URL arrives or the modal closes. A failed/interrupted command should
   report failure and reconcile whatever Docker state actually exists.
2. Collapse/expand details and reload the list during an operation. Progress
   must survive row replacement, and an older response must not roll state back.
3. Perform container start/stop/restart/pause/resume from its context menu.
   Check parent counts, detail status, and recovery after a failed request.
4. Recreate a container. Confirm the DOM uses its new ID and metrics follow it.
5. Hide/show the tab and metrics columns; reconnect after a connection loss.
   Confirm metrics resume without duplicated subscriptions or repeated restarts.
6. Exercise Start/Stop/Update All, including a failed stack and a slow first
   stack. A later queued stack must not be reported finished before it runs.
   Confirm every accepted stack completes independently and an older completion
   cannot clear a newer action's progress.
7. Delete a disposable stopped stack while a list reload is pending. It must not
   reappear from a late row response.
8. Recreate containers and deploy a disposable git stack from the main view.
   Check updated parent state, expanded details, and failure notifications.
9. Exercise **Follow stack logs** separately. Its existing attached-up/Done/detach
   path is intentionally unchanged and excluded from finite-command receipt
   tracking. Record startup/Done state mismatches for the remaining lifecycle
   follow-up rather than treating this mode as covered by finite-command tests.

The [runtime plan](ui-runtime-plan.md) lists the outstanding completion gates.
Automatic detection of changes made outside the plugin remains next-beta work
(#194); refresh explicitly when testing such changes.

### Archived Plugin Migration

The ordinary identity tests use temporary stack metadata and injected Docker evidence. Event integration cases execute real scripts against a temporary runtime and fake Docker; scheduler cases verify blocked migrations remain retryable. The opt-in real-Docker matrix creates random `cm183_` projects, then removes their test containers, networks and volumes. Its bind-data case uses a disposable Docker volume's host path, not a user directory. It requires Docker Compose and access to the `alpine:3.20` image.

Legacy directory discovery follows filesystem iteration order, not fixture creation order. Tests without runtime order evidence compare file membership; concurrent migration readers must also preserve the exact order stored in the migration receipt.

Run the focused PHP and shell regressions from the repository root:

```bash
vendor/bin/phpunit --no-coverage --no-logging tests/unit/ProjectIdentityTest.php tests/unit/InstallerCompatibilityTest.php tests/unit/AutoupdateRunnerTest.php tests/unit/AutoupdateTest.php
bats tests/unit/common.bats tests/unit/compose.bats
```

Run the isolated resource-continuity test:

```bash
COMPOSE_MIGRATION_DOCKER_TESTS=1 vendor/bin/phpunit --no-coverage --no-logging --group migration-docker tests/unit/ProjectIdentityTest.php
```

Run the isolated identity chooser test without a live server or authentication:

```bash
cd tests/e2e
npx playwright test tests/compose-ui-operation-flows.spec.ts --grep 'migration identity chooser' --project chromium
```

See [legacy migration](legacy-migration.md) for the compatibility contract and outstanding release gates.

## VS Code Integration

The workspace is configured with PHPUnit Test Explorer. After opening the project:

1. Open the Test Explorer panel (beaker icon in sidebar)
2. Tests will be automatically discovered
3. Click the play button to run tests
4. Failed tests show inline in the editor

## Test Structure

```text
tests/
├── framework/          # Submodule: plugin-tests framework
│   └── src/
│       ├── php/
│       │   ├── Mocks/      # Function & global mocks
│       │   ├── TestCase.php
│       │   └── bootstrap.php
│       └── bats/
│           └── helpers/    # BATS helper functions
├── unit/
│   ├── UtilTest.php    # PHP unit tests
│   └── compose.bats    # BATS shell tests
├── results/            # JUnit XML output (CI)
└── coverage/           # Code coverage reports
```

## Writing Tests

### PHP Tests

```php
<?php
use PluginTests\TestCase;

class MyTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Setup mocks
        $this->mockGlobals(['var' => ['NAME' => 'TestServer']]);
        $this->mockFunction('parse_plugin_cfg', fn() => ['key' => 'value']);
    }

    public function testSomething(): void
    {
        $this->assertEquals('expected', actual_function());
    }
}
```

### BATS Tests

```bash
#!/usr/bin/env bats

load '../framework/src/bats/setup.bash'

@test "function returns expected value" {
    result=$(my_function "input")
    assert_equals "expected" "$result"
}

@test "script handles errors" {
    run my_script --invalid-option
    assert_failure
    assert_output_contains "error"
}
```

## CI/CD Integration

The `.github/workflows/tests.yml` workflow runs on every push and PR:

- **PHP Tests**: Runs PHPUnit with coverage
- **BATS Tests**: Runs bash tests
- **Static Analysis**: Runs PHPStan

Test results appear in the GitHub Actions UI with proper reporting.

## Available Mocks

### PHP Function Mocks
- `parse_plugin_cfg()` - Config parsing
- `autov()` - Asset versioning
- `csrf_token()` - CSRF protection
- `notify()` - Notifications
- `syslog()` - Logging

### PHP Global Mocks
- `$var` - Unraid variables
- `$disks` - Disk information
- `$shares` - Share data
- `$dockerClient` - Docker API

### BATS Command Mocks
- `docker` / `docker-compose` - Container operations
- `logger` - System logging
- `notify` - Unraid notifications
- File operation helpers

## Code Coverage

Generate HTML coverage report:

```powershell
php vendor/bin/phpunit --coverage-html tests/coverage/html
start tests/coverage/html/index.html
```

Note: Code coverage requires Xdebug PHP extension.

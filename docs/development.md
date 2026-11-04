# Development Guide

This document describes how to set up a development environment, run unit and integration tests, and use the project tooling.

## Prerequisites

- PHP (match the project's platform requirements from `composer.json`)
- Composer (to install dev dependencies like PHPUnit and PHPStan)
- Git (for submodules)

## Setup

1. Clone the repository and install dependencies:

   ```bash
   git clone https://github.com/mstrhakr/compose_plugin
   cd compose_plugin
   composer install
   ```

2. Initialize submodules (integration tests):

   ```bash
   # If the submodule is configured in .gitmodules
   git submodule update --init --recursive

   # Or add it manually if you don't have it yet
   git submodule add https://github.com/mstrhakr/plugin-tests.git tests/plugin-tests
   git submodule update --init --recursive
   ```

3. Verify vendor binaries are available (e.g., `vendor/bin/phpunit`).

## Unit Tests

- Run all unit tests:

  ```bash
  php vendor/bin/phpunit --config phpunit.xml
  ```

- Run the unit test suite specifically:

  ```bash
  php vendor/bin/phpunit --config phpunit.xml --testsuite unit
  ```

- Run a single test file or method:

  ```bash
  php vendor/bin/phpunit --config phpunit.xml tests/unit/SomeTest.php
  php vendor/bin/phpunit --config phpunit.xml --filter testSomething
  ```

- Generate coverage (HTML):

  ```bash
  php vendor/bin/phpunit --config phpunit.xml --coverage-html tests/coverage/html
  ```

Coverage output is written to `tests/coverage/` by default (see `phpunit.xml`).

## Integration Tests (plugin-tests)

- The project uses a separate `plugin-tests` framework for system-level and integration tests. After initializing the submodule, read its README for environment and runner details.

- Typical flow:
  - Ensure the test environment (a test unRAID instance or VM) is available and configured per `plugin-tests` instructions.
  - Run the framework's test runner (refer to `tests/plugin-tests/README.md` for exact commands). Commonly these are shell scripts in the submodule such as `./bin/run-tests.sh`.

## Static Analysis

- Run PHPStan:

  ```bash
  composer run analyse
  ```

Adjust PHPStan rules or baseline as needed when adding new code.

## CI / Local tips

- Ensure `composer install` is part of CI job setup and that any required submodules are initialized.
- Cache `~/.composer/cache` and `vendor` between CI runs where possible to speed builds.
- Use `--filter` or PHPUnit groups to run a focused subset of tests while developing.

## Writing Tests & Contributing

- Add unit tests under `tests/unit` and integration tests to the `plugin-tests` submodule following its conventions.
- Keep tests small and focused; mock external systems where possible in unit tests.
- Update `phpunit.xml` if you add new suites or change coverage targets.

### Shared update pipeline

- `ContainerInfo` owns Compose service-label normalization for both Compose ps
  and Docker ps rows. Read-only stack checks exclude one-off jobs but retain
  regular service containers in every runtime state.

- Container check results expose `updateReadiness` (`current`, `download-required`, `ready-to-apply`, `rebuild-required`, `unknown`), `updateKind` (`image` or `build-base`), and `localImageSha` (local application tag ID for display). Existing `hasUpdate`/`updateStatus` remain compatible. Published-image readiness compares full container, local-tag, and registry config IDs before any display truncation; only a tag matching the remote target is ready to apply. Missing registry/container evidence is unknown. Build-base checks require a local replacement application image whose layers include the current remote base before reporting ready; merely pulling a base is insufficient. `remoteSha` in a `build-base` result denotes the base, not an incoming application image. Readiness is evidence from the last check, not a guarantee of build reproducibility or local source freshness.
- `StackUpdateService::checkStacks()` owns stack check orchestration, credential/build-context selection, result normalization, and saved stack status. Individual and Check All endpoints use it for all existing containers, regardless of state. The browser's periodic check uses the same Check All endpoint. `ComposeUpdateCheck` remains the lower-level image/base-image comparison service; UI icon resolution and presentation-cache enrichment remain outside it in the endpoint adapter. Checking never pulls, builds, or changes container state.
- Build-context resolution uses the bounded process runner, keeps Compose warnings
  separate from JSON, and reports command/JSON failures explicitly. An unavailable
  configuration must never be treated as a valid empty build map.
- A check workflow reuses one image checker per attached credential. Remote
  manifests and base configs are memoized only for that workflow, on instances
  bound to one credential, with platform-specific cache keys. Local container/tag evidence is
  always reread; remote failures are memoized too, avoiding repeated failed requests.
  No new disk cache is introduced.
- `StackUpdateService::buildUpdateCommand()` owns build/inclusion policy and operation preparation. Individual/force and bulk UI actions call it; cron and Auto-update **Run Now** use its `automaticUpdateInvocation()`. The CLI adapter uses `resolveUpdateSpec()` for the structured update specification. Update specs do not read deployment profile history or default profiles: runtime scope belongs to the locked engine. Profile history remains necessary for lifecycle UI and Git deployments. `ComposeCommandBuilder` resolves common stack arguments and encodes the service's resolved operation; it does not select update policy.
- `scripts/compose.sh update` owns credentials and the project lock, then invokes `scripts/compose_update.sh`. This is the single implementation of image pulling, optional rebuilding, running-image comparison, recreation, and post-success cleanup.
- `scripts/compose_autoupdate.sh` is only a notification/concurrency adapter. Do not add Docker operations or another stack lock there. Its legacy positional/environment invocation is retained for compatibility; application callers pass the shared builder's arguments.
- Under the project lock, the engine enumerates existing containers by canonical project label, excludes one-off jobs, resolves the actual Compose profile names, and targets only eligible services for pull/build/up. Profile names make deployed services resolvable; they never authorize starting every service in those profiles. Do not rely on saved/default profile selection as deployed scope or overwrite deployment profile history during an update.
- Automatic mode excludes stopped services and only recreates when selected container images changed. Manual mode reconciles configuration for selected services; force recreation is a separate flag. `StackUpdateService` resolves `UPDATE_INCLUDE_STOPPED_DEFAULT` (off when absent) and an optional per-operation `includeStopped` override. The individual/force and bulk endpoints accept explicit `includeStopped=1` or `0`; omission uses the global default. Automatic mode ignores both. The builder encodes manual inclusion as `--include-stopped`, permitting existing created/exited services, including mixed running/stopped replicas, to participate and start.
- Runtime metadata and image IDs come from one batched container inspection.
  Selection uses service maps rather than scanning every container per service.
  Automatic comparison reads one resolved Compose JSON service map (service
  selection also includes dependencies). Only selected targets are compared;
  build-only tags use Compose project/service naming, including compatibility mode.
  Each unique target image is inspected once.
  Manual reconciliation never requires an image-tag comparison.
- Paused, restarting, dead/removing services and mixed running/stopped replicas (without manual inclusion) are skipped with an explanation. Other eligible services can update. Explicit service targets, `up --no-deps`, and per-service `--scale` values preserve existing replica counts and prevent dependency or undeployed-service startup. Removed services are reported and skipped. Git-source deployments remain a separate transaction because they must also manage checkout and rollback.
- The engine reports `updated`, `unchanged`, `skipped`, `partial`, or `failed` through a private outcome file used by the adapter. `partial` means services were excluded for safety or are no longer defined; automatic updates issue an incomplete-update warning, not a success notification. Ordinary excluded stopped services do not trigger this warning. Stack operation results retain the existing success/failed JSON format.

Regression coverage: `UpdateCommandPathsTest.php` checks the real individual/force/bulk endpoints against the canonical builder; `AutoupdateTest.php` and `AutoupdateRunnerTest.php` check both automatic launchers. `bats tests/unit/update.bats` executes the real scripts with a mocked Docker executable, including build failures, already-pulled images, stopped stacks, locking, and cleanup. Extend these shared tests when changing update behavior.

The update-details dialog lists existing runtime containers rather than hypothetical services from a selected/default profile. Update actions bypass the profile selector because deployed runtime scope is authoritative. Both individual/force and bulk dialogs seed the include-stopped checkbox from the plugin setting; changing it does not save configuration. With action warnings disabled, the saved default is used directly. Bulk update can include fully stopped stacks when explicitly enabled. `tests/fixtures/update-ui.cjs` exercises the real frontend functions with lightweight UI/transport stubs, invoked by the Bats suite when Node.js is available.

The isolated Playwright layout tests render the real update-dialog markup and CSS
against conflicting host-theme rules, without contacting an Unraid server. Run
`cd tests/e2e && npm test -- tests/compose-update-layout.spec.ts` after installing
the existing E2E dependencies and browsers. They check column overflow, image-ID
alignment, independent list scrolling, sticky headers, visible controls, and
dialog dismissal for small/large stacks on desktop and phone viewports. The host
library's inline content display must not disable the flex body: only the service
list may scroll, not the action footer. Screenshots
are saved in the ignored E2E test results directory for visual inspection.
The details table reuses the main view's `--dynamix-tablesorter-thead-th-*`
and `--dynamix-tablesorter-tbody-row[-alt]-bg-color` theme variables for opaque
headers and alternating rows. Keep table spacing reset and the entire header
group sticky so scrolling rows cannot peek through above individual header cells.
See [update UI integration gates](testing.md#update-ui-pre-pr-and-integration-gates)
for the response-order/recreation regressions and deferred combined-branch tests.

`javascript/composeUpdateStatus.js` owns readiness labels and compact badges for
the main table, expanded containers, dialogs, and dashboard. Both page entry
points load it before their renderer. Stack summaries show a single pending
state or a total with a tooltip breakdown; dialogs label application and base
digests separately rather than comparing unlike image IDs with an arrow.
Main-table and dashboard stack summaries use the same presentation helper.
Fresh unknown check results retain matching pending-update history centrally
under the saved-status lock, but invalidate local readiness evidence. The returned
result is the persisted result: browser merges must not resurrect discarded history.
Image-reference, pin, name and known container-image changes invalidate history.
For build-base checks, use container inspection's `Config.Image` as the application
reference for local-tag lookup and persisted evidence. Docker's ps output may
instead show a short image ID after the tag moves; persisting that display value
causes the expanded endpoint to reject otherwise valid evidence as a tag change.
Expanded rows read this per-container evidence, not the service-keyed metadata
cache. Saved evidence for an older container image is not reapplied after recreation.
Pinned application images do not query mutable tags or contribute actionable updates.
Bulk failures include structured server errors, empty replies, and invalid JSON.
`StackUpdateService` also owns saved-status reads, merges, removals and clears.
Readers take a shared lock; writers take an exclusive lock on the same file.
Clear writes an empty result without unlinking the lock's inode. Invalid saved
JSON is reported explicitly; a full replacement check or cache clear can recover
it with a warning. Endpoint and dashboard adapters must use the service to access
this file. The service-keyed container cache stores metadata only, never update evidence.
Legacy metadata-cache evidence is stripped when loaded and dropped on the next
save. Reading legacy saved checks also excludes pinned results from actionable
update totals, without requiring the user to run another registry check first.
Main-table and expanded-container cells share explicit checking/failure state.
Successful checks repaint expanded update cells directly without another details
request. Details responses use server-owned evidence; only a check completed
after a details request began may override it. Prefetches carry the same revision,
so expanding a stack cannot restore a pre-check response or discard a newer check.
Update/force dialogs use a scoped, responsive SweetAlert class and a three-column
service/readiness/image-ID table. Other lifecycle dialogs retain their layout.
The compact update dialog omits the large warning icon but retains the textual
warning and explicit confirmation. Narrow screens allow horizontal table scrolling.

## Troubleshooting

Update checks resolve application references from container inspection before
registry batching, because Docker's container list may show an image ID after a
tag moves. Only services without `image:` use Dockerfile-base checks. A failed
registry lookup for an explicitly named application image remains unknown,
preserving matching pending evidence rather than replacing it with base evidence.
Legacy automatic invocations retain `COMPOSE_PROJECT_DIR` alongside explicit
files as an actual Compose `--project-directory` argument.
The wrapper canonicalizes that directory after changing into it, so relative
workdirs are not resolved a second time by Compose.
Dockerfile scanning consumes all heredoc bodies before interpreting subsequent
instructions. Image-backed named build contexts are resolved to their image
reference; service/directory contexts remain unknown. A digest-pinned base is
current without a mutable registry check, but has no remote base config digest
unless one was actually resolved (the application ID is never substituted).
Base checks retain the originating stage's `FROM --platform`, including global
ARG expansion and the automatic BUILDPLATFORM/TARGETPLATFORM defaults. Compose
service `platform:` supplies the default target and is also used for published
buildable application checks. Manifest and base-layer caches are keyed by both
image and platform. Missing architectures/variants and unresolved platforms
remain unknown; no unrelated architecture is selected as a fallback.
Non-host platform checks stay in canonical stack evidence and do not overwrite
Unraid's shared image-status cache, which has no platform dimension.

For local deployment after validating the current code, use
`bash deploy.sh -Dev -RemoteHost <host> --skip-tests` to build and deploy without
repeating the pre-build test suite. `-SkipTests` and `--SkipTests` are aliases.
Tests still run by default; this option does not skip packaging or installation.
`-SkipBuild` instead reuses an existing package and already bypasses build tests.

- If vendor binaries are missing, re-run `composer install`.
- If integration tests fail with environment errors, confirm the plugin-tests environment variables and the unRAID test instance are correct.

## Release Process

Releases are fully automated — merging code triggers the entire build-and-release pipeline.

### Flow

```text
PR merged to main or dev
  → tag-release.yml: generates date-based tag, updates PLG changelog, pushes tag
    → build.yml: builds TXZ, creates GitHub Release, updates PLG version/MD5
```

### Branches

| Branch | Channel | Tag example |
| ------ | ------- | ----------- |
| `main` | Stable | `v2026.03.15`, `v2026.03.15a` |
| `dev` | Beta | `v2026.03.15-dev.1430` |

### Day-to-day workflow

1. Develop on `dev` (or feature branches merged into `dev`).
2. Each merge to `dev` automatically creates a beta pre-release.
3. When the beta is validated, open a PR from `dev` → `main`.
4. Merging the PR to `main` automatically creates a stable release.

### Bot commit loop prevention

Three workflows push commits back to `main`/`dev`. Each guards against re-triggering the others:

| Workflow | Commit message | Guard |
| -------- | ------------- | ----- |
| `tag-release.yml` | `chore: update changelog for vX.Y.Z [skip ci]` | Skips `[skip ci]`, `Release v*`, and bot actor |
| `build.yml` | `Release vX.Y.Z [skip ci]` | Only runs on tag push (not branch push) |
| `sync-plugin-url.yml` | `chore: sync pluginURL ... [skip ci]` | Skips `[skip ci]` and `Release v*` |

### Manual builds

Use **Actions → Build & Release Plugin → Run workflow** to trigger a test build without creating a tag or release. Specify a version string or leave empty for a dev snapshot.

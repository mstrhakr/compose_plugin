# Testing Guide for Compose Plugin

This document describes the testing infrastructure for the Compose Manager plugin.

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

### Image Update State and Action Refresh

Known image-check results replace older results, including `hasUpdate: false` and cleared digest fields. Unknown results can retain a previous check only for the same image reference; changing a tag must not inherit another tag's badge. Clearing the update cache removes update fields from the persistent container metadata without removing icons or runtime metadata.

An image without a conclusive check is shown as **unchecked**, not **up-to-date**. Incomplete checks log whether the local or remote config digest was unavailable. Each container's actual image ID is compared with the remote config digest, so pulling a tag without recreating the container cannot hide its pending update. Registry checks are batched by image reference, but replicas are compared separately. The checker compares platform-specific image config digests, so a changed multi-architecture index alone does not imply an update for this host. Digest-pinned image behavior is unchanged.

Plugin-written shared-cache entries include `configDigest`, bound to the remote
config digest value. Runtime refreshes compare the actual container image ID
only when that value matches `remote`. Unmarked native/legacy entries retain
their saved verdict instead of comparing manifest digests to config IDs. A native
writer replacing `remote` invalidates the provenance marker. Run a fresh update
check to establish config-digest provenance for older entries.

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

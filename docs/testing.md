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

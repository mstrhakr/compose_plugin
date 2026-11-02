# Development Guide

This document describes how to set up a development environment, run unit and integration tests, and use the project tooling.

For the staged runtime-state, action, and live-metrics architecture, see the
[UI runtime consistency plan](ui-runtime-plan.md).

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

## Troubleshooting

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

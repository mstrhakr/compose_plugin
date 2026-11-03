# Repository validation

Use the repository's existing validation entry point, `test.sh`, rather than
reconstructing a narrower set of checks. See [the development guide](../docs/development.md)
for project setup and architecture.

## During development

- Run the smallest relevant PHPUnit and Bats tests while iterating.
- For focused PHPUnit runs, use `vendor/bin/phpunit --no-coverage <test paths>`.
  Coverage is not needed for routine feedback; `test.sh --phpunit` currently
  enables coverage.
- When extracting shared code, validate its callers as well as the extracted
  module. Passing tests or lint on the new file alone is insufficient.

## Before each code commit

- Commit completed, validated work in coherent slices as you go, before
  providing the final summary. Do not leave completed changes uncommitted
  unless the user asks you to. Commit instruction changes first when they
  accompany implementation changes.
- Do not push commits without the user's approval.
- Run relevant behavior tests and the full applicable lint check:
  - Shell changes: `bash test.sh --shellcheck`.
  - PHP changes: `bash test.sh --phpstan`.
  - Changes spanning both: `bash test.sh --phpstan --shellcheck`.
- ShellCheck must cover the entire scripts directory through the canonical
  runner, not just selected files. `bash -n` is an additional syntax check,
  not a substitute for ShellCheck.
- Run relevant Bats tests for shell behavior changes. Use
  `bash test.sh --bats` when shared script changes affect multiple operations.
- Run fast lint before expensive test suites so failures surface early.
- Do not commit with failures introduced by the change. Fix source-following
  and caller integration problems rather than broadly suppressing warnings.
- Documentation-only changes do not require code tests.

## Before review handoff

- Run `bash test.sh` against the final code state. A full suite is required at
  this milestone, not after every edit or small commit.
- After subsequent fixes, repeat the affected lint and tests; repeat the full
  suite when changes have broad behavioral impact.
- Check the runner's output: it can skip checks when tools are missing.
  A skipped check is not a pass.
- Report exactly which checks ran, their scope, and any failures, skips, or
  known baseline warnings. Do not describe partial lint as full validation.

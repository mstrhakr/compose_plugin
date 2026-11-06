# Repository guidelines

Use the repository's existing validation entry point, `test.sh`, rather than
reconstructing a narrower set of checks. See [the development guide](../docs/development.md)
for project setup and architecture.

## Maintainability and ownership

- Treat maintainability as a requirement of every change, especially large
  refactors, not a cleanup phase afterward. Prefer clear ownership and fewer
  independent decisions over more files, classes, or layers.
- Search for existing implementations before adding another. Shared workflows
  should have one authoritative implementation; callers may select explicit
  policies without duplicating the underlying sequence.
- When extracting a service, move its responsibilities out of the old callers.
  Do not add a facade over duplicated logic or leave competing policy owners.
- Keep stack description/resolution, operation policy, execution, and UI
  presentation responsibilities distinct. Enforce runtime safety under the
  operation lock rather than trusting an earlier snapshot.
- Prefer the simplest design that fully addresses the task. Avoid giant
  catch-all classes, speculative frameworks, unnecessary renames, and unrelated
  architectural cleanup.
- Preserve intentional behavior differences and public contracts. Ask focused
  questions before resolving ambiguous lifecycle, scope, or profile semantics;
  do not turn a proposal into an assumed requirement.
- Where practical, separate code movement/consolidation from behavior changes
  in commits. Test behavior and consistency across entry points, not merely
  that one wrapper calls another, and document where future changes belong.

## During development

- Run the smallest relevant PHPUnit and Bats tests while iterating.
- For focused PHPUnit runs, use `vendor/bin/phpunit --no-coverage <test paths>`.
  Coverage is not needed for routine feedback; `test.sh --phpunit` currently
  enables coverage.
- When extracting shared code, validate its callers as well as the extracted
  module. Passing tests or lint on the new file alone is insufficient.

## Reviewing changes and feedback

- Validate each finding against the implementation, then inspect callers,
  sibling workflows, and tangentially related paths for the same failure mode
  and nearby correctness issues. Keep that expansion evidence-driven; do not
  turn a focused fix into unrelated cleanup.
- Review maintainability alongside correctness: DRY, SOLID ownership boundaries,
  existing shared helpers, and algorithmic complexity. State Big-O for affected
  hot paths and check repeated I/O, N+1 work, unbounded resources, and races.
- Before fixing, identify the existing contract: response shapes, failure
  behavior, lifecycle/ownership, scope, and intentional caller differences.
  Preserve it and test related entry points; ask before changing semantics.
- Report confirmed defects separately from design concerns, with locations,
  concrete fixes, and regression evidence. Commit fixes in validated slices.
- For asynchronous or persisted state, first state the end-to-end invariant and
  enumerate every reader, publisher, invalidator, and acknowledgement path,
  including CLI, scheduled work, other tabs, and external owners.
- Trace ownership through server publication and cleanup, not just browser
  callback rejection. Test deterministic interleavings across checks, mutations,
  failure, deletion/recreation, and acknowledgement boundaries.
- Before adding another local guard, check whether ownership belongs in a shared
  boundary. A lock coordinates only participants that actually use it.
- Distinguish reproduced guarantees from assumptions and residual limitations.
  Recheck the whole invariant after tightening one path so incremental fixes do
  not leave sibling paths with incompatible contracts.

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

# BOU: Rebuild-on-update preference

**Status:** Draft / awaiting owner review

**Recovered:** 2026-11-09

**Baseline and historical coverage:** [Source register](sources.md)

No requirements in this draft have owner approval yet. No application code,
tests, or runtime behavior were changed to produce it.

## Purpose and boundary

Let a user choose whether stack updates proactively build application images
from their local build definitions, instead of assuming every service with a
`build:` section should be rebuilt.

This contract covers **one preference from storage through editor round trip
to update-command preparation**:

1. The global default.
2. The per-stack preference.
3. Reading, displaying, and saving that preference.
4. Resolving it to a boolean.
5. Passing that boolean to individual, force, bulk, and automatic updates.

It does not specify the complete update engine, service eligibility, Docker
build reproducibility, base-image detection, scheduling, credentials, or Git
deployment. Adjacent execution behavior is described only where needed to
prevent a misleading interpretation of this preference.

**Important:** "Do not proactively build" is not currently an absolute "never
build." Manual updates have a documented compatibility fallback after certain
pull failures. Force recreation is not permission to override build policy.

## Ownership and entry points

Paths below are relative to the repository root.

| Responsibility | Existing owner / evidence |
| --- | --- |
| Shipped global default | [`default.cfg`](../../source/compose.manager/default.cfg): `BUILD_ON_UPDATE_DEFAULT="false"` |
| Global checkbox and serialization | [`compose.manager.settings.page`](../../source/compose.manager/compose.manager.settings.page): global checkbox; `serializeSettingsForm()` submits string `true`/`false` to the host settings form |
| Global persistence dependency | Unraid's settings form/update infrastructure; not implemented or independently verified by this plugin-only trace |
| Stack checkbox and help text | [`ComposeManager.php`](../../source/compose.manager/include/ComposeManager.php): `settings-build-on-update` |
| Editor load, dirty tracking, save acknowledgement | [`composeManagerMain.js`](../../source/compose.manager/javascript/composeManagerMain.js): `loadSettingsData()` / `saveSettings()` and checkbox change handling |
| Stack settings API | [`Exec.php`](../../source/compose.manager/include/Exec.php): `getStackSettings` / `setStackSettings` |
| Preference resolution and update policy | [`StackUpdateService.php`](../../source/compose.manager/include/StackUpdateService.php): `resolveBuildOnUpdate()` / `buildUpdateCommand()` |
| Argument encoding | [`ComposeCommandBuilder.php`](../../source/compose.manager/include/ComposeCommandBuilder.php): `buildUpdateCommand()` adds `--build` when resolved `build` is true |
| Individual, force, and bulk adapters | [`ComposeUtil.php`](../../source/compose.manager/include/ComposeUtil.php) and [`Helpers.php`](../../source/compose.manager/include/Helpers.php) use the shared service |
| Scheduled and Run Now adapters | [`AutoUpdateRunner.php`](../../source/compose.manager/include/AutoUpdateRunner.php) and [`AutoUpdate.php`](../../source/compose.manager/include/AutoUpdate.php) call `automaticUpdateInvocation()` |
| Execution handoff | [`compose_autoupdate.sh`](../../source/compose.manager/scripts/compose_autoupdate.sh) forwards prepared arguments; [`compose.sh`](../../source/compose.manager/scripts/compose.sh) consumes `--build`; [`compose_update.sh`](../../source/compose.manager/scripts/compose_update.sh) owns execution |

The standalone [`compose_args.php`](../../source/compose.manager/scripts/compose_args.php)
exports a structured update specification, not the fully policy-resolved update
command. Direct shell and legacy automatic invocations do not independently
resolve this preference from plugin configuration. Do not promise preference
parity for those callers merely because they use the same execution engine.

## Current data and response contracts

### Persisted data

- Global key: `BUILD_ON_UPDATE_DEFAULT`, conventionally a string.
- Per-stack file: `<stack metadata directory>/build_on_update`, not the external
  Compose source directory.
- A missing or whitespace-only regular file means inherit the global default.
- A trimmed nonempty regular file is an override: exactly `true` or `1` enables;
  every other nonempty value currently disables.
- The resolver enables the global default only for the exact string `true`.
  Global `1`, boolean `true`, and uppercase `TRUE` do not enable it.

These are observed parsing rules, not approval of permissive malformed-file
handling or inconsistent normalization.

### Resolution table: observed

`G` means the supplied global config value is exactly the string `true`.

| Per-stack file after trimming | Global disabled or absent | Global enabled |
| --- | ---: | ---: |
| Missing / empty / whitespace-only | false | true |
| `true` or `1` | true | true |
| `false` or `0` | false | false |
| Other nonempty text, including `TRUE` | false | false |

`resolveBuildOnUpdate()` returns a PHP boolean. It has no writes, Docker calls,
or configuration migration. It reads the complete override file once when it
is a regular file. It does not apply the global setting back into that file.

### Settings API and browser

These shapes describe this field within the existing endpoints, not every
unrelated field:

- `getStackSettings`: on success, JSON has `result: "success"` and
  `buildOnUpdate: boolean`. That boolean describes an explicit enabled override,
  **not** the effective inherited value and not a three-state preference.
- `setStackSettings`: the browser posts `script` and string `buildOnUpdate`.
  The handler lowercases and trims the supplied value. Omission defaults to
  `false`; this is not PATCH-like behavior.
- `true` / `1` writes literal `true` to the override file.
- `false` / `0` / empty removes the override file if present; it does not persist
  an explicit false override.
- Other nonempty posted strings currently leave the file unchanged without a
  field-validation error; the larger save can still report success.
- Successful settings save responds with `{"result":"success","message":"Settings saved"}`.
  Caught save errors respond with `result: "error"` and a message and are logged.
- The browser sends the field with the grouped settings payload, even when a
  different grouped setting caused the save. Changing only this checkbox also
  triggers that payload, preserving the fix in [#168](https://github.com/mstrhakr/compose_plugin/pull/168).
- On acknowledged success, the browser updates its original value and clears
  the dirty flag. Explicit server errors, malformed nonempty responses, and
  network errors follow the existing save-error path. This trace does not
  establish robust handling of every empty or structurally invalid response.

## Round trips and contradictions: observed, not approved

| Starting state / action | What currently happens | Why review is needed |
| --- | --- | --- |
| Global enabled; no override; open editor | Checkbox is unchecked; effective update preference is true | UI suggests a different effective behavior from execution |
| Global enabled; uncheck and save | File is absent; effective preference remains true | Unchecked cannot force disable |
| Global enabled; explicit file `false`; save grouped settings | API reads checkbox false; browser posts false; server removes file; next resolution is true | Saving a different grouped field can change effective rebuild policy |
| Explicit override true; API caller omits this field while saving settings | Handler defaults to false and removes it | Partial callers can erase a preference |
| Invalid nonempty stored value | Resolver returns false | Malformed data is accepted as meaningful policy |
| Invalid nonempty posted value | File can remain unchanged while endpoint reports success | A successful response need not mean the requested preference was applied |

The first three consequences follow directly from the reader/writer trace.
They were not reproduced on a live Unraid host during this task.

## Temporal behavior and side effects

**Candidate end-to-end invariant:** a preference the UI acknowledges as saved
must survive a load/save round trip without silently changing its meaning, and
all plugin-managed update entry points must encode the same resolved preference.
The current inherit/false mismatch prevents claiming this invariant today.

Readers, publishers, and acknowledgements:

- The global settings page displays/submits the global value. The host owns its
  persisted publication; callers supply a config snapshot to the policy service.
- The stack API reads the override; the browser retains a local checkbox snapshot.
- `setStackSettings` writes/removes the override while holding the canonical
  project's exclusive operation lock and rechecks project identity after locking.
  It snapshots settings files and attempts restoration on runtime write failure;
  restoration failure is included in the error. This is not a crash-safe database
  transaction or an assurance that every related side effect is rolled back.
- `getStackSettings` and the preference resolver do not acquire that lock.
- Command preparation reads the preference before shell execution acquires its
  operation lock. The shell consumes the encoded boolean; it does not re-read
  global or per-stack preferences.
- A successful stack save clears `StackInfo`'s process cache. The resolver itself
  has no preference cache. This is not a cross-tab refresh or acknowledgement.
- Another browser tab, global-setting change, direct filesystem editor, backup
  restore, or administrator action may change stored data. This pilot establishes
  no revision check, cross-tab broadcast, or shared locking guarantee for those
  external writers.
- Settings acknowledgement is separate from an update operation's completion.
  Saving the preference must not itself pull, build, recreate, or schedule a run.

A setting changed after a command is prepared does not rewrite that command.
Whether that snapshot behavior is intended remains decision D4 below. The
setting alone does not grant authorization or change runtime service eligibility.

## Candidate requirements for approval

All rows are **Proposed**. "Aligned" means supported by inspected code, not a
newly executed test or formal approval.

| ID | Candidate requirement | Current assessment |
| --- | --- | --- |
| BOU-01 | The shipped and missing global default is disabled. A valid per-stack choice takes precedence over that default. | Aligned for supported stored inputs |
| BOU-02 | The preference has explicit `inherit`, `enabled`, and `disabled` meanings; users can select all three, and the UI distinguishes stored choice from effective behavior. | Storage resolution supports three meanings; API/UI do not; depends on D1 |
| BOU-03 | Loading and saving a setting preserves its meaning unless the user deliberately changes it. A grouped save of another setting must not erase an explicit false override. | Not satisfied by the traced round trip |
| BOU-04 | Omitting this field in a settings request preserves it. A deliberate inherit operation is distinct from omission. | Not satisfied; depends on D2 |
| BOU-05 | Invalid preference values and unreadable stored preferences produce explicit errors rather than silently selecting a policy or acknowledging success. Valid legacy encodings have a documented migration/compatibility rule. | Not consistently satisfied; depends on D3 |
| BOU-06 | `StackUpdateService` is the single preference-policy owner for plugin-managed individual, force, bulk, scheduled, and Run Now update commands. The builder only encodes its boolean. | Aligned by inspected callers |
| BOU-07 | The prepared update argument list includes `--build` if and only if the resolved preference is true. Force recreation and automatic mode do not independently enable it. | Aligned at the shared command boundary |
| BOU-08 | Changing only this preference must be saveable. Dirty state is cleared only after a valid success acknowledgement; failed saves retain a retryable edit and show an error. | Checkbox-only dispatch is present; error paths partly inspected; complete acknowledgement validation unverified |
| BOU-09 | Preference resolution is read-only. Saving it changes preference state, not containers or the execution of already running updates. | Aligned for the scoped field; shared settings infrastructure can have other side effects |
| BOU-10 | Save failures are explicit; project lock contention or changed identity cannot be reported as a successful write. Failed grouped writes attempt restoration and disclose restoration failure. | Implemented by shared save boundary; field-specific failure interleavings not verified |
| BOU-11 | Manual compatibility fallback is distinguished from proactive build preference. The UI/docs must not promise that disabled means "never build" unless that fallback policy changes. Automatic mode must not acquire manual fallback implicitly. | Existing user guide and engine distinguish these; UI wording needs review under D5 |
| BOU-12 | Preference timing is documented, deterministic, and tested when preparation overlaps saves or global-setting changes. | Current behavior is a preparation-time snapshot; intended contract unresolved under D4 |

Approval of BOU-02 through BOU-05 would authorize behavior/API/UI changes only in
a later implementation task, not as part of this document.

## Acceptance and regression matrix

These cases define how to validate the proposed boundary. Existing tests below
were inspected, **not executed** for this documentation-only task.

| Case | Expected outcome / requirement | Existing evidence or gap |
| --- | --- | --- |
| No override, global absent/false/true | false / false / true; BOU-01 | [`ComposeProjectDirectoryArgsTest.php`](../../tests/unit/ComposeProjectDirectoryArgsTest.php): `testBuildOnUpdateDefaultsToGlobalSetting` |
| Override true/false against opposite default | Override wins; BOU-01 | Same file: `testBuildOnUpdateStackOverrideWinsOverGlobal` |
| Empty override with global enabled | Inherits true | Same test covers empty, but not all whitespace/legacy/error cases |
| Normal, force, and automatic commands with global enabled | All encode build; force only adds recreation policy; BOU-06/07 | [`ComposeCommandBuilderTest.php`](../../tests/unit/ComposeCommandBuilderTest.php): `testUpdateVariantsShareAllResolvedArguments` |
| Explicit false override with global enabled | No `--build` | Same builder test covers automatic mode; expand across entry points |
| Individual, force, and bulk endpoint commands | Shared command shape; BOU-06/07 | [`UpdateCommandPathsTest.php`](../../tests/unit/UpdateCommandPathsTest.php); existing matrix varies stopped inclusion, not all build preferences |
| Scheduled and Run Now launchers | Forward resolved build preference; BOU-06/07 | [`AutoupdateRunnerTest.php`](../../tests/unit/AutoupdateRunnerTest.php) and [`AutoupdateTest.php`](../../tests/unit/AutoupdateTest.php) assert `--build` with enabled overrides |
| Change checkbox only; Apply; reopen | Persist intended selection; BOU-08 | #168 historical live test and present JS path; dedicated behavior regression not identified in this trace |
| Global true with each per-stack choice | UI shows choice/effective value; save/reopen preserves both; BOU-02/03 | Needed after D1 |
| Explicit false; edit another grouped setting; Apply | No false-to-inherit conversion; BOU-03 | Needed; current trace predicts failure |
| Missing field versus deliberate inherit | Missing preserves; inherit removes override; BOU-04 | Needed after D2 |
| Legacy `1`/`0`, whitespace, uppercase, garbage, read failure | Approved normalization/error behavior; BOU-05 | Needed after D3; must not merely encode today's silent fallback |
| Save busy, identity changes, write/restore failure | Explicit error; no false success; BOU-10 | Shared helpers implement checks; require field-specific failure/interleaving cases |
| Server error, empty/malformed success, network failure | Dirty edit retained and error visible; BOU-08 | Add focused browser/transport cases; do not infer coverage from UI source inspection |
| Prepare command, change preference, then execute | Behavior matches selected timing policy; BOU-12 | Needed after D4 |
| Preference disabled; ordinary successful manual pull | No proactive build | [`update.bats`](../../tests/unit/update.bats): `successful manual pull does not rebuild published buildable images without opt-in` |
| Preference disabled; manual pull failure versus automatic failure | Preserve explicit mode difference; BOU-11 | Same suite: `manual pull fallback preserves support for local build tags` and `automatic updates do not enable build fallback when rebuilding is disabled` |

Avoid relying on source-string grep tests alone: they do not prove the
saved-state round trip or what an executed command does. Add behavioral cases
to existing PHP, browser, and Bats suites rather than a parallel test framework.
Use the canonical runner and existing lint requirements when implementation
work begins; live-Unraid settings persistence remains a separate integration
check.

## Resource and failure limits

For an override of B bytes, resolution is O(B) time and O(B) memory, with one
regular-file check and at most one full file read. Conventionally B is tiny;
there is currently no explicit size bound. Command encoding adds O(1) work for
this preference; repeated resolution across N stacks adds O(N) file reads,
not per-container or registry calls.

A failed `file_get_contents()` currently emits a PHP warning and is cast to an
empty string, allowing the resolver to inherit the global default. Whether the
host converts warnings to exceptions is environment-dependent; the resolver
does not itself establish a reliable error contract. A path that is not a
regular file also falls through to the global default. Do not describe those
cases as verified safe disablement.

No exactly-once save, crash recovery, atomic global-and-stack snapshot, bounded
external editor participation, or protection against stale browser settings is
claimed. These limitations matter even if all existing tests pass.

## Decisions for the owner

| ID | Decision | Recommendation, not an assumption |
| --- | --- | --- |
| D1 | Does a stack need inherit / explicitly enable / explicitly disable, or is unchecked intentionally inherit? | Expose three states, matching the resolver's existing precedence and preventing ambiguous false round trips. |
| D2 | Is `setStackSettings` a partial-update API for this field, or does omission intentionally reset it? | Preserve omitted fields; make inherit explicit. Assess compatibility before changing the endpoint. |
| D3 | Which legacy inputs remain supported, and what happens for malformed/unreadable data? | Retain documented `true`/`false` and `1`/`0` file compatibility; explicitly reject invalid new requests and block command preparation on unreadable/malformed policy. Define migration before rejecting existing malformed files. |
| D4 | Is the effective preference fixed when preparing the operation, or re-resolved under its execution lock? | First confirm whether queued actions should retain the user's submitted policy. Do not silently change timing. |
| D5 | Should disabled mean "no proactive rebuild, manual fallback still allowed" or absolute "never build"? | Preserve documented manual fallback for compatibility unless explicitly changed; make the distinction visible rather than silently broadening automatic mode. |

## Approval record

No decisions or requirement IDs approved yet. Record accepted wording and date
here after the pilot review. Implementation and verification remain separate
follow-up work.

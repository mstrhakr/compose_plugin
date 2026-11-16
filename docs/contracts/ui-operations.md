# UI-OPS: Action acceptance, completion and reconciliation

**Status:** Draft / awaiting owner review. No whole-contract approval.

**Baseline:** 2026-11-10, `e2760a6`, clean worktree before research.
Documentation-only extraction; no runtime or test changes.

**Main backport:** copied from dev commit `a07a944` for hotfix #203.
References to contracts not yet present on main remain plain requirement IDs
or titles; their definitions and the relationship ledger remain on dev.
Approvals and historical evidence below are preserved, not newly verified by
this backport. The bounded housekeeping validation is recorded separately.

## Purpose and scope

Tell the user what happened to the action they requested without confusing
request acceptance, execution, terminal connection, runtime health or fresh
image evidence. Track each accepted stack action through its own completion
or an explicitly uncertain outcome, independently of whether its viewer is open.

Owns dispatch-specific receipt semantics, browser response ownership, bulk
acceptance accounting and the handoff to runtime refresh/image rechecks.
Owns neither the actual mutation policy nor a general job scheduler, runtime
health evaluator, output terminal, or rollback system. The terminal boundary
is described only as needed to establish operation independence and cancellation.
UI-TERM remains a separate unextracted candidate.

**Proposed end-to-end invariant:** only evidence belonging to the current
dispatch may settle its operation indicator. Viewer closure, connection failure,
an unlocked project or an accepted HTTP request cannot establish that queued
work ran or completed. Completion must trigger fresh reconciliation, not assert
the desired container state. Image-check acknowledgement consumes only its
own accepted evidence, never completion or viewer-close evidence.

## Evidence and intent

Independent reports are weighted ahead of owner-authored historical prose,
per the owner's direction. A user's requested outcome is distinct from their
suggested design, and neither a merged PR nor a passing test is owner approval.

| Source | Intended outcome / evidence limit |
| --- | --- |
| [dcflachs/compose_plugin#31](https://github.com/dcflachs/compose_plugin/issues/31), brandonocasey | Explicitly requests start/stop/update that survives navigation or local network problems, with optional log inspection that does not stop work. This supports execution/viewer independence; it does not approve unlimited retention or a particular reattachment UI. |
| [#142](https://github.com/mstrhakr/compose_plugin/issues/142), castor-homelab | Reports long Up/pull failing with terminal socket errors after 10-15 seconds. The reporter later says intervening releases appear to have fixed it. Supports completing long work and distinguishing connection failure; does not establish a universal 15-second command deadline or root cause. |
| [#56](https://github.com/mstrhakr/compose_plugin/issues/56), FudiHub | Reports Start All/Stop All doing nothing and ttyd/proxy failures; follow-up confirms both buttons affected. Demonstrates that a bulk click/viewer is not proof of executed work. No reporter confirmation of a specific fix in the retrieved discussion. |
| [#156](https://github.com/mstrhakr/compose_plugin/issues/156), Barrydamonke | Requests an action dialog or explicit warning instead of an invisible dialog and locked scrolling for include/profile configuration. Confirmation/preparation must not silently disappear. Detailed profile-resolution rules stay with STK-PROFILES. |
| [#154](https://github.com/mstrhakr/compose_plugin/pull/154), brandonocasey, merged | Fixes the action dialog disappearing because the prior profile dialog closes after its callback. Independent contribution supports ordered dialog handoff, not a new operation-completion signal. |
| [#196](https://github.com/mstrhakr/compose_plugin/pull/196), owner-authored, plus inline review | Implementation rationale and concrete review cases: unlocked queued bulk work, early-close acknowledgement, stale Dashboard refresh and cancellation across process groups. Review findings were compared with current code/tests; owner responses are implementation history, not independent endorsement. |
| [#61](https://github.com/mstrhakr/compose_plugin/issues/61), [#197](https://github.com/mstrhakr/compose_plugin/issues/197), [#200](https://github.com/mstrhakr/compose_plugin/issues/200), owner-authored | Attached-output requests/proposals and an intermittent viewer investigation. #197 describes deferred scope, while current code already has native-detach controls: record the historical mismatch, do not infer approval or a supported general reconnectable attached-terminal product from either. #200 is not a proven current root cause. |

[Runtime plan](../ui-runtime-plan.md#release-scope) explicitly separates
execution, viewer controls and retained output. The
[user guide](../user-guide.md#checking-for-updates) and
[README](../../README.md#features) still describe completion using lock release
in places. Tracked receipt paths are stronger than that shorthand; legacy
paths remain weaker and must not be represented as equally verified.

At pinned archived revision `074a9af3a29d14b346e5dae8a700a0649cda601c`,
the original browser's Up/Down/update paths opened the returned viewer URL;
the dispatcher launched the command as ttyd's child on a shared action socket,
without per-dispatch receipts in those paths. This is historical explanation
for the independence request, not a compatibility mandate to keep that coupling.

Current code, callers and tests were traced below. Retained current/legacy
issue/PR bodies were screened; selected reports and #196 inline comments were
refreshed, not every historical discussion. Available forum excerpts yielded
no additional verified UI-OPS rule. Fresh requests to both support threads
returned no content. No Discord history was accessed.

## Dependencies and consumers

The relationship ledger (on dev) indexes both
ends. Linked sketches remain unextracted, not silently approved dependencies.

| Relationship | Owner / consumer | Required integration |
| --- | --- | --- |
| Depends on | STK-ID, STK-FILES | Resolve dispatch inputs and accepted runtime identity. Receipt keys use managed folder identity; that is not the canonical Docker project lock identity. |
| Depends on / shares boundary | STATE-LOCK-05/06/10/11 | A receipt is not a lock, epoch or check reservation. Only accepted checks can acknowledge pending marks; deletion/identity changes retain shared coordination limitations. |
| Consumes execution result | UPD-RUN-11/12, OPS-LIFE, OPS-BULK, GIT-DEPLOY | Preserve dispatch scope, exit status, per-stack failures and operation-specific partial/skipped outcomes (OPS-BULK-04/06). UI must not redefine command success. |
| Shares boundary with | UI-TERM, OPS-PROCESS | Execute independently of the viewer; validate cancellation ownership and wait for actual owned work. Viewer retries cannot redispatch the mutation. Output, PTY, attachment and retention details belong there. |
| Shares boundary with / consumed by | UI-RUNTIME | Operation start invalidates older projections; completion requests fresh data and health may settle later. Replacement rows retain the existing action owner; no new dispatch is created by rendering. |
| Depends on | UPD-CHECK, UPD-STATE | Publish accepted image evidence separately, retain pending checks while locally busy, and acknowledge only the accepted token. Consume UPD-CHECK-08/10 result distinctions; UI-OPS participation does not independently own pending-state semantics. |
| Depends on | API, SUPPORT-NOTIFY | Preserve host request protections and explicit error responses. Background notification policy must not be inferred from merely closing a foreground viewer. |
| Consumed by / shares boundary | STK-LIFE-07/08 | Truthful removal/partial-failure responses and local invalidation do not cancel accepted work or prove server-side exclusion across same-name recreation. |
| Consumed by | OPS-LIFE, OPS-BULK, GIT-DEPLOY, UI-TERM, UI-RUNTIME | Compose-page single/Force/bulk, Git deploy and Dashboard stack controls participate where tracked. Interactive consoles, ordinary log followers, cron and direct CLI commands do not automatically become tracked UI jobs. |

Editor save ownership remains STK-EDIT, not another
consumer of operation receipts. Similar stale-callback problems do not imply
that editor openings and command dispatches have the same lifecycle.

### Recommended UI-OPS / UI-TERM / OPS-PROCESS ownership

Clarified 2026-11-10 at the owner's request; proposed boundaries do not approve
the current class layout or prescribe another layer:

- **UI-OPS:** acceptance, per-dispatch receipts, response ownership, truthful
  completion/uncertainty and reconciliation handoff. UI-OPS-01 through 12 remain
  stable participation requirements.
- **UI-TERM:** output/viewer attachment, reconnect, native detach and retained
  output presentation. Reopening output never redispatches work. Still a sketch,
  not a fully extracted terminal contract.
- **OPS-PROCESS:** owned command execution, argument/output/exit preservation,
  process identity, PTY/process supervision and safe cancellation mechanism.
  Lifecycle/update owners decide permitted effects and partial outcomes.
- **UPD-STATE:** authoritative evidence/pending acknowledgement semantics.
  UI-OPS-05/09 participate; neither terminal closure nor receipt completion can
  consume an image-check obligation.

Current terminal-session supervisor and dispatch helpers mix several mechanisms.
Assign one authoritative workflow per responsibility during future alignment;
do not add a facade over competing implementations or merge contracts merely
because code shares a file.

## State and response contract

The following is **observed/recovered**, not wholesale approval.

### Distinct identities and states

| Concept | Meaning / forbidden inference |
| --- | --- |
| Local browser action owner | A distinct object per action and managed stack. Old callbacks cannot clear, warn for, open a viewer for or refresh over its replacement. Not a cross-tab mutex. |
| Per-stack receipt | Managed-folder key plus random 32-lowercase-hex dispatch ID. Persisted outside the stack directory. `queued`, `running`, or `completed` with integer `exitCode`. Not the stack's global last result. |
| Managed terminal session | Separate 32-hex receipt for the session supervising one command/batch and output. One bulk session can contain several stack receipts. Session completion and an individual stack's completion are different events. |
| `queued` | Receipt created, wrapper not yet started. A later bulk stack can be queued while completely unlocked. |
| `running` | Tracking wrapper started; may still wait for the background folder queue or canonical project lock. Does not prove that Docker mutation has begun. |
| `completed`, exit 0 | This wrapper finished successfully at process level. Does not prove container health, a new image, full domain success, or fresh update evidence. |
| `completed`, nonzero | Command, cancellation or another required wrapper phase failed. Runtime effects can already exist. Managed cancellation conventionally uses 143; launch/abandoned-queue failure can use 1. |
| Confirmation unavailable | Missing/malformed receipt, transport failure or observation deadline. Not a synthesized completed/failed execution result. |

Finite tracked actions post `trackOperation=1`. Successful foreground dispatch
returns `{url, operations:{folder:id}}`, with a plugin-local managed terminal URL.
Background dispatch returns `{background:true, operations:{folder:id}}`.
Bulk responses can also contain `failures:{folder:message}` for configuration
skips. Identity/configuration refusals use error/message responses; they are not
terminal URLs. A confirmed terminal launch failure can return
`{error:"terminal", message, operations}` with unstarted receipts failed.

Once work is accepted, viewer/startup uncertainty can instead return
`{background:true, warning:true, message, operations}`. Keep tracking; do not
retry the mutation just because the viewer is unavailable. Acceptance itself
does not guarantee the supervisor will survive a subsequent server failure.

`getUiOperation` requires the stack folder and exact ID and returns
`{result:"success", operation:{...}}` or explicit error/message. The server
validates the state vocabulary; the browser additionally requires an integer
exit code before accepting completed. A missing or malformed receipt cannot
fall back to unlocked-project success when an ID was supplied.

### Dispatch, refusal and bulk sequencing

- Successful prerequisite preparation comes before dispatch. Update, Force
  Update, Update All and image-preparing Up require a persisted post-operation
  recheck mark; ordinary Up does not add that UI prerequisite. Empty, malformed,
  failed or refused preparation must show an error and prevent dispatch.
  A saved mark followed by a later dispatch refusal can leave a harmless pending
  recheck; do not consume it as if an update completed.
- Track local progress during dispatch. A refusal clears only its own local
  owner. Transport failure or invalid response warns the user to check whether
  work started; the server may have accepted it before the response was lost.
  Automatic redispatch would risk duplicate execution.
- Bulk generates one receipt for each dispatched stack before sequential
  execution. Configuration failures have no successful execution to await.
  Eligible stacks continue after another stack's command failure. Foreground
  session status aggregates failures; per-stack receipts preserve individual
  codes. This is not an atomic batch or rollback transaction.
- Managed bulk cancellation affects the active command and remaining queued
  commands, not already completed stacks. Originally requested background
  batches use their existing background runner, not this managed Stop control.
- Main-page responses require the current local owner before changing its
  state. Dashboard stack controls additionally suppress a duplicate local
  stack request while one is tracked. UI guards do not replace server locks.

### Observation and refresh

Tracked polling asks for the exact receipt, not the most recent stack result.
Queued/running stays busy even when the project lock is free. Missing/invalid
evidence or poll transport failure is logged and retried; it is not completion.

The shared poller starts after two seconds, uses a 30-second AJAX timeout and
nominal 2/5/15-second delays as observation age passes 60/300 seconds. It stops
after approximately 30 minutes and warns that the operation may still run.
This is an observation deadline, not command cancellation or an exact wall-clock
execution bound. The current UI releases its local progress indicator then and
requests a refresh; runtime ownership remains governed on the server.

On accepted completion, clear only the matching local owner, visibly report
nonzero status, and request a fresh runtime snapshot. Refresh parent/detail
projection from accepted current data; do not overwrite newer actions/checks
with stale results. Health readiness and ongoing refresh remain UI-RUNTIME /
OPS-HEALTH responsibilities, not reasons to extend a completed command receipt.
Closing a viewer also requests presentation refresh, but does not clear
operation ownership or drain/acknowledge pending image checks.

Legacy responses containing only a terminal URL and untracked background
responses remain compatibility paths. Main-page legacy background polling may
use `checkStackLock`; legacy foreground paths queue refresh without a tracked
receipt. Dashboard stack controls require receipt evidence instead. These
differences are observed compatibility, not proof of the stronger invariant.

### Viewer, cancellation and attached Up boundary

Finite managed commands run in an owned process session before a viewer
connects. Closing the finite viewer or choosing **Continue in background**
does not cancel it. This is not conversion into the originally requested
background notification workflow. **Done** follows completed status; a nonzero
session code remains visible. Viewer connection/retry/fallback state is separate.

**Stop command** asks for confirmation explaining the dispatched command and
whole-batch scope, not a guessed execution phase. It signals the validated owned
supervisor; it is not a direct Docker Stop/Down operation and does not undo
already-applied effects. Cancellation waits for live owned descendants across
process groups/PTY sessions, attempts TERM and escalates after a nominal
10-second grace period before finalizing remaining receipts. PID reuse or
changed session/start-time/command identity must refuse control. These are
Linux process-ownership guarantees, not control over all possible external
daemon work started by the command.

Attached Up is different: Follow stack logs overrides background and wait-for-
healthy for that invocation without rewriting saved settings. Its receipt stays
active while attached. Current native **Detach** writes only `d` to the owned
PTY after Compose advertises support; successful final session evidence is
required before closing. Unsupported/failed/timed-out detach leaves the viewer
open and never silently substitutes Stop. Stop warns attached Up can stop
containers. Full-tab navigation cannot reliably request detach. General
interactive reattachment and historical suspended-process handling are not
upgraded to approved guarantees by this extraction.

### Image evidence and acknowledgement

Operation start invalidates the Compose page's older image-check owner and
runtime response generation. Checks requested while locally busy remain pending;
the deferred drain rechecks ownership before starting. Completion refreshes
runtime and reads the server's pending queue. Accepted checks publish their
result separately and then send the exact `recheckToken` to acknowledge it.

Acknowledgement failure warns that the result arrived but the pending request
could not be cleared; it retains retryable pending state. It must not turn
received image evidence into a failed check or clear a newer mutation's mark.
Obsolete check/ack callbacks cannot change another action's state. Server-side
epochs/reservations still protect other tabs, CLI and scheduled mutations; this
local busy map does not know all external operations.

## Participants, persistence and cleanup

| Participant | Publisher / reader / invalidator role |
| --- | --- |
| Compose-page single/Force/bulk and Git helper dispatch | Prepare command/marks, allocate per-stack queued receipts, dispatch once and return IDs/refusals. Bulk keeps configuration skips distinct. |
| Dashboard stack controls | Submit tracked Start/Stop/Restart and poll the shared receipt model; viewer close requests a tile refresh. Inline container controls have a separate in-flight request guard and response validation, not these asynchronous receipts. |
| Receipt wrapper | Atomically replaces queued with running, then completed/code on exit. During managed cancellation it defers finalization to the supervisor. |
| Managed terminal supervisor | Publishes separate PID/start-time session identity, owns cancellation, finalizes remaining queued/running stack receipts and its session after owned work ends. Output capture failure can fail the session independently of an already finished stack command. |
| Original background runner | Waits on a per-folder queue, executes the canonical wrapper, captures command log and sends operation notifications. It has no managed terminal session merely because it has stack receipts. |
| Shared poller / main-page action owner / Dashboard owner | Read receipts, reject obsolete callbacks, report failures/uncertainty and request fresh presentation. They do not publish Docker success or acknowledge image freshness themselves. |
| UI-RUNTIME / update-check coordinator | Reconcile current snapshots; manage local busy-aware checks and server token acknowledgements. Server stores own publication/acknowledgement integrity. |
| Deletion/recreation | Local deletion purges action/check owners and invalidates runtime generations. Old callbacks become obsolete locally. Server receipt IDs are not stack-incarnation IDs or a lifecycle lock; deletion does not itself cancel accepted work. |
| Other tabs, cron, CLI and Docker users | May mutate without this browser's action owner or any UI receipt. Locks/epochs/check publication coordinate only participating paths. No automatic cross-tab job discovery/resumption is established. |
| Receipt housekeeping | Activity-driven on new receipt creation, nonblocking housekeeping lock, at most once per minute. Queued/running receipts and their output survive. Completed output/diagnostics expire after one hour and receipts after 24 hours; elapsed time alone does not trigger a scheduled sweep. Only canonical receipt filenames are candidates, not viewer JSON sidecars. |

### Receipt-housekeeping hotfix evidence (#203)

Implementation baseline `c07864d`, 2026-11-16. The reported failure was reproduced
by an expired receipt with its real `.json.viewer.json` sidecar: housekeeping
deleted the sidecar while processing its parent, then attempted to read the
deleted sidecar from the original glob result. No concurrent writer was needed.
This exception occurred before new receipt allocation/dispatch, affecting all
shared tracked callers, not only Update.

The shared housekeeping boundary now filters canonical receipt names, reads
content and age from one open file (publishers replace receipts atomically),
preserves queued/running work, and expires the connection acknowledgement with
other completed output. Disappearance before opening or during removal is
harmless; a remaining target that cannot be read/removed still fails explicitly.
No new command retry, inferred success, cancellation or retention setting.

Regression coverage in [UiOperationTest](../../tests/unit/UiOperationTest.php)
uses production-format receipt names and tests self-deleted viewer metadata,
unrelated/orphan auxiliary JSON, queued/running preservation beyond 24 hours,
completed output/receipt retention, throttling, simulated disappearance between
enumeration and opening, and persistent removal failure.
On dev, focused receipt/terminal tests passed: 36 tests, 157 assertions. Full
`bash test.sh` completed without failures: PHPUnit reported 1,470 tests and
7,878 assertions (8 skipped, 1 deprecation); configured PHPStan, full-script
ShellCheck and all 189 Bats tests passed. No runner stage was skipped.
The eight PHPUnit skips cover missing resvg/GD, four opt-in Docker migration
cases and an unwritable Unraid icon-fixture path. The unrelated existing
`htmlspecialchars(null)` deprecation in
[ComposeList.php](../../source/compose.manager/include/ComposeList.php) was
confirmed with `ComposeListEndpointTest` (2 tests, 7 assertions, 1 deprecation).
Changed-document links/anchors, requirement-ID uniqueness and inventory counts
also passed validation.

Main backport validation, 2026-11-16: baseline `d878de4`, with main's pinned
test framework `5aed44f`. The receipt implementation, relevant dispatch/viewer
callers and focused tests matched the dev hotfix baseline before application;
the resulting PHP implementation and receipt tests match `a07a944` exactly.
Focused receipt/terminal tests passed again (36 tests, 157 assertions).
Full `bash test.sh` completed without failures: PHPUnit reported 1,442 tests
and 7,532 assertions (the same 8 environment/opt-in skips and 1 deprecation);
configured PHPStan, full-script ShellCheck and all 188 Bats tests passed.
No runner stage was skipped. Local links/anchors and requirement-ID uniqueness
passed for the backported contract and updated index. Dev-only dependencies
are retained as plain references, as in the earlier STATE-META backport.

Enumeration remains O(N) in directory entries at most once per minute; filename
filtering precedes file reads. This is not a general orphan-sidecar collector,
a stale queued-job timeout, or complete viewer/cleanup synchronization.
External manual directory removal, crashed supervisors and reconnection racing
expired-output cleanup retain their existing lifecycle limits; no live-host
acceptance or broader receipt-protocol fulfillment is claimed by this fix.

Receipts live in a private temporary directory with folder-hashed filenames and
random IDs. Atomic rename helps readers observe complete records; this is not
fsync-backed durability, reboot recovery or an immutable audit log. A crashed/
hard-killed wrapper can leave running evidence indefinitely; no automatic
orphan repair/lease is established. There is no client completion acknowledgement
that removes the operation receipt: image acknowledgement is a different API.
The mutable stack command log can be overwritten by a later action.

UI-TERM's current live spool is capped at 1 MiB and saved-output requests at
64 KiB with generation/cursor checks. Single-stack full logs are separate;
managed bulk supplies no full stack-log destination to its session capture,
so do not promise complete retained bulk output. Running receipt retention is
not a bounded total storage budget.

## Requirements and acceptance evidence

Rows 01-12 remain proposed except the bounded owner approvals of UI-OPS-05/07/10 below;
later numbered decisions record other narrow approvals.

| ID | Observable requirement | Enforcement / acceptance evidence | Gaps / limits |
| --- | --- | --- | --- |
| UI-OPS-01 | Account separately for refusal, accepted dispatch and uncertain transport; never automatically redispatch uncertain work. | Real helper/endpoint PHPUnit; single/bulk refusal and malformed-response browser fixtures. | Lost acceptance response can leave the client without receipt IDs; no idempotent redispatch/recovery protocol proven. |
| UI-OPS-02 | Bind each tracked stack action to its own receipt; queued/running cannot complete from unlocked-project evidence. | UiOperationTest, ExecActionsTest; unlocked+queued, missing/malformed/integer-exit browser cases. | Legacy lock-only paths do not fulfill the stronger guarantee. |
| UI-OPS-03 | Only the current action owner may settle progress, open its viewer, warn for its response or apply its completion refresh. | Out-of-order dispatch/failure/completion and superseded bulk-skip fixtures; replacement-row test. | Ownership before the shared preparation callback and server lifecycle transitions is not exhaustively demonstrated by these dispatch tests. |
| UI-OPS-04 | Track each bulk stack through actual execution or explicit non-dispatch; continue eligible siblings after failures and expose partial acceptance. | Single/bulk helper, sequential receipts, configuration-skip browser and managed cancellation process tests. | Not atomic; no general background launch acknowledgement/repair demonstrated for every shell-script write/launch failure. |
| UI-OPS-05 | Viewer close/failure/retry must not cancel accepted finite work or establish completion. | Owner-approved option A, 2026-11-11: closing output for finite operations lets accepted work continue independently; stopping requires a separate explicit action. Existing process/viewer fixtures are historical evidence, not fresh verification. | General reattachment/tab recovery remains unextracted; accepted supervisor can still crash. Attached Up/follow lifetimes remain separate. Help/warning presentation is not approved by this choice. |
| UI-OPS-06 | Stop requires explicit scoped confirmation and validated process ownership; do not finalize cancellation while owned commands still run or start queued siblings afterward. | PID/start-time refusal, unrelated-process, timeout/PTY descendant drain and 10-second escalation process tests; browser command-specific confirmation. | Not rollback, direct stack Stop, external-daemon cancellation or a universal exactly-10-second completion promise. |
| UI-OPS-07 | Lost contact or timeout without an established execution result means unknown/unable to confirm, not command failure, success or termination; never automatically rerun accepted work merely because confirmation was lost. | Owner-approved bounded option A, 2026-11-11; shared poller, terminal status/error fixtures and exact-code receipts are historical evidence. | Recovery, notification presentation and orphan handling remain parked. Poll errors mainly log/retry in main/Dashboard; no fresh runtime verification or isolated accelerated 30-minute end-to-end recovery proof in this pass. |
| UI-OPS-08 | Reconcile accepted fresh runtime state on completion without declaring readiness or freezing the close-time snapshot. | Browser parent/detail same-snapshot test; current response-generation trace. | Full health retry/rendering contract remains UI-RUNTIME/OPS-HEALTH; no live-host verification here. |
| UI-OPS-09 | Require successful persisted recheck preparation on covered image actions; keep checks pending while locally busy and acknowledge only accepted evidence. | Preparation refusal fixtures, update-check ownership/interleaving browser cases; UPD-STATE-06/07/08 owns semantics, STATE-LOCK supplies ordering. | Marks and dispatch are not one transaction; outside callers rely on server epochs/pending policy. |
| UI-OPS-10 | Interactive readable output is sufficient without additional interpretation; background/automated summaries must distinguish known incomplete or failed work from success, using explicit operation results rather than parsing command output. Any displayed summary must not overstate what is known. | Owner-approved narrowed option A, 2026-11-11; Update's explicit outcome file and background/automatic adapters inspected. | Foreground receipts contain state/code, not Update's domain outcome; this alone is not a requirement for a new foreground warning. No universal structured outcome protocol, new notification frequency or fresh runtime verification approved. |
| UI-OPS-11 | Make receipt/output retention and missing evidence explicit; never fabricate completion after deletion, expiry or restart. | Private receipt validation and housekeeping tests; local deletion/check-generation fixtures. | No server stack-incarnation binding, reboot recovery, stale-running repair or cross-tab operation enumeration proved. |
| UI-OPS-12 | Keep attached follow, native detach and cancellation distinct; detach failure must leave work alone and remain visible. | Attached receipt/native-input process tests; browser button/X/early-close guard and unsupported/timeout/failure cases. | Full attached UX/product scope still needs explicit validation; #197's historical deferred label is not approval of current implementation. |

**UI-OPS-13 (owner-approved, 2026-11-10):** keep the local-base rebuild choice
visible but disabled when explicit configuration forbids it, explaining the
actual blocking setting and how to return that decision to manager policy.
Acceptance: explicit `build.pull: true` shows a disabled, explained choice;
manager-default freshness permits an explicit retry choice after failure.
Execution must independently revalidate permission. No implementation or
accessibility verification claimed.

### Approved reporting foundation

Owner-approved bounded option A, 2026-11-11, **UI-OPS-07**:
loss of contact with accepted work without an established result means
unknown/unable to confirm. It does not establish failure, success or termination
and must not trigger automatic re-execution merely because confirmation was
lost. This approves the uncertainty boundary also proposed in UI-OPS-01, not all
of that row's acceptance/transport requirements. Recovery mechanisms, orphan
handling and presentation remain parked.

Owner-approved narrowed option A, 2026-11-11, **UI-OPS-10**:
readable command output suffices for interactive detail; no requirement to
parse Docker output or duplicate it in a new foreground summary. Background
and automated summaries must distinguish known incomplete/failed work from
success using explicit operation results. Any summary already shown, including
an interactive one, must not claim more than its evidence establishes.

Current Update execution already writes an explicit outcome consumed by
`compose_background.sh` and `compose_autoupdate.sh`. Background Update distinguishes
partial/skipped outcomes; automatic Update warns for partial/failure, reports
updated and stays quiet for unchanged/skipped. These are observed caller
differences, not approval of a universal notification policy. The earlier broad
proposal for distinct foreground domain warnings is narrowed, not an authorized
implementation gap. No new parser, result schema or notification workflow is
required by this decision.

### Approved finite-operation viewer independence

On 2026-11-11 the owner approved UI-OPS-05's finite-operation boundary, noting
that dev has moved away from terminal-close interruption toward independent
execution. Current documented process isolation and the terminal's Continue in
background control support that observation; no live reproduction or new tests
were performed for this approval. Historical interruption was described by the
owner as a "sorta rollback"; do not promise transactional rollback on cancellation.

Closing output neither cancels work nor proves completion/success. It also does
not automatically enroll work into the separately requested background
notification workflow. Attached Up and following-log sessions retain their
separate policies; finite-operation approval does not settle their close behavior.

The owner subsequently approved lightweight disclosure as UI-OPS-25, while
again directing the review away from presentation details and back to foundations.

**UI-OPS-25 (owner-approved, 2026-11-11):** explain finite-viewer independence
through brief accessible inline help/control wording, without routine close
confirmation popups. Integrate with the existing Stop command and Continue in
background controls rather than assume a separate warning workflow.
Exact text, placement and desktop/mobile/keyboard acceptance checks are parked
for later UI review. No new notification or attached-session policy is approved.

### Approved confirmation advanced options

**UI-OPS-14 (owner-approved, 2026-11-11):** place newly added optional controls
in confirmation dialogs inside an Advanced options section, rather than grow
the always-visible control list. This generalizes the earlier Update-dialog
direction to confirmation dialogs across features; it does not authorize new
options, alter their semantics or require every action to have a dialog.

**UI-OPS-15 (owner-approved, 2026-11-11):** initialize that Advanced options
section collapsed on each new confirmation dialog. Expansion is presentation,
not consent, and collapsing a section does not silently reset option values.
Specific option defaults remain owned by their capability contracts.

These are separate presentation requirements, not approval of a particular
widget or of relocating all existing controls. Scope/action summaries, required
inputs, warnings and consequences are not automatically optional controls to
hide; the earlier requirement to keep consequences clear remains intact.
Accessible expansion and mobile/keyboard behavior still require acceptance
review and executed evidence. No runtime or accessibility verification claimed.

Consumed by OPS-LIFE-16/17
and OPS-BULK-22
for the new force-recreate control. Other confirmation dialogs consume these
shared presentation rules when optional controls are added, without acquiring
the lifecycle capability's defaults or execution policy.

### Approved competing operation warnings and revalidation

Owner decision, 2026-11-11: allow an explicitly acknowledged competing manual
action rather than categorically refuse it because queued/running work exists.
Record independent guarantees, not one combined catch-all rule:

- **UI-OPS-16:** for a known queued or running operation against the same
  accepted runtime project, disclose that conflict and require explicit
  acknowledgement before accepting a competing manual action. This applies to
  individual and bulk-origin work across sessions, not only this tab's local
  busy state. A future warning-bypass policy is not approved by this rule.
- **UI-OPS-17:** explicitly warn that acknowledgement does not guarantee when
  the competing action executes relative to existing work, and that ordering
  can cause failures or an unexpected final deployment state. Do not promise
  FIFO, cancellation, replacement or priority.
- **UI-OPS-18:** recheck relevant operation/conflict state after user
  confirmation; an initial menu/click-time check is insufficient.
- **UI-OPS-19:** recheck again at the server dispatch boundary just before
  spawning the script. Neither an earlier acknowledgement nor browser snapshot
  is an execution-lock grant. If confirmation and dispatch are distinct
  boundaries, both must participate.
- **UI-OPS-20:** expose contention or acquisition failure occurring after
  acknowledgement, including another session winning the race. Never report
  launch/approval as successful execution, silently bypass ownership or
  automatically redispatch uncertain work.
- **UI-OPS-21 (owner-approved, 2026-11-11):** if a pre-dispatch recheck discovers
  an additional competing operation not covered by the user's acknowledgement,
  do not spawn the affected operation under that old acknowledgement. Fresh
  acknowledgement is required to proceed. The initial option A left recovery
  presentation open; subsequent UI-OPS-22 resolves the available-UI case.
- **UI-OPS-22 (owner-approved, 2026-11-11, recovery option A):** when UI-OPS-21
  prevents dispatch and the initiating UI remains available, preserve the
  selected action/options and present an updated confirmation identifying the
  additional conflict and stating that the affected operation has not started.
  Require explicit renewed confirmation or cancellation; never automatically
  resubmit. The server must prevent launch under the obsolete acknowledgement.
- **UI-OPS-23 (owner-approved, 2026-11-11, revised waiting option C):** expose
  project-ownership waiting as a non-mutating state with availability updates.
  When automatic execution is off, becoming available does not start the action;
  the user can request execution or enable Run automatically when available.
  Availability observations never replace authoritative lock acquisition.
- **UI-OPS-24 (owner-approved, 2026-11-11, waiting-default option A):** initially
  default automatic execution while waiting to off, with a setting allowing the
  user to choose the default mode. The per-action choice remains explicit.
  This is a required configurable default, not a remembered-last-choice feature.

Required warnings/consequences must be accessible in desktop/mobile flows,
not hidden as optional Advanced controls. Actual exclusion and whole-operation
ownership remain STATE-LOCK-01/04; bulk participation is OPS-BULK-36. The
decision approves outcomes, not a new job service, storage format or reservation.
Existing queued receipts are prior art, not proven cross-session intent
discovery or complete canonical-project binding.

Before alignment, settle background manual versus interactive dispatch,
unattended callers, explicit warning
suppression scope, and how unavailable/stale intent evidence is handled.
Intent publication, launch-failure cleanup, cancellation/completion, abandoned
jobs and deletion/recreation require a bounded lifecycle review; an accepted
receipt alone cannot establish that lifecycle guarantee. No suppression setting,
silent consent, timeout value or default background waiting policy is approved.
No implementation, cross-session/browser verification or new tests.

### Approved waiting mode; detailed job lifecycle parked

On 2026-11-11 the owner clarified that option C is waiting/availability control,
not repeated permission prompts, then chose automatic execution initially off
with a setting for the default mode. UI-OPS-23/24 record the bounded decision.
With automatic execution enabled, the accepted action may proceed only after
successful real ownership acquisition and applicable validation. A status poll
showing an apparently free project does not guarantee priority or permit effects.
New unacknowledged conflicts still consume UI-OPS-21/22; waiting is not a blanket
waiver of conflict review. No automatic replay of uncertain work is authorized.

CFG owns persistence of the
default setting; UI-OPS owns its meaning. This
partial CFG extraction does not establish a global settings transaction.
Server-owned waiting was recommended, not independently approved as a required
job-service architecture. Background/unattended behavior, tab closure/reload,
waiting limits, cancellation lifecycle, stale-intent recovery, exact setting
scope/storage and batch continuation remain unresolved. Do not silently fill
these gaps or claim the existing bounded shell wait implements the approved UX.
Desktop/mobile presentation and keyboard access need later acceptance evidence.

The owner requested refocusing to avoid expanding scope indefinitely.
Park the detailed waiting/job-lifecycle branch after recording these decisions;
resolve its remaining dependencies before alignment, not by opening more
options now. No runtime changes or tests accompany this approval.

**Changed-conflict recovery, 2026-11-11:** the owner selected updated
confirmation rather than forcing the user to reopen the action (UI-OPS-22).
Additional-conflict detection before spawn is a known non-dispatch case, not
an execution failure or an uncertain transport result. The revised confirmation
retains UI-OPS-17's ordering/risk disclosure, works in desktop/mobile flows and
requires explicit user input. Preserving options does not silently expand
accepted scope or waive normal validation.

Do not extend pre-spawn re-prompting to already launched work or promise that
a disconnected browser can supply consent. With no renewed acknowledgement,
the affected operation remains unlaunched; no indefinitely waiting background
prompt is authorized. Mixed-batch partial dispatch and unattended/CLI handling
remain separate. Existing no-automatic-redispatch uncertainty limits continue
to apply. No runtime, browser or accessibility verification accompanies approval.

### Update options and terminology follow-up

Owner-approved option B, 2026-11-10: keep the existing single menu slot named
Update with positive update evidence and Force Update otherwise, while using
identical ordinary manual Update execution policy in both cases, per
UPD-RUN-29.
Explain that Force attempts Update without a detected update, not forced
replacement of unchanged containers or bypassed safeguards.

Owner direction, 2026-11-10: avoid an ever-expanding visible checkbox list in
the Update dialog. Group advanced/exception choices in a collapsible details
area or equivalent; exact layout remains to design. Do not lose clear
consequences when controls are collapsed. Preferred stopped-container label:
"Recreate and start stopped containers." This applies to stopped services in
a partly running stack as well as mixed-state replicas, per
UPD-RUN-26/27.
Entirely stopped stack defaults remain a separate question.

The owner also flagged the page-level "Autostart only" checkbox and Start All /
Stop All wording as unclear. Record this as deferred related UI work, not
approval to change bulk selection or lifecycle semantics during Update review.
Use stack, service and container/replica explicitly in scenarios; do not call a
service fully stopped when intending to describe an entirely stopped stack.

### Approved base-refresh recovery presentation

**Mixed-replica follow-up expectation, 2026-11-10:** the owner reconfirmed
UPD-RUN-23 for actual
Compose replicas and expects the later deliberate update/recreation to be
available in Compose Manager rather than requiring CLI use. Keep the manual
recovery path in UI scope; exact controls, replica targeting and stopped/start
effects remain unapproved. Automatic exclusion must not imply that the plugin
can already perform a safely scoped per-replica recreation.
The owner subsequently approved
UPD-RUN-26:
prefer an explicit include-stopped checkbox within manual Update over adding
more context-menu actions. Explain that selecting it permits recreating and
starting all existing replicas of an otherwise eligible mixed-state service
whose count matches configuration. Without selection, stop after preparation.
This does not approve every fully stopped-service or bulk-dialog behavior.

On 2026-11-10 the owner approved a separate, consented local-base retry after
exhausted acquisition retries when freshness came from the manager default.
UPD-RUN-18 owns
permission, failure behavior and fresh execution-time revalidation.
If explicit configuration forbids that retry, retain the option visibly disabled
and explain the actual blocker rather than hide it. Example:
"Unavailable: build.pull: true requires fresh bases. Remove build.pull to use
Compose Manager's recovery policy."
Setting false instead explicitly disables requested base refresh; it does not
delegate the decision to Compose Manager. Both true and false are explicit
instructions; only omission lets the manager supply this policy. The message
is illustrative concise copy, not a mandated paragraph or exact layout.
Do not blame service `pull_policy` for a build-base freshness restriction.
Exact layout is not approved; disabled presentation is not server enforcement.

Bulk Update consumes the owner-approved
UPD-RUN-20:
an ordinary stack failure does not block processing independent selected stacks.
Preserve per-stack outcomes and distinguish a mixed result from complete success.
The owner explicitly left report-page design and how retries are offered during
bulk work open on 2026-11-10. Neither a particular report page nor per-stack
prompting, automatic redispatch or retry-all behavior is approved by continuation.

### Current implementation and test map

- [Helpers](../../source/compose.manager/include/Helpers.php),
  [UiOperation](../../source/compose.manager/include/UiOperation.php),
  [receipt wrapper](../../source/compose.manager/scripts/compose_ui_operation.sh)
  and [Exec](../../source/compose.manager/include/Exec.php) prepare/publish/read.
- [Shared poller](../../source/compose.manager/javascript/composeOperations.js),
  [Compose-page integration](../../source/compose.manager/javascript/composeManagerMain.js)
  and [Dashboard](../../source/compose.manager/javascript/composeDashboard.js)
  consume dispatches/receipts and request reconciliation.
- [TerminalSession](../../source/compose.manager/include/TerminalSession.php),
  [supervisor](../../source/compose.manager/scripts/compose_terminal_session.sh)
  and [terminal controls](../../source/compose.manager/javascript/composeTerminal.js)
  own the execution/viewer/cancellation handoff. Detailed output mechanisms stay
  with UI-TERM.
- [Update-check coordinator](../../source/compose.manager/javascript/composeUpdateChecks.js)
  participates in invalidation, deferred checks and acknowledgement; server
  publication/acknowledgement belongs to the linked state contracts.
- [UiOperationTest](../../tests/unit/UiOperationTest.php),
  [TerminalSessionTest](../../tests/unit/TerminalSessionTest.php),
  [ComposeUtilTest](../../tests/unit/ComposeUtilTest.php),
  [GitStackWebActionsTest](../../tests/unit/GitStackWebActionsTest.php) and
  [ExecActionsTest](../../tests/unit/ExecActionsTest.php) exercise receipt,
  supervisor and real caller integration.
- Browser fixtures:
  [operation state](../../tests/e2e/tests/compose-operation-state.spec.ts),
  [terminal controls](../../tests/e2e/tests/compose-terminal-controls.spec.ts),
  [Dashboard inline controls](../../tests/e2e/tests/compose-dashboard-container-actions.spec.ts)
  and [check ownership](../../tests/e2e/tests/compose-update-check-ownership.spec.ts).
  They execute production functions with controlled transport/DOM, not a
  running Unraid stack.

### Resource and complexity limits

For N stacks, there are O(N) local owners/receipts and independent sequential
polls, not one batch receipt-status request. Nominal polling tapers but has no
global request-concurrency cap. Per-receipt lookup is one computed filename;
housekeeping scans R receipts in O(R) work at most once per minute on activity.
It reads each receipt and can scan without a fixed R limit. Runtime/check
reconciliation adds its own data-dependent work.

Managed cancellation repeatedly snapshots P system processes and computes
descendant closure. The repeated scan/closure passes are not proven O(P);
adversarial parent ordering can require O(P squared) work per snapshot.
The nominal grace period is not a bound on all scan/I/O/termination latency.
Spool bounds do not bound full log growth or total running receipts.

## Validation decisions and checkpoint

No whole or individual UI-OPS requirement is approved here. Existing identity
and BOU decisions remain unchanged. Review the connected first wave in concrete
scenarios before treating remaining implementation behavior as specification:

1. **Strict tracking:** retain legacy lock-only compatibility or require receipt
   evidence for every supported finite UI action? Recommended direction:
   stronger receipt evidence for current clients, with an explicit compatibility
   boundary rather than silently treating old paths as equivalent.
2. **Uncertainty/recovery:** what must users see and be able to recover after a
   lost dispatch response, 30-minute observation timeout, page reload or server
   restart? Current bounded polling is not a persistent job manager.
3. **Completion meaning:** confirm domain-partial presentation and that successful
   command completion is distinct from healthy runtime and fresh image checks.
   The UPD-RUN partial-result gap is a concrete alignment item, not permission
   to redefine partial as success.
4. **Cancellation/attached scope:** confirm the operation-specific partial-effect
   warnings, whole managed-batch cancellation and limits of native detach.
   Do not assume approval of all attached/reconnect behavior from code.

All five first-wave drafts now exist on dev. The owner subsequently authorized bounded
UPD-STATE and OPS-LIFE extensions,
now drafted with recommended versus observed ownership.
**Return to owner validation** of identity -> sources -> coordination ->
evidence/lifecycle/update policy -> operation presentation.
Record approved/amended/deferred/rejected requirements, then
triage implementation gaps against the approved scope. No runtime fix,
architecture expansion, new cancellation policy or release commitment is
authorized by this draft.

## Verification and limits

Executed existing checks on `e2760a6`, PHP 8.3.6 / PHPUnit 12.5.23:

- UiOperationTest, TerminalSessionTest, ComposeUtilTest,
  GitStackWebActionsTest and ExecActionsTest: **228 cases / 1,548 assertions**,
  no failures/skips. **One existing PHP deprecation triggered by two Git list
  tests:** ComposeList.php:350 passes null to `htmlspecialchars`. This
  documentation-only change does not fix that unrelated baseline behavior.
- Chromium Playwright: operation state, terminal controls and Dashboard inline
  controls **46/46 passed**; update-check ownership **30/30 passed**.
  Combined **76/76**, no skips reported. Controlled browser fixtures, not a
  live-host suite; Dashboard stack receipt integration was traced, not exercised
  by its separate inline-container fixture.
- The editor's unit-test integration found no PHP tests, so the repository
  PHPUnit executable was used directly with no coverage.

No Firefox run, live Unraid mutation, proxy/registry experiment, full Bats,
canonical full suite or application lint was run for this documentation-only
extraction. No screenshots were captured; browser fixtures are not approved
visual references. Passing checks establish bounded implementation evidence,
not owner approval, crash recovery or whole-contract compliance.

Documentation checks: 722 local links/anchors across 14 contract documents;
12 unique ordered UI-OPS requirements; 55 sketches; 9 indexed drafts; inventory
arithmetic and diff whitespace passed.

Research details and access limits are in the
UI-OPS extraction section of the source register on dev.

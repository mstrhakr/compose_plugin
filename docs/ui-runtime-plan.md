# UI runtime consistency plan

## Release scope

The current beta covers bug fixes and streamlining/stabilization of existing
container and stack state handling: response ordering, list/detail lifecycle,
operation progress, failure recovery, and existing metrics connections.

The no-reload action-terminal work and this runtime unification ship together.
Terminal close callbacks refresh presentation but do not establish completion.
Tracked finite actions (single stack, Git deploy, and bulk) run in an owned
process session independently of ttyd. The terminal is an output viewer:
**Continue in background** closes only that viewer; closing the window has the
same non-cancelling behavior. **Stop command** asks for confirmation and signals
only owned command processes, not the stack's containers. Already-applied
changes are not rolled back. Stop also cancels undispatched bulk receipts.
The cancellation confirmation describes the dispatched command's possible
effects (detached Up, Down, Stop, Pull, Update, or Git deploy), including partial
changes and whole-batch cancellation. It does not claim to know the current
execution phase or guarantee the final container state.
Once the session completes, these controls become **Done**, with a visible
nonzero exit status when applicable. Existing run-in-background dispatch and
its notification behavior are unchanged. Legacy untracked terminals retain
their existing behavior.

Managed viewers send the parent page's CSRF token for status, output, connection,
and stop requests. The control dialog opens after command acceptance, before
viewer readiness. Its connection endpoint waits up to 10 seconds for a socket,
including a final deadline check. The browser confirms attachment separately,
with up to three automatic viewer-only attempts; no retry redispatches the command.
If connection fails, the dialog says it could not connect to the live terminal
and shows saved output instead. Actual command status remains separate.
**Retry live connection** starts another bounded series without restarting work.
**Continue in background** and **Stop command** remain available during recovery.
Completed sessions retain a connection acknowledgement so a short-lived viewer
is not mistaken for a failed connection between status polls.

Saved-output requests read at most 64 KiB from the specific validated session's
1 MiB spool. Byte cursors and a rotation generation prevent duplicate or skipped
output across truncation; shared/exclusive spool locks coordinate readers with
the writer. The browser incrementally decodes bytes, displays output as text,
removes terminal control sequences, and bounds its retained output. This is a
plain-text fallback, not a second interactive terminal.
Cancellation is owned by the supervisor: receipts remain running until live
members of its session have exited, including separate process groups created
by GNU `timeout` and descendant PTY sessions, with forced termination after a
10-second grace period. Commands have a pseudo-terminal even before a browser
connects, preserving Compose colours and in-place progress. The capture bridge
drains after command cleanup rather than sending an early hangup to its children.
The live output spool is capped at 1 MiB and explicitly marks omitted earlier
output; a single stack's command log retains the complete output. A viewer that
never connects releases its listener and socket after 30 seconds. Closing an
already-connected viewer also releases its listener without stopping the action.
Completed output spools and diagnostics expire after one hour, and receipts
after 24 hours; running receipts and output are preserved. Cleanup is
activity-driven and scans at most once per minute across dispatch requests,
so a bulk dispatch does not rescan all receipts for every stack.
Viewer connection failures never cancel accepted work; receipt polling continues
independently. A confirmed command-launch failure instead completes only
still-queued receipts as failed.

Selecting **Follow stack logs** for Up disables wait-for-healthy for that run,
including inherited stack/global defaults or an explicit wait request. It does
not modify saved wait settings; a subsequent Up without following logs uses the
normal wait policy again. The dialog explains this override beside the checkbox.
It also overrides Run in background for this run, opening a managed viewer.
Attached Up uses the same owned PTY and output recovery as finite actions.
The viewer remains read-only. When Compose advertises its native `d Detach`
menu, **Detach** becomes available; the popup's X uses the same request.
The server validates session/process ownership and writes only lowercase `d`
to a private FIFO feeding that PTY. It sends no signal. The viewer waits up to
10 seconds for a successful final receipt before closing, never a fixed sleep
or a visual output heuristic. Failure or timeout stays visible and leaves the
command alone. Older Compose versions without the native menu explicitly lack
Detach; closing is refused rather than pretending the follower ended.
**Stop command** is separate and warns that attached Up can stop containers.
Closing an entire browser tab cannot be intercepted reliably; accepted work
still runs independently, and native detach is not guaranteed after navigation.
General reattachment/interactive terminal controls remain outside this scope.

Pull/Build & Up persists a post-operation image recheck before dispatch; ordinary
Up does not. Update, Force Update, and Update All share the same validated
preparation step. Empty, malformed, refused, or failed preparation prevents
dispatch with an explicit error. Mark/clear endpoints report persistence failure
rather than claiming a saved change.

Image preparation retries confirmed registry pull rate limits at most twice
(three total attempts), waiting at least 3 seconds or the longer reported delay,
rounded up. A suggested wait over 30 seconds stops immediately with a warning;
the delay is never shortened to fit the limit. Missing hints use 3 seconds.
Docker duration hints and exposed HTTP Retry-After seconds/dates are understood;
Compose does not necessarily expose the original HTTP header. Only the pull is
retried, with the operation lock held, never the whole update or container
recreation. Exhausted rate limits do not trigger the buildable-image fallback.
Foreground capture preserves terminal streams and ANSI progress. Manual updates,
scheduled updates, and Pull/Build & Up use the same policy.
An unrecognized explicit retry hint refuses automatic retry instead of risking
a request before the registry's required delay.

`PendingRechecks` owns every pending-store read and mutation, including deletion.
Readers take a shared lock; writers hold an exclusive lock across validation,
read-modify-write, and flush. Missing storage is empty; malformed, invalid,
unreadable, or unexpectedly empty storage fails explicitly without being reset.
Operations use O(P + S) work for P persisted entries and S requested stacks.

The public pending map still contains timestamps. New stored entries also carry
random identities; legacy integer entries remain readable and acknowledgeable.
Each single-stack image check captures its current identity before checking and
returns an opaque `recheckToken` (null when no mark existed). `clearStackRecheck`
requires a JSON `tokens` map containing that value for each requested stack and
clears only matching marks. A null token cannot consume a subsequently created
mark. Renewals have distinct identities even within one second. Missing/malformed
tokens are explicit errors: old browser tabs must refresh after deployment.
Deletion deliberately removes its stack's entry, rather than acknowledging it.
The single-stack checker also owns a local request identity. A newer check,
action start, or deletion invalidates older callbacks, including acknowledgement
errors. Checks requested while a local action is busy remain queued. Failed
acknowledgements warn explicitly and retain retryable pending state without
calling the received image result a failure.

`StackMutationGuard` coordinates checks with the canonical Compose project's
existing operation lock. Checks take short shared locks to capture an epoch and
pending identity, then release them before Docker/registry work. Publication
reacquires shared locks and accepts only unchanged epochs; busy or changed
projects are superseded without evidence or acknowledgement tokens. Full-check
publication and metadata commits are batched, not repeated whole-store writes
per project. Lock ordering costs O(P log P) for P projects, with O(P) temporary
handles; all are released after the short publication transaction.

Source writers use the same exclusive project lock: Compose/env/override saves,
template creation, source settings, and Git conversion/dry-run/reclone rotate
epochs at both boundaries, including failed mutations. They renew existing
pending checks without introducing automatic checks for ordinary edits.
Editor paths are resolved again under the lock; busy projects return explicit
errors. Compose rename migration and multi-file settings restore previous bytes
on a reported write failure; restoration failures are also surfaced. Writes
remain in-place to preserve symlink targets and existing mounted file inodes;
this is failure recovery, not crash-atomic storage.

The stack editor reports shared Compose/env/override sources, including symlink
and hard-link aliases and missing template targets. Saves of known shared files
require confirmation against a freshly computed sharing list. A persistent
per-stack "Don't show again" preference suppresses both the opening notice and
save confirmations; the editor exposes the same checkbox to re-enable them.
Incomplete inspection is reported rather than represented as exclusive
ownership. These notices are advisory, not cross-project mutation coordination.

Editor reads return a content revision distinguishing a missing file from an
empty file. Updated editors submit that revision with Compose/env/override,
Labels, and template saves. A mismatch preserves unsaved edits and asks the
user to review the current file; it never automatically reloads or overwrites
the other change. A short, nonblocking source-edit lock serializes plugin
compare-and-save operations across path/symlink/hard-link aliases. For dangling
symlinks, relative/absolute link chains resolve to the missing
target for both edit locks and sharing notices; cyclic chains fail explicitly.
This does not lock external editors or coordinate Git checkouts with other
projects' checks.
Legacy API callers without a revision retain their existing response contract
and do not gain optimistic stale-content protection.

Pending-recheck and presentation-cache readers and writers acquire a stable
sidecar lock before opening or creating the data file. Existing data-inode
transaction locks remain in place. First-use initialization is serialized;
preexisting empty/corrupt data still requires explicit recovery.

Individual container actions resolve the actual Compose project label, acquire
its lock, and execute against the inspected immutable container ID. Native
containers retain their no-project behavior. Docker exit failures and bounded
execution timeouts are errors, not completion. Dashboard actions reject duplicate
requests per container and display server, malformed-response, and network
failures without refreshing as if the action succeeded.

The shared shell mutation path rotates epochs and renews pending identities at
both locked boundaries, including failure/normal termination cleanup. Pull,
Update, Git Deploy, and image-preparing Up create pending checks. Ordinary
Up/down/stop only renew marks that already exist. Failed begin bookkeeping
refuses execution; failed end bookkeeping fails the operation and its saved
result while retaining the begin mark. Forced interruption still retains the
begin epoch/mark. Folder keys and canonical runtime project names remain
distinct. Acknowledgements take the operation lock and bind their token to the
epoch, so a check published before a later mutation cannot consume its mark.
This guarantee covers the shared shell operation path, not external Docker
changes or source-editing paths that do not rotate mutation epochs.

Stack-level image evidence has a separate server publication generation. Single
and full checks reserve their project generations before Docker inspection or
registry requests; only matching generations may commit. A newer failed check
does not revive an older publisher. Deletion removes the generation and clearing
resets all generations, so late checks cannot restore removed evidence. Full
checks prune only originally observed, still-owned entries absent from their
scope; they do not erase unrelated newer results.

Saved update storage migrates legacy project maps to a version-1 envelope with
`stacks` and `generations`. Both are read and written under the existing single
inode lock; public saved-result maps remain unchanged. Missing generation
metadata is safe for legacy evidence because new checks reserve random tokens.
No network operation holds the persistence lock. Ownership transactions cost
O(P + S), plus existing container-evidence merge work. Single checks rejected at
publication return `result: superseded` without image evidence or recheck tokens.
Full responses retain `result: success`, containing only accepted `stacks` and an
additive `superseded` project list. Superseded checks must not acknowledge pending
marks.

Compose's platform/config-digest checker does not read or write the native Docker
image-update cache. Compose badges use saved per-container evidence; missing
plugin evidence remains unchecked rather than falling back to native verdicts.
Explicit clearing leaves native cache bytes untouched. There is no replacement
plugin image cache: the existing stack evidence store remains authoritative.

`ContainerMetadataCache` owns presentation metadata transactions and per-project
collection generations. A collector reserves its generation before inspecting
containers or resolving icons; only a still-owned scoped patch may merge.
Image-check metadata additionally requires accepted check publication. Deletion
invalidates the project generation, and explicit clearing invalidates all active
collectors while preserving valid icons/runtime metadata. Both public metadata
endpoints retain their project/service maps; the internal version-1 envelope has
`projects` and `owners`. Legacy maps migrate on the first write. Corruption fails
explicitly and remains intact until explicit clearing. Transactions are O(M + S)
for M stored metadata entries and S requested changes; no Docker/network work
holds the store lock.
Detail collectors also verify the operation epoch before committing metadata.
Metadata read errors warn in the browser without discarding the last loaded
presentation snapshot.

The main-page `composeUpdateChecks.js` coordinator owns single/bulk checks,
saved-result hydration, pending retries, and acknowledgement callbacks. Existing
page entry points delegate to it; row/badge rendering and command dispatch remain
in the page layer. Actions and deletion explicitly invalidate project ownership.
Bulk success and failure apply only to still-owned projects, merging accepted
entries instead of replacing all evidence. Cached hydration never supersedes a
fresh check, including one that has already completed. Unreadable pending storage
is reported and suppresses automatic Check All rather than acting like an empty
queue. Presentation helpers remain in the Dashboard-shared `composeUpdateStatus.js`.
Both single and full checks preserve corrupt saved storage and fail explicitly;
Check All no longer implicitly repairs it while reserving ownership. Use explicit
Clear Update Cache to recover, so a failed inspection cannot erase damaged evidence.

Pending post-update checks use one busy-aware drain for both progressive rows
and completed runtime refreshes; queued/running operations keep their pending
checks until completion, including an action started before a deferred drain.
The internal pending queue is an insertion-ordered Set: membership/removal is
constant-time on average and a complete drain is O(P) for P pending stacks.
This changes no endpoint payloads or persisted recheck format.
Preserve update-check revisions independently of runtime generations: a runtime
response must not restore image-update evidence superseded by a fresh check.
Accepted runtime snapshots also synchronize DOM/jQuery container membership and
continue targeted health refreshes while checks are starting.
Deleting a stack cancels its health timer and emits `compose-stack-removed` so
Docker visibility can refresh managed membership even if stopped containers
remain. Docker-table mutations reapply cached filtering; only changed container
names, table replacement, or explicit list/removal invalidation fetch membership.
Routine metrics text updates do not enumerate Docker or saved stacks again.
Docker-table membership requests retain their own row-name/table baseline.
Changes during a request cause one coalesced follow-up even if its response
arrives before the mutation debounce; applying stale membership cannot consume
the invalidation. Routine unchanged-name mutations still use cached filtering.
Dashboard actions and stack logs use the metadata path from the latest accepted
stack snapshot, including a nondefault projects root. Missing paths fail visibly
instead of falling back to the default root; removed stacks lose their cached path.
Project-keyed runtime, operation, update, and cache registries have no inherited
keys. Valid names such as `constructor` are not mistaken for active operations,
request generations, or cached promises. Saved status/cache loading preserves
that dictionary behavior.
Runtime/health refreshes do not rebuild whole-list description widgets or
re-evaluate column-layout subscriptions; list initialization and layout changes
own those enhancements. Container membership changes still notify metrics.
Unchanged snapshots preserve health badge/status animation DOM and expanded
details. Stack context menus are rebound only when their inputs change, not on
every health poll. Queued/running tracked operations disable conflicting stack
menu actions, editing and update checks; logs/WebUI remain accessible. Health
`starting` by itself does not disable actions. Stale menu callbacks and container
actions also check current operation ownership before dispatch; backend locks
remain authoritative for operations from other clients.
When menu inputs do change, the menu adapter preserves an open menu's position,
scroll and focused action across the host library's rebuild. Progressive-list
finalization must not close already initialized menus. Disabled actions are
enforced by the adapter because the host context library ignores that option.

Review the bottom action controls during this UI work, especially the scope of
the autostart-only checkbox and the grouping of affected actions. Agree on the
interaction before changing behavior. A dedicated identity audit/reconciliation
settings UI is a separate deferred investigation, not a prerequisite here.

Automatic detection of external Docker lifecycle changes is targeted for the
**next beta**, tracked in
[feature request #194](https://github.com/mstrhakr/compose_plugin/issues/194).
It is not a merge gate for the current stabilization beta. Build it on the shared
reconciliation path rather than adding a second runtime-state writer.

## Contract

Dashboard list snapshots own a generation; detail snapshots also own a
per-stack request generation. Superseded successes and failures cannot mutate
the current view. An accepted Dashboard action keeps its owner until completion
or tracking failure; repeated dispatches cannot replace it.

Health-settling refreshes reuse the accepted runtime cache and the single
per-project timer after failed requests. Removed rows and settled health stop
retries. Unchanged detail markup may be reused only after temporary indicators
are cleared; menu busy-state reconciliation does not require table replacement.

Detail enhancements are scoped to the changed table and its containers.
Global column preference changes and scheduled metrics renders retain their
whole-page paths. Dashboard Docker hiding owns one bounded scheduler and always
uses the latest membership, including an empty set after removal.

`Pull/Build & Up` is a deployment action, not an alias for Update. It uses
profile selection and the normal Up path, with shared image preparation from
the update module under the same operation lock/receipt. It pulls published
images and rebuilds buildable services (latest base images, with the existing
local-base fallback), then starts default services and selected profiles,
including undeployed services. Image-preparation failure prevents Up.
Ordinary Update retains its existing-container/stopped-service safety policy.
The deployment action does not use or change the manual Update inclusion default.

Runtime state, operation progress, image-update results, and resource metrics are
different kinds of information. A metrics message must not imply that a stack
operation finished. An unknown image check must not imply an up-to-date image.
Only authoritative runtime responses should change container identity and state.

The current live metrics transport is the Compose-local Nchan WebSocket channel,
not SSE. Keep that transport; replacing it would not fix competing state writers.

## Stage 1: reconcile current runtime writers

This branch introduces a project-scoped request generation and a shared
`reconcileStackRuntime` path for parent refreshes, detail fetches, and prefetch
hydration:

- Starting a newer runtime request invalidates earlier responses and prefetched
  snapshots. Starting an operation or deleting a stack also invalidates them.
- Accepted responses update normalized containers, uptime, DOM container names
  and short IDs before rendering parent state and notifying metrics listeners.
- A late response cannot overwrite a newer accepted state or recreate a missing
  row. Unknown image-check fallback remains image- and container-aware.
- Row refresh requests have a bounded timeout and release their in-flight
  accounting on success, failure, and stale responses.
- Starting/recovering the metrics socket also ensures its publisher exists.
- Empty operation dispatch responses explicitly clear progress and report error.

Regression tests execute production functions with controlled response ordering.
No live Docker or Unraid host is required for these tests.

## Stage 2: unify list and detail lifecycle

The list/detail stabilization slice guards metadata, progressive row responses,
and delayed commit callbacks with a whole-list generation. Superseded loads
settle without changing the new list or its spinner. List and row requests have
bounded timeouts. Deletion invalidates an active list and schedules a fresh one
so pending row responses cannot revive the deleted stack.

If runtime state changes while row HTML is in flight, request fresh runtime
reconciliation after inserting that row. This is not a transactional snapshot of
the whole page; operation ownership and preserving progress across replacement
are handled separately from snapshot reconciliation.

Use project folder identity as the stable key. Treat DOM row IDs as view
identifiers, not durable cache identities. In-flight detail requests coalesce
additional refresh requests into a follow-up fetch. Superseded responses remove
loading placeholders by rendering current cached details or an explicit retry
message; delayed prefetch waiters cannot issue requests after invalidation.
Prefetch is an optimization, never a
source of authority independent of the runtime request generation.

Preserve expansion intent during accepted row replacement and refresh. Failure
and supersession paths must also respect collapse intent. Do not display an old
snapshot as newly verified data.

## Stage 3: consistent operation lifecycle

Move stack, container, git deployment, and dashboard actions toward the same
dispatch/running/reconciling/failed state transitions. Keep operation identity
separate from runtime-request generations so overlapping operations cannot clear
each other's progress indicators.

Audit every response shape, timeout, malformed response, disconnect, and
completion path. On uncertain completion, preserve uncertainty and offer retry;
do not fabricate success or restore obsolete state as authoritative.

The current checkpoint assigns unique owners to main-view stack and container
actions. Late dispatches, lock polls, and container callbacks cannot clear a
newer owner's progress. Progressive row replacement reapplies progress without
starting another operation. Bulk Start/Stop/Update share response cleanup;
dispatch and Update All preparation requests have bounded timeouts and reject
empty or invalid responses. Container failures restore controls, report the
error, and request authoritative runtime state.

### Dispatch completion receipts

The main view requests tracking for finite stack actions, bulk actions, recreate,
and git deployments. The server creates a separate receipt for each accepted
stack/dispatch before launching the command. A shell wrapper records `running`
when that command actually starts and `completed` with its exit code when it
exits. Later bulk stacks remain `queued` until their turn; an unlocked stack is
never completion evidence for a tracked dispatch.

Foreground responses include the terminal URL and receipt identifiers. Opening
the terminal does not clear progress: the same completion poll runs independently
of the modal. After success or failure it clears only its own progress and requests
fresh runtime state for the parent and any currently expanded container details.
Nonzero exits are reported explicitly. Missing, malformed, or unavailable receipts
remain uncertain, retry within the existing 30-minute bound, and then produce a
warning rather than claiming success. Superseded polls cannot finish newer actions.

Tracking is opt-in (`trackOperation=1`). Main-view and Dashboard finite actions
request receipts and use the same `composePollOperation` completion poller.
Dashboard close only refreshes presentation; queued/running receipt polling
continues after early close and refreshes again on completion. Refused,
malformed, failed and unconfirmed dispatches report errors without opening an
invalid terminal. Nonzero exits remain visible in Dashboard status after refresh.
Other terminal clients keep their response contract. Receipts are private
transient files, scoped to project folder and random dispatch ID, and expired
after a day during subsequent dispatches. They contain no credentials.

This is a live-testing checkpoint, not a completed lifecycle migration:

- Test real ttyd completion and early modal closure on Unraid; isolated tests
  validate command receipts and browser reconciliation, not host signal delivery.
- **Follow stack logs** now shares managed receipts and viewer recovery, with
  explicit native Detach and a matching popup-close guard. Its receipt remains
  running until Compose actually exits; detachment does not stop the stack.
  Native shortcut compatibility still needs a guarded real-host soak.
- Main-view git deploys opt into the same receipts; scheduled/CLI deployments
  keep existing behavior. Dashboard rendering remains page-specific even though
  finite-action completion now shares the receipt contract.

## Stage 4: transport recovery and observability

Keep metrics snapshots disposable and timestamped. Recover publishers and sockets
across hidden tabs, hidden columns, connection loss, and navigation; avoid
duplicate subscribers and restart storms. Test real Nchan connection/open/error
behavior on Unraid in addition to isolated callback tests.

For the next beta (#194), use a shared Compose-filtered Docker events stream to
send debounced project invalidations through the existing Nchan connection, then
request authoritative snapshots through runtime reconciliation. Bootstrap and
reconnect must refresh state because event history is bounded. Lifecycle updates
must work independently of visible metrics columns. Missing stats mean metrics
unavailable, not stopped. Measure server cost before adding any low-rate safety
poll; never inspect all stacks on each metrics tick.

## Stage 5: align all views

Migrate dashboard and embedded Docker-tab views to the same runtime and operation
contracts, preserving their UX. Remove duplicated merge/state logic only after
the replacement is tested. Avoid a framework rewrite as a prerequisite.

## Validation and release gates

- Deterministic out-of-order, failed, empty, timed-out, and obsolete responses.
- Start/stop/restart/recreate, replicas, deletion, and explicit refresh after
  externally changed state.
- Expanded/collapsed rows, progressive lists, hidden browser/panel/columns.
- Cache/DOM identity agreement and metrics following recreated containers.
- Existing PHP, browser, type, syntax, and lint suites.
- Live Unraid smoke tests for publisher restart and operation completion.

Automatic external-change detection, event-burst coalescing, and lifecycle
subscription recovery are additional release gates for the next beta (#194),
not the current stabilization beta.

Ship stages as reviewable slices. The issue185 and private-registry fixes are
already on `dev`; the combined UI branch is rebased onto those fixes.

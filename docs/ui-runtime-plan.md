# UI runtime consistency plan

## Release scope

The current beta covers bug fixes and streamlining/stabilization of existing
container and stack state handling: response ordering, list/detail lifecycle,
operation progress, failure recovery, and existing metrics connections.

The no-reload action-terminal work and this runtime unification ship together.
Terminal close callbacks refresh presentation but do not establish completion.
Pending post-update checks use one busy-aware drain for both progressive rows
and completed runtime refreshes; queued/running operations keep their pending
checks until completion, including an action started before a deferred drain.
Preserve update-check revisions independently of runtime generations: a runtime
response must not restore image-update evidence superseded by a fresh check.
Accepted runtime snapshots also synchronize DOM/jQuery container membership and
continue targeted health refreshes while checks are starting.
Deleting a stack cancels its health timer and emits `compose-stack-removed` so
Docker visibility can refresh managed membership even if stopped containers
remain. Docker-table mutations reapply cached filtering; only changed container
names, table replacement, or explicit list/removal invalidation fetch membership.
Routine metrics text updates do not enumerate Docker or saved stacks again.

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

Tracking is opt-in (`trackOperation=1`). Existing dashboard/terminal clients keep
their response contract and are not silently migrated. Receipts are private
transient files, scoped to project folder and random dispatch ID, and expired
after a day during subsequent dispatches. They contain no credentials.

This is a live-testing checkpoint, not a completed lifecycle migration:

- Test real ttyd completion and early modal closure on Unraid; isolated tests
  validate command receipts and browser reconciliation, not host signal delivery.
- **Follow stack logs** deliberately retains its existing attached-up/Done/detach
  behavior. It is not a finite command, so this slice does not keep a completion
  spinner waiting for the log stream to end or change how Compose attaches.
  Its lifecycle remains an explicit follow-up before declaring all modes aligned.
- Dashboard lifecycle alignment remains a separate audit. Main-view git deploys
  opt into the same receipts; scheduled/CLI deployments keep existing behavior.

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

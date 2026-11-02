# UI runtime consistency plan

## Release scope

The current beta covers bug fixes and streamlining/stabilization of existing
container and stack state handling: response ordering, list/detail lifecycle,
operation progress, failure recovery, and existing metrics connections.

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

This is a live-testing checkpoint, not a completed lifecycle migration:

- Background bulk jobs are queued sequentially on the server. An unlocked later
  stack does not prove its queued job has completed; lock-only completion needs
  queue-aware evidence before merge.
- Foreground commands start through ttyd when the terminal connects. A dispatch
  response is not command completion. Verify the host's terminal-close refresh
  path and add a reliable completion signal if closing the terminal leaves stale
  rows. Do not reuse immediate unlocked polling as proof of completion.
- Dashboard and git-deployment lifecycle alignment remains a separate audit.

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

Ship stages as reviewable slices. Retain the issue185 fixes as a separate PR;
this follow-up branch is based on that branch until it merges into `dev`.

# UI runtime consistency plan

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

Follow up with tests and implementation for whole-list load generations,
progressive row insertion, concurrent expansion/collapse, and deletion/recreation.
Whole-list HTML requests currently remain a separate writer; this branch does
not claim to serialize that pipeline.

Use project folder identity as the stable key. Treat numeric DOM row IDs as view
identifiers, not durable cache identities. In-flight detail requests should be
coalesced or superseded explicitly, with a pending-refresh flag when a newer
refresh must run after an existing request. Prefetch is an optimization, never a
source of authority independent of the runtime request generation.

Preserve expansion intent during accepted row replacement and refresh. Do not
reopen collapsed details or display an old snapshot as newly verified data.

## Stage 3: consistent operation lifecycle

Move stack, container, git deployment, and dashboard actions toward the same
dispatch/running/reconciling/failed state transitions. Keep operation identity
separate from runtime-request generations so overlapping operations cannot clear
each other's progress indicators.

Audit every response shape, timeout, malformed response, disconnect, and
completion path. On uncertain completion, preserve uncertainty and offer retry;
do not fabricate success or restore obsolete state as authoritative.

## Stage 4: transport recovery and observability

Keep metrics snapshots disposable and timestamped. Recover publishers and sockets
across hidden tabs, hidden columns, connection loss, and navigation; avoid
duplicate subscribers and restart storms. Test real Nchan connection/open/error
behavior on Unraid in addition to isolated callback tests.

Runtime changes made outside the plugin need an explicit refresh/invalidation
signal or a bounded visible-page reconciliation poll. The metrics-only channel
does not supply container lifecycle events. Choose that mechanism after measuring
server cost; do not make high-frequency Docker inspection part of every metrics
tick.

## Stage 5: align all views

Migrate dashboard and embedded Docker-tab views to the same runtime and operation
contracts, preserving their UX. Remove duplicated merge/state logic only after
the replacement is tested. Avoid a framework rewrite as a prerequisite.

## Validation and release gates

- Deterministic out-of-order, failed, empty, timed-out, and obsolete responses.
- Start/stop/restart/recreate, replicas, deletion, and externally changed state.
- Expanded/collapsed rows, progressive lists, hidden browser/panel/columns.
- Cache/DOM identity agreement and metrics following recreated containers.
- Existing PHP, browser, type, syntax, and lint suites.
- Live Unraid smoke tests for publisher restart and operation completion.

Ship stages as reviewable slices. Retain the issue185 fixes as a separate PR;
this follow-up branch is based on that branch until it merges into `dev`.

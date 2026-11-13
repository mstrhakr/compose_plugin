# STATE-META: Shared metadata/state inventory and cleanup addendum

**Status:** Draft / awaiting owner review. No whole-contract approval.

**Baseline:** 2026-11-11, `c2bd252`, clean before research.

**Branch note:** this copy was backported to `main` for the #202 hotfix.
References to contracts not yet present on `main` are plain text.
Documentation-only extraction; no runtime or test changes.

## Purpose and scope

Define one canonical inventory of Compose Manager metadata/state stores and a
linked cleanup addendum for stack Delete outcomes. This prevents each contract
from copying or drifting its own store list.

STATE-META owns:

- the catalog structure and stable entry IDs;
- per-entry keying, durability and owner references;
- proposed Required/Optional cleanup classification for stack deletion;
- disposable presentation-metadata cache behavior, including authoritative
  fallback and freshness-based bypass (STATE-META-06), and automatic repair
  of corrupt presentation storage (STATE-META-14).

STATE-META does **not** absorb other domains' behavior semantics. For example:

- UPD-STATE still owns update-evidence meaning;
- UI-OPS still owns operation-receipt lifecycle;
- UI-LAYOUT still owns stack/column preference semantics;
- UPD-AUTO still owns schedule behavior.

## Evidence and intent

| Source | Intended outcome / limit |
| --- | --- |
| STK-LIFE-19 | Delete success must reflect cleanup of known stack-keyed manager state, not only folder deletion. |
| STK-LIFE-20 | Cleanup documentation is split into full inventory plus cleanup addendum instead of duplicated per-contract file lists. |
| STK-LIFE-21 | Every cleanup entry must be explicitly Required or Optional (no unclassified middle state). |
| STK-LIFE-22 | STATE-META owns those inventory/addendum definitions; STK-LIFE consumes them. |
| [ContainerMetadataCache](../../source/compose.manager/include/ContainerMetadataCache.php), [StackUpdateService](../../source/compose.manager/include/StackUpdateService.php), [PendingRechecks](../../source/compose.manager/include/PendingRechecks.php), [Exec](../../source/compose.manager/include/Exec.php) | Current persisted state stores and current Delete-time purge subset (`containers.cache`, `update-status`, `pending-recheck`, icon normalize override). |
| [#125](https://github.com/mstrhakr/compose_plugin/issues/125), [#185](https://github.com/mstrhakr/compose_plugin/issues/185) | Reports stale update metadata/evidence after updates; supports explicit invalidation/cleanup ownership instead of "eventually consistent by refresh". |
| [#62](https://github.com/mstrhakr/compose_plugin/issues/62), [#41](https://github.com/mstrhakr/compose_plugin/pull/41) | Confirms persisted presentation preferences (ordering/default views) are deliberate state surfaces, not transient UI-only behavior. |

## Dependencies and consumers

| Relationship | Owner / consumer | Required integration |
| --- | --- | --- |
| Depends on | STK-LIFE | Consume STK-LIFE-19 through 22 as scope authority for cleanup inventory/addendum ownership and Required/Optional classification rule. |
| Shares boundary with | UPD-STATE | Inventory includes update-status and pending-recheck stores, but evidence/pending semantics remain UPD-STATE-owned. |
| Shares boundary with | UI-OPS | Inventory includes receipt stores; dispatch/completion lifecycle semantics remain UI-OPS-owned. |
| Shares boundary with | UPD-AUTO, UI-LAYOUT | Inventory includes schedule/presentation preference stores; operational semantics remain with those owners. |
| Consumed by | STK-LIFE, OPS-BULK, UPD-STATE | Contracts reference entry IDs and cleanup classes rather than duplicating store lists. |

## Canonical metadata/state inventory (v1)

These entries are the authoritative inventory set for current extraction scope.
`Owner` names the behavioral owner; STATE-META owns the catalog record.

| Entry ID | Store / location | Keying | Owner (semantics) | Primary writers/readers | Durability |
| --- | --- | --- | --- | --- | --- |
| STATE-META-INV-01 | `containers.cache.json` ([COMPOSE_CONTAINER_CACHE_FILE](../../source/compose.manager/include/Defines.php)) | `project -> service -> metadata` | STATE-META | Write: [ContainerMetadataCache](../../source/compose.manager/include/ContainerMetadataCache.php) (`begin/merge/remove/clearEvidence`), read: [ContainerCache](../../source/compose.manager/include/ContainerCache.php), [Exec](../../source/compose.manager/include/Exec.php), browser metadata adapters | Persistent (`/boot/config/...`) |
| STATE-META-INV-02 | `update-status.json` ([COMPOSE_UPDATE_STATUS_FILE](../../source/compose.manager/include/Defines.php)) | `stack -> check evidence + generation` | UPD-STATE | Write/read: [StackUpdateService](../../source/compose.manager/include/StackUpdateService.php), read: [DashboardStacks](../../source/compose.manager/include/DashboardStacks.php), browser projections | Persistent (`/boot/config/...`) |
| STATE-META-INV-03 | `pending-recheck.json` ([PENDING_RECHECK_FILE](../../source/compose.manager/include/Defines.php)) | `stack -> {timestamp, token}` | UPD-STATE | Write/read: [PendingRechecks](../../source/compose.manager/include/PendingRechecks.php), endpoints in [Exec](../../source/compose.manager/include/Exec.php) | Persistent (`/boot/config/...`) |
| STATE-META-INV-04 | normalize override file (`[COMPOSE_ICON_NORMALIZE_DIR](../../source/compose.manager/include/Defines.php)/<sanitized-project>.yaml`) | Per-sanitized project name | UPD-CHECK / UI-ICONS boundary | Write/read during buildability and icon normalization flows; remove in [composePurgeDeletedStackCaches](../../source/compose.manager/include/Exec.php) | Ephemeral (`/var/tmp`) |
| STATE-META-INV-05 | `autoupdate.json` ([getAutoUpdateConfigFilePath](../../source/compose.manager/include/Util.php)) | Absolute stack path + `defaults` | UPD-AUTO | Write/read: [AutoUpdate](../../source/compose.manager/include/AutoUpdate.php), [AutoUpdateRunner](../../source/compose.manager/include/AutoUpdateRunner.php) | Persistent preferred (`/boot/config/...`) |
| STATE-META-INV-06 | `stack-order.json` ([COMPOSE_STACK_ORDER_FILE](../../source/compose.manager/include/Defines.php)) | `composeRoot -> ordered project folders` | UI-LAYOUT | Write: [saveComposeStackOrder](../../source/compose.manager/include/Util.php), read: [ComposeList](../../source/compose.manager/include/ComposeList.php) and list/discovery flows | Persistent (`/boot/config/...`) |
| STATE-META-INV-07 | `column_visibility.json` ([COMPOSE_COLUMN_PREF_FILE](../../source/compose.manager/include/ColumnLayout.php)) | Global visibility/order preferences | UI-LAYOUT | Write/read: column visibility actions in [Exec](../../source/compose.manager/include/Exec.php), normalization in [ColumnLayout](../../source/compose.manager/include/ColumnLayout.php) | Persistent (`/boot/config/...`) |
| STATE-META-INV-08 | operation receipts (`/tmp/compose-manager-ui-operations/...`) | Hashed folder + operation id | UI-OPS | Write/read: [UiOperation](../../source/compose.manager/include/UiOperation.php) | Ephemeral (`/tmp`) |
| STATE-META-INV-09 | plugin icon cache directory ([COMPOSE_ICON_CACHE_DIR](../../source/compose.manager/include/Defines.php)) | Hash/icon URL keyed files | UI-ICONS | Write/read: [Icon](../../source/compose.manager/include/Icon.php), clear endpoints in [Exec](../../source/compose.manager/include/Exec.php) | Persistent (`/boot/config/...`) |

### Inventory limits (this extraction pass)

- Includes state stores directly evidenced in current code paths and tests for
  stack registration/update/runtime presentation.
- Does not yet claim exhaustive coverage of every host/plugin/global store
  outside Compose Manager's implemented surfaces.
- Does not redefine each owner's data semantics; see the owner contracts.

## Cleanup addendum for stack Delete (outside project folder)

This addendum applies STK-LIFE-19
through STK-LIFE-22.
Classes below are **proposed** unless explicitly marked owner-approved.

| Cleanup ID | Inventory entry | Proposed class | Required Delete behavior (proposed) | Current observed behavior |
| --- | --- | --- | --- | --- |
| STATE-META-CLN-01 | STATE-META-INV-01 (`containers.cache`) | **Required — owner-approved, 2026-11-13** | Remove deleted stack project entry; residual blocks clean-success result. | Purged by [composePurgeDeletedStackCaches](../../source/compose.manager/include/Exec.php). |
| STATE-META-CLN-02 | STATE-META-INV-02 (`update-status`) | **Required** | Remove deleted stack saved evidence entry; residual blocks clean-success result. | Purged by [composePurgeDeletedStackCaches](../../source/compose.manager/include/Exec.php). |
| STATE-META-CLN-03 | STATE-META-INV-03 (`pending-recheck`) | **Required** | Remove deleted stack pending token entry; residual blocks clean-success result. | Purged by [composePurgeDeletedStackCaches](../../source/compose.manager/include/Exec.php). |
| STATE-META-CLN-04 | STATE-META-INV-04 (icon normalize override) | **Required** | Remove per-project override file; residual blocks clean-success result for same-name recreation safety. | Purged by [composePurgeDeletedStackCaches](../../source/compose.manager/include/Exec.php). |
| STATE-META-CLN-05 | STATE-META-INV-05 (`autoupdate.json` path key) | **Required — owner-approved, 2026-11-12** | Remove deleted stack path schedule entry; residual blocks clean-success result because same path can be recreated. Preserve unrelated schedules and global defaults. | Not purged by current Delete flow. |
| STATE-META-CLN-06 | STATE-META-INV-06 (`stack-order`) | **Optional** | Remove stale folder name where present; residual reported as non-blocking remnant. | Not explicitly purged; discovery filters unavailable projects. |
| STATE-META-CLN-07 | STATE-META-INV-08 (UI operation receipts) | **Optional** | Remove/expire stale receipt files without blocking delete success. | Housekeeping-driven retention in [UiOperation](../../source/compose.manager/include/UiOperation.php); no delete-targeted purge. |
| STATE-META-CLN-08 | STATE-META-INV-09 (icon cache) | **Optional** | Best-effort cleanup when stack-specific mapping is provable; residual reported as non-blocking. | `clearIconCache` / `clearAllIconCache` actions exist; delete does not target hashed icon cache files. |
| STATE-META-CLN-09 | STATE-META-INV-07 (`column_visibility`) | **Optional / out of stack scope** | No per-stack delete mutation required (global user preference). | No delete purge, by design. |

## Requirements and acceptance evidence

| ID | Requirement text | Approval status | Evidence / notes |
| --- | --- | --- | --- |
| STATE-META-01 | Maintain one canonical metadata/state inventory and one linked delete cleanup addendum, each with stable IDs, instead of per-contract duplicated file lists. | **Owner-approved via STK-LIFE-20/22; recorded here as adopted scope.** | STK-LIFE-20, STK-LIFE-22 |
| STATE-META-02 | Every addendum entry must be explicitly classed Required or Optional, with no unclassified middle state. | **Owner-approved via STK-LIFE-21; recorded here as adopted rule.** | STK-LIFE-21 |
| STATE-META-03 | Inventory entries record semantics owner explicitly; catalog ownership must not transfer behavioral ownership away from domain contracts. | **Proposed.** | Prevents STATE-META from collapsing UPD-STATE/UI-OPS/UI-LAYOUT policy boundaries. |
| STATE-META-04 | Stack Delete outcomes consume cleanup classes from this addendum: Required residuals imply incomplete/remnant, Optional residuals remain non-blocking but visible. | **Proposed (shared with STK-LIFE alignment).** | Current `cachePurge` booleans in [Exec](../../source/compose.manager/include/Exec.php) do not yet enforce this classification. |
| STATE-META-05 | Addendum must include stack-path-keyed scheduler state (`autoupdate.json`) as Required cleanup for same-path recreation safety. | **Owner-approved, 2026-11-12.** | A leftover absolute-path schedule could silently authorize automatic updates for a different stack recreated at the same path. No current delete-time removal found in [AutoUpdate](../../source/compose.manager/include/AutoUpdate.php) or [Exec](../../source/compose.manager/include/Exec.php). |
| STATE-META-06 | Disposable presentation metadata is an optional shortcut, not an authority or availability prerequisite. Use suitable cached data first where appropriate; on a miss, corruption or insufficient freshness, obtain needed data from its authoritative source. Bypass the cache when freshness is required or its value is suspect. Cache repair/publication must not prevent use of successfully retrieved authoritative data. If authoritative retrieval fails, do not present stale data as current; explicitly historical display may remain where appropriate. | **Owner-approved, 2026-11-12.** | Owner approved cache-aside reads, authoritative fallback and freshness-based bypass, then requested brief inspection of current behavior. Exact freshness rules, lookup batching and repair mechanisms remain undecided; this does not authorize implementation. |
| STATE-META-07 | A view initially displaying cached presentation metadata must arrange nonblocking authoritative revalidation and reconcile both displayed data and cache with the accepted result. Share/coalesce refresh work instead of issuing a lookup per widget. Obsolete responses must not overwrite newer data or revive deleted stacks; refresh failure must not make cached data appear newly verified. | **Owner-approved, 2026-11-12.** | Extends STATE-META-06 with required background revalidation after cache-backed display. Initial rendering must not wait for that revalidation. Exact timing, intervals and coalescing mechanisms remain undecided; no continuous polling or new registry update checks are required by this decision. |
| STATE-META-08 | Failure to refresh does not itself establish that cached presentation data is stale or invalid. Retain otherwise usable last-known data rather than clearing it or substituting stopped/empty state solely because authoritative lookup failed. Known invalidation or contradictory evidence still requires the suitability/bypass rules of STATE-META-06; failed refresh does not establish a new successful verification. | **Owner-approved, 2026-11-13, cached-display/Docker-unavailable scenario.** | Owner accepts potentially stale display because inability to update is not proof of change. Does not approve a mandatory stale badge or popup. Current action safety remains independent of cached display; see OPS-LIFE-25. |
| STATE-META-09 | Distinguish current runtime facts from reusable service presentation details. Reconcile runtime facts against complete accepted authoritative observations, but retain otherwise usable presentation metadata such as last-known service icons across container removal/Down/Up. The Up dialog must reuse that metadata for configured-but-uncreated services; cached container identity/state/address bindings must not become current facts for replacement containers. | **Owner-approved, 2026-11-13, container-replacement/service-retention discussion.** | Presentation enrichment only: Compose configuration remains authoritative for deployment inputs and service selection. No icon can be promised for a service for which no usable metadata has ever been obtained. Does not prescribe separate stores. |
| STATE-META-10 | Bound retained presentation entries by authoritative stack and service membership: Delete clears the stack's metadata, and background cleanup removes entries for stacks confirmed no longer registered/present and services confirmed absent from the complete resolved Compose model, including inactive-profile services. Container absence alone must not prune a still-configured service's presentation details. Failed/partial enumeration or an unavailable projects root is not evidence of absence. | **Owner-approved, 2026-11-13, membership-based pruning with Delete cleanup.** | Supersedes the discussion's tentative stack-lifetime retention for services removed from configuration. Consumes STK-LIFE directory authority; stale cleanup must preserve STATE-META-07's newer-publication/recreation protections. Entry reconciliation is not a hard byte limit. |
| STATE-META-11 | Provide a stack-scoped cache reset/rebuild backstop when retained service-entry count exceeds an agreed ratio to that stack's enumerated potential services. Reset only disposable metadata for the affected stack, not deployment configuration or other owners' persisted state. | **Owner-approved direction, 2026-11-13; threshold and failure policy unresolved.** | Supplements membership-based pruning rather than replacing it. Ratio, minimum allowance, zero/unknown denominator handling, trigger timing, safe reset/publication sequence and rebuild-failure handling require bounded refinement before implementation. No numerical or hard storage bound is approved. |
| STATE-META-12 | A successful authoritative lookup confirming that an icon is absent/removed must clear the cached icon and show the normal fallback, not resurrect an older cached value. A failed/incomplete lookup is not confirmation of absence. For configured-service presentation such as Up, use effective Compose configuration including applicable overrides; runtime container-label presentation remains tied to the source represented by that view. | **Owner-approved, 2026-11-13.** | Narrows STATE-META-09 retention: cache is a reusable copy, never permanent icon configuration. Container absence alone remains insufficient to establish configured icon absence. |
| STATE-META-13 | Do not delay the Up confirmation dialog with an additional blocking configuration lookup solely for icon enrichment. Reuse suitable results from already-required service/profile/configuration resolution where practical; otherwise display usable cached icons or normal fallbacks and revalidate presentation in the background. | **Owner-approved, 2026-11-13, icon-removal approval with dialog-latency caveat.** | Does not remove required service/profile checks or authorize guessed deployment scope. Current `config --services` request returns names, not icon labels; reuse of full effective metadata is not already established by that request. No latency threshold or specific command/query design approved. |
| STATE-META-14 | When the presentation-metadata store (STATE-META-INV-01) cannot be parsed or validated, automatically reset the whole store under its exclusive lock, invalidating all in-flight publisher ownership, and record a warning in the plugin log. Reads report no cached metadata rather than failing. Do not salvage individual entries from unparseable storage. Do not delete and recreate the store file; reset it in place so lock coordination remains on one file. Corruption and store-access/write failures must not be presented as a blocking UI dialog and must not prevent use of authoritative data (STATE-META-06). Applies only to disposable presentation metadata: saved update evidence, pending rechecks, settings, credentials and operation receipts keep their own failure policies. | **Owner-approved, 2026-11-13, issue #202 hotfix.** | Replaces the earlier corrupt-store preservation until explicit clear. Partially parseable storage cannot identify which entries are trustworthy, so recovery is whole-store. Store-access failures remain logged as errors. No stale/repair indicator beyond the log is required. |

### Approved automatic repair of corrupt presentation metadata

Owner decision, 2026-11-13, main `e22a6b3`: STATE-META-14 selects the repair
mechanism that STATE-META-06 left open. [Issue #202](https://github.com/mstrhakr/compose_plugin/issues/202)
reported a blocking "Container metadata unavailable" dialog ("Syntax error")
caused by an unparseable `containers.cache.json`. Unlocked writers in releases
before v2026.11.13 could interleave overlapping writes and leave such
storage; v2026.11.13 then preserved and reported the damage until an explicit
cache clear.

Because the store is not an authority, the owner chose a whole-store automatic
reset with a log warning instead of preservation, partial salvage or a dialog.
The reset rewrites the existing file under the initialization and file locks
rather than unlinking it, because readers and writers coordinate by locking that
file. A reader that observes corruption escalates to exclusive access and
re-validates first, so a concurrent valid rewrite is not discarded.

### Confirmed icon removal and nonblocking Up enrichment

Owner decision, 2026-11-13, baseline `0794723`: STATE-META-12/13.
The owner identified that unconditional historical icon retention would hide
intentional icon removal. An authoritative positive value replaces the cache;
an authoritative confirmed absence clears it; lookup failure alone does
neither. This explicitly narrows the prior reusable-icon retention rule,
without changing membership pruning or selecting a ratio reset threshold.

The owner approved checking effective configuration, with the caveat that
unnecessary icon lookups must not block Up confirmation. Brief static inspection
confirms [the dialog](../../source/compose.manager/javascript/composeManagerMain.js)
requests `getProfileServices`, whose
[handler](../../source/compose.manager/include/Exec.php) invokes
`docker compose ... config --services`. This is existing model-resolution work,
but supplies service names rather than full label metadata. It is not evidence
that icon validation is already free or available in that response.

Acceptance must distinguish explicit icon removal from lookup failure, label
values supplied by another effective override, and configured presentation from
still-existing container labels. Icon enrichment must not add a blocking
dependency to otherwise-ready confirmation. Tests/browser timings were not
executed; no runtime changes are authorized by this decision.

### Approved presentation retention and bounded cleanup direction

Owner discussion, 2026-11-13, baseline `6fc1316`: STATE-META-09 through 11.
The owner wanted reusable icons without repeated blocking Docker lookups,
including in the Up dialog, but rejected indefinite accumulation of generated
names as an acceptable consequence. The resulting shared model separates:

- current runtime observations, which must not preserve a removed container as
  current merely to retain its icon;
- reusable service presentation details, which may survive Down while their
  service remains in the full Compose configuration;
- obsolete stack/service entries, subject to background membership cleanup;
- a ratio-triggered stack reset/rebuild as an additional growth backstop.

The owner explicitly accepted Delete clearing that stack's metadata, promoting
STATE-META-CLN-01's Required class. No other cleanup class is approved by
association. Registration membership belongs to
STK-LIFE;
resolved service/profile membership belongs to
STK-FILES and STK-PROFILES, not Docker container
existence. A failed enumeration cannot safely authorize mass eviction.

The [Up dialog](../../source/compose.manager/javascript/composeManagerMain.js)
already attempts persisted metadata fallback for some services. The owner's
reported frequent question-mark icons are a symptom to investigate during
alignment, not proof that fallback is absent or that a historical icon is
always available. Up must not stop reading authoritative Compose configuration
for execution; STATE-META-09 changes presentation enrichment only.

The proposed stack-lifetime-only retention was discussion input, not an earlier
approval. No arbitrary ratio, age limit or eviction ordering is selected here.
Successful membership reconciliation bounds entry counts relative to the
current model, but cannot alone guarantee a byte ceiling, timely cleanup during
source outages, or bounded size of each value. The ratio backstop remains
partially specified. No new runtime assessment or behavior tests were performed.

### Cached display when authoritative refresh fails

Owner discussion, 2026-11-13, baseline `ed6a11a`: the owner corrected the
proposal that failure to refresh necessarily makes cached data suspect.
Cache remains usable absent evidence against it; failed revalidation alone
does not prove a change. STATE-META-08 refines STATE-META-06/07 without
removing background refresh or allowing a false claim of successful refresh.
Exact failure indication remains undecided, not an approved stale-warning
requirement.

The same scenario establishes
OPS-LIFE-25:
Docker-dependent actions must check availability independently and must not
proceed when Docker is not running. Display continuity is not execution
permission. This does not disable source editing or other non-Docker work.

### Approved background revalidation after cache-backed display

Owner decision, 2026-11-12, baseline `cecaac0`: STATE-META-07.
The owner requested fast cache-backed initial display followed by background
checks against authoritative sources so both cached and displayed metadata
are corrected, and explicitly approved the bounded formulation above.

UI-RUNTIME and other presentation consumers
participate in this shared guarantee; they must not invent separate freshness
authorities. STATE-META-06 still requires direct lookup when cached data is
unsuitable, rather than always displaying suspect data first. Successful
authoritative display remains independent of cache-write success.

Existing background detail prefetch in
[composeManagerMain](../../source/compose.manager/javascript/composeManagerMain.js)
is partial supporting prior art, not proof of consumer-wide revalidation,
cache correction or deletion/recreation safety. Later acceptance coverage must
show fast cached display followed by authoritative reconciliation, changed and
removed metadata, refresh/cache-write failures, shared refresh work, and
obsolete-result rejection. No such tests were executed in this documentation
decision pass. Continuous polling, a refresh daemon, registry checks, timing
thresholds and detailed failure presentation are not approved by implication.

### Approved disposable presentation-cache foundation

Owner decision, 2026-11-12, baseline `1f76f6f`: STATE-META-06 replaces the
narrower pending explicit-clear-versus-automatic-rebuild question. The cache's
purpose is to accelerate access to information available from authoritative
sources, not make those sources inaccessible when the cache fails.

The owner emphasized that every metadata consumer needs an appropriate fallback,
and potentially stale data can require bypass rather than reuse. Recovery is
secondary to obtaining truthful data. This does not reclassify saved update
evidence, settings, credentials or operation receipts as disposable caches.
Publication safety remains required; no particular storage/repair design or
universal time-to-live is approved here.

### Brief implementation comparison for STATE-META-06

**Assess-only, 2026-11-12, revision `1f76f6f`:** static inspection of the
presentation store, UI check adapter, runtime-details endpoint, browser loading,
action dialogs and refresh paths. No runtime tests executed or comprehensive
fulfillment claim.

| Area | Observed behavior | Comparison with approved foundation |
| --- | --- | --- |
| Cache miss for an icon | [composeResolveContainerIcon](../../source/compose.manager/include/Exec.php) tries cached service/name icons, then Docker inspect, memoized within the request. | Existing cache-first/authority-fallback example. A nonempty cached icon is accepted without freshness/context validation in this helper. |
| Runtime detail refresh | [refreshStackRow](../../source/compose.manager/javascript/composeManagerMain.js) explicitly fetches current containers after mutation; detail loading consumes prefetched responses or requests `getStackContainers`. Browser generations reject superseded responses. | Useful existing freshness/invalidation mechanisms, not a general presentation-cache freshness policy. |
| Corrupt/unwritable presentation store | [getStackContainers](../../source/compose.manager/include/Exec.php) reserves cache ownership before Docker collection; reservation failure returns an error. A later cache-publication failure also returns an error instead of the collected container response. | **Confirmed mismatch by trace:** disposable-cache failures can block authoritative lookup or suppress successfully collected data. |
| UI update checks | [composeCheckStackUpdatesForUi](../../source/compose.manager/include/Exec.php) reserves/reads metadata before invoking the shared checker; metadata publication runs in its published callback. | Presentation storage is coupled into the check workflow rather than isolated as optional enrichment. Full cross-store outcome analysis is outside this brief pass. |
| Browser cache-load failure | [loadPersistentContainerCache](../../source/compose.manager/javascript/composeManagerMain.js) logs/warns and resolves with existing in-memory metadata, allowing list loading to proceed. | Browser startup is not wholly blocked, but this path does not itself fetch authoritative replacements; later detail requests still encounter the server-side coupling above. |
| Cached service metadata | [getPersistentContainerInfo](../../source/compose.manager/javascript/composeManagerMain.js) tests existence only. Some non-Update action-dialog paths use live-cache, then persisted-cache, then placeholders; transport failure can also fall back to cached metadata. | No general suitability/freshness check in these lookups. Historical-vs-current display and fallback behavior need later outcome validation. |
| Store lifecycle | [ContainerMetadataCache](../../source/compose.manager/include/ContainerMetadataCache.php) rejects corruption until explicit clear; merges service patches without pruning absent services. | No automatic repair on ordinary access. Absent pruning is a residual stale-entry risk, not proof every consumer displays obsolete services. |

Existing [metadata-store tests](../../tests/unit/ContainerMetadataCacheTest.php)
encode corruption preservation until explicit clear, serialization and rejection
of obsolete publishers. They were read, not run; they do not establish the newly
approved fallback behavior.

**Maintainability/resource observation:** cache access parses the whole JSON
store and changes rewrite it: O(M) in serialized store size, even for one
project. Runtime details perform per-container inspect calls (O(N) container
lookups, plus ancillary work), while icon fallback memoizes within a request.
Moving all reads to unconditional direct lookups would lose the cache's benefit;
the approved foundation instead calls for suitable reuse and selective bypass.
No performance benchmark or new expiry threshold is claimed.

### Approved schedule cleanup classification

Owner decision, 2026-11-12, baseline `44baf16`: **Required** for
STATE-META-CLN-05 / STATE-META-05. Unlike harmless display residue, an enabled
schedule can cause unattended mutations. A newly registered stack at the deleted
stack's absolute path must not silently inherit its old automatic-update policy.
A residual schedule therefore prevents a clean-success Delete result under
STK-LIFE-19/21. This approves the schedule-entry classification only, not the
other proposed classes, the entire inventory, or runtime implementation.

## Current mismatch snapshot (documentation only)

- Delete currently purges four stores only (metadata cache, update status,
  pending recheck, normalize override), with booleans that conflate absent vs
  removal success.
- Required/Optional class enforcement and explicit residual reporting are not
  implemented from this draft.
- `autoupdate.json` cleanup on Delete is not currently wired.

This mismatch record is for later alignment planning, not implementation
authorization in this pass.

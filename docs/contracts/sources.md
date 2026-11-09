# Contract source register

Research snapshot: **2026-11-09**.

## Implementation baseline

The pilot was traced against checkout HEAD
`30e659cbd0cc26d05c0db182b55178233a5a9ffb`, plus the existing working tree.
There were already uncommitted changes to update scripts, update tests, and
testing/runtime-plan documentation, and new registry-retry files.
Those changes were not made, reverted, or committed by this documentation task.

The preference resolver, editor, command builder, and automatic PHP launchers
used by the pilot were not dirty at the initial inspection. Adjacent shell
fallback observations describe the working tree; they are not a specification
of the in-progress retry changes.

## GitHub coverage

Both repositories were queried for **all states**, not just open items. The
1,000-item listing limit exceeded every returned inventory size:

| Repository | Issues | Pull requests | Issue/PR conversation comments | Submitted reviews | Inline review comments |
| --- | ---: | ---: | ---: | ---: | ---: |
| [mstrhakr/compose_plugin](https://github.com/mstrhakr/compose_plugin) | 127 | 73 | 355 | 145 | 302 |
| [dcflachs/compose_plugin](https://github.com/dcflachs/compose_plugin) | 19 | 18 | 88 | 27 | 49 |

Issue and PR titles/descriptions and collected discussions were screened for
relevance. Build/rebuild, Dockerfile, and preference-related matches were
followed in detail against implementation and tests. The conversation-comment
and inline-review endpoints were paginated across each repository, rather than
relying only on embedded comment previews.

This is an inventory/relevance pass across all listed items, **not** a full
review of every historical PR diff or a verified contract for every issue.
Unrelated topics remain evidence for future sections. Attachments, screenshots,
private/deleted material, every linked external project, and other forks were
not exhaustively inspected.

Raw API responses were retained as session research artifacts, not added to the
repository. Reproduce the inventory with `gh issue list --state all --limit
1000 --repo OWNER/REPO` and `gh pr list --state all --limit 1000 --repo
OWNER/REPO`; retrieve bodies, comments, and reviews using their JSON fields.
Use paginated `repos/OWNER/REPO/issues/comments` and
`repos/OWNER/REPO/pulls/comments` API endpoints for conversation and inline
comments. Future sessions should refresh the snapshot, not assume these counts
remain current.

## Evidence directly relevant to the pilot

| Source | Evidence and interpretation |
| --- | --- |
| [#37](https://github.com/mstrhakr/compose_plugin/issues/37) | Users need a way to rebuild locally built application images through the UI. Does not establish that all updates must rebuild. |
| [#94](https://github.com/mstrhakr/compose_plugin/issues/94) | Request to skip pulling buildable images; discussion initially favored making this universal. Later opt-in policy and the current shared engine qualify that older statement. |
| [#149](https://github.com/mstrhakr/compose_plugin/issues/149) | Complex projects motivated compatibility work. The current regression tests explicitly associate this issue with avoiding unconditional builds for published images that also declare an unusable build context. |
| [#168](https://github.com/mstrhakr/compose_plugin/pull/168) | Merged 2026-10-05. Fixes saving when rebuild-on-update is the only changed setting. Its author reports live-Unraid validation; that is historical evidence, not a test executed during this task. |
| [#195](https://github.com/mstrhakr/compose_plugin/pull/195) | Merged 2026-11-04. Establishes shared update ownership and execution, intentional manual/automatic differences, and limitations of historical validation. |
| [dcflachs/compose_plugin#16](https://github.com/dcflachs/compose_plugin/issues/16) | An older update unexpectedly enters Docker build behavior. Shows why a build-capable service and an intended rebuild are not interchangeable. Old advice about absolute paths is not adopted as a new requirement. |
| [Original update script at the pinned legacy revision](https://github.com/dcflachs/compose_plugin/blob/074a9af3a29d14b346e5dae8a700a0649cda601c/source/compose.manager/scripts/compose.sh#L91) | The legacy update branch includes `up -d --build`. Useful compatibility history, not authority to restore unconditional builds in Plus. |

Other screened topics include project identity, profiles, compose/env discovery,
credentials, update evidence, background work, cache ownership, Git deployment,
backups, and UI lifecycle. They require separate boundary traces before becoming
contracts. In particular, future-feature requests must not be mistaken for
currently supported behavior.

## Forum coverage and limitations

Located the primary support threads:

- [Compose Manager Plus](https://forums.unraid.net/topic/197334-plugin-compose-manager-plus/)
- [Original Docker Compose Manager](https://forums.unraid.net/topic/114415-plugin-docker-compose-manager/)

The first page of each thread was fetched and its 25 post articles extracted
(50 total). Pagination advertised 10 Plus pages and 35 original-plugin pages.
Bulk retrieval of the other 43 pages returned HTTP 403. Alternate direct page
reads returned excerpts of Plus pages 9 and 10 and original-plugin page 35.
Those excerpts are **not full-thread coverage**; the forum corpus remains
incomplete. No access restrictions were bypassed.

The [Plus page 10 discussion](https://forums.unraid.net/topic/197334-plugin-compose-manager-plus/page/10/)
contains especially relevant primary evidence:

- September 15 reports describe a local build failing at registry pull and a
  per-stack rebuild checkbox that does not remain saved.
- Follow-up reports distinguish the working global preference from the
  non-persisting per-stack preference.
- An October 8 maintainer reply confirms the opt-in-build regression and reports
  fixes for manual pull-to-build fallback and the per-stack save bug.
- With a pull policy that skips the registry pull, the maintainer advises
  explicitly enabling rebuilding rather than assuming a failed pull will occur.

This corroborates #168 and explains the documented manual compatibility
fallback. It does **not** resolve the pilot's inherit-versus-disable question.
The [Plus page 9 excerpt](https://forums.unraid.net/topic/197334-plugin-compose-manager-plus/page/9/)
also discusses stale update indicators, which belong to a future update-evidence
contract, not this preference contract.

No behavior requirement in the pilot relies solely on an unverified search
summary. Forum statements are paraphrased and linked, not reproduced wholesale.

## Repository evidence

The pilot's main source map is in its [ownership section](build-on-update.md#ownership-and-entry-points).
Relevant existing documentation:

- [User guide: checking and applying updates](../user-guide.md#checking-for-updates)
- [Development guide: shared update pipeline](../development.md#shared-update-pipeline)
- [Testing guide](../testing.md)
- [Legacy migration guide](../legacy-migration.md)
- Inline help in the global settings page and stack editor.

The general configuration guide currently does not enumerate
`BUILD_ON_UPDATE_DEFAULT`; the settings UI, shared update documentation, and
implementation supply the more specific evidence. This register does not claim
every repository document has been audited for consistency.

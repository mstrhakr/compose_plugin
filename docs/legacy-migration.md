# Archived Plugin Migration

## Compatibility Target

The reference is the archived [dcflachs/compose_plugin](https://github.com/dcflachs/compose_plugin/tree/074a9af3a29d14b346e5dae8a700a0649cda601c), revision `074a9af3a29d14b346e5dae8a700a0649cda601c`. It is a fixed compatibility target, not a moving upstream dependency. Plus still requires Unraid 6.12 or newer.

Migration must preserve existing Docker identity and effective configuration, not recreate stacks under a new project name. Installation must not issue stack up/down/update commands. Uncertain ownership must block affected mutations rather than guess.

## Implemented on the Issue Branch

- Exact archived PHP naming, historical autostart naming and Plus-era candidates are considered. Repeated separators in valid identities are preserved in metadata and shell commands.
- Running/stopped containers, named volumes and networks count as ownership evidence. Container working-directory labels corroborate ownership when available; contradictory directories block automatic adoption.
- Competing stack folders cannot automatically adopt the same runtime identity during migration.
- Installers atomically capture a `legacy-origin` marker from original runtime code or parsed active/removed manifests. An active Plus manifest takes precedence over stale original files. Confirmed imports preserve the PHP-derived legacy name even when no labelled resources remain.
- New Plus stacks record their own provenance and do not adopt legacy identities based on display names.
- Existing unversioned pins receive a one-time audit. If another candidate owns resources, the stack is blocked for explicit recovery; the old pin is not silently changed.
- Identity metadata is written under a per-stack lock using atomic file replacement. A versioned `project_identity.json` receipt records name, origin and decision source. Later display renames do not change an audited identity.
- Replacing a pin preserves its original contents as `project_name.pre-migration`. Pin/receipt mismatch after an interrupted write triggers another audit. Failed persistence blocks mutations.
- Confirmed imports recover exact ordered Compose inputs from container `config_files` labels and persist that list in the identity receipt. Conflicting lists, partial evidence on live multi-file stacks and unavailable pinned inputs block actions. New files discovered later do not silently join a pinned list. Explicit Plus file-selection settings opt into normal Plus behavior.
- Archived `compose.yml`, capitalized `Name`, and `envToUse`/`envPath` metadata are supported without renaming original inputs. Current `name` and `envpath` take precedence.
- Configured env files, available profiles, autostart flags and source contents remain intact. Missing configured env or Compose sources block actions rather than silently substitute configuration.
- The existing chooser exposes all valid candidates. Probe/save failures do not offer an override button; backend choices recheck Docker availability.
- Managed-stack lookup cannot bypass migration failures through an unmanaged basename fallback. Blocked scheduled updates retain their retry opportunity; manual updates return a conflict instead of launching under another name.

## Recovery and Limitations

Existing pins predate provenance tracking, so an explicit old choice cannot reliably be distinguished from a bad automatic pin. Those ambiguous repairs require owner confirmation. Never automatically rename containers, volumes, networks, stack folders or display names to resolve this uncertainty.

Working-directory labels can be absent, particularly when only volumes or networks remain. Matching resource names then remain weaker evidence. No automatic algorithm can reconstruct an arbitrarily renamed historical project from a lost metadata directory.

The archived indirect wrapper used filesystem enumeration order. Runtime labels now preserve its historical ordered inputs when available. A live multi-file stack with missing/contradictory order evidence is blocked. Without any labelled resources, current archived-style discovery is pinned once; lost historical configuration cannot be reconstructed from absent evidence. When only volumes remain and multiple files could have contributed, restore the historical inputs/evidence before attempting migration.

Back up configuration/project metadata before installing a test build. The per-pin backup is not a backup of application data, all stack metadata or external Compose directories. Do not delete conflicting Docker resources merely to make migration proceed.

## Automated Coverage

`ProjectIdentityTest` covers issue #183, historical naming variants, exact pin round-trips, explicit choices, old bad pins, provenance, conflicts, resource types, invalid/offline/persistence failures, duplicate folders, foreign directories and all standard mutation gates. A combined indirect fixture checks files, overrides, env, profiles and unchanged contents.

Bats executes the actual shell wrapper with fake Docker to verify exact `-p` preservation and invalid-name rejection. PHP integration tests execute actual autostart/shutdown scripts and the PHP args provider inside a temporary runtime, checking identity, file order, env, profiles and blocked actions. Scheduled/manual update regressions verify exact inputs and safe retry behavior. Concurrent subprocess readers verify complete receipts.

Playwright exercises the actual chooser function in an isolated browser page. Four opt-in Docker cases cover running, stopped and volume-only imports, reversed multi-file precedence, explicit container names and bind-backed data. They check container state/identity and persistent data continuity. Bind tests use a generated Docker volume's host path, never a user directory.

## Archived History and Installer Audit

The following upstream transitions were inspected and covered with fixtures:

- `d68d9b0` (May 2022): event scripts changed `Name` references to `name`.
- `a0bb162` (July 2022): `compose.yml` changed to `docker-compose.yml`.
- `95f758f` (August 2022): sanitizer added lowercasing; valid lowercase runtime identities remain preserved.
- `bfb3d9c` (November 2022): autostart gained its distinct sanitizer.
- `d94e67d` (September 2023): configurable project root.
- `950105b` (December 2023) and `a87c9f9` (January 2024): `envToUse` changed to intermediate `envPath`, before the later lowercase `envpath` format.
- `16e0875` (February 2024): available-profile metadata.
- `e718300` (August 2024): env-file quoting, covered by paths containing spaces.

Local Community Applications `Apps.page`/`scripts/downloadPlugin` invokes `plugin install` without first removing the old plugin. The local Unraid plugin-manager installer executes the incoming manifest before replacing the old registration. Explicit removal retains the old manifest under `/boot/config/plugins-removed/`; installer tests cover that fallback and stale-Plus precedence. This source audit does not replace a real-host CA reinstall test.

Commands are documented in [the testing guide](testing.md#archived-plugin-migration).

## Local Gates

- [x] Audit archived history for meaningful metadata/action differences and add compatibility fixtures.
- [x] Audit CA/plugin-manager replacement ordering and test runtime/active/removed manifest provenance detection.
- [x] Persist historical ordered Compose inputs; block conflicting, missing and partial evidence.
- [x] Execute autostart/shutdown and scheduled/manual update paths against isolated imported fixtures.
- [x] Exercise real Docker resource/data continuity and concurrent metadata writes.

## Remaining Host Release Gates

- [ ] Verify real Community Applications reinstall preserves origin, custom project roots and behavior when storage is temporarily unavailable.
- [ ] Exercise original-to-Plus installation and reboot on supported Unraid versions, checking obsolete events/packages and legacy WebUI patches without restoring stale core files.
- [ ] Run host-level autostart, scheduled update and shutdown against imported stacks, including external env files, relative bind mounts and `container_name` configurations.
- [ ] Verify the complete live conflict/recovery flow and safe rollback with backed-up metadata.

The local code gaps are addressed and can proceed to PR review. Automated validation does not replace the host-level gates required before claiming a release-ready seamless upgrade.
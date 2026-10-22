# Archived Plugin Migration

## Compatibility Target

The reference is the archived [dcflachs/compose_plugin](https://github.com/dcflachs/compose_plugin/tree/074a9af3a29d14b346e5dae8a700a0649cda601c), revision `074a9af3a29d14b346e5dae8a700a0649cda601c`. It is a fixed compatibility target, not a moving upstream dependency. Plus still requires Unraid 6.12 or newer.

Migration must preserve existing Docker identity and effective configuration, not recreate stacks under a new project name. Installation must not issue stack up/down/update commands. Uncertain ownership must block affected mutations rather than guess.

## Implemented on the Issue Branch

- Exact archived PHP naming, historical autostart naming and Plus-era candidates are considered. Repeated separators in valid identities are preserved in metadata and shell commands.
- Running/stopped containers, named volumes and networks count as ownership evidence. Container working-directory labels corroborate ownership when available; contradictory directories block automatic adoption.
- Competing stack folders cannot automatically adopt the same runtime identity during migration.
- Installers capture a `legacy-origin` marker before replacing detected original-plugin code. Confirmed imports preserve the PHP-derived legacy name even when no labelled resources remain.
- New Plus stacks record their own provenance and do not adopt legacy identities based on display names.
- Existing unversioned pins receive a one-time audit. If another candidate owns resources, the stack is blocked for explicit recovery; the old pin is not silently changed.
- Identity metadata is written under a per-stack lock using atomic file replacement. A versioned `project_identity.json` receipt records name, origin and decision source. Later display renames do not change an audited identity.
- Replacing a pin preserves its original contents as `project_name.pre-migration`. Pin/receipt mismatch after an interrupted write triggers another audit. Failed persistence blocks mutations.
- Confirmed imports retain original direct-file selection and indirect `*compose*.yml` discovery, with stack-local override last. Explicit Plus file-selection settings opt into normal Plus behavior.
- Configured env files, available profiles, autostart flags and source contents remain intact. Missing configured env or Compose sources block actions rather than silently substitute configuration.
- The existing chooser exposes all valid candidates. Probe/save failures do not offer an override button; backend choices recheck Docker availability.

## Recovery and Limitations

Existing pins predate provenance tracking, so an explicit old choice cannot reliably be distinguished from a bad automatic pin. Those ambiguous repairs require owner confirmation. Never automatically rename containers, volumes, networks, stack folders or display names to resolve this uncertainty.

Working-directory labels can be absent, particularly when only volumes or networks remain. Matching resource names then remain weaker evidence. No automatic algorithm can reconstruct an arbitrarily renamed historical project from a lost metadata directory.

The archived indirect wrapper used filesystem enumeration order. This branch retains current directory enumeration order, but does not yet recover historical file ordering from container config-file labels. Changing directory contents before migration can therefore require additional validation.

Back up configuration/project metadata before installing a test build. The per-pin backup is not a backup of application data, all stack metadata or external Compose directories. Do not delete conflicting Docker resources merely to make migration proceed.

## Automated Coverage

`ProjectIdentityTest` covers issue #183, historical naming variants, exact pin round-trips, explicit choices, old bad pins, provenance, conflicts, resource types, invalid/offline/persistence failures, duplicate folders, foreign directories and all standard mutation gates. A combined indirect fixture checks files, overrides, env, profiles and unchanged contents.

Bats executes the actual shell wrapper with fake Docker to verify exact `-p` preservation and invalid-name rejection. Playwright exercises the actual chooser function in an isolated browser page. The opt-in Docker test checks stable container identity through migration/up and volume-data continuity through down/up.

Commands are documented in [the testing guide](testing.md#archived-plugin-migration).

## Remaining Release Gates

- [ ] Audit older archived releases for meaningful metadata/action differences; expand fixtures where needed.
- [ ] Verify real Community Applications reinstall ordering captures legacy origin, including custom project roots and temporarily unavailable storage.
- [ ] Corroborate and persist historical indirect file ordering from runtime config-file labels where available; block unresolved ordering differences.
- [ ] Exercise original-to-Plus installation and reboot on supported Unraid versions, checking obsolete events/packages and legacy WebUI patches without restoring stale core files.
- [ ] Run host-level autostart, scheduled update and shutdown against imported stacks, including external env files, relative bind mounts and `container_name` configurations.
- [ ] Verify the complete live conflict/recovery flow and safe rollback with backed-up metadata.

Passing unit tests and an isolated Docker regression does not replace these host-level gates. Keep this issue branch separate from release selection until the intended beta scope is agreed.
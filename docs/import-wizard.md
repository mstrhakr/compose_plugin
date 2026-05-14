# Import Wizard

The Import Wizard lets you convert existing Docker Manager containers into a Compose Manager stack with a guided 5-stage workflow.

## Where to Find It

1. Go to **Docker -> Compose**.
2. Click **Import from Docker Manager**.
3. Select one or more existing containers to import.

## What the Wizard Imports

For each selected container, Compose Manager reads Docker metadata and converts it into Compose service definitions, including:

- Image name
- Container name
- Ports
- Environment variables
- Volumes and bind mounts
- Labels (including Unraid-specific WebUI/icon labels)
- Existing healthcheck (if present)
- Network mode and network attachments

The wizard can also auto-detect likely healthchecks for common services when no healthcheck exists.

## 5-Stage Workflow

### Stage 1: Select Containers

- Shows import candidates from Docker Manager.
- You can select individual containers or use **Select All**.
- At least one container is required to continue.

### Stage 2: Select Options

Configure stack-wide options:

- **Stack Name** (required)
- **Create Stack Network** and network name
- **External Networks** to make available during import
- Post-import behavior:
  - **Stop original containers**
  - **Remove original containers**
  - **Start imported stack**

Notes:

- If **Remove original containers** is enabled, **Stop original containers** is enforced.
- The stack name is sanitized to a safe folder-style name for preview.

### Stage 3: Configure Containers

Per-service configuration:

- Container name (must be unique and non-empty)
- Network mode (default/bridge/host/none)
- Network attachments (stack network and selected external networks)
- Healthcheck command and timing settings
- Read-only port view with conflict indicators

Validation behavior:

- Duplicate or empty container names disable **Next**.
- Host port conflicts are highlighted so you can review them before import.

### Stage 4: Configure Dependencies

Define Compose `depends_on` relationships between imported services:

- Add dependencies service-by-service
- Choose condition:
  - `service_started`
  - `service_healthy` (available only when target service has a healthcheck)

Safety checks:

- Dependency cycles are detected and block progress.
- A calculated startup order preview is shown when possible.

### Stage 5: Review & Import

Compose Manager generates the configuration and presents a final review screen with:

- `compose.yaml` (with parse validation)
- `.env` content (when needed)
- Override content for labels/icons (when needed)
- Validation result and any parse errors
- Import summary (services, networks, healthchecks, dependencies)

Click **Import** to write files and complete the transfer.

## What Happens on Import

When you confirm import:

1. A new stack is created in the configured projects directory.
2. Generated files are written to that stack folder.
3. Selected source containers can be stopped and/or removed (based on your options).
4. The stack opens in the editor.
5. If selected, Compose Manager runs **Compose Up** for the imported stack.

## Tips and Best Practices

- Start with related containers that should live in the same stack.
- Review network mode carefully: `host`/`none` intentionally limit network attachments.
- Keep healthchecks when possible; they improve dependency sequencing and startup reliability.
- Resolve any port conflicts before starting the new stack if services must run in parallel.
- If this is a production workload, take a backup before removing original containers.

## Troubleshooting

### No containers appear in Stage 1

- Verify containers exist in Docker Manager and are readable by the plugin.

### Cannot continue from Stage 3

- Fix duplicate or empty container names.

### Cannot continue from Stage 4

- Remove circular dependencies until the cycle warning is gone.

### Import fails at the final step

- Re-check stack name validity.
- Re-open the wizard and re-import if source containers changed during the session.
- Check Unraid syslog/plugin logs for server-side error details.

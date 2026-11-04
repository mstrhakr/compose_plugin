# User Guide

## Managing Stacks

### Creating a Stack

1. Navigate to **Docker → Compose**
2. Click **Add Stack**
3. Enter a stack name and optional description
4. Edit your compose file (`compose.yaml`, `compose.yml`, `docker-compose.yaml`, or `docker-compose.yml`)
5. Click **Compose Up**

### Stack Editor

The editor provides four tabs for managing your stack:

| Tab | Purpose |
|-----|---------|
| **Compose File** | Edit your stack compose file with syntax highlighting |
| **Settings** | Configure autostart, profiles, and environment files |
| **Env** | Edit environment variables for your stack |
| **Web UI** | Add Unraid-specific labels for web UI integration |

![Editor - Compose File](images/editor-composeFile.png)

![Editor - Settings](images/editor-settings.png)

![Editor - Env](images/editor-env.png)

![Editor - Web UI Labels](images/editor-webUI.png)

### Stack Operations

Each stack supports the following actions:

| Action | Description |
|--------|-------------|
| **Compose Up** | Start all services in the stack |
| **Compose Down** | Stop and remove all containers |
| **Update Stack** | Pull latest images and recreate containers |
| **Edit Stack** | Open the stack editor |
| **Remove Stack** | Delete the stack configuration |

### Checking for Updates

Use **Check Updates** on a stack to query the registry for newer image versions. Results are cached until the next manual or scheduled check (see [Update Checking settings](configuration.md#update-checking)).

Individual, **Check All**, and periodic browser update checks inspect all existing stack containers, including stopped and profiled containers. They do not download images, build images, or start/stop services. Services without an existing container are not included.

Services built locally from a `build:` section without a published `image:` are checked against the base image of their Dockerfile's final stage. An update is shown when the registry's base image no longer matches the layers the local image was built on. To apply it, enable **Build on update** for the stack; **Update Stack** then rebuilds with `docker compose build --pull` so the newer base image is used (base images that only exist locally are built from the local copy).

Individual updates, bulk updates, and scheduled auto-updates all use the same update engine and honor the stack's **Build on update** setting, falling back to the global default. When refreshing base images fails, the engine logs a warning before retrying the build with local base images.

Automatic updates only operate on eligible running services, not stopped siblings or undeployed profile services. With **Build on update** enabled, each scheduled run performs a cached build of those services to discover base-image or source changes; containers are recreated only when their running image IDs differ from the resulting local images. Unchanged and stopped stacks do not produce success notifications. Build failures are reported through logs and notifications without proceeding to recreation.

Manual **Update Stack** also reconciles Compose configuration changes, even when image IDs are unchanged. **Force Update** additionally forces container recreation; it does not bypass the **Build on update** setting. For compatibility with local build tags, a manual update whose pull fails can retry without buildable services and rebuild them even when that setting is disabled; automatic updates do not enable this fallback.

Updates preserve deployed replica counts and do not start stopped dependencies or one-off jobs. Paused/restarting services and services with a mixture of running and stopped replicas are skipped with an explanation rather than changing their state unexpectedly; other eligible services can still update. Automatic updates report these cases as incomplete updates. Manual updates leave stopped services unchanged by default, including fully stopped stacks.

The **Include stopped services — they will be started** checkbox in the Update, Force Update, and Update All dialogs permits existing stopped services (including stopped replicas) to be redeployed and started. It does not activate services without an existing container. Update dialogs show existing containers across deployed profiles instead of asking which profile to start. Set **Include Stopped Services in Manual Updates by Default** in the main plugin settings to choose the initial checkbox state; a dialog override affects only that operation. With action warnings disabled, manual updates use the saved default directly. Automatic updates always ignore this manual preference.

When updates are available, clicking **Update Stack** opens a confirmation dialog listing each container alongside its current and incoming image digest. Containers that are already up to date are dimmed.

#### Changelogs

If the [docker.versions](https://github.com/phyzical/docker.versions) Unraid plugin is installed, a **Changelog** link appears below the digest for each container with a pending update. Clicking it opens the release notes for that image in a modal. No configuration is required — Compose Manager detects docker.versions automatically.

## Autostart

Enable autostart to have stacks start automatically when the Unraid array starts.

1. Click the autostart toggle on a stack
2. Optionally configure default profiles for autostart
3. Stacks will start in order when the array starts

### Force Recreate

Enable "Autostart Force Recreate" in settings to always recreate containers during autostart.

### Recreate After Label Changes

When Unraid-specific labels are modified via the Web UI, Compose Manager can optionally recreate affected containers so updated label metadata is applied without manual recreation steps.

### Stack Recheck

Use the stack "Recheck" action in the UI to re-evaluate a stack's state on the server. Results are persisted server-side to help with diagnostics and automated checks.

### Backup / Restore

Compose Manager provides a Backup & Restore interface under **Settings → Compose → Backup / Restore**:
- **Create Backup** - Create a compressed archive of selected stacks and configuration
- **List / Browse Backups** - Inspect available backup archives and their contents
- **Restore** - Restore selected stacks from an archive (select which stacks to restore)
- **Schedule** - Configure periodic backups with frequency and retention

### Hiding and Filtering Compose Containers

Use **Hide Compose Containers from Docker Page** to hide Compose-managed containers from the native Docker Containers table when **Show Compose in Header Menu** is disabled (inline mode). Use **Hide Compose Containers from Docker Dashboard Tile** to hide them from the native Dashboard tile when **Show Dashboard Tile** is enabled. These display options avoid duplicate entries without changing how containers run; no legacy WebUI patch is required.

### Display Options

Enable the option to display Compose stacks above native Docker containers on the Dashboard for clearer stack-focused views.

## Environment Files

Specify custom `.env` file paths per stack in the Settings tab. This is useful when:

- Your env file is in a different location
- You want to share env files between stacks
- You have environment-specific configurations

## Indirect Stacks

Reference compose files stored outside the default projects folder. Useful for:

- Keeping compose files with your application data
- Managing compose files in version control
- Sharing configurations across servers

## Web UI Integration

### Unraid Docker Labels

Add Unraid-specific labels to integrate containers with the native Docker UI:

```yaml
services:
  myapp:
    image: myapp:latest
    labels:
      net.unraid.docker.webui: "http://[IP]:[PORT:8080]/"
      net.unraid.docker.icon: "https://example.com/icon.png"
```

The **Web UI** tab in the editor provides a visual interface for adding these labels.

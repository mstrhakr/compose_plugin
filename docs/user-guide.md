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

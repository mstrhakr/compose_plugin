# Configuration

Access settings via **Settings → Compose** in the Unraid web UI.

## Settings Reference

### General Settings

| Setting | Default | Description |
|---------|---------|-------------|
| **Output Style** | Terminal | Choose between terminal (ttyd) or basic output for compose operations |
| **Projects Folder** | `/boot/config/plugins/compose.manager/projects` | Location where compose project directories are stored. Changing this path will not move existing project folders. |
| **Autostart: Recreate** | No | Use `--force-recreate` when autostarting stacks to handle network-related start failures. |
| **Autostart: Wait for Docker** | No | Wait for Docker's autostart containers to finish before starting compose stacks. Useful when stacks depend on non-compose Docker containers. |
| **Autostart Docker Wait Timeout** | 120 | Seconds to wait for Docker autostart containers to stabilize (applies only when "Autostart: Wait for Docker" is enabled). |
| **Autostart Timeout** | 300 | Maximum time to wait for each stack to start during autostart (seconds). |
| **Create Missing External Networks** | No | Before a stack starts (Compose Up, Update, autostart), create any network it declares `external: true` that does not exist yet, as a plain bridge network. Docker Compose never creates external networks itself. Networks that need another driver or options (for example macvlan) must still be created by hand. |

### Display Options

| Setting | Default | Description |
|---------|---------|-------------|
| **Show in Header Menu** | No | Display Compose Manager as a separate page in the header navigation bar. |
| **Show Dashboard Tile** | Yes | Display a Compose Stacks tile on the Dashboard showing stack status at a glance. |
| **Hide Compose Containers (Dashboard Tile)** | No | Hide containers managed by Compose stacks from Unraid's Docker Containers dashboard tile. This avoids duplicate entries when both tiles are visible. Requires "Show Dashboard Tile". |
| **Hide Compose Containers (Docker Page)** | No | Patch the native Docker UI to hide or filter Compose-managed containers. See the Web UI Patches section for version limitations. |
| **Show Compose Above Docker Containers** | No | When the Docker page is displayed without tabs, move the Compose Stacks section above the built-in Docker Containers section. |
| **Expand Stacks by Default** | No | Automatically expand all stack detail rows when the page loads. |

### Update Checking

| Setting | Default | Description |
|---------|---------|-------------|
| **Auto Check for Updates** | No | Automatically check for container image updates when the Compose page loads. |
| **Auto Check Interval (days)** | 1 | How often to check for updates (examples: 0.04 hourly, 1 daily, 7 weekly). |
| **Clear Update Cache** | — | Clear cached update status if update checks show incorrect results. |

### Advanced

| Setting | Default | Description |
|---------|---------|-------------|
| **Legacy Patch Web UI** | No | Enable integration patches for the native Docker manager UI on Unraid ≤ 6.11. Not required on Unraid 6.12 and later. Use the Patch/Unpatch buttons to apply or remove the patch. |
| **Debug to Log** | No | Enable debug logging to syslog to troubleshoot Compose command output. |

### Backup Settings

| Setting | Default | Description |
|---------|---------|-------------|
| **Backup Destination** | `/boot/config/plugins/compose.manager/backups` | Filesystem path to store manual and scheduled backups. Leave blank to use the default. |
| **Backup Retention** | 5 | Number of most recent backups to keep (older archives are removed automatically). Set to 0 for unlimited retention. |
| **Backup Schedule Enabled** | No | Toggle to enable scheduled backups. |
| **Backup Schedule Frequency** | daily | Frequency for scheduled backups (`daily`, `weekly`, `monthly`). |
| **Backup Schedule Time** | `03:00` | Time of day to perform scheduled backups. |

### Restore Operations

Restore options are available in the UI to restore stacks from backup archives or upload a backup file; consult the Restore section in the UI for step-by-step instructions.

## Output Styles

### Terminal (ttyd)

- Full terminal output with colors and real-time updates
- Interactive terminal session
- Best for debugging and watching build progress

### Basic

- Simple text output
- Lower resource usage
- Good for headless or automated operations

## Projects Folder

The default location stores all compose configurations on the USB flash drive, ensuring they persist across reboots.

**Structure:**

``` text
/boot/config/plugins/compose.manager/projects/
├── stack-name/
│   ├── compose.yaml | compose.yml | docker-compose.yaml | docker-compose.yml
│   ├── compose.override.yaml | compose.override.yml | docker-compose.override.yaml | docker-compose.override.yml (optional)
│   ├── .env (optional)
│   ├── profiles (auto-generated)
│   └── default_profile (optional)
└── another-stack/
    └── ...
```

Compose Manager supports all four standard Compose file names and preserves the filenames already present in each stack.

## Web UI Patches

This setting controls small compatibility patches that change how Compose-managed containers are presented in Unraid's native Docker UI. These patches only affect UI rendering and metadata — they do **not** stop containers or change how they run.

### Legacy UI Patch

- **Legacy behavior (Unraid < 6.12)** — On older Unraid releases the patch adds Compose-specific metadata (e.g. `net.unraid.docker.managed`) and tweaks the Docker manager so Compose-managed containers are recognized. Practical effects include skipping native update checks for those containers and preventing them from being treated like native Docker-managed containers in the UI. Use this when running an older Unraid release so Compose can interoperate cleanly with the Docker manager. This is enabled in the settings under "Patch UI"

### Hide Compose Containers from Docker UI via Patch

- **Hide patches (Unraid 6.12–7.2)** — For Unraid 6.12 through 7.2 we offer a different patch that **removes** Compose-managed containers from the Docker page and Dashboard tile entirely (they remain visible through the Compose Stacks tile). Use the hide patch to remove duplicate entries and declutter the Docker UI when you prefer to manage Compose stacks separately. This is enabled in the settings under "Hide Compose Containers from Docker UI via Patch"

Notes and version limitations:

- Unraid 6.12 and later integrate the basic "ignore" behavior natively; the legacy patch is not required for that functionality.
- The explicit "hide" patches are provided only for **6.12–7.2**. If you are on a version outside this range, check compatibility before enabling the hide option.
- Patch files are stored under `source/compose.manager/patches/` and are applied only when a matching Unraid version is detected.

## Debug Logging

Enable debug logging to troubleshoot issues. Logs are written to the Unraid syslog.

View logs with:
```bash
tail -f /var/log/syslog | grep compose
```

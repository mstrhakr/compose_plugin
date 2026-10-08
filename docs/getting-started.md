# Getting Started

## Requirements

- Unraid 6.12.0 or later (PHP 8 is required; first included in Unraid 6.12.0)
- Docker service enabled

## Installation

### Via Community Applications (**NOT YET AVAILABLE**)

1. ~~Open the **Apps** tab in Unraid~~
2. ~~Search for "Compose Manager"~~
3. ~~Click **Install**~~

### Manual Installation

1. Navigate to **Plugins → Install Plugin**
2. Enter the plugin URL:
   ```
   https://raw.githubusercontent.com/mstrhakr/compose_plugin/main/compose.manager.plg
   ```
3. Click **Install**

## First Steps

After installation:

1. Navigate to **Docker → Compose** in the Unraid web UI
2. Click **Add Stack** to create your first compose stack
3. Enter a name for your stack
4. Edit the `compose.yaml` file using the built-in editor
5. Click **Compose Up** to start your stack

![Compose Manager Interface](images/compose.png)

## Dashboard Widget

Compose Manager adds a widget to your Unraid dashboard showing stack status at a glance.

![Dashboard Widget](images/dashboard.png)

## Next Steps

- [User Guide](user-guide.md) - Learn about all the features
- [Configuration](configuration.md) - Customize your settings
- [Backup & Restore](configuration.md#backup--restore) - Configure backups and restores via **Settings → Compose → Backup / Restore** (manual and scheduled options)

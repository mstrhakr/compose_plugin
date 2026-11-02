<div align="center">
  <img src="docs/images/banner.png" alt="Compose Manager Plus" width="900">

![GitHub Repo stars](https://img.shields.io/github/stars/mstrhakr/compose_plugin)
![GitHub branch check runs](https://img.shields.io/github/check-runs/mstrhakr/compose_plugin/main)
![GitHub Downloads (all assets, latest release)](https://img.shields.io/github/downloads/mstrhakr/compose_plugin/latest/total)
![GitHub Closed Issues](https://img.shields.io/github/issues-closed/mstrhakr/compose_plugin)
![GitHub Closed Pull Requests](https://img.shields.io/github/issues-pr-closed/mstrhakr/compose_plugin)

</div>

Compose Manager installs the Docker Compose CLI plugin on your unRAID server and provides a comprehensive web-based interface to create, run, update, back up, and restore Compose stacks directly from the unRAID dashboard. It also supports Git-backed stacks, deploying a compose file from a repository branch, with optional scheduled change notifications or automatic deploys. Other features include per-stack autostart, scheduled image updates, an integrated terminal, background command execution with notifications, optional UI integration to hide Compose-managed containers, and tooling for testing and CI.

## Screenshots

<details>
  <summary>Screenshots of Compose Manager Plus on Unraid</summary>

### Main Compose Manager Interface

#### Compose Page

![Compose Manager UI](docs/images/compose.png)

#### Dashboard Integration

![Dashboard Stacks](docs/images/dashboard.png)

### Stack Editor

The built-in editor provides multiple tabs for managing your compose stack:

#### Compose Tab

![Editor - Compose Tab](docs/images/editor-composeFile.png)

#### Env Tab

![Editor - Env Tab](docs/images/editor-env.png)

#### WebUI Labels Tab

![Editor - WebUI Labels Tab](docs/images/editor-webUI.png)

#### Settings Tab

![Editor - Settings Tab](docs/images/editor-settings.png)

#### Theme Support

![Editor - Compose Tab with the Azure theme applied](docs/images/theme-support.png)

### Settings

#### Settings - Main

![Settings - Main Tab](docs/images/settings-main.png)

#### Settings - Updates

![Settings - Updates Tab](docs/images/settings-updates.png)

#### Settings - Backups

![Settings - Backups Tab](docs/images/settings-backups.png)

#### Settings - Log Viewer

![Settings - Logs Tab](docs/images/settings-logs.png)

</details>

## Features

- **Docker Compose Integration** - Installs the Docker Compose CLI plugin (v5 by default) and manages stacks on your unRAID server.
- **Web UI Management** - Create, edit, and manage Compose stacks directly from the unRAID dashboard.
- **Stack Operations** - Start, stop, restart, update, pull/build, and remove stacks with one click (supports profiles and override files).
- **Context Menu** - Rich context menu on every stack icon with state-aware actions (see [Context Menu](#context-menu) below).
- **Container Context Menu** - Right-click individual containers to open a WebUI, console, or logs; start, stop, pause, resume, or restart individual containers without touching the whole stack.
- **Auto-Updates** - Per-stack scheduled image update checks using SHA-256 digest comparison, with a configurable scheduler, bulk "Run Now", and toggle-all controls.
- **Image Pinning** - Images referenced with a `@sha256:` digest are automatically detected as pinned and will not trigger update alerts.
- **Background Execution** - Any stack action (up, down, update, pull, etc.) can be dispatched in the background; a notification is shown on completion and the UI polls for when the lock is released.
- **Expandable Stack Details** - Click a stack row to expand an inline Docker-tab-style container table showing update status, image source/tag, network, IP, and port mapping for each container.
- **Autostart & Shutdown** - Configurable per-stack autostart with optional force-recreate, wait-for-Docker, and configurable timeouts. Graceful shutdown handling.
- **Visibility & Filtering** - Optionally hide Compose-managed containers from the native Docker UI and Dashboard tile to avoid duplicate entries (behavior varies by unRAID version).
- **Backup & Restore** - Manual and scheduled backups with selective restore from the UI.
- **Web Terminal** - Integrated ttyd terminal for live, colorized compose command output.
- **Profiles** - Full support for Docker Compose profiles, including multi-profile selection per action.
- **External Paths** - Compose files and env files can live outside the default projects folder (external compose path and env path per stack).
- **Override File Management** - Centralized management of override files (`compose.override.yaml`, `compose.override.yml`, `docker-compose.override.yaml`, `docker-compose.override.yml`) with service rename handling.
- **Stack Editor** - Full-screen modal editor with five tabs: Compose (YAML with live validation), ENV, Web UI Labels (icon/WebUI/shell per service via override file), Sources (compose files, external paths, and Git settings), and Settings (name, description, icon URL, WebUI URL, and default profile). Ctrl+S to save, Esc to close.
- **Git-Backed Stacks** - Create a stack from a Git repository or move an existing stack into Git. Review branch changes, deploy a selected commit, and optionally receive notifications or deploy new commits automatically. Private repositories support HTTPS credentials and SSH deploy keys. See [Git Stacks](docs/git-stacks.md).
- **Column Customizer & Layout** - Choose which stack and service columns are visible and in what order; extra detail fields (for example SHA snippets and force-update links) follow your saved layout in the Compose tab.
- **Compose File Discovery** - Automatically detects all four standard compose file names (`compose.yaml`, `docker-compose.yaml`, `compose.yml`, `docker-compose.yml`).
- **Filename Preservation** - Existing compose and override filenames are preserved; upgrades do not auto-rename your stack files.
- **Build Stack Support** - Stacks with a `build:` section in the compose file are detected automatically; context menu and update labels adapt ("Build", "Build & Up", "Update & Rebuild").
- **Indirect Stack Support** - Register a stack pointing to a compose file stored anywhere on the array (outside the projects folder) using an "Indirect Path".
- **Security Hardening** - Path traversal prevention, shell injection hardening with safe argument arrays, XSS escaping throughout, and strict input validation.
- **Developer & Testing Tools** - Unit tests (PHPUnit), shell tests (BATS), live-server browser tests (Playwright), static analysis (PHPStan), and CI workflows.

## Installation

### Community Applications

Install via the **Community Applications** plugin in unRAID by going to the 'Apps' tab and searching for 'Compose Manager Plus'.

![Screenshot of both Compose Manager Plus listings in the CA App Store](docs/images/ca-app-store.png)

Once there you will see both the main release and the beta release available to be installed. See below if you have Compose Manager already.

### Manual Install

Install manually by navigating to:

**Plugins → Install Plugin** and entering the plugin URL:

Main Release:

```url
https://raw.githubusercontent.com/mstrhakr/compose_plugin/main/compose.manager.plg
```

Beta Release:

```url
https://raw.githubusercontent.com/mstrhakr/compose_plugin/dev/compose.manager.plg
```

### Migrating from the Original Docker Compose Manager

The archived dcflachs plugin and Plus share the same internal plugin name and persistent project structure; they cannot be installed together. Plus checks historical project-name rules against existing Docker containers, volumes and networks rather than renaming those resources.

Back up your plugin configuration and project metadata before upgrading. Keep existing stacks running when possible: their Docker labels help identify the correct runtime project. There is no general requirement to run `compose down` before migration.

#### Standard migration path

- Go to App Tab (Community Applications/CA)
- Search for 'Compose Manager Plus'
- Press (re)install in Community Applications.
- Check existing stack status before running stack actions.

If identity ownership is ambiguous, Plus blocks mutations and offers explicit project choices. An old incorrectly pinned identity also requires confirmation instead of silently targeting another project. Docker-offline or metadata-write failures cannot be bypassed through that chooser.

See [legacy migration details and release-validation status](docs/legacy-migration.md) for preserved settings, recovery behavior and remaining validation gates.

### Return to the Original Docker Compose Manager

The original dcflachs Docker Compose Manager is deprecated. If you need to return to it, follow these steps:

#### Show Deprecated Applications in Community Applications

- Go to `Apps` tab (Community Applications/CA)
- Go to `Settings` in the bottom of the left sidebar
- Set `Hide Deprecated Applications` to `No`.

![Screenshot of Hide Deprecated Applications setting set to no](docs/images/reinstall-setting.png)

#### Remove 'Compose Manager Plus' and reinstall 'Docker Compose Manager'

- Go to `Apps` tab (Community Applications/CA)
- Search for `Compose Manager` at the top.
- Find currently installed version and press `Actions`->`Uninstall` to uninstall.
- Find `Docker Compose Manager` and press `Actions`->`(Re)Install` to install.

![Screenshot of the Actions menu for the deprecated dcflachs Compose Manager plugin](docs/images/reinstall-actions.png)

## Requirements

- PHP 8 and the Docker service (PHP 8 is included in unRAID 6.12.0 and later).
- Verified on unRAID 7.2.3 and later; earlier versions are not currently verified.

## Configuration

Access **Settings → Compose** in the unRAID web UI. Key options:

- **Rich Terminal Output** — Toggle terminal-style live, colorized output (ttyd) versus simpler text output.
- **Projects Folder** — Default: `/boot/config/plugins/compose.manager/projects`. Changing it does not move existing projects.
- **Show Compose in Header Menu** — Adds a Compose tab to the unRAID header navigation and removes it from the Docker tab.
- **Show Dashboard Tile** — Show or hide the Compose stacks summary tile on the unRAID dashboard.
- **Recreate During Autostart / Wait for Docker Autostart** — Autostart behavior controls, plus **Docker Wait Timeout** and **Stack Startup Timeout**.
- **Run in Background by Default** — Action dialogs pre-check "Run in background" and notify on completion.
- **Expand Stacks by Default / Only Expand Running Stacks** — Control how stack rows expand on page load.
- **Auto Check for Updates** — Automatically check image updates on page load with a configurable interval.
- **Hide Compose Containers from Docker** — Hide Compose-managed containers from the Docker page (non-tabbed Docker mode).
- **Hide Compose Containers from Docker Tile on Dashboard** — Hide Compose-managed containers from the Dashboard Docker tile.
- **Backup Settings** — Backup destination, retention, and optional scheduled backups.
- **Debug Logging** — Log detailed compose command output to syslog.

## Usage

### Creating a Stack

1. Navigate to **Docker → Compose** (or **Compose** if the header menu option is enabled)
2. Click **Add Stack**
3. Enter a name and optionally a description
4. Optionally expand **Advanced Options** and enter an **Indirect Path** if the compose file lives outside the default projects folder
5. The stack editor opens automatically — add your `compose.yaml` content
6. Click **Compose Up** to start the stack

### Git-Backed Stacks

To create a Git-backed stack, choose **Git repository** as the compose source in **Add New Stack**, then enter the repository URL and compose file path. The plugin clones the repository onto the array; the new stack is not deployed until you choose **Pull and Redeploy**. To convert an existing stack, use **Edit Stack → Sources → Move this stack into git...**.

From the stack menu, check for branch changes, deploy the latest commit, or deploy a specific commit. In the editor's **Sources** tab, automatic deploys can be set to **Off**, **Notify**, or **Deploy**; checks run every 15 minutes. Private repositories can use an HTTPS token from the plugin's Credentials settings or an SSH deploy key. Keep secrets out of the repository; the stack's `.env` stays in its project folder. Make changes in the repository rather than the server clone, since local clone changes must be resolved before the next deploy.

See the [Git Stacks guide](docs/git-stacks.md) for setup, conversion, repository layout, credentials, and deploy behavior.

### Managing Stacks

#### Context Menu

Right-click (or left-click) the stack icon to open the context menu. Available actions depend on the stack's current state:

**When Running:**

- **WebUI** — Opens the configured WebUI URL in a new tab (if set)
- **Compose Down** — Stop and remove all containers
- **Compose Stop** — Stop containers without removing them
- **Compose Restart** — Recreate containers without pulling
- **Update** / **Update & Rebuild** — Pull latest images and recreate (shown when updates are detected; label changes to "Update & Rebuild" for build stacks)
- **Force Update** / **Force Update & Rebuild** — Pull and recreate even when no updates are detected
- **Check for Updates** — Run an immediate SHA-256 digest check for this stack
- **Edit Stack** — Open the full editor modal
- **View Logs** — Open a live `docker compose logs -f` terminal window
- **View Last Cmd Log** — Show the saved output of the last command run for this stack
- **Delete Stack** *(disabled while running)*

**When Stopped:**

- **Compose Up** — Create and start all containers
- **Pull** / **Build** — Pull or build images without starting (label is "Build" for build stacks)
- **Pull & Up** / **Build & Up** — Pull/build and then start
- **Check for Updates**, **Edit Stack**, **View Logs**, **View Last Cmd Log**, **Delete Stack**

All actions that support Docker Compose profiles will show a profile selector when profiles are defined.

#### Container Context Menu

Expand a stack row and right-click (or left-click) a container's icon:

- **WebUI** — Open the container's WebUI (if configured and running)
- **Console** — Open a live interactive terminal inside the container via ttyd
- **Stop / Pause / Restart** — Manage the individual container
- **Start / Resume** — For stopped or paused containers
- **Logs** — Open a `docker logs -f` terminal window for this container

#### Action Dialogs

When confirming a stack action, the dialog shows all containers with their current image tag, update status, and a SHA-256 diff (current → new) for any pending updates. A **Run in background** checkbox lets you dispatch the command without a terminal window; a notification pops up when it finishes.

### Auto-Updates

Navigate to the **Auto-Updates** section (toolbar button on the Compose page) to configure per-stack automatic updates:

- Enable or disable the scheduler globally
- Set a schedule per stack (daily, weekly, etc.)
- Use **Toggle All** to enable/disable all stacks at once
- Use **Run Now** to trigger an immediate update check for a stack
- Requests are serialized to avoid overwhelming the server

The runner (`autoupdate_runner.php`) is invoked by cron and compares image digests before pulling, so updates only happen when a new image is actually available.

### Image Pinning

If a service in your `compose.yaml` references an image with a digest (e.g. `redis:7@sha256:abc123...`), Compose Manager automatically marks it as **pinned**. Pinned containers display a cyan thumbtack badge in the update column and are skipped during update checks.

### Bulk Actions

The toolbar provides **Start All**, **Stop All**, and **Update All** buttons. An **Autostart Only** toggle filters these operations to stacks that have autostart enabled.

### Autostart

Enable autostart for a stack by clicking the autostart toggle. Stacks will automatically start when the unRAID array starts, with optional force-recreate and configurable wait timeouts.

## Documentation

For detailed guides, see the [docs](docs/) folder:

- [Getting Started](docs/getting-started.md)
- [User Guide](docs/user-guide.md)
- [Configuration](docs/configuration.md)
- [Profiles](docs/profiles.md)
- [Git Stacks](docs/git-stacks.md)

## Development & Testing 🔧

Quick start:

- Install PHP dependencies: `composer install`.
- Initialize the test framework submodule: `git submodule update --init --recursive`.
- Run PHPUnit tests: `composer test:unit` (or `composer test`).
- Run one test file: `vendor/bin/phpunit tests/unit/ExampleTest.php`.

Shell tests:

- BATS tests are in `tests/unit`; see [the test guide](docs/testing.md) for how to run them.

Live-server browser tests:

- Playwright tests are in `tests/e2e`; see [the E2E guide](tests/e2e/README.md) for setup and safety notes.

Static analysis:

- `composer run analyse` (runs PHPStan configured for this project).

For full developer setup, test running, and contribution guidelines see `docs/development.md`. (Contains examples for coverage, CI notes, and integration testing tips.)

## Release Process

Releases are fully automated via GitHub Actions. **No local scripts or manual tagging required.**

### How it works

1. **Merge a PR to `main` (stable) or `dev` (beta).**
2. The `tag-release.yml` workflow automatically:
   - Generates a date-based version tag (`v2026.03.15` for stable, `v2026.03.15-dev.HHMM` for beta)
   - Updates the PLG changelog from conventional commit messages
   - Pushes the tag
3. The tag triggers `build.yml`, which:
   - Builds the TXZ package in a Slackware Docker container
   - Creates a GitHub Release with the package attached
   - Updates the PLG file version and MD5 hash in the target branch

### Branching model

| Branch | Purpose | Release type | Tag format |
| ------ | ------- | ------------ | ---------- |
| `main` | Stable releases | Full release | `v2026.03.15`, `v2026.03.15a` |
| `dev` | Beta/testing | Pre-release | `v2026.03.15-dev.1430` |

- Work on `dev`, open a PR to `main` when ready to promote to stable.
- Same-day stable releases get an auto-incrementing letter suffix (`a`, `b`, `c`, ...).
- Beta releases use a UTC timestamp suffix for uniqueness.

### Manual override

Use the `workflow_dispatch` trigger on `build.yml` in the Actions tab to manually build any version without tagging.

Contributions are welcome — we added issue and PR templates to guide reports and pull requests. Please follow the templates when submitting issues or PRs.

## Support

- [GitHub Issues](https://github.com/mstrhakr/compose_plugin/issues)
- [unRAID Forums](https://forums.unraid.net/)

## License

Original material owned and licensable by **Nick Szittai (mstrhakr)** is offered
under the [MIT License](LICENSE). This is **not a blanket license for the plugin**:
inherited dcflachs/compose_plugin code and independently owned contributions are
excluded, and upstream permission remains unresolved. Changes to inherited files
do not relicense their underlying material.

See the [licensing and provenance audit](source/compose.manager/licenses/LICENSING.md)
for surviving upstream code, scope, and required permissions. Third-party
components retain their [own licenses and notices](source/compose.manager/licenses/THIRD-PARTY-NOTICES.md).
The complete project is not yet cleared for unrestricted redistribution.

## Credits

Originally created by **dcflachs**. This fork maintained by **mstrhakr**.
Huge thanks to the entire Unraid community, without you this would be impossible.

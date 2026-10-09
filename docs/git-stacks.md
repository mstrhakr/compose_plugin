# Git Stacks

A git stack is a stack whose compose file lives in a git repository. Compose Manager keeps a clone of the
repository on your server, and deploying the stack means: fetch the latest commit, check it, pull and build
images, and start the containers from it. To change the stack, you change the repository: commit, push,
deploy.

For now git stacks are managed from the command line with `compose-git`. Stacks made this way show up in
the Compose page like any other stack, and Start, Stop and the rest work on them as usual.

```bash
/usr/local/emhttp/plugins/compose.manager/scripts/compose-git
```

To type it as `compose-git`, add `alias compose-git=/usr/local/emhttp/plugins/compose.manager/scripts/compose-git`
to your shell profile.

## Contents

- [How it works](#how-it-works)
- [Quick start](#quick-start)
- [Moving an existing stack into git](#moving-an-existing-stack-into-git)
- [Day to day](#day-to-day)
- [How to lay out your repositories](#how-to-lay-out-your-repositories)
- [Private repositories](#private-repositories)
- [Which .env is used](#which-env-is-used)
- [Folders and files your containers need](#folders-and-files-your-containers-need)
- [Config files and recreating containers](#config-files-and-recreating-containers)
- [Local changes on the server](#local-changes-on-the-server)
- [When a deploy fails](#when-a-deploy-fails)
- [What is checked before a deploy](#what-is-checked-before-a-deploy)
- [Command reference](#command-reference)
- [Other actions on a git stack](#other-actions-on-a-git-stack)
- [Limitations](#limitations)

## How it works

- **The clone** lives on your array, by default under `/mnt/user/appdata/compose.manager/git/<stack>-<id>`,
  never on the flash drive. Each git stack has its own clone of the whole repository.
- **The stack folder** in the projects folder holds the stack's settings (`git.json`), what was last deployed
  (`git_state.json`), the stack's `.env` and the plugin's override file (and, until the next deploy that works,
  `git_discarded_changes`, see [below](#config-files-and-recreating-containers)). These are never inside the
  clone, so pulling new commits never touches them.
- **A deploy changes no container until everything else has worked.** It fetches the commit, checks it out,
  checks it (see [below](#what-is-checked-before-a-deploy)), pulls and builds images, and only then runs
  `docker compose up`, which neither builds nor pulls again, whatever a service's `pull_policy` says. If
  anything before `up` fails, the previous commit is put back and your containers are exactly as they were.
- **Nothing of yours is deleted.** Files the plugin replaces are moved into a backup folder, local changes are
  saved as a patch before they are discarded, and an old clone is moved aside rather than removed. The plugin
  removes only a clone it has just made itself, when setting it up fails (for example, the branch has no
  compose file at the given path), images a deploy has replaced, and override entries for services the
  compose file no longer has.
- **The array must be started.** Git stacks never write anything while the array is stopped, and a path that
  is not on a mounted disk, pool or share is refused, because on Unraid it would land in RAM.

## Quick start

You need a repository with a compose file in it: a public one over `https://`, a private one (see
[Private repositories](#private-repositories)), or one on this server under `/mnt` (see
[step 2](#2-create-the-repository)).

```bash
compose-git add whoami --url https://github.com/you/stacks.git --path whoami/compose.yaml
compose-git deploy whoami
```

`--path` is the compose file's path inside the repository. `--branch` defaults to `main`.

## Moving an existing stack into git

This takes a stack you already run in Compose Manager and puts it under git, keeping its containers, volumes,
`.env` and settings. The example stack is called `myapp`.

### 1. Decide where it goes

Read [How to lay out your repositories](#how-to-lay-out-your-repositories) first. In short: one repository
for all your stacks works well if it stays small (compose files and small config files only). A stack with
big files gets a repository of its own.

A layout for one repository with several stacks:

```
stacks/
  myapp/
    compose.yaml
    config/            small config files the stack mounts (optional)
    .env.example       the variable names the stack needs, no values (optional)
  otherapp/
    compose.yaml
.gitignore
```

### 2. Create the repository

Either:

- **On GitHub, Gitea, Forgejo or similar.** Public or private; a private one needs an access token or a
  deploy key (see [Private repositories](#private-repositories)). Either way, keep secrets out of it (step 4).
- **On this server.** A bare repository under `/mnt` works without any credentials:

  ```bash
  mkdir -p /mnt/user/appdata/git-repos
  git init --bare -b main /mnt/user/appdata/git-repos/stacks.git
  ```

  From your own computer you then push to it over SSH:
  `git remote add origin root@tower:/mnt/user/appdata/git-repos/stacks.git`.

### 3. Copy the stack's files into the repository

Find the stack's current compose file. A stack created in Compose Manager keeps it in the projects folder,
by default `/boot/config/plugins/compose.manager/projects/myapp/compose.yaml`. A stack that points at another
folder (an "indirect" stack) keeps it there; the `indirect` file in the stack's projects folder says where.

Copy it into the repository, for example to `stacks/myapp/compose.yaml`.

Then look at its `volumes:` entries:

- **Absolute paths** such as `/mnt/user/appdata/myapp:/config` keep working unchanged. This is where data the
  container writes belongs.
- **Relative paths** such as `./config:/config` will point inside the clone after the move, because the
  compose file now lives there. For each one, either:
  - commit those files to the repository next to the compose file, if they are configuration you want versioned
    (and small), or
  - change the path to an absolute one under appdata, and copy the files there, if the container writes to
    them or they are big.

  Data a container writes should never live in the clone: it would show up as local changes, and it is not
  backed up by the repository.
- **Named volumes** keep working: the stack keeps its project name, so it keeps its volumes.

### 4. Keep secrets out

Do not commit the `.env` if it has passwords or tokens in it. Add a `.gitignore` to the repository:

```
.env
```

The stack's `.env` stays on the server, in the stack folder, and keeps being used (see
[Which .env is used](#which-env-is-used)). If you like, commit a `.env.example` listing the variable names
with no values: before each deploy, Compose Manager checks that every name in it is set in the stack's `.env`,
so a variable added later can't be forgotten.

If you do want your `.env` versioned with the stack, that works too; it is your call.

### 5. Commit and push

```bash
git add stacks/myapp
git commit -m "Add myapp"
git push
```

### 6. Convert the stack

```bash
compose-git convert myapp --url https://github.com/you/stacks.git --path stacks/myapp/compose.yaml
```

This clones the repository and points the stack at the compose file in the clone. It:

- moves the stack's old compose file, when it is in the stack folder, into a backup folder in the stack
  folder, `pre-git-<date>` (an indirect stack's compose file stays where it is, and its old `indirect`
  settings go into the backup folder);
- keeps the stack's `.env`, override file, name and settings;
- leaves your running containers alone;
- lists any folders next to the old compose file, as a reminder: a relative bind mount such as `./db` now
  points inside the clone (see step 3).

If the compose file is not on the branch, nothing is changed.

If the stack had an auto-update schedule, set it again after converting: the schedule is stored against the
old compose file's location.

### 7. Deploy

```bash
compose-git deploy myapp
```

The stack now runs from the repository's compose file. A container whose definition is unchanged keeps
running; one that changed (a path you moved in step 3, say) is recreated. Because the project name is
unchanged, the stack keeps its volumes and networks. Check that it works.

### 8. Tidy up

Once you are happy, delete the `pre-git-<date>` folder. Until then it is your way back: to undo the
conversion, delete `git.json`, `git_state.json`, `git_discarded_changes` (if there is one), `indirect` and
`indirect_mode` from the stack folder, then
copy the backup folder's files back into it (they include the old `indirect` settings, if the stack had any).

## Day to day

1. Change the compose file (or its config files) in the repository, commit and push.
2. See whether the server is behind: `compose-git check myapp` (or `--all`). Exit status 3 means a newer
   commit is waiting.
3. Deploy it: `compose-git deploy myapp`.

To go back to an older version, deploy its commit: `compose-git deploy myapp --commit <full commit id>`. A
later plain `deploy` brings the stack back to the branch's latest.

`compose-git status` shows every git stack: what is deployed, any failed commit, and local changes.

## How to lay out your repositories

**Every git stack keeps its own clone of the whole repository.** Ten stacks from one repository means ten
copies of every file in it on your array. That is fine for compose files and small config files, and it is
what makes a single repository for all your stacks convenient. It stops being fine when the repository holds
big things:

- Keep a shared stacks repository **lightweight**: compose files, small config files, `.env.example`
  files. No container data, backups, media, databases or downloaded binaries.
- **Give a stack its own repository** when it needs large files: the build context and source for an image
  you build yourself, big configuration sets, or data files. Its size is then paid once, by that stack
  alone.
- Clones download only the files of the commit they check out, not every old version (a partial clone),
  so history costs little. What costs is the size of the files at the current commit, times the number of
  stacks.

A change to another stack's folder in a shared repository is fetched by every stack, but only recreates the
containers of the stack whose folder changed (see [below](#config-files-and-recreating-containers)).

## Private repositories

A private repository is reached in one of two ways. Either way, the secret is kept encrypted in the plugin's
credential vault, given to git only while it runs, and never written into the clone.

**An HTTPS access token.** Make a token on your git host that can only read the repository (on GitHub, a
fine-grained token with read access to its contents; on Gitea or Forgejo, an access token with read access to
repositories). Add it on the **Credentials** tab of the plugin settings, as **Git repository (HTTPS token, for
git stacks)**, with the host only (`github.com`, or `git.example.com:3000`). Then name it when you add the
stack:

```bash
compose-git add myapp --url https://github.com/you/stacks.git --path myapp/compose.yaml --credential "GitHub stacks"
```

Use the repository's address exactly as the host gives it for cloning, usually ending in `.git`. The token is
offered only for that address, so a host that redirects a shorter address gets no token after the redirect.

One token can serve every stack on the same host that it can read. **Test** on the Credentials tab tries it
against a stack that already uses it, so add the stack first; until then the tab says no git stack uses it.

**An SSH deploy key.** Use an ssh address, and the stack gets a key of its own:

```bash
compose-git add myapp --url git@github.com:you/stacks.git --path myapp/compose.yaml
```

The first time, the clone fails because the repository does not know the key yet. The command prints the
public key: add it to the repository as a **read-only deploy key** (on GitHub: the repository's Settings >
Deploy keys), then run the same command again. `compose-git deploy-key myapp` shows the key again later.
Until then the key is listed on the Credentials tab as used by no stack: leave it there, or the second run
makes a new key, and the one you added to the repository no longer works.

When the stack is added, the server's ssh host keys are pinned and their fingerprints printed. Compare them with
the ones your git host publishes (GitHub, GitLab and Codeberg list theirs). Every later connection must match
them. If the server is rebuilt and its keys change, deploys stop until you run `compose-git trust-host myapp`,
which shows the old and new fingerprints and pins the new ones.

To change or remove a stack's HTTPS credential later: `compose-git credential myapp "Other token"` or
`compose-git credential myapp --none`. The change is saved only if the repository can be reached with it.

## Which .env is used

With no custom env file set for the stack, a git stack uses, in order:

1. the `.env` in the stack folder, if there is one (the usual place, and where secrets stay off git);
2. otherwise a `.env` committed in the repository next to the compose file;
3. otherwise none. The editor creates one in the stack folder.

Only one of the two is used; they are not merged. Editing a `.env` that came from the repository changes the
clone, so the next deploy stops with "local changes": make the change in the repository instead.

Variables exported in the shell you run `compose-git deploy` from are not passed on, and `${PWD}` in the compose
file is the compose file's folder in the clone: a deploy uses only what its checks saw. Put values in the `.env`.

If the `.env` sets `COMPOSE_FILE` with relative paths, they are relative to the folder the `.env` is in. For
the stack folder's `.env` that is the stack folder, not the clone, so use paths relative to the clone only in
a `.env` committed next to the compose file.

## Folders and files your containers need

A bind mount whose folder does not exist yet is created by Docker on the first deploy, owned by root. Compose
Manager allows this inside a share that exists, such as `/mnt/user/appdata`, and names each folder it will
create in the deploy log. A path that looks like a mistake stops the deploy instead: a share that does not
exist (a typo such as `/mnt/user/apdata`), or a path that is not on a mounted disk, pool or share, which on
Unraid would end up in RAM.

If your container runs as a non-root user, a folder Docker created as root is not writable for it. Two ways
to fix that from the compose file itself, so it works on every deploy:

**A `pre_start` hook** (Docker Compose 5.3 and later; the plugin ships 5.5):

```yaml
services:
  app:
    image: example/app
    user: "1000:1000"
    volumes:
      - /mnt/user/appdata/app/data:/data
    pre_start:
      - command: ["sh", "-c", "mkdir -p /data && chown -R 1000:1000 /data"]
        image: alpine
        user: root
```

**An init service** that runs first and exits:

```yaml
services:
  init-perms:
    image: alpine
    command: ["sh", "-c", "mkdir -p /data && chown -R 1000:1000 /data"]
    volumes:
      - /mnt/user/appdata/app/data:/data
  app:
    image: example/app
    user: "1000:1000"
    volumes:
      - /mnt/user/appdata/app/data:/data
    depends_on:
      init-perms:
        condition: service_completed_successfully
```

## Config files and recreating containers

`docker compose up` only recreates a container when its compose definition changes. A commit that only edits
a config file the container mounts would otherwise be checked out but never take effect.

So by default, **when a deploy changes anything in the stack's folder in the repository, every container in
the stack is recreated**, and the new config takes effect.

A deploy that saves local changes in the stack's folder as a patch and discards them recreates every
container too, even at the same commit: a container may still hold an edited file. Until a deploy works, the
stack folder holds a `git_discarded_changes` file that says so.

Only the stack's own folder counts: a change to a shared folder elsewhere in the repository does not
recreate anything yet. To turn the behaviour off, set `"recreateOnFolderChange": false` in the stack's
`git.json`.

## Local changes on the server

The clone is not meant to be edited. If a tracked file in it is changed (or a commit is made in it), the next
deploy stops and lists the files. Then either:

- undo the change by hand, or
- deploy with `--save-local-changes`: the changes are saved as a patch in the stack folder's `git-changes`
  folder, then discarded, and the deploy goes ahead. Apply the patch to your own copy of the repository with
  `git apply <patch>` if you want to keep it.

Files that are not part of the repository, such as data a container wrote into the clone, are never removed
or overwritten, whether `.gitignore` lists them or not. If a new commit would put a file where one of them
is, the deploy stops instead.

## When a deploy fails

- **Before `up`** (a check fails, the pull or build fails): the previous commit is put back and no container
  was changed. Fix the cause, usually in the repository, and deploy again.

  "No container was changed" is about the containers themselves. A running container that mounts a file or
  folder from the clone (`./config`, say) sees the new commit's files from the moment it is checked out, while
  the checks, pull and build run, and the old ones again once it is put back. Most apps read their config only
  when they start, so this does not matter to them. For an app that reloads its config when the file changes
  (a proxy watching its config folder, say), keep that config at an absolute path outside the clone if a
  deploy that is put back must not reach it.
- **During `up`** (a container fails to start, or does not get healthy with `--wait`): a half-finished `up`
  cannot be undone safely, so it is not rolled back. The commit is recorded as failed and shown by
  `compose-git status`. Fix it in the repository and deploy again, or deploy a known good commit with
  `--commit`.

If the clone itself gets into a bad state, `compose-git reclone myapp` moves it aside (as
`<clone>.replaced-<date>`, nothing is deleted) and clones the repository again at the deployed commit. It
lists the untracked files left in the old clone: copy back anything you need, then delete the old clone
yourself. A running container with a bind mount into the clone (`./config`, say) keeps using the moved
folder until the container is recreated, so stop and start the stack before deleting the old clone. If the
deployed commit is no longer on the branch (the branch was rewritten), the new clone is left at the
branch's latest commit, and the next deploy recreates every container.

## What is checked before a deploy

Every deploy runs `docker compose config` on the new commit, with the stack's `.env` and profiles, and stops
without changing anything when:

- the compose file is not valid, defines no services, or uses a variable that is not set (an empty value must
  be written as `NAME=` in the `.env`);
- the repository's `.env.example` lists names missing from the `.env` in use;
- an `external: true` network or volume does not exist. With **Create Missing External Networks** turned on
  in the plugin's settings, a missing network is created instead, the same as for any other stack;
- a bind-mount source is missing in a way described in
  [Folders and files](#folders-and-files-your-containers-need), or a config or secret file, or a local build
  folder, is missing;
- a `container_name` or published port is already taken by another stack or container, or two services of
  the stack publish the same port;
- Docker could not be asked whether a name or port is free, or whether an external network or volume exists
  (the daemon is not answering, say).

The deploy log says which `.env` was used.

Before the checks, the plugin's override loses its entries (UI labels) for services the compose file no
longer has, as it does before every Compose Up from the web UI. An entry for a renamed service would
otherwise make compose refuse the stack. The log names each one removed.

## Command reference

| Command | What it does |
|---|---|
| `add <name> --url <url> --path <file> [--branch <b>] [--clones-root <folder>] [--description <text>] [--credential <name>]` | Clone a repository and make a new git stack. Not deployed yet. |
| `convert <stack> --url <url> --path <file> [--branch <b>] [--clones-root <folder>] [--credential <name>]` | Turn an existing stack into a git stack (see above). |
| `credential <stack> <name>` or `credential <stack> --none` | Change or remove the HTTPS credential a stack uses. |
| `deploy-key <stack>` | Show an ssh stack's public deploy key. |
| `trust-host <stack>` | Pin an ssh stack's server host keys again, after they changed. |
| `check <stack>` or `check --all` | Compare the deployed commit with the branch on the remote. Changes nothing. |
| `deploy <stack> [--commit <id>] [--save-local-changes] [--wait\|--no-wait] [--wait-timeout <s>] [--profile <p>]...` | Deploy the branch's latest commit, or the given one. Waits for healthy containers when the stack's wait-for-healthy setting says so, unless `--wait` or `--no-wait` overrides it. |
| `reclone <stack>` | Move the clone aside and clone again at the deployed commit. |
| `status [<stack>\|--all] [--json]` | Show what is deployed, failed and changed locally, without asking the remote. |

`compose-git <command> --help` explains a command and each of its options.

`<url>` is an `https://` address without a user name or password in it, an ssh address
(`ssh://git@host[:port]/path` or `git@host:path`), or the path of a repository under `/mnt`. `--credential`
is the name of a git credential (an HTTPS token) on the Credentials tab, for an `https://` address; an ssh
stack always uses the deploy key made for it. `<stack>` is the stack's exact folder name in the projects folder. `--clones-root` must be on a share,
disk or pool, and the share must exist.

Exit status: `0` success (for `check`: up to date), `1` failed, `2` the command line was not understood, `3`
(`check`) a newer commit is available. `check --all` exits `3` if any stack is behind, even when another could
not be checked. If another operation on the same stack is running (a Start or Update from the web UI, say),
`deploy` waits up to 30 seconds (`COMPOSE_LOCK_TIMEOUT`) for it to finish, then fails without changing
anything. `convert` and `reclone` do not wait: they refuse to start while another operation on the stack is
running, so run them again once it has finished.

Without `--profile`, `deploy` uses the profiles the stack is running with, or else its default profiles, the
same as Update in the web UI.

## Other actions on a git stack

The rest of Compose Manager treats a git stack like any other stack, using the commit that is checked out in
the clone. Start, Stop, Update, autostart, auto-update and stopping the array all work as usual, with the same
compose files and `.env` as a deploy. None of them fetch a new commit, run the checks above, or record a
deployed commit: only `compose-git deploy` does that.

- **Delete** removes the stack folder and leaves the clone (see [Limitations](#limitations)).
- **Backup** saves the stack folder, not the clone, as for any stack whose compose file lives elsewhere. The
  repository is the backup of the clone.
- **Settings:** do not change the stack's external compose path. The next deploy refuses, because it no longer
  matches `git.json`.

## Limitations

- **An `https` server needs a certificate this server trusts.** A self-hosted git server with a self-signed
  certificate is refused over `https`; reach it over ssh instead.
- **No web UI yet.** Git stacks are created and deployed from the command line; the Compose page shows and
  runs them like other stacks.
- **No automatic deploys yet.** Use `check` and `deploy` from your own schedule or a git hook.
- No submodules and no Git LFS: the clone checks out plain files only.
- **The web UI's editor edits the file in the clone.** The next deploy treats that as a local change. Changes
  that must survive deploys belong in the plugin's override or the stack folder's `.env`.
- **Deleting a git stack leaves its clone** under the clones folder. Remove it by hand.

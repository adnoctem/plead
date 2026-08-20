<p align="center">
    <!-- plead -->
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://github.com/adnoctem/artwork/blob/38866111fd02fab46ce80117372240815d7dbf27/projects/plead/white/plead-icon-white.png?raw=true">
      <img src="https://github.com/adnoctem/artwork/blob/38866111fd02fab46ce80117372240815d7dbf27/projects/plead/white/plead-icon-white.png?raw=true" width="225" alt="plead">
    </picture>&nbsp;&nbsp;&nbsp;
    <!-- Plesk -->
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://upload.wikimedia.org/wikipedia/commons/8/80/Logo_Plesk.svg">
      <img src="https://upload.wikimedia.org/wikipedia/commons/8/80/Logo_Plesk.svg" width="225" alt="Plesk">
    </picture>
</p>

[![License](https://img.shields.io/github/license/adnoctem/plead?label=License)][license]
[![Language](https://img.shields.io/github/languages/top/adnoctem/plead?label=PHP)][php]
[![Linting](https://github.com/adnoctem/plead/actions/workflows/superlint.yaml/badge.svg)][ci_linting_workflow]
[![GitHub Release](https://img.shields.io/github/v/release/adnoctem/plead?label=Release)][github_releases]
[![GitHub Activity](https://img.shields.io/github/commit-activity/m/adnoctem/plead?label=Commits)][github_commits]
[![Semantic Release](https://img.shields.io/badge/Semantic_Release-enabled-brightgreen?logo=semanticrelease&logoColor=E5E4E7)][semantic_release]
[![Renovate](https://img.shields.io/badge/Renovate-enabled-brightgreen?logo=renovate&logoColor=1A1F6C)][renovate]
[![PreCommit](https://img.shields.io/badge/PreCommit-enabled-brightgreen?logo=precommit&logoColor=FAB040)][precommit]

`plead` is an open-source [MIT][license]-licensed [PHP][php] command-line tool written and maintained by the [Ad Noctem Collective][org] for managing Plesk-hosted mail resources that Plesk's own UI and XML-API don't handle natively.

Two things are currently automated:

- **Auto-replies with a scheduled start time.** Plesk supports `end_date` natively, but `start_date` does not exist as a Plesk concept. `plead` enforces it: you schedule a reply with a start and end date, and the `mail:autoresponder:watch` daemon pushes it to Plesk the moment the start time is reached.
- **Mail distribution groups** (e.g. `all@company.com`) — add/remove recipients without hand-editing Plesk's forwarding UI. A `mail:group:watch` daemon continuously converges the server state toward what `plead` has been told.

`plead` runs entirely off the Plesk box and talks to Plesk exclusively over HTTPS XML-RPC (port 8443) using a secret key or administrator credentials. It is safe to run against production mail infrastructure: development follows read-before-write, `--dry-run` is enforced structurally in the gateway, reconciliation is idempotent, and every action lands in a local SQLite audit trail.

### The audit-trail model

The local SQLite database is the **desired state**; the Plesk server holds the **live state**. Every mutation follows the same three steps:

1. **Record the intent locally first** — the change lands in SQLite (and the `sync_log` table gains a `pending` entry) *before* anything touches the network.
2. **Apply to Plesk** (or `--dry-run`).
3. **Finalize** — on success the entry is marked `reconciled` and the log entry resolves to `ok`; on failure the entry stays `pending` (the watcher retries) and the log entry carries `error:<message>`.

Rows are never deleted: auto-replies carry a `status` (`scheduled`/`disabled`) and recipients are soft-deleted, so the database doubles as a full audit trail of every intent, even months later. Read commands show the **live server state by default**; pass `--local` to inspect the desired state instead.

## ✨ TL;DR

```bash
# configure once (either works)
plead config:set plesk.host mail.company.com
plead config:set plesk.secret_key <secret-key>
plead config:edit          # open the config file in $EDITOR instead

# see the resolved configuration (post-merge) and where files live
plead config:view
plead config:path

# schedule an auto-reply (message file is rendered as a Twig template)
plead mail:autoresponder:set user@company.com \
    --message-file=~/vacation.txt \
    --start-date="2026-08-20 08:00" \
    --end-date="2026-08-30 18:00"

# dry-run first — no network calls are made at all
plead mail:autoresponder:set user@company.com --message-file=~/vacation.txt \
    --start-date="2026-08-20 08:00" --end-date="2026-08-30 18:00" --dry-run

# what is configured live on the server vs. what plead intends to converge toward
plead mail:autoresponder:get user@company.com
plead mail:autoresponder:get user@company.com --local

# disable an auto-reply again (kept in the audit trail)
plead mail:autoresponder:set user@company.com --enabled=false

# continuously apply scheduled auto-replies as their start time is reached
plead mail:autoresponder:watch --interval=60

# manage a mail distribution group
plead mail:group:add group@company.com newhire@company.com
plead mail:group:remove group@company.com leaver@company.com
plead mail:group:set group@company.com --recipients=a@company.com,b@company.com
plead mail:group:get group@company.com          # live state on the Plesk server
plead mail:group:get group@company.com --local  # desired state + history
plead mail:group:watch --interval=60            # continuously converge server state

# mailbox operations
plead mail:address:list --domain=company.com
plead mail:address:set user@company.com --description="Holiday replacement"
plead mail:address:set user@company.com --outgoing-limit=250
plead mail:address:set user@company.com --quota=512
plead mail:address:password user@company.com --generate
plead mail:address:rename user@company.com newuser

# aliases (additional addresses delivering into a mailbox)
plead mail:alias:add user@company.com info@company.com
plead mail:alias:remove user@company.com sales@company.com
plead mail:alias:set user@company.com --aliases=info@company.com,sales@company.com
plead mail:alias:list

# domains
plead domain:list
plead domain:get delta4x4.net
plead domain:set delta4x4.net --description="Main company domain"
```

## 🧰 Commands

Read commands query the **live Plesk server** by default; add `--local` to read the SQLite desired state instead.

| Command                                 | Purpose                                                                                                                               |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `mail:group:list`                       | List mail groups (live: mailnames with forwarding; `--local`: managed)                                                                |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `mail:group:set <group>`                | Replace the full recipient list (`--recipients=a@b,c@d`)                                                                              |
| `mail:group:add <group> <recipient>`    | Add one recipient                                                                                                                     |
| `mail:group:remove <group> <recipient>` | Remove one recipient (soft-delete, keeps history)                                                                                     |
| `mail:group:watch`                      | Converge server state toward desired state (`--interval`, `--full`)                                                                   |
| `mail:address:list`                     | List all mail addresses (`--domain=`, `--local`)                                                                                      |
| `mail:address:get <email>`              | Mailbox, forwarding and auto-reply state of an address                                                                                |
| `mail:address:set <email>`              | Set properties: `--description=`, `--outgoing-limit=`, `--quota=` (MiB)                                                               |
| `mail:address:remove <email>`           | Remove the mail address                                                                                                               |
| `mail:address:password <email>`         | Set (`--password=`) or rotate (`--generate`) the mailbox password                                                                     |
| `mail:address:rename <email> <name>`    | Rename a mail account (local part only; mailbox settings are kept)                                                                    |
| `mail:address:export`                   | Export all addresses (`--format=csv\                                                                                                  |
| `mail:alias:list`                       | Mailboxes with aliases (live: on the server; `--local`: managed)                                                                      |
| `mail:alias:get <email>`                | Aliases of a mailbox (`--local` adds desired state + removal history)                                                                 |
| `mail:alias:set <email>`                | Replace the full alias list (`--aliases=a@b,c@d`)                                                                                     |
| `mail:alias:add <email> <alias>`        | Add one alias address                                                                                                                 |
| `mail:alias:remove <email> <alias>`     | Remove one alias address (soft-delete, keeps history)                                                                                 |
| `mail:autoresponder:list`               | All addresses with an enabled auto-reply (`--local`: scheduled)                                                                       |
| `mail:autoresponder:get <email>`        | Auto-reply of an address (`--local`: desired state)                                                                                   |
| `mail:autoresponder:set <email>`        | Enable/schedule (`--message-file`, `--start-date`, `--end-date`) or disable (`--enabled=false`)                                       |
| `mail:autoresponder:watch`              | Apply scheduled auto-replies and pending disables (`--interval`, `--full`)                                                            |
| `domain:list`                           | List all domains on the server                                                                                                        |
| `domain:get <domain>`                   | Everything plead can read about a domain (hosting, limits, mail count)                                                                |
| `domain:set <domain>`                   | Set properties (`--description=`, `--status=enabled or disabled`)                                                                     |
| `domain:add <name>`                     | Create a domain (`--type=virtual-host or forwarding or frame-forwarding or none`, `--parent=`, `--dest-url=`, `--property=key:value`) |
| `domain:remove <domain>`                | Remove a domain from the server                                                                                                       |
| `domain:traffic:get <domain>`           | Traffic usage in a window (`--from=`, `--to=`, YYYY-MM-DD)                                                                            |
| `domain:traffic:set <domain>`           | Record traffic counters manually (`--date=`, `--smtp-in=`, ...)                                                                       |
| `domain:descriptor <domain>`            | Hosting settings descriptor (property names, types, defaults)                                                                         |
| `config:get <dotted.key>`               | Show the resolved value of one configuration key                                                                                      |
| `config:set <dotted.key> <value>`       | Write a configuration key to the user config file                                                                                     |
| `config:list`                           | Show the resolved configuration with secrets masked                                                                                   |
| `config:view`                           | Show the full resolved configuration (post-merge, including secrets)                                                                  |
| `config:edit`                           | Open the user config file in `$EDITOR` and validate it afterwards                                                                     |
| `config:path`                           | Show where plead stores configuration, data, and logs                                                                                 |
| `db:path`                               | Show the location of the SQLite database                                                                                              |
| `db:query`                              | Open an interactive `sqlite3` shell on the database                                                                                   |
| `server:info`                           | Server identity, versions, object counts, resources and update status                                                                 |
| `server:session:list`                   | Currently opened control-panel sessions                                                                                               |
| `server:session:get <session-id>`       | One session (the API has no single-session read; the list is filtered)                                                                |
| `server:session:terminate <session-id>` | Close a control-panel session                                                                                                         |
| `server:admin`                          | Plesk Administrator personal information                                                                                              |
| `server:service:status [service]`       | Service states (all, or one id like web, smtp, dns)                                                                                   |
| `server:service:start <service>`        | Start a server service                                                                                                                |
| `server:service:stop <service>`         | Stop a server service                                                                                                                 |
| `server:service:restart <service>`      | Restart a server service                                                                                                              |
| `server:ip:list`                        | All IP addresses on the server                                                                                                        |
| `server:ip:get <ip>`                    | One IP address (list filtered client-side)                                                                                            |
| `server:ip:add <ip>`                    | Add an IP (`--netmask=`, `--type=shared\                                                                                              |
| `server:ip:set <ip>`                    | Set IP properties (`--type=`, `--public-ip=`)                                                                                         |
| `server:ip:remove <ip>`                 | Remove an IP address from the server                                                                                                  |
| `server:components:list`                | Installed Plesk components                                                                                                            |
| `server:components:install <id>`        | Install a component (`--update-id=`; docs shape, live validation pending)                                                             |
| `server:extension:list`                 | Installed extensions                                                                                                                  |
| `server:extension:get <id>`             | One extension by id                                                                                                                   |
| `server:extension:install <id>`         | Install an extension by id or `--url=`                                                                                                |
| `server:extension:uninstall <id>`       | Uninstall an extension                                                                                                                |
| `server:extension:call <id> <op>`       | Call an XML API operation of an ApiRpc extension (`--param=name:value`; e.g. git: get/create/update/remove/deploy/fetch)              |
| `server:ref [id]`                       | REST CLI-gate: available CLI ids (no arg) or the reference of one id                                                                  |
| `server:exec <id> <args...>`            | Execute a Plesk CLI command via the REST CLI-gate (args after `--`, e.g. `extension -- --call sslit --help`; `--no-fail-on-error`)    |
| `audit:trail`                           | Browse the audit trail (interactive TUI on a TTY, plain table otherwise; `--resource=`, `--result=`, `--limit=`)                      |
| `audit:export`                          | Dump the entire audit trail (incl. change details) to a file (`--format=json or yaml`, `-o/--output`)                                 |

Global options (available on every command):

| Flag           | Behavior                                                              |
| -------------- | --------------------------------------------------------------------- |
| `-c, --config` | Load only this config file; skip discovery and merging                |
| `--dry-run`    | Log mutations without performing them - no network calls at all       |
| `--log-level`  | Baseline file log level (default `info`); `-v/-vv/-vvv` also raise it |
| `-v/-vv/-vvv`  | Console verbosity (drives both console output and file log level)     |

## ⚙️ Configuration

Configuration is discovered from per-user and system-wide locations, in most-specific-first order, and merged with the user's files winning:

| OS      | User (wins)                               | System-wide fallback                 |
| ------- | ----------------------------------------- | ------------------------------------ |
| Linux   | `$XDG_CONFIG_HOME/plead` (`~/.config`)    | `/etc/plead`                         |
| macOS   | `~/Library/Application Support/plead`     | `/Library/Application Support/plead` |
| Windows | `%LOCALAPPDATA%\plead`, `%APPDATA%\plead` | `%ProgramData%\plead`                |

`plead.yaml` and `plead.json` are both supported (YAML preferred). Environment variables override everything:

- `PLEAD_PLESK_HOST` — Plesk host (bare hostname, or with scheme/port, e.g. `https://mail.company.com:8443`)
- `PLEAD_PLESK_SECRET_KEY` — Plesk secret key (alternative to credentials)
- `PLEAD_PLESK_LOGIN` / `PLEAD_PLESK_PASSWORD` — administrator credentials (alternative to secret key)
- `PLEAD_DATA_DIR` — overrides the data directory (used by the containerized deployment, e.g. `/data`)

Data (SQLite database and log file) lives under the platform data directory — on Linux `~/.local/share/plead/`. The SQLite database is the authoritative record of the desired state and the full audit trail.

## 📚 Documentation

- [`docs/plesk-xml-api-notes.md`](docs/plesk-xml-api-notes.md) — empirically-verified Plesk XML API facts (packet shapes, quirks, batching) collected against Obsidian 18.0.80
- [`docs/AGENTS.md`](docs/AGENTS.md) — agent/contributor cheat sheet (symlinked to the repo root as `AGENTS.md`)
- [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md) — commit format, PR process, repository layout
- [`docs/ACKNOWLEDGEMENTS.md`](docs/ACKNOWLEDGEMENTS.md) — sources the project learned from

## 🔃 Contributing

Contributions are welcome via GitHub's Pull Requests. Fork the repository and implement your changes within the forked
repository, after that you may submit a [Pull Request][gh_pr_fork_docs]. Refer to our [documentation for contributors][contributing]
for contributing guidelines, commit message formats and versioning tips.

## 📥 Maintainers

This project is owned and maintained by [Ad Noctem Collective](https://github.com/adnoctem) refer to
the [`AUTHORS`][authors] or [`CODEOWNERS`][owners] for more information. You may also use the linked
contact details to reach out directly.

## ©️Copyright

*Licensed under the [MIT][license] license.*

<!-- File references -->

[license]: LICENSE
[contributing]: docs/CONTRIBUTING.md
[authors]: .github/AUTHORS
[owners]: .github/CODEOWNERS
[ci_linting_workflow]: https://github.com/adnoctem/plead/actions/workflows/superlint.yaml

<!-- General links -->

[org]: https://github.com/adnoctem
[php]: https://www.php.net/
[gh_pr_fork_docs]: https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/proposing-changes-to-your-work-with-pull-requests/creating-a-pull-request-from-a-fork
[github_releases]: https://github.com/adnoctem/plead/releases
[github_commits]: https://github.com/adnoctem/plead/commits/main/

<!-- Third-party -->

[semantic_release]: https://semantic-release.org/
[renovate]: https://renovatebot.com/
[precommit]: https://pre-commit.com/

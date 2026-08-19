<p align="center">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://upload.wikimedia.org/wikipedia/commons/8/80/Logo_Plesk.svg">
      <img src="https://upload.wikimedia.org/wikipedia/commons/8/80/Logo_Plesk.svg" width="225" alt="Plesk">
    </picture>
    <!-- plead -->
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

- **Auto-replies with a scheduled start time.** Plesk supports `end_date` natively, but `start_date` does not exist as a Plesk concept. `plead` enforces it: you schedule a reply with a start and end date, and the `auto-reply:watch` daemon pushes it to Plesk the moment the start time is reached.
- **Mail distribution groups** (e.g. `all@company.com`) — add/remove recipients without hand-editing Plesk's forwarding UI. A `mail:watch` daemon continuously converges the server state toward what `plead` has been told.

`plead` runs entirely off the Plesk box and talks to Plesk exclusively over HTTPS XML-RPC (port 8443) using a secret key or administrator credentials. It is safe to run against production mail infrastructure: development follows read-before-write, `--dry-run` is enforced structurally in the gateway, reconciliation is idempotent, and every action lands in a local SQLite audit trail.

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
plead auto-reply:set user@company.com \
    --message-file=~/vacation.txt \
    --start-date="2026-08-20 08:00" \
    --end-date="2026-08-30 18:00"

# dry-run first — no network calls are made at all
plead auto-reply:set user@company.com --message-file=~/vacation.txt \
    --start-date="2026-08-20 08:00" --end-date="2026-08-30 18:00" --dry-run

# what is configured live on the server vs. what plead intends to converge toward
plead auto-reply:get user@company.com
plead auto-reply:list user@company.com

# continuously apply scheduled auto-replies as their start time is reached
plead auto-reply:watch --interval=60

# manage a mail distribution group
plead mail:add group@company.com newhire@company.com
plead mail:remove group@company.com leaver@company.com
plead mail:set group@company.com --recipients=a@company.com,b@company.com
plead mail:get group@company.com    # live state on the Plesk server
plead mail:list group@company.com   # local desired state + removal history
plead mail:watch --interval=60      # continuously converge server state
```

## 🧰 Commands

| Command                           | Purpose                                                                    |
| --------------------------------- | -------------------------------------------------------------------------- |
| `auto-reply:get <email>`          | Live read of the autoresponder configured on the Plesk server              |
| `auto-reply:list <email>`         | Locally scheduled auto-reply for an email (from the SQLite database)       |
| `auto-reply:set <email>`          | Schedule an auto-reply with `--message-file`, `--start-date`, `--end-date` |
| `auto-reply:watch`                | Continuously apply scheduled auto-replies (`--interval` seconds)           |
| `mail:get <email>`                | Live read of the forwarding recipients on the Plesk server                 |
| `mail:list <email>`               | Locally managed recipients (from SQLite, including removal history)        |
| `mail:set <email>`                | Replace the full recipient list (`--recipients=a@b,c@d`)                   |
| `mail:add <email> <recipient>`    | Add one recipient                                                          |
| `mail:remove <email> <recipient>` | Remove one recipient (soft-delete, keeps history)                          |
| `mail:watch`                      | Continuously converge server state toward the managed recipient lists      |
| `config:get <dotted.key>`         | Show the resolved value of one configuration key                           |
| `config:set <dotted.key> <value>` | Write a configuration key to the user config file                          |
| `config:list`                     | Show the resolved configuration with secrets masked                        |
| `config:view`                     | Show the full resolved configuration (post-merge, including secrets)       |
| `config:edit`                     | Open the user config file in `$EDITOR` and validate it afterwards          |
| `config:path`                     | Show where plead stores configuration, data, and logs                      |

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

Data (SQLite database and log file) lives under the platform data directory — on Linux `~/.local/share/plead/`. The SQLite database is the authoritative record of scheduled auto-replies.

## 🔃 Contributing

Contributions are welcome via GitHub's Pull Requests. Fork the repository and implement your changes within the forked
repository, after that you may submit a [Pull Request][gh_pr_fork_docs]. Refer to our [documentation for contributors][contributing]
for contributing guidelines, commit message formats and versioning tips.

## 📥 Maintainers

This project is owned and maintained by [Ad Noctem Collective](https://github.com/adnoctem) refer to
the [`AUTHORS`][authors] or [`CODEOWNERS`][owners] for more information. You may also use the linked
contact details to reach out directly.

## ©️ Copyright

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

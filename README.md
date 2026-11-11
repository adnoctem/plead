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

Three things are currently automated:

- **Auto-replies with a scheduled start time.** Plesk supports `end_date` natively, but `start_date` does not exist as a Plesk concept. `plead` enforces it: you schedule a reply with a start and end date, and the `watch` daemon pushes it to Plesk the moment the start time is reached.
- **Mail distribution groups** (e.g. `all@company.com`) — add/remove recipients without hand-editing Plesk's forwarding UI. A `watch` daemon continuously converges the server state toward what `plead` has been told.
- **Rule-driven groups** — a group's recipients can be derived from a regex over the domain's addresses
  (`mail.group` config or `mail:group:set --rule`), with manual recipients appended (even foreign addresses).
  The watcher re-derives the list every pass, so new mailboxes join automatically while `info@`, `pbx@` and friends stay out.

`plead` runs entirely off the Plesk box and talks to Plesk exclusively over HTTPS XML-RPC (port 8443) using a secret key or administrator credentials. It is safe to run against production mail infrastructure: development follows read-before-write, `--dry-run` is enforced structurally in the gateway, reconciliation is idempotent, and every action lands in a local SQLite audit trail.

One installation can manage several Plesk servers; a global `--server` flag picks one (default: the first configured).

### The audit-trail model

The local SQLite database is the **desired state**; the Plesk server holds the **live state**. Every mutation follows the same three steps:

1. **Record the intent locally first** — the change lands in SQLite (and the `sync_log` table gains a `pending` entry) _before_ anything touches the network.
2. **Apply to Plesk** (or `--dry-run`).
3. **Finalize** — on success the entry is marked `reconciled` and the log entry resolves to `ok`; on failure the entry stays `pending` (the watcher retries) and the log entry carries `error:<message>`.

Rows are never deleted: auto-replies carry a `status` (`scheduled`/`disabled`) and recipients are soft-deleted, so the database doubles as a full audit trail of every intent, even months later. Read commands show the **live server state by default**; pass `--local` to inspect the desired state instead.

## 📦 Installation

Three ways to run `plead`, all behaving identically:

- **Standalone phar (any platform, no install)** — download `plead.phar` from the [latest release][github_releases], `chmod +x` it and run `./plead.phar list` (Windows: `php plead.phar`). The phar is self-contained: config, SQLite state and logs live in the usual XDG locations, never inside the archive.
  It needs PHP 8.4.27+ (or 8.5.12+) with `ext-ctype`, `ext-curl`, `ext-dom`, `ext-iconv`, `ext-pdo_sqlite`, `ext-phar`,
  `ext-simplexml`, `ext-xml` and `ext-zlib` (the embedded requirement checker enforces these); `ext-pcntl` is additionally required for `watch -d`. `plead.phar --version` reports the git tag (or commit hash) it was built from.
- **Docker** — `docker pull ghcr.io/adnoctem/plead` (image tags cover the PHP 8.4/8.5 variants); `PLEAD_DATA_DIR=/data` is the persistent volume. The image runs as UID 1001, so bind-mounted state directories must be writable by that user.
- **From source** — `composer install` in a checkout; `bin/plead` runs directly on PHP 8.4.27+ (or 8.5.12+).

> **PHP security floor:** PHP2Shell (CVE-2026-109618 / CVE-2026-109619) is fixed in PHP 8.4.27 and 8.5.12.
> `plead` is a CLI and never serves HTTP requests, but it requires a patched runtime regardless — older
> patch releases are unsupported.

## ✨ TL;DR

```bash
# configure once (either works)
plead config:set servers.0.host mail.company.com
plead config:set servers.0.secret_key <secret-key>
plead config:edit          # open the config file in $EDITOR instead

# see the resolved configuration (post-merge, credentials masked) and where files live
plead config:list
plead config:path

# schedule an auto-reply (the configured template renders the message;
# --data-file sources template variables from a JSON or YAML file)
plead mail:autoresponder:set user@company.com \
    --data-file=~/vacation-data.yaml \
    --start-date="2026-08-20 08:00" \
    --end-date="2026-08-30 18:00" \
    --subject="Automatische Antwort" --format=html

# dry-run first — no network calls are made at all
plead mail:autoresponder:set user@company.com --data-file=~/vacation-data.yaml \
    --start-date="2026-08-20 08:00" --end-date="2026-08-30 18:00" --dry-run

# what is configured live on the server vs. what plead intends to converge toward
plead mail:autoresponder:get user@company.com
plead mail:autoresponder:get user@company.com --local

# disable an auto-reply again (kept in the audit trail)
plead mail:autoresponder:set user@company.com --enabled=false

# manage a mail distribution group
plead mail:group:add group@company.com newhire@company.com
plead mail:group:remove group@company.com leaver@company.com
plead mail:group:set group@company.com --recipients=a@company.com,b@company.com
plead mail:group:set all@company.com --rule='^(1und1|info|support|noreply|pbx)@'
plead mail:group:set all@company.com --rule='^(info|noreply)@' --recipients=consultant@external.com
plead mail:group:set all@company.com --rule='^(info|noreply)@' --write-config   # keep it maintained by the watcher
plead mail:group:get group@company.com          # live state on the Plesk server
plead mail:group:get group@company.com --local  # desired state + history

# one watcher for everything: auto-replies, groups, aliases and rule-driven lists
plead watch --interval=60            # foreground
plead watch -d                       # detached: releases the terminal prompt

# target a specific server when several are configured
plead --server prod.example.com mail:group:list

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
plead domain:get example.com
plead domain:set example.com --description="Main company domain"
```

## 🧰 Commands

Read commands query the **live Plesk server** by default; add `--local` to read the SQLite desired state instead.

| Command                                 | Purpose                                                                                                                                                |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `mail:group:list`                       | List mail groups (live: mailnames with forwarding; `--local`: managed)                                                                                 |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------                  |
| `mail:group:set <group>`                | Replace the full recipient list (`--recipients=a@b,c@d` and/or `--rule=<pcre>`; falls back to the configured `mail.group` entry)                       |
| `mail:group:add <group> <recipient>`    | Add one recipient                                                                                                                                      |
| `mail:group:remove <group> <recipient>` | Remove one recipient (soft-delete, keeps history)                                                                                                      |
| `mail:address:list`                     | List all mail addresses (`--domain=`, `--local`)                                                                                                       |
| `mail:address:get <email>`              | Mailbox, forwarding and auto-reply state of an address                                                                                                 |
| `mail:address:set <email>`              | Set properties: `--description=`, `--outgoing-limit=`, `--quota=` (MiB), `--antivir=`; missing quota/antivir fall back to `mail.defaults`              |
| `mail:address:remove <email>`           | Remove the mail address                                                                                                                                |
| `mail:address:password <email>`         | Set (`--password=`) or rotate (`--generate`) the mailbox password                                                                                      |
| `mail:address:rename <email> <name>`    | Rename a mail account (local part only; mailbox settings are kept)                                                                                     |
| `mail:address:export`                   | Export all addresses (`--format=csv\                                                                                                                   |
| `mail:alias:list`                       | Mailboxes with aliases (live: on the server; `--local`: managed)                                                                                       |
| `mail:alias:get <email>`                | Aliases of a mailbox (`--local` adds desired state + removal history)                                                                                  |
| `mail:alias:set <email>`                | Replace the full alias list (`--aliases=a@b,c@d`)                                                                                                      |
| `mail:alias:add <email> <alias>`        | Add one alias address                                                                                                                                  |
| `mail:alias:remove <email> <alias>`     | Remove one alias address (soft-delete, keeps history)                                                                                                  |
| `mail:autoresponder:list`               | All addresses with an enabled auto-reply (`--local`: scheduled)                                                                                        |
| `mail:autoresponder:get <email>`        | Auto-reply of an address (`--local`: desired state)                                                                                                    |
| `mail:autoresponder:set <email>`        | Enable/schedule (`--template`, `--data-file`, `--start-date`, `--end-date`, `--inclusive`, `--subject`, `--format`), disable, or preview (`--preview`) |
| `watch`                                 | Continuously converge everything (auto-replies, groups, aliases, rule-driven lists; `--interval`, `--full`, `-d/--detached`)                           |
| `domain:list`                           | List all domains on the server                                                                                                                         |
| `domain:get <domain>`                   | Everything plead can read about a domain (hosting, limits, mail count)                                                                                 |
| `domain:set <domain>`                   | Set properties (`--description=`, `--status=enabled or disabled`, `--type=virtual-host                                                                 |
| `domain:add <name>`                     | Create a domain (`--type=virtual-host or forwarding or frame-forwarding or none`, `--parent=`, `--dest-url=`, `--property=key:value`)                  |
| `domain:remove <domain>`                | Remove a domain from the server                                                                                                                        |
| `domain:traffic:get <domain>`           | Traffic usage in a window (`--from=`, `--to=`, YYYY-MM-DD)                                                                                             |
| `domain:traffic:set <domain>`           | Record traffic counters manually (`--date=`, `--smtp-in=`, ...)                                                                                        |
| `domain:descriptor <domain>`            | Hosting settings descriptor (property names, types, defaults)                                                                                          |
| `config:get <dotted.key>`               | Show the resolved value (secrets masked; `--expose-secrets` bypasses)                                                                                  |
| `config:set <dotted.key> <value>`       | Write a configuration key to the user config file                                                                                                      |
| `config:list`                           | Show the resolved configuration with secrets masked                                                                                                    |
| `config:edit`                           | Open the user config file in `$EDITOR` and validate it afterwards                                                                                      |
| `config:path`                           | Show where plead stores configuration, data, and logs                                                                                                  |
| `template:render`                       | Render one template to MJML, HTML or text                                                                                                              |
| `template:serve`                        | Hot-reloading local template studio                                                                                                                    |
| `db:path`                               | Show the location of the SQLite database                                                                                                               |
| `db:query`                              | Open an interactive `sqlite3` shell on the database                                                                                                    |
| `server:info`                           | Server identity, versions, object counts, resources and update status                                                                                  |
| `server:session:list`                   | Currently opened control-panel sessions                                                                                                                |
| `server:session:get <session-id>`       | One session (the API has no single-session read; the list is filtered)                                                                                 |
| `server:session:terminate <session-id>` | Close a control-panel session                                                                                                                          |
| `server:admin`                          | Plesk Administrator personal information                                                                                                               |
| `server:service:status [service]`       | Service states (all, or one id like web, smtp, dns)                                                                                                    |
| `server:service:start <service>`        | Start a server service                                                                                                                                 |
| `server:service:stop <service>`         | Stop a server service                                                                                                                                  |
| `server:service:restart <service>`      | Restart a server service                                                                                                                               |
| `server:ip:list`                        | All IP addresses on the server                                                                                                                         |
| `server:ip:get <ip>`                    | One IP address (list filtered client-side)                                                                                                             |
| `server:ip:add <ip>`                    | Add an IP (`--netmask=`, `--type=shared\                                                                                                               |
| `server:ip:set <ip>`                    | Set IP properties (`--type=`, `--public-ip=`)                                                                                                          |
| `server:ip:remove <ip>`                 | Remove an IP address from the server                                                                                                                   |
| `server:components:list`                | Installed Plesk components                                                                                                                             |
| `server:components:install <id>`        | Install a component (`--update-id=`; docs shape, live validation pending)                                                                              |
| `server:extension:list`                 | Installed extensions                                                                                                                                   |
| `server:extension:get <id>`             | One extension by id                                                                                                                                    |
| `server:extension:install <id>`         | Install an extension by id or `--url=`                                                                                                                 |
| `server:extension:uninstall <id>`       | Uninstall an extension                                                                                                                                 |
| `server:extension:call <id> <op>`       | Call an XML API operation of an ApiRpc extension (`--param=name:value`; e.g. git: get/create/update/remove/deploy/fetch)                               |
| `server:ref [id]`                       | REST CLI-gate: available CLI ids (no arg) or the reference of one id                                                                                   |
| `server:exec <id> <args...>`            | Execute a Plesk CLI command via the REST CLI-gate (args after `--`, e.g. `extension -- --call sslit --help`; `--no-fail-on-error`)                     |
| `audit:trail`                           | Browse the audit trail (interactive TUI on a TTY, plain table otherwise; `--resource=`, `--result=`, `--limit=`)                                       |
| `audit:export`                          | Dump the entire audit trail (incl. change details) to a file (`--format=json or yaml`, `-o/--output`)                                                  |

Global options (available on every command):

| Flag | Behavior ||
| --------------- | -------------------------------------------------------------------------- ||
| `-c, --config` | Load only this config file; skip discovery and merging ||
| `--server` | Operate on this server (host or index; default: the first) ||
| `--write-config`| Persist the change to the config file (mail.group definitions) ||
| `--dry-run` | Log mutations without performing them - no network calls at all ||
| `--log-level` | Baseline file log level (default `info`); `-v/-vv/-vvv` also raise it ||
| `-v/-vv/-vvv` | Console verbosity (drives both console output and file log level) ||

## ⚙️ Configuration

Configuration is discovered from per-user and system-wide locations, in most-specific-first order, and merged with the user's files winning:

| OS      | User (wins)                               | System-wide fallback                 |
| ------- | ----------------------------------------- | ------------------------------------ |
| Linux   | `$XDG_CONFIG_HOME/plead` (`~/.config`)    | `/etc/plead`                         |
| macOS   | `~/Library/Application Support/plead`     | `/Library/Application Support/plead` |
| Windows | `%LOCALAPPDATA%\plead`, `%APPDATA%\plead` | `%ProgramData%\plead`                |

`plead.yaml` and `plead.json` are both supported (YAML preferred). One installation can manage several Plesk servers; `servers` is a list, a per-server section (named by host) overrides the general `mail` config for that server, and the **first server is the default** unless `--server` says otherwise.

```yaml
servers:
  - host: plesk.example.com
    secret_key: <key>
  - host: prod.example.com
    login: admin # or credentials instead of a secret key
    password: <password>

default_server: prod.example.com # used when neither --server nor PLEAD_SERVER is given

watch:
  interval: 60 # default interval of `plead watch` (--interval overrides)
  # optional: ingest absence JSON files dropped by external tools
  # (directory scripts, HR exports, ...) - disabled unless `paths.root` is set
  inbox:
    paths:
      root: /srv/plead/inbox # watched for *.json; processed/ and failed/ live inside
      # processed: done # optional (default: <root>/processed)
      # failed: failed # optional (default: <root>/failed)
    # template: resources/templates/autoresponder.txt.twig # default: template.paths.auto_reply
    # translations: resources/translations # default: template.paths.translations
    domain: company.com # mail domain for entries that use samAccountName
    timezone: Europe/Berlin # optional; defaults to the process TZ (match the Plesk server!)
    # data_file: /etc/plead/absence-data.yaml # baseline template variables
    # language: de
    # subject: "Automatische Antwort"
    # format: plain # plain|html; default: inferred from the rendered message
    # inclusive: true # end date counts as absence (template wording)

# optional: template + translation catalog locations (defaults shown)
template:
  paths:
    auto_reply: resources/templates/autoresponder.txt.twig
    translations: resources/translations
  organization: "Ad Noctem Collective" # optional: footer name
  urls: # optional: logo + social icons (map order)
    logo: "https://example.com/logo.png"
    # github: "https://github.com/adnoctem"
    # x: "https://x.com/adnoctemcollective"
    # web: "https://adnoctem.co"
  language: de # optional: render language for single-language templates

# general config applying to every server
mail:
  defaults:
    quota: 512MB # parsed to bytes; also accepts 1GB, 2 GB, 1048576
    antivirus: off # off|in|out|inout; default quota/antivirus for mail:address:set
  group:
    - address: all@example.com
      pattern: "^(1und1|payments|info|support|shop|noreply|marketing|bestellungen)@"
    - address: group2@example.com
      recipients:
        - user1@example.com
        - user2@example.com
  autoresponder:
    - address: user@example.com
      data_file: /etc/plead/replies/vacation-data.yaml # optional: JSON/YAML template variables
      start_date: "2026-08-20T08:00:00+02:00" # optional; quote to keep the offset
      end_date: "2026-08-30T18:00:00+02:00"
      inclusive: false # optional: whether the end date is vacation
      subject: "Automatische Antwort" # optional: reply subject (default: Re: <request_subject>)
      format: html # optional: plain|html (default: server default)

# per-server overrides: this section only applies to plesk.example.com
plesk.example.com:
  mail:
    group:
      - address: group@example.com
        pattern: "^(info|support)@"
```

Group entries define the **desired recipients** of a mail group:

- `pattern` — a PCRE (delimiters optional) of addresses to **exclude** from the list. The list holds every mailbox on
  its domain except those matching, and the list itself is never its own recipient. Entries with a domain-less
  address need an explicit `domain:`.
- `recipients` — manual recipients **appended** to the pattern-filtered set. They may point at any address, including
  domains Plesk does not manage (e.g. a consultant's mailbox at an external provider) — and a manual entry wins over
  the exclusion pattern. Without a `pattern`, the manual list is the whole set.
- An entry with neither is a configuration error; `pattern` and `recipients` freely combine.

`watch` re-derives every configured group every pass: new mailboxes join `all@…` automatically, disabled ones drop out, and `pbx@…`-style addresses never get in. If the group address does not exist on Plesk yet, the first push creates it as a forward-only mailname with the computed recipients (not a mailbox). The same definition can be applied once via
`mail:group:set <email> --rule='…' --recipients=…` (both flags combine; with neither, the configured entry for that address is used).

Add `--write-config` and the applied definition is written into the config file's `mail.group` (replacing an existing entry for the address), so the watcher keeps maintaining the list without a manual edit.

Auto-replies can be configured the same way via `mail.autoresponder` entries (`address`, optional `data_file`,
`start_date`, `inclusive`, `subject` and `format`, required `end_date`; quote the dates so the timezone offset
survives the YAML round-trip).
`mail:autoresponder:set <email>` without options falls back to the configured entry (a partial CLI run borrows the
missing pieces from it), `--write-config` persists the applied schedule (and `--enabled=false --write-config` removes
it), and `watch` re-records diverging definitions every pass.

`--subject` sets the reply subject (default: Plesk's `Re: <request_subject>`); `--format plain|html` selects the
autoresponder content type (Plesk stores `text/plain`/`text/html`; the element order in the XML packet matters,
see `docs/NOTES.md`). A rendered message that is HTML (e.g. the MJML template) is rejected unless
the format is `html` — pass `--force` to store it with a non-html format anyway. `--language de|en` selects the render language (default: `template.language` or the first catalog language) —
both the txt and the HTML templates render exactly one language per invocation.
`--template` renders a specific template for this invocation instead of
`template.paths.auto_reply` — handy to preview the MJML variant before switching it in the config.

`--data-file` (or `data_file`) sources template variables from a JSON or YAML file — e.g. `firstname`, `surname`,
`phonenumber`, `department`, `email`. The file is a flat map of Twig variables merged into the render context.
The scheduling window is injected
automatically as `startDate`/`endDate` (ISO 8601) and always wins over data-file values, so the rendered text can
never drift from the actual activation window; `--inclusive`/`--no-inclusive` (or an `inclusive` config entry) is
authoritative the same way. Any value that differs per language is a locale map, resolved as `key[lang] ?? key.de ?? key`
inside the language loop: `email: {de: buchhaltung@example.com, en: invoices@example.com}` renders the German
address in the German section and the English one in the English section (a plain string applies to every
language, and a locale missing from the map falls back to the German value). The same works for `department`,
`phonenumber` and any future field. `logo_url` optionally overrides
`template.urls.logo` for this autoresponder (e.g. a seasonal logo). With `--write-config` the data file path is
persisted alongside the message file, so `watch` renders the identical message.

Pass `--preview` to print the rendered message without recording an intent, touching the database or calling Plesk —
a pure dry check before anything goes live. It honors the same config fallback as a regular run, so
`mail:autoresponder:set <email> --preview` shows exactly what `watch` would push for a configured entry.

**Translations.** All sentences live in catalog files under `template.paths.translations` (default `translations`,
may point outside the phar): `resources/translations/autoresponder.de.yaml`, `autoresponder.en.yaml`, and so on — one file
per language. The templates are pure structure: they loop over the languages found in the catalog directory, so
**adding a language is a single new file** — no template or config edits. German (`de`) is the fallback locale: a
missing entry in any catalog degrades to the German text, so raw keys are never shown and rendering never fails —
a stale catalog cannot break the watcher. Each catalog also carries a `date_format` entry (e.g. `d.m.Y` vs
`F j, Y`) so dates render locale-appropriately. The `resources/translations/` folder ships the German and English catalogs;
the MJML template (`resources/templates/autoresponder.mjml.twig`) shares the same sentences and adds `phone_button` /
`email_button` labels. The single template picks the body sentence from the catalog: `absence` when no deputy is
present, and `forwarded_to` with `%deputy%` when the absence carries one. Templates that render MJML markup are **converted to final HTML automatically** by the
rendering pipeline (via the `mjml` CLI binary — install it with `npm install -g mjml` or set `MJML_BINARY_PATH`;
the container image ships it), so the message stored on Plesk is ready to send. Both templates render
**one language per invocation** (the `language` context variable — `--language`, `template.language` or a
per-entry `language`; default: the first catalog language). In the HTML template the phone number and email
inside the text are clickable `tel:`/`mailto:` links and the action buttons sit side by side below the card.
The email is fully responsive: below 650px viewport width MJML stacks the columns, the 196px page gaps shrink
to 56px and the card tightens its padding. Note that the mjml.io try-it-live pane is desktop-width only — to
preview the mobile layout, shrink your browser window below 650px.
**Branding.** `template.urls.logo` sets the logo, `template.urls.<network>` adds a social icon to the footer row
(map order), and `template.organization` sets the footer name. Supported networks are `facebook`, `twitter`, `x`,
`google`, `pinterest`, `linkedin`, `tumblr` and `xing` (share-capable; the template keeps the configured URL via
`-noshare`), plus `github`, `instagram`, `web`, `snapchat`, `youtube`, `vimeo`, `medium`, `soundcloud` and `dribbble`.
Omit a network to remove its icon. Template authors should note that Twig consumes one newline after every `{% %}` tag — keep two blank source lines
where the message should show a blank line (the shipped template documents this in its header comment).

### Inbox — absence files from external tools

`watch.inbox` turns `plead watch` into a drop-folder processor: every `*.json` file in
`watch.inbox.paths.root` describes one absence and is applied as an auto-reply (plus an optional
deputy forwarding). `ad-account-map.json` is reserved and never processed as an entry. The
format is intentionally **not tied to a directory service** — `email` addresses a mailbox
directly, `samAccountName` resolves through the account map or a domain fallback. Schema v1:

```json
{
  "schemaVersion": 1,
  "action": "schedule",
  "email": "erika.mustermann@example.com",
  "startDate": "2026-09-17",
  "startTime": "17:00",
  "endDate": "2026-09-22",
  "endTime": "12:00",
  "deputyEmail": "vertretung@example.com",
  "timezone": "Europe/Berlin",
  "language": "de",
  "subject": "Automatische Antwort",
  "format": "html",
  "inclusive": true,
  "data": {
    "firstname": "Erika",
    "surname": "Mustermann",
    "department": { "de": "Vertrieb" }
  },
  "submittedAt": "2026-09-17T16:57:46.987+02:00"
}
```

- **Identity** — `email` wins; otherwise `samAccountName` through `ad-account-map.json`, then `domain` (entry or `watch.inbox.domain`).
- **Schedule** — `startDate`/`endDate` required (`YYYY-MM-DD`); `startTime`/`endTime` optional
  (`HH:mm`; defaults: start of day / end of day). Times are interpreted in `timezone`, then the
  process timezone (Plesk exposes no server timezone — align `TZ` with the server).
- **Two modes** — with `deputyEmail` the _forwarding_ template is used (the reply says the mail
  is forwarded) and a forwarding to the deputy is configured; without it the usual out-of-office
  template is used and nothing is forwarded. Plesk forwarding keeps a local copy in the mailbox.
- **Expiry** — Plesk disables the reply itself via `end_date`, but forwarding has no end date, so
  `watch` removes the deputy forwarding once the absence has ended (and records it in the audit trail).
- **`action: "cancel"`** — ends an absence immediately (disables the reply, removes the deputy).
- **`data`** — arbitrary template variables, merged over `watch.inbox.data_file`; per-language maps
  resolve as `key[lang] ?? key.de ?? key`.
- **File lifecycle** — valid files move to `processed/`; malformed files and unknown mailboxes move
  to `failed/` with a `<name>.json.error.txt` sidecar. `--dry-run` leaves files in place.
  Writers should emit UTF-8 without BOM and write atomically (`.tmp` + rename).
- **Schema** — the machine-readable contract is
  [`resources/schema/inbox-entry.schema.json`](resources/schema/inbox-entry.schema.json) (JSON Schema draft 2020-12, schema v1).
- **AD generators** — see [Using the inbox in an AD environment](docs/USAGE.md#using-the-inbox-in-an-ad-environment)
  for a rough GPO → invocation script → server share workflow and how
  `ad-account-map.json` resolves `samAccountName`.

Environment variables override everything:

- `PLEAD_SERVER` — selects the server (same as `--server`; host or index)
- `PLEAD_PLESK_SECRET_KEY` — overrides the selected server's secret key
- `PLEAD_PLESK_LOGIN` / `PLEAD_PLESK_PASSWORD` — override the selected server's credentials
- `PLEAD_DATA_DIR` — overrides the data directory itself (used by the containerized deployment, e.g. `/data`)

Data (SQLite database and log file) lives under the platform data directory — on Linux `~/.local/share/plead/`. Every row in the database is scoped to its server (`server` column), so the desired state and audit trail of multiple servers coexist without collisions. The SQLite database is the authoritative record of the desired state and the full audit trail.

## 📚 Documentation

- [`docs/USAGE.md`](docs/USAGE.md) — configuration guide and deep dives on the complex commands (groups, auto-replies, the inbox, watch)
- [`docs/NOTES.md`](docs/NOTES.md) — empirically-verified Plesk XML API facts (packet shapes, quirks, batching) collected against Obsidian 18.0.80
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

_Licensed under the [MIT][license] license._

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

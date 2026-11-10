# Usage

`plead` manages Plesk-hosted mail resources over the XML-RPC API: scheduled
auto-replies (with a start time Plesk itself cannot express), mail group
membership, mailbox aliases and an inbox of absence files dropped by external
tools. Local desired state and the full audit trail live in SQLite; a single
`watch` daemon converges Plesk toward that state.

## Requirements

- **PHP 8.4.27+** (or **8.5.12+** on the 8.5 branch) — earlier patch releases
  are affected by PHP2Shell (CVE-2026-109618 / CVE-2026-109619). `plead` is a
  CLI and never serves HTTP, but it requires a patched runtime regardless.
- Extensions: `ctype`, `curl`, `dom`, `iconv`, `pdo_sqlite`, `phar`,
  `simplexml`, `xml`, `zlib`; `pcntl` additionally enables graceful shutdown
  for `watch` (and `watch -d`).
- Optional: the `mjml` CLI (`npm install -g mjml`, or `MJML_BINARY_PATH`) for
  HTML auto-reply templates.

## Installation

- **Phar** — download `plead.phar` from the latest release, `chmod +x`, run it.
- **Docker** — `ghcr.io/adnoctem/plead` (PHP 8.4/8.5 tags); `/data` is the
  persistent volume (`PLEAD_DATA_DIR=/data` names the state directory itself).
- **Source** — `composer install`, then `bin/plead`.

> **Docker volume permissions.** The image runs as UID/GID **1001**
> (`plead:plead`). Bind-mounted host directories must be writable by that
> user, e.g. `sudo chown -R 1001:1001 data archive`. If you see
> `mkdir(): Permission denied` or `Data directory is not writable` for
> `/data`, the mounted volume is still owned by another user.

## Configuration

Configuration is discovered in most-specific-first order and merged, with the
user's files winning:

| OS      | User (wins)                               | System-wide fallback                 |
| ------- | ----------------------------------------- | ------------------------------------ |
| Linux   | `$XDG_CONFIG_HOME/plead` (`~/.config`)    | `/etc/plead`                         |
| macOS   | `~/Library/Application Support/plead`     | `/Library/Application Support/plead` |
| Windows | `%LOCALAPPDATA%\plead`, `%APPDATA%\plead` | `%ProgramData%\plead`                |

`plead.yaml` and `plead.json` are both supported (YAML preferred). One
installation can manage several Plesk servers: `servers` is a list, the first
entry is the default, and a per-server section keyed by host overrides the
general `mail` config for that server.

```yaml
servers:
  - host: plesk.example.com
    secret_key: <key> # or login + password
  - host: plesk2.example.com
    login: admin
    password: <password>

default_server: plesk.example.com # --server / PLEAD_SERVER override this

log_level: info # debug|info|notice|warning|error

watch:
  interval: 60
  inbox: # optional; disabled unless path is set
    path: /srv/plead/inbox
    # processed_path: processed # default: <path>/processed
    # failed_path: failed # default: <path>/failed
    domain: example.com # for entries that use samAccountName
    timezone: Europe/Berlin # default: process TZ (Plesk exposes no timezone)
    # data_file: /etc/plead/absence-data.yaml
    # template_autoreply: resources/templates/autoresponder.txt.twig
    # template_forwarding: resources/templates/autoresponder-forwarding.txt.twig
    # language: de
    # subject: "Automatische Antwort"
    # format: plain # plain|html; default: inferred from the rendered message
    # inclusive: true

template:
  auto_reply_path: resources/templates/autoresponder.txt.twig
  translations_path: resources/translations
  logo_url: "https://example.com/logo.png"
  language: de

mail:
  defaults:
    quota: 512MB # parsed to bytes; also accepts 1GB, 2 GB, 1048576
    antivirus: off # off|in|out|inout
  group:
    - address: all@example.com
      pattern: "^(info|noreply|pbx)@" # exclusion PCRE over the domain's mailboxes
    - address: group2@example.com # external/manual recipients
      recipients: [consultant@partner.example]
  autoresponder:
    - address: user@example.com
      data_file: /etc/plead/replies/vacation.yaml
      start_date: "2026-08-20T08:00:00+02:00"
      end_date: "2026-08-30T18:00:00+02:00"
      inclusive: false
      subject: "Automatische Antwort"
      format: html
      language: de

# per-server override: only applies to plesk.example.com
plesk.example.com:
  mail:
    group:
      - address: group@example.com
        pattern: "^(info|support)@"
```

Every value is validated by the config tree on load. `config:list` shows the
resolved configuration with credentials masked; it prints the configured
`servers` list plus `selected_server` / `default_server`, and never the
internal `server` / `plesk` blocks. To inspect effective credentials, read the
loaded configuration file (`config:path`). `config:set <key> <value>` writes
one key into the persisted config file — the file `config:edit`/`config:set`
targets is the first existing config path, or the first candidate when none
exists.

### Environment variables

- `PLEAD_SERVER` — selects the server (same as `--server`; host or index)
- `PLEAD_PLESK_SECRET_KEY` — overrides the selected server's secret key
- `PLEAD_PLESK_LOGIN` / `PLEAD_PLESK_PASSWORD` — override credentials
- `PLEAD_DATA_DIR` — overrides the data directory itself (e.g. `/data` in Docker)
- `MJML_BINARY_PATH` — explicit `mjml` binary (needed inside the phar)
- `TZ` — process timezone; must match the Plesk server (see "Timezones")

### Data and audit trail

The SQLite database (`plead.sqlite`) and `plead.log` live in the platform data
directory (`~/.local/share/plead` on Linux; `PLEAD_DATA_DIR` overrides the
directory itself, e.g. `/data` in the container). Every row is scoped to its
server, so desired state and history of multiple servers coexist. The database
is the authoritative record of what `plead` intends and what it did:

1. a mutation records the intent (`reconciled = 0` plus a `pending` audit row),
2. the RPC is issued,
3. the row is marked reconciled and the audit row resolves to `ok`, `dry-run`
   or `error:<message>`.

Failed intents stay dirty and are retried by `watch`. Rows are never deleted —
`auto_replies.status` and `mail_recipients.removed_at` keep the history.

## Commands

Uniform verbs: `list` (enumerate), `get <target>` (single resource, live
Plesk), `set` (mutate). Reads default to the **live server**; `--local` reads
the SQLite desired state instead. Every mutating command supports `--dry-run`,
which skips all RPCs.

| Namespace            | Commands                                                                                        |
| -------------------- | ----------------------------------------------------------------------------------------------- |
| `mail:group`         | `list`, `get`, `set`, `add`, `remove`                                                           |
| `mail:alias`         | `list`, `get`, `set`, `add`, `remove`                                                           |
| `mail:address`       | `list`, `get`, `set`, `remove`, `password`, `rename`, `export`                                  |
| `mail:autoresponder` | `list`, `get`, `set`                                                                            |
| `domain`             | `list`, `get`, `set`, `add`, `remove`, `traffic:get`, `traffic:set`, `descriptor`               |
| `server`             | `info`, `admin`, `session:*`, `service:*`, `ip:*`, `components:*`, `extension:*`, `ref`, `exec` |
| `config`             | `get`, `set`, `list`, `view`, `path`, `edit`                                                    |
| `db`                 | `path`, `query`                                                                                 |
| `audit`              | `trail`, `export`                                                                               |
| —                    | `watch`                                                                                         |

Run `plead list` or `plead <command> --help` for the full option set.

### Mail groups

A group is a forwarding list (`all@example.com`) whose recipients `plead`
derives or sets explicitly:

- `--rule='<pcre>'` — every mailbox on the group's domain whose address does
  **not** match the pattern becomes a recipient. The group's own address is
  never added; the list is authoritative (non-matching recipients are purged).
- `--recipients=a@b,c@d` — manual recipients appended verbatim (any domain,
  including external providers). With both flags the manual entries win over
  the exclusion pattern.
- With neither flag the configured `mail.group` entry for that address is used.
- `--write-config` persists the applied definition into the config file so
  `watch` keeps maintaining it.

`mail:group:add`/`remove` are incremental edits; `watch` re-derives rule-driven
lists every pass, so new mailboxes join automatically and leavers drop out.
Adoption: the first time `plead` touches a list it imports the current Plesk
recipients, so pre-existing forwarding is preserved rather than purged.

If the group address does not exist as a mailname on Plesk yet, the first push
creates it as a **forward-only** mailname with the computed recipients. It is
not a mailbox; mail is delivered to the recipients only.

### Auto-replies

Plesk supports `end_date` natively but has no concept of a **start date** —
`plead` computes the window and pushes the reply the moment it opens (that is
what `watch` is for). Dates are stored as ISO 8601 with an explicit offset;
naive CLI/config values are interpreted in the process timezone.

```bash
plead mail:autoresponder:set user@example.com --start-date=2026-08-20T08:00:00+02:00 \
  --end-date=2026-08-30T18:00:00+02:00 --subject="Automatische Antwort" --format=html
plead mail:autoresponder:set user@example.com --preview     # render only, no writes
plead mail:autoresponder:set user@example.com --enabled=false
```

- `--template` renders a specific template for this invocation (falling back to
  `template.auto_reply_path`); `--data-file` supplies template variables;
  `--language de|en` selects the render language; `--inclusive/--no-inclusive`
  chooses the "through %end%" vs "until %end%" wording.
- Without options, the command borrows the configured `mail.autoresponder`
  entry for that address (so `--preview` shows exactly what `watch` would push).
- `--write-config` persists the applied schedule; `--enabled=false
--write-config` removes the entry from the config.
- HTML output (e.g. the MJML template) requires `--format=html`, otherwise the
  command refuses to store it (`--force` overrides).

### The inbox (absence files)

`watch.inbox` turns `watch` into a drop-folder processor: every `*.json` file
in `watch.inbox.path` describes one absence (`ad-account-map.json` is the one
reserved exception). The format is deliberately not tied to a directory
service — `email` addresses a mailbox directly, while `samAccountName` is
resolved through `ad-account-map.json` (when present) or a `domain` fallback.

The machine-readable contract is
[`resources/schema/inbox-entry.schema.json`](../resources/schema/inbox-entry.schema.json)
(JSON Schema draft 2020-12, schema v1). Point generators and UI validators at
that file rather than reimplementing the table below.

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

| Field            | Required                | Meaning                                                            |
| ---------------- | ----------------------- | ------------------------------------------------------------------ |
| `schemaVersion`  | no (default `1`)        | unknown versions are rejected                                      |
| `action`         | no (`schedule`)         | `schedule` or `cancel` (cancel needs only the identity fields)     |
| `email`          | one of both             | target mailbox; wins over `samAccountName`                         |
| `samAccountName` | ″                       | resolved through `ad-account-map.json`, else `domain`/config       |
| `domain`         | no                      | fallback mail domain for `samAccountName`                          |
| `startDate`      | yes                     | `YYYY-MM-DD`                                                       |
| `startTime`      | no (default `00:00`)    | `HH:mm` (`HH:mm:ss` accepted)                                      |
| `endDate`        | yes                     | `YYYY-MM-DD`                                                       |
| `endTime`        | no (default `23:59:59`) | `HH:mm`                                                            |
| `deputyEmail`    | no                      | presence switches to forwarding mode                               |
| `timezone`       | no                      | IANA name; defaults to `watch.inbox.timezone`, then the process TZ |
| `language`       | no                      | per-entry render language                                          |
| `subject`        | no                      | per-entry reply subject                                            |
| `format`         | no                      | `plain` or `html` (default: inferred from the rendered message)    |
| `inclusive`      | no                      | template wording only                                              |
| `data`           | no                      | arbitrary template variables, merged over `watch.inbox.data_file`  |
| `submittedAt`    | no                      | ISO 8601 timestamp (audit/ordering only)                           |

A `cancel` entry only needs the identity fields — the schedule fields are
ignored:

```json
{
  "schemaVersion": 1,
  "action": "cancel",
  "email": "erika.mustermann@example.com"
}
```

**Two modes.** With `deputyEmail` the _forwarding_ template is rendered (the
reply says the mail is forwarded) and a forwarding to the deputy is configured.
Without it, the regular out-of-office template is used and nothing is
forwarded. Plesk forwarding delivers a copy to the deputy and keeps the mail in
the mailbox — it is not a redirect.

**Expiry.** Plesk disables the reply itself at `end_date`, but forwarding has no
end date: `watch` removes the deputy forwarding once the absence has ended and
disables the row (audit trail). `action: "cancel"` does the same immediately.
Existing forwarding on a mailbox is adopted before the deputy is added, so only
the deputy is removed again on expiry.

**File lifecycle.** Valid files move to `processed/`; malformed files and
unknown mailboxes move to `failed/` with a `<name>.json.error.txt` sidecar.
Render/RPC failures leave the file in place for the next pass. `--dry-run`
leaves files untouched. Writers should emit **UTF-8 without BOM** and write
atomically (`.tmp` + rename) so a half-written file is never read.

#### Using the inbox in an AD environment

`plead` does not query Active Directory itself, but it can read an optional
`ad-account-map.json` in the inbox directory to resolve entries that only
carry a `sAMAccountName`. This is useful when the AD `mail` attribute is
missing or stale.

1. **Account map.** Put `ad-account-map.json` in the inbox directory, next to
   the per-user JSON files. It is a JSON object mapping each `sAMAccountName`
   to its mailbox address:

   ```json
   {
     "erika.mustermann": "erika.mustermann@example.com",
     "jdoe": "john.doe@example.org"
   }
   ```

   `plead` reads it on every pass and never treats it as an absence entry (it
   is not archived). Lookups are case-insensitive. If the map contains entries
   but not the entry's `sAMAccountName`, the entry is rejected instead of
   guessing `<sAMAccountName>@<domain>`; update the map when users join. An
   explicit `email` in the entry always wins.

2. **Invocation script.** Publish a small script on a server share and run it
   from a GPO logon script or a published shortcut. The script resolves the
   current user's `sAMAccountName`, prompts for the absence window and
   optional deputy, and writes `<sAMAccountName>.json` in schema v1 to the
   inbox. It may emit `email` directly or only `samAccountName`; `plead`
   resolves the latter from the map. Write atomically (`.tmp` + rename), use
   UTF-8 without BOM, and sign the script or allow it through the execution
   policy so the GPO/shortcut can run it.

3. **Server share.** Keep the script, the map and the inbox in one place:

   ```text
   \\fileserver\absence\OOOConfiguration\
   ├── Invoke-OutOfOfficePrompt.ps1
   ├── ad-account-map.json        <- read by plead, never archived
   └── erika.mustermann.json      <- absence entry
   ```

   CIFS-mount `OOOConfiguration` on the host that runs `watch` and bind-mount
   it into the container. The mount must be writable by the container user:
   `watch` moves each processed file to the local archive and removes the
   source from the share.

   ```yaml
   volumes:
     - /mnt/absence/OOOConfiguration:/inbox
   ```

   ```yaml
   watch:
     inbox:
       path: /inbox
   ```

4. **Keep the map current.** Regenerate it when users join or leave (for
   example from an AD export) and replace the file on the share atomically
   (`.tmp` + rename) so a pass never reads a half-written map. The watcher
   reloads it on the next pass; no restart is required.

If every user shares one mail domain, an entry can emit `samAccountName` +
`domain` instead of `email`; `plead` uses `domain` (or `watch.inbox.domain`)
when the map is absent or empty. With multiple domains, keep the map current
and let it resolve the address — one configured domain cannot cover them.

### The watcher

```bash
plead watch                  # foreground
plead watch --interval=30    # override watch.interval
plead watch --full           # re-verify all managed state against Plesk
plead watch -d               # detached: log to plead.log, pid file in the data dir
```

Each pass: **inbox files** → **inbox expiry** → configured auto-replies →
rule-driven groups → the auto-reply/group/alias reconcilers. With `--full` the
reconcilers also re-check state that was already reconciled (drift detection).
The loop stops cleanly on SIGINT/SIGTERM between passes; the spinner is only
drawn on a TTY.

The pass summary (`changed N items`) goes to stdout; the individual rule and
reconciler changes (which address was added/removed/created) are written to
`plead.log` in the data directory. In the container, follow it with
`docker compose exec plead tail -f /data/plead.log` (or `tail -f data/plead.log`
on the host).

For a service manager, run it in the foreground (e.g. `Restart=always` in
systemd or `restart: unless-stopped` in Docker) rather than with `-d`.

### Addresses, aliases, domains, server

- `mail:address:list [--domain=]` enumerates mailboxes; `get <email>` reads one;
  `set` updates description/outgoing limit/quota/antivirus (missing flags fall
  back to `mail.defaults`); `password` sets a password (`--generate` available);
  `rename` changes the local part; `remove` deletes; `export` dumps.
- `mail:alias:add|remove|set|list|get` manages additional addresses that
  deliver into a mailbox (stored as local parts on the Plesk side).
- `domain:list|get|set|add|remove` manages webspaces/sites, including status
  (`--status=enabled|disabled`), hosting type/destination and traffic
  (`domain:traffic:get|set`), plus `domain:descriptor` for hosting capabilities.
- `server:*` covers server info, admin contact, sessions, services, IPs,
  components, extensions and the REST CLI gate (`server:ref`, `server:exec`).

### Local commands

- `config:get|set|list|path|edit` — inspect/edit the merged configuration.
- `db:path|query` — locate/open the SQLite database (needs the `sqlite3` CLI).
- `audit:trail` — TUI pager over the audit log (plain table fallback);
  `audit:export` dumps it as JSON/YAML.

## Templates and translations

Templates are Twig files rendered with `autoescape` off. Variables come from
the data file (or the inbox `data` object) plus the schedule: `startDate`,
`endDate` and `inclusive` are always injected by the renderer and always win
over data values, so the text can never drift from the real window. Standard
variables the shipped templates use: `firstname`, `surname`, `phonenumber`,
`department`, `email` (contact), `logo_url`. Any value may be a locale map
(`{de: …, en: …}`), resolved as `key[lang] ?? key.de ?? key`.

All sentences live in `resources/translations/autoresponder.{locale}.yaml`
(`template.translations_path`). German is the fallback locale — a missing entry
degrades to German, never to a raw key, so rendering cannot fail. Adding a
language is a single new catalog file. Each catalog also carries `date_format`.

The MJML template (`resources/templates/autoresponder.mjml.twig`) is converted
to HTML automatically by the render pipeline (via the `mjml` CLI); the stored
message is ready for Plesk. The forwarding variants
(`autoresponder-forwarding.{txt,mjml}.twig`) use the `forwarded_to` sentence
with `%deputy%`.

## Timezones

Plesk's XML and REST APIs expose **no server timezone**. Naive dates/times in
config entries and inbox files are interpreted in `watch.inbox.timezone` (or
the process `TZ`, default `Europe/Berlin`). Set it to the Plesk server's
timezone — otherwise auto-replies start/end at the wrong wall-clock time.

## Security

- Keep credentials out of the repository: `plead.yaml` lives in the user config
  directory (or is mounted into the container); never commit it.
- Use a Plesk secret key (scoped, revocable) instead of admin credentials when
  possible.
- Run a patched PHP (see Requirements). `plead` never serves HTTP.
- `--dry-run` skips all mutating RPCs structurally in the gateway.

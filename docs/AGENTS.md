# AGENTS.md

Agent instructions for working in the `plead` repository. This file lives in `docs/` and is symlinked into the repository root (`AGENTS.md` → `docs/AGENTS.md`) — edit the `docs/` copy.

## What plead is

A PHP 8.4+ Symfony Console CLI that manages Plesk-hosted mail resources over the Plesk XML-RPC API (HTTPS, port 8443, secret key or admin credentials). Local state (desired state + audit trail) lives in SQLite; the Plesk server holds the live state. All network access goes through `src/Gateway/PleskMailGateway.php` — nothing else may talk to Plesk.

## Developer commands

```bash
composer install                 # no build step beyond this
vendor/bin/phpunit               # full suite (152+ tests); phpunit.xml.dist at repo root
vendor/bin/phpunit --filter SomeTestName   # single test class/method
php -l src/.../File.php          # syntax check (no dedicated PHP linter)
pre-commit run --all-files       # yaml/markdown/actionlint checks before committing
php bin/plead list               # see all commands
```

`failOnWarning`/`failOnRisky` are enabled in phpunit.xml.dist — deprecation warnings fail the suite (e.g. `fputcsv()` needs explicit `escape: ''` on PHP 8.5).

## Architecture in one screen

- **Audit-first mutations.** Every mutation follows: (1) write the intent to SQLite (`reconciled = 0` + a `pending` `sync_log` entry), (2) RPC to Plesk (or `--dry-run`), (3) finalize: `reconciled = 1` + resolve log to `ok`/`error:<msg>`. Failures stay dirty for the watcher. See `src/Reconciler/*`, `src/Repository/*`.
- **Rows are never deleted.** `auto_replies.status` (`scheduled`/`disabled`), `mail_recipients.removed_at` soft-delete. The DB is the audit trail.
- **Reads default to LIVE Plesk; `--local` reads SQLite.** `AbstractMailCommand::addLocalOption()`/`isLocal()`.
- **Watchers** (`src/Command/Mail/AbstractWatchCommand.php`): dirty-only by default (`reconciled = 0` / `unreconciledLists()`), `--full` sweeps everything. `Spinner` (braille frames, CR+`ESC[2K`) animates the wait; only when stdout is a TTY.
- **Batching.** The Plesk API has no cross-domain mail listing, so live list commands batch N queries into one HTTP POST via `PleskMailGateway::*Bulk()` + `Client::multiRequest` → `mail:address:list` = 2 round trips, group/autoresponder/export = 3.

## Command structure

Uniform verbs per namespace: `list` (enumerate), `get <target>` (single resource), `set` (mutate). No mixed verbs, no `show`/`enable`/`update` — those were deliberately unified away.

- `src/Command/Mail/Group/` — `mail:group:list|get|set|add|remove|watch` (forwarding recipients)
- `src/Command/Mail/Address/` — `mail:address:list|get|set|delete|password|export` (mailboxes)
- `src/Command/Mail/Autoresponder/` — `mail:autoresponder:list|get|set|watch` (auto-replies; `set --enabled=false` disables)
- `src/Command/Domain/` — `domain:list|get|set` (webspaces; `--status` accepted but errors "not supported yet" — TODO in code)
- `src/Command/Config/`, `src/Command/Db/` — local-only commands

## Plesk XML API hard truths (all empirically validated)

Full detail: `docs/plesk-xml-api-notes.md`. The short version an agent must not re-learn the hard way:

- **Enumerate WEBSPACES, not sites.** On Obsidian-with-subscriptions setups an unfiltered `site-get` returns zero results; domains live at the webspace level and the main site shares the webspace id. `listDomains()` uses `<webspace><get>` with a site fallback.
- **Packet shape rules.** `<filter>` first, `<dataset>` after. An **empty `<dataset/>` makes the server omit ids** — always request `<dataset><gen_info/></dataset>`. Never send a site/webspace id of `0` (schema rejects it) — skip id-less results.
- **`mail-update set` allowed properties** (verbatim): `forwarding, alias, autoresponder, password, user-guid, antivir, outgoing-messages-mbox-limit, description`. **`mailbox` is NOT settable** on existing mailboxes (create-time only) — `mail:address:set` has no `--enabled`/`--quota` because of this. `enable`/`disable` toggle the whole site's mail service (site-id only), not one mailbox.
- **Password shape:** `<password><value>…</value><type>plain|crypt</type></password>` inside `update/set`. There is no `set-password` operation.
- **Not-found semantics:** a read of a non-existent mailname throws `PleskX\Api\Exception: mail does not exist` (the lib's `verifyResponse`). `PleskMailGateway::requestOrNull()` maps that ("does not exist|not found") to `null`; genuine errors rethrow.
- **`multiRequest` returns plain `\SimpleXMLElement` per op** (NOT `XmlResponse`) — type batch handlers accordingly, and per-op errors do NOT throw (must be checked via `okResults()`).
- Any new mutation packet must be validated against the live server before shipping (see "Live server workflow" below).

## Testing conventions

- `tests/Gateway/PleskMailGatewayTest.php` — `FakeClient` (extends `PleskX\Api\Client`) asserts packet shapes and canned responses at the XML level. Its `multiRequest()` override must return plain `SimpleXMLElement` (mirroring the lib).
- `tests/Support/RecordingGateway.php` — in-memory fake used by command tests (no XML, no network). Command tests build a fake `PathProviderInterface` + `RuntimeContext` and run `CommandTester`.
- **Never hit the real Plesk server from tests.** Isolate via `HOME`/`XDG_*`/`PLEAD_*` env vars to scratch temp dirs.
- New repository/reconciler behavior: extend `tests/Repository/*`, `tests/Reconciler/*` (the reconcilers are the safety-critical convergence logic).

## Live server workflow (when the user asks to verify)

- Real credentials live in `~/.config/plead/plead.yaml` (`plesk.host`, `plesk.secret_key`). **Never print the key**; probe scripts read it from config or env.
- Read-only commands (`*:list`, `*:get`, `domain:get`, `db:*`) are safe to run against the live server.
- **Mutation probes require explicit user confirmation first** and should target a designated disposable resource (e.g. `probe@delta4x4.net`, `4x4d.de` description). Revert afterwards. The server is the user's production mail infrastructure.
- `--dry-run` skips all RPCs structurally in the gateway — use it for mutation dry checks.

## Environment gotchas

- **Spawn mechanics:** `InteractiveProcessLauncher` (used by `config:edit`, `db:query`) must use fork + `pcntl_exec` (no `/bin/sh`): the `sh -c` wrapper breaks TUIs on WSL2/Windows Terminal (ConPTY). Falls back to `passthru` without pcntl. Terminal-mode reset sequences (mouse/focus reporting) around the spawn.
- **Terminal control sequences** (spinner, reset, cursor) must be gated on `stream_isatty(STDOUT)` + non-Windows — they corrupt piped output otherwise.
- On WSL/ConPTY, remember `reset` (or a fresh tab) after a hard-killed TUI.
- SQLite schema changes go in `config/schema.sql` **plus** guarded `ALTER TABLE` steps in `Connection::migrate()` (existing DBs upgrade in place; backfill idempotently).

## Docs map

- `README.md` — usage, command reference, configuration, audit-trail model
- `docs/CONTRIBUTING.md` — commit format (semantic-release/Conventional Commits), PR process, layout
- `docs/plesk-xml-api-notes.md` — **canonical empirically-verified API facts** (read before touching the gateway)
- `docs/AGENTS.md` — this file (symlinked to repo root)
- `docs/ACKNOWLEDGEMENTS.md` — sources the project learned from

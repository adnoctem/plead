# Plesk XML API notes

Empirically-verified facts about the Plesk XML API (tested against **Plesk Obsidian 18.0.80 Update 3, XML API 1.6.9.1**). Complements the official docs at <https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-mail.34477/> — where a conflict exists, the server's schema validator wins (see the `mailbox` discrepancy below).

## Object model

- Plesk distinguishes **webspace** (subscription) from **site** (domain) objects.
- On the tested server each domain is its own webspace, and the **main site of a webspace shares the webspace id** (`webspace-guid == guid`, `webspace-id == id`).
- **Unfiltered `site-get` returns zero results** on this setup; enumerate **webspaces** instead: `<webspace><get><filter/><dataset><gen_info/></dataset></get></webspace>`.

## Request packet rules

- `<filter>` must be the **first** child of `<get>`; `<dataset>` comes after. An empty `<dataset/>` makes the server **omit per-result ids** — always request at least one data tag (`<dataset><gen_info/></dataset>`).
- Site/webspace ids that come back as `0` are rejected by follow-up requests (`id_type` validation) — skip id-less results.
- Errors: the lib's `verifyResponse` throws `PleskX\Api\Exception` on the first `status=error` result. **`multiRequest` skips that check** — per-operation errors must be inspected individually (our `okResults()` pattern).

## mail operator

- **`mail-get_info` filter**: `<site-id>` + optional `<name>`; data tags `<mailbox/>`, `<forwarding/>`, `<autoresponder/>` may be combined. A site without mail returns `status ok` with no `<mailname>` (not an error). A **non-existent mailname throws `mail does not exist`** — treat that (and `not found`) as "null" in read methods.
- **`mail-update set` properties** (the full allowed list, verbatim from the server): `forwarding, alias, autoresponder, password, user-guid, antivir, outgoing-messages-mbox-limit, description`.
  - **`mailbox` is NOT settable on existing mailboxes** — the official docs list it in `mailnameUpdateType`, but the server rejects it in `update/set` (and `set-password` does not exist). Mailbox `enabled`/`quota` are **create-time only**.
  - `password` is element-only: `<password><value>…</value><type>plain|crypt</type></password>` (`crypt` is Linux-only).
- **`mail-enable` / `mail-disable` toggle the site's mail service**, not a single mailbox: `<mail><enable><site-id>N</site-id></enable></mail>` (site-id is the only allowed child; `filter`/`name`/`id`/`mailname` are all rejected).
- Forwarding: `<mail><update><add|remove>…<forwarding><address>…</address></forwarding>` (idempotent; safe to retry).
- Removal: `<mail><remove><filter><site-id>N</site-id><name>X</name></filter></remove></mail>` (bare `<name>`, not `<mailname>`).

## webspace operator

- Enumeration and reads as above; `domain:get` requests `gen_info + hosting + limits + prefs` datasets.
- Update shape (from the plesk library's `Webspace::setProperties`, validated live): `<webspace><set><filter><name>…</name></filter><values><gen_setup><description>…</description></gen_setup></values></set></webspace>`.
- Status convention: `0` = enabled, `16` = disabled (via `gen_setup`).

## Batching (`Client::multiRequest`)

- Accepts array-form requests, builds one `<packet>` with N top-level operations, one HTTP POST.
- Returns **plain `SimpleXMLElement` per operation** (rebuilt via `simplexml_load_string`), NOT `XmlResponse` — type your batch handlers against `\SimpleXMLElement`.
- Live list commands use it: `mail:address:list` = 2 round trips, `mail:group:list`/`mail:autoresponder:list`/`mail:address:export` = 3.

## Misc

- `curl_close()` is deprecated on PHP 8.5 (vendor lib noise) — `bin/plead` suppresses deprecations.
- The official docs for `mail-update` (modifying-mail-account-settings) describe the `set` sub-operation semantics correctly: it *replaces* all settings mentioned, keeps others untouched — and samples match the validated shapes.

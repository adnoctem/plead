# Plesk XML API notes

Empirically-verified facts about the Plesk XML API (tested against **Plesk Obsidian 18.0.80 Update 3, XML API 1.6.9.1**). Complements the official docs at <https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-mail.34477/> — where a conflict exists, the server's schema validator wins (see the `mailbox` discrepancy below).

## Authentication (API key)

- Secret-key auth is **NOT HTTP Basic**. The server wants a custom **`KEY: <secret>` HTTP header** — HTTP Basic (or no auth at all) yields `<system><status>error</status><errcode>1029</errcode><errtext>Authentication method is not specified</errtext></system>`.
- Account credentials use two other custom headers: `HTTP_AUTH_LOGIN: <login>` + `HTTP_AUTH_PASSWD: <password>`.
- The lib (`plesk/api-php-lib`, `Client::getHeaders()`) sends `KEY: $this->secretKey` when a key is set, otherwise the two `HTTP_AUTH_*` headers — plus `Content-Type: text/xml` and `HTTP_PRETTY_PRINT: TRUE`.
- In Postman, this means: plain headers, **no Basic-auth helper** (Postman's Basic auth would otherwise be the natural-but-wrong choice).

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
- **`mailbox/quota` IS settable** on existing mailboxes via `<mailbox><quota>N</quota>` inside `update/set` — validated live (256 → 512 MiB on admin@delta4x4.net, status ok). Earlier notes claiming "mailbox is not settable" were wrong: the likely reject was the `enabled` child, not the whole node. `mailbox<enabled>` on existing mailboxes: not tested separately; treat as unsupported (the GUI has no
  per-mailbox disable either).
  - **Schema vs server, both ways:** the published XSD (1.6.9.1, `plesk/api-schemas` repo) defines `mailbox{enabled: boolean, quota: long}` AND `cp-access` in `mailnameUpdateType` — the server additionally accepts `password` and `user-guid`, which the XSD omits. Treat the **server as truth**; the XSD is a hint, not a contract.
  - `password` is element-only: `<password><value>…</value><type>plain|crypt</type></password>` (`crypt` is Linux-only).
- **Aliases are plain strings.** `alias` is `string 0..∞` in `mailnameAddType`/`mailnameUpdateType` (create, update add/remove/set): `<mailname><name>x</name><alias>info</alias></mailname>`. **The server stores aliases as LOCAL PARTS** (no `@domain`). Reading them requires the **`aliases` data tag** (plural — the server rejects `alias`); the response then contains `<mailname><alias>info</alias>…`.
  The server's own error message lists the valid `get_info` data tags as `mailbox, mailbox-usage, forwarding, aliases, autoresponder` — the XSD's `mailbox|forwarding|autoresponder` choice is incomplete. The `mailbox-usage` tag adds `<mailbox><usage>N</usage>` (bytes used) to the response; `quota` comes with `<mailbox/>`.
- **Rename is a standalone op** (not `update`): `<mail><rename><site-id>N</site-id><name>old</name><new-name>new</new-name></rename></mail>` — `new-name` is the local part only. Multiple `<rename>` sections per packet are allowed. Shape confirmed from XSD 1.6.9.1; live validation pending.
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

## More empirically-validated shapes (2026-08-20)

- **`antivir` values are an enum**: `off | in | out | inout` (NOT `on`/`off` — `on` is rejected with errcode 1014). Flat element in `update/set`; the response reports it as `<antivir>off</antivir>`.
- **`autoresponder` in `update/set` accepts**: `enabled, subject, text, charset, end_date, attachment, forward` — **`start_date` is REJECTED** (1014: "Expected is one of ( attachment, forward, end_date )") and **`content_type` is also rejected** (server fills it itself; errtext "Expected is one of ( attachment, forward, end_date )"). Window-start scheduling must be computed client-side (as plead
  does).
- **`forwarding.enabled` IS settable** in `update/set` (`<forwarding><enabled>false</enabled>` → ok). Whether `set` wipes the address list alongside is untested — assume yes (set = replace-all-mentioned), test on a forwarding-free mailbox.
- **Webspace `status` IS settable** via `<webspace><set><values><gen_setup><status>0|16</status>` (0 = enabled, 16 = disabled) — validated live on 4wd.de.
- **`server/get` data tags** (all optional, combinable): `key, gen_info, components, stat, admin, interfaces, services_state, prefs, shells, session_setup, site-isolation-config, updates, admin-domain-list, certificates`.
  - `stat` → `version{plesk_version, plesk_os, plesk_os_version, plesk_build, os_release, veid}`, `objects{clients, domains, active_domains, mail_boxes, mail_redirects, mail_groups, mail_responders, web_users, databases, ...}`, `other{cpu, uptime}`, `load_avg{l1,l5,l15}`, `mem/swap/diskspace`.
  - `updates` → `available_update, available_update_type, security_updates, last_installed_update, install_updates_automatically, ...`.
- **`session` operator** (Plesk 12+, admin only): `<session><get/>` → `result/session*{id, type, ip-address, login, login-time, idle}`; `<session><terminate><session-id>X</session-id></terminate></session>` → ok, or 1013 "Session does not exist". There is no single-session read.
- **`server/get admin`** → `result/admin{admin_cname, admin_pname, admin_phone, admin_fax, admin_email, admin_address, admin_city, admin_state, admin_pcode, admin_country, admin_locale, admin_multiple_sessions}`. Settable via `server/set admin{...}` (not exposed in plead yet).
- **Service lifecycle**: status via `server/get services_state` → `result/services_state/srv{id, title, state, error}` (state: `running|stopped|none`; ids are real service ids, e.g. `web, smtp, imap-pop3, dns, spamassassin, milter, plesk-php83-fpm, ...` — there is no generic `mail`). Mutations via `<server><srv_man><id>X</id><operation>start|stop|restart</operation></srv_man></server>`.
- **`ip` operator** (`ip_input.xsd`): `<ip><get/>` → `result/addresses/ip_info{ip_address, netmask, type, interface, public_ip_address}`; `<ip><add><ip_address>..</ip_address><netmask>..</netmask><type>shared|exclusive</type><interface>..</interface>`; `<ip><set><filter><ip_address>..</ip_address></filter><type>..</type><public_ip_address>..</public_ip_address>`;
  `<ip><del><filter><ip_address>..</ip_address></filter>`. No validated single-IP read — `get` filters the list client-side.
- **Components**: installed list = `<server><get><components/>` → `result/components/component{name, version}` (no ids). **The 1.6.9.1 schema has NO `updater` operator** (input and output); the docs' `<updater><install-component><update-id>..</update-id><component-id>..</component-id>` is unvalidated and `uninstall-component` appears nowhere — treat both as experiments until a live probe says
  otherwise.
- **`site` operator** (the "domains" surface): `<site><add><gen_setup><name>..</name><htype>vrt_hst|std_fwd|frm_fwd|none</htype><webspace-name|webspace-id|webspace-guid>..</gen_setup><hosting><vrt_hst><property><name>..</name><value>..</value>` → `result{id, guid}`. `<site><del><filter><name>..</name></filter>` → `result{id}`.
  `<site><get_traffic><filter><name>..</name></filter><since_date>..</since_date><to_date>..</to_date>` → `result/traffic{date, http_in, http_out, ftp_in, ftp_out, smtp_in, smtp_out, pop3_imap_in, pop3_imap_out}` (validated live). `<site><set_traffic><dom_id>N</dom_id><date>..</date><smtp_in>..</smtp_in>...` (dom_id = numeric site id; resolve via webspace enumeration).
  `<site><get-physical-hosting-descriptor><filter><name>..</name></filter>` → `result/descriptor/property{name, type, default-value, label, ...}` (validated live).
- **`extension` operator**: `<extension><get/>` or with `<filter><id>..</id></filter>` → `result/details{id, name, version, release, active}` (validated live — the id filter is native, unlike ip/session). `<extension><install><id>..</id>` or `<url>..</url>`; `<extension><uninstall><id>..</id>` (schema-confirmed). **`<extension><call>`**: the extension id is the ELEMENT NAME under `<call>`, the
  operation is its child, the operation's parameters its grandchildren — `<call><git><remove><domain>..</domain><name>..</name></remove></git></call>` (validated live; docs "Calling Extensions Operations").
- **Operation names are per-extension contracts, NOT derived from the `plesk ext <id> --flag` CLI surface.** The CLI goes through a different gateway: e.g. `plesk ext git --info` works while `<git><info>` fails with "The command \"info\" was not found" — Git Manager's XML API registers only `remove` (matches the docs' single sample). To enumerate an extension's XML API ops: read its package
  manifest on the server, `/usr/local/psa/var/modules/<extension-id>/meta.xml` (the API explorer has been deprecated).
- **The XML API surface is implemented via the extension's `ApiRpc` hook** (proven by `monitoring` answering "Hook ApiRpc is not implemented in monitoring.."). An extension without that hook has NO XML API at all; with it, the operation names live in the (often IonCube-encrypted) hook implementation. Enumeration options on a root shell: `grep -rn ApiRpc /usr/local/psa/admin/plib/modules/<id>/`,
  grep the Plesk core for the hook contract (`grep -rn ApiRpc /usr/local/psa/admin/plib/`), and reflection on the loaded hook class (ionCube classes are runtime-reflectable — dump public methods; if dispatch is method-based they ARE the operations).
- **Pitfall: `pm_ApiRpc::getService()->call($xml, $login)` (seen in extension vendor SDKs) is the CLIENT** — how extensions internally call the Plesk API. It is NOT the server-side hook. Also: IonCube-encoded PHP contains NO greppable strings, so `grep` finds nothing in the core dispatcher or encoded extension hooks — runtime reflection (via Plesk's PHP with the ioncube loader, e.g.
  `/usr/local/psa/bin/php`) or behavior probing are the only enumeration tools for encoded extensions.
  - **Per-extension XML API sections exist in the docs** under "Managing Plesk Extensions" (e.g. "Managing Git Repositories"): git's operations are `get, create, update, remove, deploy, fetch` (NOT the CLI names like `info`). The docs are the canonical operation source; the CLI (`plesk ext <id> --flag`) is a different surface.
- **REST API CLI-gate** (`PleskRestGateway`, `https://<host>:8443/api/v2`): auth via **`X-API-Key: <secret>`** header (or Basic admin:key); OpenAPI 3.0.3 spec at `/api/v2/openapi.json` (live-verified). `GET /cli/commands` lists 80+ callable ids; `GET /cli/{id}/ref` shows a command's allowed commands/options.
  `POST /cli/{id}/call` with `{"params": [...], "fail_on_error": true}` runs `plesk <id> ...` server-side and returns `{code, stdout, stderr}` (HTTP 422 when fail_on_error and a non-zero exit).
  **Extension CLIs go through the `extension` id + `--call <name> <command> [<options>]`** — verified live: `--call sslit --help` returns sslit's full CLI (certificate/hsts/etc.).
  This covers extensions that implement only ApiCli (e.g. `sslit`); extensions with NEITHER hook (e.g. `letsencrypt`) have no remote API surface at all — sslit is the automation path for LE. Extensions are deliberately NOT catalogued (e.g. warden isn't even in the Plesk store).

-- Every row is scoped to the Plesk server it belongs to ('server' = servers[].host).
-- Pre-production: the schema is the source of truth; existing databases are
-- thrown away rather than migrated.

CREATE TABLE IF NOT EXISTS auto_replies (
  server TEXT NOT NULL,
  email TEXT NOT NULL,
  message TEXT NOT NULL,          -- rendered template output, stored verbatim — not a file path
  start_date TEXT NOT NULL,       -- ISO 8601 with UTC offset, e.g. 2026-08-19T08:00:00+02:00
  end_date TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'scheduled',  -- 'scheduled' | 'disabled'; rows are never deleted (audit trail)
  reconciled INTEGER NOT NULL DEFAULT 0,     -- 0 = intent recorded but not yet confirmed on Plesk
  reconciled_at TEXT,             -- when the desired state was last confirmed on Plesk
  updated_at TEXT NOT NULL,
  PRIMARY KEY (server, email)
);

CREATE TABLE IF NOT EXISTS mail_recipients (
  server TEXT NOT NULL,
  list_email TEXT NOT NULL,
  recipient_email TEXT NOT NULL,
  removed_at TEXT,                -- soft-delete; preserves history for the audit trail
  reconciled INTEGER NOT NULL DEFAULT 0,     -- 0 = intent recorded but not yet confirmed on Plesk
  reconciled_at TEXT,
  updated_at TEXT NOT NULL,
  PRIMARY KEY (server, list_email, recipient_email)
);

CREATE TABLE IF NOT EXISTS mail_aliases (
  server TEXT NOT NULL,
  email TEXT NOT NULL,            -- the mailbox whose alias this is
  alias_email TEXT NOT NULL,      -- additional address delivering into that mailbox
  removed_at TEXT,                -- soft-delete; preserves history for the audit trail
  reconciled INTEGER NOT NULL DEFAULT 0,     -- 0 = intent recorded but not yet confirmed on Plesk
  reconciled_at TEXT,
  updated_at TEXT NOT NULL,
  PRIMARY KEY (server, email, alias_email)
);

CREATE TABLE IF NOT EXISTS sync_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  server TEXT NOT NULL,
  resource_type TEXT NOT NULL,    -- 'auto_reply' | 'mail_group' | 'mail_address' | 'mail_alias' | 'domain' | 'server_session' | 'server_service'
  resource_id TEXT NOT NULL,
  action TEXT NOT NULL,
  result TEXT NOT NULL,           -- 'pending' | 'ok' | 'dry-run' | 'error:<message>'
  details TEXT,                   -- JSON with the values involved, e.g. {"from": "a@b", "to": "c@b"} for renames
  occurred_at TEXT NOT NULL
);

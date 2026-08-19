CREATE TABLE IF NOT EXISTS auto_replies (
  email TEXT PRIMARY KEY,
  message TEXT NOT NULL,          -- rendered template output, stored verbatim — not a file path
  start_date TEXT NOT NULL,       -- ISO 8601 with UTC offset, e.g. 2026-08-19T08:00:00+02:00
  end_date TEXT NOT NULL,
  applied_at TEXT,                -- NULL until the reconciler confirms the RPC call succeeded
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mail_recipients (
  list_email TEXT NOT NULL,
  recipient_email TEXT NOT NULL,
  removed_at TEXT,                -- soft-delete; preserves history for the audit trail
  updated_at TEXT NOT NULL,
  PRIMARY KEY (list_email, recipient_email)
);

CREATE TABLE IF NOT EXISTS sync_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  resource_type TEXT NOT NULL,    -- 'auto_reply' | 'mail_group'
  resource_id TEXT NOT NULL,
  action TEXT NOT NULL,
  result TEXT NOT NULL,           -- 'ok' | 'dry-run' | 'error:<message>'
  occurred_at TEXT NOT NULL
);

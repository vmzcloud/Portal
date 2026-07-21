PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY NOT NULL,
    value TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    location TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#4fc3f7',
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    all_day INTEGER NOT NULL DEFAULT 0 CHECK(all_day IN (0, 1)),
    period TEXT NOT NULL DEFAULT 'none' CHECK(period IN ('none', 'am', 'pm')),
    visibility TEXT NOT NULL DEFAULT 'public' CHECK(visibility IN ('public', 'share', 'private')),
    owner_id INTEGER,
    ics_uid TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS event_persons (
    event_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    PRIMARY KEY (event_id, user_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS event_groups (
    event_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    PRIMARY KEY (event_id, group_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_events_starts ON events(starts_at);
CREATE INDEX IF NOT EXISTS idx_events_ends ON events(ends_at);
CREATE INDEX IF NOT EXISTS idx_events_visibility ON events(visibility);
CREATE INDEX IF NOT EXISTS idx_events_owner ON events(owner_id);
CREATE INDEX IF NOT EXISTS idx_events_ics_uid ON events(ics_uid);

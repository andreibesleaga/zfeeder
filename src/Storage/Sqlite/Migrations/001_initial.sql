-- zFeeder 2.0 initial schema.
-- Mirrors the flat-file layout one to one: a category is a row plus its feeds,
-- a cache entry is a body with the metadata a conditional GET needs.

CREATE TABLE categories (
    name          TEXT PRIMARY KEY,
    date_modified INTEGER,
    owner_name    TEXT,
    owner_email   TEXT
);

CREATE TABLE feeds (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    category        TEXT NOT NULL REFERENCES categories(name) ON DELETE CASCADE,
    position        INTEGER,
    title           TEXT,
    xml_url         TEXT NOT NULL,
    html_url        TEXT,
    description     TEXT,
    language        TEXT,
    refresh_minutes INTEGER,
    showed_items    INTEGER,
    subscribed      INTEGER
);

CREATE INDEX feeds_category_position ON feeds (category, position);

CREATE TABLE cache (
    url           TEXT PRIMARY KEY,
    body          BLOB,
    fetched_at    INTEGER,
    etag          TEXT,
    last_modified TEXT,
    status        INTEGER,
    error         TEXT
);

CREATE INDEX cache_fetched_at ON cache (fetched_at);

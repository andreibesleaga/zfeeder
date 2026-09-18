# data-dist — shipped defaults

These files are copied into the live data directory the first time zFeeder starts
(see `deploy/entrypoint.sh`), and are never read again afterwards. Edit the copies
in the data directory, not these.

- `categories/*.opml` — subscription lists to start from. `zfeeder` is the default
  category; `empty` exists so a new installation has somewhere clean to work.
- `config.json.dist` — the starting configuration. Every value here can also be set
  by an environment variable, which always wins; see `docs/CONFIGURATION.md`.

The subscriptions point at public feeds from the BBC, The Guardian, The New York Times,
Wired, Ars Technica, LWN, Phoronix, Hacker News, Linux.com and SourceForge. They are
there so a fresh install shows something real. Check each publisher's terms before you
redisplay their content on a public site — the same note the 2004 manual carried.

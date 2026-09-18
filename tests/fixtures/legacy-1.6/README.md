# The 2004 corpus

These files are from zFeeder 1.6, released April 2004. They are byte-identical to
the originals, timestamps and all, and are not edited when a test runs.

They are here because five test classes assert against real 2004 files rather than
against files written to suit the tests:

| Test | What it proves |
|---|---|
| `Unit\Subscription\OpmlReaderTest` | every 2004 `.opml` file still parses |
| `Unit\Subscription\OpmlWriterTest` | and survives a read/write round trip |
| `Unit\Storage\FlatSubscriptionStoreTest` | the flat backend loads one directly |
| `Support\SubscriptionStoreContractTestCase` | both backends agree on them |
| `Cli\LegacyImportCommandTest` | `bin/zfeeder legacy-import` imports a 2004 installation |

Only what those tests read is kept:

- `newsfeeds/categories/*.opml` — the eleven original subscription files;
- `newsfeeds/templates/` — the 2004 templates, so the importer can be shown
  recognising them as the shipped set and declining to copy them;
- `newsfeeds/config.php`.

**`config.php` is never executed.** It is read as text and scanned for `define()`
calls — see the note in `src/Cli/Command/LegacyImportCommand.php`. It is the only
PHP file here, and no 2004 PHP is loaded by this project, here or anywhere else.

The complete 1.6 release — the application code, templates, images and demos — is
archived at <https://github.com/andreibesleaga/old-projects> as `zfeeder-1.6.zip`,
together with the original screenshots. It was removed from this repository in
September 2026: it was 14 MB that nothing loaded, and publishing the 2004 source in
a repository people install from invited someone to deploy it.

`tools/record-goldens.sh` fetches that archive when the golden corpus has to be
re-recorded, which is the one task that still needs the whole tree.

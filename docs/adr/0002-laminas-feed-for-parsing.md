# 0002. laminas-feed for RSS and Atom, an own reader for JSON Feed

## Status

Accepted.

## Context

1.6 parsed feeds with `xml_parser_create()` and global callbacks, handled RSS 0.9 to 2.0 and nothing else,
and had no entity protection. The rebuild has to read those same 2004 documents — the fixtures under
`tests/fixtures/feeds-2004/` are the acceptance criterion — plus Atom 1.0 and JSON Feed. Feed documents are
adversarial input: they arrive from whatever the administrator subscribed to.

A second constraint comes from the golden tests (ADR 0011). Classic templates print `{pubdate}` exactly as
the feed wrote it, and a normalising reader parses that string into a date object, after which the original
bytes are gone.

## Decision

`laminas/laminas-feed` (`^2.23`) detects the format and validates the XML. `src/Parse/FeedParser.php` then
maps the *same DOM* onto zFeeder's own model by hand, which is what makes `Item::$rawDate` possible. Its
docblock states the two governing principles: lenient about content, strict about structure; and read the
document twice.

laminas-feed does not cover JSON Feed, so `src/Parse/JsonFeedReader.php` reads 1.0 and 1.1 directly into the
same objects. Dates from every format go through `src/Parse/DateParser.php`, which tries fifteen formats
strictest-first, each with a leading `!` so an unspecified field resets to the epoch instead of picking up
the current time. An unreadable date becomes `null` rather than an exception.

The model is `src/Parse/Model/Channel.php`, `Item.php` and `Enclosure.php`: `final readonly`, no
dependencies.

## Consequences

- Two libxml defences live in `FeedParser::assertSafeXml()` and `importXml()`: document type declarations are
  rejected before parsing, and `libxml_set_external_entity_loader()` returns null for every reference. The
  exception is an allow-list of four subset-free RSS 0.91 identifiers (`HISTORIC_DOCTYPE_IDS`), because half
  the 2004 corpus opens with one; `$allowHistoricDoctype` turns even those off.
- `Item::$summaryHtml` and `$contentHtml` stay unsanitised here; policy belongs to the render layer (ADR 0007).
- Not finished: the goldens do not yet exercise this parser. `tests/Golden/ClassicTemplateGoldenTest.php`
  builds its channels through `tests/Support/FixtureSubscriptions.php`, which defaults to
  `LegacyFixtureFeedParser` — a stand-in whose docblock carries the TODO to delete it and switch to
  `FeedParser`. Until then the goldens prove the renderer, not the parser.

## Alternatives considered

- **SimplePie.** Mature and lenient, but it owns caching and sanitising too, and both are decided elsewhere
  here (ADRs 0004 and 0007).
- **An own XMLReader parser.** Maximum fidelity and no dependency, at the cost of maintaining the format
  detection and XML hardening laminas-feed already has. Kept as the fallback if a 2004 fixture ever fails.

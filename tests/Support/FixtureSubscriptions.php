<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Support;

use Zfeeder\Render\RenderedFeed;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * Loads the OPML files and feed bodies that the golden recorder fed to zFeeder
 * 1.6, and turns them into the objects the renderer expects.
 *
 * TODO: switch the OPML reading to `Zfeeder\Opml\*` and the feed parsing to
 * `Zfeeder\Parse\FeedParser` once those land; see LegacyFixtureFeedParser.
 */
final class FixtureSubscriptions
{
    /**
     * The mtime the recorder stamped on every cached feed file, and therefore
     * the value behind every `{lastupdated}` in the golden corpus.
     *
     * `tools/record-goldens.sh` runs `touch -t 203001010000` on the cache
     * directory. `touch` interprets that in the *host's* timezone, which was
     * UTC+2, so the instant stored is 2029-12-31 22:00:00 UTC and 1.6's
     * `gmdate()` printed "Mon, 31 Dec 2029 22:00:00 GMT". Hard-coding the
     * instant keeps the test independent of the machine's clock and timezone;
     * {@see \Zfeeder\Tests\Golden\ClassicTemplateGoldenTest::testEveryGoldenUsesTheRecordedCacheTimestamp}
     * re-derives it from the corpus so the constant cannot silently rot.
     */
    public const string CACHE_FETCHED_AT = '2029-12-31 22:00:00 UTC';

    /** Where the recorder's fixture URLs map to on disk. */
    private const array FEED_DIRS = ['feeds-2004', 'feeds-2026'];

    public function __construct(
        private readonly string $fixturesDir,
        private readonly LegacyFixtureFeedParser $parser = new LegacyFixtureFeedParser(),
    ) {
    }

    public function category(string $name): Category
    {
        $path = $this->fixturesDir . '/opml/' . $name . '.opml';
        $xml = file_get_contents($path);
        if ($xml === false) {
            throw new \RuntimeException('Missing OPML fixture: ' . $path);
        }
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($doc === false) {
            throw new \RuntimeException('Unparsable OPML fixture: ' . $path);
        }

        $feeds = [];
        foreach ($doc->body->outline ?? [] as $outline) {
            $feeds[] = new Feed(
                xmlUrl: (string) ($outline['xmlUrl'] ?? ''),
                title: (string) ($outline['title'] ?? ''),
                description: (string) ($outline['description'] ?? ''),
                htmlUrl: (string) ($outline['htmlUrl'] ?? ''),
                position: (int) ($outline['position'] ?? 0),
                refreshMinutes: (int) ($outline['refreshTime'] ?? 60),
                showedItems: (int) ($outline['showedItems'] ?? 0),
                subscribed: ((string) ($outline['isSubscribed'] ?? 'no')) === 'yes',
                language: (string) ($outline['language'] ?? ''),
            );
        }

        // The recorded fixtures are called goldenA/B/C, but
        // Category::NAME_PATTERN only allows lower case, so the model object
        // gets the lower-cased name. The mixed-case spelling is what 1.6 put in
        // `{category}` and in the zfcategory link parameter, and the golden test
        // passes it through RenderRequest::$category, which is where 1.6 read it
        // from too (`$_GET['zfcategory']`).
        return new Category(strtolower($name), $feeds);
    }

    /**
     * Every feed of the category, with its fixture body parsed and a cache
     * entry stamped with the recorder's mtime.
     *
     * @return list<RenderedFeed>
     */
    public function renderedFeeds(Category $category): array
    {
        $fetchedAt = new \DateTimeImmutable(self::CACHE_FETCHED_AT);
        $out = [];
        foreach ($category->renderableFeeds() as $feed) {
            $path = $this->feedFixture($feed->xmlUrl);
            $body = file_get_contents($path);
            if ($body === false) {
                throw new \RuntimeException('Missing feed fixture: ' . $path);
            }
            $out[] = new RenderedFeed(
                $feed,
                $this->parser->parse($body),
                new CacheEntry($feed->xmlUrl, $body, $fetchedAt),
            );
        }

        return $out;
    }

    private function feedFixture(string $url): string
    {
        $basename = basename((string) parse_url($url, PHP_URL_PATH));
        foreach (self::FEED_DIRS as $dir) {
            $path = $this->fixturesDir . '/' . $dir . '/' . $basename;
            if (is_file($path)) {
                return $path;
            }
        }

        throw new \RuntimeException('No fixture body for feed URL: ' . $url);
    }
}

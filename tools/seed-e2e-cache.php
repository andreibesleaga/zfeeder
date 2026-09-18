#!/usr/bin/env php
<?php
/**
 * Fills a data directory's cache with fixture feed bodies, so the browser suite
 * renders known content and never depends on a live publisher.
 *
 * The mapping below pairs each subscription in data-dist/categories with a local
 * fixture of a similar shape. Usage: php tools/seed-e2e-cache.php <data-dir>
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Zfeeder\Storage\CacheEntry;
use Zfeeder\Storage\Flat\FileCacheStore;

$dataDir = $argv[1] ?? null;
if ($dataDir === null || !is_dir($dataDir)) {
    fwrite(STDERR, "usage: seed-e2e-cache.php <data-dir>\n");
    exit(1);
}

$root = dirname(__DIR__);
$fixtures = [
    'https://feeds.bbci.co.uk/news/world/rss.xml' => 'feeds-2026/rss20-content-encoded.xml',
    'https://feeds.bbci.co.uk/news/technology/rss.xml' => 'feeds-2026/rss20-content-encoded.xml',
    'https://feeds.bbci.co.uk/news/science_and_environment/rss.xml' => 'feeds-2026/rss20-science.xml',
    'https://rss.nytimes.com/services/xml/rss/nyt/Technology.xml' => 'feeds-2026/rss20-science.xml',
    'https://www.theguardian.com/world/rss' => 'feeds-2026/rss20-content-encoded.xml',
    'https://lwn.net/headlines/rss' => 'feeds-2004/rdf10-devchannel.xml',
    'https://www.phoronix.com/rss.php' => 'feeds-2004/rss20-zfeeder.xml',
    'https://hnrss.org/frontpage' => 'feeds-2026/atom10-modern.xml',
    'https://www.wired.com/feed/rss' => 'feeds-2026/rss20-content-encoded.xml',
    'https://feeds.arstechnica.com/arstechnica/index' => 'feeds-2026/rss20-science.xml',
    'https://sourceforge.net/p/forge/documentation/feed.rss' => 'feeds-2004/rss20-zfeeder.xml',
    'https://www.linux.com/feed/' => 'feeds-2004/rss092-weblog.xml',
];

$store = new FileCacheStore($dataDir . '/cache');
// A fixed, recent timestamp: recent enough that nothing looks expired during the
// run, fixed so that rendered dates are identical on every run.
$fetchedAt = new DateTimeImmutable('2026-09-18T06:00:00+00:00');

$count = 0;
foreach ($fixtures as $url => $relative) {
    $path = $root . '/tests/fixtures/' . $relative;
    $body = file_get_contents($path);
    if ($body === false) {
        fwrite(STDERR, "missing fixture: {$path}\n");
        exit(1);
    }
    $store->put(new CacheEntry($url, $body, $fetchedAt, null, null, 200, ''));
    $count++;
}

echo "seeded {$count} cache entries in {$dataDir}/cache\n";

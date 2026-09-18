<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Http;

use Zfeeder\Render\RenderRequest;
use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * A feed is content from a third party, and the third party may be hostile.
 *
 * zFeeder 1.6 wrote channel titles, descriptions and item bodies into the page
 * exactly as the publisher sent them, which is the defect these cases exist to
 * keep closed. Only the `classic` set rendered in legacy fidelity reproduces
 * the 2004 behaviour, and that mode is reachable only from the golden tests.
 */
final class HostileFeedTest extends PublicSurfaceTestCase
{
    private const string HOSTILE = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
          <title>Pwn&lt;/a&gt;&lt;script&gt;alert('chantitle')&lt;/script&gt;</title>
          <link>javascript:alert('chanlink')</link>
          <description>&lt;img src=x onerror=alert('chandesc')&gt;</description>
          <image>
            <url>javascript:alert('logourl')</url>
            <title>&lt;script&gt;alert('logotitle')&lt;/script&gt;</title>
            <link>javascript:alert('logolink')</link>
          </image>
          <item>
            <title>Head&lt;/a&gt;&lt;script&gt;alert('title')&lt;/script&gt;</title>
            <link>javascript:alert('link')</link>
            <description>&lt;script&gt;alert('description')&lt;/script&gt;&lt;img src=x onerror=alert(2)&gt;</description>
            <pubDate>&lt;script&gt;alert('pubdate')&lt;/script&gt;</pubDate>
          </item>
        </channel></rss>
        XML;

    /** The same feed with nothing hostile in it, as the control. */
    private const string BENIGN = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
          <title>Ordinary news</title>
          <link>https://ordinary.example/</link>
          <description>Nothing unusual here</description>
          <item>
            <title>An ordinary headline</title>
            <link>https://ordinary.example/1</link>
            <description>An ordinary paragraph of text.</description>
            <pubDate>Mon, 14 Sep 2026 12:00:00 GMT</pubDate>
          </item>
        </channel></rss>
        XML;

    protected function seed(): void
    {
        $store = $this->kernel->subscriptions();
        if (!$store->has(self::CATEGORY)) {
            $store->createCategory(self::CATEGORY);
        }
        $this->kernel->cache()->put(new CacheEntry(
            'https://hostile.example/feed.xml',
            self::HOSTILE,
            new \DateTimeImmutable(self::NOW),
        ));
        $store->saveCategory(new Category(self::CATEGORY, [
            new Feed(
                xmlUrl: 'https://hostile.example/feed.xml',
                title: 'Hostile',
                position: 1,
                refreshMinutes: 10_000,
                showedItems: 3,
            ),
        ]));
    }

    public function testNoTemplateInEitherSetRendersMarkupFromAHostileFeed(): void
    {
        $checked = 0;

        foreach ($this->kernel->templateLocator()->available() as $set => $names) {
            foreach ($names as $name) {
                $where = $set . '/' . $name;
                $hostile = $this->renderWith(self::HOSTILE, $where);
                $benign = $this->renderWith(self::BENIGN, $where);

                // A template may legitimately ship its own script — the 2004
                // infojunkie folder does — so what matters is that the hostile
                // feed adds nothing the benign one did not already produce.
                self::assertSame(
                    $this->executableProfile($benign, $where),
                    $this->executableProfile($hostile, $where),
                    $where . ' gained executable markup when the feed turned hostile',
                );
                $this->assertNoDangerousUrls($hostile, $where);
                $checked++;
            }
        }

        self::assertGreaterThan(25, $checked, 'every shipped template must be covered');
    }

    /** Re-renders the category with a different feed body in the cache. */
    private function renderWith(string $feedXml, string $template): string
    {
        $this->kernel->cache()->put(new CacheEntry(
            'https://hostile.example/feed.xml',
            $feedXml,
            new \DateTimeImmutable(self::NOW),
        ));

        return $this->kernel->feeds()->render(new RenderRequest(
            category: self::CATEGORY,
            template: $template,
            selfUrl: '/embed',
        ));
    }

    /**
     * How many executable things the document contains, by kind.
     *
     * @return array<string, int>
     */
    private function executableProfile(string $html, string $where): array
    {
        $xpath = $this->parse($html, $where);

        $count = static function (\DOMXPath $x, string $query): int {
            $nodes = $x->query($query);

            return $nodes === false ? -1 : $nodes->count();
        };

        return [
            'script' => $count($xpath, '//script'),
            'frames' => $count($xpath, '//iframe | //object | //embed'),
            'forms' => $count($xpath, '//form | //input | //button'),
            'handlers' => $count(
                $xpath,
                '//@*[starts-with(translate(name(), "ONERABCDFGHIJKLMPQSTUVWXYZ", "onerabcdfghijklmpqstuvwxyz"), "on")]',
            ),
        ];
    }

    /**
     * Parses the fragment the way a browser would.
     *
     * A string search cannot tell `onerror=` inside an escaped attribute value,
     * which is inert, from `onerror=` on a real element, which is not. Parsing
     * removes that ambiguity.
     */
    private function parse(string $html, string $where): \DOMXPath
    {
        $document = new \DOMDocument();
        $loaded = @$document->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        self::assertTrue($loaded, $where . ' produced markup that will not parse');

        return new \DOMXPath($document);
    }

    /** No link or resource in the document may use a scheme a browser executes. */
    private function assertNoDangerousUrls(string $html, string $where): void
    {
        $xpath = $this->parse($html, $where);

        foreach (['href', 'src'] as $attribute) {
            $nodes = $xpath->query('//@' . $attribute);
            self::assertNotFalse($nodes);
            foreach ($nodes as $node) {
                $value = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $node->nodeValue ?? ''));
                foreach (['javascript:', 'data:text/html', 'vbscript:'] as $scheme) {
                    self::assertStringNotContainsString(
                        $scheme,
                        $value,
                        sprintf('%s rendered %s="%s"', $where, $attribute, $value),
                    );
                }
            }
        }
    }

    public function testTheEscapedFormIsStillVisibleToTheReader(): void
    {
        $html = $this->kernel->feeds()->render(new RenderRequest(
            category: self::CATEGORY,
            template: 'modern/list',
            selfUrl: '/embed',
        ));

        // Neutralised, not silently deleted: the reader still sees the text.
        self::assertStringContainsString('Pwn', $html);
        self::assertStringContainsString('&lt;', $html);
    }

    public function testTheJsonApiDoesNotExecuteEither(): void
    {
        $payload = self::jsonOf($this->get('/api/feeds'));
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        // JSON is data, so the markup may appear, but it must be encoded such
        // that a browser parsing the response as JSON cannot run it.
        self::assertStringNotContainsString('</script>', $encoded);
    }

    public function testAFilteredTokenIsNeutralisedOnItsOwnPath(): void
    {
        // `{chantitle|trunc:20}` goes through the 2.0 filter pass rather than
        // the 1.6 substitution, so it needs proving separately.
        $html = $this->kernel->feeds()->render(new RenderRequest(
            category: self::CATEGORY,
            template: 'modern/cards',
            selfUrl: '/embed',
        ));

        $this->assertNoDangerousUrls($html, 'modern/cards');
        self::assertSame(
            ['script' => 0, 'frames' => 0, 'forms' => 0, 'handlers' => 0],
            $this->executableProfile($html, 'modern/cards'),
        );
    }

    public function testAHostileLinkIsDroppedRatherThanEscapedIntoAWorkingOne(): void
    {
        // Escaping a `javascript:` URL leaves a `javascript:` URL, so the
        // scheme has to be refused outright.
        $html = $this->kernel->feeds()->render(new RenderRequest(
            category: self::CATEGORY,
            template: 'modern/list',
            selfUrl: '/embed',
        ));

        self::assertStringNotContainsString('javascript:', $html);
    }
}

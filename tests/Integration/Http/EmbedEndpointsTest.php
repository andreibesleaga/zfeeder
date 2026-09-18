<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Http;

use Zfeeder\Embed\Embed;
use Zfeeder\Render\RenderRequest;

/** The three ways a site can pull feeds out of zFeeder, plus the operational endpoints. */
final class EmbedEndpointsTest extends PublicSurfaceTestCase
{
    public function testEmbedReturnsAFragmentRatherThanAWholeDocument(): void
    {
        $response = $this->get('/embed');
        $body = self::bodyOf($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('<!doctype', strtolower($body));
        self::assertStringNotContainsString('<html', strtolower($body));
        self::assertStringContainsString('Tech Wire', $body);
    }

    public function testEmbedAcceptsBothTheModernAndThe2004ParameterNames(): void
    {
        $modern = self::bodyOf($this->get('/embed?category=' . self::CATEGORY . '&template=modern/list'));
        $legacy = self::bodyOf($this->get('/embed?zfcategory=' . self::CATEGORY . '&zftemplate=modern/list'));

        self::assertSame($modern, $legacy, 'the 1.6 parameter names must still work');
    }

    public function testThePoweredByLineCanBeTurnedOffPerRequestAsIn2004(): void
    {
        self::assertStringContainsString('powered by', self::bodyOf($this->get('/embed')));
        self::assertStringNotContainsString('powered by', self::bodyOf($this->get('/embed?zf_link=off')));
    }

    public function testEmbedRestrictsToTheRequestedPositions(): void
    {
        $everything = self::bodyOf($this->get('/embed'));
        $firstOnly = self::bodyOf($this->get('/embed?position=p1'));

        self::assertStringContainsString('Tech Wire', $everything);
        self::assertStringContainsString('Science Desk', $everything);
        self::assertStringNotContainsString('Science Desk', $firstOnly);
    }

    public function testTheJsonApiReturnsTheParsedFeeds(): void
    {
        $response = $this->get('/api/feeds');
        $payload = self::jsonOf($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::CATEGORY, $payload['category']);
        self::assertStringStartsWith('zFeeder', (string) $payload['generator']);
        self::assertCount(2, $payload['channels']);

        $first = $payload['channels'][0];
        self::assertSame('Tech Wire', $first['title']);
        self::assertSame('rss-2.0', $first['format']);
        self::assertCount(3, $first['items'], 'showedItems must bound the item list');
        self::assertArrayHasKey('published', $first['items'][0]);
    }

    public function testTheJsonApiLeaksNoConfiguration(): void
    {
        $body = self::bodyOf($this->get('/api/feeds'));

        foreach (['password', 'hash', 'data_dir', $this->tempDir()] as $secret) {
            self::assertStringNotContainsStringIgnoringCase($secret, $body);
        }
    }

    public function testTheJsonApiCanBeTurnedOff(): void
    {
        $this->bootKernel(['api_enabled' => false]);
        $this->seed();

        self::assertSame(404, $this->get('/api/feeds')->getStatusCode());
    }

    public function testOpmlExportIsPrivateUnlessItIsTurnedOn(): void
    {
        self::assertSame(404, $this->get('/api/opml/' . self::CATEGORY)->getStatusCode());

        $this->bootKernel(['opml_export_public' => true]);
        $this->seed();

        $response = $this->get('/api/opml/' . self::CATEGORY);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('opml', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('<opml', self::bodyOf($response));
    }

    public function testOpmlExportOfAnUnknownCategoryIsNotFound(): void
    {
        $this->bootKernel(['opml_export_public' => true]);
        $this->seed();

        self::assertSame(404, $this->get('/api/opml/nope')->getStatusCode());
        self::assertSame(404, $this->get('/api/opml/..%2F..%2Fetc')->getStatusCode());
    }

    public function testHealthReportsTheVersionAndBackend(): void
    {
        $payload = self::jsonOf($this->get('/healthz'));

        self::assertSame('ok', $payload['status']);
        self::assertSame(\Zfeeder\Version::NUMBER, $payload['version']);
        self::assertSame('flat', $payload['storage']);
        self::assertTrue($payload['checks']['data_writable']);
        self::assertTrue($payload['checks']['subscriptions']);
    }

    public function testRefreshingOverHttpNeedsTheKey(): void
    {
        self::assertSame(403, $this->get('/refresh')->getStatusCode());
        self::assertSame(403, $this->get('/refresh?key=guess')->getStatusCode());
    }

    public function testRefreshingWithTheKeyPrintsThe2004Report(): void
    {
        $this->bootKernel(['refresh_key' => 'a-shared-secret']);
        $this->seed();

        $response = $this->get('/refresh?key=a-shared-secret');
        $body = self::bodyOf($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('zFeeder feed refreshing', $body);
        // The cache was just written and the interval is long, so 1.6's exact
        // wording for a feed that needs no work is what must come back.
        self::assertStringContainsString('not expired yet', $body);
        self::assertStringContainsString('2 feeds, 0 failed', $body);
    }

    public function testTheIncludeFunctionProducesTheSameHtmlAsTheEndpoint(): void
    {
        Embed::setKernel($this->kernel);
        try {
            $viaFunction = zfeeder(['category' => self::CATEGORY, 'template' => 'modern/list', 'self_url' => '/embed']);
            $viaEndpoint = self::bodyOf($this->get('/embed?category=' . self::CATEGORY . '&template=modern/list'));

            self::assertSame($viaEndpoint, $viaFunction);
        } finally {
            Embed::setKernel(null);
        }
    }

    public function testTheChannelHelperReturnsParsedFeeds(): void
    {
        Embed::setKernel($this->kernel);
        try {
            $channels = zfeeder_feeds(['category' => self::CATEGORY]);

            self::assertCount(2, $channels);
            self::assertSame('Tech Wire', $channels[0]->title);
        } finally {
            Embed::setKernel(null);
        }
    }

    public function testAnEmbeddedBlockNeverBreaksThePageThatHostsIt(): void
    {
        Embed::setKernel($this->kernel);
        try {
            // A template that does not exist would throw inside the renderer.
            $html = zfeeder(['category' => self::CATEGORY, 'template' => 'modern/does-not-exist']);

            self::assertStringContainsString('zFeeder', $html);
            self::assertStringNotContainsString('Fatal', $html);
        } finally {
            Embed::setKernel(null);
        }
    }

    public function testTheServiceRendersTheSameWayTheEndpointDoes(): void
    {
        $direct = $this->kernel->feeds()->render(new RenderRequest(
            category: self::CATEGORY,
            template: 'modern/list',
            selfUrl: '/embed',
        ));

        self::assertSame(self::bodyOf($this->get('/embed?category=' . self::CATEGORY . '&template=modern/list')), $direct);
    }
}

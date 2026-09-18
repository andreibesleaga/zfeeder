<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;

/** The demonstration site: the front page, the six demos and the template gallery. */
final class DemoSiteTest extends PublicSurfaceTestCase
{
    public function testTheFrontPageIntroducesTheProductAndListsTheDemonstrations(): void
    {
        $response = $this->get('/');
        $body = self::bodyOf($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('zFeeder', $body);
        self::assertStringContainsString('One line in any page', $body);
        self::assertStringContainsString('The aggregator', $body);
        // Each demonstration names the 1.6 file it descends from.
        self::assertStringContainsString('demo_frames.php', $body);
    }

    public function testTheFrontPageListsTheCategoriesThatExist(): void
    {
        $body = self::bodyOf($this->get('/'));

        self::assertStringContainsString(self::CATEGORY, $body);
        self::assertStringContainsString('other', $body);
    }

    public function testTheTemplateSetCanBeSwitchedFromTheQueryString(): void
    {
        self::assertStringContainsString('switch to modern', self::bodyOf($this->get('/?set=classic')));
        self::assertStringContainsString('switch to classic', self::bodyOf($this->get('/?set=modern')));
    }

    public function testAnUnknownSetFallsBackToTheConfiguredOneRatherThanFailing(): void
    {
        $response = $this->get('/?set=nonsense');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('switch to classic', self::bodyOf($response));
    }

    #[DataProvider('demoSlugs')]
    public function testEachDemonstrationRendersRealFeedContent(string $slug): void
    {
        $response = $this->get('/demos/' . $slug);
        $body = self::bodyOf($response);

        self::assertSame(200, $response->getStatusCode(), $slug . ' should render');
        self::assertStringContainsString('Tech Wire', $body, $slug . ' should show the fixture channel');
        self::assertStringNotContainsString('{description', $body, $slug . ' left a token unexpanded');
        self::assertStringNotContainsString('{scripturl}', $body, $slug . ' left the asset base unexpanded');
    }

    /** @return iterable<string, array{string}> */
    public static function demoSlugs(): iterable
    {
        foreach (['one-line', 'css', 'multiple', 'positions', 'categories', 'aggregator'] as $slug) {
            yield $slug => [$slug];
        }
    }

    public function testAnUnknownDemonstrationIsNotFound(): void
    {
        $response = $this->get('/demos/there-is-no-such-thing');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Not found', self::bodyOf($response));
    }

    public function testTheCategoriesDemonstrationMarksTheCurrentCategory(): void
    {
        $body = self::bodyOf($this->get('/demos/categories?category=other'));

        self::assertStringContainsString('aria-current="page"', $body);
    }

    public function testEveryModernTemplateRendersThroughTheGallery(): void
    {
        foreach ($this->kernel->templateLocator()->available()['modern'] as $name) {
            $response = $this->get('/demos/template/modern/' . $name);
            self::assertSame(200, $response->getStatusCode(), 'modern/' . $name . ' should render');
            $body = self::bodyOf($response);
            self::assertStringNotContainsString('{scripturl}', $body, 'modern/' . $name . ' left {scripturl} literal');
            self::assertDoesNotMatchRegularExpression(
                '/\{[a-z_]+\|[^}]*\}/',
                $body,
                'modern/' . $name . ' left a filtered token unexpanded',
            );
        }
    }

    public function testEveryClassicTemplateRendersThroughTheGallery(): void
    {
        foreach ($this->kernel->templateLocator()->available()['classic'] as $name) {
            $response = $this->get('/demos/template/classic/' . $name);
            self::assertSame(200, $response->getStatusCode(), 'classic/' . $name . ' should render');
        }
    }

    public function testAnUnknownTemplateIsReportedRatherThanCrashing(): void
    {
        $response = $this->get('/demos/template/modern/not-a-template');

        // A name in the URL that does not resolve is the client's mistake.
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('templates/modern', self::bodyOf($response), 'a path leaked into the page');
    }

    public function testAnUnknownTemplateSetIsRefused(): void
    {
        $response = $this->get('/demos/template/elsewhere/cards');

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    public function testTheGalleryLinksToTheOtherTemplatesInTheSameSet(): void
    {
        $body = self::bodyOf($this->get('/demos/template/modern/cards'));

        self::assertStringContainsString('/demos/template/modern/list', $body);
        self::assertStringContainsString('aria-current="page"', $body);
    }
}

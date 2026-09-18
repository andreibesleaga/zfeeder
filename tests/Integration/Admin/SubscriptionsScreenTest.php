<?php

declare(strict_types=1);

namespace Zfeeder\Tests\Integration\Admin;

use Zfeeder\Storage\CacheEntry;
use Zfeeder\Subscription\Feed;

/**
 * The subscriptions table. Every assertion here ends at the store rather than
 * at the HTML: the screen is only interesting because of what it changes.
 */
final class SubscriptionsScreenTest extends AdminTestCase
{
    private const string FIRST = 'https://one.example/feed.xml';
    private const string SECOND = 'https://two.example/feed.xml';
    private const string THIRD = 'https://three.example/feed.xml';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        $this->withFeeds([
            new Feed(self::FIRST, 'One', 'The first feed', 'https://one.example/', 1, 60, 3),
            new Feed(self::SECOND, 'Two', 'The second feed', 'https://two.example/', 2, 60, 3),
            new Feed(self::THIRD, 'Three', 'The third feed', 'https://three.example/', 3, 60, 3),
        ]);
    }

    /** @return list<Feed> */
    private function stored(): array
    {
        $feeds = $this->kernel->subscriptions()->category(self::CATEGORY)->feeds;
        usort($feeds, static fn (Feed $a, Feed $b): int => $a->position <=> $b->position);

        return $feeds;
    }

    public function testTheTableListsEverySubscription(): void
    {
        $body = self::bodyOf($this->send('GET', '/admin/subscriptions'));

        self::assertStringContainsString('One', $body);
        self::assertStringContainsString('Two', $body);
        self::assertStringContainsString(self::THIRD, $body);
        self::assertStringContainsString('not fetched yet', $body);
    }

    public function testTheTableShowsWhatHappenedAtTheLastFetch(): void
    {
        $this->kernel->cache()->put(new CacheEntry(
            self::FIRST,
            '<rss/>',
            new \DateTimeImmutable('2026-09-18T11:00:00+00:00'),
            null,
            null,
            200,
            'timed out',
        ));

        $body = self::bodyOf($this->send('GET', '/admin/subscriptions'));

        self::assertStringContainsString('last fetched', $body);
        self::assertStringContainsString('HTTP 200', $body);
        self::assertStringContainsString('timed out', $body);
    }

    public function testSavingChangesTheStore(): void
    {
        $response = $this->send('POST', '/admin/subscriptions', $this->withToken([
            'action' => 'save',
            'category' => self::CATEGORY,
            'xml_url' => ['0' => self::FIRST, '1' => self::SECOND, '2' => self::THIRD],
            'position' => ['0' => '1', '1' => '2', '2' => '3'],
            'refresh_minutes' => ['0' => '15', '1' => '60', '2' => '60'],
            'showed_items' => ['0' => '7', '1' => '3', '2' => '0'],
            'subscribed' => ['0' => 'yes', '1' => 'no', '2' => 'yes'],
        ]));

        self::assertSame(303, $response->getStatusCode());

        $feeds = $this->stored();
        self::assertSame(15, $feeds[0]->refreshMinutes);
        self::assertSame(7, $feeds[0]->showedItems);
        self::assertFalse($feeds[1]->subscribed);
        self::assertSame(0, $feeds[2]->showedItems);
    }

    public function testDeletingSelectedRowsRemovesThemAndRenumbersTheRest(): void
    {
        $this->send('POST', '/admin/subscriptions', $this->withToken([
            'action' => 'delete',
            'category' => self::CATEGORY,
            'xml_url' => ['0' => self::FIRST, '1' => self::SECOND, '2' => self::THIRD],
            'select' => ['1' => '1'],
        ]));

        $feeds = $this->stored();
        self::assertCount(2, $feeds);
        self::assertSame(self::FIRST, $feeds[0]->xmlUrl);
        self::assertSame(self::THIRD, $feeds[1]->xmlUrl);
        self::assertSame([1, 2], [$feeds[0]->position, $feeds[1]->position]);
    }

    public function testDeletingNothingDeletesNothing(): void
    {
        $this->send('POST', '/admin/subscriptions', $this->withToken([
            'action' => 'delete',
            'category' => self::CATEGORY,
            'xml_url' => ['0' => self::FIRST, '1' => self::SECOND, '2' => self::THIRD],
        ]));

        self::assertCount(3, $this->stored());
    }

    public function testARowCanBeMovedUp(): void
    {
        $this->send('POST', '/admin/subscriptions', $this->withToken([
            'category' => self::CATEGORY,
            'move' => 'up:2',
        ]));

        $order = array_map(static fn (Feed $f): string => $f->xmlUrl, $this->stored());
        self::assertSame([self::FIRST, self::THIRD, self::SECOND], $order);
    }

    public function testARowCanBeMovedDown(): void
    {
        $this->send('POST', '/admin/subscriptions', $this->withToken([
            'category' => self::CATEGORY,
            'move' => 'down:0',
        ]));

        $order = array_map(static fn (Feed $f): string => $f->xmlUrl, $this->stored());
        self::assertSame([self::SECOND, self::FIRST, self::THIRD], $order);
    }

    public function testTheTopRowCannotMoveUp(): void
    {
        $this->send('POST', '/admin/subscriptions', $this->withToken([
            'category' => self::CATEGORY,
            'move' => 'up:0',
        ]));

        $order = array_map(static fn (Feed $f): string => $f->xmlUrl, $this->stored());
        self::assertSame([self::FIRST, self::SECOND, self::THIRD], $order);
    }

    public function testARowWhoseAddressNoLongerMatchesIsLeftAlone(): void
    {
        // The list changed in another tab: row 1 is no longer the feed the
        // form thought it was, so its values must not be written to it.
        $this->send('POST', '/admin/subscriptions', $this->withToken([
            'action' => 'save',
            'category' => self::CATEGORY,
            'xml_url' => ['1' => 'https://moved.example/feed.xml'],
            'refresh_minutes' => ['1' => '5'],
        ]));

        self::assertSame(60, $this->stored()[1]->refreshMinutes);
    }

    public function testAnHtmxSaveAnswersWithTheTableAlone(): void
    {
        $response = $this->send('POST', '/admin/_/subscriptions', $this->withToken([
            'action' => 'save',
            'category' => self::CATEGORY,
            'xml_url' => ['0' => self::FIRST],
            'refresh_minutes' => ['0' => '45'],
        ]), ['HX-Request' => 'true']);

        self::assertSame(200, $response->getStatusCode());
        $body = self::bodyOf($response);
        self::assertStringStartsNotWith('<!DOCTYPE', $body);
        self::assertStringContainsString('id="zf-subscriptions"', $body);
        self::assertStringContainsString('hx-swap-oob="true"', $body);
        self::assertSame(45, $this->stored()[0]->refreshMinutes);
    }

    public function testTheCategorySwitchAnswersWithTheTableAlone(): void
    {
        $this->kernel->subscriptions()->createCategory('empty-one');

        $request = $this->request('GET', '/admin/_/subscriptions')
            ->withQueryParams(['category' => 'empty-one']);
        $response = $this->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
        $body = self::bodyOf($response);
        self::assertStringContainsString('There are no subscriptions in "empty-one"', $body);
        self::assertStringNotContainsString('One', $body);
    }

    public function testAnUnknownCategoryFallsBackToTheDefault(): void
    {
        $request = $this->request('GET', '/admin/subscriptions')
            ->withQueryParams(['category' => '../../etc/passwd']);

        $response = $this->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(self::CATEGORY, self::bodyOf($response));
    }

    public function testAnAnonymousHtmxRequestIsToldToGoToTheLoginForm(): void
    {
        $this->session->destroy();

        $response = $this->send('POST', '/admin/_/subscriptions', [], ['HX-Request' => 'true']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('HX-Redirect'));
    }
}

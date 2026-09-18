<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Admin\AdminContext;
use Zfeeder\Exception\FetchException;
use Zfeeder\Exception\ParseException;
use Zfeeder\Exception\SecurityException;
use Zfeeder\Exception\StorageException;
use Zfeeder\Fetch\FetchResult;
use Zfeeder\Parse\Model\Channel;
use Zfeeder\Subscription\Feed;

/**
 * "Add new feed": the 2004 screen, with the 2004 shape and none of the 2004
 * plumbing.
 *
 * The flow is unchanged — a site address is searched for feeds, a feed address
 * is fetched and previewed, the preview is saved into a category — because it
 * is a good flow and people remember it. What changed is underneath: every
 * outbound request goes through UrlGuard and the bounded reader, where 1.6
 * called `fopen($url)` on whatever was typed in, and the preview is built by
 * the real parser rather than by a hand-rolled SAX handler that understood
 * only RSS.
 */
final class AddFeedController extends AbstractController
{
    protected const string SCREEN = 'addfeed';

    private const int DEFAULT_REFRESH_MINUTES = 60;
    private const int DEFAULT_SHOWED_ITEMS = 3;

    /** A web page can be much larger than a feed; still bounded. */
    private const int MAX_PAGE_BYTES = 2097152;

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $query = $this->query($request);
        $feedUrl = $this->field($query, 'feed_url');

        // The bookmarklet arrives here, exactly as "ShowOnMySite" did in 1.6.
        if ($feedUrl !== '') {
            return $this->preview($request, $feedUrl);
        }

        return $this->form($request);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->csrfValid($request)) {
            return $this->csrfFailure($request);
        }

        $body = $this->body($request);

        return match ($this->field($body, 'action')) {
            'discover' => $this->discover($request, $this->field($body, 'site_url')),
            'preview' => $this->preview($request, $this->field($body, 'feed_url')),
            'subscribe' => $this->subscribe($request),
            default => $this->form($request, 'Nothing to do: choose a site address or a feed address.'),
        };
    }

    /** The htmx target for the autodiscovery box; identical markup either way. */
    public function discoverPartial(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->csrfValid($request)) {
            return $this->csrfFailure($request);
        }

        $result = $this->runDiscovery($this->field($this->body($request), 'site_url'));

        return $this->partial('partials/discovery.twig', ['discovery' => $result]);
    }

    // ---- the three steps -------------------------------------------------

    private function form(ServerRequestInterface $request, string $error = ''): ResponseInterface
    {
        return $this->render('addfeed.twig', [
            'title' => 'Add new feed',
            'error' => $error,
            'discovery' => null,
            'bookmarklet' => $this->bookmarklet($request),
        ]);
    }

    private function discover(ServerRequestInterface $request, string $siteUrl): ResponseInterface
    {
        return $this->render('addfeed.twig', [
            'title' => 'Add new feed',
            'error' => '',
            'discovery' => $this->runDiscovery($siteUrl),
            'bookmarklet' => $this->bookmarklet($request),
        ]);
    }

    /**
     * @return array{site_url: string, feeds: list<array{url: string, type: string, title: string}>, guesses: list<string>, error: string}
     */
    private function runDiscovery(string $siteUrl): array
    {
        $result = ['site_url' => $siteUrl, 'feeds' => [], 'guesses' => [], 'error' => ''];

        if ($siteUrl === '') {
            $result['error'] = 'Type the address of a site to search.';

            return $result;
        }

        try {
            $html = $this->guardedFetch(self::MAX_PAGE_BYTES)->get($siteUrl, ['Accept' => 'text/html, application/xhtml+xml']);
        } catch (SecurityException | FetchException $e) {
            $result['error'] = $e->getMessage();

            return $result;
        } catch (\Throwable $e) {
            $result['error'] = 'That page could not be read: ' . $e->getMessage();

            return $result;
        }

        $result['feeds'] = $this->kernel->autodiscovery()->discover($html, $siteUrl);
        if ($result['feeds'] === []) {
            // Nothing declared. 1.6 gave up here; offering the conventional
            // locations turns a dead end into one more click.
            $result['guesses'] = $this->kernel->autodiscovery()->fallbackPaths($siteUrl);
            $result['error'] = 'This page declares no feeds. You can try one of the usual addresses below, '
                . 'or paste the feed address into the second form.';
        }

        return $result;
    }

    private function preview(ServerRequestInterface $request, string $feedUrl): ResponseInterface
    {
        if ($feedUrl === '') {
            return $this->form($request, 'Type the address of a feed.');
        }

        $result = $this->kernel->fetcher()->fetch($feedUrl, self::DEFAULT_REFRESH_MINUTES, true);
        if ($result->outcome === FetchResult::FAILED && $result->entry === null) {
            return $this->render('addfeed.twig', [
                'title' => 'Add new feed',
                'error' => $result->message,
                'discovery' => null,
                'bookmarklet' => $this->bookmarklet($request),
            ], 400);
        }

        $entry = $result->entry;
        if ($entry === null) {
            return $this->render('addfeed.twig', [
                'title' => 'Add new feed',
                'error' => 'Nothing came back from ' . $feedUrl,
                'discovery' => null,
                'bookmarklet' => $this->bookmarklet($request),
            ], 400);
        }

        try {
            $channel = $this->kernel->parser()->parse($entry->body, $feedUrl);
        } catch (ParseException $e) {
            return $this->render('addfeed.twig', [
                'title' => 'Add new feed',
                'error' => 'That address is not a feed we can read: ' . $e->getMessage(),
                'discovery' => null,
                'bookmarklet' => $this->bookmarklet($request),
            ], 400);
        }

        return $this->render('addfeed_preview.twig', [
            'title' => 'Add new feed',
            'error' => $result->message,
            'preview' => $this->previewFields($feedUrl, $channel),
            'categories' => $this->categoryNames(),
            'selected_category' => $this->resolveCategoryName(''),
            'validator_url' => 'https://validator.w3.org/feed/check.cgi?url=' . rawurlencode($feedUrl),
        ]);
    }

    /**
     * @return array{
     *   feed_url: string, site_url: string, language: string, format: string, copyright: string,
     *   last_build: string, title: string, description: string, refresh_minutes: int,
     *   showed_items: int, subscribed: bool, items: int, logo_url: string, logo_title: string
     * }
     */
    private function previewFields(string $feedUrl, Channel $channel): array
    {
        return [
            'feed_url' => $feedUrl,
            'site_url' => $channel->link,
            'language' => $channel->language,
            'format' => $channel->format,
            'copyright' => $channel->copyright,
            'last_build' => $channel->lastBuild?->format(DATE_RFC7231) ?? '',
            'title' => $channel->title,
            'description' => $channel->description,
            'refresh_minutes' => self::DEFAULT_REFRESH_MINUTES,
            'showed_items' => self::DEFAULT_SHOWED_ITEMS,
            'subscribed' => true,
            'items' => count($channel->items),
            'logo_url' => $channel->logoUrl,
            'logo_title' => $channel->logoTitle,
        ];
    }

    private function subscribe(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $feedUrl = $this->field($body, 'feed_url');

        if ($feedUrl === '') {
            return $this->form($request, 'That form had no feed address in it.');
        }

        // The address was checked before the preview; it is checked again here
        // because the preview form is a form, and a form can be edited.
        if (!$this->kernel->urlGuard()->isAllowed($feedUrl)) {
            return $this->form($request, 'That feed address cannot be used: ' . $feedUrl);
        }

        $categoryName = $this->resolveCategoryName($this->field($body, 'category'));

        try {
            $store = $this->kernel->subscriptions();
            $category = $store->category($categoryName);

            $position = 0;
            foreach ($category->feeds as $existing) {
                if ($existing->xmlUrl === $feedUrl) {
                    return $this->form($request, 'That feed is already in the "' . $categoryName . '" category.');
                }
                $position = max($position, $existing->position);
            }

            $category->feeds[] = new Feed(
                xmlUrl: $feedUrl,
                title: $this->field($body, 'title'),
                description: $this->field($body, 'description'),
                htmlUrl: $this->field($body, 'site_url'),
                position: $position + 1,
                refreshMinutes: max(1, $this->intField($body, 'refresh_minutes', self::DEFAULT_REFRESH_MINUTES)),
                showedItems: max(0, $this->intField($body, 'showed_items', self::DEFAULT_SHOWED_ITEMS)),
                subscribed: $this->checked($body, 'subscribed'),
                language: $this->field($body, 'language'),
            );
            $store->saveCategory($category);
        } catch (StorageException | \Zfeeder\Exception\ConfigException $e) {
            return $this->form($request, 'That subscription could not be saved: ' . $e->getMessage());
        }

        $this->context->addFlash(
            AdminContext::LEVEL_SUCCESS,
            sprintf('Added to the "%s" subscription list.', $categoryName),
        );

        return $this->redirect('/admin/subscriptions?category=' . rawurlencode($categoryName));
    }

    /**
     * The 2004 bookmarklet, rebuilt: it reads the feed links a page declares
     * and sends the first one back to this screen.
     */
    private function bookmarklet(ServerRequestInterface $request): string
    {
        $base = trim($this->kernel->config()->string('base_url'));
        if ($base === '') {
            $uri = $request->getUri();
            $authority = $uri->getAuthority();
            $base = $authority === ''
                ? $this->context->url('/')
                : $uri->getScheme() . '://' . $authority . $this->context->url('/');
        }
        $target = rtrim($base, '/') . '/admin/add-new';

        return "javascript:(function(){var l=document.getElementsByTagName('link'),f=[];"
            . "for(var i=0;i<l.length;i++){var t=(l[i].getAttribute('type')||'').toLowerCase(),h=l[i].getAttribute('href');"
            . "if(h&&(t=='application/rss+xml'||t=='application/atom+xml'||t=='application/feed+json'||t=='text/xml')){f.push(h)}}"
            . "if(!f.length){alert('No feeds found on this page.');return}"
            . "location.href='" . $target . "?feed_url='+encodeURIComponent(new URL(f[0],location.href).href)})()";
    }
}

<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Admin\AdminContext;
use Zfeeder\Exception\ConfigException;
use Zfeeder\Exception\StorageException;
use Zfeeder\Subscription\Category;
use Zfeeder\Subscription\Feed;

/**
 * The subscriptions table: position, subscribed, refresh minutes, shown items,
 * and what happened the last time each feed was fetched.
 *
 * Rows are addressed by their index in the sorted list *and* carry the feed
 * address in a hidden field. Index alone is what 1.6 used, and it silently
 * writes the wrong row if the list changed in another tab between the render
 * and the save; comparing the address turns that into "this row was skipped"
 * instead of "this row was overwritten with someone else's settings".
 *
 * Every mutation is a POST with a token. The htmx attributes on the form only
 * change where the answer is painted: with scripting off the same form posts
 * to the same route and the whole page comes back.
 */
final class SubscriptionsController extends AbstractController
{
    protected const string SCREEN = 'subscriptions';

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $name = $this->resolveCategoryName($this->field($this->query($request), 'category'));

        return $this->render('subscriptions.twig', $this->screenVars($name));
    }

    /** htmx: the category selector swaps the table without a page load. */
    public function tablePartial(ServerRequestInterface $request): ResponseInterface
    {
        $name = $this->resolveCategoryName($this->field($this->query($request), 'category'));

        return $this->partial('partials/subscriptions_table.twig', ['fragment' => true] + $this->screenVars($name));
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->csrfValid($request)) {
            return $this->csrfFailure($request);
        }

        $body = $this->body($request);
        $name = $this->resolveCategoryName($this->field($body, 'category'));

        try {
            $category = $this->kernel->subscriptions()->category($name);
        } catch (StorageException $e) {
            return $this->failure($request, $name, $e->getMessage());
        }

        $action = $this->field($body, 'action');
        $move = $this->field($body, 'move');
        if ($move !== '') {
            $action = 'move';
        }

        try {
            $outcome = match ($action) {
                'save' => $this->save($category, $body),
                'delete' => $this->delete($category, $body),
                'move' => $this->move($category, $move),
                default => 'Nothing to do.',
            };
        } catch (StorageException | ConfigException $e) {
            return $this->failure($request, $name, $e->getMessage());
        }

        $this->context->addFlash(AdminContext::LEVEL_SUCCESS, $outcome);

        if ($this->isHtmx($request)) {
            return $this->partial('partials/subscriptions_table.twig', ['fragment' => true] + $this->screenVars($name));
        }

        return $this->redirect('/admin/subscriptions?category=' . rawurlencode($name));
    }

    // ---- the three mutations --------------------------------------------

    /** @param array<string, mixed> $body */
    private function save(Category $category, array $body): string
    {
        $rows = $this->sorted($category);
        $positions = $this->rowValues($body, 'position');
        $refresh = $this->rowValues($body, 'refresh_minutes');
        $showed = $this->rowValues($body, 'showed_items');
        $subscribed = $this->rowValues($body, 'subscribed');
        $urls = $this->rowValues($body, 'xml_url');

        $saved = 0;
        $feeds = [];
        foreach ($rows as $index => $feed) {
            $key = (string) $index;
            if (($urls[$key] ?? $feed->xmlUrl) !== $feed->xmlUrl) {
                // The list moved under us; keep the row exactly as it is.
                $feeds[] = $feed;

                continue;
            }

            $feeds[] = new Feed(
                xmlUrl: $feed->xmlUrl,
                title: $feed->title,
                description: $feed->description,
                htmlUrl: $feed->htmlUrl,
                position: max(0, $this->toInt($positions[$key] ?? null, $feed->position)),
                refreshMinutes: max(1, $this->toInt($refresh[$key] ?? null, $feed->refreshMinutes)),
                showedItems: max(0, $this->toInt($showed[$key] ?? null, $feed->showedItems)),
                subscribed: $this->toBool($subscribed[$key] ?? null, $feed->subscribed),
                language: $feed->language,
            );
            ++$saved;
        }

        // The numbers just typed decide the order; then they are made tidy.
        usort($feeds, static fn (Feed $a, Feed $b): int => $a->position <=> $b->position);
        $category->feeds = $this->renumber($feeds);
        $this->kernel->subscriptions()->saveCategory($category);

        return sprintf('Saved %d subscription%s.', $saved, $saved === 1 ? '' : 's');
    }

    /** @param array<string, mixed> $body */
    private function delete(Category $category, array $body): string
    {
        $selected = $body['select'] ?? null;
        $selected = is_array($selected) ? $selected : [];
        $urls = $this->rowValues($body, 'xml_url');

        $rows = $this->sorted($category);
        $kept = [];
        $removed = 0;

        foreach ($rows as $index => $feed) {
            $key = (string) $index;
            $isSelected = array_key_exists($key, $selected);
            $identityMatches = ($urls[$key] ?? $feed->xmlUrl) === $feed->xmlUrl;

            if ($isSelected && $identityMatches) {
                ++$removed;

                continue;
            }
            $kept[] = $feed;
        }

        if ($removed === 0) {
            return 'Nothing was selected, so nothing was deleted.';
        }

        $category->feeds = $this->renumber($kept);
        $this->kernel->subscriptions()->saveCategory($category);

        return sprintf('Deleted %d subscription%s.', $removed, $removed === 1 ? '' : 's');
    }

    /** @param string $move `up:3` or `down:3` */
    private function move(Category $category, string $move): string
    {
        $parts = explode(':', $move, 2);
        $direction = $parts[0];
        $index = isset($parts[1]) && preg_match('/^\d+$/', $parts[1]) === 1 ? (int) $parts[1] : -1;

        $rows = $this->sorted($category);
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index < 0 || !isset($rows[$index], $rows[$target])) {
            return 'That row cannot move any further.';
        }

        $moved = $rows[$index];
        $rows[$index] = $rows[$target];
        $rows[$target] = $moved;

        $category->feeds = $this->renumber(array_values($rows));
        $this->kernel->subscriptions()->saveCategory($category);

        return sprintf('Moved "%s" %s.', $moved->label(), $direction === 'up' ? 'up' : 'down');
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * Positions become 1..n in the order the list is already in.
     *
     * Renumbering rather than keeping what was typed is what makes "position
     * 3, 3, 7" behave: the operator's intent is an order, and gaps and ties in
     * an OPML `position` attribute are exactly the mess 1.6 left behind.
     *
     * @param list<Feed> $feeds
     *
     * @return list<Feed>
     */
    private function renumber(array $feeds): array
    {
        $position = 1;
        foreach ($feeds as $feed) {
            $feed->position = $position;
            ++$position;
        }

        return $feeds;
    }

    /** @return list<Feed> */
    private function sorted(Category $category): array
    {
        $feeds = $category->feeds;
        usort($feeds, static fn (Feed $a, Feed $b): int => $a->position <=> $b->position);

        return $feeds;
    }

    /**
     * One row-indexed form field, as `name[0]`, `name[1]`, …
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, string>
     */
    private function rowValues(array $body, string $name): array
    {
        $raw = $body[$name] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($value) || is_int($value)) {
                $out[(string) $key] = trim((string) $value);
            }
        }

        return $out;
    }

    private function toInt(?string $value, int $default): int
    {
        return $value !== null && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    private function toBool(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['yes', '1', 'on', 'true'], true);
    }

    /** @return array<string, mixed> */
    private function screenVars(string $name): array
    {
        $rows = [];
        $problem = '';

        try {
            $category = $this->kernel->subscriptions()->category($name);
            foreach ($this->sorted($category) as $index => $feed) {
                $rows[] = $this->row($index, $feed);
            }
        } catch (StorageException $e) {
            $problem = $e->getMessage();
        }

        return [
            'title' => 'Subscriptions',
            'category' => $name,
            'categories' => $this->categoryNames(),
            'rows' => $rows,
            'problem' => $problem,
        ];
    }

    /** @return array<string, mixed> */
    private function row(int $index, Feed $feed): array
    {
        $entry = $this->kernel->cache()->get($feed->xmlUrl);
        $now = $this->kernel->clock();

        return [
            'index' => $index,
            'xml_url' => $feed->xmlUrl,
            'html_url' => $feed->htmlUrl,
            'title' => $feed->label(),
            'description' => $feed->description,
            'language' => $feed->language,
            'position' => $feed->position,
            'refresh_minutes' => $feed->refreshMinutes,
            'showed_items' => $feed->showedItems,
            'subscribed' => $feed->subscribed,
            'last_fetch' => [
                'cached' => $entry !== null,
                'at' => $entry !== null ? $entry->fetchedAt->format(DATE_RFC7231) : '',
                'status' => $entry !== null ? $entry->status : 0,
                'error' => $entry !== null ? $entry->error : '',
                'stale' => $entry === null || $entry->isExpired($feed->refreshMinutes, $now),
            ],
        ];
    }

    private function failure(ServerRequestInterface $request, string $name, string $message): ResponseInterface
    {
        $this->context->addFlash(AdminContext::LEVEL_ERROR, $message);

        if ($this->isHtmx($request)) {
            return $this->partial('partials/subscriptions_table.twig', ['fragment' => true] + $this->screenVars($name), 422);
        }

        return $this->render('subscriptions.twig', $this->screenVars($name), 422);
    }
}

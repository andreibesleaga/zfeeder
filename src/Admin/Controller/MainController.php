<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Exception\StorageException;
use Zfeeder\Version;

/**
 * The landing screen: the menu 1.6 had, plus the three numbers an operator
 * actually opens the panel to check.
 *
 * "Refresh overdue" is the useful one. In offline mode nothing fetches unless
 * cron calls `/refresh`, and a cron job that quietly stopped looks exactly
 * like a site whose feeds have gone quiet. Comparing every subscription
 * against its own cache entry turns that into a sentence.
 */
final class MainController extends AbstractController
{
    protected const string SCREEN = 'main';

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render('main.twig', [
            'title' => 'Administration',
            'status' => $this->status(),
        ]);
    }

    /**
     * @return array{
     *   categories: int, subscriptions: int, cache_entries: int, cache_bytes: int,
     *   storage: string, version: string, refresh_mode: string, overdue: int,
     *   template_set: string, data_dir: string, demo_mode: bool, problem: string
     * }
     */
    private function status(): array
    {
        $config = $this->kernel->config();
        $store = $this->kernel->subscriptions();
        $cache = $this->kernel->cache();
        $now = $this->kernel->clock();

        $categories = 0;
        $subscriptions = 0;
        $overdue = 0;
        $problem = '';

        try {
            $names = $store->categories();
            $categories = count($names);
            foreach ($names as $name) {
                foreach ($store->category($name)->feeds as $feed) {
                    ++$subscriptions;
                    if (!$feed->isRenderable()) {
                        continue;
                    }
                    $entry = $cache->get($feed->xmlUrl);
                    if ($entry === null || $entry->isExpired($feed->refreshMinutes, $now)) {
                        ++$overdue;
                    }
                }
            }
        } catch (StorageException $e) {
            $problem = $e->getMessage();
        }

        $entries = 0;
        $bytes = 0;
        foreach ($cache->urls() as $url) {
            $entry = $cache->get($url);
            if ($entry === null) {
                continue;
            }
            ++$entries;
            $bytes += strlen($entry->body);
        }

        return [
            'categories' => $categories,
            'subscriptions' => $subscriptions,
            'cache_entries' => $entries,
            'cache_bytes' => $bytes,
            'storage' => $config->string('storage'),
            'version' => Version::NUMBER,
            'refresh_mode' => $config->string('refresh_mode'),
            'overdue' => $overdue,
            'template_set' => $config->string('template_set'),
            'data_dir' => $config->dataDir(),
            'demo_mode' => $config->bool('demo_mode'),
            'problem' => $problem,
        ];
    }
}

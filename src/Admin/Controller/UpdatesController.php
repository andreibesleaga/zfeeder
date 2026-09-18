<?php

declare(strict_types=1);

namespace Zfeeder\Admin\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Exception\FetchException;
use Zfeeder\Exception\SecurityException;
use Zfeeder\Version;

/**
 * "Is there a newer zFeeder?"
 *
 * 1.6 answered this with `readfile('http://zvonnews.sourceforge.net/latest.php')`,
 * which prints whatever a remote host returns straight into the admin page.
 * Here the release endpoint is fetched through the guarded client, capped at a
 * few kilobytes, parsed as JSON, and only a version-shaped `tag_name` is ever
 * shown.
 *
 * The check is also off by default and says so. A panel that phones home
 * without being asked is a panel that leaks its existence, its version and its
 * address to a third party on every visit.
 */
final class UpdatesController extends AbstractController
{
    protected const string SCREEN = 'updates';

    public const string RELEASES_API = 'https://api.github.com/repos/andreibesleaga/zfeeder/releases/latest';
    public const string RELEASES_PAGE = 'https://github.com/andreibesleaga/zfeeder/releases';

    /** A release document is a few kilobytes; anything larger is not one. */
    private const int MAX_BYTES = 65536;

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $checking = $this->kernel->config()->bool('update_check');
        $vars = [
            'title' => 'Updates',
            'version' => Version::NUMBER,
            'releases_page' => self::RELEASES_PAGE,
            'checking' => $checking,
            'latest' => '',
            'newer' => false,
            'error' => '',
        ];

        if (!$checking) {
            return $this->render('updates.twig', $vars);
        }

        try {
            $latest = self::latestTag($this->guardedFetch(self::MAX_BYTES)->get(self::RELEASES_API, [
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]));
        } catch (SecurityException | FetchException $e) {
            $vars['error'] = $e->getMessage();

            return $this->render('updates.twig', $vars);
        } catch (\Throwable $e) {
            $vars['error'] = 'The release list could not be read: ' . $e->getMessage();

            return $this->render('updates.twig', $vars);
        }

        if ($latest === null) {
            $vars['error'] = 'The release list did not name a version.';

            return $this->render('updates.twig', $vars);
        }

        $vars['latest'] = $latest;
        $vars['newer'] = self::isNewer($latest, Version::NUMBER);

        return $this->render('updates.twig', $vars);
    }

    /** The `tag_name` of a releases document, when it looks like a version. */
    public static function latestTag(string $json): ?string
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $tag = $decoded['tag_name'] ?? null;
        if (!is_string($tag)) {
            return null;
        }

        $tag = ltrim(trim($tag), 'vV');

        // Only a plain dotted version is ever shown; the response is remote
        // input and this string ends up on the page.
        return preg_match('/^\d+(\.\d+){0,3}(-[0-9A-Za-z.-]{1,32})?$/', $tag) === 1 ? $tag : null;
    }

    /** True when `$candidate` is a later release than `$current`. */
    public static function isNewer(string $candidate, string $current): bool
    {
        return version_compare($candidate, $current, '>');
    }
}

<?php

declare(strict_types=1);

namespace Zfeeder\Admin;

use Zfeeder\Http\Router;

/**
 * Every path the panel answers on, and the handler name each one maps to.
 *
 * The 1.6 names are kept where they were meaningful ("add new",
 * "subscriptions", "import feed list"), because they are what the
 * documentation, the screenshots and fifteen-year-old muscle memory all say.
 * What changed is that they are paths rather than `?zfaction=` values, and
 * that reading and writing are different routes: in 1.6 a GET could delete a
 * subscription.
 *
 * htmx fragments live under `/admin/_/`, which marks them as an implementation
 * detail of a screen rather than a screen of their own. Every one of them has
 * a full-page equivalent, and the templates post to the full-page route when
 * scripting is off.
 */
final class AdminRoutes
{
    public const string MAIN = 'admin.main';
    public const string LOGIN = 'admin.login';
    public const string LOGIN_SUBMIT = 'admin.login.submit';
    public const string LOGOUT = 'admin.logout';
    public const string ADD_FEED = 'admin.addfeed';
    public const string ADD_FEED_SUBMIT = 'admin.addfeed.submit';
    public const string SUBSCRIPTIONS = 'admin.subscriptions';
    public const string SUBSCRIPTIONS_SUBMIT = 'admin.subscriptions.submit';
    public const string CONFIG = 'admin.config';
    public const string CONFIG_SUBMIT = 'admin.config.submit';
    public const string IMPORT = 'admin.import';
    public const string IMPORT_SUBMIT = 'admin.import.submit';
    public const string EXPORT = 'admin.export';
    public const string UPDATES = 'admin.updates';
    public const string PARTIAL_DISCOVER = 'admin.partial.discover';
    public const string PARTIAL_SUBSCRIPTIONS = 'admin.partial.subscriptions';
    public const string PARTIAL_SUBSCRIPTIONS_SUBMIT = 'admin.partial.subscriptions.submit';

    /** Handlers an anonymous visitor may reach. */
    public const array PUBLIC_HANDLERS = [self::LOGIN, self::LOGIN_SUBMIT];

    /** Handlers demo mode lets through even though they are POSTs. */
    public const array DEMO_EXEMPT_HANDLERS = [self::LOGIN_SUBMIT, self::LOGOUT];

    public static function register(Router $router): Router
    {
        $router
            ->get('/admin', self::MAIN, 'admin.main')
            ->get('/admin/login', self::LOGIN, 'admin.login')
            ->post('/admin/login', self::LOGIN_SUBMIT)
            ->post('/admin/logout', self::LOGOUT, 'admin.logout')
            ->get('/admin/add-new', self::ADD_FEED, 'admin.addfeed')
            ->post('/admin/add-new', self::ADD_FEED_SUBMIT)
            ->get('/admin/subscriptions', self::SUBSCRIPTIONS, 'admin.subscriptions')
            ->post('/admin/subscriptions', self::SUBSCRIPTIONS_SUBMIT)
            ->get('/admin/config', self::CONFIG, 'admin.config')
            ->post('/admin/config', self::CONFIG_SUBMIT)
            ->get('/admin/import', self::IMPORT, 'admin.import')
            ->post('/admin/import', self::IMPORT_SUBMIT)
            ->get('/admin/export/{category}', self::EXPORT, 'admin.export')
            ->get('/admin/updates', self::UPDATES, 'admin.updates')
            ->post('/admin/_/discover', self::PARTIAL_DISCOVER)
            ->get('/admin/_/subscriptions', self::PARTIAL_SUBSCRIPTIONS)
            ->post('/admin/_/subscriptions', self::PARTIAL_SUBSCRIPTIONS_SUBMIT);

        return $router;
    }

    /** @return list<string> every handler name this class registers */
    public static function handlers(): array
    {
        return [
            self::MAIN, self::LOGIN, self::LOGIN_SUBMIT, self::LOGOUT,
            self::ADD_FEED, self::ADD_FEED_SUBMIT,
            self::SUBSCRIPTIONS, self::SUBSCRIPTIONS_SUBMIT,
            self::CONFIG, self::CONFIG_SUBMIT,
            self::IMPORT, self::IMPORT_SUBMIT, self::EXPORT,
            self::UPDATES,
            self::PARTIAL_DISCOVER, self::PARTIAL_SUBSCRIPTIONS, self::PARTIAL_SUBSCRIPTIONS_SUBMIT,
        ];
    }

    public static function isAdminHandler(string $handler): bool
    {
        return str_starts_with($handler, 'admin.');
    }
}

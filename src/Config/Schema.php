<?php

declare(strict_types=1);

namespace Zfeeder\Config;

/**
 * The single definition of every configuration option.
 *
 * Each option names its environment variable, its JSON key, its type and its
 * default. `docs/CONFIGURATION.md` is generated from this table, the admin
 * config screen is built from it, and `bin/zfeeder check-config` validates
 * against it, so the three can never drift apart.
 */
final class Schema
{
    public const string TYPE_STRING = 'string';
    public const string TYPE_INT = 'int';
    public const string TYPE_BOOL = 'bool';
    public const string TYPE_ENUM = 'enum';

    /** Options the admin screen must never offer to change. */
    public const array SECRET_KEYS = ['admin_password_hash', 'refresh_key'];

    /**
     * @return array<string, array{
     *   env: string, type: string, default: string|int|bool, group: string,
     *   values?: list<string>, min?: int, max?: int, label: string, help: string, adminEditable: bool
     * }>
     */
    public static function all(): array
    {
        return [
            'env' => [
                'env' => 'ZF_ENV', 'type' => self::TYPE_ENUM, 'values' => ['production', 'development', 'testing'],
                'default' => 'production', 'group' => 'general', 'adminEditable' => false,
                'label' => 'Environment', 'help' => 'development shows detailed errors and allows private hosts.',
            ],
            'data_dir' => [
                'env' => 'ZF_DATA_DIR', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'general',
                'adminEditable' => false, 'label' => 'Data directory',
                'help' => 'Where subscriptions, cache and config live. Must be outside the web root.',
            ],
            'storage' => [
                'env' => 'ZF_STORAGE', 'type' => self::TYPE_ENUM, 'values' => ['flat', 'sqlite'],
                'default' => 'flat', 'group' => 'general', 'adminEditable' => false,
                'label' => 'Storage backend', 'help' => 'flat keeps OPML files; sqlite keeps one database file.',
            ],
            'sqlite_path' => [
                'env' => 'ZF_SQLITE_PATH', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'general',
                'adminEditable' => false, 'label' => 'SQLite file', 'help' => 'Defaults to zfeeder.sqlite in the data directory.',
            ],
            'categories_dir' => [
                'env' => 'ZF_CATEGORIES_DIR', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'general',
                'adminEditable' => false, 'label' => 'Categories directory', 'help' => 'Flat storage only. Defaults to categories/ in the data directory.',
            ],
            'cache_dir' => [
                'env' => 'ZF_CACHE_DIR', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'general',
                'adminEditable' => false, 'label' => 'Cache directory', 'help' => 'Flat storage only. Defaults to cache/ in the data directory.',
            ],
            'base_url' => [
                'env' => 'ZF_URL', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'general',
                'adminEditable' => true, 'label' => 'zFeeder URL',
                'help' => 'Public URL of this installation, ending with a slash. Empty means the site root, which is correct whenever zFeeder is served at the top of a domain; set it for an installation in a subdirectory.',
            ],
            'default_category' => [
                'env' => 'ZF_DEFAULT_CATEGORY', 'type' => self::TYPE_STRING, 'default' => 'zfeeder', 'group' => 'feeds',
                'adminEditable' => true, 'label' => 'Default category', 'help' => 'Used when a request names no category.',
            ],
            'template_set' => [
                'env' => 'ZF_TEMPLATE_SET', 'type' => self::TYPE_ENUM, 'values' => ['classic', 'modern'],
                'default' => 'classic', 'group' => 'display', 'adminEditable' => true,
                'label' => 'Template set', 'help' => 'classic reproduces the 2004 output; modern is the responsive redesign.',
            ],
            'default_template' => [
                'env' => 'ZF_DEFAULT_TEMPLATE', 'type' => self::TYPE_STRING, 'default' => 'bluelogos', 'group' => 'display',
                'adminEditable' => true, 'label' => 'Default template', 'help' => 'Template name within the selected set.',
            ],
            'admin_skin' => [
                'env' => 'ZF_ADMIN_SKIN', 'type' => self::TYPE_ENUM, 'values' => ['classic', 'modern'],
                'default' => 'modern', 'group' => 'display', 'adminEditable' => true,
                'label' => 'Admin skin', 'help' => 'Appearance of the administration panel.',
            ],
            'channel_location' => [
                'env' => 'ZF_CHANNEL_LOCATION', 'type' => self::TYPE_ENUM, 'values' => ['top', 'bottom', 'none'],
                'default' => 'top', 'group' => 'display', 'adminEditable' => true,
                'label' => 'Channel bar location', 'help' => 'Where the channel bar is drawn relative to the items.',
            ],
            'channel_one_bar' => [
                'env' => 'ZF_CHANNEL_ONE_BAR', 'type' => self::TYPE_BOOL, 'default' => true, 'group' => 'display',
                'adminEditable' => true, 'label' => 'One channel bar per feed',
                'help' => 'On draws one bar per feed; off repeats it for every item, as 1.6 did.',
            ],
            'display_error' => [
                'env' => 'ZF_DISPLAY_ERROR', 'type' => self::TYPE_BOOL, 'default' => false, 'group' => 'display',
                'adminEditable' => true, 'label' => 'Show feed errors',
                'help' => 'Render a message in place of a feed that cannot be read.',
            ],
            'powered_by' => [
                'env' => 'ZF_POWERED_BY', 'type' => self::TYPE_BOOL, 'default' => true, 'group' => 'display',
                'adminEditable' => true, 'label' => 'Powered by link', 'help' => 'Append the small "powered by zFeeder" line.',
            ],
            'max_description_chars' => [
                'env' => 'ZF_MAX_DESCRIPTION_CHARS', 'type' => self::TYPE_INT, 'default' => 600, 'min' => 0, 'max' => 100000,
                'group' => 'display', 'adminEditable' => true, 'label' => 'Maximum description length',
                'help' => 'Modern feeds ship whole articles. 0 means no limit, which is what 1.6 did.',
            ],
            'allow_html_in_items' => [
                'env' => 'ZF_ALLOW_HTML_IN_ITEMS', 'type' => self::TYPE_BOOL, 'default' => true, 'group' => 'display',
                'adminEditable' => true, 'label' => 'Allow HTML in items',
                'help' => 'On keeps sanitised markup from the feed; off strips it to plain text.',
            ],
            'refresh_mode' => [
                'env' => 'ZF_REFRESH_MODE', 'type' => self::TYPE_ENUM, 'values' => ['online', 'offline'],
                'default' => 'online', 'group' => 'feeds', 'adminEditable' => true,
                'label' => 'Refresh mode', 'help' => 'online fetches while rendering; offline only refreshes on command.',
            ],
            'refresh_key' => [
                'env' => 'ZF_REFRESH_KEY', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'feeds',
                'adminEditable' => true, 'label' => 'Refresh key', 'help' => 'Shared secret for the /refresh endpoint used by cron.',
            ],
            'fetch_timeout' => [
                'env' => 'ZF_FETCH_TIMEOUT', 'type' => self::TYPE_INT, 'default' => 10, 'min' => 1, 'max' => 120,
                'group' => 'feeds', 'adminEditable' => true, 'label' => 'Fetch timeout (seconds)', 'help' => 'Per request, including redirects.',
            ],
            'fetch_max_bytes' => [
                'env' => 'ZF_FETCH_MAX_BYTES', 'type' => self::TYPE_INT, 'default' => 2097152, 'min' => 1024, 'max' => 67108864,
                'group' => 'feeds', 'adminEditable' => true, 'label' => 'Maximum feed size (bytes)', 'help' => 'Larger responses are refused.',
            ],
            'fetch_max_redirects' => [
                'env' => 'ZF_FETCH_MAX_REDIRECTS', 'type' => self::TYPE_INT, 'default' => 5, 'min' => 0, 'max' => 20,
                'group' => 'feeds', 'adminEditable' => true, 'label' => 'Maximum redirects', 'help' => 'Every hop is checked again against the address rules.',
            ],
            'fetch_user_agent' => [
                'env' => 'ZF_FETCH_USER_AGENT', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'feeds',
                'adminEditable' => true, 'label' => 'User agent', 'help' => 'Sent when fetching feeds. Empty uses the zFeeder default.',
            ],
            'allow_private_hosts' => [
                'env' => 'ZF_ALLOW_PRIVATE_HOSTS', 'type' => self::TYPE_BOOL, 'default' => false, 'group' => 'feeds',
                'adminEditable' => false, 'label' => 'Allow private addresses',
                'help' => 'Development and tests only. Off blocks loopback and private network ranges.',
            ],
            'owner_name' => [
                'env' => 'ZF_OWNER_NAME', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'feeds',
                'adminEditable' => true, 'label' => 'Feed list owner name', 'help' => 'Written into exported OPML files.',
            ],
            'owner_email' => [
                'env' => 'ZF_OWNER_EMAIL', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'feeds',
                'adminEditable' => true, 'label' => 'Feed list owner email', 'help' => 'Written into exported OPML files.',
            ],
            'admin_enabled' => [
                'env' => 'ZF_ADMIN_ENABLED', 'type' => self::TYPE_BOOL, 'default' => true, 'group' => 'admin',
                'adminEditable' => false, 'label' => 'Administration panel', 'help' => 'Off removes the panel entirely, as the 1.6 "no panel" setting did.',
            ],
            'admin_user' => [
                'env' => 'ZF_ADMIN_USER', 'type' => self::TYPE_STRING, 'default' => 'admin', 'group' => 'admin',
                'adminEditable' => true, 'label' => 'Administrator name', 'help' => 'Username for the panel.',
            ],
            'admin_password_hash' => [
                'env' => 'ZF_ADMIN_PASSWORD_HASH', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'admin',
                'adminEditable' => false, 'label' => 'Password hash',
                'help' => 'Created by bin/zfeeder hash-password. Empty disables the panel.',
            ],
            'login_max_attempts' => [
                'env' => 'ZF_LOGIN_MAX_ATTEMPTS', 'type' => self::TYPE_INT, 'default' => 5, 'min' => 1, 'max' => 100,
                'group' => 'admin', 'adminEditable' => true, 'label' => 'Login attempts allowed', 'help' => 'Per address, within the window below.',
            ],
            'login_window_seconds' => [
                'env' => 'ZF_LOGIN_WINDOW', 'type' => self::TYPE_INT, 'default' => 900, 'min' => 30, 'max' => 86400,
                'group' => 'admin', 'adminEditable' => true, 'label' => 'Login window (seconds)', 'help' => 'How long failed attempts are remembered.',
            ],
            'session_name' => [
                'env' => 'ZF_SESSION_NAME', 'type' => self::TYPE_STRING, 'default' => 'zfsid', 'group' => 'admin',
                'adminEditable' => false, 'label' => 'Session cookie name', 'help' => 'Change it if another application shares the domain.',
            ],
            'session_idle_seconds' => [
                'env' => 'ZF_SESSION_IDLE', 'type' => self::TYPE_INT, 'default' => 1800, 'min' => 60, 'max' => 86400,
                'group' => 'admin', 'adminEditable' => true, 'label' => 'Session idle timeout (seconds)', 'help' => 'An idle panel session is signed out.',
            ],
            'session_secure' => [
                'env' => 'ZF_SESSION_SECURE', 'type' => self::TYPE_ENUM, 'values' => ['auto', 'always', 'never'],
                'default' => 'auto', 'group' => 'admin', 'adminEditable' => false,
                'label' => 'Secure cookie', 'help' => 'auto sets the flag when the request arrives over HTTPS.',
            ],
            'demo_mode' => [
                'env' => 'ZF_DEMO_MODE', 'type' => self::TYPE_BOOL, 'default' => false, 'group' => 'admin',
                'adminEditable' => false, 'label' => 'Demo mode', 'help' => 'The panel becomes read-only: every write is refused.',
            ],
            'demo_enabled' => [
                'env' => 'ZF_DEMO_ENABLED', 'type' => self::TYPE_BOOL, 'default' => true, 'group' => 'admin',
                'adminEditable' => false, 'label' => 'Site enabled', 'help' => 'Off serves a short notice instead of the site.',
            ],
            'api_enabled' => [
                'env' => 'ZF_API_ENABLED', 'type' => self::TYPE_BOOL, 'default' => true, 'group' => 'embedding',
                'adminEditable' => true, 'label' => 'JSON API', 'help' => 'Serve /api/feeds for pages that render feeds themselves.',
            ],
            'opml_export_public' => [
                'env' => 'ZF_OPML_EXPORT_PUBLIC', 'type' => self::TYPE_BOOL, 'default' => false, 'group' => 'embedding',
                'adminEditable' => true, 'label' => 'Public OPML export', 'help' => 'Allow anyone to download a category as OPML.',
            ],
            'embed_cors_origins' => [
                'env' => 'ZF_EMBED_CORS_ORIGINS', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'embedding',
                'adminEditable' => true, 'label' => 'Allowed origins',
                'help' => 'Comma separated list allowed to call /embed and /api. Empty allows none.',
            ],
            'embed_frame_ancestors' => [
                'env' => 'ZF_EMBED_FRAME_ANCESTORS', 'type' => self::TYPE_STRING, 'default' => "'self'", 'group' => 'embedding',
                'adminEditable' => true, 'label' => 'Frame ancestors',
                'help' => 'Content Security Policy value controlling who may put /embed in a frame.',
            ],
            'update_check' => [
                'env' => 'ZF_UPDATE_CHECK', 'type' => self::TYPE_BOOL, 'default' => false, 'group' => 'general',
                'adminEditable' => true, 'label' => 'Check for updates', 'help' => 'Asks the project release feed when you open the updates screen.',
            ],
            'trusted_proxies' => [
                'env' => 'ZF_TRUSTED_PROXIES', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'general',
                'adminEditable' => false, 'label' => 'Trusted proxies',
                'help' => 'Comma separated addresses, or * behind a platform load balancer such as Railway.',
            ],
            'log_level' => [
                'env' => 'ZF_LOG_LEVEL', 'type' => self::TYPE_ENUM,
                'values' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
                'default' => 'warning', 'group' => 'general', 'adminEditable' => true,
                'label' => 'Log level', 'help' => 'Records at or above this level are written.',
            ],
            'log_path' => [
                'env' => 'ZF_LOG_PATH', 'type' => self::TYPE_STRING, 'default' => '', 'group' => 'general',
                'adminEditable' => false, 'label' => 'Log file',
                'help' => 'Defaults to zfeeder.log in the data directory. php://stdout works in containers.',
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return array<string, array<string, mixed>> */
    public static function group(string $group): array
    {
        return array_filter(self::all(), static fn (array $o): bool => $o['group'] === $group);
    }

    /** @return list<string> */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::all() as $option) {
            if (!in_array($option['group'], $groups, true)) {
                $groups[] = $option['group'];
            }
        }

        return $groups;
    }

    /** @return array<string, string|int|bool> */
    public static function defaults(): array
    {
        return array_map(static fn (array $o): string|int|bool => $o['default'], self::all());
    }

    /** @return array<string, string> env var name => config key */
    public static function envMap(): array
    {
        $map = [];
        foreach (self::all() as $key => $option) {
            $map[$option['env']] = $key;
        }

        return $map;
    }
}

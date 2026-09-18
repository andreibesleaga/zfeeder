<?php

declare(strict_types=1);

namespace Zfeeder\Admin;

use Zfeeder\Admin\Auth\Csrf;
use Zfeeder\Admin\Auth\SessionAuth;
use Zfeeder\Admin\Auth\SessionInterface;
use Zfeeder\Config\Config;

/**
 * The per-request state the panel shares between its middleware, its
 * controllers and its templates: who is signed in, the CSRF token, the script
 * nonce, where the panel lives and what should be announced on the next page.
 *
 * It is deliberately not a service locator. Controllers still receive the
 * Kernel for everything that belongs to the application; this object only
 * carries what exists because the request is an *admin* request.
 */
final class AdminContext
{
    private const string FLASH_KEY = '_zf_flash';

    public const string LEVEL_SUCCESS = 'success';
    public const string LEVEL_ERROR = 'error';
    public const string LEVEL_INFO = 'info';

    public string $nonce = '';

    public string $handler = '';

    public function __construct(
        private readonly Config $config,
        public readonly SessionInterface $session,
        public readonly SessionAuth $auth,
        public readonly Csrf $csrf,
    ) {
    }

    /**
     * The path this installation is served under, without a trailing slash.
     * Empty when zFeeder is at the root, which is the common case.
     */
    public function basePath(): string
    {
        $base = trim($this->config->string('base_url'));
        if ($base === '') {
            return '';
        }

        $path = parse_url($base, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }

        $path = rtrim($path, '/');

        return $path === '/' ? '' : $path;
    }

    /** An absolute path within the panel: `url('/admin/config')`. */
    public function url(string $path): string
    {
        return $this->basePath() . '/' . ltrim($path, '/');
    }

    public function asset(string $path): string
    {
        return $this->basePath() . '/assets/' . ltrim($path, '/');
    }

    public function addFlash(string $level, string $message): void
    {
        $flashes = $this->flashes();
        $flashes[] = ['level' => $level, 'message' => $message];
        $this->session->set(self::FLASH_KEY, $flashes);
    }

    /**
     * The pending messages, cleared as they are read: a flash shown twice is
     * a flash the operator stops believing.
     *
     * @return list<array{level: string, message: string}>
     */
    public function takeFlashes(): array
    {
        $flashes = $this->flashes();
        $this->session->remove(self::FLASH_KEY);

        return $flashes;
    }

    /** @return list<array{level: string, message: string}> */
    private function flashes(): array
    {
        $raw = $this->session->get(self::FLASH_KEY);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $level = $entry['level'] ?? self::LEVEL_INFO;
            $message = $entry['message'] ?? '';
            if (is_string($level) && is_string($message) && $message !== '') {
                $out[] = ['level' => $level, 'message' => $message];
            }
        }

        return $out;
    }
}

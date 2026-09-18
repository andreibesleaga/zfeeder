<?php

declare(strict_types=1);

namespace Zfeeder\Config;

use Zfeeder\Exception\ConfigException;

/**
 * Effective configuration: defaults, overlaid by the config file, overlaid by
 * the environment. Every value remembers where it came from, so the admin
 * screen can show environment-locked values as read-only and
 * `bin/zfeeder check-config` can explain the result.
 */
final class Config
{
    public const string SOURCE_DEFAULT = 'default';
    public const string SOURCE_FILE = 'file';
    public const string SOURCE_ENV = 'env';

    /**
     * @param array<string, string|int|bool> $values
     * @param array<string, string>          $sources
     */
    private function __construct(
        private array $values,
        private array $sources,
        private readonly string $projectRoot,
        private readonly ?string $configPath,
    ) {
    }

    /**
     * @param array<string, string|int|bool> $values
     * @param array<string, string>          $sources
     */
    public static function fromResolved(
        array $values,
        array $sources,
        string $projectRoot,
        ?string $configPath = null,
    ): self {
        return new self($values, $sources, $projectRoot, $configPath);
    }

    /**
     * A configuration made only of defaults plus the given overrides; used by tests.
     *
     * @param array<string, string|int|bool> $overrides
     */
    public static function forTesting(array $overrides = [], ?string $dataDir = null): self
    {
        $values = Schema::defaults();
        $sources = array_fill_keys(array_keys($values), self::SOURCE_DEFAULT);
        foreach ($overrides as $key => $value) {
            if (!Schema::has((string) $key)) {
                throw new ConfigException('Unknown configuration key: ' . $key);
            }
            $values[$key] = $value;
            $sources[$key] = self::SOURCE_FILE;
        }
        $values['env'] = $overrides['env'] ?? 'testing';
        if ($dataDir !== null) {
            $values['data_dir'] = $dataDir;
            $sources['data_dir'] = self::SOURCE_FILE;
        }

        return new self($values, $sources, dirname(__DIR__, 2), null);
    }

    public function get(string $key): string|int|bool
    {
        if (!array_key_exists($key, $this->values)) {
            throw new ConfigException('Unknown configuration key: ' . $key);
        }

        return $this->values[$key];
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** @return list<string> comma separated option split into trimmed, non-empty parts */
    public function list(string $key): array
    {
        $raw = trim($this->string($key));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }

    public function sourceOf(string $key): string
    {
        return $this->sources[$key] ?? self::SOURCE_DEFAULT;
    }

    public function isLockedByEnvironment(string $key): bool
    {
        return $this->sourceOf($key) === self::SOURCE_ENV;
    }

    /** @return array<string, string|int|bool> */
    public function all(): array
    {
        return $this->values;
    }

    /** @return array<string, string> */
    public function sources(): array
    {
        return $this->sources;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function configPath(): ?string
    {
        return $this->configPath;
    }

    public function isProduction(): bool
    {
        return $this->string('env') === 'production';
    }

    public function isDevelopment(): bool
    {
        return $this->string('env') === 'development';
    }

    // ---- derived paths -------------------------------------------------

    public function dataDir(): string
    {
        $configured = trim($this->string('data_dir'));
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        return $this->projectRoot . '/data';
    }

    public function categoriesDir(): string
    {
        $configured = trim($this->string('categories_dir'));

        return $configured !== '' ? rtrim($configured, '/\\') : $this->dataDir() . '/categories';
    }

    public function cacheDir(): string
    {
        $configured = trim($this->string('cache_dir'));

        return $configured !== '' ? rtrim($configured, '/\\') : $this->dataDir() . '/cache';
    }

    public function sqlitePath(): string
    {
        $configured = trim($this->string('sqlite_path'));

        return $configured !== '' ? $configured : $this->dataDir() . '/zfeeder.sqlite';
    }

    public function logPath(): string
    {
        $configured = trim($this->string('log_path'));

        return $configured !== '' ? $configured : $this->dataDir() . '/zfeeder.log';
    }

    public function templatesDir(): string
    {
        return $this->projectRoot . '/templates';
    }

    public function adminTemplatesDir(): string
    {
        return $this->projectRoot . '/templates-admin';
    }

    public function userAgent(): string
    {
        $configured = trim($this->string('fetch_user_agent'));

        return $configured !== '' ? $configured : \Zfeeder\Version::userAgent();
    }

    /** True when the panel can actually be used: enabled and a password is set. */
    public function adminUsable(): bool
    {
        return $this->bool('admin_enabled') && trim($this->string('admin_password_hash')) !== '';
    }

    /** A copy with one value replaced; used when the admin saves the config screen. */
    public function with(string $key, string|int|bool $value): self
    {
        if (!Schema::has($key)) {
            throw new ConfigException('Unknown configuration key: ' . $key);
        }
        $clone = clone $this;
        $clone->values[$key] = $value;
        $clone->sources[$key] = self::SOURCE_FILE;

        return $clone;
    }

    /**
     * Values that belong in the config file: everything not set by the environment.
     *
     * @return array<string, string|int|bool>
     */
    public function persistableValues(): array
    {
        $out = [];
        foreach ($this->values as $key => $value) {
            if ($this->sourceOf($key) !== self::SOURCE_ENV) {
                $out[$key] = $value;
            }
        }
        ksort($out);

        return $out;
    }
}

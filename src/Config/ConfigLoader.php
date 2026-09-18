<?php

declare(strict_types=1);

namespace Zfeeder\Config;

use Zfeeder\Exception\ConfigException;

/**
 * Builds the effective configuration: defaults, overlaid by the JSON config
 * file, overlaid by the environment.
 *
 * The environment always wins so that a container or a shared host can pin a
 * setting that the admin panel then shows as read-only (`Config::isLockedByEnvironment`).
 * Everything is validated here, once, at boot: the rest of the program may
 * assume that an int option is an int within its range and that an enum option
 * holds one of its listed values.
 */
final class ConfigLoader
{
    /** Where the config file lives when the caller names no path. */
    public const string DEFAULT_CONFIG_RELATIVE = 'data/config.json';

    /**
     * Where to look for the config file when the caller names no path.
     *
     * The file lives in the data directory, so a deployment that mounts a
     * volume and sets ZF_DATA_DIR keeps its configuration across redeploys.
     * Reading the data directory out of the environment here, before the rest
     * of the configuration is resolved, is deliberate: the alternative is to
     * read `data_dir` from a file whose location that same setting decides,
     * which cannot be made to work. ZF_CONFIG_FILE overrides everything for
     * the unusual case of a config file kept somewhere else entirely.
     *
     * @param array<string, string|int|bool>|null $env
     */
    private static function defaultConfigPath(string $root, ?array $env): string
    {
        $explicit = self::readEnvValue('ZF_CONFIG_FILE', $env);
        if ($explicit !== null && trim($explicit) !== '') {
            return self::normalisePath(trim($explicit), $root);
        }

        $dataDir = self::readEnvValue('ZF_DATA_DIR', $env);
        if ($dataDir !== null && trim($dataDir) !== '') {
            return rtrim(self::normalisePath(trim($dataDir), $root), '/\\') . '/config.json';
        }

        return $root . '/' . self::DEFAULT_CONFIG_RELATIVE;
    }

    /**
     * One environment value, from the injected environment when tests supply
     * one and from the real environment otherwise.
     *
     * @param array<string, string|int|bool>|null $env
     */
    private static function readEnvValue(string $name, ?array $env): ?string
    {
        if ($env !== null) {
            return isset($env[$name]) ? (string) $env[$name] : null;
        }

        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $candidate) {
            if (is_scalar($candidate) && (string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        $value = getenv($name);

        return $value === false ? null : $value;
    }

    /** Spellings accepted for a boolean, matching what 1.6's config.php allowed. */
    private const array TRUE_WORDS = ['1', 'true', 'yes', 'on'];
    private const array FALSE_WORDS = ['0', 'false', 'no', 'off'];

    /**
     * @param string|null                     $configPath  JSON config file; a missing file is not an error
     * @param string|null                     $projectRoot repository root; defaults to the installed package root
     * @param array<string, string|int|bool>|null $env     environment to read instead of the real one (tests, CLI)
     *
     * @throws ConfigException on unknown keys, malformed JSON, bad types, out-of-range or unsafe values
     */
    public static function load(?string $configPath = null, ?string $projectRoot = null, ?array $env = null): Config
    {
        $root = self::normalisePath($projectRoot ?? dirname(__DIR__, 2), null);
        $path = $configPath ?? self::defaultConfigPath($root, $env);
        $options = Schema::all();

        $values = Schema::defaults();
        $sources = array_fill_keys(array_keys($values), Config::SOURCE_DEFAULT);

        foreach (self::readFile($path) as $key => $raw) {
            $values[$key] = self::coerce($key, $options[$key], $raw);
            $sources[$key] = Config::SOURCE_FILE;
        }

        foreach (self::readEnvironment($options, $env) as $key => $raw) {
            $values[$key] = self::coerce($key, $options[$key], $raw);
            $sources[$key] = Config::SOURCE_ENV;
        }

        self::assertRanges($options, $values);

        $config = Config::fromResolved($values, $sources, $root, $path);
        self::assertDataOutsideWebRoot($config);

        return $config;
    }

    /**
     * @return array<string, mixed> raw file values, already known to belong to the schema
     *
     * @throws ConfigException
     */
    private static function readFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ConfigException('Cannot read the configuration file: ' . $path);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException(sprintf('Malformed JSON in %s: %s', $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new ConfigException('The configuration file must contain a JSON object: ' . $path);
        }

        $out = [];
        /** @var mixed $value */
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || !Schema::has($key)) {
                throw new ConfigException(sprintf('Unknown configuration key "%s" in %s.', (string) $key, $path));
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Reads one variable per schema option.
     *
     * An environment variable set to the empty string is treated as "not set"
     * for typed options, because platforms routinely export empty placeholders
     * and an empty string is not a number, a boolean or an enum member. For
     * string options the empty value is kept: it is how `ZF_URL=` asks for the
     * URL to be detected again after the config file pinned one.
     *
     * @param array<string, array<string, mixed>> $options
     * @param array<string, string|int|bool>|null $env
     *
     * @return array<string, string|int|bool>
     */
    private static function readEnvironment(array $options, ?array $env): array
    {
        $out = [];
        foreach ($options as $key => $option) {
            $name = is_string($option['env']) ? $option['env'] : '';
            $value = $env !== null ? ($env[$name] ?? null) : self::realEnv($name);
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '' && $option['type'] !== Schema::TYPE_STRING) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** getenv() first, then the superglobals, so CLI and web agree. */
    private static function realEnv(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        $value = getenv($name);
        if (is_string($value)) {
            return $value;
        }
        foreach ([$_ENV, $_SERVER] as $bag) {
            if (isset($bag[$name]) && is_scalar($bag[$name])) {
                return (string) $bag[$name];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $option
     *
     * @throws ConfigException
     */
    private static function coerce(string $key, array $option, mixed $raw): string|int|bool
    {
        $type = is_string($option['type']) ? $option['type'] : Schema::TYPE_STRING;

        return match ($type) {
            Schema::TYPE_BOOL => self::toBool($key, $raw),
            Schema::TYPE_INT => self::toInt($key, $raw),
            Schema::TYPE_ENUM => self::toEnum($key, $option, $raw),
            default => self::toString($key, $raw),
        };
    }

    private static function toBool(string $key, mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) && ($raw === 0 || $raw === 1)) {
            return $raw === 1;
        }
        if (is_string($raw)) {
            $word = strtolower(trim($raw));
            if (in_array($word, self::TRUE_WORDS, true)) {
                return true;
            }
            if (in_array($word, self::FALSE_WORDS, true)) {
                return false;
            }
        }

        throw self::typeError($key, 'bool', $raw, ' Use one of 1/0, true/false, yes/no, on/off.');
    }

    private static function toInt(string $key, mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^[+-]?\d+$/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        throw self::typeError($key, 'int', $raw, '');
    }

    /**
     * @param array<string, mixed> $option
     */
    private static function toEnum(string $key, array $option, mixed $raw): string
    {
        /** @var list<string> $values */
        $values = is_array($option['values'] ?? null) ? array_values(array_filter($option['values'], 'is_string')) : [];
        if (is_string($raw) && in_array(trim($raw), $values, true)) {
            return trim($raw);
        }
        if (!is_string($raw)) {
            throw self::typeError($key, 'enum', $raw, ' Allowed: ' . implode(', ', $values) . '.');
        }

        throw new ConfigException(sprintf(
            'Configuration key "%s" must be one of: %s. Got "%s".',
            $key,
            implode(', ', $values),
            $raw,
        ));
    }

    private static function toString(string $key, mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }

        throw self::typeError($key, 'string', $raw, '');
    }

    private static function typeError(string $key, string $expected, mixed $raw, string $hint): ConfigException
    {
        return new ConfigException(sprintf(
            'Configuration key "%s" expects %s, got %s.%s',
            $key,
            $expected,
            get_debug_type($raw),
            $hint,
        ));
    }

    /**
     * @param array<string, array<string, mixed>> $options
     * @param array<string, string|int|bool>      $values
     *
     * @throws ConfigException
     */
    private static function assertRanges(array $options, array $values): void
    {
        foreach ($options as $key => $option) {
            if ($option['type'] !== Schema::TYPE_INT) {
                continue;
            }
            $value = $values[$key];
            if (!is_int($value)) {
                continue;
            }
            $min = is_int($option['min'] ?? null) ? $option['min'] : null;
            $max = is_int($option['max'] ?? null) ? $option['max'] : null;
            if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
                throw new ConfigException(sprintf(
                    'Configuration key "%s" must be between %s and %s, got %d.',
                    $key,
                    $min ?? '-inf',
                    $max ?? 'inf',
                    $value,
                ));
            }
        }
    }

    /**
     * The data directory holds the subscription files, the cache and the config
     * itself. Under `public/` a web server would happily serve all three, so
     * refuse to boot rather than leak them.
     *
     * @throws ConfigException
     */
    private static function assertDataOutsideWebRoot(Config $config): void
    {
        $root = $config->projectRoot();
        $public = self::normalisePath($root . '/public', $root);

        $dirs = [
            'data_dir' => $config->dataDir(),
            'categories_dir' => $config->categoriesDir(),
            'cache_dir' => $config->cacheDir(),
        ];
        foreach ($dirs as $key => $dir) {
            $resolved = self::normalisePath($dir, $root);
            if ($resolved === $public || str_starts_with($resolved, $public . '/')) {
                throw new ConfigException(sprintf(
                    'Configuration key "%s" resolves to "%s", inside the web root "%s". '
                    . 'Put it outside public/ so that subscriptions, cache and config cannot be downloaded.',
                    $key,
                    $resolved,
                    $public,
                ));
            }
        }
    }

    /**
     * Absolute, symlink-resolved where possible, with `.` and `..` removed so
     * that a path which does not exist yet can still be compared.
     */
    private static function normalisePath(string $path, ?string $base): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            $path = '.';
        }
        if (!str_starts_with($path, '/') && $base !== null) {
            $path = rtrim($base, '/') . '/' . $path;
        }

        $real = realpath($path);
        if (is_string($real)) {
            return rtrim(str_replace('\\', '/', $real), '/');
        }

        $absolute = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return ($absolute ? '/' : '') . implode('/', $parts);
    }
}

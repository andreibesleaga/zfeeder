<?php

declare(strict_types=1);

namespace Zfeeder\Render;

use Zfeeder\Config\Config;
use Zfeeder\Exception\TemplateException;

/**
 * Turns a user supplied template spec into a file inside `templates/`.
 *
 * 1.6 built the path by string concatenation straight from `$_GET['zftemplate']`
 * (`templates/ . $_GET['zftemplate'] . .html`), which was a directory traversal
 * hole. Here the spec has to survive a name pattern *and* a realpath
 * containment check before the file is opened.
 */
final class TemplateLocator
{
    /** The only two template sets that exist; anything else is rejected. */
    public const array SETS = ['classic', 'modern'];

    /**
     * Template names are matched case-insensitively so the 2004 spelling
     * `RiJ` still resolves, even though the file on disk is `rij.html`.
     */
    private const string NAME_PATTERN = '/^[a-z0-9_-]{1,40}$/i';

    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{set: string, name: string, path: string} */
    public function resolve(string $spec, ?string $defaultSet = null): array
    {
        $spec = trim($spec);
        if ($spec === '') {
            throw new TemplateException('No template was requested.');
        }

        $set = $defaultSet ?? $this->config->string('template_set');
        $name = $spec;
        if (str_contains($spec, '/')) {
            $parts = explode('/', $spec);
            if (count($parts) !== 2) {
                throw new TemplateException(sprintf('Invalid template "%s": expected "set/name".', $spec));
            }
            [$set, $name] = $parts;
        }

        if (!in_array($set, self::SETS, true)) {
            throw new TemplateException(sprintf('Unknown template set "%s".', $set));
        }
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new TemplateException(sprintf('Invalid template name "%s".', $name));
        }

        $root = $this->root();
        // `RiJ` and `rij` name the same template; try the spelling as given first.
        foreach ([$name, strtolower($name)] as $candidate) {
            $path = $root . '/' . $set . '/' . $candidate . '.html';
            $real = realpath($path);
            if ($real === false || !is_file($real)) {
                continue;
            }
            // Belt and braces: even though the name pattern forbids "." and "/",
            // confirm the resolved file really lives under templates/.
            if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                throw new TemplateException(sprintf('Template "%s" resolves outside the templates directory.', $spec));
            }

            return ['set' => $set, 'name' => $candidate, 'path' => $real];
        }

        throw new TemplateException(sprintf('Unknown template "%s/%s".', $set, $name));
    }

    /** Absolute path of the template file for `$spec`. */
    public function locate(string $spec, ?string $defaultSet = null): string
    {
        return $this->resolve($spec, $defaultSet)['path'];
    }

    /**
     * Every template that can be rendered, grouped by set, for the admin
     * screen and the demo gallery.
     *
     * @return array<string, list<string>>
     */
    public function available(): array
    {
        $out = [];
        foreach (self::SETS as $set) {
            $names = [];
            $dir = $this->root() . '/' . $set;
            $files = is_dir($dir) ? glob($dir . '/*.html') : false;
            foreach ($files === false ? [] : $files as $file) {
                $name = basename($file, '.html');
                if (preg_match(self::NAME_PATTERN, $name) === 1) {
                    $names[] = $name;
                }
            }
            sort($names);
            $out[$set] = $names;
        }

        return $out;
    }

    private function root(): string
    {
        $root = realpath($this->config->templatesDir());
        if ($root === false) {
            throw new TemplateException('The templates directory does not exist: ' . $this->config->templatesDir());
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }
}

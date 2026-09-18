<?php

declare(strict_types=1);

namespace Zfeeder\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zfeeder\Kernel;
use Zfeeder\Render\RenderRequest;
use Zfeeder\Version;

/**
 * The demonstration site.
 *
 * It exists for the same reason the 2004 package shipped demo pages: the
 * quickest way to explain what a template-driven aggregator does is to show
 * the same subscriptions rendered several different ways on one page. Each
 * demo below is the 2026 counterpart of one of the files in the 1.6 zip.
 */
final class DemoController
{
    private const array DEMOS = [
        'one-line' => ['One line in any page', 'The whole product in a single include, the way 2004 did it.', 'demo.php'],
        'css' => ['A CSS template', 'No tables: the layout comes entirely from the stylesheet.', 'demo_css.php'],
        'multiple' => ['Several categories at once', 'Three categories, three templates, one page.', 'demo_multiple.php'],
        'positions' => ['Placing feeds by position', 'Individual subscriptions pulled out and placed separately.', 'demo_positions.php'],
        'categories' => ['Switching categories', 'A category menu beside the output.', 'demo_categories.php'],
        'aggregator' => ['The aggregator', 'A reading layout: sources on the left, articles on the right.', 'demo_frames.php'],
    ];

    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $set = $this->set($request);
        $locator = $this->kernel->templateLocator();
        $available = $locator->available();
        $categories = $this->kernel->subscriptions()->categories();

        $demoCards = '';
        foreach (self::DEMOS as $slug => [$title, $blurb, $origin]) {
            $demoCards .= sprintf(
                '<li><a class="card" href="/demos/%s?set=%s"><h3>%s</h3><p>%s</p>'
                . '<p class="origin">2004: <code>%s</code></p></a></li>',
                $this->e($slug),
                $this->e($set),
                $this->e($title),
                $this->e($blurb),
                $this->e($origin),
            );
        }

        $templateLinks = '';
        foreach ($available[$set] ?? [] as $name) {
            $templateLinks .= sprintf(
                '<li><a href="/demos/template/%s/%s">%s</a></li>',
                $this->e($set),
                $this->e($name),
                $this->e($name),
            );
        }

        $categoryLinks = '';
        foreach ($categories as $category) {
            $categoryLinks .= sprintf('<li><code>%s</code></li>', $this->e($category));
        }

        $otherSet = $set === 'modern' ? 'classic' : 'modern';
        $body = <<<HTML
            <header class="hero">
              <h1>zFeeder <span>{$this->e(Version::NUMBER)}</span></h1>
              <p class="lead">The PHP feed aggregator from 2004, rebuilt. Subscriptions live in OPML files,
                 output is driven by templates, and one line of PHP puts it in a page.</p>
              <p class="switch">
                Template set: <strong>{$this->e($set)}</strong> &middot;
                <a href="/?set={$this->e($otherSet)}">switch to {$this->e($otherSet)}</a>
              </p>
            </header>

            <section>
              <h2>Demonstrations</h2>
              <ul class="cards">{$demoCards}</ul>
            </section>

            <section>
              <h2>Templates in the <em>{$this->e($set)}</em> set</h2>
              <ul class="chips">{$templateLinks}</ul>
            </section>

            <section>
              <h2>Subscription categories</h2>
              <ul class="chips">{$categoryLinks}</ul>
              <p>Data comes from OPML files, one per category, the same format the 2004 version used.</p>
            </section>

            <section>
              <h2>Using it</h2>
              <pre tabindex="0"><code>&lt;?php echo zfeeder(['category' =&gt; 'news', 'template' =&gt; 'modern/cards']); ?&gt;</code></pre>
              <p>Not a PHP site? Use <a href="/embed?template={$this->e($set)}/list">/embed</a> for an HTML fragment
                 or <a href="/api/feeds">/api/feeds</a> for JSON.</p>
            </section>

            <section>
              <h2>More</h2>
              <ul class="chips">
                <li><a href="/admin">Administration panel</a></li>
                <li><a href="https://github.com/andreibesleaga/zfeeder">Source code</a></li>
                <li><a href="https://sourceforge.net/projects/zvonnews/">The original, on SourceForge</a></li>
              </ul>
            </section>
            HTML;

        return Responder::html($this->page('zFeeder ' . Version::NUMBER, $body, $set));
    }

    public function demo(ServerRequestInterface $request, string $slug): ResponseInterface
    {
        if (!isset(self::DEMOS[$slug])) {
            return (new ErrorHandler($this->kernel->config(), $this->kernel->logger()))->notFound();
        }

        [$title, $blurb, $origin] = self::DEMOS[$slug];
        $set = $this->set($request);
        $query = $request->getQueryParams();
        $category = isset($query['category']) && is_string($query['category']) ? $query['category'] : null;

        $body = match ($slug) {
            'one-line' => $this->oneLine($set, $category, $slug),
            'css' => $this->single($set, 'simplecss', $category, $slug),
            'multiple' => $this->multiple($set, $slug),
            'positions' => $this->positions($set, $category, $slug),
            'categories' => $this->categories($set, $category, $slug),
            default => $this->aggregator($set, $category, $slug),
        };

        $intro = sprintf(
            '<header class="hero"><p class="back"><a href="/?set=%s">&larr; all demonstrations</a></p>'
            . '<h1>%s</h1><p class="lead">%s</p><p class="origin">The 2004 package shipped this as <code>%s</code>.</p></header>',
            $this->e($set),
            $this->e($title),
            $this->e($blurb),
            $this->e($origin),
        );

        return Responder::html($this->page($title . ' — zFeeder', $intro . $body, $set));
    }

    public function template(ServerRequestInterface $request, string $set, string $name): ResponseInterface
    {
        $query = $request->getQueryParams();
        $category = isset($query['category']) && is_string($query['category']) ? $query['category'] : null;

        try {
            $html = $this->render($set . '/' . $name, $category, '/demos/template/' . $set . '/' . $name);
        } catch (\Throwable $e) {
            return (new ErrorHandler($this->kernel->config(), $this->kernel->logger()))->handle($e);
        }

        $others = '';
        foreach ($this->kernel->templateLocator()->available()[$set] ?? [] as $other) {
            $current = $other === $name ? ' aria-current="page"' : '';
            $others .= sprintf(
                '<li><a href="/demos/template/%s/%s"%s>%s</a></li>',
                $this->e($set),
                $this->e($other),
                $current,
                $this->e($other),
            );
        }

        $body = sprintf(
            '<header class="hero"><p class="back"><a href="/?set=%s">&larr; all templates</a></p>'
            . '<h1>%s <span>%s</span></h1></header>'
            . '<nav aria-label="Templates"><ul class="chips">%s</ul></nav>'
            . '<section class="output" tabindex="0">%s</section>',
            $this->e($set),
            $this->e($name),
            $this->e($set),
            $others,
            $html,
        );

        return Responder::html($this->page($name . ' — zFeeder templates', $body, $set));
    }

    // ---- individual demonstrations -------------------------------------

    private function oneLine(string $set, ?string $category, string $slug): string
    {
        $default = $set === 'modern' ? 'cards' : 'bluelogos';

        return '<section><pre tabindex="0"><code>&lt;?php echo zfeeder([\'category\' =&gt; \'' . $this->e($category ?? 'zfeeder')
            . '\', \'template\' =&gt; \'' . $this->e($set . '/' . $default) . '\']); ?&gt;</code></pre></section>'
            . '<section class="output" tabindex="0">' . $this->render($set . '/' . $default, $category, '/demos/' . $slug) . '</section>';
    }

    private function single(string $set, string $template, ?string $category, string $slug): string
    {
        return '<section class="output" tabindex="0">' . $this->render($set . '/' . $template, $category, '/demos/' . $slug) . '</section>';
    }

    private function multiple(string $set, string $slug): string
    {
        $categories = array_slice($this->kernel->subscriptions()->categories(), 0, 3);
        $templates = $set === 'modern' ? ['list', 'cards', 'simplegray'] : ['simplegray', 'ampheta', 'simpleblue'];

        $columns = '';
        foreach ($categories as $i => $category) {
            $template = $templates[$i] ?? $templates[0];
            $columns .= sprintf(
                '<div class="column"><h2>%s <small>%s</small></h2>%s</div>',
                $this->e($category),
                $this->e($template),
                $this->render($set . '/' . $template, $category, '/demos/' . $slug),
            );
        }

        return '<section class="output columns" tabindex="0">' . $columns . '</section>';
    }

    private function positions(string $set, ?string $category, string $slug): string
    {
        $template = 'titlebox';
        $first = $this->render($set . '/' . $template, $category, '/demos/' . $slug, 'p1');
        $second = $this->render($set . '/' . $template, $category, '/demos/' . $slug, 'p2');

        return '<section class="output split" tabindex="0">'
            . '<div><h2>Position 1</h2>' . $first . '</div>'
            . '<div><h2>Position 2</h2>' . $second . '</div>'
            . '</section>';
    }

    private function categories(string $set, ?string $category, string $slug): string
    {
        $current = $category ?? $this->kernel->config()->string('default_category');
        $menu = '';
        foreach ($this->kernel->subscriptions()->categories() as $name) {
            $isCurrent = $name === $current ? ' aria-current="page"' : '';
            $menu .= sprintf(
                '<li><a href="/demos/%s?set=%s&amp;category=%s"%s>%s</a></li>',
                $this->e($slug),
                $this->e($set),
                $this->e($name),
                $isCurrent,
                $this->e($name),
            );
        }

        $template = 'infojunkie';

        return '<section class="output with-menu" tabindex="0">'
            . '<nav aria-label="Categories"><ul class="menu">' . $menu . '</ul></nav>'
            . '<div>' . $this->render($set . '/' . $template, $category, '/demos/' . $slug) . '</div>'
            . '</section>';
    }

    private function aggregator(string $set, ?string $category, string $slug): string
    {
        $current = $category ?? $this->kernel->config()->string('default_category');
        $sources = '';
        foreach ($this->kernel->subscriptions()->categories() as $name) {
            $isCurrent = $name === $current ? ' aria-current="page"' : '';
            $sources .= sprintf(
                '<li><a href="/demos/%s?set=%s&amp;category=%s"%s>%s</a></li>',
                $this->e($slug),
                $this->e($set),
                $this->e($name),
                $isCurrent,
                $this->e($name),
            );
        }

        // 2004 used two frames. A grid does the same job without the frames.
        return '<section class="output aggregator" tabindex="0">'
            . '<aside><h2>Sources</h2><nav aria-label="Sources"><ul class="menu">' . $sources . '</ul></nav></aside>'
            . '<div class="reading">' . $this->render($set . '/mainframe', $category, '/demos/' . $slug) . '</div>'
            . '</section>';
    }

    // ---- helpers -------------------------------------------------------

    private function render(string $template, ?string $category, string $selfUrl, ?string $positions = null): string
    {
        return $this->kernel->feeds()->render(new RenderRequest(
            category: $category,
            template: $template,
            positions: $positions,
            selfUrl: $selfUrl,
        ));
    }

    private function set(ServerRequestInterface $request): string
    {
        $query = $request->getQueryParams();
        $set = $query['set'] ?? null;
        if (is_string($set) && in_array($set, ['classic', 'modern'], true)) {
            return $set;
        }

        return $this->kernel->config()->string('template_set');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function page(string $title, string $body, string $set): string
    {
        $safeTitle = $this->e($title);
        $year = gmdate('Y');

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$safeTitle}</title>
            <meta name="description" content="zFeeder, the 2004 PHP feed aggregator, rebuilt for 2026.">
            <link rel="stylesheet" href="/assets/modern/zf.css">
            <link rel="stylesheet" href="/assets/modern/zf-site.css">
            <link rel="icon" href="/assets/modern/zfeeder-logo.svg" type="image/svg+xml">
            </head>
            <body class="zf-site zf-set-{$this->e($set)}">
            <a class="skip" href="#main">Skip to content</a>
            <nav class="topbar" aria-label="Site">
              <a class="brand" href="/"><img src="/assets/modern/zfeeder-logo.svg" alt="" width="28" height="28"> zFeeder</a>
              <ul>
                <li><a href="/">Demonstrations</a></li>
                <li><a href="/admin">Admin</a></li>
                <li><a href="https://github.com/andreibesleaga/zfeeder">Code</a></li>
              </ul>
            </nav>
            <main id="main" class="zf">{$body}</main>
            <footer class="sitefoot">
              <p>zFeeder {$this->e(Version::NUMBER)} &middot; GPL-2.0-or-later &middot;
                 originally released in 2004 on
                 <a href="https://sourceforge.net/projects/zvonnews/">SourceForge</a> &middot;
                 &copy; 2003&ndash;{$year} Andrei N. Besleaga</p>
            </footer>
            </body>
            </html>
            HTML;
    }
}

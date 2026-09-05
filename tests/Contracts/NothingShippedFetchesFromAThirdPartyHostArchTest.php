<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Modules\Core\Public\Controllers\HealthController;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-health-page-nobody-here-wrote-that-fetched-from-two-cdns
 */

// The product's claim is a zero-by-default outbound surface, so a subresource
// naming a third party is not a performance choice: opening the page hands that
// party the reader's IP address and the moment they looked, with nothing on
// screen to say it happened and no setting that would have stopped it.

// Only the attributes a browser fetches without being asked. `<a href>` is
// deliberately absent — a reader clicking through to the Google, Entra or
// Enable Banking console has chosen to leave — and so is `xmlns`, which is a
// name rather than an address. That is why this reads attributes off parsed
// elements instead of hunting `https://` through the text of a template.
const OUTBOUND_FETCHED_ATTRIBUTES = [
    'audio' => ['src'],
    'embed' => ['src'],
    'iframe' => ['src'],
    'image' => ['href'],
    'img' => ['src', 'srcset'],
    'input' => ['src'],
    'link' => ['href'],
    'object' => ['data'],
    'script' => ['src'],
    'source' => ['src', 'srcset'],
    'track' => ['src'],
    'use' => ['href'],
    'video' => ['poster', 'src'],
];

// Every spelling a probe is reached by, matched as a whole URI so the Dev
// Console's own `/sync-health` screen is not mistaken for one.
const OUTBOUND_PROBE_URIS = ['up', 'health', 'healthz', 'health-check', 'healthcheck', 'livez', 'ping', 'readyz', 'status'];

const OUTBOUND_BOOTSTRAP_ROOTS = ['bootstrap/app.php', 'mobile-app/bootstrap/app.php'];

/** @return list<array{path: string, source: string}> */
function outboundShippedFiles(string $extension): array
{
    $found = [];

    foreach (['Modules', 'resources', 'mobile-app/resources'] as $root) {
        $absolute = base_path($root);

        if (! is_dir($absolute)) {
            continue;
        }

        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($walk as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! str_ends_with($path, $extension)) {
                continue;
            }

            if (str_contains($path, '/tests/') || str_contains($path, '/node_modules/')) {
                continue;
            }

            $found[] = [
                'path' => str_replace(base_path().'/', '', $path),
                'source' => (string) file_get_contents($path),
            ];
        }
    }

    usort($found, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

    return $found;
}

// Loopback is not a third party: both shells load the app from it. Null for a
// Blade expression, a rooted path or a `data:` payload, none of which name a
// host at all.
function outboundThirdPartyHost(string $value): ?string
{
    $trimmed = trim($value);

    if (! PatternScan::matches('~^(?:https?:)?//~i', $trimmed)) {
        return null;
    }

    $host = parse_url(str_starts_with($trimmed, '//') ? 'https:'.$trimmed : $trimmed, PHP_URL_HOST);

    if (! is_string($host) || $host === '') {
        return null;
    }

    return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '[::1]'], true) ? null : $host;
}

it('names no third-party host in anything a browser fetches on its own', function (): void {
    $offenders = [];
    $templates = 0;
    $elements = 0;

    foreach (outboundShippedFiles('.blade.php') as $template) {
        $templates++;

        foreach (MarkupSource::tags($template['source']) as $element) {
            $elements++;

            foreach (OUTBOUND_FETCHED_ATTRIBUTES[strtolower($element->name)] ?? [] as $attribute) {
                $host = outboundThirdPartyHost($element->attribute($attribute) ?? '');

                if ($host === null) {
                    continue;
                }

                $offenders[] = $template['path'].':'.$element->line($template['source'])
                    .' — <'.$element->name.' '.$attribute.'> fetches from '.$host;
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "A page this app serves must cost the reader no request to anyone else.\n".
        "Vendor the asset into resources/ and let Vite build it, or inline it as\n".
        "a data: URI. Offenders:\n  ".implode("\n  ", $offenders),
    );

    // A walk that examined nothing reads exactly like a clean tree, and this
    // one is a lexer rather than a pattern, so a parse that stopped early would
    // report the same empty list a repo with nothing to find does.
    expect($templates)->toBeGreaterThan(100);
    expect($elements)->toBeGreaterThan(1000);
});

it('pulls no stylesheet, font or script into a bundle from a third party', function (): void {
    $offenders = [];

    foreach (outboundShippedFiles('.css') as $stylesheet) {
        $targets = PatternScan::all('/url\(\s*[\'"]?([^\'")]+)|@import\s+[\'"]([^\'"]+)/i', $stylesheet['source']);

        foreach (array_merge($targets[1], $targets[2]) as $target) {
            $host = outboundThirdPartyHost($target);

            if ($host !== null) {
                $offenders[] = $stylesheet['path'].' — '.$host;
            }
        }
    }

    foreach (outboundShippedFiles('.js') as $script) {
        foreach (PatternScan::all('/[\'"`](https?:\/\/[^\'"`\s]+)/i', $script['source'])[1] as $literal) {
            $host = outboundThirdPartyHost($literal);

            if ($host !== null) {
                $offenders[] = $script['path'].' — '.$host;
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "A bundled stylesheet or script reaching a third party is the same leak\n".
        "as a tag in a template, one build step further from where anybody looks.\n".
        "Offenders:\n  ".implode("\n  ", $offenders),
    );
});

// This is the blind spot the two rules above cannot cover. They walk `Modules/`
// and `resources/` — the trees this repository writes — and the page that broke
// the claim was Laravel's own, reached because a bootstrap root asked for it by
// name. A template nobody here wrote is still a page this app serves.
it('asks the framework for no health page at any bootstrap root', function (): void {
    $offenders = [];

    foreach (OUTBOUND_BOOTSTRAP_ROOTS as $root) {
        $path = base_path($root);

        if (! is_file($path)) {
            continue;
        }

        if (PatternScan::matches('/^\s*health:/m', (string) file_get_contents($path))) {
            $offenders[] = $root;
        }
    }

    expect($offenders)->toBe(
        [],
        "`health:` in withRouting() serves Laravel's stock health page, which\n".
        "preconnects to fonts.bunny.net, pulls a stylesheet from it and a script\n".
        "from cdn.jsdelivr.net, and prints a render time that no probe can\n".
        "equality-check. It lives under vendor/, so no scan of our own tree sees\n".
        "it. This application answers /health instead. Offenders:\n  ".
        implode("\n  ", $offenders),
    );
});

it('serves exactly one health endpoint, and it is one this repository wrote', function (): void {
    /** @var Router $router */
    $router = app(Router::class);

    $probes = [];

    foreach ($router->getRoutes() as $route) {
        $uri = strtolower(trim($route->uri(), '/'));

        if (in_array($uri, OUTBOUND_PROBE_URIS, true)) {
            $probes[$uri] = $route->getActionName();
        }
    }

    expect(array_keys($probes))->toBe(
        ['health'],
        'Two probe endpoints are two answers to one question, and the spec names '.
        'one. Found: '.(($probes === []) ? 'none at all' : implode(', ', array_keys($probes))),
    );

    expect($probes['health'] ?? '')->toStartWith(HealthController::class);
});

it('goes red on the three lines that broke the claim and stays quiet on a link the reader clicks', function (): void {
    $planted = <<<'BLADE'
        <a href="https://console.cloud.google.com/">Google Cloud console</a>
        <svg xmlns="http://www.w3.org/2000/svg"><use href="#icon" /></svg>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,600&display=swap" rel="stylesheet" />
        <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
        <script src="/build/app.js"></script>
        BLADE;

    $found = [];

    foreach (MarkupSource::tags($planted) as $element) {
        foreach (OUTBOUND_FETCHED_ATTRIBUTES[strtolower($element->name)] ?? [] as $attribute) {
            $host = outboundThirdPartyHost($element->attribute($attribute) ?? '');

            if ($host !== null) {
                $found[] = $host;
            }
        }
    }

    expect($found)->toBe(['fonts.bunny.net', 'fonts.bunny.net', 'cdn.jsdelivr.net']);
});

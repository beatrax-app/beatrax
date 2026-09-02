<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function emptyInstallPages(): array
{
    /** @var Router $router */
    $router = app(Router::class);

    $pages = [];
    foreach ($router->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        $uri = $route->uri();
        if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{')) {
            continue;
        }
        if (preg_match('#^(_|livewire|storage|api|sanctum|flux|telescope|horizon)#', $uri) === 1) {
            continue;
        }
        // Static files, and the endpoints whose whole job is to end a session
        // or answer a probe: none of them renders a page to read.
        if (preg_match('#\.(png|js|css|webmanifest)$#', $uri) === 1 || in_array($uri, ['logout', 'up', 'health'], true)) {
            continue;
        }

        $action = $route->getAction('uses');
        // Vendor packages register routes of their own, some of them gated on
        // a licence this repo does not carry. This guard is about the pages
        // this repo renders, so it asks only about those.
        if (is_string($action) && ! str_starts_with(ltrim($action, '\\'), 'Modules\\')) {
            continue;
        }

        $pages[] = $uri;
    }

    return $pages;
}

// Reading a page the way a reader does: text nodes only. Substring-matching
// the HTML instead reports every page, because Alpine writes "undefined" in
// every guard it emits — and stripping tags with a regex does not work here,
// since an x-data attribute holding an arrow function contains its own '>'.
function emptyInstallVisibleText(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    foreach (['script', 'style', 'template', 'noscript'] as $tag) {
        $nodes = iterator_to_array($dom->getElementsByTagName($tag));
        foreach ($nodes as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    return (string) $dom->textContent;
}

// A seeded demo database exercises every page with rows in every table. The
// state a new install actually starts in — an account and nothing else — is
// the one path no walk has covered, and it is the first thing a reader sees.
it('opens every page for an account that has no data yet', function (): void {
    $user = User::query()->create([
        'username' => 'empty-install',
        'password' => 'test-password',
        'is_developer' => false,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // What an empty aggregate looks like when nothing guarded the divide or
    // the format: a NaN in a chart, an Infinity in a rate, a bare 'undefined'
    // or 'null' where a name goes.
    $rot = ['NaN', 'Infinity', 'undefined', '-0.00'];

    $pages = emptyInstallPages();
    $broken = [];
    foreach ($pages as $uri) {
        try {
            $response = $this->actingAs($user)->get('/'.$uri);
        } catch (Throwable $e) {
            $broken[] = '/'.$uri.' threw '.$e::class.': '.$e->getMessage();

            continue;
        }

        $status = $response->getStatusCode();
        if ($status >= 500) {
            $broken[] = '/'.$uri.' returned '.$status;

            continue;
        }
        if ($status !== 200) {
            continue;
        }

        $text = emptyInstallVisibleText((string) $response->getContent());
        foreach ($rot as $needle) {
            if (str_contains($text, $needle)) {
                $broken[] = '/'.$uri.' renders "'.$needle.'"';
            }
        }
    }

    expect($pages)->not->toBeEmpty();
    expect($broken)->toBe([], implode("\n", ['Pages that break with no data:', ...$broken]));
});

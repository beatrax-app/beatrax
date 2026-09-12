<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Router;
use Illuminate\View\Factory as ViewFactory;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\MarkupElement;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Symfony\Component\Finder\Finder;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-bare-envsafe-area-inset--on-android
 */
function safeAreaTemplates(): Finder
{
    return Finder::create()
        ->files()
        ->in([base_path('Modules'), base_path('resources/views')])
        ->name('*.blade.php');
}

// Blade comments are prose about the seam, not a use of it. Reading them as
// markup fails the build on a template that documents the anti-pattern, which
// is the opposite of what every arm here is for. Their line breaks stay, so an
// offender's line number still points at the line the file really has.
function safeAreaMarkup(string $contents): string
{
    return PatternScan::replaceCallback(
        '/\{\{--.*?--\}\}/s',
        static fn (array $comment): string => str_repeat("\n", substr_count((string) $comment[0], "\n")),
        $contents,
    );
}

/**
 * @return list<string>
 */
function safeAreaClassesIn(string $template): array
{
    $classes = [];

    $attributes = PatternScan::all('/class="([^"]*)"/', safeAreaMarkup($template));

    foreach ($attributes[1] as $attribute) {
        foreach (PatternScan::split('/\s+/', trim($attribute)) as $class) {
            if ($class !== '') {
                $classes[] = $class;
            }
        }
    }

    return $classes;
}

// A screen wearing a class app.css defines as padding an edge has reserved
// that seam as surely as one spelling the token inline. Only a lone class
// selector counts: in `.a .b` neither half pads anything on its own, and
// crediting both would pass a template that carries just the outer one.
/**
 * @return array<string, list<string>>
 */
function safeAreaClassEdges(): array
{
    $css = PatternScan::replace(
        '#/\*.*?\*/#s',
        '',
        (string) file_get_contents(base_path('resources/css/app.css')),
    );

    // A body with no brace inside it is an innermost block, so what sits in
    // front of it is a real selector rather than an @media or @layer head.
    // Comments go first: they sit between the previous rule and this one, so
    // they land inside the selector capture and no rule looks like a class.
    $rules = PatternScan::sets('/([^{}]+)\{([^{}]*)\}/s', $css);

    $map = [];

    foreach ($rules as [, $selector, $body]) {
        if (preg_match('/^\s*\.([A-Za-z0-9_-]+)\s*$/', $selector, $name) !== 1) {
            continue;
        }

        foreach (['top', 'bottom', 'left', 'right'] as $edge) {
            if (preg_match('/padding-'.$edge.'\s*:[^;]*var\(--safe-'.$edge.'\)/', $body) === 1) {
                $map[$name[1]][] = $edge;
            }
        }
    }

    return array_map(
        static fn (array $edges): array => array_values(array_unique($edges)),
        $map,
    );
}

// Case-insensitive with optional inner whitespace: CSS function names are ASCII
// case-insensitive and `env( safe-area-inset-top )` is the same declaration, so
// a literal lowercase match let both through.
function safeAreaEnvReadsIn(string $template): bool
{
    return preg_match('/\benv\(\s*safe-area-inset-/i', safeAreaMarkup($template)) === 1;
}

it('never reads env(safe-area-inset-*) outside the seam that fills it in', function (): void {
    $offenders = [];
    $read = 0;

    foreach (safeAreaTemplates() as $file) {
        $read++;

        if (safeAreaEnvReadsIn((string) $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($read)->toBeGreaterThan(150, 'The template walk read almost nothing, so a clean answer below is the walk being broken rather than the templates being right.');

    expect($offenders)->toBe([], implode("\n", [
        'These templates read env(safe-area-inset-*) directly:',
        ...$offenders,
        '',
        'iOS fills env() in; the Android shell leaves it at zero and injects',
        '--inset-* onto :root instead. A bare env() is therefore a padding of',
        'zero on Android — no error, no warning, content simply under the system',
        'bars. Use var(--safe-top|bottom|left|right), which resources/css/app.css',
        'defines as max() of the two sources and is correct on both platforms.',
    ]));
});

it('reads a bare env() a template really writes and not one it documents', function (): void {
    $bare = '<div style="padding-top: env(safe-area-inset-top)">';
    // The two the reader has to keep telling apart: the spelling a literal
    // lowercase match let through, and the prose in a Blade comment that a
    // template documenting this very rule carries.
    $spaced = '<div style="padding-top: ENV( safe-area-inset-top )">';
    $documented = '{{-- never env(safe-area-inset-top): the Android shell leaves it at zero --}}'."\n"
        .'<div class="safe-screen">';
    $seam = '<div style="padding-top: var(--safe-top)">';

    expect(safeAreaEnvReadsIn($bare))->toBeTrue('The reader stopped seeing the bare env() this rule exists to forbid.')
        ->and(safeAreaEnvReadsIn($spaced))->toBeTrue('An uppercase env with inner whitespace is the same declaration and is being let through.')
        ->and(safeAreaEnvReadsIn($documented))->toBeFalse('Prose about the anti-pattern is being read as the anti-pattern.')
        ->and(safeAreaEnvReadsIn($seam))->toBeFalse('A template padding through the seam is being reported as reading env() directly.');
});

it('keeps the seam those templates depend on', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    // Whitespace-insensitive: the declaration is the invariant, not the
    // formatting. A byte-exact match failed all four edges on a reformat and
    // reported it as the seam having been lost.
    $collapsed = PatternScan::replace('/\s+/', '', $css);

    $missing = [];

    foreach (['top', 'bottom', 'left', 'right'] as $edge) {
        $rule = sprintf('--safe-%s: max(env(safe-area-inset-%s, 0px), var(--inset-%s, 0px))', $edge, $edge, $edge);

        if (! str_contains($collapsed, PatternScan::replace('/\s+/', '', $rule))) {
            $missing[] = $rule;
        }
    }

    expect($missing)->toBe([], implode("\n", [
        'resources/css/app.css no longer defines these as max() of both sources:',
        ...$missing,
        '',
        'Every template padding through var(--safe-*) depends on this rule to be',
        'correct on both platforms. Losing an arm of the max() does not fail here',
        'or anywhere else — it just stops padding on the platform it dropped.',
    ]));
});

// Padding reserves the strip; nothing was painting it. Under viewport-fit=cover
// the system bars are drawn OVER the page, so a screen taller than the viewport
// slid its own heading up under the clock and the two rendered on top of each
// other — at rest the same screen measured correctly.
it('paints the strip .safe-screen only reserves', function (): void {
    $collapsed = PatternScan::replace(
        '/\s+/',
        '',
        (string) file_get_contents(base_path('resources/css/app.css')),
    );

    $missing = array_values(array_filter(
        ['.safe-screen::before{', 'position:fixed', 'height:var(--safe-top)'],
        static fn (string $fragment): bool => ! str_contains($collapsed, $fragment),
    ));

    expect($missing)->toBe([], implode("\n", [
        'resources/css/app.css no longer covers the top seam on .safe-screen:',
        ...$missing,
        '',
        'The four paddings hold content clear of the system bars at scroll 0 and',
        'nowhere else. A screen that scrolls needs something standing over the',
        'strip as well — .top-bar does it for the screens that have one, and this',
        'pseudo-element does it for the screens that do not. Losing it fails no',
        'other rule here: the resting screenshot stays correct.',
    ]));
});

// A bar's height is not the status bar's height, and on the screen that typed
// it there was no bar at all: a first run reaches every step of the import
// bootstrap inside the markup layouts.app produced for a signed-out reader,
// because Livewire re-renders the component and never the layout.
it('never reserves the top bar height in place of the status-bar inset', function (): void {
    $offenders = [];
    $read = 0;

    foreach (safeAreaTemplates() as $file) {
        $read++;

        if (preg_match('/(?:padding-top|\bpt-\[)[^;"\']*var\(--top-bar-h\)/', safeAreaMarkup((string) $file->getContents())) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($read)->toBeGreaterThan(150, 'The template walk read almost nothing, so a clean answer below is the walk being broken rather than the templates being right.');

    expect($offenders)->toBe([], implode("\n", [
        'These templates reserve --top-bar-h at the top of the page:',
        ...$offenders,
        '',
        'A screen standing under .top-bar needs no top reserve at all — the bar is',
        'sticky, so it already stands in the flow and pads var(--safe-top) itself.',
        'A screen with no bar over it needs the inset, which is a device',
        'measurement and not 48px. Either way --top-bar-h is the wrong number, and',
        'it is only ever right by coincidence on the phone it was checked on.',
    ]));
});

it('pads an inset per edge rather than mirroring one of them', function (): void {
    $offenders = [];
    $read = 0;

    foreach (safeAreaTemplates() as $file) {
        $read++;

        // px- AND py-: the vertical form is the same defect and the more
        // reachable one, because the top and bottom insets differ on every
        // notched phone held in portrait.
        if (preg_match('/p[xy]-\[var\(--safe-(top|bottom|left|right)\)\]/', safeAreaMarkup((string) $file->getContents())) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($read)->toBeGreaterThan(150, 'The template walk read almost nothing, so a clean answer below is the walk being broken rather than the templates being right.');

    expect($offenders)->toBe([], implode("\n", [
        'These templates set two paddings from one edge:',
        ...$offenders,
        '',
        'px-* writes padding-left AND padding-right; py-* writes top AND bottom.',
        'Paired with a following pr-*/pb-*, the result is right only because',
        'Tailwind happens to emit the single edge after the pair; the first inset',
        'is otherwise mirrored onto the opposite edge, which genuinely differs on',
        'a notched phone. Use pl-*/pr-* and pt-*/pb-*.',
    ]));
});

// layouts.lock is a bare @yield('content') under viewport-fit=cover: it draws
// no chrome and reserves no bar, so every view it wraps is the only thing
// standing between its content and the status bar. Spelling is arm 1's job;
// this is the invariant those five views exist to satisfy.
it('pads all four edges in every view layouts.lock reserves nothing for', function (): void {
    /** @var ViewFactory $views */
    $views = app('view');

    $components = Finder::create()
        ->files()
        ->in(base_path('Modules'))
        ->name('*.php')
        ->contains("extends('layouts.lock'");

    $unpadded = [];
    $unreadable = [];
    $checked = 0;
    $classEdges = safeAreaClassEdges();

    foreach ($components as $file) {
        $relative = $file->getRelativePathname();

        // A harness that extends layouts.lock to exercise the lock screen is
        // not a screen a reader is shown, and its view is often a stub with no
        // markup at all.
        if (str_contains(str_replace('\\', '/', $relative), '/tests/')) {
            continue;
        }

        $source = (string) $file->getContents();

        if (preg_match("/->make\(\s*'([^']+)'/", $source, $match) !== 1) {
            $unreadable[] = $relative.' (no ->make(\'…\') to resolve)';

            continue;
        }

        try {
            $template = (string) file_get_contents($views->getFinder()->find($match[1]));
        } catch (InvalidArgumentException) {
            $unreadable[] = $relative.' → '.$match[1].' (no such view)';

            continue;
        }

        $checked++;

        $consumed = [];

        foreach (safeAreaClassesIn($template) as $class) {
            foreach ($classEdges[$class] ?? [] as $edge) {
                $consumed[$edge] = true;
            }
        }

        $markup = safeAreaMarkup($template);

        $edges = array_values(array_filter(
            ['top', 'bottom', 'left', 'right'],
            static fn (string $edge): bool => ! isset($consumed[$edge])
                && ! str_contains($markup, 'var(--safe-'.$edge.')'),
        ));

        if ($edges !== []) {
            $unpadded[] = $relative.' → '.$match[1].' (no '.implode(', ', $edges).')';
        }
    }

    // Six consumers today. A floor of one would let five of them fall out of
    // the Finder's sight while the rule went on reporting a clean answer.
    expect($checked)->toBeGreaterThan(3, 'Almost no layouts.lock consumer was found, so this rule checked next to nothing.');

    expect(count($classEdges))->toBeGreaterThan(3, 'app.css yielded almost no class that pads an edge, so every view below is judged as if only an inline var(--safe-*) counted.');

    expect([...$unreadable, ...$unpadded])->toBe([], implode("\n", [
        'These components extend layouts.lock without padding every edge:',
        ...$unreadable,
        ...$unpadded,
        '',
        'layouts.lock yields content straight into <body> under viewport-fit=cover.',
        'It reserves no status bar and no gesture bar, so a view under it that does',
        'not pad var(--safe-top|bottom|left|right) itself puts its own content under',
        'the system bars — on iOS and on Android both, and only on a device.',
    ]));
});

// The five screens above were the same four utilities typed out five times,
// which is how one of them came to be missing an edge. .safe-screen is where
// that string lives now; this arm is what keeps it from being retyped.
it('takes all four edges from .safe-screen rather than typing them onto an element', function (): void {
    $offenders = [];
    $read = 0;

    foreach (safeAreaTemplates() as $file) {
        $read++;

        $attributes = PatternScan::all('/class="([^"]*)"/', safeAreaMarkup((string) $file->getContents()));

        foreach ($attributes[1] as $attribute) {
            $inline = array_filter(
                ['t' => 'top', 'b' => 'bottom', 'l' => 'left', 'r' => 'right'],
                static fn (string $edge, string $side): bool => str_contains($attribute, 'p'.$side.'-[var(--safe-'.$edge.')]'),
                ARRAY_FILTER_USE_BOTH,
            );

            if (count($inline) === 4) {
                $offenders[] = $file->getRelativePathname();

                break;
            }
        }
    }

    expect($read)->toBeGreaterThan(150, 'The template walk read almost nothing, so a clean answer below is the walk being broken rather than the templates being right.');

    expect($offenders)->toBe([], implode("\n", [
        'These templates spell all four insets onto one element:',
        ...$offenders,
        '',
        'resources/css/app.css defines .safe-screen as exactly those four',
        'paddings. Use it. A screen that sits inside layouts.app <main> is a',
        'different case and must NOT wear it: the .top-bar above it already',
        'pads var(--safe-top) and stands in the flow, so a top inset there',
        'reserves the status bar twice.',
    ]));
});

// Arm 4's other half. layouts.app draws its drawer and its .top-bar under
// @auth and yields straight into <body> under @guest, so a signed-out document
// is chromeless in exactly the way a layouts.lock one is — and five of the six
// screens a signed-out reader can reach reserved nothing at all. Asked of the
// rendered document rather than of the template, because the closure routes the
// mobile shell registers name their component only inside the closure body.
/**
 * @return array{checked: int, offenders: list<string>}
 */
function safeAreaSignedOutSweep(): array
{
    $offenders = [];
    $checked = 0;

    /** @var Router $router */
    $router = app('router');

    foreach ($router->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        if (! in_array('GET', $route->methods(), true)
            || str_contains($route->uri(), '{')
            || ! in_array('web', $middleware, true)
            || in_array(Authenticate::class, $middleware, true)) {
            continue;
        }

        $response = test()->get('/'.ltrim($route->uri(), '/'));

        // A full-screen page and nothing else: the icon, manifest and
        // service-worker routes beside these answer 200 with no markup, and a
        // redirect answers with somebody else's.
        $html = $response->getStatusCode() === 200 ? (string) $response->getContent() : '';

        if (! str_contains($html, 'min-h-screen')) {
            continue;
        }

        $checked++;

        if (! str_contains($html, 'safe-screen')) {
            $offenders[] = $route->uri();
        }
    }

    return ['checked' => $checked, 'offenders' => $offenders];
}

// The other end of the same document, and the one the four-edge rule above
// says .safe-screen may not cover. .top-bar reserves var(--safe-top) and
// stands in the flow, so the top of a signed-in screen is held clear; nothing
// held the bottom, and both phone shells paint the page edge to edge.
//
// Measured on a Galaxy A51 with the page scrolled as far as it goes: 9px of
// the budget row's move button behind the navigation bar, with no scroll left
// to rescue it. On the iPhone the same seam is 34px of home-indicator.
it('reserves the bottom seam on the surface a signed-in reader reaches', function (): void {
    $reader = User::query()->create([
        'username' => 'safe-area-below',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $html = (string) $this->actingAs($reader)->get('/settings')->getContent();

    // str_contains, not toContain: a second argument to toContain is a second
    // needle, not the message, so the explanation below would be searched for
    // in the page.
    expect(str_contains($html, 'safe-below'))->toBeTrue(implode("\n", [
        'The signed-in document reserves nothing at its bottom edge.',
        '',
        "layouts.app's <main> is the only element between the page and the",
        'navigation bar, and the phone shells paint the page under it. A screen',
        'cannot reserve this for itself: Livewire re-renders the component and',
        'never the layout, and a page that happens to end in whitespace today is',
        'not a guarantee about the one that ends in a button tomorrow.',
    ]));
});

// .safe-screen pads four edges and would reserve the status bar twice here,
// which is why the bottom gets a class of its own rather than the shared one.
it('gives the bottom seam its own class rather than the four-edge one', function (): void {
    $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $mains = MarkupSource::elements($layout, 'main');

    expect($mains)->not->toBe([], 'layouts.app has no <main> element to read');

    $classes = $mains[0]->attribute('class') ?? '';

    expect($classes)->toContain('safe-below')
        ->and($classes)->not->toContain('safe-screen');

    expect($css)->toContain('.safe-below {')
        ->and(PatternScan::matches('/\.safe-below\s*\{\s*padding-bottom:\s*var\(--safe-bottom\);\s*\}/', $css))
        ->toBeTrue('.safe-below has to read the seam variable, not a fixed height');
});

it('reserves the seam on every full-screen surface a signed-out reader reaches', function (): void {
    // Twice, because a fresh install and a populated one expose different
    // screens: the first-run gate answers /login with a redirect to /welcome
    // until an account exists, and /signup and /welcome stop answering once
    // one does. Sweeping either state alone leaves half of them unvisited.
    $fresh = safeAreaSignedOutSweep();

    User::query()->create([
        'username' => 'safe-area-sweep',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $populated = safeAreaSignedOutSweep();

    $offenders = array_values(array_unique([...$fresh['offenders'], ...$populated['offenders']]));
    sort($offenders);

    expect($fresh['checked'] + $populated['checked'])
        ->toBeGreaterThan(0, 'No signed-out full-screen surface answered, so this rule checked nothing.');

    expect($offenders)->toBe([], implode("\n", [
        'These signed-out screens render with no seam reserved anywhere:',
        ...$offenders,
        '',
        'layouts.app renders the drawer and the .top-bar under @auth only. Under',
        '@guest it yields into <body> with no chrome at all, which leaves the',
        'screen as the only thing between its content and the system bars — the',
        'same position a layouts.lock consumer is in, and .safe-screen is the',
        'answer in both. The component cannot decide this for itself: Livewire',
        're-renders the component and never the layout, so a screen that signs',
        'its reader in mid-flow still sits in the signed-out markup.',
        '',
        'Only what this Composer root can route is swept. /mobile/welcome answers',
        'under the phone shell alone and is checked by eye there.',
    ]));
});

/**
 * Every lone class selector app.css declares by reading the seam, mapped to
 * the declarations that read it. Only a lone class, for the same reason
 * safeAreaClassEdges() gives: in `.a .b` neither half is a class a template
 * can wear to get the padding.
 *
 * @return array<string, string>
 */
function safeAreaSeamClassBodies(): array
{
    $css = PatternScan::replace(
        '#/\*.*?\*/#s',
        '',
        (string) file_get_contents(base_path('resources/css/app.css')),
    );

    $bodies = [];

    foreach (PatternScan::sets('/([^{}]+)\{([^{}]*)\}/s', $css) as [, $selector, $body]) {
        $name = PatternScan::first('/^\s*\.([A-Za-z0-9_-]+)\s*$/', $selector);

        if ($name === [] || ! str_contains($body, 'var(--safe-')) {
            continue;
        }

        $bodies[$name[1]] = ($bodies[$name[1]] ?? '').$body;
    }

    return $bodies;
}

/**
 * @return list<string>
 */
function safeAreaBottomSeamClasses(): array
{
    return array_keys(array_filter(
        safeAreaSeamClassBodies(),
        static fn (string $body): bool => str_contains($body, 'var(--safe-bottom)'),
    ));
}

// The same question asked of the stylesheet that actually ships. It is
// minified and its selectors are merged, so the answer is read off whichever
// selector list the minifier settled on rather than off one written rule.
/**
 * @return array<string, bool>
 */
function safeAreaShippedClassSeams(): array
{
    $built = glob(base_path('public/build/assets/app-*.css')) ?: [];

    expect($built)->not->toBe([], 'No compiled stylesheet under public/build/assets. Run `npm run build`: the phone bundler zips that directory as it finds it, so an unbuilt tree cannot answer this and must not be skipped.');

    $seams = [];

    foreach ($built as $file) {
        $rules = PatternScan::sets('/([^{}]+)\{([^{}]*)\}/s', (string) file_get_contents($file));

        foreach ($rules as [, $selector, $body]) {
            $reads = str_contains($body, 'var(--safe-');

            foreach (PatternScan::all('/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $selector)[1] as $name) {
                $seams[$name] = ($seams[$name] ?? false) || $reads;
            }
        }
    }

    return $seams;
}

/**
 * @param  list<string>  $classes
 */
function safeAreaAnchorsToTheBottom(array $classes): bool
{
    foreach ($classes as $class) {
        if (PatternScan::matches('#^(?:[a-z0-9-]+:)*-?bottom-(?!auto$)[a-z0-9./\[\]%-]+$#', $class)) {
            return true;
        }
    }

    return false;
}

// Credited on the element or anywhere inside it: a bar that must keep painting
// to the screen edge reserves the seam on the row within it, and that is the
// correct arrangement rather than a way around this rule.
/**
 * @param  list<string>  $seam
 */
function safeAreaBottomSeamIsReserved(MarkupElement $element, array $seam): bool
{
    $reach = implode(' ', $element->classes()).' '.($element->inner ?? '');

    foreach ($seam as $name) {
        if (PatternScan::matches('/(?:^|[\s"\'])'.preg_quote($name, '/').'(?:$|[\s"\'])/', $reach)) {
            return true;
        }
    }

    return false;
}

// Which spacing utilities would beat a seam class that sets this property.
// Tailwind emits every one of them into @layer utilities and the seam classes
// live in @layer components, so the utility wins outright however specific the
// class is and whatever order the two are written in.
/**
 * @return list<string>
 */
function safeAreaUtilitiesOverriding(string $body): array
{
    $shorthands = [
        'padding-bottom' => ['pb', 'py', 'p'],
        'margin-bottom' => ['mb', 'my', 'm'],
    ];

    $prefixes = [];

    foreach ($shorthands as $property => $tokens) {
        if (str_contains($body, $property.':')) {
            $prefixes = [...$prefixes, ...$tokens];
        }
    }

    return array_values(array_unique($prefixes));
}

// The seam is COMPILED from resources/css/app.css and SHIPPED from
// public/build. The phone bundler zips that directory as it finds it and
// rebuilds nothing, so a stylesheet older than the layout it dresses travels
// to the device intact: the class is in the markup, the rule is in the source,
// every test over the source passes, and the element pads by zero.
//
// Measured on a Galaxy A51 from an APK built the same hour: the bundle carried
// a stylesheet compiled six days earlier, with no `.safe-below` rule anywhere
// in it, while `<main>` wore the class. Five of nineteen routes ended with
// their last row behind the navigation bar, the worst of them a destructive
// restore button 23px under it.
it('ships every seam class it compiles', function (): void {
    $compiled = safeAreaSeamClassBodies();
    $shipped = safeAreaShippedClassSeams();

    expect(count($compiled))->toBeGreaterThan(3, 'app.css yielded almost no class that reads the seam, so a clean answer below is this reader being broken rather than the bundle being right.');

    expect(count($shipped))->toBeGreaterThan(500, 'Almost no selector was read out of the shipped stylesheet, so every class below would report clean whatever the bundle holds.');

    $missing = array_values(array_filter(
        array_keys($compiled),
        static fn (string $name): bool => ($shipped[$name] ?? false) === false,
    ));

    expect($missing)->toBe([], implode("\n", [
        'app.css defines these against the seam and public/build does not ship them:',
        ...$missing,
        '',
        'Run `npm run build`. public/build is gitignored and nothing rebuilds it on',
        'the way into a phone bundle, so a stale one ships whatever it was compiled',
        'from — and a class with no rule behind it is a padding of zero, on the',
        'device only, with the markup and the source both looking correct.',
    ]));
});

// The corner region names its id with a constant, so the markup carries the
// expression and never the value. Matched as the template writes it, which is
// also how NothingButTheRegionPinsItselfToTheCornerArchTest names the same
// element.
const SAFE_AREA_CORNER_REGION = 'CornerNotices::REGION_ID';

// A `bottom-4` measures its gap from the screen edge, and both phone shells
// paint that edge under the navigation bar, so the gap the design asked for is
// spent on the bar and the overlay's own controls stand behind it. Worse than
// the scrolled-to-the-end case <main> answers: nothing in a fixed box scrolls,
// so there is no position from which the reader can reach the control.
it('measures an overlay pinned to the bottom edge from the seam', function (): void {
    $seam = safeAreaBottomSeamClasses();
    $offenders = [];
    $read = 0;
    $region = null;

    foreach (safeAreaTemplates() as $file) {
        $read++;
        $source = safeAreaMarkup((string) $file->getContents());

        foreach (MarkupSource::tags($source) as $element) {
            $classes = $element->classes();

            if (! in_array('fixed', $classes, true) || ! safeAreaAnchorsToTheBottom($classes)) {
                continue;
            }

            $at = $file->getRelativePathname().':'.$element->line($source);

            if (str_contains($element->attribute('id') ?? '', SAFE_AREA_CORNER_REGION)) {
                $region = ['at' => $at, 'reserved' => safeAreaBottomSeamIsReserved($element, $seam)];
            }

            if (! safeAreaBottomSeamIsReserved($element, $seam)) {
                $offenders[] = $at;
            }
        }
    }

    expect($read)->toBeGreaterThan(150, 'The template walk read almost nothing, so a clean answer below is the walk being broken rather than the templates being right.');

    // This floor used to be a count of bottom-anchored overlays, over three,
    // and it broke the day the corner region landed: four boxes that each
    // pinned themselves to one corner became one that pins and four occupants
    // that inherit, so the population fell to two while the tree got better.
    // A count lowered to fit is no longer a floor. The subject is named
    // instead, which cannot pass once the subject is gone.
    // One string rather than implode("\n", …): this matcher prints the message
    // inline, so the line breaks the others rely on ran two sentences together.
    expect($region)->not->toBeNull(
        'No element carrying the corner region\'s id is anchored to the bottom of the viewport. '
        .'Modules/Core/Resources/views/components/corner-notices.blade.php is the one surface the '
        .'conflict prompt, the standing notices and the toast stack are all drawn on, so it is where '
        .'the seam is read on behalf of every one of them. If it has moved or stopped anchoring, this '
        .'rule is judging a tree it no longer describes rather than a tree that is right.',
    );

    expect($region['reserved'])->toBeTrue(
        'The corner region at '.$region['at'].' anchors to the bottom edge and reserves nothing, so every '
        .'notice inside it now sits under the home indicator. It is one class — .safe-lift — for all of them.',
    );

    expect(count($seam))->toBeGreaterThan(1, 'app.css yielded almost no class that reads the bottom seam, so every overlay below is judged against an empty vocabulary.');

    expect($offenders)->toBe([], implode("\n", [
        'These elements are pinned to the bottom of the viewport and reserve nothing:',
        ...$offenders,
        '',
        'Wear .safe-lift, which moves the origin the gap is counted from and leaves',
        'the gap itself alone. A bar that must keep painting to the screen edge puts',
        'it on the row inside instead, so the surface still reaches the edge and only',
        'its content clears the bar.',
    ]));
});

// The floor above is a name, so it cannot report a healthy tree once the
// subject is gone. What a name cannot answer is whether the two readers under
// it still tell an anchored overlay from a static one and a reserved seam from
// none — a rule whose readers have stopped working reports the same empty list
// a correct tree does.
it('tells an overlay pinned to the bottom from one that is not, and a reserved seam from none', function (): void {
    $seam = safeAreaBottomSeamClasses();

    // in_array rather than toContain: that matcher takes needles, so a message
    // passed beside one becomes a second thing the array has to hold.
    expect(in_array('safe-lift', $seam, true))->toBeTrue(
        'app.css no longer declares .safe-lift against var(--safe-bottom), so the one class every '
        .'occupant of the corner region depends on is not in the vocabulary this rule judges against.',
    );

    $bare = MarkupSource::tags('<div class="fixed bottom-4 right-4"></div>')[0];
    $lifted = MarkupSource::tags('<div class="safe-lift fixed bottom-4 right-4"></div>')[0];
    $inner = MarkupSource::tags('<footer class="fixed inset-x-0 bottom-0"><div class="safe-lift"></div></footer>')[0];
    $sideways = MarkupSource::tags('<div class="fixed right-4 top-4"></div>')[0];

    expect(safeAreaAnchorsToTheBottom($bare->classes()))->toBeTrue('A bottom offset stopped reading as an anchor to the bottom edge.')
        ->and(safeAreaAnchorsToTheBottom($sideways->classes()))->toBeFalse('An overlay with no bottom offset is being judged as one pinned to that edge.')
        ->and(safeAreaBottomSeamIsReserved($bare, $seam))->toBeFalse('An overlay reserving nothing is being credited with the seam, so this rule can no longer go red.')
        ->and(safeAreaBottomSeamIsReserved($lifted, $seam))->toBeTrue('An overlay wearing the seam class is being reported as reserving nothing.')
        ->and(safeAreaBottomSeamIsReserved($inner, $seam))->toBeTrue('A bar reserving the seam on the row inside it is no longer credited for it.');
});

// The seam classes live in @layer components and every Tailwind spacing
// utility lives in @layer utilities, which wins outright — however specific
// the class is, and whichever is written first. A `py-4` beside .safe-below
// leaves the element with the design's 16px and nothing reports it: the class
// is in the attribute, the rule is in the bundle, and the computed padding is
// the utility's.
it('lets no utility take back the seam a class reserves', function (): void {
    $bodies = safeAreaSeamClassBodies();
    $offenders = [];
    $read = 0;
    $wearers = 0;

    foreach (safeAreaTemplates() as $file) {
        $read++;
        $attributes = PatternScan::all('/class="([^"]*)"/', safeAreaMarkup((string) $file->getContents()));

        foreach ($attributes[1] as $attribute) {
            $classes = PatternScan::split('/\s+/', trim($attribute));

            foreach (array_intersect($classes, array_keys($bodies)) as $worn) {
                $wearers++;

                $beaten = array_values(array_filter(
                    safeAreaUtilitiesOverriding($bodies[$worn]),
                    static fn (string $prefix): bool => array_filter(
                        $classes,
                        static fn (string $class): bool => PatternScan::matches('#^'.$prefix.'-[a-z0-9./\[\]%-]+$#', $class),
                    ) !== [],
                ));

                if ($beaten !== []) {
                    $offenders[] = $file->getRelativePathname().': .'.$worn.' beside '.implode('-*, ', $beaten).'-*';
                }
            }
        }
    }

    expect($read)->toBeGreaterThan(150, 'The template walk read almost nothing, so a clean answer below is the walk being broken rather than the templates being right.');

    expect($wearers)->toBeGreaterThan(3, 'Almost no element wearing a seam class was found, so this rule judged next to nothing.');

    expect($offenders)->toBe([], implode("\n", [
        'These elements carry a seam class and a utility that overwrites it:',
        ...$offenders,
        '',
        'Cascade layers, not specificity: @layer utilities beats @layer components',
        'whatever the selectors say, so the seam class is inert here and the element',
        'keeps the number the utility spells. Move the reserve onto an element the',
        'utility does not dress, or take the utility off.',
    ]));
});

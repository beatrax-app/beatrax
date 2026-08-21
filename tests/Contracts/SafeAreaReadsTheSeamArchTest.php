<?php

declare(strict_types=1);

use Illuminate\View\Factory as ViewFactory;
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
// is the opposite of what every arm here is for.
function safeAreaMarkup(string $contents): string
{
    return preg_replace('/\{\{--.*?--\}\}/s', '', $contents) ?? '';
}

/**
 * @return list<string>
 */
function safeAreaClassesIn(string $template): array
{
    $classes = [];

    preg_match_all('/class="([^"]*)"/', safeAreaMarkup($template), $attributes);

    foreach ($attributes[1] as $attribute) {
        foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
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
    $css = (string) preg_replace(
        '#/\*.*?\*/#s',
        '',
        (string) file_get_contents(base_path('resources/css/app.css')),
    );

    // A body with no brace inside it is an innermost block, so what sits in
    // front of it is a real selector rather than an @media or @layer head.
    // Comments go first: they sit between the previous rule and this one, so
    // they land inside the selector capture and no rule looks like a class.
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);

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

it('never reads env(safe-area-inset-*) outside the seam that fills it in', function (): void {
    $offenders = [];

    foreach (safeAreaTemplates() as $file) {
        // Case-insensitive with optional inner whitespace: CSS function names
        // are ASCII case-insensitive and `env( safe-area-inset-top )` is the
        // same declaration, so a literal lowercase match let both through.
        if (preg_match('/\benv\(\s*safe-area-inset-/i', safeAreaMarkup((string) $file->getContents())) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

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

it('keeps the seam those templates depend on', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    // Whitespace-insensitive: the declaration is the invariant, not the
    // formatting. A byte-exact match failed all four edges on a reformat and
    // reported it as the seam having been lost.
    $collapsed = preg_replace('/\s+/', '', $css) ?? '';

    $missing = [];

    foreach (['top', 'bottom', 'left', 'right'] as $edge) {
        $rule = "--safe-{$edge}: max(env(safe-area-inset-{$edge}, 0px), var(--inset-{$edge}, 0px))";

        if (! str_contains($collapsed, (string) preg_replace('/\s+/', '', $rule))) {
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

it('pads an inset per edge rather than mirroring one of them', function (): void {
    $offenders = [];

    foreach (safeAreaTemplates() as $file) {
        // px- AND py-: the vertical form is the same defect and the more
        // reachable one, because the top and bottom insets differ on every
        // notched phone held in portrait.
        if (preg_match('/p[xy]-\[var\(--safe-(top|bottom|left|right)\)\]/', safeAreaMarkup((string) $file->getContents())) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

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

    expect($checked)->toBeGreaterThan(0, 'No layouts.lock consumer was found, so this rule checked nothing.');

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

    foreach (safeAreaTemplates() as $file) {
        preg_match_all('/class="([^"]*)"/', safeAreaMarkup((string) $file->getContents()), $attributes);

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

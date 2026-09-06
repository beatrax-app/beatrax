<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-native-post-form-in-the-mobile-shell
 */
const NATIVE_POST_FORM_SUBMITTER = 'beatraxSubmitPostForm';

const NATIVE_POST_FORM_HELPER = 'resources/js/app.js';

/**
 * Livewire owns its own submits via wire:submit and never issues a native form
 * POST, so only forms declaring method="POST" are in scope.
 *
 * @return list<string> the start tag of each POST form that submits natively
 */
function nativePostFormOffendersIn(string $source): array
{
    $offenders = [];

    foreach (MarkupSource::elements($source, 'form') as $form) {
        if (strtoupper((string) $form->attribute('method')) !== 'POST') {
            continue;
        }

        if (! str_contains($form->startTag, NATIVE_POST_FORM_SUBMITTER)) {
            $offenders[] = trim(preg_replace('/\s+/', ' ', $form->startTag) ?? $form->startTag);
        }
    }

    return $offenders;
}

it('routes every plain POST form through the fetch submitter', function (): void {
    $views = RepoTree::files(RepoTree::EVERY_BLADE_VIEW);

    expect(count($views))->toBeGreaterThan(
        150,
        'RepoTree returned '.count($views).' Blade views, which is too few to have read the tree.'
    );

    $posts = 0;
    $offenders = [];

    foreach ($views as $path) {
        $source = (string) file_get_contents($path);

        foreach (MarkupSource::elements($source, 'form') as $form) {
            if (strtoupper((string) $form->attribute('method')) === 'POST') {
                $posts++;
            }
        }

        foreach (nativePostFormOffendersIn($source) as $offender) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).' — '.$offender;
        }
    }

    // Read before the verdict: a walk that parsed no form reports the same
    // clean tree seven wired forms do. The floor sits under today's 7.
    expect($posts)->toBeGreaterThan(
        2,
        'the walk found '.$posts.' POST forms, which is too few to be this tree — every verdict below read nothing.'
    );

    expect(array_values(array_unique($offenders)))->toBe(
        [],
        "A native form POST is silently dropped by the mobile shell. Add\n".
        '  x-data x-on:submit.prevent="'.NATIVE_POST_FORM_SUBMITTER.'($el, $event.submitter)"'."\n".
        "to the <form> tag in:\n  ".implode("\n  ", array_unique($offenders)),
    );
});

it('keeps the submitter helper available to those views', function (): void {
    // The views call it by name off window; if the helper is renamed or
    // dropped, every one of them fails open to the broken native submit.
    $path = base_path(NATIVE_POST_FORM_HELPER);

    expect(is_file($path))->toBeTrue(NATIVE_POST_FORM_HELPER.' is gone, so nothing defines the submitter the forms call.');

    expect((string) file_get_contents($path))->toContain('window.'.NATIVE_POST_FORM_SUBMITTER);
});

it('submits those forms as JSON', function (): void {
    // The mobile shell forwards ONLY JSON request bodies — urlencoded and
    // multipart both arrive with an empty input bag, so the route reads
    // nothing and redirects back with a 200. Nothing else catches this: the
    // request succeeds, and the control is simply inert on device.
    $helper = (string) file_get_contents(base_path(NATIVE_POST_FORM_HELPER));
    $body = (string) mb_strstr($helper, 'window.'.NATIVE_POST_FORM_SUBMITTER);

    expect($body)->not->toBe(
        '',
        NATIVE_POST_FORM_HELPER.' declares no window.'.NATIVE_POST_FORM_SUBMITTER.', so the three assertions '
        .'below would read an empty body and pass over nothing.'
    );

    expect($body)->toContain("'Content-Type': 'application/json'")
        ->and($body)->toContain('JSON.stringify')
        ->and($body)->not->toContain('application/x-www-form-urlencoded');
});

// Every POST form in the tree is already wired, so this rule reports on what it
// cannot find. The near-misses are the two shapes that legitimately submit
// without the helper: a GET form, and a Livewire form with no method at all.
it('tells a native POST submit from a wired one and from a form that never posts', function (): void {
    $wired = '<form method="POST" x-data x-on:submit.prevent="'.NATIVE_POST_FORM_SUBMITTER.'($el, $event.submitter)"></form>';

    expect(nativePostFormOffendersIn('<form method="POST" action="/x"></form>'))
        ->toBe(['<form method="POST" action="/x">'])
        ->and(nativePostFormOffendersIn("<form\n    method=\"post\"\n    action=\"/x\"></form>"))
        ->toBe(['<form method="post" action="/x">'])
        ->and(nativePostFormOffendersIn($wired))->toBe([])
        ->and(nativePostFormOffendersIn('<form method="GET" action="/x"></form>'))->toBe([])
        ->and(nativePostFormOffendersIn('<form wire:submit="save"></form>'))->toBe([]);
});

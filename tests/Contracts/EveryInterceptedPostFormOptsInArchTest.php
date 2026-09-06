<?php

declare(strict_types=1);

use Tests\Contracts\Support\RepoTree;

// The mobile shell replays a native form submit without its method, so Laravel
// answers 405 for `GET /logout` and the reader lands on a framework error page.
// `beatraxSubmitPostForm` exists to route those submits through fetch instead,
// but the per-form `x-on:submit.prevent` only fires once Alpine has bound that
// node -- and a drawer Livewire morphs back can carry the attribute without it.
// The delegated listener in app.js catches them either way, keyed on the marker
// below, so a form that names the helper and not the marker is invisible to it.

/**
 * Every use of the post helper in one template, and whether the `<form>` it
 * sits on carries the marker the delegate reads. Named and taking a source
 * string so the control below drives the same reader the walk drives.
 *
 * @return list<array{line: int, marked: bool}>
 */
function interceptedPostFormOffendersIn(string $source): array
{
    $uses = [];
    $offset = 0;

    while (($at = strpos($source, 'beatraxSubmitPostForm', $offset)) !== false) {
        $offset = $at + 1;

        // The marker belongs to the <form> the handler sits on. Reading
        // back to the opening tag rather than the whole file: a second form
        // in the same file must not lend this one its attribute.
        $tagStart = strrpos(substr($source, 0, $at), '<form');

        $uses[] = [
            'line' => substr_count(substr($source, 0, $at), "\n") + 1,
            'marked' => $tagStart !== false && str_contains(substr($source, $tagStart, $at - $tagStart), 'data-beatrax-post'),
        ];
    }

    return $uses;
}

it('gives every form routed through the post helper the marker the delegate reads', function (): void {
    $views = RepoTree::files(RepoTree::EVERY_BLADE_VIEW);

    // The floor sits well under the 279 templates this tree ships.
    expect(count($views))->toBeGreaterThan(
        100,
        'The Blade walk opened almost nothing, so no form was read at all.'
    );

    /** @var list<string> $offenders */
    $offenders = [];
    $seen = 0;

    foreach ($views as $path) {
        foreach (interceptedPostFormOffendersIn((string) file_get_contents($path)) as $use) {
            $seen++;

            if (! $use['marked']) {
                $offenders[] = str_replace(RepoTree::root().'/', '', $path).':'.$use['line'];
            }
        }
    }

    // Seven submits across five templates route through the helper today. A run
    // that found none of them reports every form marked without having opened one.
    expect($seen)->toBeGreaterThan(
        2,
        'No template names the post helper at all, so this rule checked nothing.'
    );

    expect($offenders)->toBe([], implode("\n  ", [
        'These route a submit through the post helper without the marker the delegate reads:',
        ...$offenders,
        '',
        'The per-form x-on:submit.prevent fires only once Alpine has bound that node, and',
        'a drawer Livewire morphs back carries the attribute without it. Without',
        'data-beatrax-post on the <form>, the delegated listener in app.js cannot see the',
        'submit either, and the shell replays it as GET — a 405 and a framework error page.',
    ]));
});

// The delegate must never claim a submit another handler wanted. Capture
// phase and unqualified, it preempted Livewire: a wire:submit form with no
// action submitted natively to its own URL, sign-in silently reloaded the
// page, and a wrong password produced no message at all.
it('keeps the delegate weaker than every handler that could own a submit', function (): void {
    $js = (string) file_get_contents(base_path('resources/js/app.js'));

    $missing = array_values(array_filter(
        ['data-beatrax-post', 'event.defaultPrevented', "form.getAttribute('action')"],
        static fn (string $part): bool => ! str_contains($js, $part),
    ));

    expect($missing)->toBe([], implode("\n  ", [
        'The delegated submit listener no longer reads:',
        ...$missing,
        '',
        'It has to key on the marker, stand down for a handler that already prevented',
        'the event, and know whether the form names its own action.',
    ]));

    // The third argument is what put it ahead of Livewire.
    expect(str_contains($js, ', true)'))->toBeFalse(
        'A listener in app.js registers for the capture phase. The delegate ran there once and preempted Livewire: '.
        'a wire:submit form with no action submitted natively to its own URL, sign-in silently reloaded the page, '.
        'and a wrong password produced no message at all.'
    );
});

// The guard is worth its ability to go red, and a reader that found nothing
// would report every form marked.
it('sees an unmarked form and does not lend it a neighbour\'s marker', function (): void {
    $source = <<<'BLADE'
        <form data-beatrax-post method="POST" action="/logout"
              x-on:submit.prevent="beatraxSubmitPostForm($el)">
            <button>Sign out</button>
        </form>
        <form method="POST" action="/lock"
              x-on:submit.prevent="beatraxSubmitPostForm($el)">
            <button>Lock</button>
        </form>
        BLADE;

    expect(interceptedPostFormOffendersIn($source))->toBe([
        ['line' => 2, 'marked' => true],
        ['line' => 6, 'marked' => false],
    ]);
});

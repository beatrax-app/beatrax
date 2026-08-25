<?php

declare(strict_types=1);

// The mobile shell replays a native form submit without its method, so Laravel
// answers 405 for `GET /logout` and the reader lands on a framework error page.
// `beatraxSubmitPostForm` exists to route those submits through fetch instead,
// but the per-form `x-on:submit.prevent` only fires once Alpine has bound that
// node -- and a drawer Livewire morphs back can carry the attribute without it.
// The delegated listener in app.js catches them either way, keyed on the marker
// below, so a form that names the helper and not the marker is invisible to it.
it('gives every form routed through the post helper the marker the delegate reads', function (): void {
    /** @var list<string> $offenders */
    $offenders = [];

    /** @var Iterator<SplFileInfo> $found */
    $found = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'))),
        '/\.blade\.php$/',
    );

    $seen = 0;

    foreach ($found as $file) {
        $source = (string) file_get_contents($file->getPathname());
        $offset = 0;

        while (($at = strpos($source, 'beatraxSubmitPostForm', $offset)) !== false) {
            $offset = $at + 1;
            $seen++;

            // The marker belongs to the <form> the handler sits on. Reading
            // back to the opening tag rather than the whole file: a second form
            // in the same file must not lend this one its attribute.
            $tagStart = strrpos(substr($source, 0, $at), '<form');

            if ($tagStart === false || ! str_contains(substr($source, $tagStart, $at - $tagStart), 'data-beatrax-post')) {
                $line = substr_count(substr($source, 0, $at), "\n") + 1;
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.$line;
            }
        }
    }

    expect($seen)->toBeGreaterThan(0);
    expect($offenders)->toBe([]);
});

it('keeps the delegate listening in the capture phase, where a missing binding cannot beat it', function (): void {
    $js = (string) file_get_contents(base_path('resources/js/app.js'));

    expect($js)->toContain('data-beatrax-post');
    expect($js)->toContain('}, true);');
});

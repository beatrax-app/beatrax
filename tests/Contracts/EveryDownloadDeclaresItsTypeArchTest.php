<?php

declare(strict_types=1);

use Tests\Contracts\Support\RepoTree;

// `streamDownload` leaves Laravel's default `text/html; charset=utf-8` when it
// is handed no headers. Two of the five call sites shipped that way: a report
// CSV was named .csv, held CSV, and announced itself as HTML. Content-Disposition
// keeps a browser from rendering it, but the iOS shell decides between a page
// and a download partly from the MIME type, and `savesWebViewDownloads()`
// promises the share sheet.

/**
 * Every `streamDownload(` in one source, and whether its own argument list
 * names a Content-Type. Named and taking a source string so the control below
 * drives the same reader the walk drives.
 *
 * @return list<array{line: int, declared: bool}>
 */
function downloadsMissingContentTypeIn(string $source): array
{
    $calls = [];
    $offset = 0;

    while (($at = strpos($source, 'streamDownload(', $offset)) !== false) {
        $offset = $at + 1;

        // The call runs to its matching paren; a Content-Type anywhere
        // inside it is the header argument, and nothing else in that span
        // spells one.
        $depth = 0;
        $end = $at;

        for ($i = $at, $length = strlen($source); $i < $length; $i++) {
            if ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    $end = $i;

                    break;
                }
            }
        }

        $calls[] = [
            'line' => substr_count(substr($source, 0, $at), "\n") + 1,
            'declared' => str_contains(substr($source, $at, $end - $at), 'Content-Type'),
        ];
    }

    return $calls;
}

it('gives every streamed download an explicit Content-Type', function (): void {
    $files = RepoTree::files(RepoTree::PRODUCTION_PHP);

    // The floor sits far under the 6,600 shipped files this scope opens.
    expect(count($files))->toBeGreaterThan(
        2000,
        'The production walk opened almost nothing, so no download was read at all.'
    );

    $offenders = [];
    $calls = 0;

    foreach ($files as $path) {
        foreach (downloadsMissingContentTypeIn((string) file_get_contents($path)) as $call) {
            $calls++;

            if (! $call['declared']) {
                $offenders[] = str_replace(RepoTree::root().'/', '', $path).':'.$call['line'];
            }
        }
    }

    // Five call sites stream a download today. A run that found none of them
    // reports every download typed without having opened one.
    expect($calls)->toBeGreaterThan(
        2,
        'No streamDownload() call was found at all, so this rule checked nothing.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These streamed downloads declare no Content-Type, so they are served as text/html:',
        ...$offenders,
        '',
        'Pass a headers array as streamDownload()\'s third argument.',
    ]));
});

// The guard is worth its ability to go red, and a brace matcher that found
// nothing would report every download typed.
it('sees an untyped download and credits a typed one', function (): void {
    $source = <<<'PHP'
        <?php
        final class Planted
        {
            public function untyped(): mixed
            {
                return response()->streamDownload(fn () => print($this->rows()), 'report.csv');
            }

            public function typed(): mixed
            {
                return response()->streamDownload(
                    fn () => print($this->rows()),
                    'report.csv',
                    ['Content-Type' => 'text/csv; charset=utf-8'],
                );
            }
        }
        PHP;

    expect(downloadsMissingContentTypeIn($source))->toBe([
        ['line' => 6, 'declared' => false],
        ['line' => 11, 'declared' => true],
    ]);
});

<?php

declare(strict_types=1);

// `streamDownload` leaves Laravel's default `text/html; charset=utf-8` when it
// is handed no headers. Two of the five call sites shipped that way: a report
// CSV was named .csv, held CSV, and announced itself as HTML. Content-Disposition
// keeps a browser from rendering it, but the iOS shell decides between a page
// and a download partly from the MIME type, and `savesWebViewDownloads()`
// promises the share sheet.
it('gives every streamed download an explicit Content-Type', function (): void {
    $offenders = [];

    /** @var list<string> $files */
    $files = [];
    foreach (['Modules', 'app', 'routes'] as $root) {
        $dir = base_path($root);
        if (! is_dir($dir)) {
            continue;
        }
        /** @var Iterator<SplFileInfo> $found */
        $found = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
            '/\.php$/',
        );
        foreach ($found as $file) {
            $path = $file->getPathname();
            if (! str_contains($path, '/tests/')) {
                $files[] = $path;
            }
        }
    }
    expect($files)->not->toBe([]);

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $offset = 0;
        while (($at = strpos($source, 'streamDownload(', $offset)) !== false) {
            $offset = $at + 1;

            // The call runs to its matching paren; a Content-Type anywhere
            // inside it is the header argument, and nothing else in that span
            // spells one.
            $depth = 0;
            $end = $at;
            for ($i = $at; $i < strlen($source); $i++) {
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

            if (! str_contains(substr($source, $at, $end - $at), 'Content-Type')) {
                $offenders[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These streamed downloads declare no Content-Type, so they are served as text/html:',
        ...$offenders,
        '',
        'Pass a headers array as streamDownload()\'s third argument.',
    ]));
});

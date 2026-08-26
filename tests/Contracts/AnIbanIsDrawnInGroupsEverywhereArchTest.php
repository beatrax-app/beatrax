<?php

declare(strict_types=1);

// An IBAN has no break opportunity of its own, so any column narrow enough
// splits it mid-identifier. Core::Iban is the one place that decides the
// presentation, and the reason this rule exists is that routing four render
// sites through it was not enough: a fifth sat in the onboarding preview and a
// sixth in an import partial, and both were found by eye on a device rather
// than by the change that was supposed to have covered them.

it('draws every IBAN it echoes through the one seam that groups it', function (): void {
    $files = [];
    /** @var Iterator<SplFileInfo> $found */
    $found = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'))),
        '/\.blade\.php$/',
    );
    foreach ($found as $file) {
        $files[] = $file->getPathname();
    }

    expect(count($files))->toBeGreaterThan(100, 'No blades were read, so this rule proved nothing.');

    $raw = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        // Echoes only, and only where the IBAN is the WHOLE value. An IBAN
        // passed to in_array() or to a mask is an argument, not something the
        // reader sees, and grouping it there would break the comparison.
        if (preg_match_all('/\{\{(?!--)\s*(\$[\w>\-\[\]\']*?[Ii]ban)\s*\}\}/', $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new RuntimeException('The IBAN scan stopped reading '.$path);
        }

        foreach ($matches[1] as $match) {
            $raw[] = str_replace(base_path().'/', '', $path)
                .':'.(substr_count(substr($source, 0, (int) $match[1]), "\n") + 1)
                .' {{ '.$match[0].' }}';
        }
    }

    sort($raw);

    expect($raw)->toBe(
        [],
        "These render an IBAN as one unbroken run; pass it through Iban::grouped():\n  ".implode("\n  ", $raw)
    );
});

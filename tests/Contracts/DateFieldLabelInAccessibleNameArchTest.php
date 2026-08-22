<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Modules\Core\Public\Support\Lang;

it('carries the visible label of every date and time field into the name it computes (WCAG 2.5.3)', function (): void {
    // A <label for="…"> cannot name a <button>, so the pickers build their name
    // from aria-labelledby. That outranks the label outright: a caller pairing a
    // visible "Target date" with the default name leaves the field called
    // "Choose a date", and voice control has nothing to say to it.
    $resolveText = static function (string $raw): ?string {
        $raw = trim($raw);
        if (preg_match('~^\{\{\s*Lang::get\(\s*\'([^\']+)\'\s*\)\s*\}\}$~', $raw, $m) === 1) {
            return (string) Lang::get($m[1]);
        }

        return preg_match('~[{}$<>]~', $raw) === 1 ? null : ($raw === '' ? null : $raw);
    };

    $accessibleName = static function (string $html, string $buttonId): string {
        $document = new DOMDocument;
        $loaded = @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        expect($loaded)->toBeTrue('the rendered picker must parse as HTML');

        $xpath = new DOMXPath($document);
        $button = $xpath->query(sprintf('//button[@id="%s"]', $buttonId))->item(0);
        expect($button)->toBeInstanceOf(DOMElement::class, 'the picker must expose a button carrying fieldId');

        /** @var DOMElement $button */
        $labelledBy = trim($button->getAttribute('aria-labelledby'));
        if ($labelledBy !== '') {
            $parts = [];
            foreach (preg_split('~\s+~', $labelledBy) ?: [] as $id) {
                $node = $xpath->query(sprintf('//*[@id="%s"]', $id))->item(0);
                if ($node !== null) {
                    $parts[] = trim($node->textContent);
                }
            }

            return trim(implode(' ', array_filter($parts, static fn (string $p): bool => $p !== '')));
        }

        $ariaLabel = trim($button->getAttribute('aria-label'));

        return $ariaLabel !== '' ? $ariaLabel : trim($button->textContent);
    };

    $blades = [];
    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
                $blades[] = $file->getPathname();
            }
        }
    }
    sort($blades);

    $offenders = [];
    $checked = 0;

    foreach ($blades as $path) {
        $source = (string) file_get_contents($path);

        preg_match_all('~<label\b([^>]*\bfor="([^"]+)"[^>]*)>(.*?)</label>~s', $source, $labelMatches, PREG_SET_ORDER);
        $labels = [];
        foreach ($labelMatches as $label) {
            $text = $resolveText($label[3]);
            if ($text !== null) {
                $labels[$label[2]] = $text;
            }
        }

        if ($labels === []) {
            continue;
        }

        preg_match_all(
            '~<x-core::(date|time)-input\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)/?>~s',
            $source,
            $tags,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($tags as $tag) {
            $offset = $tag[0][1];
            $kind = $tag[1][0];
            $attributes = $tag[2][0];

            if (preg_match('~\bfield-id="([^"]+)"~', $attributes, $fieldId) !== 1) {
                continue;
            }
            if (! array_key_exists($fieldId[1], $labels)) {
                continue;
            }

            $ariaLabel = null;
            if (preg_match("~\\s:aria-label=\"Lang::get\\(\\s*'([^']+)'\\s*\\)\"~", $attributes, $bound) === 1) {
                $ariaLabel = (string) Lang::get($bound[1]);
            } elseif (preg_match('~\s:aria-label="([^"]+)"~', $attributes) === 1) {
                // A bound expression this file cannot evaluate is left to the
                // caller: it is already naming the field deliberately.
                continue;
            } elseif (preg_match('~\saria-label="([^"{}]+)"~', $attributes, $literal) === 1) {
                $ariaLabel = $literal[1];
            }

            $probe = 'accname-probe';
            $rendered = Blade::render(sprintf(
                '<x-core::%s-input field-id="%s" %s />',
                $kind,
                $probe,
                $ariaLabel === null ? '' : sprintf('aria-label="%s"', e($ariaLabel)),
            ));

            $name = $accessibleName($rendered, $probe);
            $checked++;

            if (! str_contains($name, $labels[$fieldId[1]])) {
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $offenders[] = sprintf(
                    '%s:%d  visible label "%s" — computed name "%s"',
                    str_replace(base_path().'/', '', $path),
                    $line,
                    $labels[$fieldId[1]],
                    $name,
                );
            }
        }
    }

    expect($checked)->toBeGreaterThan(0, 'the scan found no labelled date or time field to check');
    expect($offenders)->toBe([], implode("\n", [
        'These date and time fields compute an accessible name that does not',
        'contain their own visible label, so voice control cannot reach them:',
        ...$offenders,
        '',
        'Pass :aria-label to the component with the same string the <label>',
        'renders — it becomes the name half of aria-labelledby.',
    ]));
});

it('names each open calendar and clock after its own field, not after every other one', function (): void {
    // Two pickers in one toolbar ("from" and "to") both opened a dialog called
    // "Choose a date", so nothing announced which of the two was on screen.
    foreach ([['date', 'from-probe', 'Start date'], ['time', 'until-probe', 'Quiet hours end']] as [$kind, $probe, $label]) {
        $rendered = Blade::render(sprintf(
            '<x-core::%s-input field-id="%s" aria-label="%s" />',
            $kind,
            $probe,
            $label,
        ));

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$rendered, LIBXML_NOERROR | LIBXML_NOWARNING);
        $dialog = (new DOMXPath($document))->query('//*[@role="dialog"]')->item(0);

        expect($dialog)->toBeInstanceOf(DOMElement::class, 'the picker must expose its popover as a dialog');
        /** @var DOMElement $dialog */
        expect($dialog->getAttribute('aria-label'))->toBe($label);
    }
});

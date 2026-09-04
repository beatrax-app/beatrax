<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\RenderedMarkup;

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
        $document = RenderedMarkup::of($html);
        $button = $document->firstOrFail(sprintf('button[id="%s"]', $buttonId));
        $labelledBy = trim((string) $button->attribute('aria-labelledby'));

        if ($labelledBy !== '') {
            $parts = [];
            foreach (explode(' ', $labelledBy) as $id) {
                $node = $id === '' ? null : $document->first(sprintf('[id="%s"]', $id));
                $parts[] = $node?->text() ?? '';
            }

            return trim(implode(' ', array_filter($parts, static fn (string $p): bool => $p !== '')));
        }

        $ariaLabel = trim((string) $button->attribute('aria-label'));

        return $ariaLabel !== '' ? $ariaLabel : $button->text();
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

        $labels = [];
        foreach (MarkupSource::elements($source, 'label') as $label) {
            $for = $label->attribute('for');
            $text = $for === null || $label->inner === null ? null : $resolveText($label->inner);
            if ($for !== null && $text !== null) {
                $labels[$for] = $text;
            }
        }

        if ($labels === []) {
            continue;
        }

        $tags = [];
        foreach (['date', 'time'] as $kind) {
            foreach (MarkupSource::elements($source, 'x-core::'.$kind.'-input') as $element) {
                $tags[] = [$kind, $element];
            }
        }

        foreach ($tags as [$kind, $element]) {
            $fieldId = $element->attribute('field-id');

            if ($fieldId === null || ! array_key_exists($fieldId, $labels)) {
                continue;
            }

            $bound = $element->attribute(':aria-label');
            $ariaLabel = null;

            if ($bound !== null && preg_match("~^Lang::get\\(\\s*'([^']+)'\\s*\\)$~", trim($bound), $key) === 1) {
                $ariaLabel = (string) Lang::get($key[1]);
            } elseif ($bound !== null) {
                // A bound expression this file cannot evaluate is left to the
                // caller: it is already naming the field deliberately.
                continue;
            } elseif (($literal = $element->attribute('aria-label')) !== null && preg_match('~[{}]~', $literal) !== 1) {
                $ariaLabel = $literal;
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

            if (! str_contains($name, $labels[$fieldId])) {
                $offenders[] = sprintf(
                    '%s:%d  visible label "%s" — computed name "%s"',
                    str_replace(base_path().'/', '', $path),
                    $element->line($source),
                    $labels[$fieldId],
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

        $dialog = RenderedMarkup::of($rendered)->firstOrFail('[role="dialog"]');

        expect($dialog->attribute('aria-label'))->toBe($label);
    }
});

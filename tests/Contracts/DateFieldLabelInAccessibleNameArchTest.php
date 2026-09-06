<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\RenderedMarkup;
use Tests\Contracts\Support\RepoTree;

/**
 * Every id a template's `<label for=…>` names, paired with the text that label
 * renders, resolved only where this file can resolve it. Named and taking a
 * source string so the control below drives the reader the walk drives.
 *
 * @return array<string, string> the id a label points at => the text it renders
 */
function dateFieldLabelsIn(string $source): array
{
    $labels = [];

    foreach (MarkupSource::elements($source, 'label') as $label) {
        $for = $label->attribute('for');

        if ($for === null || $label->inner === null) {
            continue;
        }

        $raw = trim($label->inner);

        if (preg_match('~^\{\{\s*Lang::get\(\s*\'([^\']+)\'\s*\)\s*\}\}$~', $raw, $key) === 1) {
            $labels[$for] = (string) Lang::get($key[1]);

            continue;
        }

        // An expression this file cannot evaluate names nothing it can compare,
        // and an empty label is not a name a reader was given.
        if ($raw !== '' && preg_match('~[{}$<>]~', $raw) !== 1) {
            $labels[$for] = $raw;
        }
    }

    return $labels;
}

it('carries the visible label of every date and time field into the name it computes (WCAG 2.5.3)', function (): void {
    // A <label for="…"> cannot name a <button>, so the pickers build their name
    // from aria-labelledby. That outranks the label outright: a caller pairing a
    // visible "Target date" with the default name leaves the field called
    // "Choose a date", and voice control has nothing to say to it.
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

    $blades = RepoTree::files(RepoTree::EVERY_BLADE_VIEW);

    // The floor sits well under the 279 templates this tree ships. A walk that
    // opened none of them finds no unnamed field because it read nothing.
    expect(count($blades))->toBeGreaterThan(
        100,
        'The Blade walk opened almost nothing, so no labelled field was read at all.'
    );

    $offenders = [];
    $checked = 0;

    foreach ($blades as $path) {
        $source = (string) file_get_contents($path);

        $labels = dateFieldLabelsIn($source);

        // A template with no `for` label pairs nothing with a picker, so there
        // is no visible name for a computed one to disagree with.
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
                    str_replace(RepoTree::root().'/', '', $path),
                    $element->line($source),
                    $labels[$fieldId],
                    $name,
                );
            }
        }
    }

    // Twelve labelled pickers stand on this tree. A run that computed a name for
    // none of them found no mismatch because it measured nothing.
    expect($checked)->toBeGreaterThan(2, 'The scan computed no accessible name at all, so it found no labelled date or time field to check.');
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

        expect($dialog->attribute('aria-label'))->toBe(
            $label,
            'The '.$kind.' picker opened a dialog that does not carry its own field name, so two pickers in one '.
            'toolbar announce the same thing and nothing says which of them is on screen.'
        );
    }
});

// The label reader is where the rule above gets its subject, and a reader that
// silently returned nothing would skip every template and pass.
it('reads a labelled field where there is one, and skips a label it cannot resolve', function (): void {
    $source = <<<'BLADE'
        <label for="target-date">Target date</label>
        <x-core::date-input field-id="target-date" />
        <label for="computed">{{ $whateverThisIs }}</label>
        <label>No for attribute</label>
        BLADE;

    expect(dateFieldLabelsIn($source))->toBe(['target-date' => 'Target date']);
});

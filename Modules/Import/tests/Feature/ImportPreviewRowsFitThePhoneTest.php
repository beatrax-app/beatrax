<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

function previewTableMarkup(): string
{
    $blade = file_get_contents(base_path('Modules/Import/Resources/views/livewire/preview-wizard.blade.php'));
    expect($blade)->toBeString();

    $start = strpos($blade, '<x-core::data-table');
    $end = strpos($blade, '</x-core::data-table>');
    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();

    return substr($blade, $start, $end - $start);
}

// The whole @media block the hook lives in, brace-matched: the rules are
// separated by comments, so anything shorter stops at the first one.
function previewRestackRules(): string
{
    $css = file_get_contents(base_path('resources/css/app.css'));
    expect($css)->toBeString();

    $hook = strpos($css, '.preview-rows-table,');
    expect($hook)->not->toBeFalse();

    $start = strrpos(substr($css, 0, $hook), '@media (max-width: 767px)');
    expect($start)->not->toBeFalse();

    $depth = 0;
    $length = strlen($css);
    for ($i = $start; $i < $length; $i++) {
        if ($css[$i] === '{') {
            $depth++;
        }
        if ($css[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($css, $start, $i - $start + 1);
            }
        }
    }

    return substr($css, $start);
}

it('gives the import preview table the hook that restacks it below 768px', function (): void {
    expect(previewTableMarkup())->toContain('preview-rows-table');

    $css = file_get_contents(base_path('resources/css/app.css'));
    $hook = strpos($css, '.preview-rows-table,');
    $query = strrpos(substr($css, 0, $hook), '@media (max-width: 767px)');

    expect($query)->not->toBeFalse();
});

it('places every column of the preview row, so none can fall off the screen', function (): void {
    $columns = substr_count(previewTableMarkup(), '<x-core::th');
    expect($columns)->toBe(5);

    $rules = previewRestackRules();
    for ($nth = 1; $nth <= $columns; $nth++) {
        expect($rules)->toContain(sprintf('.preview-rows-table td:nth-child(%d)', $nth));
    }
});

it('keeps the amount and the status on the phone, which is what the reader is asked to review', function (): void {
    $rules = previewRestackRules();

    expect($rules)->toContain('.preview-rows-table thead');
    expect($rules)->toMatch('/\.preview-rows-table td:nth-child\(4\)\s*\{[^}]*grid-row:\s*1/');
    expect($rules)->toMatch('/\.preview-rows-table td:nth-child\(5\)\s*\{[^}]*grid-column:\s*1 \/ -1/');
});

// Measured on an iPhone 12 mini: the input in the name-this-account row was
// 173px against a placeholder needing 197, so it read "e.g. Main savings ac",
// and the button beside it broke "Save name" over two lines at 55px in a 44px
// row. Three rows share the shape -- the two preset wallets and the unknown
// IBAN -- so all three are checked.
it('stacks every name-this-account row on a phone, so its button has a row to itself', function (): void {
    $blade = (string) file_get_contents(base_path('Modules/Import/Resources/views/livewire/preview-wizard.blade.php'));

    $rows = substr_count($blade, 'import::preview.save_name');
    expect($rows)->toBe(4)
        ->and(substr_count($blade, 'flex flex-col gap-2 sm:flex-row sm:items-end'))->toBe($rows)
        ->and($blade)->not->toContain('<div class="flex items-end gap-2">');
});

// The width is the whole fix ON A PHONE. A `whitespace-nowrap` on these
// buttons looks like a second guard and cannot act as one there: the
// coarse-pointer block sets white-space:normal on every button, unlayered, so
// it outranks the utility whatever the specificity. Measured on the device --
// the class was present, matched its selector, carried no inline style, and
// computed `normal`. It is not dead everywhere: that block is scoped to
// pointer:coarse, so the same class still holds a desktop label on one line,
// which is why two other buttons in this repo keep theirs.
it('leans on the stacking rather than on a nowrap the phone overrides', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    // The rule this defers to, found by its selector list rather than a line
    // number, and read to its closing brace.
    $start = strpos($css, "    [role='tab'],\n    .status-pill,");
    expect($start)->not->toBeFalse('The coarse-pointer rule that lets control labels wrap is gone.');

    $block = substr($css, (int) $start, (int) strpos($css, '}', (int) $start) - (int) $start);
    expect($block)->toContain('white-space: normal;')
        ->and(CssRule::atRuleEnclosing($css, "    [role='tab'],\n    .status-pill,"))->toContain('pointer: coarse');
});

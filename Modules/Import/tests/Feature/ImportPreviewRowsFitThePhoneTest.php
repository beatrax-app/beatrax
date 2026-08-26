<?php

declare(strict_types=1);

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
it('stacks every name-this-account row on a phone, and never wraps its button', function (): void {
    $blade = (string) file_get_contents(base_path('Modules/Import/Resources/views/livewire/preview-wizard.blade.php'));

    $rows = substr_count($blade, 'import::preview.save_name');
    expect($rows)->toBe(3)
        ->and(substr_count($blade, 'flex flex-col gap-2 sm:flex-row sm:items-end'))->toBe($rows)
        ->and($blade)->not->toContain('<div class="flex items-end gap-2">');

    $unwrapped = [];
    $offset = 0;
    while (($at = strpos($blade, 'import::preview.save_name', $offset)) !== false) {
        $button = strrpos(substr($blade, 0, $at), '<button');
        expect($button)->not->toBeFalse();

        if (! str_contains(substr($blade, (int) $button, $at - (int) $button), 'whitespace-nowrap')) {
            $unwrapped[] = substr_count(substr($blade, 0, $at), "\n") + 1;
        }
        $offset = $at + 1;
    }

    expect($unwrapped)->toBe([], 'Save-name buttons that can break over two lines, at line: '.implode(', ', $unwrapped));
});

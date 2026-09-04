<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\PatternScan;
use Modules\DevMode\Internal\Http\Livewire\CommandPaletteModal;

beforeEach(function (): void {
    $user = User::query()->create([
        'username' => 'palette-keyboard-user',
        'password' => 'fixture-password',
        'theme' => 'dark',
        'is_developer' => true,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $contract = $this->createStub(CurrentUser::class);
    $contract->method('isAuthenticated')->willReturn(true);
    $contract->method('user')->willReturn($user);
    $contract->method('id')->willReturn($user->id);
    app()->instance(CurrentUser::class, $contract);
});

/**
 * @return list<array{0: string, 1: string}> tag name and attributes of every palette row
 */
function paletteRowTags(string $html): array
{
    $matches = PatternScan::sets('~<([a-zA-Z][\w:.-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~s', $html);

    $rows = [];
    foreach ($matches as $match) {
        if (preg_match('~class="([^"]*)"~', $match[2], $class) !== 1) {
            continue;
        }
        $tokens = preg_split('~\s+~', $class[1]) ?: [];
        if (in_array('palette-row', $tokens, true) || in_array('srch-token-suggest-row', $tokens, true)) {
            $rows[] = [$match[1], $match[2]];
        }
    }

    return $rows;
}

it('renders every palette row as a button rather than a div with a click handler', function (): void {
    $rows = paletteRowTags(Livewire::test(CommandPaletteModal::class)->html());

    expect($rows)->not->toBeEmpty();

    $offenders = [];
    foreach ($rows as [$tag, $attributes]) {
        if ($tag !== 'button') {
            $offenders[] = '<'.$tag.'> row';
        } elseif (! str_contains($attributes, 'data-palette-row')) {
            $offenders[] = 'button row without data-palette-row';
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders));
});

it('gives the results list a listbox contract the search input can point at', function (): void {
    $html = Livewire::test(CommandPaletteModal::class)->html();

    expect($html)->toContain('id="palette-results-listbox"')
        ->and($html)->toContain('role="listbox"')
        ->and($html)->toContain('role="combobox"')
        ->and($html)->toContain('aria-controls="palette-results-listbox"')
        ->and($html)->toContain(':aria-activedescendant=')
        ->and($html)->toContain("'palette-option-' + activeIndex")
        ->and($html)->toContain('role="option"')
        ->and($html)->toContain(':aria-selected="i === activeIndex"')
        ->and($html)->toContain(':tabindex="i === activeIndex ? 0 : -1"');
});

// Verified in Chromium against the rendered component: with the guard, Enter on
// a focused row runs that row once; without it the handler runs
// results[activeIndex] instead and its preventDefault swallows the row the user
// was actually on.
it('leaves Enter on a focused row to the browser rather than handling it twice', function (): void {
    $source = (string) file_get_contents(base_path('resources/js/palette.js'));

    expect($source)->toContain("const ROW_SELECTOR = '[data-palette-row]';")
        ->and($source)->toContain('const onRow = e.target instanceof Element && e.target.closest(ROW_SELECTOR) !== null;');

    $enterBranch = mb_strstr($source, "if (e.key === 'Enter') {\n            if (onRow) {\n                return;\n            }");

    expect($enterBranch)->not->toBeFalse('the Enter branch of onKey no longer returns early for a focused row');
});

it('hands focus back to whatever opened the palette when it closes', function (): void {
    $source = (string) file_get_contents(base_path('resources/js/palette.js'));

    expect($source)->toContain('this._returnFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : null;')
        ->and($source)->toContain('const origin = this._returnFocusTo;')
        ->and($source)->toContain('origin.focus();');
});

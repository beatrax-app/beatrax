<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Chains\Internal\Http\Livewire\ChainsIndex;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Lang;

uses(RefreshDatabase::class);

// /chains is the least self-explanatory screen in the product: the word names
// a data structure, and the page it heads shows cards of transactions with no
// sentence saying why they are grouped. The subtitle describes the cards; the
// tip has to answer what a chain is at all.

/** @return list<string> every locale the app is offered in */
function chainsHelpLocales(): array
{
    return array_map(static fn (Locale $case): string => $case->value, Locale::cases());
}

it('ships the sentence in every language, none of them the English one', function (): void {
    $english = (require base_path('Modules/Chains/Resources/lang/en/help.php'))['index'];

    $problems = [];
    foreach (array_diff(chainsHelpLocales(), ['en']) as $locale) {
        $file = base_path('Modules/Chains/Resources/lang/'.$locale.'/help.php');
        if (! is_file($file)) {
            $problems[] = $locale.' (no file)';

            continue;
        }

        $translated = (require $file)['index'] ?? '';
        if ($translated === '' || $translated === $english) {
            $problems[] = $locale;
        }
    }

    expect($problems)->toBe([]);
});

it('draws the tip beside the heading, named after it', function (): void {
    $user = User::create([
        'username' => 'chains-help-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $html = Livewire::test(ChainsIndex::class)->html();

    expect($html)->toContain('id="help-tip-chains"')
        ->and($html)->toContain('popovertarget="help-tip-chains"')
        ->and($html)->toContain('aria-label="'.e(Lang::get('core::help.tip.about', ['subject' => Lang::get('chains::index.heading')])).'"')
        ->and($html)->toContain(e(Lang::get('chains::help.index')));
});

// The heading is one word and the page has an empty state, so the tip has to
// survive the case where there is nothing on the screen to infer meaning from.
it('is on the page before there is a single chain to look at', function (): void {
    $user = User::create([
        'username' => 'chains-help-empty-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $html = Livewire::test(ChainsIndex::class)->html();

    expect($html)->toContain('data-testid="chains-index-empty"')
        ->and($html)->toContain('id="help-tip-chains"');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Enums\CurrencyView;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

// Settings and the transactions list write one preference — which amount a row
// prints, never which rows are listed. Two wordings for one control drift the
// moment only one is edited, so what is asserted is that both surfaces render
// one string per locale, not what that one string happens to say.

/** @return string the on-screen text of the amount-preference option carrying $value */
function amountPreferenceOption(string $html, string $value): string
{
    $matched = preg_match(
        '/<option\b[^>]*\bvalue="'.preg_quote($value, '/').'"[^>]*>(.*?)<\/option>/s',
        $html,
        $match,
    );

    expect($matched)->toBe(1, "No amount-preference option rendered for value=\"{$value}\".");

    return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES));
}

function amountPreferenceReader(string $locale): User
{
    return User::query()->create([
        'username' => 'amount-preference-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('amount-preference-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => CurrencyView::BaseOnly->value,
        'locale' => $locale,
    ]);
}

it('offers the Dutch reader the words the transactions list uses, not a currency', function (): void {
    $this->actingAs(amountPreferenceReader('nl'));
    App::setLocale('nl');

    $html = Livewire::test(SettingsPage::class)->html();

    expect(amountPreferenceOption($html, CurrencyView::BaseOnly->value))->toBe('Verrekend bedrag')
        ->and(amountPreferenceOption($html, CurrencyView::Original->value))->toBe('Oorspronkelijk bedrag')
        ->and($html)->toContain('Bedragweergave')
        ->and($html)->not->toContain('Alleen EUR')
        ->and($html)->not->toContain('Oorspronkelijke valuta')
        ->and($html)->not->toContain('Valutaweergave');
});

it('reads the same as the transactions list in every locale', function (): void {
    $pairs = [
        'eur_only' => 'currency_eur',
        'original' => 'currency_original',
    ];

    $offenders = [];

    foreach (Locale::cases() as $locale) {
        App::setLocale($locale->value);

        foreach ($pairs as $settingsKey => $listKey) {
            $settings = Lang::get("core::settings.currency_display.{$settingsKey}");
            $list = Lang::get("ledger::list.{$listKey}");

            if ($settings !== $list) {
                $offenders[] = "{$locale->value}.{$settingsKey}: \"{$settings}\" vs \"{$list}\"";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('leaves no locale a currency code to fill in', function (): void {
    $offenders = [];

    foreach (Locale::cases() as $locale) {
        App::setLocale($locale->value);

        foreach (Lang::group('core::settings.currency_display') as $key => $line) {
            if (str_contains($line, ':code')) {
                $offenders[] = "{$locale->value}.{$key}: {$line}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the stored preference value the settings page writes', function (): void {
    // The label is display text; the enum value is what users.default_currency_view
    // holds and what ?currency= carries. Renaming it resets every reader's choice.
    expect(CurrencyView::BaseOnly->value)->toBe('eur_only')
        ->and(CurrencyView::Original->value)->toBe('original');
});

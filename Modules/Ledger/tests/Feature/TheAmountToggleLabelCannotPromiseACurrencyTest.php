<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\CurrencyView;
use Modules\Ledger\Public\ValueObjects\Money;

// A code or a symbol in the label is a promise about the rows, and the rows are
// free to break it: an account denominated outside the reader's base currency
// prints its own money whatever the control claims above it.
const AMOUNT_TOGGLE_CURRENCY_TOKENS = ['EUR', 'USD', 'GBP', 'JPY', 'CHF', '€', '$', '£', '¥', ':code'];

// Below this a shared token stops being evidence the two labels describe one
// subject: two-letter fragments collide across unrelated words.
const AMOUNT_TOGGLE_MIN_SHARED_TOKEN = 4;

/** @return string the on-screen text of the segmented option carrying $value */
function amountToggleLabel(string $html, string $value): string
{
    $matched = preg_match('/<ui-radio\b[^>]*\bvalue="'.preg_quote($value, '/').'"[^>]*>(.*?)<\/ui-radio>/s', $html, $match);

    expect($matched)->toBe(1, "No segmented option rendered for value=\"{$value}\".");

    return trim(strip_tags($match[1]));
}

/** @return string the accessible name the segmented group announces */
function amountToggleGroupName(string $html): string
{
    $matched = preg_match('/<ui-radio-group\b[^>]*\baria-label="([^"]*)"/', $html, $match);

    expect($matched)->toBe(1, 'The segmented group renders no accessible name at all.');

    return html_entity_decode($match[1], ENT_QUOTES);
}

/** @return list<string> the tokens of AMOUNT_TOGGLE_CURRENCY_TOKENS present in $label */
function amountToggleCurrencyClaims(string $label): array
{
    return array_values(array_filter(
        AMOUNT_TOGGLE_CURRENCY_TOKENS,
        static fn (string $token): bool => str_contains($label, $token),
    ));
}

/** @return list<string> the lowercased words of $label at or above the shared-token floor */
function amountToggleWords(string $label): array
{
    $words = preg_split('/[^\p{L}]+/u', mb_strtolower($label)) ?: [];

    return array_values(array_filter(
        $words,
        static fn (string $word): bool => mb_strlen($word) >= AMOUNT_TOGGLE_MIN_SHARED_TOKEN,
    ));
}

/** @return bool whether some word of $name occurs inside every one of $options */
function amountToggleNamesOneSubject(string $name, string ...$options): bool
{
    $haystacks = array_map(static fn (string $option): string => mb_strtolower($option), $options);

    foreach (amountToggleWords($name) as $word) {
        $inAll = true;
        foreach ($haystacks as $haystack) {
            $inAll = $inAll && str_contains($haystack, $word);
        }

        if ($inAll) {
            return true;
        }
    }

    return false;
}

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $this->run = $this->makeImportRun($this->fixtureUser);

    $this->foreignAccount = Account::query()->create([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Google Play',
        'slug' => 'google-play',
        'kind' => 'paypal',
        'iban' => 'GOOGLE-PLAY',
        'default_currency' => 'USD',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    app()->setLocale('en');
});

// The device case: a base-EUR reader, an account denominated in USD, one row
// settled in USD. The row is right to print dollars — the settled amount IS the
// dollar figure — so the only thing that can be wrong is the label above it.
it('names no currency the rows can contradict when an account is denominated outside the base currency', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->foreignAccount, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'USD',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'Spotify Premium (1 month)',
    ]);

    $html = Livewire::test(TransactionsList::class)->set('currency', CurrencyView::BaseOnly->value)->html();

    expect($html)->toContain(Money::ofMinor(-1299, 'USD')->format());

    $labels = [
        'group' => amountToggleGroupName($html),
        CurrencyView::BaseOnly->value => amountToggleLabel($html, CurrencyView::BaseOnly->value),
        CurrencyView::Original->value => amountToggleLabel($html, CurrencyView::Original->value),
    ];

    foreach ($labels as $where => $label) {
        expect($label)->not->toBe('');
        expect(amountToggleCurrencyClaims($label))->toBe([], "{$where} reads \"{$label}\", a currency the visible rows do not keep.");
    }
});

// The group's accessible name is announced before either option, so a reader on
// a screen reader hears the framing first. It has to be the framing the options
// answer to, or the control introduces itself as something it is not.
it('introduces the group as the same subject both of its options name', function (): void {
    $html = Livewire::test(TransactionsList::class)->html();

    $name = amountToggleGroupName($html);
    $baseOnly = amountToggleLabel($html, CurrencyView::BaseOnly->value);
    $original = amountToggleLabel($html, CurrencyView::Original->value);

    expect(amountToggleNamesOneSubject($name, $baseOnly, $original))
        ->toBeTrue("\"{$name}\" shares no word with both \"{$baseOnly}\" and \"{$original}\".");
});

it('ships labels that claim no currency in any locale', function (): void {
    $offenders = [];

    foreach (glob(base_path('Modules/Ledger/Resources/lang/*/list.php')) ?: [] as $file) {
        $locale = basename(dirname($file));
        app()->setLocale($locale);

        foreach (['currency_aria', 'currency_eur', 'currency_original'] as $key) {
            $label = Lang::get("ledger::list.{$key}");

            if (amountToggleCurrencyClaims($label) !== []) {
                $offenders[] = "{$locale}.{$key}: {$label}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the group and its options on one subject in every locale', function (): void {
    $offenders = [];

    foreach (glob(base_path('Modules/Ledger/Resources/lang/*/list.php')) ?: [] as $file) {
        $locale = basename(dirname($file));
        app()->setLocale($locale);

        $name = Lang::get('ledger::list.currency_aria');
        $baseOnly = Lang::get('ledger::list.currency_eur');
        $original = Lang::get('ledger::list.currency_original');

        if (! amountToggleNamesOneSubject($name, $baseOnly, $original)) {
            $offenders[] = "{$locale}: \"{$name}\" vs \"{$baseOnly}\" / \"{$original}\"";
        }
    }

    expect($offenders)->toBe([]);
});

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\ValueObjects\Money;

// Both renderings of the list are in the DOM at once and CSS picks one, so an
// assertion made over the whole document cannot tell which width drew a value.
// TransactionsListCurrencyToggleTest asserts the settled amount is present in
// original mode and passes on the desktop table alone — the phone card never
// carried the line, and the test could not see the difference. These two scope
// to a half.
beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->run = $this->makeImportRun($this->fixtureUser);

    $this->makeTransaction($this->fixtureUser, $account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1207,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'USD Merchant',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{phone: string, desktop: string}
 */
function halvesOfTheList(string $html): array
{
    // splitOpen is the desktop table's own x-data and nothing else in the
    // document carries it, which is why it divides the two widths where the
    // wrapper's utility classes do not: 'hidden md:block' appears four times,
    // three of them in the toolbar above both lists, so splitting on the first
    // would hand back a header as the phone half and pass on desktop markup.
    $split = strpos($html, 'splitOpen');

    expect($split)->not->toBeFalse('the desktop table no longer carries splitOpen — this split no longer separates the two widths')
        ->and(substr_count($html, 'splitOpen'))->toBe(1, 'splitOpen is no longer unique, so it cannot mark where the desktop table begins');

    return [
        'phone' => substr($html, 0, (int) $split),
        'desktop' => substr($html, (int) $split),
    ];
}

it('draws the settled amount on the phone card, not only in the desktop table', function (): void {
    $settledEur = Money::ofMinor(-1207, 'EUR')->format();
    $nativeUsd = Money::ofMinor(-1299, 'USD')->format();

    $halves = halvesOfTheList(
        Livewire::test(TransactionsList::class)->set('currency', 'original')->html()
    );

    expect($halves['phone'])->toContain($nativeUsd)
        ->and($halves['phone'])->toContain($settledEur)
        ->and($halves['desktop'])->toContain($settledEur);
})->group('phase-3');

it('leaves the settled line off both widths when the reader asked for one amount', function (): void {
    $halves = halvesOfTheList(
        Livewire::test(TransactionsList::class)->set('currency', 'eur_only')->html()
    );

    expect($halves['phone'])->not->toContain('data-secondary-amount')
        ->and($halves['desktop'])->not->toContain('data-secondary-amount');
})->group('phase-3');

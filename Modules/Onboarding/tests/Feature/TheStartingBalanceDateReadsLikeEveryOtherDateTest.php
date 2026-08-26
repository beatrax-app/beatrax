<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Fmt;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\StartingBalanceCard;

// Step 6 of the wizard shows the preview rows above the starting-balance cards
// on one screen. The rows read 02/02/2026 and the card underneath read
// "on 2026-02-01" — the raw column value, straight from the detector. Fmt is
// what every other date on that screen goes through, and it follows the
// reader's locale.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'sbc-date',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var Account $account */
    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN account',
        'slug' => 'asn-date',
        'kind' => 'bank',
        'iban' => 'NL03ASNB0123450002',
        'default_currency' => 'EUR',
    ]);
});

it('writes the detected date in the reader\'s own format, not the stored one', function (): void {
    $html = Livewire::test(StartingBalanceCard::class, [
        'accountId' => $this->account->id,
        'accountLabel' => 'ASN bank',
        'accountShort' => '6789',
        'currency' => 'EUR',
        'detectedMinor' => 215891,
        'detectedDate' => '2026-02-01',
        'state' => 'detected',
    ])->html();

    expect($html)->toContain(Fmt::shortDate('2026-02-01'))
        ->and($html)->not->toContain('on 2026-02-01');
});

it('writes a conflict candidate\'s date in the reader\'s own format too', function (): void {
    $html = Livewire::test(StartingBalanceCard::class, [
        'accountId' => $this->account->id,
        'accountLabel' => 'ASN bank',
        'accountShort' => '6789',
        'currency' => 'EUR',
        'detectedMinor' => 215891,
        'detectedDate' => '2026-02-01',
        'state' => 'conflict',
        'alternativeCandidates' => [
            ['minor' => 215891, 'date' => '2026-02-01', 'sourceLabel' => 'CAMT.053'],
            ['minor' => 100000, 'date' => '2026-03-17', 'sourceLabel' => 'MT940'],
        ],
    ])->html();

    expect($html)->toContain(Fmt::shortDate('2026-03-17'))
        ->and($html)->not->toContain('2026-03-17<');
});

it('writes the confirmed line in the reader\'s own format as well', function (): void {
    $html = Livewire::test(StartingBalanceCard::class, [
        'accountId' => $this->account->id,
        'accountLabel' => 'ASN bank',
        'accountShort' => '6789',
        'currency' => 'EUR',
        'detectedMinor' => 215891,
        'detectedDate' => '2026-02-01',
        'state' => 'detected',
    ])->call('confirm')->html();

    expect($html)->toContain(Fmt::shortDate('2026-02-01'))
        ->and($html)->not->toContain('on 2026-02-01');
});

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

// PotWriter refuses a withdrawal past nought, but it refuses it in PHP inside
// its own transaction against a cross-row sum no index can express. Two devices
// used apart each take the whole balance, both rows are creates under the merge
// rules, both land, and the pot reads below zero with nothing saying why.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-12 09:00:00');

    $this->user = User::create([
        'username' => 'pot-overdrawn-'.bin2hex(random_bytes(4)),
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-overdrawn',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => Currency::Eur->value,
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/overdrawn.xml',
        'sha256' => hash('sha256', 'overdrawn-'.bin2hex(random_bytes(4))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_in',
        'posted_at' => '2026-08-01',
        'booked_at' => '2026-08-01 12:00:00',
        'value_date' => '2026-08-01',
        'amount_minor' => 200_000,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => 200_000,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'Salaris',
        'counterparty_normalized' => 'salaris-overdrawn',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('overdrawn1', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    // The row shape OpLogEntryApplier writes for an arriving `create_row`, which
    // is what every pot_movements op is: the table carries `_create_required`
    // and no LWW field, and the applier has no payload gate for it.
    $this->peerWithdrawal = function (int $potId, int $amountMinor): void {
        DB::table('pot_movements')->insert([
            'id' => 9_002,
            'user_id' => $this->user->id,
            'pot_id' => $potId,
            'counterpart_pot_id' => null,
            'amount_minor' => -$amountMinor,
            'currency' => Currency::Eur->value,
            'kind' => 'withdraw',
            'memo' => null,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('flags a pot whose movements sum below zero', function (): void {
    $pot = app(PotWriter::class)->save($this->user, 'Vakantie', '100,00', $this->account->id, null, null);
    app(PotWriter::class)->withdraw($this->user, (int) $pot->id, '100,00');

    ($this->peerWithdrawal)((int) $pot->id, 100_00);

    $row = app(PotBalanceQuery::class)->forUser($this->user)[0];

    expect($row->balanceMinor)->toBe(-10_000)
        ->and($row->isOverdrawn())->toBeTrue();
});

it('names the shortfall on the pot card and says what to do about it', function (): void {
    $pot = app(PotWriter::class)->save($this->user, 'Vakantie', '100,00', $this->account->id, null, null);
    app(PotWriter::class)->withdraw($this->user, (int) $pot->id, '100,00');

    ($this->peerWithdrawal)((int) $pot->id, 100_00);

    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertSee(Lang::get('pots::messages.recon.overdrawn', [
            'amount' => Money::ofMinor(10_000, Currency::Eur->value)->format(),
        ]));
});

it('draws the shortfall on the phone list and on the desktop card alike', function (): void {
    $pot = app(PotWriter::class)->save($this->user, 'Vakantie', '100,00', $this->account->id, null, null);
    app(PotWriter::class)->withdraw($this->user, (int) $pot->id, '100,00');

    ($this->peerWithdrawal)((int) $pot->id, 100_00);

    $html = (string) Livewire::test(PotsPage::class)->html();

    $phoneStart = strpos($html, 'pots-phone-list');
    $desktopStart = strpos($html, 'pots-desktop-list space-y-8');
    expect($phoneStart)->not->toBeFalse()->and($desktopStart)->not->toBeFalse();

    $phoneHtml = substr($html, (int) $phoneStart, (int) $desktopStart - (int) $phoneStart);
    $desktopHtml = substr($html, (int) $desktopStart);

    $sentence = e(Lang::get('pots::messages.recon.overdrawn', [
        'amount' => Money::ofMinor(10_000, Currency::Eur->value)->format(),
    ]));

    expect($phoneHtml)->toContain($sentence)
        ->and($desktopHtml)->toContain($sentence);
});

it('says nothing of the kind about a pot that still holds money', function (): void {
    app(PotWriter::class)->save($this->user, 'Vakantie', '50,00', $this->account->id, null, null);

    $row = app(PotBalanceQuery::class)->forUser($this->user)[0];

    expect($row->balanceMinor)->toBe(5_000)
        ->and($row->isOverdrawn())->toBeFalse();

    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertDontSee(Lang::get('pots::messages.recon.overdrawn', [
            'amount' => Money::ofMinor(5_000, Currency::Eur->value)->format(),
        ]));
});

it('says nothing of the kind about a pot emptied to exactly nought', function (): void {
    $pot = app(PotWriter::class)->save($this->user, 'Vakantie', '50,00', $this->account->id, null, null);
    app(PotWriter::class)->withdraw($this->user, (int) $pot->id, '50,00');

    $row = app(PotBalanceQuery::class)->forUser($this->user)[0];

    expect($row->balanceMinor)->toBe(0)
        ->and($row->isOverdrawn())->toBeFalse();

    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertDontSee(Lang::get('pots::messages.recon.overdrawn', [
            'amount' => Money::ofMinor(0, Currency::Eur->value)->format(),
        ]));
});

// The sentences that quote a pot's balance as money the reader may still take
// out or get back. Below zero there is neither: the figure invited a withdrawal
// the writer refuses, and archiving takes the shortfall back OUT of unallocated
// rather than returning anything at all.
it('offers nothing to take out of a pot that is already below zero', function (): void {
    $pot = app(PotWriter::class)->save($this->user, 'Vakantie', '100,00', $this->account->id, null, null);
    app(PotWriter::class)->withdraw($this->user, (int) $pot->id, '100,00');

    ($this->peerWithdrawal)((int) $pot->id, 100_00);

    Livewire::test(PotsPage::class)
        ->set('operationPotId', (int) $pot->id)
        ->assertOk()
        ->assertSee(Lang::get('pots::messages.available_in', [
            'name' => 'Vakantie',
            'amount' => Money::ofMinor(0, Currency::Eur->value)->format(),
        ]))
        ->assertDontSee(Lang::get('pots::messages.available_in', [
            'name' => 'Vakantie',
            'amount' => Money::ofMinor(-10_000, Currency::Eur->value)->format(),
        ]));
});

it('offers the whole balance of a pot that holds money', function (): void {
    $pot = app(PotWriter::class)->save($this->user, 'Vakantie', '50,00', $this->account->id, null, null);

    Livewire::test(PotsPage::class)
        ->set('operationPotId', (int) $pot->id)
        ->assertOk()
        ->assertSee(Lang::get('pots::messages.available_in', [
            'name' => 'Vakantie',
            'amount' => Money::ofMinor(5_000, Currency::Eur->value)->format(),
        ]));
});

it('promises no release from a pot that has nothing to give back', function (): void {
    $pot = app(PotWriter::class)->save($this->user, 'Vakantie', '100,00', $this->account->id, null, null);
    app(PotWriter::class)->withdraw($this->user, (int) $pot->id, '100,00');

    ($this->peerWithdrawal)((int) $pot->id, 100_00);

    Livewire::test(PotsPage::class)
        ->set('archivingPotId', (int) $pot->id)
        ->assertOk()
        ->assertSee(Lang::get('pots::messages.archive_confirm_overdrawn', [
            'amount' => Money::ofMinor(10_000, Currency::Eur->value)->format(),
        ]))
        ->assertDontSee(Lang::get('pots::messages.archive_confirm', [
            'amount' => Money::ofMinor(0, Currency::Eur->value)->format(),
        ]))
        ->assertDontSee(Lang::get('pots::messages.archive_confirm', [
            'amount' => Money::ofMinor(-10_000, Currency::Eur->value)->format(),
        ]));
});

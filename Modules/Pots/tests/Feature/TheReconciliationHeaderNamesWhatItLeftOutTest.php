<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

// Pots are denominated in the account's own currency, so every other line the
// account holds is left out of real, allocated and unallocated entirely. Every
// other money surface in the app names what it left out; this header just
// printed a smaller number.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Revolut',
        'slug' => 'revolut',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/recon.xml',
        'sha256' => str_repeat('7', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->post = function (int $amountMinor, string $currency, int $seq): void {
        Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'type' => $amountMinor < 0 ? 'expense' : 'transfer_in',
            'posted_at' => CarbonImmutable::now()->subDays(5)->toDateString(),
            'booked_at' => CarbonImmutable::now()->subDays(5)->toDateString().' 12:00:00',
            'value_date' => CarbonImmutable::now()->subDays(5)->toDateString(),
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => $currency,
            'counterparty_name' => 'Recon',
            'counterparty_normalized' => 'recon-'.$seq,
            'normalization_version' => 1,
            'source_format' => 'camt053',
            'import_run_id' => $this->run->id,
            'source_row_index' => $seq,
            'fingerprint' => str_pad('recon'.$seq, 64, '0', STR_PAD_LEFT),
            'fingerprint_version' => 1,
        ]);
    };
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

it('names a currency the account holds that no pot figure counts', function (): void {
    ($this->post)(50000, 'EUR', 1);
    ($this->post)(80000, 'AED', 2);

    app(PotWriter::class)->save($this->user, 'Buffer', '100,00', $this->account->id, null, null);

    $row = app(PotBalanceQuery::class)->reconciliationForAccount($this->account->id, $this->user);

    expect($row->realBalanceMinor)->toBe(50000)
        ->and($row->allocatedMinor)->toBe(10000)
        ->and($row->unconverted)->toBe(['AED'])
        ->and($row->isPartial())->toBeTrue();

    $html = (string) Livewire::test(PotsPage::class)->html();

    expect(substr_count($html, 'data-not-converted="true"'))->toBeGreaterThan(0)
        ->and($html)->toContain('AED');
});

it('says nothing about conversion for an account holding one currency', function (): void {
    ($this->post)(50000, 'EUR', 3);

    app(PotWriter::class)->save($this->user, 'Buffer', '100,00', $this->account->id, null, null);

    expect(app(PotBalanceQuery::class)->reconciliationForAccount($this->account->id, $this->user)->isPartial())
        ->toBeFalse();

    expect((string) Livewire::test(PotsPage::class)->html())
        ->not->toContain('data-not-converted="true"');
});

// The page subtitle claimed the pots ALWAYS add up to the real balance, and the
// banner directly beneath it said by how much they did not. Over-allocation is
// surfaced by design, so the subtitle was the false half and it is the one that
// changed.
it('does not promise the pots always add up while telling the reader they do not', function (): void {
    ($this->post)(20000, 'EUR', 4);

    app(PotWriter::class)->save($this->user, 'Buffer', '200,00', $this->account->id, null, null);

    ($this->post)(-113035, 'EUR', 5);

    $row = app(PotBalanceQuery::class)->reconciliationForAccount($this->account->id, $this->user);

    expect($row->isOverAllocated)->toBeTrue()
        ->and($row->unallocatedMinor)->toBe(-113035);

    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertSee(Lang::get('pots::messages.recon.over_allocated', [
            'amount' => Money::ofMinor(113035, 'EUR')->format(),
        ]))
        ->assertDontSee('always add up');

    expect(Lang::get('pots::messages.subtitle'))->not->toContain('always add up');
});

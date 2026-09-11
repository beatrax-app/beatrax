<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotMovementKind;
use Modules\Pots\Public\Services\PotWriter;

uses(RefreshDatabase::class);

// A pot two devices emptied apart settles on archive with a POSITIVE
// `released_on_archive` row, and the history line took its colour and its '+'
// from the kind. Four of the five kinds have a fixed sign and the fifth does
// not, so the kind stopped being able to answer for the direction and the one
// case that is money coming back was drawn in the grey an outflow wears.

const RELEASE_SIGN_IN = 'shrink-0 text-sm tabular-nums text-emerald-700 dark:text-emerald-400';

const RELEASE_SIGN_OUT = 'shrink-0 text-sm tabular-nums text-slate-500 dark:text-slate-400';

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-19 10:00:00');

    $this->user = User::create([
        'username' => 'release-sign-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'release-sign-asn-'.bin2hex(random_bytes(4)),
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => Currency::Eur->value,
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/release-sign.xml',
        'sha256' => hash('sha256', 'release-sign-'.bin2hex(random_bytes(4))),
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
        'amount_minor' => 500_00,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => 500_00,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'Salaris',
        'counterparty_normalized' => 'salaris-release-sign',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('releasesign', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $this->writer = app(PotWriter::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function releaseSignPot(User $user, Account $account, string $name, ?string $initial = null): Pot
{
    return app(PotWriter::class)->save($user, $name, $initial, (int) $account->id, null, null);
}

// The row shape OpLogEntryApplier writes for an arriving `create_row`. Nothing
// re-checks a cross-row sum on arrival, so this is how a pot ends up below what
// it held and the only way the release below is ever written at a plus.
function releaseSignPeerWithdrawal(int $userId, int $potId, int $amountMinor, int $rowId): void
{
    releaseSignMovement($userId, $potId, -$amountMinor, PotMovementKind::Withdraw, $rowId);
}

// What `PotWriter::archive()` inserts, minus the status flip that would move the
// pot into the archived list — where no history is drawn. The applier takes the
// movement and the `pots.status` edit as separate ops, so an active pot already
// carrying its release is a state a peer really does deliver.
function releaseSignRelease(int $userId, int $potId, int $amountMinor, int $rowId): void
{
    releaseSignMovement($userId, $potId, $amountMinor, PotMovementKind::ReleasedOnArchive, $rowId);
}

function releaseSignMovement(int $userId, int $potId, int $amountMinor, PotMovementKind $kind, int $rowId): void
{
    DB::table('pot_movements')->insert([
        'id' => $rowId,
        'user_id' => $userId,
        'pot_id' => $potId,
        'counterpart_pot_id' => null,
        'amount_minor' => $amountMinor,
        'currency' => Currency::Eur->value,
        'kind' => $kind->value,
        'memo' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

function releaseSignPage(): RenderedMarkup
{
    return RenderedMarkup::of((string) Livewire::test(PotsPage::class)->html());
}

/**
 * The amount cell of one history line: the colours it wears and the figure it
 * prints, which together are the whole of the direction claim.
 *
 * @return array{class: string, text: string}
 */
function releaseSignAmountCell(RenderedMarkup $page, int $potId, string $label): array
{
    $lines = $page->all('#pot-history-'.$potId.' li');

    foreach ($lines as $line) {
        if (! str_contains($line->text(), $label)) {
            continue;
        }

        $cell = $line->firstOrFail('span.shrink-0');

        return ['class' => (string) $cell->attribute('class'), 'text' => $cell->text()];
    }

    throw new RuntimeException('Pot '.$potId.' drew no history line labelled "'.$label.'" — it drew '.count($lines).'.');
}

it('draws the release that settles an overdrawn pot as money coming back in', function (): void {
    $pot = releaseSignPot($this->user, $this->account, 'Boodschappen');
    releaseSignPeerWithdrawal((int) $this->user->id, (int) $pot->id, 40_00, 9_101);
    releaseSignRelease((int) $this->user->id, (int) $pot->id, 40_00, 9_102);

    $cell = releaseSignAmountCell(releaseSignPage(), (int) $pot->id, Lang::get('pots::messages.movement.released_on_archive'));

    expect($cell['class'])->toBe(RELEASE_SIGN_IN)
        ->and($cell['text'])->toBe('+'.Money::ofMinor(40_00, Currency::Eur->value)->format());
});

it('draws the release that hands a funded pot back to unallocated as money going out', function (): void {
    $pot = releaseSignPot($this->user, $this->account, 'Boodschappen', '60,00');
    releaseSignRelease((int) $this->user->id, (int) $pot->id, -60_00, 9_103);

    $cell = releaseSignAmountCell(releaseSignPage(), (int) $pot->id, Lang::get('pots::messages.movement.released_on_archive'));

    expect($cell['class'])->toBe(RELEASE_SIGN_OUT)
        ->and($cell['text'])->toBe(Money::ofMinor(-60_00, Currency::Eur->value)->format());
});

// The regression the amount-derived answer has to clear. Each of these four is
// written at one sign and one sign only, so every one of them must read exactly
// as it read while the kind was answering for it.
it('leaves the four kinds whose sign never moves drawn exactly as they were', function (): void {
    $vakantie = releaseSignPot($this->user, $this->account, 'Vakantie', '100,00');
    $noodfonds = releaseSignPot($this->user, $this->account, 'Noodfonds');

    $this->writer->transfer($this->user, (int) $vakantie->id, (int) $noodfonds->id, '30,00');
    $this->writer->withdraw($this->user, (int) $vakantie->id, '20,00');

    $page = releaseSignPage();

    $fund = releaseSignAmountCell($page, (int) $vakantie->id, Lang::get('pots::messages.movement.fund'));
    expect($fund['class'])->toBe(RELEASE_SIGN_IN, 'A fund is money into the pot and is drawn as incoming.')
        ->and($fund['text'])->toBe('+'.Money::ofMinor(100_00, Currency::Eur->value)->format());

    $withdraw = releaseSignAmountCell($page, (int) $vakantie->id, Lang::get('pots::messages.movement.withdraw'));
    expect($withdraw['class'])->toBe(RELEASE_SIGN_OUT, 'A withdrawal is money out of the pot and is never drawn as incoming.')
        ->and($withdraw['text'])->toBe(Money::ofMinor(-20_00, Currency::Eur->value)->format());

    $movedTo = releaseSignAmountCell($page, (int) $vakantie->id, Lang::get('pots::messages.movement.moved_to', ['name' => 'Noodfonds']));
    expect($movedTo['class'])->toBe(RELEASE_SIGN_OUT, 'The source leg of a transfer is money out of the pot.')
        ->and($movedTo['text'])->toBe(Money::ofMinor(-30_00, Currency::Eur->value)->format());

    $movedFrom = releaseSignAmountCell($page, (int) $noodfonds->id, Lang::get('pots::messages.movement.moved_from', ['name' => 'Vakantie']));
    expect($movedFrom['class'])->toBe(RELEASE_SIGN_IN, 'The target leg of a transfer is money into the pot.')
        ->and($movedFrom['text'])->toBe('+'.Money::ofMinor(30_00, Currency::Eur->value)->format());
});

it('asks about a pot that still holds money in the words it has always used', function (): void {
    $pot = releaseSignPot($this->user, $this->account, 'Vakantie', '75,00');

    Livewire::test(PotsPage::class)
        ->set('archivingPotId', (int) $pot->id)
        ->assertOk()
        ->assertSee(Lang::get('pots::messages.archive_confirm', [
            'amount' => Money::ofMinor(75_00, Currency::Eur->value)->format(),
        ]))
        ->assertDontSee(Lang::get('pots::messages.archive_confirm_overdrawn', [
            'amount' => Money::ofMinor(75_00, Currency::Eur->value)->format(),
        ]));
});

// The sentence a reader confirms has to describe the write it confirms.
// Archiving below zero takes the shortfall back out of unallocated, and the
// clamped figure said "0,00 will return to unallocated" — the wrong amount, the
// wrong direction, and a promise nothing keeps.
it('tells a reader archiving an overdrawn pot what archiving is about to take', function (): void {
    $pot = releaseSignPot($this->user, $this->account, 'Vakantie', '100,00');
    $this->writer->withdraw($this->user, (int) $pot->id, '100,00');
    releaseSignPeerWithdrawal((int) $this->user->id, (int) $pot->id, 100_00, 9_104);

    Livewire::test(PotsPage::class)
        ->set('archivingPotId', (int) $pot->id)
        ->assertOk()
        ->assertSee(Lang::get('pots::messages.archive_confirm_overdrawn', [
            'amount' => Money::ofMinor(100_00, Currency::Eur->value)->format(),
        ]))
        ->assertDontSee(Lang::get('pots::messages.archive_confirm', [
            'amount' => Money::ofMinor(0, Currency::Eur->value)->format(),
        ]));
});

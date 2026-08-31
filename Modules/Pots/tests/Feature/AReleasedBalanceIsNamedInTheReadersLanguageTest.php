<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Public\Enums\PotMovementKind;
use Modules\Pots\Public\Services\PotWriter;

uses(RefreshDatabase::class);

// Archiving a funded pot releases the balance, and the line naming that release
// used to be an English sentence written into `pot_movements.memo`. The memo is
// a synced free-text column, so it froze twice over: in the language of
// whichever device ran the archive, and on the peer that received the row.
// The release now carries its own kind, and the screen names it per reader.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 11:00:00');

    $this->user = User::create([
        'username' => 'pot-release-'.bin2hex(random_bytes(4)),
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-pot-release',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456780',
        'default_currency' => Currency::Eur->value,
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/pot-release.xml',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'import_run_id' => $run->id,
        'type' => TransactionType::TransferIn->value,
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => 50000,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => 50000,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'Salaris',
        'counterparty_normalized' => 'salaris',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'fingerprint' => str_pad('potrelease', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $pot = app(PotWriter::class)->save($this->user, 'Boodschappen', null, $this->account->id, null, null);
    app(PotWriter::class)->fund($this->user, $pot->id, '100,00');
    app(PotWriter::class)->archive($this->user, $pot->id);

    // An archived pot's history is not drawn — the release row reaches a reader
    // once the pot is restored, which is the undo the archive toast offers.
    app(PotWriter::class)->restore($this->user, $pot->id);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function releasedRowLabelIn(string $locale): string
{
    /** @var Translator $translator */
    $translator = app(Translator::class);
    $was = $translator->getLocale();
    $translator->setLocale($locale);

    try {
        return Livewire::test(PotsPage::class)->html();
    } finally {
        $translator->setLocale($was);
    }
}

it('stores the release as a kind rather than a sentence', function (): void {
    $release = DB::table('pot_movements')
        ->where('user_id', $this->user->id)
        ->where('kind', PotMovementKind::ReleasedOnArchive->value)
        ->first();

    expect($release)->not->toBeNull();
    expect($release->memo)->toBeNull(implode("\n", [
        'The release wrote a sentence into the memo. That column is free text on a',
        'synced, create-only ledger, so whatever language wrote it is the language the',
        'peer reads — and an older build has no way to resolve anything put there.',
    ]));
});

it('names the release in the language of whoever is looking', function (): void {
    $english = releasedRowLabelIn('en');
    $dutch = releasedRowLabelIn('nl');

    expect($english)->toContain(Lang::get('pots::messages.movement.released_on_archive'));

    $dutchLine = (string) app(Translator::class)->get('pots::messages.movement.released_on_archive', [], 'nl');
    expect($dutch)->toContain($dutchLine);

    // Held against the English rendering rather than against the key alone: a
    // locale with no line falls back to English on both sides, and an assertion
    // that only names the key passes on exactly the defect it is meant to catch.
    expect($dutchLine)->not->toBe(Lang::get('pots::messages.movement.released_on_archive'));
});

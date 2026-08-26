<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Counterparties\Models\Counterparty;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

// "Review the parsed rows. Nothing is saved to your ledger until you confirm."
// is the promise this screen makes, so the name it prints has to be the name
// the commit writes. On the phone an N26 direct debit to "Nederlandse
// Spoorwegen Reizigers NV" whose payment reference was "OV-chipkaart" previewed
// as OV-chipkaart, and /transactions then showed the carrier.
//
// The chain below is the one all three preview blades render.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);

    // The preset mints its own account identifier because an N26 export carries
    // no own-IBAN column; without a row claiming it every parsed row errors and
    // never reaches the naming chain under test.
    Account::create([
        'user_id' => $this->fixtureUser->id,
        'name' => 'N26 Main',
        'slug' => 'n26-main',
        'kind' => 'bank',
        'iban' => 'N26',
        'default_currency' => 'EUR',
    ]);

    DB::table('merchant_aliases')->insert([
        'user_id' => $this->fixtureUser->id,
        'pattern' => 'Groceries',
        'generalized_pattern' => 'groceries',
        'friendly_name' => 'Groceries Direct',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
});

it('previews a row under the name its own file gives it', function (): void {
    $preview = $this->importer->runFromUpload(
        base_path('Modules/Ingestion/tests/fixtures/csv/n26-sample.csv'),
        'n26-csv',
        $this->fixtureUser,
        'n26-sample.csv',
    );

    $row = collect($preview->rows)->firstWhere('description', 'Groceries');

    expect($row)->not->toBeNull()
        ->and($row->counterpartyName)->toBe('REWE');

    $shown = $row->aliasFriendlyName
        ?? $row->counterpartyName
        ?? $row->counterpartyIban
        ?? $row->description;

    expect($shown)->toBe('REWE');
});

it('commits the row under the same name the preview showed', function (): void {
    $fixture = base_path('Modules/Ingestion/tests/fixtures/csv/n26-sample.csv');

    $preview = $this->importer->runFromUpload($fixture, 'n26-csv', $this->fixtureUser, 'n26-sample.csv');
    $row = collect($preview->rows)->firstWhere('description', 'Groceries');

    $shown = $row->aliasFriendlyName
        ?? $row->counterpartyName
        ?? $row->counterpartyIban
        ?? $row->description;

    $this->importer->runAndConfirm($fixture, 'n26-csv', $this->fixtureUser, 'n26-sample.csv');

    // Found by amount: description is a sensitive column, so the stored value
    // is ciphertext and a where() on it matches nothing.
    /** @var Transaction $committed */
    $committed = Transaction::query()->where('settled_amount_minor', -2345)->firstOrFail();

    /** @var Counterparty $entity */
    $entity = Counterparty::query()->findOrFail($committed->counterparty_id);

    expect($entity->display_name)->toBe($shown)
        ->and($committed->counterparty_name)->toBe($shown)
        ->and($entity->merchant_name)->toBe('Groceries Direct');
});

<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Shell\Internal\Http\Livewire\SampleDataCard;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

// The Dev Console is closed on a store build, so this card is the sample
// dataset's only door on one. It wrote 341 readable values into sealed columns
// for a reader whose ledger was encrypted: the demo transaction seeders insert
// through the base query builder, which bypasses casts, model events and the
// codec alike.
//
// Sealing them alone is not the fix. Six demo seeders found their rows by the
// literal description they had seeded, and a sealed description matches
// nothing — so a ledger that sealed correctly would arrive with no chain
// links, split legs, tax tags, transfer pairs or receipt conflict.

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-sample-dataset
 */
function sampleDataReader(string $name): User
{
    return User::query()->create([
        'username' => $name.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Asserted rather than assumed: without this the sealed count below is zero for
// a reader the codec was passing through in the clear, which is the state the
// defect was invisible in.
function sampleDataEnrolledReader(string $name): User
{
    $reader = sampleDataReader($name);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(EncryptionMigrationService::class)->migrate($reader, $session);

    expect(app(SensitiveColumnCodec::class)->canSeal((int) $reader->id, $session))
        ->toBeTrue('The reader cannot seal, so every column below is written in the clear by design.');

    return $reader;
}

/**
 * @return array<string, array{written: int, readable: int}> per registered column
 */
function sampleDataSealedColumnCensus(User $reader): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    /** @var Session $session */
    $session = app(Session::class);

    $census = [];

    foreach (SensitiveFieldRegistry::columns() as $qualified) {
        [$table, $column] = explode('.', $qualified, 2);

        $values = $db->connection()->table($table)
            ->where('user_id', $reader->id)
            ->whereNotNull($column)
            ->pluck($column)
            ->all();

        $readable = 0;
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            if (! $codec->decryptValue($table, $column, $value, (int) $reader->id, $session)['decrypted']) {
                $readable++;
            }
        }

        $census[$qualified] = ['written' => count($values), 'readable' => $readable];
    }

    return $census;
}

/**
 * The rows the six seeders that used to match on a sealed description build.
 *
 * @return array<string, int|array<string, int>|list<int>>
 */
function sampleDataDerivedRows(User $reader): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $chainLinks = [];
    foreach ($db->connection()->table('chain_links')->where('user_id', $reader->id)->pluck('kind') as $kind) {
        $chainLinks[(string) $kind] = ($chainLinks[(string) $kind] ?? 0) + 1;
    }
    ksort($chainLinks);

    $settlements = $db->connection()->table('transactions')
        ->where('user_id', $reader->id)
        ->whereIn('type', ['transfer_in', 'transfer_out'])
        ->orderBy('id')
        ->pluck('amount_minor')
        ->map(static fn (mixed $minor): int => (int) $minor)
        ->all();
    sort($settlements);

    return [
        'chain_links' => $chainLinks,
        'split_legs' => $db->connection()->table('transaction_splits')->where('user_id', $reader->id)->count(),
        'tax_tags' => $db->connection()->table('tax_transaction_tags')->where('user_id', $reader->id)->count(),
        'transfer_pair_legs' => $db->connection()->table('transactions')->where('user_id', $reader->id)->whereNotNull('pair_transaction_id')->count(),
        'receipt_conflicts' => $db->connection()->table('pending_enrichment_conflicts')->where('user_id', $reader->id)->count(),
        'counterparties' => $db->connection()->table('counterparties')->where('user_id', $reader->id)->count(),
        'transfer_amounts' => $settlements,
    ];
}

it('writes no readable value into a registered column for a reader who can seal', function (): void {
    $reader = sampleDataEnrolledReader('sample-sealed');

    Livewire::actingAs($reader)->test(SampleDataCard::class)->call('load');

    $census = sampleDataSealedColumnCensus($reader);

    $written = array_sum(array_column($census, 'written'));
    expect($written)->toBeGreaterThan(300, 'The load wrote almost nothing into the registered columns, so a clean count below is an empty ledger.');

    $leaked = [];
    foreach ($census as $qualified => $counts) {
        if ($counts['readable'] > 0) {
            $leaked[] = $qualified.' '.$counts['readable'].' of '.$counts['written'];
        }
    }

    expect($leaked)->toBe([], implode("\n", [
        'A control a store build carries wrote plaintext into columns the registry seals.',
        'The reader could seal throughout — every one of these is readable on disk:',
        ...$leaked,
    ]));
});

// The control the case above cannot be read without: it is the same census over
// a reader who never enabled encryption, and it has to come back readable. A
// census that had stopped seeing plaintext would report the sealed reader clean
// whatever was on disk, and this arm answers the same before the fix and after.
it('leaves an unenrolled readers sample data in the clear, which is what makes the count above a measurement', function (): void {
    $reader = sampleDataReader('sample-plain');

    Livewire::actingAs($reader)->test(SampleDataCard::class)->call('load');

    $census = sampleDataSealedColumnCensus($reader);

    foreach (['transactions.description', 'transactions.counterparty_name', 'counterparties.display_name'] as $qualified) {
        expect($census[$qualified]['written'])->toBeGreaterThan(0)
            ->and($census[$qualified]['readable'])->toBe(
                $census[$qualified]['written'],
                $qualified.' came back sealed for a reader who never enabled encryption.'
            );
    }
});

// Sealing the writer without moving these off the sealed description seals
// correctly and silently empties the dataset, which is why it is one change.
it('builds the same derived rows for a sealed ledger as for a plaintext one', function (): void {
    $plain = sampleDataReader('sample-derived-plain');
    Livewire::actingAs($plain)->test(SampleDataCard::class)->call('load');
    $fromPlaintext = sampleDataDerivedRows($plain);

    $sealed = sampleDataEnrolledReader('sample-derived-sealed');
    Livewire::actingAs($sealed)->test(SampleDataCard::class)->call('load');
    $fromSealed = sampleDataDerivedRows($sealed);

    // Equality alone is satisfied by two empty ledgers, so each side has to
    // hold rows before the comparison means anything.
    expect($fromPlaintext['chain_links'])->not->toBe([])
        ->and($fromPlaintext['split_legs'])->toBeGreaterThan(0)
        ->and($fromPlaintext['tax_tags'])->toBeGreaterThan(0)
        ->and($fromPlaintext['transfer_pair_legs'])->toBeGreaterThan(0)
        ->and($fromPlaintext['receipt_conflicts'])->toBeGreaterThan(0);

    expect($fromSealed)->toBe($fromPlaintext, implode("\n", [
        'The sealed ledger derived different rows from the same seed. Six demo seeders',
        'find their rows again by a value transactions.description holds, and a sealed',
        'description matches nothing — chain links, split legs, tax tags, transfer pairs,',
        'the receipt conflict and the ICS settlement rewrite all hang off that match.',
    ]));
});

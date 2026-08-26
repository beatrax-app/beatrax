<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Internal\Services\StripAsnDescriptionDelimiters;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(EnablesEncryptionForUser::class);

// Read off an iPhone 12 mini after importing a real ASN export: the ledger
// held 'Rentevergoeding tweede kwartaal' with the delimiter quotes. The
// adapter fix only changes what the NEXT import writes, so these are the rows
// eight shipped releases already have.
const SADD_WRAPPED = "'Rentevergoeding tweede kwartaal'";

const SADD_UNWRAPPED = 'Rentevergoeding tweede kwartaal';

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'asn-delimiters-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN betaalrekening',
        'slug' => 'asn-betaalrekening',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = $this->makeImportRun($this->user, str_repeat('e', 64));

    $this->seedRow = function (string $description, string $sourceFormat = SourceFormat::AsnCsv->value): int {
        $transaction = $this->makeTransaction($this->user, $this->account, $this->importRun, [
            'description' => $description,
            'source_format' => $sourceFormat,
        ]);

        app(SearchIndexWriterContract::class)->upsertForTransaction((int) $transaction->id, (int) $this->user->id);

        return (int) $transaction->id;
    };

    $this->storedDescription = fn (int $id): ?string => $this->app->make(DatabaseManager::class)
        ->connection()
        ->table('transactions')
        ->where('id', $id)
        ->value('description');

    $this->searchBody = fn (int $id): ?string => $this->app->make(DatabaseManager::class)
        ->connection()
        ->table('transaction_search_docs')
        ->where('transaction_id', $id)
        ->value('search_body');

    $this->ftsHits = fn (string $needle): int => count($this->app->make(DatabaseManager::class)
        ->connection()
        ->table('transaction_search_fts')
        ->whereRaw('transaction_search_fts MATCH ?', ['"'.str_replace('"', '""', $needle).'"'])
        ->pluck('rowid')
        ->all());

    $this->backfill = fn (): int => $this->app->make(StripAsnDescriptionDelimiters::class)->run();
});

it('removes the delimiter quotes an already-imported ASN row still carries', function (): void {
    $id = ($this->seedRow)(SADD_WRAPPED);

    expect(($this->storedDescription)($id))->toBe(SADD_WRAPPED);

    expect(($this->backfill)())->toBe(1);

    expect(($this->storedDescription)($id))->toBe(SADD_UNWRAPPED);
});

it('leaves an apostrophe that is punctuation rather than a delimiter alone', function (): void {
    $internal = ($this->seedRow)("Bakker's Delft");
    $leading = ($this->seedRow)("'Betaalverzoek");
    $trailing = ($this->seedRow)("Maandtermijn'");

    expect(($this->backfill)())->toBe(0);

    expect(($this->storedDescription)($internal))->toBe("Bakker's Delft")
        ->and(($this->storedDescription)($leading))->toBe("'Betaalverzoek")
        ->and(($this->storedDescription)($trailing))->toBe("Maandtermijn'");
});

// ING's CSV rides the generic preset adapter, not AsnCsvAdapter, and the quotes
// in an n26/revolut/ing-nl description are the bank's own punctuation.
it('leaves a row from a source format that never reached AsnCsvAdapter alone', function (): void {
    $other = ($this->seedRow)(SADD_WRAPPED, 'ing-nl-csv');
    $asn = ($this->seedRow)(SADD_WRAPPED);

    expect(($this->backfill)())->toBe(1);

    expect(($this->storedDescription)($other))->toBe(SADD_WRAPPED)
        ->and(($this->storedDescription)($asn))->toBe(SADD_UNWRAPPED);
});

it('changes nothing on a second run', function (): void {
    $id = ($this->seedRow)(SADD_WRAPPED);

    expect(($this->backfill)())->toBe(1);
    $afterFirst = ($this->storedDescription)($id);

    expect(($this->backfill)())->toBe(0)
        ->and(($this->storedDescription)($id))->toBe($afterFirst)
        ->and($afterFirst)->toBe(SADD_UNWRAPPED);
});

it('is safe on an install with no ASN rows at all', function (): void {
    ($this->seedRow)('Maandelijkse bijdrage', 'camt053');

    expect(($this->backfill)())->toBe(0);
});

it('rewrites the search document so the quoted form stops matching', function (): void {
    $id = ($this->seedRow)(SADD_WRAPPED);

    expect(($this->searchBody)($id))->toContain(SADD_WRAPPED)
        ->and(($this->ftsHits)("'Rentevergoeding"))->toBe(1);

    ($this->backfill)();

    expect(($this->searchBody)($id))->toContain(SADD_UNWRAPPED)
        ->and(($this->searchBody)($id))->not->toContain(SADD_WRAPPED)
        ->and(($this->ftsHits)("'Rentevergoeding"))->toBe(0)
        ->and(($this->ftsHits)('Rentevergoeding tweede'))->toBe(1);
});

it('finds the row by the plain narrative through the search the reader uses', function (): void {
    ($this->seedRow)(SADD_WRAPPED);

    ($this->backfill)();

    /** @var SearchQuery $search */
    $search = $this->app->make(SearchQuery::class);

    expect($search->search($this->user, 'Rentevergoeding', SearchFilters::empty())->totalCount)->toBe(1)
        ->and($search->search($this->user, "'Rentevergoeding", SearchFilters::empty())->totalCount)->toBe(0);
});

// Decrypting plaintext is a documented no-op, so a sealed ledger is the only
// state that proves the pass reads and writes through the codec rather than
// straight past it.
it('unwraps through the codec on a sealed ledger whose key this process holds', function (): void {
    $session = $this->enablesEncryptionForUser($this->user);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    $sealed = $codec->encryptValue('transactions', 'description', SADD_WRAPPED, (int) $this->user->id, $session);

    $id = ($this->seedRow)(SADD_WRAPPED);
    $this->app->make(DatabaseManager::class)->connection()
        ->table('transactions')->where('id', $id)->update(['description' => $sealed]);

    expect(($this->storedDescription)($id))->toBe($sealed)
        ->and($sealed)->not->toBe(SADD_WRAPPED);

    expect(($this->backfill)())->toBe(1);

    $after = ($this->storedDescription)($id);

    expect($after)->not->toBe(SADD_UNWRAPPED)
        ->and($codec->decryptValue('transactions', 'description', (string) $after, (int) $this->user->id, $session)['value'])
        ->toBe(SADD_UNWRAPPED);
});

it('leaves the audit copy in raw_payload exactly as the bank wrote it', function (): void {
    $transaction = $this->makeTransaction($this->user, $this->account, $this->importRun, [
        'description' => SADD_WRAPPED,
        'raw_payload' => [17 => SADD_WRAPPED],
    ]);

    ($this->backfill)();

    /** @var Transaction $reloaded */
    $reloaded = Transaction::query()->findOrFail($transaction->id);

    expect($reloaded->description)->toBe(SADD_UNWRAPPED)
        ->and($reloaded->raw_payload)->toBe([17 => SADD_WRAPPED]);
});

it('leaves a sealed ledger this process cannot open completely untouched', function (): void {
    $session = $this->enablesEncryptionForUser($this->user);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    $sealed = $codec->encryptValue('transactions', 'description', SADD_WRAPPED, (int) $this->user->id, $session);

    $id = ($this->seedRow)(SADD_WRAPPED);
    $this->app->make(DatabaseManager::class)->connection()
        ->table('transactions')->where('id', $id)->update(['description' => $sealed]);

    AppLockTestHarness::lock($session);

    expect(($this->backfill)())->toBe(0)
        ->and(($this->storedDescription)($id))->toBe($sealed);
});

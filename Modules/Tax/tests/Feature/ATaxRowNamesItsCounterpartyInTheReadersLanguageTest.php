<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;
use Modules\Tax\Internal\Services\TaxYearQuery;
use Modules\Tax\Public\Services\TaxTagQuery;

uses(EnablesEncryptionForUser::class);

// `counterparties.display_name` holds the app's own English for the rows the
// resolver had to name itself, and `metadata.default_name` says so. Every read
// inside Modules/Counterparties goes through the seam that turns that back into
// the reader's word; the tax cockpit, its CSV/PDF exports and the batch-tag
// banner read the column straight, so a Dutch reader got "Unknown" on a row the
// counterparty screen was already calling "Onbekend".

function trclUser(DatabaseManager $db, string $username): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function trclCounterparty(DatabaseManager $db, int $userId, string $type, string $slug, string $name, ?string $token, ?string $stored = null): int
{
    return (int) $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => $type,
        'slug' => $slug,
        'display_name' => $stored ?? $name,
        'iban' => null,
        'merchant_name' => null,
        'metadata' => $token === null
            ? null
            : json_encode(CounterpartyDefaultName::mark([], $token), JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function trclEncryptedName(int $userId, Session $session, string $name): string
{
    /** @var SensitiveColumnCodec $codec */
    $codec = Container::getInstance()->make(SensitiveColumnCodec::class);
    $ciphertext = $codec->encryptValue('counterparties', 'display_name', $name, $userId, $session);

    expect($ciphertext)->not->toBe($name);

    return $ciphertext;
}

function trclTransaction(DatabaseManager $db, int $userId, int $counterpartyId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TRCL ASN '.$suffix,
        'slug' => 'trcl-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/trcl-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'trcl-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'counterparty_id' => $counterpartyId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'trcl-tx-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 3,
        'posted_at' => '2025-03-01',
        'booked_at' => '2025-03-01 00:00:00',
        'value_date' => '2025-03-01',
        'type' => 'expense',
        'amount_minor' => -4990,
        'currency' => 'EUR',
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'trcl-vendor',
        'counterparty_name' => 'TRCL Vendor BV',
        'normalization_version' => 1,
        'description' => 'TRCL test transaction',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function trclTag(DatabaseManager $db, int $userId, int $txId): void
{
    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => null,
        'tax_year_override' => null,
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return list<string|null>
 */
function trclCockpitNames(int $userId): array
{
    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);

    $names = [];
    foreach ($query->forUser($userId, 2025)->categories as $category) {
        foreach ($category['rows'] as $row) {
            $names[] = is_string($row['counterpartyName'] ?? null) ? $row['counterpartyName'] : null;
        }
    }

    return $names;
}

afterEach(function (): void {
    app()->setLocale('en');
});

it('names the app-invented counterparty on a cockpit row in the reader s language', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = trclUser($db, 'trcl-cockpit');
    $cpId = trclCounterparty($db, $userId, 'unknown', 'unknown', 'Unknown', CounterpartyDefaultName::UNKNOWN);
    trclTag($db, $userId, trclTransaction($db, $userId, $cpId));

    app()->setLocale('nl');

    expect(trclCockpitNames($userId))->toBe(['Onbekend']);
});

it('leaves a counterparty the reader named in the reader s own words', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = trclUser($db, 'trcl-named');
    $cpId = trclCounterparty($db, $userId, 'merchant', 'unknown-shop', 'Unknown Shop', null);
    trclTag($db, $userId, trclTransaction($db, $userId, $cpId));

    app()->setLocale('nl');

    expect(trclCockpitNames($userId))->toBe(['Unknown Shop']);
});

it('names the app-invented counterparty on the batch-tag banner in the reader s language', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = trclUser($db, 'trcl-banner');
    $cpId = trclCounterparty($db, $userId, 'government', 'government', 'Government', CounterpartyDefaultName::GOVERNMENT);
    $txId = trclTransaction($db, $userId, $cpId);
    trclTransaction($db, $userId, $cpId);

    app()->setLocale('nl');

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);
    $suggestion = $query->untaggedCountForCounterparty($userId, $txId, 2025);

    expect($suggestion->counterpartyName)->toBe('Overheid')
        ->and($suggestion->untaggedCount)->toBe(1);
});

// Decryption has to happen first: the seam reads the plaintext name, and
// `metadata` is not a sensitive column, so an encrypted install reaches the
// same answer by the same route.
it('translates after decrypting, so an encrypted install reads it too', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = trclUser($db, 'trcl-encrypted');
    /** @var User $user */
    $user = User::query()->findOrFail($userId);
    $session = $this->enablesEncryptionForUser($user);

    $cpId = trclCounterparty(
        $db,
        $userId,
        'unknown',
        'unknown',
        'Unknown',
        CounterpartyDefaultName::UNKNOWN,
        trclEncryptedName($userId, $session, 'Unknown'),
    );
    $txId = trclTransaction($db, $userId, $cpId);
    trclTag($db, $userId, $txId);
    trclTransaction($db, $userId, $cpId);

    app()->setLocale('nl');

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);

    expect(trclCockpitNames($userId))->toBe(['Onbekend'])
        ->and($query->untaggedCountForCounterparty($userId, $txId, 2025)->counterpartyName)->toBe('Onbekend');
});

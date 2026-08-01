<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;
use Modules\Tax\Public\Services\TaxTagQuery;

uses(EnablesEncryptionForUser::class);

/*
 * 14.1-10 Task 2 (D-06 cosmetic-display cluster) — under an encrypted
 * user, `counterparties.display_name` is ciphertext at rest.
 * TaxTagQuery::untaggedCountForCounterparty's $cpName must decrypt it
 * for the "Also tag N more from [Gym] this year?" prompt rather than
 * leaking ciphertext.
 */

function ttqddUser(DatabaseManager $db): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => 'ttqdd-user-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ttqddEncryptedCounterparty(DatabaseManager $db, int $userId, Session $session, string $displayName): int
{
    /** @var SensitiveColumnCodec $codec */
    $codec = Container::getInstance()->make(SensitiveColumnCodec::class);
    $ciphertext = $codec->encryptValue('counterparties', 'display_name', $displayName, $userId, $session);

    expect($ciphertext)->not->toBe($displayName);

    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'display_name' => $ciphertext,
        'slug' => 'ttqdd-cp-'.bin2hex(random_bytes(4)),
        'type' => 'merchant',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ttqddTransaction(DatabaseManager $db, int $userId, int $cpId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TTQDD ASN '.$suffix,
        'slug' => 'ttqdd-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ttqdd-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'ttqdd-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'counterparty_id' => $cpId,
        'type' => 'expense',
        'posted_at' => '2025-01-01',
        'booked_at' => '2025-01-01 12:00:00',
        'value_date' => '2025-01-01',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ttqdd',
        'normalization_version' => 1,
        'source_format' => 'asn_csv',
        'import_run_id' => $runId,
        'source_row_index' => 1,
        'fingerprint' => str_pad('ttqdd-'.$suffix, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('untaggedCountForCounterparty decrypts the counterparty name for an encrypted user', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = ttqddUser($db);
    /** @var User $user */
    $user = User::query()->findOrFail($userId);
    $session = $this->enablesEncryptionForUser($user);

    $cpId = ttqddEncryptedCounterparty($db, $userId, $session, 'Neighbourhood Gym');
    $txId = ttqddTransaction($db, $userId, $cpId);

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);
    $suggestion = $query->untaggedCountForCounterparty($userId, $txId, 2025);

    expect($suggestion->counterpartyId)->toBe($cpId)
        ->and($suggestion->counterpartyName)->toBe('Neighbourhood Gym');
});

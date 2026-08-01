<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Public\Services\UncategorizedTriageQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(EnablesEncryptionForUser::class);

/*
 * 14.1-10 Task 3 (D-06 fold-in, per 14.1-AUDIT.md Cluster 1) — under an
 * encrypted user, `transactions.counterparty_name` AND `description`
 * are both ciphertext at rest. UncategorizedTriageQuery::mapRow() must
 * decrypt both for the `/uncategorized` triage inbox rather than
 * leaking ciphertext into a TriageRow. No SQL predicate/ORDER BY on
 * either column exists at this site, so the D-09 guard cannot catch
 * an unfixed leak here — only this decrypt-for-display test does.
 */

function uctddUser(): User
{
    return User::query()->create([
        'username' => 'uctdd-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function uctddEncryptedTx(
    DatabaseManager $db,
    Session $session,
    User $user,
    string $counterpartyName,
    string $description,
): int {
    /** @var SensitiveColumnCodec $codec */
    $codec = Container::getInstance()->make(SensitiveColumnCodec::class);
    $encryptedCounterparty = $codec->encryptValue('transactions', 'counterparty_name', $counterpartyName, (int) $user->id, $session);
    $encryptedDescription = $codec->encryptValue('transactions', 'description', $description, (int) $user->id, $session);
    expect($encryptedCounterparty)->not->toBe($counterpartyName);
    expect($encryptedDescription)->not->toBe($description);

    $suffix = bin2hex(random_bytes(4));

    $accountId = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'UCTDD ASN '.$suffix,
        'slug' => 'uctdd-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
    ])->id;

    $runId = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/uctdd-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'uctdd-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
    ])->id;

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 12:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => $encryptedCounterparty,
        'description' => $encryptedDescription,
        'counterparty_normalized' => 'uctdd-fixture',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'asn-csv',
        'import_run_id' => $runId,
        'source_row_index' => 1,
        'fingerprint' => str_pad('uctdd-'.$suffix, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('UncategorizedTriageQuery decrypts counterparty_name and description for an encrypted user', function (): void {
    /** @var DatabaseManager $db */
    $db = Container::getInstance()->make(DatabaseManager::class);

    $user = uctddUser();
    $session = $this->enablesEncryptionForUser($user);

    uctddEncryptedTx($db, $session, $user, 'Neighbourhood Gym', 'Monthly membership fee');

    /** @var UncategorizedTriageQuery $query */
    $query = Container::getInstance()->make(UncategorizedTriageQuery::class);
    $batch = $query->for($user);

    expect($batch->rows)->toHaveCount(1);
    expect($batch->rows[0]->counterpartyName)->toBe('Neighbourhood Gym');
    expect($batch->rows[0]->description)->toBe('Monthly membership fee');
});

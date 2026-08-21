<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Sync\Public\Services\BlindIndexCodec;

uses(RefreshDatabase::class);

// The sweep has to move every column that is compared against a matching key,
// not just the one the column is named after. A half-swept device keeps
// matching on a value nothing computes any more, and does so silently.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
const CKS_IBAN = 'NL57ASNB0123456789';

const CKS_PAYER_IBAN = 'NL22INGB0006543210';

function cksUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'cks-'.$suffix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'recurring_detection_window_months' => 12,
    ]);
}

function cksAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN cks',
        'slug' => 'cks-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => CKS_IBAN,
        'default_currency' => 'EUR',
    ]);
}

function cksTransaction(User $user, Account $account, string $normalized): int
{
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cks.csv',
        'sha256' => hash('sha256', 'cks-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::parse('2026-07-01 09:00:00'),
        'status' => 'previewed',
    ]);

    return (int) app(DatabaseManager::class)->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 12:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -2450,
        'currency' => 'EUR',
        'settled_amount_minor' => -2450,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => $normalized,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'fingerprint' => str_pad('cks'.bin2hex(random_bytes(6)), 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);
}

function cksSignature(string $matchingKey, string $fundingIban): string
{
    return hash('sha256', $matchingKey.'|'.$fundingIban);
}

function cksEnable(User $user): Session
{
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(EncryptionMigrationService::class)->migrate($user, $session);

    return $session;
}

// The auto-promotion counter matches confirmed links on this hash. Composed
// from the matching key, so re-keying the column without re-keying the hash
// resets the counter to zero with nothing on screen.
it('rewrites a chain link signature so it still matches what the resolver computes after the sweep', function (): void {
    $user = cksUser('chain');
    $account = cksAccount($user);
    $txId = cksTransaction($user, $account, 'spotify ab');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $txId,
        'to_transaction_id' => cksTransaction($user, $account, 'paypal'),
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => '1.000',
        'resolver' => 'auto',
        'evidence' => json_encode([
            'matched_iban' => CKS_IBAN,
            'signature_hash' => cksSignature('spotify ab', CKS_IBAN),
        ], JSON_THROW_ON_ERROR),
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);

    $session = cksEnable($user);

    $derived = app(CounterpartyKey::class)->forNormalized('spotify ab', (int) $user->id);
    expect(BlindIndexCodec::looksDerived($derived))->toBeTrue();

    $evidence = json_decode((string) $db->connection()->table('chain_links')->where('user_id', $user->id)->value('evidence'), true);

    expect($evidence['signature_hash'])->toBe(cksSignature($derived, CKS_IBAN));
    expect($evidence['matched_iban'])->toBe(CKS_IBAN);
});

it('leaves a chain link signature alone when no account IBAN reproduces it', function (): void {
    $user = cksUser('chain-foreign');
    $account = cksAccount($user);
    $txId = cksTransaction($user, $account, 'spotify ab');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $foreign = str_repeat('9', 64);
    $db->connection()->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $txId,
        'to_transaction_id' => cksTransaction($user, $account, 'paypal'),
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => '1.000',
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => $foreign], JSON_THROW_ON_ERROR),
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);

    cksEnable($user);

    $evidence = json_decode((string) $db->connection()->table('chain_links')->where('user_id', $user->id)->value('evidence'), true);
    expect($evidence['signature_hash'])->toBe($foreign);
});

// A decrypted IBAN written verbatim into a plaintext column puts back exactly
// the identifier the AEAD column one table over is protecting.
it('keys an income cluster IBAN under its own domain', function (): void {
    $user = cksUser('income');
    cksAccount($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('recurring_series')->insert([
        'user_id' => $user->id,
        'direction' => 'income',
        'detected_name' => 'Employer BV',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => 250000,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => 250000,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'income::'.CKS_PAYER_IBAN.'::eur::monthly',
        'cluster_counterparty_key' => CKS_PAYER_IBAN,
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);

    $session = cksEnable($user);

    $stored = (string) $db->connection()->table('recurring_series')->where('user_id', $user->id)->value('cluster_counterparty_key');

    expect(BlindIndexCodec::looksDerived($stored))->toBeTrue();
    expect($stored)->toBe(app(CounterpartyKey::class)->forIban(CKS_PAYER_IBAN, (int) $user->id));
    expect($stored)->not->toBe(app(CounterpartyKey::class)->forNormalized(CKS_PAYER_IBAN, (int) $user->id));
});

it('keys an expense cluster name under the counterparty domain, not the IBAN one', function (): void {
    $user = cksUser('expense');
    cksAccount($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('recurring_series')->insert([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'Spotify',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1099,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::spotify ab::eur::monthly',
        'cluster_counterparty_key' => 'spotify ab',
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);

    cksEnable($user);

    $stored = (string) $db->connection()->table('recurring_series')->where('user_id', $user->id)->value('cluster_counterparty_key');

    expect($stored)->toBe(app(CounterpartyKey::class)->forNormalized('spotify ab', (int) $user->id));
});

// Chunked passes page by id. A pagination bug leaves the tail unconverted, and
// a merchant whose key was missed silently stops matching its transactions.
it('converts every merchants row past the chunk boundary', function (): void {
    $user = cksUser('chunk');
    cksAccount($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $rows = [];
    for ($i = 0; $i < 620; $i++) {
        $rows[] = [
            'user_id' => $user->id,
            'name' => 'Merchant '.$i,
            'normalized_name' => 'merchant '.$i,
            'created_at' => '2026-07-01 12:00:00',
            'updated_at' => '2026-07-01 12:00:00',
        ];
    }
    foreach (array_chunk($rows, 200) as $batch) {
        $db->connection()->table('merchants')->insert($batch);
    }

    cksEnable($user);

    $unconverted = 0;
    foreach ($db->connection()->table('merchants')->where('user_id', $user->id)->pluck('normalized_name') as $value) {
        if (! BlindIndexCodec::looksDerived((string) $value)) {
            $unconverted++;
        }
    }

    expect($unconverted)->toBe(0);
});

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

// The PayPal alias the ASN-direct arm matches a partner transaction against.
// It is a counterparty's IBAN: `accounts` never holds it, `known_counterparty_ibans` does.
const CKS_ALIAS_IBAN = 'LU89751000135104200E';

// Neither an account IBAN, nor a registered alias, nor on any evidence blob.
const CKS_UNRELATED_IBAN = 'BE68539007547034';

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

/**
 * @param  array<string, mixed>  $evidence
 */
function cksChainLink(User $user, int $fromTxId, int $toTxId, array $evidence): void
{
    app(DatabaseManager::class)->connection()->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $fromTxId,
        'to_transaction_id' => $toTxId,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => '1.000',
        'resolver' => 'auto',
        'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);
}

function cksAlias(User $user, string $realIban): void
{
    app(DatabaseManager::class)->connection()->table('known_counterparty_ibans')->insert([
        'user_id' => $user->id,
        'real_iban' => $realIban,
        'target_account_kind' => 'paypal',
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);
}

/**
 * @return array<string, mixed>
 */
function cksEvidence(User $user): array
{
    $evidence = json_decode(
        (string) app(DatabaseManager::class)->connection()->table('chain_links')->where('user_id', $user->id)->value('evidence'),
        true,
    );

    expect($evidence)->toBeArray();

    return $evidence;
}

// A hash the sweep cannot reproduce belongs to another arm — IcsSettlementResolver
// composes one over an account id and a period end — and re-keying that would
// break the arm it belongs to. Genuinely unrelated, not merely unrecognised.
it('leaves a chain link signature alone when no IBAN any arm could have hashed reproduces it', function (): void {
    $user = cksUser('chain-foreign');
    $account = cksAccount($user);
    $txId = cksTransaction($user, $account, 'spotify ab');
    $foreign = cksSignature('spotify ab', CKS_UNRELATED_IBAN);

    cksChainLink($user, $txId, cksTransaction($user, $account, 'paypal'), ['signature_hash' => $foreign]);

    cksEnable($user);

    expect(cksEvidence($user)['signature_hash'])->toBe($foreign);
});

// PaypalFundingResolver::asnDirectLink() hashes the matched partner's IBAN — a
// counterparty's, which `accounts` never holds — and it can only have come from
// the alias set the arm matched against. Miss it and the link orphans from its
// own resolver, and ConfirmChainLink's three-link counter resets.
it('rewrites an ASN-direct chain link signature built from a registered counterparty alias IBAN', function (): void {
    $user = cksUser('chain-alias');
    $account = cksAccount($user);
    $txId = cksTransaction($user, $account, 'google cloud emea limited');
    cksAlias($user, CKS_ALIAS_IBAN);

    cksChainLink($user, $txId, cksTransaction($user, $account, 'paypal'), [
        'matched_via' => 'asn_alias_amount_date',
        'signature_hash' => cksSignature('google cloud emea limited', CKS_ALIAS_IBAN),
    ]);

    cksEnable($user);

    $derived = app(CounterpartyKey::class)->forNormalized('google cloud emea limited', (int) $user->id);

    expect(cksEvidence($user)['signature_hash'])->toBe(cksSignature($derived, CKS_ALIAS_IBAN));
});

// Links minted before the resolver stopped writing the plaintext IBAN still
// carry it, and it is the cheapest reproduction there is: the value is right
// there in the blob the sweep is already parsing.
it('rewrites a chain link signature from the IBAN on its own evidence when no account holds it', function (): void {
    $user = cksUser('chain-evidence');
    $account = cksAccount($user);
    $txId = cksTransaction($user, $account, 'google cloud emea limited');

    cksChainLink($user, $txId, cksTransaction($user, $account, 'paypal'), [
        'matched_iban' => CKS_ALIAS_IBAN,
        'signature_hash' => cksSignature('google cloud emea limited', CKS_ALIAS_IBAN),
    ]);

    cksEnable($user);

    $derived = app(CounterpartyKey::class)->forNormalized('google cloud emea limited', (int) $user->id);

    expect(cksEvidence($user)['signature_hash'])->toBe(cksSignature($derived, CKS_ALIAS_IBAN));
});

function cksSeries(User $user, string $direction, string $counterpartyKey, string $clusterKey): void
{
    app(DatabaseManager::class)->connection()->table('recurring_series')->insert([
        'user_id' => $user->id,
        'direction' => $direction,
        'detected_name' => 'Employer BV',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => 250000,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => 250000,
        'variance_tolerance_percent' => 25,
        'cluster_key' => $clusterKey,
        'cluster_counterparty_key' => $counterpartyKey,
        'created_at' => '2026-07-01 12:00:00',
        'updated_at' => '2026-07-01 12:00:00',
    ]);
}

function cksSeriesColumn(User $user, string $column): string
{
    return (string) app(DatabaseManager::class)->connection()
        ->table('recurring_series')
        ->where('user_id', $user->id)
        ->value($column);
}

// A decrypted IBAN written verbatim into a plaintext column puts back exactly
// the identifier the AEAD column one table over is protecting.
it('keys an income cluster IBAN under its own domain', function (): void {
    $user = cksUser('income');
    cksAccount($user);
    cksSeries($user, 'income', CKS_PAYER_IBAN, 'income::'.CKS_PAYER_IBAN.'::eur::monthly');

    cksEnable($user);

    $stored = cksSeriesColumn($user, 'cluster_counterparty_key');

    expect(BlindIndexCodec::looksDerived($stored))->toBeTrue();
    expect($stored)->toBe(app(CounterpartyKey::class)->forIban(CKS_PAYER_IBAN, (int) $user->id));
    expect($stored)->not->toBe(app(CounterpartyKey::class)->forNormalized(CKS_PAYER_IBAN, (int) $user->id));
});

// cluster_key is composed OVER the counterparty key. Rekeying one column and not
// the other leaves the payer's IBAN slugged into an indexed column beside the
// sealed copy, and it is the column the op-log and the sync merge rules carry.
it('leaves no readable payer IBAN in cluster_key after the sweep', function (): void {
    $user = cksUser('income-cluster');
    cksAccount($user);
    cksSeries($user, 'income', CKS_PAYER_IBAN, 'income::'.strtolower(CKS_PAYER_IBAN).'::eur::monthly');

    cksEnable($user);

    expect(cksSeriesColumn($user, 'cluster_key'))->not->toContain(strtolower(CKS_PAYER_IBAN));
});

// Nothing on the import path compacts or upper-cases an IBAN before it reaches
// transactions.counterparty_iban, and the detector that wrote this column stored
// what it decrypted. A bank that prints the IBAN in groups of four therefore
// leaves a series keyed under a value the live detector never computes again.
it('keys an income cluster IBAN the statement printed with spaces under the IBAN domain', function (string $stored): void {
    $user = cksUser('income-spaced');
    cksAccount($user);
    cksSeries($user, 'income', $stored, 'income::'.strtolower(str_replace(' ', '', $stored)).'::eur::monthly');

    cksEnable($user);

    expect(cksSeriesColumn($user, 'cluster_counterparty_key'))
        ->toBe(app(CounterpartyKey::class)->forIban($stored, (int) $user->id));
})->with([
    'grouped in fours' => 'NL22 INGB 0006 5432 10',
    'lower case' => 'nl22ingb0006543210',
    'padded' => '  NL22INGB0006543210  ',
]);

it('keys an expense cluster name under the counterparty domain, not the IBAN one', function (): void {
    $user = cksUser('expense');
    cksAccount($user);
    cksSeries($user, 'expense', 'spotify ab', 'expense::spotify ab::eur::monthly');

    cksEnable($user);

    expect(cksSeriesColumn($user, 'cluster_counterparty_key'))
        ->toBe(app(CounterpartyKey::class)->forNormalized('spotify ab', (int) $user->id));
});

// An expense series never holds an IBAN, so its matching key is never shape-tested.
// A merchant whose normalised name reads like one keys under the counterparty
// domain because of the row it is on, not because of how it happens to spell.
it('keys an expense cluster name that reads like an IBAN under the counterparty domain', function (): void {
    $user = cksUser('expense-ibanish');
    cksAccount($user);
    cksSeries($user, 'expense', 'nl22 ingb 0006543210', 'expense::nl22-ingb-0006543210::eur::monthly');

    cksEnable($user);

    expect(cksSeriesColumn($user, 'cluster_counterparty_key'))
        ->toBe(app(CounterpartyKey::class)->forNormalized('nl22 ingb 0006543210', (int) $user->id));
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

<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Modules\Community\Public\Services\ClassificationRuleProvider;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

/*
 * Counterparties are created by the resolver during import, but they carry the
 * user's own work: the triage screen is where you say "yes, this IBAN is
 * Versio". Without capture that decision stayed on one device.
 *
 * The values go onto the event as PLAINTEXT. OpLogWriter encrypts sensitive
 * columns itself under the GDK epoch and the backfiller decrypts before
 * handing them over, so passing the stored ciphertext would encrypt it twice
 * and the peer would never read it back.
 */

it('writes the counterparty to the op log in plaintext, not stored ciphertext', function (): void {
    $user = User::query()->create([
        'username' => 'cpc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'cpc-device',
        'userId' => (int) $user->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));

    $counterparty = Counterparty::query()->create([
        'user_id' => $user->id,
        'slug' => 'versio-'.bin2hex(random_bytes(3)),
        'type' => 'merchant',
        'display_name' => 'Versio',
    ]);

    event(new EntityMutated(
        table: 'counterparties',
        pk: (int) $counterparty->id,
        userId: (int) $user->id,
        mutationType: 'create',
        dirtyFields: [
            'user_id' => $user->id,
            'slug' => $counterparty->slug,
            'type' => 'merchant',
            'display_name' => 'Versio',
        ],
    ));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $ops = $db->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'counterparties')
        ->get();

    expect($ops)->not->toBeEmpty()
        ->and($ops->pluck('op_type')->unique()->all())->toBe(['create_row'])
        ->and($ops->pluck('field')->all())->toContain('display_name', 'slug', 'type');
});

it('routes the display name through the op log\'s own encryption, not the column\'s', function (): void {
    $user = User::query()->create([
        'username' => 'cpc2-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'cpc2-device',
        'userId' => (int) $user->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));

    event(new EntityMutated(
        table: 'counterparties',
        pk: 4242,
        userId: (int) $user->id,
        mutationType: 'create',
        dirtyFields: ['display_name' => 'Versio'],
    ));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'counterparties')
        ->where('field', 'display_name')
        ->first();

    expect($row)->not->toBeNull();

    // It must round-trip to the exact plaintext the event carried. Asserting
    // only that it was non-empty passed for a base64 blob as well, which is
    // the single failure this file exists to catch.
    $stored = is_string($row->value) ? $row->value : '';

    expect($stored)->not->toBe('')
        ->and(json_decode($stored, true, 512, JSON_THROW_ON_ERROR))->toBe('Versio');
});

it('sends the metadata a rule match produced, where website and logo_url live', function (): void {
    $user = User::query()->create([
        'username' => 'cpc3-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'cpc3-device',
        'userId' => (int) $user->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'cpc3 account',
        'slug' => 'cpc3-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00CPC3'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    // A government rule match is the path that produces metadata; the merchant
    // path passes [] and would prove nothing.
    $rules = app(ClassificationRuleProvider::class)->governmentRules();
    $plain = null;
    foreach ($rules as $rule) {
        if (! str_starts_with($rule->pattern, 'regex:')) {
            $plain = $rule->pattern;
            break;
        }
    }

    expect($plain)->toBeString();

    app(CounterpartyResolver::class)->resolve(
        makeCpCaptureTx((int) $account->id, (int) $user->id, (string) $plain),
        $user,
    );

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'counterparties')
        ->where('field', 'metadata')
        ->first();

    // Omitted from the create op, so the counterparty landed on the peer with
    // website and logo_url blank and no later op ever filled them in.
    expect($row)->not->toBeNull('the counterparty create op carried no metadata');

    $decoded = json_decode((string) $row->value, true);

    expect($decoded)->toBeArray()
        ->and($decoded['matched_keyword'] ?? null)->toBe($plain);
});

function makeCpCaptureTx(int $accountId, int $userId, string $description): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: $userId,
        accountId: $accountId,
        type: 'expense',
        postedAt: CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        bookedAt: CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        valueDate: CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        amountMinor: -1099,
        currency: 'EUR',
        settledAmountMinor: -1099,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: null,
        counterpartyIban: null,
        counterpartyNormalized: 'fixture',
        normalizationVersion: 1,
        description: $description,
        categoryId: null,
        sourceFormat: 'asn_csv',
        importRunId: 0,
        sourceRowIndex: 0,
        sourceRef: null,
    );
}

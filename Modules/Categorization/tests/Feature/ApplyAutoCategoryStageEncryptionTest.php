<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Internal\Pipeline\ApplyAutoCategoryStage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// The stage matches on the pre-persistence CanonicalTransaction, so the values
// it compares are plaintext whether or not the user has encryption on: there
// is no decrypt-before-match gap here, unlike ReapplyRulesJob reading the
// persisted columns. This proves that holds end-to-end for an encrypted user.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'auto-cat-enc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->session = $this->enablesEncryptionForUser($this->user);
    $this->actingAs($this->user);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN auto-cat-enc',
        'slug' => 'auto-cat-enc-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->streamingId = DB::table('categories')->insertGetId([
        'user_id' => null,
        'name' => 'Streaming auto-cat-enc',
        'slug' => 'auto-cat-enc-streaming-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 100,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
});

function autoCatEncCanonical(int $userId, int $accountId, int $importRunId): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: $userId,
        accountId: $accountId,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-07-03'),
        bookedAt: CarbonImmutable::parse('2026-07-03 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-07-03'),
        amountMinor: -1299,
        currency: 'EUR',
        settledAmountMinor: -1299,
        settledCurrency: 'EUR',
        counterpartyName: 'Spotify Premium',
        counterpartyIban: null,
        counterpartyNormalized: 'spotify premium',
        normalizationVersion: 1,
        description: 'Spotify Premium subscription',
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: $importRunId,
        sourceRowIndex: 0,
        sourceRef: null,
    );
}

function seedAutoCatEncRule(int $userId, int $categoryId): int
{
    $ruleId = (int) DB::table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'priority' => 0,
        'combinator' => 'all',
        'hits_count' => 0,
        'active' => true,
        'notes' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    DB::table('rule_conditions')->insert([
        'rule_id' => $ruleId,
        'field' => 'merchant',
        'op' => 'equals',
        'value_type' => 'string',
        'value' => 'Spotify Premium',
        'value2' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    DB::table('rule_actions')->insert([
        'rule_id' => $ruleId,
        'position' => 0,
        'type' => 'category',
        'payload' => json_encode(['category_id' => $categoryId], JSON_THROW_ON_ERROR),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return $ruleId;
}

it('fires an import-time rule against the plaintext DTO for an encrypted user, then persists ciphertext at rest (no decrypt needed on this path)', function (): void {
    $ruleId = seedAutoCatEncRule($this->user->id, $this->streamingId);
    $importRunId = (int) DB::table('import_runs')->insertGetId([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/auto-cat-enc.csv',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var ApplyAutoCategoryStage $stage */
    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $tx = autoCatEncCanonical($this->user->id, $this->account->id, $importRunId);

    $outcome = $stage->apply($tx, $this->user);

    expect($outcome->provenance)->toBe('rule');
    expect($outcome->ruleId)->toBe($ruleId);
    expect($outcome->canonical->categoryId)->toBe($this->streamingId);

    $recorder = $this->app->make(RecordsTransactions::class);
    $result = $recorder([$outcome->canonical], $this->user);
    expect($result->inserted)->toBe(1);

    $stored = DB::table('transactions')->where('user_id', $this->user->id)->orderByDesc('id')->first();
    expect((int) $stored->category_id)->toBe($this->streamingId);
    // The write hook encrypts both columns at rest, so the match above must
    // have run off the plaintext DTO rather than off this row.
    expect($stored->counterparty_name)->not->toBe('Spotify Premium');
    expect($stored->description)->not->toBe('Spotify Premium subscription');

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    $decrypted = $codec->decryptRow('transactions', (array) $stored, $this->user->id, $this->session);
    expect($decrypted['counterparty_name'])->toBe('Spotify Premium');
    expect($decrypted['description'])->toBe('Spotify Premium subscription');
})->group('ReapplyRulesJobEncryption');

it('falls back to merchant_memories (plaintext normalized_name, never encrypted) when no rule fires, for an encrypted user', function (): void {
    $importRunId = (int) DB::table('import_runs')->insertGetId([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/auto-cat-enc-memory.csv',
        'sha256' => str_repeat('f', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $merchantId = (int) DB::table('merchants')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'spotify premium',
        'normalized_name' => 'spotify premium',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
    $memoryId = (int) DB::table('merchant_memories')->insertGetId([
        'user_id' => $this->user->id,
        'merchant_id' => $merchantId,
        'category_id' => $this->streamingId,
        'occurrence_count' => 3,
        'last_seen_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var ApplyAutoCategoryStage $stage */
    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $tx = autoCatEncCanonical($this->user->id, $this->account->id, $importRunId);

    $outcome = $stage->apply($tx, $this->user);

    expect($outcome->provenance)->toBe('memory');
    expect($outcome->memoryId)->toBe($memoryId);
    expect($outcome->canonical->categoryId)->toBe($this->streamingId);
})->group('ReapplyRulesJobEncryption');

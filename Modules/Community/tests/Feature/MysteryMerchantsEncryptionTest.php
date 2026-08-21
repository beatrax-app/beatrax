<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\MysteryMerchantsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

// The page reads transactions.description through the raw query builder, which
// applies no cast. Undecrypted, the card renders base64 AND hands that base64
// to the suggest-mapping flow, whose submit publishes it to a public repo.

uses(EnablesEncryptionForUser::class);

function mmeAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Mystery-encryption fixture ASN',
        'slug' => 'mme-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => 'EUR',
    ]);
}

function mmeImportRun(User $user): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn_csv',
        'raw_file_path' => 'fixture://mme-mystery-test',
        'sha256' => hash('sha256', 'mme-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'confirmed_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);
}

function mmeEncryptedTx(User $user, Account $account, ImportRun $run, string $description, SensitiveColumnCodec $codec, mixed $session): Transaction
{
    static $rowIndex = 0;
    $rowIndex++;

    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-19',
        'booked_at' => '2026-07-19 12:00:00',
        'value_date' => '2026-07-19',
        'amount_minor' => -2000 - $rowIndex,
        'currency' => 'EUR',
        'settled_amount_minor' => -2000 - $rowIndex,
        'settled_currency' => 'EUR',
        'counterparty_name' => null,
        'counterparty_normalized' => '',
        'normalization_version' => 1,
        'description' => $codec->encryptValue('transactions', 'description', $description, $user->id, $session),
        'source_format' => 'asn_csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad((string) $rowIndex, 64, 'm', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'payment_type' => 'pin',
    ]);
}

it('decrypts transactions.description before the card renders it or offers it to suggest-mapping', function (): void {
    $user = makeCommunityTestUser('mme-render');
    $session = $this->enablesEncryptionForUser($user);
    $account = mmeAccount($user);
    $run = mmeImportRun($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $description = 'BCK*ONBEKENDE WINKEL *4471';
    mmeEncryptedTx($user, $account, $run, $description, $codec, $session);

    // Without this the case passes vacuously against a plaintext fixture.
    $stored = DB::table('transactions')->where('user_id', $user->id)->value('description');
    expect($stored)->toBeString()->and($stored)->not->toBe($description);

    Livewire::actingAs($user)->test(MysteryMerchantsPage::class)
        ->assertSee($description)
        ->assertDontSee(substr((string) $stored, 0, 16));
});

it('counts a description the corpus already knows as resolved once it is decrypted', function (): void {
    $user = makeCommunityTestUser('mme-resolved');
    $session = $this->enablesEncryptionForUser($user);
    $account = mmeAccount($user);
    $run = mmeImportRun($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    mmeEncryptedTx($user, $account, $run, 'ALBERT HEIJN 1234 AMSTERDAM', $codec, $session);

    DB::table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => 'ALBERT HEIJN 1234 AMSTERDAM',
        'generalized_pattern' => 'albert heijn',
        'friendly_name' => 'Albert Heijn',
        'merged_from' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    // Ciphertext matches no alias, so the row was counted as a mystery and
    // dragged the auto-named KPI down with it.
    Livewire::actingAs($user)->test(MysteryMerchantsPage::class)
        ->assertDontSee('ALBERT HEIJN 1234 AMSTERDAM')
        ->assertSee('100%');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;

// A card charged in dollars and settled in euros: the two figures differ in
// both number and currency, which no other fixture in this module builds.
const FX_NATIVE_MINOR = -1420;

const FX_SETTLED_MINOR = -1267;

function cpFxUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function cpFxAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'FX fixture account',
        'slug' => 'fx-acct-'.uniqid(),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
        'default_currency' => Currency::Eur->value,
    ]);
}

function cpFxCounterparty(int $userId, string $slug, string $type): int
{
    $now = now()->toDateTimeString();

    return DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => $type,
        'slug' => $slug,
        'display_name' => 'Cloudflare Inc',
        'iban' => null,
        'merchant_name' => $type === 'merchant' ? 'Cloudflare Inc' : null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function cpFxImportRun(User $user): int
{
    $now = now()->toDateTimeString();

    return DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn_csv',
        'raw_file_path' => 'fixture://fx-recent-rows',
        'sha256' => str_pad((string) random_int(1, 1_000_000_000), 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => $now,
        'confirmed_at' => $now,
        'inserted_count' => 0,
        'duplicate_count' => 0,
        'error_count' => 0,
        'status' => 'confirmed',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function cpFxTransaction(User $user, Account $account, int $counterpartyId, int $importRunId): void
{
    $now = now()->toDateTimeString();

    DB::table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-26',
        'booked_at' => $now,
        'value_date' => '2026-04-26',
        'amount_minor' => FX_NATIVE_MINOR,
        'currency' => 'USD',
        'settled_amount_minor' => FX_SETTLED_MINOR,
        'settled_currency' => Currency::Eur->value,
        'fx_rate_used' => null,
        'counterparty_name' => null,
        'counterparty_iban' => null,
        'counterparty_normalized' => 'cloudflare inc',
        'normalization_version' => 1,
        'description' => 'CLOUDFLARE INC',
        'category_id' => null,
        'source_format' => 'asn_csv',
        'import_run_id' => $importRunId,
        'source_row_index' => random_int(1, 1_000_000),
        'source_ref' => 'fx-'.uniqid(),
        'fingerprint' => str_pad((string) random_int(1, 1_000_000_000), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'counterparty_id' => $counterpartyId,
        'payment_type' => 'unknown',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('prices a triage row at what the account paid, not what the merchant charged', function (): void {
    $user = cpFxUser('cp-fx-triage');
    $account = cpFxAccount($user);
    $run = cpFxImportRun($user);
    $unknown = cpFxCounterparty((int) $user->id, 'cloudflare-inc', 'unknown');

    cpFxTransaction($user, $account, $unknown, $run);

    $html = (string) Livewire::actingAs($user)->test(CounterpartyTriage::class)->html();
    $matches = PatternScan::first('/triage-tx__amount"[^>]*>(.*?)<\/span>/s', $html);
    $rendered = html_entity_decode(trim($matches[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($rendered)->toBe(Money::ofMinor(FX_SETTLED_MINOR, Currency::Eur->value)->format());
    expect($rendered)->not->toContain('$');
});

it('prices a profile recent-activity row at what the account paid', function (): void {
    $user = cpFxUser('cp-fx-profile');
    $account = cpFxAccount($user);
    $run = cpFxImportRun($user);
    $merchant = cpFxCounterparty((int) $user->id, 'cloudflare-inc', 'merchant');

    cpFxTransaction($user, $account, $merchant, $run);

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'cloudflare-inc']);

    $html = html_entity_decode((string) $component->html(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($html)->toContain(Money::ofMinor(abs(FX_SETTLED_MINOR), Currency::Eur->value)->format());
    expect($html)->not->toContain(Money::ofMinor(abs(FX_NATIVE_MINOR), 'USD')->format());
});

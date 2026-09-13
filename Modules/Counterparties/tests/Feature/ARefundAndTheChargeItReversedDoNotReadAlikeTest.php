<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;

// The triage list says it in its own comment: a per-transaction row is signed,
// because one transaction is not an aggregate and abs() makes a charge and a
// refund of the same size read identically. The recent-activity partial every
// profile tab includes was the one list that still stripped it.

function cpSignedRowUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'cp-signed-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
}

function cpSignedRowMerchant(User $user, string $slug): int
{
    $now = now()->toDateTimeString();

    return (int) DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => 'Reversed Goods BV',
        'merchant_name' => 'Reversed Goods BV',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function cpSignedRowMovement(User $user, Account $account, int $runId, int $counterpartyId, int $settledMinor, string $type, string $postedAt): void
{
    $now = now()->toDateTimeString();

    DB::table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 10:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $settledMinor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'reversed goods bv',
        'normalization_version' => 1,
        'description' => 'REVERSED GOODS BV',
        'source_format' => 'asn_csv',
        'import_run_id' => $runId,
        'source_row_index' => random_int(1, 1_000_000),
        'fingerprint' => str_pad((string) random_int(1, 1_000_000_000), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'counterparty_id' => $counterpartyId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('draws a charge and the refund that reversed it as two different figures', function (): void {
    $user = cpSignedRowUser();

    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'cp-signed-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
        'default_currency' => Currency::Eur->value,
    ]);

    $now = now()->toDateTimeString();
    $runId = (int) DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn_csv',
        'raw_file_path' => 'fixture://cp-signed',
        'sha256' => str_pad((string) random_int(1, 1_000_000_000), 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => $now,
        'confirmed_at' => $now,
        'status' => 'confirmed',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $slug = 'reversed-goods-'.bin2hex(random_bytes(4));
    $counterpartyId = cpSignedRowMerchant($user, $slug);

    cpSignedRowMovement($user, $account, $runId, $counterpartyId, -4_250, 'expense', '2026-04-14');
    cpSignedRowMovement($user, $account, $runId, $counterpartyId, 4_250, 'refund', '2026-04-21');

    $html = html_entity_decode(
        (string) Livewire::actingAs($user)->test(CounterpartyProfile::class, ['slug' => $slug])->html(),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8',
    );

    $charge = Money::ofMinor(-4_250, Currency::Eur->value)->format();
    $refund = Money::ofMinor(4_250, Currency::Eur->value)->format();

    // Both rows are on the page, and only one of them carries the sign: a
    // count, because the refund's rendering is a substring of the charge's.
    expect($html)->toContain($charge)
        ->and(substr_count($html, $charge))->toBe(1)
        ->and(substr_count($html, $refund))->toBe(2);
});

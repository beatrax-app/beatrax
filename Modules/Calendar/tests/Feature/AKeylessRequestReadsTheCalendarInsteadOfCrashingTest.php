<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Public\Services\TransactionSeriesMembershipQuery;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(EnablesEncryptionForUser::class);

function keylessCalendarReader(): User
{
    return User::query()->create([
        'username' => 'keyless-calendar-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// An income row inside the grid whose payer IBAN sits in the column in the
// CLEAR. A sealed one is blanked by the codec and skipped; only a row written
// before this device adopted an epoch reaches the keying step at all.
function keylessCalendarPlaintextIbanRow(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'keyless calendar account',
        'slug' => 'keyless-cal-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00KEYL'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/keyless-calendar.csv',
        'sha256' => str_pad(bin2hex(random_bytes(8)), 64, 'a'),
        'uploaded_at' => CarbonImmutable::parse('2026-09-01 00:00:00'),
        'status' => 'previewed',
    ]);

    $posted = CarbonImmutable::now()->addDays(3)->toDateString();

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'income',
        'posted_at' => $posted,
        'booked_at' => $posted.' 12:00:00',
        'value_date' => $posted,
        'amount_minor' => 250000,
        'currency' => 'EUR',
        'settled_amount_minor' => 250000,
        'settled_currency' => 'EUR',
        'counterparty_iban' => 'NL91ABNA0417164300',
        'counterparty_normalized' => '_no_counterparty',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'fingerprint' => str_pad(bin2hex(random_bytes(8)), 64, 'd', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->reader = keylessCalendarReader();
    $this->keylessSession = $this->enablesEncryptionForUser($this->reader);

    $this->transactionId = keylessCalendarPlaintextIbanRow($this->reader);
});

// The app lock being ENGAGED cannot produce this: AppLockMiddleware redirects
// a locked session to the lock screen before any page renders. What produces
// it is an unlocked session holding no key -- a sign-in while the lock is off,
// or a desktop custodian that has not handed the key back yet -- and the
// screen answered 500 rather than drawing the month.
it('draws the month when this request holds no blind-index key', function (): void {
    $this->keylessSession->forget(AppLockTestHarness::HELD_KEY_SESSION_KEY);
    $this->keylessSession->save();

    $this->actingAs($this->reader)
        ->get('/calendar')
        ->assertOk();
});

// The same answer a sealed IBAN already gets on this request: no link. A
// lookup that cannot be keyed matches nothing, which is an answer; only a
// write has none, and that is why derive() still refuses for one.
it('answers a keyless series lookup with no match rather than a throw', function (): void {
    $this->keylessSession->forget(AppLockTestHarness::HELD_KEY_SESSION_KEY);
    $this->keylessSession->save();

    $membership = app(TransactionSeriesMembershipQuery::class);

    expect($membership->seriesIdsForTransactionIds([$this->transactionId], $this->reader))->toBe([]);
});

it('still renders the month while the key IS held', function (): void {
    $this->actingAs($this->reader)
        ->get('/calendar')
        ->assertOk();
});

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Internal\Detectors\MerchantDisplayName;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Sync\Public\Services\BlindIndexCodec;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(EnablesEncryptionForUser::class);

// counterparty_normalized is a keyed one-way digest once at-rest encryption is
// on, so the decrypted counterparty_name is the only source of a readable name
// here. Anything that reads the key back for display puts a digest on screen.
function binrUser(): User
{
    return User::query()->create([
        'username' => 'binr-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'recurring_detection_window_months' => 12,
    ]);
}

// Three monthly rows under one keyed cluster, with counterparty_name stored
// exactly as the real write path stores it: encrypted.
function binrSeed(User $user, Session $session, string $bankName, ?string $userGivenName = null, bool $dropStoredName = false): string
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    $key = app(CounterpartyKey::class)->forName($bankName, (int) $user->id);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'binr account',
        'slug' => 'binr-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00BINR'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/binr.csv',
        'sha256' => str_pad((string) $user->id, 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);

    if ($userGivenName !== null) {
        $db->connection()->table('merchants')->insert([
            'user_id' => $user->id,
            'name' => $userGivenName,
            'normalized_name' => $key,
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);
    }

    foreach (['2026-06-04', '2026-07-04', '2026-08-04'] as $i => $postedAt) {
        $db->connection()->table('transactions')->insert([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'expense',
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'amount_minor' => -1399,
            'currency' => 'EUR',
            'settled_amount_minor' => -1399,
            'settled_currency' => 'EUR',
            'counterparty_name' => $dropStoredName
                ? null
                : $codec->encryptValue('transactions', 'counterparty_name', $bankName, (int) $user->id, $session),
            'counterparty_normalized' => $key,
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $i,
            'fingerprint' => str_pad('binr'.$user->id.$i, 64, 'd', STR_PAD_LEFT),
            'fingerprint_version' => 3,
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);
    }

    return $key;
}

function binrDetect(User $user): ?RecurringSeries
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-19 09:00:00'));
    app(ExpenseSeriesDetector::class)->detectForUser($user);
    CarbonImmutable::setTestNow();

    return RecurringSeries::query()->where('user_id', $user->id)->first();
}

it('resolves a keyed cluster to the decrypted counterparty name', function (): void {
    $user = binrUser();
    $session = $this->enablesEncryptionForUser($user);
    $key = binrSeed($user, $session, 'Zilveren Kruis');

    expect(BlindIndexCodec::looksDerived($key))->toBeTrue();

    expect(app(MerchantDisplayName::class)->forStoredKey((int) $user->id, $key))
        ->toBe('Zilveren Kruis');
});

it('shows the merchant name, never the digest, on a detected series', function (): void {
    $user = binrUser();
    $session = $this->enablesEncryptionForUser($user);
    $key = binrSeed($user, $session, 'Zilveren Kruis');

    $series = binrDetect($user);

    expect($series)->not->toBeNull();
    expect($series->detected_name)->toBe('Zilveren Kruis');
    expect($series->cluster_counterparty_key)->toBe($key);
    expect(BlindIndexCodec::looksDerived((string) $series->detected_name))->toBeFalse();
});

it('prefers the user own naming over the bank string, both keyed the same way', function (): void {
    $user = binrUser();
    $session = $this->enablesEncryptionForUser($user);
    binrSeed($user, $session, 'ZILVEREN KRUIS ACHMEA', 'Health insurer');

    expect(binrDetect($user)->detected_name)->toBe('Health insurer');
});

// A keyed cluster whose rows carry no readable name — a peer's rows that
// arrived without one. Writing the key would put a digest on the review
// screen, so the series waits for a sweep that can read a name.
it('defers a series rather than writing a digest when no source knows the name', function (): void {
    $user = binrUser();
    $session = $this->enablesEncryptionForUser($user);
    $key = binrSeed($user, $session, 'Nordwind Media BV', dropStoredName: true);

    expect(BlindIndexCodec::looksDerived($key))->toBeTrue();
    expect(binrDetect($user))->toBeNull();
});

// Reached the real way: three un-named expenses cluster under the sentinel,
// and the detector used to write that literal into a column the screen renders.
// The sentinel is legible, so no ciphertext scan would ever have caught it.
it('never writes the no-counterparty sentinel into the displayed name', function (): void {
    $user = binrUser();
    $this->enablesEncryptionForUser($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'binr sentinel account',
        'slug' => 'binr-s-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00BINS'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/binr-s.csv',
        'sha256' => str_pad('s'.$user->id, 64, 'b', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);

    // No counterparty name at all, which is what NormalizeStage turns into the
    // sentinel — the same value the detector then clusters on.
    $key = app(CounterpartyKey::class)->forName(null, (int) $user->id);
    expect($key)->toBe(BlindIndexCodec::SENTINEL);
    expect(BlindIndexCodec::looksDerived($key))->toBeFalse();

    foreach (['2026-06-04', '2026-07-04', '2026-08-04'] as $i => $postedAt) {
        $db->connection()->table('transactions')->insert([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'expense',
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'amount_minor' => -2599,
            'currency' => 'EUR',
            'settled_amount_minor' => -2599,
            'settled_currency' => 'EUR',
            'counterparty_name' => null,
            'counterparty_normalized' => $key,
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $i,
            'fingerprint' => str_pad('binrs'.$user->id.$i, 64, 'e', STR_PAD_LEFT),
            'fingerprint_version' => 3,
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);
    }

    expect(binrDetect($user))->toBeNull();
});

it('still falls back to the readable key for a user without at-rest encryption', function (): void {
    $user = binrUser();

    expect(app(MerchantDisplayName::class)->forStoredKey((int) $user->id, 'kpn bv'))
        ->toBe('kpn bv');
});

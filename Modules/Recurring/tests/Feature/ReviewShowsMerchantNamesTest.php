<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Models\RecurringSeries;

// detected_name used to be the clustering key, so the review screen showed
// `domino s pizza`. merchants maps that key back to the name the user gave it,
// and the transactions themselves carry the name the bank wrote.
function rsmnUser(): User
{
    return User::query()->create([
        'username' => 'rsmn-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'recurring_detection_window_months' => 12,
    ]);
}

function rsmnSeed(User $user, string $normalized, ?string $displayName, ?string $bankName = null): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'rsmn account',
        'slug' => 'rsmn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00RSMN'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rsmn.csv',
        'sha256' => str_pad((string) $user->id, 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);

    if ($displayName !== null) {
        $db->connection()->table('merchants')->insert([
            'user_id' => $user->id,
            'name' => $displayName,
            'normalized_name' => $normalized,
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
            'counterparty_name' => $bankName ?? $displayName ?? $normalized,
            'counterparty_normalized' => $normalized,
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $i,
            'fingerprint' => str_pad('rsmn'.$user->id.$i, 64, 'd', STR_PAD_LEFT),
            'fingerprint_version' => 3,
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);
    }
}

it('lists a detected series under the merchant name as written', function (): void {
    $user = rsmnUser();
    rsmnSeed($user, 'domino s pizza', "Domino's Pizza");

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-19 09:00:00'));
    app(ExpenseSeriesDetector::class)->detectForUser($user);
    CarbonImmutable::setTestNow();

    $series = RecurringSeries::query()->where('user_id', $user->id)->first();

    expect($series)->not->toBeNull()
        ->and($series->detected_name)->toBe("Domino's Pizza");
});

it('keeps the clustering key on the column that clusters', function (): void {
    $user = rsmnUser();
    rsmnSeed($user, 'netflix international bv', 'Netflix International BV');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-19 09:00:00'));
    app(ExpenseSeriesDetector::class)->detectForUser($user);
    CarbonImmutable::setTestNow();

    $series = RecurringSeries::query()->where('user_id', $user->id)->first();

    expect($series->cluster_counterparty_key)->toBe('netflix international bv')
        ->and($series->detected_name)->toBe('Netflix International BV');
});

it('falls back to the normalised key when no source knows a name', function (): void {
    $user = rsmnUser();
    rsmnSeed($user, 'kpn bv', null, '');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-19 09:00:00'));
    app(ExpenseSeriesDetector::class)->detectForUser($user);
    CarbonImmutable::setTestNow();

    $series = RecurringSeries::query()->where('user_id', $user->id)->first();

    expect($series)->not->toBeNull()
        ->and($series->detected_name)->toBe('kpn bv');
});

it('heals the rows that already carry the normalised key', function (): void {
    $user = rsmnUser();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('merchants')->insert([
        'user_id' => $user->id,
        'name' => 'ASN Bank GEA',
        'normalized_name' => 'asn bank gea',
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'asn bank gea',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1399,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1399,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense|asn bank gea|EUR|monthly',
        'cluster_counterparty_key' => 'asn bank gea',
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    // The rows already in the database were written before the detector learned
    // to do this, and no sweep revisits them.
    $migration = require base_path('Modules/Recurring/Database/Migrations/2026_08_19_000002_show_merchant_names_on_recurring_review.php');
    $migration->up();

    $healed = $db->connection()->table('recurring_series')->where('id', $seriesId)->first(['detected_name', 'cluster_counterparty_key']);

    expect($healed->detected_name)->toBe('ASN Bank GEA')
        ->and($healed->cluster_counterparty_key)->toBe('asn bank gea');
});

it('heals only the owner rows, never another account with the same key', function (): void {
    $mine = rsmnUser();
    $theirs = rsmnUser();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Only my account knows this merchant by a display name.
    $db->connection()->table('merchants')->insert([
        'user_id' => $mine->id,
        'name' => 'ASN Bank GEA',
        'normalized_name' => 'asn bank gea',
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    $rows = [];
    foreach ([$mine, $theirs] as $owner) {
        $rows[$owner->id] = $db->connection()->table('recurring_series')->insertGetId([
            'user_id' => $owner->id,
            'direction' => 'expense',
            'detected_name' => 'asn bank gea',
            'state' => 'pending',
            'cadence' => 'monthly',
            'latest_amount_minor' => -1399,
            'latest_currency' => 'EUR',
            'monthly_equivalent_minor' => -1399,
            'variance_tolerance_percent' => 25,
            'cluster_key' => 'expense|asn bank gea|EUR|monthly',
            'cluster_counterparty_key' => 'asn bank gea',
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);
    }

    $migration = require base_path('Modules/Recurring/Database/Migrations/2026_08_19_000002_show_merchant_names_on_recurring_review.php');
    $migration->up();

    // The per-merchant loop scoped every UPDATE by user_id; the single correlated
    // statement that replaced it has to scope the same way, or one household
    // member's naming of a merchant renames the other's series.
    expect($db->connection()->table('recurring_series')->where('id', $rows[$mine->id])->value('detected_name'))
        ->toBe('ASN Bank GEA')
        ->and($db->connection()->table('recurring_series')->where('id', $rows[$theirs->id])->value('detected_name'))
        ->toBe('asn bank gea');
});

// merchants is only ever written by the demo seeder and by sync, so an imported
// statement leaves it empty and every recurring surface showed `kpn bv` — while
// the dashboard's own transaction list beside it showed "KPN BV" all along.
it('names the series the way the bank wrote it when no merchant row exists', function (): void {
    $user = rsmnUser();
    rsmnSeed($user, 'kpn bv', null, 'KPN BV');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-19 09:00:00'));
    app(ExpenseSeriesDetector::class)->detectForUser($user);
    CarbonImmutable::setTestNow();

    $series = RecurringSeries::query()->where('user_id', $user->id)->first();

    expect($series)->not->toBeNull()
        ->and($series->detected_name)->toBe('KPN BV')
        ->and($series->cluster_counterparty_key)->toBe('kpn bv');
});

// The rows a user already has were detected before this existed, and only the
// insert path resolved a name — so nothing would have changed on their screen.
it('heals a stored key on the next sweep and leaves a real name alone', function (): void {
    $user = rsmnUser();
    rsmnSeed($user, 'zilveren kruis', null, 'Zilveren Kruis');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'zilveren kruis',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1399,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1399,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense|zilveren kruis|EUR|monthly',
        'cluster_counterparty_key' => 'zilveren kruis',
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-19 09:00:00'));
    app(ExpenseSeriesDetector::class)->detectForUser($user);

    expect($db->connection()->table('recurring_series')->where('id', $seriesId)->value('detected_name'))
        ->toBe('Zilveren Kruis');

    // A name the sweep did not write is not the sweep's to overwrite.
    $db->connection()->table('recurring_series')->where('id', $seriesId)->update(['detected_name' => 'Zilveren Kruis Zorgverzekering']);
    app(ExpenseSeriesDetector::class)->detectForUser($user);
    CarbonImmutable::setTestNow();

    expect($db->connection()->table('recurring_series')->where('id', $seriesId)->value('detected_name'))
        ->toBe('Zilveren Kruis Zorgverzekering');
});

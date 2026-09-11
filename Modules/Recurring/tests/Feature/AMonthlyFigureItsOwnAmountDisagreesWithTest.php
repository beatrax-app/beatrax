<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\Support\MonthlyEquivalent;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

uses(RefreshDatabase::class);

// `monthly_equivalent_minor` denormalises `latest_amount_minor` and `cadence`.
// The three are separate columns of a row that merges per field, so a peer's
// concurrent refresh can land the amount from one device and the monthly
// figure from the other.
function mfadUser(): User
{
    return User::query()->create([
        'username' => 'mfad-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function mfadSeries(DatabaseManager $db, int $userId, string $cadence, int $amountMinor, int $storedMonthly): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Mfad Gym',
        'state' => 'approved',
        'cadence' => $cadence,
        'latest_amount_minor' => $amountMinor,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => $storedMonthly,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'mfad::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}

function mfadMonthlyMinor(User $user, int $seriesId): int
{
    /** @var RecurringSeriesQuery $query */
    $query = app(RecurringSeriesQuery::class);

    foreach ($query->approvedForUser($user) as $row) {
        if ($row->seriesId === $seriesId) {
            return $row->monthlyEquivalent->toMinor();
        }
    }

    return 0;
}

it('answers the figure the amount beside it derives', function (): void {
    $user = mfadUser();
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // A weekly ten with a monthly figure computed for a monthly cadence: what
    // a device that detected `monthly` leaves behind when the other device's
    // `cadence` op wins and its own `monthly_equivalent_minor` op does not.
    $seriesId = mfadSeries($db, (int) $user->id, 'weekly', -1000, -1000);

    expect(mfadMonthlyMinor($user, $seriesId))->toBe(-4333);
});

it('leaves a row whose columns agree exactly where it is', function (): void {
    $user = mfadUser();
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $seriesId = mfadSeries($db, (int) $user->id, 'monthly', -1299, -1299);

    expect(mfadMonthlyMinor($user, $seriesId))->toBe(-1299);
});

// Irregular derives nothing, so the stored column is still the only answer
// available and the reader keeps whatever the detector last wrote.
it('keeps the stored figure where no cadence derives one', function (): void {
    $user = mfadUser();
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $seriesId = mfadSeries($db, (int) $user->id, 'irregular', -2500, -2500);

    expect(MonthlyEquivalent::forCadence(-2500, SeriesCadence::Irregular))->toBeNull()
        ->and(mfadMonthlyMinor($user, $seriesId))->toBe(-2500);
});

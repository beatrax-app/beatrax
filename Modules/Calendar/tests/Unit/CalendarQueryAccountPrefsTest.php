<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Services\AccountResolver;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

// null means "never configured" and resolves to the defaults; an explicit
// array — the empty array included — is taken literally.
function cqapUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'cqap-'.$suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cqapAccount(DatabaseManager $db, int $userId, string $name, string $kind): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'cqap-'.$hex,
        'kind' => $kind,
        'iban' => 'NL00CQAP'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 0,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function cqapSeries(User $user, string $name): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1000,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cqap::'.$name,
        'next_expected_at' => CarbonImmutable::parse('2026-06-15'),
    ]);
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('shows entries from all owned accounts when visibleAccountIds is null (never configured)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqapUser('all-visible');

    cqapAccount($db, $user->id, 'ASN Checking', 'bank');
    cqapAccount($db, $user->id, 'ICS Card', 'ics_card');

    // A series carries no account id of its own; CalendarQuery resolves one
    // through the occurrences fallback.
    cqapSeries($user, 'Series-ASN');
    cqapSeries($user, 'Series-ICS');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    $days = $calendarQuery->forMonth($user, 2026, 6, null, null);

    $totalEntries = 0;
    foreach ($days as $day) {
        $totalEntries += count($day->entries);
    }

    // Both series have nextExpectedAt on June 15, so 2 entries for June
    expect($totalEntries)->toBeGreaterThanOrEqual(2);
});

it('defaults balance to spendable-kind accounts when balanceAccountIds is null (never configured)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqapUser('spendable-default');

    $asnId = cqapAccount($db, $user->id, 'ASN Checking', 'bank');
    $icsId = cqapAccount($db, $user->id, 'ICS Card', 'ics_card');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    $days = $calendarQuery->forMonth($user, 2026, 6, null, null);

    expect($calendarQuery->spendableAccountIds($user))->toBe([$asnId]);

    expect($days)->toBeArray()->not->toBeEmpty();

    $spendableKinds = (new ReflectionClass(AccountResolver::class))
        ->getConstant('SPENDABLE_KINDS');
    expect($spendableKinds)->toContain('bank');
    expect($spendableKinds)->toContain('cash');
    expect($spendableKinds)->toContain('paypal');
    expect($spendableKinds)->toContain('paypal_funding');
    expect($spendableKinds)->not->toContain('ics');
    expect($spendableKinds)->not->toContain('ics_card');
    expect($spendableKinds)->not->toContain('ics_bulk_settle');

    unset($asnId, $icsId);
});

it('returns no entries for an explicit empty visibleAccountIds array (deselect-all, CR-01)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqapUser('deselect-all');

    cqapAccount($db, $user->id, 'ASN Checking', 'bank');
    cqapSeries($user, 'Series-Hidden');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    $days = $calendarQuery->forMonth($user, 2026, 6, [], null);

    $totalEntries = 0;
    foreach ($days as $day) {
        $totalEntries += count($day->entries);
    }

    expect($totalEntries)->toBe(0);
});

it('drops a foreign account id from visibleAccountIds (T-06-02)', function (): void {
    $db = app(DatabaseManager::class);
    $owner = cqapUser('foreign-visible-owner');
    $other = cqapUser('foreign-visible-other');

    $foreignAccountId = cqapAccount($db, $other->id, 'Other ASN', 'bank');
    cqapSeries($owner, 'Owner Series');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    $days = $calendarQuery->forMonth($owner, 2026, 6, [$foreignAccountId], null);

    $totalEntries = 0;
    foreach ($days as $day) {
        $totalEntries += count($day->entries);
    }

    // The intersect leaves no visible accounts at all, so the owner's own
    // series has nowhere to land either.
    expect($totalEntries)->toBe(0);
});

it('drops a foreign account id from balanceAccountIds (T-06-02)', function (): void {
    $db = app(DatabaseManager::class);
    $owner = cqapUser('foreign-balance-owner');
    $other = cqapUser('foreign-balance-other');

    $foreignAccountId = cqapAccount($db, $other->id, 'Other ASN', 'bank');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    $days = $calendarQuery->forMonth($owner, 2026, 6, null, [$foreignAccountId]);

    expect($days)->toBeArray()->not->toBeEmpty();

    $allComputing = true;
    foreach ($days as $day) {
        if (! $day->isComputing) {
            $allComputing = false;
            break;
        }
    }
    expect($allComputing)->toBeTrue('Foreign balance account dropped → no balance accounts → isComputing for all days');

    unset($foreignAccountId);
});

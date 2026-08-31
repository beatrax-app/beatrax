<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ShortfallDetector;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Ledger\Models\Account;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

// Driven through ShortfallDetector, which is what dispatches the event: the
// floor it judges against is the reader's own buffer, and ProjectForecastsCommand
// runs every ForecastHorizon case — 30 through 365 — for every account.

function sfnUser(): User
{
    return User::query()->create([
        'username' => 'sfn-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function sfnAccount(User $user): Account
{
    return Account::create([
        'user_id' => $user->id,
        'name' => 'SFN ASN',
        'slug' => 'sfn-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00SFN'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

/**
 * @return list<array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}>
 */
function sfnPoints(int $dipOnDay, int $dipMinor, int $horizonDays): array
{
    $points = [];
    for ($day = 0; $day <= $horizonDays; $day++) {
        $point = $day === $dipOnDay ? $dipMinor : 500000;
        $points[] = [
            'date' => CarbonImmutable::now()->startOfDay()->addDays($day)->toDateString(),
            'low_minor' => $point,
            'point_minor' => $point,
            'high_minor' => $point,
            'currency' => 'EUR',
        ];
    }

    return $points;
}

function sfnDetect(User $user, Account $account, int $bufferMinor, int $dipOnDay, int $dipMinor, int $horizonDays): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user, $account, $bufferMinor, $dipOnDay, $dipMinor, $horizonDays): void {
        app(ShortfallDetector::class)->detect(
            sfnPoints($dipOnDay, $dipMinor, $horizonDays),
            $account->id,
            null,
            $horizonDays,
            $bufferMinor,
            'EUR',
            $user,
        );
    });
}

function sfnBody(User $user): string
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (string) $db->connection()->table('notifications')
        ->where('user_id', $user->id)
        ->where('trigger_type', NotificationTrigger::ForecastShortfall->value)
        ->value('body');
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('does not say a balance that never leaves the black dipped below zero', function (): void {
    $user = sfnUser();
    $account = sfnAccount($user);

    // €3,000 left, judged against the reader's own €5,000 buffer: nothing here
    // is anywhere near zero.
    sfnDetect($user, $account, bufferMinor: 500000, dipOnDay: 9, dipMinor: 300000, horizonDays: ForecastHorizon::OneMonth->value);

    expect(sfnBody($user))
        ->not->toContain('below zero')
        ->and(sfnBody($user))->toBe('Your projected balance dips below your €5,000.00 buffer on 13 Jul.');
});

it('does not promise a dip 200 days out is within the next 30 days', function (): void {
    $user = sfnUser();
    $account = sfnAccount($user);

    sfnDetect($user, $account, bufferMinor: 0, dipOnDay: 200, dipMinor: -12500, horizonDays: ForecastHorizon::OneYear->value);

    expect(sfnBody($user))
        ->not->toContain('30 days')
        ->and(sfnBody($user))->toBe('Your projected balance dips below zero on 20 Jan.');
});

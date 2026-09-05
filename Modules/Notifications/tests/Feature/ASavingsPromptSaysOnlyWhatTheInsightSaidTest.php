<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Events\SavingsPromptDue;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

// The event carries the insight's key and its figure, not its sentence — one of
// three insight kinds, each of which already names the monthly amount inside its
// own line. Two of the three suggest no cheaper plan at all.

function spsUser(): User
{
    return User::query()->create([
        'username' => 'sps-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function spsDispatch(User $user, string $kind, int $monthlyMinor): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user, $kind, $monthlyMinor): void {
        app(Dispatcher::class)->dispatch(new SavingsPromptDue(
            userId: $user->id,
            insightKey: $kind.':1',
            seriesId: 1,
            name: 'Netflix',
            monthlyMinor: $monthlyMinor,
            currency: 'EUR',
            messageKey: 'drift-alerts::savings.insight.'.$kind.'_message',
        ));
    });
}

function spsRow(User $user): stdClass
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var stdClass $row */
    $row = $db->connection()->table('notifications')
        ->where('user_id', $user->id)
        ->where('trigger_type', NotificationTrigger::SavingsPrompt->value)
        ->first();

    return $row;
}

it('does not announce a cheaper plan over a prompt that only asks whether you still use it', function (): void {
    $user = spsUser();

    spsDispatch($user, 'review', 999);

    expect((string) spsRow($user)->body)->toBe('Still using Netflix? It costs €9.99/mo.')
        ->and((string) spsRow($user)->title)->not->toBe('A cheaper plan exists');
});

it('does not announce a cheaper plan over a price rise', function (): void {
    $user = spsUser();

    spsDispatch($user, 'cancel', 999);

    expect((string) spsRow($user)->title)->not->toBe('A cheaper plan exists');
});

it('prints the monthly figure once, not twice', function (): void {
    $user = spsUser();

    spsDispatch($user, 'cheaper', 999);

    expect(substr_count((string) spsRow($user)->body, '€9.99'))->toBe(1);
});

it('deep-links the prompt at this application, never at the merchant', function (): void {
    $user = spsUser();

    $routes = [];
    app(Dispatcher::class)->listen(
        NotificationDeliverable::class,
        function (NotificationDeliverable $event) use (&$routes): void {
            $routes[] = $event->deepLinkRoute;
        },
    );

    spsDispatch($user, 'cheaper', 999);

    // Clicking this notification on the desktop replaces the address of the
    // application's own window. The event used to carry the corpus cancel_url
    // here, so a contributed entry chose what that window loaded.
    expect($routes)->toBe([route('recurring.series.show', ['seriesId' => 1])]);
});

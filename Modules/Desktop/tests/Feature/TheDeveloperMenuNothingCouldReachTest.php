<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Native\AppMenuBuilder;

// Menu::create() runs once per launch from POST /_native/api/booted, a route
// with no `web` group and therefore no session. "Open console (⌘.)" and "Run a
// command" were written, localised in 26 languages, and never once rendered.

function developerMenuUser(string $username, bool $developer): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('opensesame'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $developer,
    ]);
    test()->actingAs($user);

    return $user;
}

/**
 * @return list<string>
 */
function developerMenuLabels(): array
{
    return collect(app(AppMenuBuilder::class)->build())
        ->map(fn (object $item): array => $item->toArray())
        ->pluck('label')
        ->filter(fn (mixed $label): bool => is_string($label))
        ->values()
        ->all();
}

it('offers the developer submenu to a signed-in developer', function (): void {
    developerMenuUser('desktop-menu-developer', true);

    expect(developerMenuLabels())->toContain('Developer');
});

it('leaves the developer submenu off for a reader who is not one', function (): void {
    developerMenuUser('desktop-menu-plain', false);

    expect(developerMenuLabels())->not->toContain('Developer');
});

it('replaces the app menu when a user signs in, because the boot-time build had no session', function (): void {
    Http::fake();
    config(['nativephp-internal.running' => true]);

    $user = developerMenuUser('desktop-menu-signs-in', true);

    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new Login('web', $user, false));

    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'menu'));
});

it('never reaches for the native menu outside the Electron bundle', function (): void {
    Http::fake();
    config(['nativephp-internal.running' => false]);

    $user = developerMenuUser('desktop-menu-off-bundle', true);

    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new Login('web', $user, false));

    Http::assertNothingSent();
});

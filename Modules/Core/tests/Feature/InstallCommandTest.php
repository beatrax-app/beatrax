<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Ledger\Models\Category;
use Tests\Helpers\LiveSqliteConnection;

it('creates User id=1 on a fresh install', function (): void {
    Event::fake([UserInstalled::class]);

    $this->artisan('beatrax:install', [
        '--username' => 'wessel',
        '--password' => 'opensesame',
        '--period-start-day' => 25,
    ])->assertSuccessful();

    $user = User::find(1);
    expect($user)->not->toBeNull();
    expect($user->username)->toBe('wessel');
    expect($user->period_start_day)->toBe(25);

    Event::assertDispatched(UserInstalled::class);
});

it('is idempotent — re-running with the same username does not change the password or period', function (): void {
    $this->artisan('beatrax:install', [
        '--username' => 'wessel',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ])->assertSuccessful();

    $originalHash = User::find(1)->password;

    $this->artisan('beatrax:install', [
        '--username' => 'wessel',
        '--password' => 'differentpassword',
        '--period-start-day' => 28,
    ])->assertSuccessful();

    $user = User::find(1);
    expect($user->password)->toBe($originalHash);
    expect($user->period_start_day)->toBe(1);
    expect(User::count())->toBe(1);
});

it('re-dispatches UserInstalled on a re-run so seed listeners can heal missing reference data', function (): void {
    // First install creates User id=1 and (via SeedDefaultCategoryTree)
    // populates the default category tree.
    $this->artisan('beatrax:install', [
        '--username' => 'wessel',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ])->assertSuccessful();

    expect(Category::query()->whereNull('user_id')->count())->toBeGreaterThan(0);

    // Simulate the failure mode this heal exists for: a developer's DB
    // that pre-dates the SeedDefaultCategoryTree wiring still has User id=1
    // but no shared default-tree categories. Wipe categories then re-run
    // install and confirm the tree is restored.
    Category::query()->whereNull('user_id')->delete();
    expect(Category::query()->whereNull('user_id')->count())->toBe(0);

    // Attach a probe listener BEFORE re-running so we can count the
    // re-dispatch directly without relying on Event::fake() racing the
    // constructor-injected Dispatcher used by InstallCommand.
    /** @var list<int> $captured */
    $captured = [];
    $this->app->make(Dispatcher::class)->listen(
        UserInstalled::class,
        function (UserInstalled $event) use (&$captured): void {
            $captured[] = $event->userId;
        },
    );

    $this->artisan('beatrax:install', [
        '--username' => 'wessel',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ])->assertSuccessful();

    expect($captured)->toBe([1]);
    expect(Category::query()->whereNull('user_id')->count())->toBeGreaterThan(0);
});

it('refuses an iCloud-Drive database path', function (): void {
    LiveSqliteConnection::pathOnDefault(
        $this->app,
        '/Users/test/Library/Mobile Documents/com~apple~CloudDocs/db.sqlite',
    );

    $this->artisan('beatrax:install', [
        '--username' => 'test',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ])
        ->expectsOutputToContain('Mobile Documents')
        ->assertFailed();

    expect(file_exists('/Users/test/Library/Mobile Documents/com~apple~CloudDocs/db.sqlite'))->toBeFalse();
});

it('refuses a Dropbox database path', function (): void {
    LiveSqliteConnection::pathOnDefault(
        $this->app,
        '/Users/test/Dropbox/finance/db.sqlite',
    );

    $this->artisan('beatrax:install', [
        '--username' => 'test',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ])
        ->expectsOutputToContain('Dropbox')
        ->assertFailed();
});

it('refuses a OneDrive database path', function (): void {
    LiveSqliteConnection::pathOnDefault(
        $this->app,
        '/Users/test/OneDrive/finance/db.sqlite',
    );

    $this->artisan('beatrax:install', [
        '--username' => 'test',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ])
        ->expectsOutputToContain('OneDrive')
        ->assertFailed();
});

it('clamps period_start_day into the 1..28 window', function (): void {
    $this->artisan('beatrax:install', [
        '--username' => 'clamp',
        '--password' => 'opensesame',
        '--period-start-day' => 99,
    ])->assertSuccessful();

    expect(User::find(1)->period_start_day)->toBe(28);
});

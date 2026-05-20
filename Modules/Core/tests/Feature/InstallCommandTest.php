<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;

it('creates User id=1 on a fresh install', function (): void {
    Event::fake([UserInstalled::class]);

    $this->artisan('diederik:install', [
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

it('is idempotent — re-running with the same username is a no-op', function (): void {
    $this->artisan('diederik:install', [
        '--username' => 'wessel',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ])->assertSuccessful();

    $originalHash = User::find(1)->password;

    $this->artisan('diederik:install', [
        '--username' => 'wessel',
        '--password' => 'differentpassword',
        '--period-start-day' => 28,
    ])->assertSuccessful();

    $user = User::find(1);
    expect($user->password)->toBe($originalHash);
    expect($user->period_start_day)->toBe(1);
    expect(User::count())->toBe(1);
});

it('refuses an iCloud-Drive database path', function (): void {
    $this->app->make(Repository::class)->set(
        'database.connections.sqlite.database',
        '/Users/test/Library/Mobile Documents/com~apple~CloudDocs/db.sqlite',
    );

    $this->artisan('diederik:install', [
        '--username' => 'test',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ])
        ->expectsOutputToContain('Mobile Documents')
        ->assertFailed();

    expect(file_exists('/Users/test/Library/Mobile Documents/com~apple~CloudDocs/db.sqlite'))->toBeFalse();
});

it('refuses a Dropbox database path', function (): void {
    $this->app->make(Repository::class)->set(
        'database.connections.sqlite.database',
        '/Users/test/Dropbox/finance/db.sqlite',
    );

    $this->artisan('diederik:install', [
        '--username' => 'test',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ])
        ->expectsOutputToContain('Dropbox')
        ->assertFailed();
});

it('refuses a OneDrive database path', function (): void {
    $this->app->make(Repository::class)->set(
        'database.connections.sqlite.database',
        '/Users/test/OneDrive/finance/db.sqlite',
    );

    $this->artisan('diederik:install', [
        '--username' => 'test',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ])
        ->expectsOutputToContain('OneDrive')
        ->assertFailed();
});

it('clamps period_start_day into the 1..28 window', function (): void {
    $this->artisan('diederik:install', [
        '--username' => 'clamp',
        '--password' => 'opensesame',
        '--period-start-day' => 99,
    ])->assertSuccessful();

    expect(User::find(1)->period_start_day)->toBe(28);
});

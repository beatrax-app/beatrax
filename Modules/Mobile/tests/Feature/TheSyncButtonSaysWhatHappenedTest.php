<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;
use Modules\Mobile\Internal\Sync\SyncAttemptOutcome;

uses(RefreshDatabase::class);

// Tapping "Sync now" on a paired, synced Galaxy S23 moved no data, wrote no log
// line and changed nothing on screen. The button reached the service, the
// service answered, and the component threw the answer away.

beforeEach(function (): void {
    @unlink(UserDataPathService::appPath('mobile/network-policy.json'));
});

afterEach(function (): void {
    @unlink(UserDataPathService::appPath('mobile/network-policy.json'));
});

function syncButtonUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function confirmPeerFor(User $user): void
{
    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => 'desktop-peer-'.bin2hex(random_bytes(4)),
        'name' => 'Study desktop',
        'ed25519_public_key_hex' => str_repeat('ab', 32),
        'x25519_public_key_hex' => str_repeat('cd', 32),
        'safety_number_words' => 'alpha bravo charlie',
        'is_self' => 0,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => '2026-08-01T10:01:00Z',
        'last_seen_at' => '2026-08-01T10:01:00Z',
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:01:00Z',
    ]);
}

it('tells the reader what the sync attempt did, instead of leaving the screen unchanged', function (): void {
    $user = syncButtonUser('sync-says-'.bin2hex(random_bytes(4)));
    confirmPeerFor($user);
    $this->actingAs($user);

    // No identity key-file for this user, so the burst is skipped rather than
    // dialled — which is exactly the case the device produced, and exactly the
    // case that used to leave the reader with no way to tell it apart from a
    // sync that worked.
    Livewire::test(SyncScreen::class)
        ->call('syncNow')
        ->assertSet('lastSyncResult', SyncAttemptOutcome::NotEnabled->value)
        ->assertSee(Lang::get('mobile::sync.result.not_enabled'));
});

it('says nothing about an attempt before one has been made', function (): void {
    $user = syncButtonUser('sync-says-quiet-'.bin2hex(random_bytes(4)));
    confirmPeerFor($user);
    $this->actingAs($user);

    Livewire::test(SyncScreen::class)
        ->assertSet('lastSyncResult', null)
        ->assertDontSee(Lang::get('mobile::sync.result.not_enabled'));
});

it('gives each outcome its own sentence, so a skip never reads as a sync', function (): void {
    $messages = array_map(
        static fn (SyncAttemptOutcome $outcome): string => Lang::get('mobile::sync.result.'.$outcome->value),
        SyncAttemptOutcome::cases(),
    );

    expect(array_unique($messages))->toHaveCount(count($messages));
});

it('has copy for every outcome the service can report, in both languages', function (): void {
    $missing = [];

    foreach (['en', 'nl'] as $locale) {
        app('translator')->setLocale($locale);

        foreach (SyncAttemptOutcome::cases() as $outcome) {
            $key = 'mobile::sync.result.'.$outcome->value;

            if (Lang::get($key) === $key) {
                $missing[] = "{$locale}: {$outcome->value}";
            }
        }
    }

    expect($missing)->toBe([], implode(', ', $missing));
});

it('states on the screen what this device does between presses', function (): void {
    $user = syncButtonUser('sync-says-background-'.bin2hex(random_bytes(4)));
    confirmPeerFor($user);
    $this->actingAs($user);

    Livewire::test(SyncScreen::class)
        ->assertSee(Lang::get('mobile::sync.background_note'));
});

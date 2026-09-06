<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Core\Internal\AutoUpdate\HttpPublisherManifestFetcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\UpdateChannel;
use Modules\Core\Public\Http\Livewire\UpdateCheckSettingsSection;
use Modules\Core\Public\Services\UpdateCheckPreference;
use Psr\Log\NullLogger;

// The check is the only outbound call a default install makes. Two callers make
// it — electron-updater from the Electron main process, and the manifest fetch
// from PHP — so switching it off has to reach both, and "off" has to mean no
// request rather than a request whose answer is thrown away.

const OFF_SWITCH_FEED_URL = 'https://feed.example.test';

function offSwitchFetcher(): HttpPublisherManifestFetcher
{
    return new HttpPublisherManifestFetcher(
        app(HttpClient::class),
        new Repository(['auto_update' => ['manifest_feed_url' => OFF_SWITCH_FEED_URL]]),
        new NullLogger,
        app(UpdateCheckPreference::class),
        'Windows',
    );
}

function offSwitchUser(bool $checkEnabled): User
{
    return User::create([
        'username' => 'reader-'.($checkEnabled ? 'on' : 'off'),
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'auto_update_check_enabled' => $checkEnabled,
    ]);
}

it('ships on: a row written without the column still reads as checked', function (): void {
    // Inserted the way every row that existed before the migration ran was,
    // with only the two columns `users` requires: the DB default is the whole
    // answer for them, and being on is the shipped posture.
    app(DatabaseManager::class)->connection()->table('users')->insert([
        'username' => 'reader-upgraded',
        'password' => 'already-hashed',
    ]);

    expect((bool) app(DatabaseManager::class)->connection()
        ->table('users')->value('auto_update_check_enabled'))->toBeTrue();
    expect(app(UpdateCheckPreference::class)->enabled())->toBeTrue();
});

it('reads on from a model that has not been read back', function (): void {
    // `create()` does not re-read the row, so without the Eloquent default the
    // fresh instance carries null, casts to false, and the switch draws OFF on
    // a device whose stored answer is on.
    $user = User::create([
        'username' => 'reader-fresh',
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    expect($user->auto_update_check_enabled)->toBeTrue()
        ->and($user->fresh()->auto_update_check_enabled)->toBeTrue();
});

it('answers on when the answer cannot be read at all', function (): void {
    // First launch reaches the boot hook before the table exists. An unreadable
    // answer must not become a silent opt-out the reader never asked for.
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->drop('users');

    expect(app(UpdateCheckPreference::class)->enabled())->toBeTrue();
});

it('sends no request at all once the check is switched off', function (): void {
    offSwitchUser(false);
    Http::fake();

    expect(offSwitchFetcher()->fetch(UpdateChannel::Stable))->toBeNull();

    Http::assertNothingSent();
});

it('still fetches the signed manifest while the check is on', function (): void {
    offSwitchUser(true);
    Http::fake([
        OFF_SWITCH_FEED_URL.'/latest.yml' => Http::response('version: 1.0.0', 200),
        OFF_SWITCH_FEED_URL.'/latest.yml.sig' => Http::response(str_repeat('ab', 64), 200),
    ]);

    offSwitchFetcher()->fetch(UpdateChannel::Stable);

    Http::assertSent(static fn ($request): bool => $request->url() === OFF_SWITCH_FEED_URL.'/latest.yml');
});

it('takes one reader saying no as the whole device saying no', function (): void {
    // The banner is recorded at user_id NULL so every account sees the one
    // notification, and the call is one call from one machine.
    offSwitchUser(true);
    offSwitchUser(false);

    expect(app(UpdateCheckPreference::class)->enabled())->toBeFalse();
});

it('persists the switch from the settings section without a save button', function (): void {
    $user = offSwitchUser(true);
    $this->actingAs($user);

    Livewire::test(UpdateCheckSettingsSection::class)
        ->assertSet('enabled', true)
        ->call('toggle')
        ->assertSet('enabled', false);

    expect((bool) $user->fresh()->auto_update_check_enabled)->toBeFalse();
    expect(app(UpdateCheckPreference::class)->enabled())->toBeFalse();
});

it('switches the check back on from the same control', function (): void {
    $user = offSwitchUser(false);
    $this->actingAs($user);

    Livewire::test(UpdateCheckSettingsSection::class)
        ->assertSet('enabled', false)
        ->call('toggle')
        ->assertSet('enabled', true);

    expect((bool) $user->fresh()->auto_update_check_enabled)->toBeTrue();
});

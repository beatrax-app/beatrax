<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// setPin() refuses to run on an already-enabled lock, because enable()
// re-provisions the whole lock where changePin() only re-wraps it. The refusal
// read a public property the browser could move: set to false, a second setPin()
// rotated the salt and replaced the PIN hash on a lock that was already on, and
// enable() drops every biometric enrolment on its way through.

function enabledFlagSnapshot(string $pageHtml): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $pageHtml, $matches);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"auth.app-lock-settings-section"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the app lock section on the rendered page.');
}

/**
 * @param  array<string, mixed>  $updates
 */
function enabledFlagTamper(string $snapshot, array $updates): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [['path' => '', 'method' => 'setPin', 'params' => []]],
        ]],
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = User::query()->create([
        'username' => 'set-pin-guard',
        'password' => bcrypt('guard-pass-12'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '135790')
        ->set('confirmPin', '135790')
        ->set('accountPassword', 'guard-pass-12')
        ->call('setPin')
        ->assertSet('lockEnabled', true);
});

it('leaves the provisioned key alone when the browser claims the lock is off', function (): void {
    $before = $this->db->connection()->table('user_app_lock_configs')
        ->where('user_id', $this->user->id)->first(['kdf_salt', 'pin_hash']);

    $snapshot = enabledFlagSnapshot($this->get('/data-devices')->assertOk()->getContent());

    enabledFlagTamper($snapshot, [
        'lockEnabled' => false,
        'newPin' => '246802',
        'confirmPin' => '246802',
        'accountPassword' => 'guard-pass-12',
    ])->assertForbidden();

    $after = $this->db->connection()->table('user_app_lock_configs')
        ->where('user_id', $this->user->id)->first(['kdf_salt', 'pin_hash']);

    expect($after->kdf_salt)->toBe($before->kdf_salt)
        ->and($after->pin_hash)->toBe($before->pin_hash);
});

it('throws rather than accepting a write to the enabled flag', function (): void {
    Livewire::test(AppLockSettingsSection::class)->set('lockEnabled', false);
})->throws(CannotUpdateLockedPropertyException::class);

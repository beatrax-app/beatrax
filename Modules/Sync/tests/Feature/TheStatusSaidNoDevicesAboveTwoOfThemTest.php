<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Public\Http\Livewire\SyncStatusSection;

// The status line reads the sync_sessions table, and with none it fell to the
// empty state. That state was worded as if no device were paired — which the
// same screen contradicted two lines below, where both paired devices were
// listed as Confirmed. Nothing is wrong here except the sentence: no sync has
// run yet, which is a different thing from having nobody to sync with.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'sync-status-wording',
        'password' => 'opensesame-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

function pairedDevice(DatabaseManager $db, int $userId, string $name, bool $isSelf): void
{
    $hex = bin2hex(random_bytes(8));
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $hex,
        'name' => $name,
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'move tip main myth inner solid',
        'is_self' => $isSelf,
        'paired_at' => '2026-08-22T13:44:42Z',
        'confirmed_at' => '2026-08-22T13:44:42Z',
        'created_at' => '2026-08-22T13:44:42Z',
        'updated_at' => '2026-08-22T13:44:42Z',
    ]);
}

it('does not claim there are no devices while two are paired and confirmed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    pairedDevice($db, $this->user->id, 'Mac', true);
    pairedDevice($db, $this->user->id, "Wessel's S24 Ultra", false);

    Livewire::test(SyncStatusSection::class)
        ->assertSee(Lang::get('sync::status.not_synced_yet'))
        ->assertDontSee('No devices synced yet');
});

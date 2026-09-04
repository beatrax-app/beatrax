<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Sync\Public\Http\Livewire\SyncStatusSection;
use Modules\Sync\Public\Services\SyncStatusService;

uses(RefreshDatabase::class);

// A device with no recorded exchange has a null last_seen_at, and the relative
// formatter reads an absent stamp as the present moment: the row that has never
// synced then reads exactly like the row that synced a second ago. Both halves
// are asserted, because the honest word is only honest while the other row
// still shows its real gap.

function neverSyncedPeerUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function neverSyncedPeerSession(DatabaseManager $db, User $user, string $peerDeviceId, ?string $lastSeenAt): void
{
    $db->connection()->table('sync_sessions')->insert([
        'user_id' => $user->id,
        'local_device_id' => 'local-device',
        'peer_device_id' => $peerDeviceId,
        'status' => 'closed',
        'error_message' => null,
        'last_seen_at' => $lastSeenAt,
        'connected_at' => '2026-06-15T09:55:00Z',
        'created_at' => '2026-06-15T09:55:00Z',
        'updated_at' => '2026-06-15T10:00:00Z',
    ]);
}

function neverSyncedPeerFreezeClock(CarbonImmutable $now): void
{
    app()->bind(Clock::class, fn (): Clock => new class($now) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $frozen) {}

        public function now(): CarbonImmutable
        {
            return $this->frozen;
        }
    });
}

/** @return list<string> the text of every last-seen cell the surface drew */
function neverSyncedPeerCells(string $html): array
{
    return array_map(
        static fn (RenderedMarkup $cell): string => trim($cell->text()),
        RenderedMarkup::of($html)->all('[data-testid="peer-last-seen"]'),
    );
}

it('says a device has never synced rather than dating it to the moment the screen was drawn', function (): void {
    $user = neverSyncedPeerUser('never-synced-peer-alone');
    test()->actingAs($user);

    $now = CarbonImmutable::parse('2026-06-15T10:05:00Z');
    neverSyncedPeerFreezeClock($now);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    neverSyncedPeerSession($db, $user, 'peer-never-seen', null);

    $component = Livewire::test(SyncStatusSection::class);

    /** @var list<array<string, mixed>> $rows */
    $rows = $component->get('peerStatuses');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['last_seen_human'])->toBeNull(
        'a device with no recorded exchange has no interval to render; handing the formatter an absent stamp dates it to now',
    );

    $cells = neverSyncedPeerCells($component->html());
    expect($cells)->toHaveCount(1);

    $zeroInterval = SyncStatusService::relativeTime($now, $now->toIso8601String());
    expect($zeroInterval)->toBeString();

    expect($cells[0])->toBe(Lang::get('sync::status.never'))
        ->and($cells[0])->not->toBe($zeroInterval);
});

it('keeps the never-synced word on the device that earned it while the other device still shows its gap', function (): void {
    $user = neverSyncedPeerUser('never-synced-peer-beside-one');
    test()->actingAs($user);

    $now = CarbonImmutable::parse('2026-06-15T10:05:00Z');
    neverSyncedPeerFreezeClock($now);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    neverSyncedPeerSession($db, $user, 'peer-never-seen', null);
    neverSyncedPeerSession($db, $user, 'peer-seen-recently', '2026-06-15T10:00:00Z');

    $component = Livewire::test(SyncStatusSection::class);

    $cells = neverSyncedPeerCells($component->html());
    expect($cells)->toHaveCount(2, 'both paired devices must be drawn, or the comparison below proves nothing');

    $never = Lang::get('sync::status.never');
    $seen = SyncStatusService::relativeTime($now, '2026-06-15T10:00:00Z');

    expect($seen)->toBeString()->and($seen)->not->toBe($never);
    expect($cells)->toContain($never)
        ->and($cells)->toContain($seen);
});

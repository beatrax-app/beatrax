<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Import\Models\MerchantAlias;
use Modules\Import\Public\Actions\MergeMerchantAliases;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\Strategies\OrSetStrategy;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

// Renaming a counterparty is one of the commonest edits in the app, and every
// user-facing merchant_aliases write dispatched nothing: only the YAML
// importer's insert branch ever reached the op log, which was enough to satisfy
// the capture gate for the whole table.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'alias-capture-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

/**
 * @param  list<EntityMutated>  $events
 * @return list<string>
 */
function aliasMutationTypes(array $events): array
{
    return array_values(array_map(
        static fn (EntityMutated $event): string => $event->mutationType,
        array_filter($events, static fn (EntityMutated $event): bool => $event->table === 'merchant_aliases'),
    ));
}

it('records an inline pattern edit in the op log instead of keeping it on one device', function (): void {
    $alias = MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'SHELL-RAW',
        'generalized_pattern' => 'shell',
        'friendly_name' => 'Shell',
    ]);

    Event::fake([EntityMutated::class]);

    Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->call('startEdit', $alias->id)
        ->set('editingPattern', 'shell pieter')
        ->call('saveAlias', $alias->id);

    Event::assertDispatched(EntityMutated::class, static fn (EntityMutated $event): bool => $event->table === 'merchant_aliases'
        && $event->pk === $alias->id
        && $event->mutationType === 'edit'
        && ($event->dirtyFields['generalized_pattern'] ?? null) === 'shell pieter');
});

it('records an alias deletion in the op log instead of leaving the peer holding the row forever', function (): void {
    $alias = MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'SPOTIFY-RAW',
        'generalized_pattern' => 'spotify',
        'friendly_name' => 'Spotify',
    ]);

    Event::fake([EntityMutated::class]);

    Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->call('deleteAlias', $alias->id);

    Event::assertDispatched(EntityMutated::class, static fn (EntityMutated $event): bool => $event->table === 'merchant_aliases'
        && $event->pk === $alias->id
        && $event->mutationType === 'delete');
});

it('records the surviving row and every absorbed row when aliases are merged', function (): void {
    $aliases = collect(['A', 'B', 'C'])->map(fn (string $suffix): MerchantAlias => MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'SHELL-'.$suffix,
        'generalized_pattern' => 'shell '.strtolower($suffix),
        'friendly_name' => 'Shell '.$suffix,
    ]));

    /** @var list<EntityMutated> $captured */
    $captured = [];
    Event::listen(EntityMutated::class, function (EntityMutated $event) use (&$captured): void {
        $captured[] = $event;
    });

    app(MergeMerchantAliases::class)(
        $this->user,
        $aliases->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        'Shell',
        'shell',
    );

    expect(aliasMutationTypes($captured))->toBe(['edit', 'delete', 'delete']);
});

it('emits merged_from in the OR-Set wire shape the replayer can actually apply', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $aliases = collect(['A', 'B'])->map(fn (string $suffix): MerchantAlias => MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'JUMBO-'.$suffix,
        'generalized_pattern' => 'jumbo '.strtolower($suffix),
        'friendly_name' => 'Jumbo '.$suffix,
    ]));

    /** @var list<EntityMutated> $captured */
    $captured = [];
    Event::listen(EntityMutated::class, function (EntityMutated $event) use (&$captured): void {
        $captured[] = $event;
    });

    $surviving = app(MergeMerchantAliases::class)(
        $this->user,
        $aliases->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        'Jumbo',
        'jumbo',
    );

    $edit = $captured[0];
    $wireValue = $edit->dirtyFields['merged_from'] ?? null;

    // The shape OrSetStrategy demands. A plain list threw
    // "Malformed OR-Set value" on the peer and quarantined the whole merge.
    expect($wireValue)->toBeArray()
        ->and($wireValue)->toHaveKeys(['added', 'removed']);

    // Replaying that op on a peer must land the same column this device wrote.
    $peerAliasId = $db->connection()->table('merchant_aliases')->insertGetId([
        'user_id' => $this->user->id,
        'pattern' => 'JUMBO-PEER',
        'generalized_pattern' => 'jumbo peer',
        'friendly_name' => 'Jumbo peer',
        'merged_from' => null,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $signer = new DeviceKeySigner;
    $encoded = json_encode($wireValue, JSON_THROW_ON_ERROR);

    $userId = (int) $this->user->id;

    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: 'merchant_aliases',
        pk: $peerAliasId,
        field: 'merged_from',
        value: $encoded,
        hlcL: 1_000,
        hlcC: 0,
        deviceId: 'device-merging',
        opType: OpType::Set,
        signature: $signature,
        userId: $userId,
    );

    (new OpLogReplayer($db, ['device-merging' => bin2hex(sodium_crypto_sign_publickey($keypair))]))->replay(
        [$make($signer->sign($make('')->signingPayload(), sodium_crypto_sign_secretkey($keypair)))],
        $userId,
    );

    expect($db->connection()->table('merchant_aliases')->where('id', $peerAliasId)->value('merged_from'))
        ->toBe($db->connection()->table('merchant_aliases')->where('id', $surviving->id)->value('merged_from'))
        ->and($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('resolves what the merge writer emits through OrSetStrategy without throwing', function (): void {
    $aliases = collect(['A', 'B'])->map(fn (string $suffix): MerchantAlias => MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'AH-'.$suffix,
        'generalized_pattern' => 'ah '.strtolower($suffix),
        'friendly_name' => 'AH '.$suffix,
    ]));

    /** @var list<EntityMutated> $captured */
    $captured = [];
    Event::listen(EntityMutated::class, function (EntityMutated $event) use (&$captured): void {
        $captured[] = $event;
    });

    app(MergeMerchantAliases::class)(
        $this->user,
        $aliases->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        'Albert Heijn',
        'albert heijn',
    );

    $resolved = (new OrSetStrategy)->resolve([new OpLogEntry(
        table: 'merchant_aliases',
        pk: 1,
        field: 'merged_from',
        value: json_encode($captured[0]->dirtyFields['merged_from'], JSON_THROW_ON_ERROR),
        hlcL: 1,
        hlcC: 0,
        deviceId: 'device-merging',
        opType: OpType::Set,
        signature: '',
        userId: (int) $this->user->id,
    )]);

    expect($resolved)->toBeArray()->toHaveCount(1);
});

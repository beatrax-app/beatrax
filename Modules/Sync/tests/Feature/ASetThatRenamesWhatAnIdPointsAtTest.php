<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\RowOwnership;

uses(RefreshDatabase::class);

// `migration_source_map.beatrax_id` means whatever its sibling
// `beatrax_entity_type` says it means, so the pair is one reference written in
// two columns -- and a Set carries one column. Naming the type repoints the id
// without ever touching it.

function polyUser(string $name): User
{
    return User::query()->create([
        'username' => $name.'-'.bin2hex(random_bytes(3)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function polyAccount(int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    return (int) app(DatabaseManager::class)->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN poly', 'slug' => 'poly-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);
}

function polyMapRow(int $userId, string $type, int $beatraxId): int
{
    return (int) app(DatabaseManager::class)->connection()->table('migration_source_map')->insertGetId([
        'user_id' => $userId, 'source_product' => 'ynab', 'source_entity_type' => 'account',
        'source_external_id' => 'ext-'.bin2hex(random_bytes(3)),
        'beatrax_entity_type' => $type, 'beatrax_id' => $beatraxId,
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);
}

it('refuses a set of the type column that repoints the id at another reader', function (): void {
    $mine = polyUser('poly-mine');
    $theirs = polyUser('poly-theirs');

    $theirAccount = polyAccount((int) $theirs->id);
    $mapId = polyMapRow((int) $mine->id, 'category', $theirAccount);

    // The row said "category N". This says "account N", and account N is the
    // other reader's.
    expect(app(RowOwnership::class)->referencesBelongToUser(
        'migration_source_map',
        ['beatrax_entity_type' => 'account'],
        (int) $mine->id,
        $mapId,
    ))->toBeFalse();
});

it('takes a set of the type column that repoints the id at a row of this reader', function (): void {
    $mine = polyUser('poly-ok');
    $myAccount = polyAccount((int) $mine->id);
    $mapId = polyMapRow((int) $mine->id, 'category', $myAccount);

    // The control that stops the refusal above being "refuse every type set".
    expect(app(RowOwnership::class)->referencesBelongToUser(
        'migration_source_map',
        ['beatrax_entity_type' => 'account'],
        (int) $mine->id,
        $mapId,
    ))->toBeTrue();
});

it('still reads the stored type when the set names the id', function (): void {
    $mine = polyUser('poly-id');
    $theirs = polyUser('poly-id-theirs');

    $theirAccount = polyAccount((int) $theirs->id);
    $mapId = polyMapRow((int) $mine->id, 'account', polyAccount((int) $mine->id));

    expect(app(RowOwnership::class)->referencesBelongToUser(
        'migration_source_map',
        ['beatrax_id' => $theirAccount],
        (int) $mine->id,
        $mapId,
    ))->toBeFalse();
});

it('leaves a set naming neither column alone', function (): void {
    $mine = polyUser('poly-other');
    $mapId = polyMapRow((int) $mine->id, 'account', polyAccount((int) $mine->id));

    expect(app(RowOwnership::class)->referencesBelongToUser(
        'migration_source_map',
        ['source_external_id' => 'something-else'],
        (int) $mine->id,
        $mapId,
    ))->toBeTrue();
});

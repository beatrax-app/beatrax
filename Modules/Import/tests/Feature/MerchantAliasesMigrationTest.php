<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Import\Models\MerchantAlias;

it('creates the merchant_aliases table with the documented schema', function (): void {
    expect(Schema::hasTable('merchant_aliases'))->toBeTrue();
    expect(Schema::hasColumns('merchant_aliases', [
        'id', 'user_id', 'pattern', 'generalized_pattern', 'friendly_name',
        'merged_from', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('accepts a new per-user alias via the Eloquent model', function (): void {
    $user = User::create(['username' => 'alias-user', 'password' => 'pw', 'period_start_day' => 1]);

    $alias = MerchantAlias::create([
        'user_id' => $user->id,
        'pattern' => 'BCK*SHELL PIETER NIEUW *0123',
        'generalized_pattern' => 'bck*shell pieter nieuw',
        'friendly_name' => 'Shell Pieter Nieuw',
        'merged_from' => null,
    ]);

    expect($alias->id)->toBeGreaterThan(0);
    expect($alias->fresh()->friendly_name)->toBe('Shell Pieter Nieuw');
});

it('rejects a duplicate (user_id, pattern) tuple via the UNIQUE index', function (): void {
    $user = User::create(['username' => 'alias-dup', 'password' => 'pw', 'period_start_day' => 1]);

    MerchantAlias::create([
        'user_id' => $user->id,
        'pattern' => 'SAME-PATTERN',
        'generalized_pattern' => 'same pattern',
        'friendly_name' => 'First alias',
    ]);

    expect(fn () => MerchantAlias::create([
        'user_id' => $user->id,
        'pattern' => 'SAME-PATTERN',
        'generalized_pattern' => 'same pattern',
        'friendly_name' => 'Second alias',
    ]))->toThrow(QueryException::class);
});

it('round-trips the merged_from JSON cast through Eloquent', function (): void {
    $user = User::create(['username' => 'alias-merged', 'password' => 'pw', 'period_start_day' => 1]);

    $alias = MerchantAlias::create([
        'user_id' => $user->id,
        'pattern' => 'MERGE-TARGET',
        'generalized_pattern' => 'merge target',
        'friendly_name' => 'Merged Alias',
        'merged_from' => [
            ['id' => 7, 'pattern' => 'old-pattern-a', 'friendly_name' => 'Old A'],
            ['id' => 8, 'pattern' => 'old-pattern-b', 'friendly_name' => 'Old B'],
        ],
    ]);

    $alias->refresh();
    expect($alias->merged_from)->toBeArray();
    expect($alias->merged_from)->toHaveCount(2);
    expect($alias->merged_from[0]['pattern'])->toBe('old-pattern-a');
    expect($alias->merged_from[1]['friendly_name'])->toBe('Old B');
});

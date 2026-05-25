<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Import\Public\Services\MerchantNameResolver;

uses(RefreshDatabase::class);

/*
 * MerchantNameResolver covers the 5-step precedence walk for resolving
 * a raw bank-statement description to a user-chosen friendly name:
 *
 *   (a) user-exact alias  → return friendly_name
 *   (b) user-generalized  → return friendly_name (mb_strpos in PHP)
 *   (c) community-exact   → extension point, returns null in this slot
 *   (d) community-fuzzy   → extension point, returns null in this slot
 *   (e) raw description fallback → return null
 *
 * Every query carries an explicit `where('user_id', $userId)` clause
 * even though the MerchantAlias model already wears the BelongsToUser
 * global scope — the resolver may be called from a queue worker where
 * the global scope is not bound, so per-user safety is enforced at the
 * query level too.
 */

function makeResolverUser(string $username): User
{
    return User::create([
        'username' => $username,
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
}

function seedAlias(int $userId, string $pattern, string $generalized, string $friendly): void
{
    DB::table('merchant_aliases')->insert([
        'user_id' => $userId,
        'pattern' => $pattern,
        'generalized_pattern' => $generalized,
        'friendly_name' => $friendly,
        'merged_from' => null,
        'created_at' => '2026-05-25 12:00:00',
        'updated_at' => '2026-05-25 12:00:00',
    ]);
}

it('returns null when no aliases exist for the user', function (): void {
    $user = makeResolverUser('resolver-empty');

    $resolver = app(MerchantNameResolver::class);

    expect($resolver->resolve('UNKNOWN-X', $user->id))->toBeNull();
});

it('returns the friendly name on exact pattern match', function (): void {
    $user = makeResolverUser('resolver-exact');

    seedAlias(
        $user->id,
        'SHELL PIETER NIEUW *0123',
        'shell pieter nieuw',
        'Shell Pieter',
    );

    $resolver = app(MerchantNameResolver::class);

    expect($resolver->resolve('SHELL PIETER NIEUW *0123', $user->id))->toBe('Shell Pieter');
});

it('isolates aliases by user_id even when a foreign user owns a matching generalized pattern', function (): void {
    $owner = makeResolverUser('resolver-owner');
    $foreigner = makeResolverUser('resolver-foreigner');

    seedAlias(
        $owner->id,
        'SHELL PIETER NIEUW *0001',
        'shell pieter nieuw',
        'Shell Pieter',
    );

    $resolver = app(MerchantNameResolver::class);

    expect($resolver->resolve('SHELL PIETER NIEUW NEW', $foreigner->id))->toBeNull();
    expect($resolver->resolve('SHELL PIETER NIEUW NEW', $owner->id))->toBe('Shell Pieter');
});

it('returns the exact alias friendly name when both exact and generalized aliases match the same row', function (): void {
    $user = makeResolverUser('resolver-precedence');

    seedAlias(
        $user->id,
        'X',
        'x',
        'Generalized X',
    );
    seedAlias(
        $user->id,
        'X-EXACT',
        'x-exact',
        'Exact Match',
    );

    $resolver = app(MerchantNameResolver::class);

    expect($resolver->resolve('X-EXACT', $user->id))->toBe('Exact Match');
});

it('falls through to null when no alias matches and the community extension point is empty', function (): void {
    $user = makeResolverUser('resolver-fallthrough');

    seedAlias(
        $user->id,
        'OTHER',
        'other',
        'Other',
    );

    $resolver = app(MerchantNameResolver::class);

    expect($resolver->resolve('UNRELATED-MERCHANT', $user->id))->toBeNull();
});

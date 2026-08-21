<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserCountry;
use Modules\Import\Models\MerchantAlias;
use Modules\Import\Public\Actions\CreateMerchantAlias;
use Modules\Import\Public\Services\MerchantNameResolver;

function memoUser(string $username): User
{
    return User::create([
        'username' => $username,
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
}

function memoAlias(int $userId, string $pattern, string $generalized, string $friendly): void
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

/**
 * Counts the statements against merchant_aliases while $work runs.
 *
 * @param  callable(): void  $work
 */
function memoAliasQueryCount(callable $work): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count): void {
        if (str_contains($query->sql, 'merchant_aliases')) {
            $count++;
        }
    });

    $work();

    return $count;
}

// One resolver per call is the shape this file replaced: nothing is carried
// between calls, so every call pays the table read it would have paid before.
function memoColdResolver(): MerchantNameResolver
{
    return new MerchantNameResolver(
        app(DatabaseManager::class),
        app(CommunityCorpusQuery::class),
        app(UserCountry::class),
    );
}

/**
 * @return list<string>
 */
function memoSeedRealisticAliases(int $userId): array
{
    $aliases = [
        ['ALBERT HEIJN 1042 AMSTERDAM', 'albert heijn', 'Albert Heijn'],
        ['ALBERT 8891', 'albert', 'Albert'],
        ['SHELL PIETER NIEUW *0123', 'shell pieter nieuw', 'Shell Pieter'],
        ['SHELL 4411', 'shell', 'Shell'],
        ['NETFLIX.COM', 'netflix', 'Netflix'],
        ['SPOTIFYAB', 'spotify', 'Spotify'],
        ['123456', '123456', 'Numeric Pattern Merchant'],
        ['0123', '0123', 'Leading Zero Merchant'],
        ['EMPTY-GENERALIZED', '', 'Exact Only Merchant'],
        ['EMPTY-FRIENDLY', 'empty friendly', ''],
        ['CAFÉ ROTTERDAM', 'café', 'Café Rotterdam'],
        ['AMAZON.', 'amazon.', 'Amazon'],
    ];
    for ($i = 0; $i < 30; $i++) {
        $aliases[] = ["FIXTURE MERCHANT {$i} *00{$i}", "fixture merchant {$i}", "Fixture Merchant {$i}"];
    }

    foreach ($aliases as [$pattern, $generalized, $friendly]) {
        memoAlias($userId, $pattern, $generalized, $friendly);
    }

    $descriptions = [];
    foreach ($aliases as [$pattern, $generalized]) {
        $descriptions[] = $pattern;
        $descriptions[] = mb_strtolower($pattern);
        $descriptions[] = $generalized === '' ? $pattern.' EXTRA' : mb_strtoupper($generalized).' 9911 UTRECHT';
    }
    $descriptions[] = 'MOBIEL ABONNEMENT';
    $descriptions[] = 'NOTHING MATCHES HERE';
    $descriptions[] = '';
    $descriptions[] = 'ALBERT HEIJN TO GO 4410';
    $descriptions[] = 'AMAZON.NL MARKETPLACE';

    return $descriptions;
}

it('reads the alias table once for a whole statement instead of twice per row', function (): void {
    $user = memoUser('memo-query-count');
    memoAlias($user->id, 'ALBERT HEIJN 1042', 'albert heijn', 'Albert Heijn');

    $resolver = app(MerchantNameResolver::class);

    $queries = memoAliasQueryCount(function () use ($resolver, $user): void {
        for ($row = 0; $row < 50; $row++) {
            $resolver->resolve("ALBERT HEIJN 1042 ROW {$row}", $user->id);
        }
    });

    expect($queries)->toBe(1);
});

it('never answers one reader out of another reader\'s memoised aliases', function (): void {
    $first = memoUser('memo-first-reader');
    $second = memoUser('memo-second-reader');

    memoAlias($first->id, 'SHELL PIETER NIEUW *0001', 'shell pieter nieuw', 'First Reader Shell');
    memoAlias($second->id, 'ALBERT HEIJN 1042', 'albert heijn', 'Second Reader Albert');

    $resolver = app(MerchantNameResolver::class);

    // The first reader's memo is warmed first, so a key that ignored the user
    // would answer the second reader out of it — and the other order catches a
    // memo that is only correct because it happened to be filled in that order.
    expect($resolver->resolve('SHELL PIETER NIEUW GRONINGEN', $first->id))->toBe('First Reader Shell');
    expect($resolver->resolve('SHELL PIETER NIEUW GRONINGEN', $second->id))->toBeNull();
    expect($resolver->resolve('SHELL PIETER NIEUW *0001', $second->id))->toBeNull();
    expect($resolver->resolve('ALBERT HEIJN 1042', $second->id))->toBe('Second Reader Albert');
    expect($resolver->resolve('ALBERT HEIJN 1042', $first->id))->toBeNull();
});

it('gives a reader who has no aliases at all nothing from the reader before them', function (): void {
    $owner = memoUser('memo-owner');
    $newcomer = memoUser('memo-newcomer');

    memoAlias($owner->id, 'NETFLIX.COM', 'netflix', 'Netflix');

    $resolver = app(MerchantNameResolver::class);

    expect($resolver->resolve('NETFLIX.COM', $owner->id))->toBe('Netflix');
    expect($resolver->resolve('NETFLIX.COM', $newcomer->id))->toBeNull();
    expect($resolver->resolve('NETFLIX SUBSCRIPTION', $newcomer->id))->toBeNull();
});

it('answers every description exactly as a resolver that re-reads the table per call', function (): void {
    $user = memoUser('memo-identical-answers');
    $descriptions = memoSeedRealisticAliases($user->id);

    $memoised = app(MerchantNameResolver::class);

    $expected = [];
    $actual = [];
    foreach ($descriptions as $description) {
        $expected[$description] = memoColdResolver()->resolve($description, $user->id);
        $actual[$description] = $memoised->resolve($description, $user->id);
    }

    expect($actual)->toBe($expected)
        ->and($expected['ALBERT HEIJN 1042 AMSTERDAM'])->toBe('Albert Heijn')
        ->and($expected['ALBERT HEIJN TO GO 4410'])->toBe('Albert Heijn')
        ->and($expected['123456'])->toBe('Numeric Pattern Merchant')
        ->and($expected['0123'])->toBe('Leading Zero Merchant')
        ->and($expected['EMPTY-FRIENDLY'])->toBeNull()
        ->and($expected['NOTHING MATCHES HERE'])->toBeNull();
});

it('still matches an alias past the generalized scan cap on its exact pattern', function (): void {
    $user = memoUser('memo-scan-cap');

    for ($i = 0; $i < 500; $i++) {
        memoAlias($user->id, "FILLER {$i}", "filler {$i}", "Filler {$i}");
    }
    memoAlias($user->id, 'PAST THE CAP *9999', 'past the cap', 'Past The Cap');

    $memoised = app(MerchantNameResolver::class);

    expect($memoised->resolve('PAST THE CAP *9999', $user->id))
        ->toBe(memoColdResolver()->resolve('PAST THE CAP *9999', $user->id))
        ->toBe('Past The Cap');

    expect($memoised->resolve('PAST THE CAP AMSTERDAM', $user->id))
        ->toBe(memoColdResolver()->resolve('PAST THE CAP AMSTERDAM', $user->id))
        ->toBeNull();

    expect($memoised->resolve('FILLER 3 AMSTERDAM', $user->id))
        ->toBe(memoColdResolver()->resolve('FILLER 3 AMSTERDAM', $user->id))
        ->toBe('Filler 3');
});

it('sees an alias saved after the memo was already warm', function (): void {
    $user = memoUser('memo-invalidation');

    $resolver = app(MerchantNameResolver::class);
    expect($resolver->resolve('BAKKERIJ DE KORENAAR 88', $user->id))->toBeNull();

    app(CreateMerchantAlias::class)($user, 'BAKKERIJ DE KORENAAR 88', 'bakkerij de korenaar', 'De Korenaar');

    expect($resolver->resolve('BAKKERIJ DE KORENAAR 88', $user->id))->toBe('De Korenaar');
    expect($resolver->resolve('BAKKERIJ DE KORENAAR 91', $user->id))->toBe('De Korenaar');
});

it('sees a renamed alias after the memo was already warm', function (): void {
    $user = memoUser('memo-invalidation-rename');
    memoAlias($user->id, 'SHELL 4411', 'shell', 'Shell');

    $resolver = app(MerchantNameResolver::class);
    expect($resolver->resolve('SHELL 4411', $user->id))->toBe('Shell');

    app(CreateMerchantAlias::class)($user, 'SHELL 4411', 'shell', 'Shell Station');

    expect($resolver->resolve('SHELL 4411', $user->id))->toBe('Shell Station')
        ->and(MerchantAlias::query()->where('user_id', $user->id)->count())->toBe(1);
});

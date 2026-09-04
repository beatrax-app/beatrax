<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Search\Internal\Services\EntityNameSearch;
use Modules\Search\Public\Enums\SearchEntityKind;

// The palette returns at most three counterparty names, and a get() over the
// merchant list paid for the whole table before the match limit could fire —
// once per keystroke. Ciphertext gives SQL no name to match on, so the cap can
// only be a walk that stops, which means the walk has to be windowed.

function keystrokeUser(): User
{
    $id = app(DatabaseManager::class)->connection()->table('users')->insertGetId([
        'username' => 'keystroke-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::findOrFail($id);
}

function keystrokeSeed(User $user, int $count): void
{
    $now = now()->toDateTimeString();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'user_id' => $user->id,
            'type' => 'merchant',
            'slug' => 'keystroke-'.$i,
            // The needle the test types matches the first handful only, so a
            // walk that stops reads one window and a get() reads every row.
            'display_name' => ($i < 5 ? 'Zephyr Supplies ' : 'Albert Heijn ').$i,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('counterparties')->insert($chunk);
    }
}

/**
 * @return list<string>
 */
function keystrokeStatements(callable $run): array
{
    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        if (str_contains($query->sql, '"counterparties"')) {
            $statements[] = $query->sql;
        }
    });

    $run();

    return $statements;
}

it('reads one window and stops once it holds three names', function (): void {
    $user = keystrokeUser();
    keystrokeSeed($user, 1200);

    $results = [];
    $statements = keystrokeStatements(function () use ($user, &$results): void {
        $results = app(EntityNameSearch::class)->query($user, 'zephyr');
    });

    expect($statements)->toHaveCount(1)
        ->and($statements[0])->toContain('limit');

    $names = array_column(array_filter($results, static fn (array $r): bool => $r['type'] === SearchEntityKind::Counterparty->value), 'label');
    expect($names)->toBe(['Zephyr Supplies 0', 'Zephyr Supplies 1', 'Zephyr Supplies 2']);
})->group('bounded-read');

it('windows the walk even when nothing matches', function (): void {
    $user = keystrokeUser();
    keystrokeSeed($user, 1200);

    $statements = keystrokeStatements(function () use ($user): void {
        app(EntityNameSearch::class)->query($user, 'nosuchmerchantanywhere');
    });

    expect(count($statements))->toBeGreaterThan(1);
    foreach ($statements as $sql) {
        expect($sql)->toContain('limit');
    }
})->group('bounded-read');

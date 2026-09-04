<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\LocaleCollator;
use Modules\Counterparties\Public\Queries\CounterpartyDisplayName;

// The picker this fills is rendered by three Livewire components, and
// TransactionDetail re-renders it on every update the reader types. A single
// get() over the merchant list held the whole table beside the list it built,
// and the sort above it collated once per comparison rather than once per name.

function pickerUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/**
 * @return list<string>
 */
function pickerSeed(User $user, int $count): array
{
    $names = [];
    $now = now()->toDateTimeString();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $name = ['Ångström', 'Émile', 'Zeta', 'Albert Heijn', 'Œuvre'][$i % 5].' '.($i + 1);
        $names[] = $name;
        $rows[] = [
            'user_id' => $user->id,
            'type' => 'merchant',
            'slug' => 'picker-'.$i,
            'display_name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('counterparties')->insert($chunk);
    }

    return $names;
}

it('never reads the merchant list in one unbounded statement', function (): void {
    $user = pickerUser('picker-windowed');
    pickerSeed($user, 1200);

    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        if (str_contains($query->sql, '"counterparties"')) {
            $statements[] = $query->sql;
        }
    });

    app(CounterpartyDisplayName::class)->forUser((int) $user->id);

    expect($statements)->not->toBeEmpty();
    foreach ($statements as $sql) {
        expect($sql)->toContain('limit');
    }
})->group('bounded-read');

it('hands back the same names in the same order the pairwise collator gives', function (): void {
    $user = pickerUser('picker-order');
    $names = pickerSeed($user, 300);

    $reference = LocaleCollator::sorted($names, static fn (string $name): string => $name);
    usort($reference, static fn (string $a, string $b): int => LocaleCollator::compare($a, $b));

    $rows = app(CounterpartyDisplayName::class)->forUser((int) $user->id);

    expect($rows->pluck('display_name')->all())->toBe($reference)
        ->and($rows)->toHaveCount(300);
});

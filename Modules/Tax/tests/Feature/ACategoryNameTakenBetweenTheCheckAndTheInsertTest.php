<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Tax\Internal\Actions\TaxCategoryStore;
use Modules\Tax\Internal\Exceptions\DuplicateTaxCategoryNameException;

// The store asked whether the name was free and then wrote as if the answer
// were still true. unique(user_id, name) is what actually settles it, and it
// settles it at the write — so the loser of a double-submit reached the call
// site's generic arm, which prints "this category could not be saved" over a
// clash the reader could have fixed by typing a different name.

function ctaCatUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'cta-cat-'.$suffix.'-'.bin2hex(random_bytes(3)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

// The second writer, committed at the exact instant the check has just read
// "free". DB::listen fires after the query it describes has run, so the row
// lands between the answer and the write that trusts it.
function ctaCatInjectAfterSelect(int $skip, int $userId, string $name): void
{
    $seen = 0;
    $done = false;

    DB::listen(function ($query) use (&$seen, &$done, $skip, $userId, $name): void {
        if ($done || ! str_contains($query->sql, 'tax_deduction_categories')) {
            return;
        }
        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }
        if ($seen++ < $skip) {
            return;
        }

        $done = true;
        DB::table('tax_deduction_categories')->insert([
            'user_id' => $userId,
            'name' => $name,
            'short_name' => 'Cmp',
            'status' => 'active',
            'sort_order' => 0,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    });
}

it('answers a name taken between the check and the insert with the clash, not a failed save', function (): void {
    $user = ctaCatUser('add-race');

    /** @var TaxCategoryStore $store */
    $store = app(TaxCategoryStore::class);

    ctaCatInjectAfterSelect(0, (int) $user->id, 'Giften');

    $thrown = null;
    try {
        $store->add((int) $user->id, 'Giften');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(DuplicateTaxCategoryNameException::class)
        ->and($thrown?->getMessage())->toBe(Lang::get('tax::messages.errors.name_duplicate'));
});

it('answers a rename whose target name is taken between the check and the update with the clash', function (): void {
    $user = ctaCatUser('rename-race');

    /** @var TaxCategoryStore $store */
    $store = app(TaxCategoryStore::class);
    $categoryId = $store->add((int) $user->id, 'Original Name');

    ctaCatInjectAfterSelect(1, (int) $user->id, 'Taken Name');

    $thrown = null;
    try {
        $store->rename((int) $user->id, $categoryId, 'Taken Name');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(DuplicateTaxCategoryNameException::class)
        ->and($thrown?->getMessage())->toBe(Lang::get('tax::messages.errors.name_duplicate'))
        ->and(DB::table('tax_deduction_categories')->where('id', $categoryId)->value('name'))->toBe('Original Name');
});

it('refuses an uncontested duplicate with the same exception the contested one raises', function (): void {
    $user = ctaCatUser('add-plain');

    /** @var TaxCategoryStore $store */
    $store = app(TaxCategoryStore::class);
    $store->add((int) $user->id, 'Duplicate Category');

    expect(fn (): int => $store->add((int) $user->id, 'Duplicate Category'))
        ->toThrow(DuplicateTaxCategoryNameException::class);
});

it('returns the id of the row it wrote rather than of a row it found by name', function (): void {
    $user = ctaCatUser('add-id');

    /** @var TaxCategoryStore $store */
    $store = app(TaxCategoryStore::class);
    $id = $store->add((int) $user->id, 'Kilometervergoeding', 'Km', 'Zakelijke kilometers');

    $row = DB::table('tax_deduction_categories')->where('id', $id)->first();

    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Kilometervergoeding')
        ->and($row->short_name)->toBe('Km')
        ->and((int) $row->user_id)->toBe((int) $user->id)
        ->and((int) $row->sort_order)->toBe(0);
});

it('seeds a country inside one transaction, so the sort base it read cannot be spent beside it', function (): void {
    $user = ctaCatUser('seed');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // The suite itself holds a transaction open, so the depth the seed adds is
    // what this measures, never the absolute number.
    $ambient = $db->connection()->transactionLevel();

    $levels = [];
    DB::listen(function ($query) use (&$levels, $db): void {
        if (str_contains($query->sql, 'tax_deduction_categories')) {
            $levels[] = $db->connection()->transactionLevel();
        }
    });

    $inserted = app(TaxCategoryStore::class)->seedFromCorpus($user, 'nl');
    $during = $levels;

    $stored = DB::table('tax_deduction_categories')->where('user_id', $user->id)->count();
    $orders = DB::table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->orderBy('sort_order')
        ->pluck('sort_order')
        ->map(static fn ($value): int => (int) $value)
        ->all();

    expect($inserted)->toBe($stored)
        ->and($during)->not->toBeEmpty()
        ->and(min($during))->toBe($ambient + 1)
        ->and($orders)->toBe(range(0, $inserted - 1));
});

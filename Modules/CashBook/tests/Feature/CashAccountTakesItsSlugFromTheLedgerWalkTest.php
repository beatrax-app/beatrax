<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\CashBook\Internal\Actions\RecordManualTransaction;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Services\AccountSlugResolver;

// accounts.slug carries one unique(user_id, slug) and the cash account minted
// its own spelling — `cash-<user id>` — which no walk had ever offered to any
// other account. Name an account "Cash <n>" and the ledger's own resolver
// hands out exactly that string.

it('does not collide with a slug the ledger resolver already handed out', function (): void {
    $user = User::query()->create([
        'username' => 'cash-slug-collision',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    DB::table('accounts')->insert([
        'user_id' => $user->id,
        'name' => 'Cash '.$user->id,
        'slug' => AccountSlugResolver::slugify('Cash '.$user->id),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    app(RecordManualTransaction::class)(
        $user,
        Direction::Expense->value,
        1250,
        CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'Bakery',
    );

    $slug = DB::table('accounts')
        ->where('user_id', $user->id)
        ->where('kind', 'cash')
        ->value('slug');

    expect($slug)->toBe('cash');
});

it('walks past a taken base the way every other account creator does', function (): void {
    $user = User::query()->create([
        'username' => 'cash-slug-walk',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    DB::table('accounts')->insert([
        'user_id' => $user->id,
        'name' => 'Cash',
        'slug' => 'cash',
        'kind' => 'bank',
        'iban' => 'NL22ASNB0555999111',
        'default_currency' => 'EUR',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    app(RecordManualTransaction::class)(
        $user,
        Direction::Expense->value,
        900,
        CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'Market',
    );

    $slug = DB::table('accounts')
        ->where('user_id', $user->id)
        ->where('kind', 'cash')
        ->value('slug');

    expect($slug)->toBe('cash-2');
});

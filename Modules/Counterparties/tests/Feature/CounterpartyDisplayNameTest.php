<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Queries\CounterpartyDisplayName;

// Four surfaces derived this list for themselves — the transaction detail
// picker, the rule-form picker, the report builder and the rule query — and
// three of them sorted it by bytes, which files every accented merchant after
// Z. The order is part of what the caller is being handed.

it('hands every caller the same list in the reader alphabet', function (): void {
    $user = User::query()->create([
        'username' => 'cp-display-name-order',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $now = now()->toDateTimeString();
    foreach (['Zeta Zaken', 'Ångström AB', 'Émile Fleurs', 'Alpha BV'] as $index => $name) {
        DB::table('counterparties')->insert([
            'user_id' => $user->id,
            'type' => 'merchant',
            'slug' => 'cdn-'.$index,
            'display_name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $rows = app(CounterpartyDisplayName::class)->forUser((int) $user->id);

    expect($rows->pluck('display_name')->all())
        ->toBe(['Alpha BV', 'Ångström AB', 'Émile Fleurs', 'Zeta Zaken']);
});

it('answers only for the ids it was asked about, and only under their owner', function (): void {
    $owner = User::query()->create([
        'username' => 'cp-display-name-owner',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $stranger = User::query()->create([
        'username' => 'cp-display-name-stranger',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $now = now()->toDateTimeString();
    $mine = DB::table('counterparties')->insertGetId([
        'user_id' => $owner->id,
        'type' => 'merchant',
        'slug' => 'cdn-mine',
        'display_name' => 'Café Ambiance',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $theirs = DB::table('counterparties')->insertGetId([
        'user_id' => $stranger->id,
        'type' => 'merchant',
        'slug' => 'cdn-theirs',
        'display_name' => 'Not mine',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(app(CounterpartyDisplayName::class)->forIds([$mine, $theirs], (int) $owner->id))
        ->toBe([$mine => 'Café Ambiance']);
});

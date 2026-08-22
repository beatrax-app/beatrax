<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;

// A bank's web page and a statement PDF group an IBAN with U+00A0, not with a
// space. The card compacted with /\s+/ and no u modifier, which leaves U+00A0
// standing, and the byte offsets the mask then slices land mid-character.

it('masks an IBAN grouped with non-breaking spaces the way it masks a plain one', function (): void {
    $user = User::query()->create([
        'username' => 'triage-nbsp-iban',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $now = now()->toDateTimeString();
    DB::table('counterparties')->insert([
        'user_id' => $user->id,
        'type' => 'unknown',
        'slug' => 'mystery-nbsp',
        'display_name' => 'Mystery payee',
        'iban' => "NL91\u{00A0}ABNA\u{00A0}0417\u{00A0}1643\u{00A0}00",
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::actingAs($user)
        ->test(CounterpartyTriage::class)
        ->assertSee('NL · ·· ABNA ···· ···· 00');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;

// The all-caught-up card owns the only h2 on this page, so with work still in
// the queue the headings ran h1 then h3. A reader moving by heading — the
// rotor on the phone, H on a desktop screen reader — is told a level is
// missing and cannot tell what the h3 is nested under.
function triageHeadingUser(string $username = 'triage-heading-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function triageHeadingUnknown(int $userId, string $slug, string $iban): void
{
    $now = now()->toDateTimeString();

    DB::table('counterparties')->insert([
        'user_id' => $userId,
        'type' => 'unknown',
        'slug' => $slug,
        'display_name' => $iban,
        'iban' => $iban,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/** @return list<int> heading levels in document order */
function triageHeadingLevels(string $html): array
{
    preg_match_all('/<h([1-6])[\s>]/i', $html, $m);

    return array_map(intval(...), $m[1]);
}

it('never jumps a heading level while the queue still has work', function (): void {
    $user = triageHeadingUser();
    triageHeadingUnknown($user->id, 'mystery-1', 'NL12RABO0000000001');

    $levels = triageHeadingLevels(Livewire::actingAs($user)->test(CounterpartyTriage::class)->html());

    expect($levels)->not->toBe([]);

    $previous = 0;
    foreach ($levels as $level) {
        if ($previous !== 0) {
            expect($level)->toBeLessThanOrEqual($previous + 1);
        }
        $previous = $level;
    }
});

it('never jumps a heading level once the queue is empty', function (): void {
    $user = triageHeadingUser('triage-heading-empty');

    $levels = triageHeadingLevels(Livewire::actingAs($user)->test(CounterpartyTriage::class)->html());

    expect($levels)->not->toBe([]);

    $previous = 0;
    foreach ($levels as $level) {
        if ($previous !== 0) {
            expect($level)->toBeLessThanOrEqual($previous + 1);
        }
        $previous = $level;
    }
});

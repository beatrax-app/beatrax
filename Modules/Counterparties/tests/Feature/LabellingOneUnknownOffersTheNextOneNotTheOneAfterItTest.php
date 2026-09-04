<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Enums\CounterpartyType;

// A decided row is filtered out of the queue by sessionDoneIds on the next
// render, so the row behind it moves into the index the cursor already holds.
// Every write path also incremented the cursor, so it landed one past that —
// and one unknown in every labelled pair was never put in front of the reader.
function cursorTriageUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/** @return list<int> queue order: updated_at then id, both descending */
function cursorTriageQueue(int $userId, int $count): array
{
    $ids = [];
    for ($i = 1; $i <= $count; $i++) {
        $now = now()->toDateTimeString();
        $ids[] = DB::table('counterparties')->insertGetId([
            'user_id' => $userId,
            'type' => CounterpartyType::Unknown->value,
            'slug' => 'mystery-cursor-'.$i,
            'display_name' => 'Mystery '.$i,
            'iban' => null,
            'merchant_name' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return array_reverse($ids);
}

function cursorTriageCardName(int $userId): string
{
    $html = (string) Livewire::actingAs(User::query()->findOrFail($userId))
        ->test(CounterpartyTriage::class)
        ->html();

    $m = PatternScan::first('/class="triage-iban">(.*?)</s', $html);

    return trim($m[1] ?? '');
}

it('offers the very next unknown after a hand-labelled one', function (): void {
    $user = cursorTriageUser('triage-cursor-manual');
    [, $second] = cursorTriageQueue($user->id, 3);

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->set('draftName', 'Albert Heijn')
        ->call('manualLabel');

    $component->assertSet('currentIndex', 0);

    /** @var Counterparty $offered */
    $offered = Counterparty::query()->where('id', $second)->firstOrFail();
    expect($component->html())->toContain($offered->display_name);
});

it('offers the very next unknown after an ignored one', function (): void {
    $user = cursorTriageUser('triage-cursor-ignored');
    [, $second] = cursorTriageQueue($user->id, 3);

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class)->call('markIgnored');

    /** @var Counterparty $offered */
    $offered = Counterparty::query()->where('id', $second)->firstOrFail();
    expect($component->html())->toContain($offered->display_name);
});

// Skipping is a move and labelling is not, so only one of them steps the
// cursor. Three rows is the smallest queue where stepping twice is visible.
it('walks a whole queue of three without stepping over one', function (): void {
    $user = cursorTriageUser('triage-cursor-walk');
    cursorTriageQueue($user->id, 3);

    $seen = [];
    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);

    for ($i = 0; $i < 3; $i++) {
        $m = PatternScan::first('/class="triage-iban">(.*?)</s', (string) $component->html());
        $seen[] = trim($m[1] ?? '');
        $component->set('draftName', 'Named '.$i)->call('manualLabel');
    }

    expect($seen)->toBe(['Mystery 3', 'Mystery 2', 'Mystery 1'])
        ->and(Counterparty::query()->where('user_id', $user->id)->where('type', CounterpartyType::Unknown->value)->count())->toBe(0);
});

it('reaches the caught-up card only once every unknown has been offered', function (): void {
    $user = cursorTriageUser('triage-cursor-caught-up');
    cursorTriageQueue($user->id, 2);

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->set('draftName', 'One')
        ->call('manualLabel');

    expect($component->html())->not->toContain('All caught up');

    $component->set('draftName', 'Two')->call('manualLabel');

    expect($component->html())->toContain('All caught up')
        ->and(cursorTriageCardName($user->id))->toBe('');
});

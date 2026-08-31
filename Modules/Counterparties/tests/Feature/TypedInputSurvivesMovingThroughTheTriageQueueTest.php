<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Enums\CounterpartyType;

// Verbatim from the phone: "Geldlener BV" typed into Display name, then Next.
// The field came back empty and Previous did not recover it. The name lived in
// an Alpine x-model, so every Livewire re-render dropped it — and Next was the
// loudest control on the card.
function draftTriageUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function draftTriageUnknown(int $userId, string $slug, ?string $iban = null): int
{
    $now = now()->toDateTimeString();

    return DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => CounterpartyType::Unknown->value,
        'slug' => $slug,
        'display_name' => $iban ?? $slug,
        'iban' => $iban,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('gives back the name the reader typed after they walk forward and back', function (): void {
    $user = draftTriageUser('triage-draft-roundtrip');
    draftTriageUnknown($user->id, 'mystery-draft-1', 'NL12RABO0000000101');
    draftTriageUnknown($user->id, 'mystery-draft-2', 'NL12RABO0000000102');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->set('draftName', 'Geldlener BV')
        ->call('nextItem')
        ->assertSet('draftName', '')
        ->call('previousItem')
        ->assertSet('draftName', 'Geldlener BV');
});

it('keeps a draft against its own counterparty rather than against the cursor', function (): void {
    $user = draftTriageUser('triage-draft-per-row');
    draftTriageUnknown($user->id, 'mystery-per-row-1', 'NL12RABO0000000201');
    draftTriageUnknown($user->id, 'mystery-per-row-2', 'NL12RABO0000000202');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->set('draftName', 'First one')
        ->set('draftType', CounterpartyType::Bank->value)
        ->call('nextItem')
        ->set('draftName', 'Second one')
        ->set('draftType', CounterpartyType::Government->value)
        ->call('previousItem')
        ->assertSet('draftName', 'First one')
        ->assertSet('draftType', CounterpartyType::Bank->value)
        ->call('nextItem')
        ->assertSet('draftName', 'Second one')
        ->assertSet('draftType', CounterpartyType::Government->value);
});

it('survives a skip, which is the same movement under another name', function (): void {
    $user = draftTriageUser('triage-draft-skip');
    draftTriageUnknown($user->id, 'mystery-skip-draft-1', 'NL12RABO0000000301');
    draftTriageUnknown($user->id, 'mystery-skip-draft-2', 'NL12RABO0000000302');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->set('draftName', 'Kept across a skip')
        ->call('skipForNow')
        // The next card is blank rather than carrying the previous one's text,
        // which is the half a bare round trip would pass without proving.
        ->assertSet('draftName', '')
        ->call('previousItem')
        ->assertSet('draftName', 'Kept across a skip');
});

// A decided row is decided: keeping its draft would offer the reader a name
// for a counterparty that already has one.
it('drops the draft of a counterparty the reader has finished with', function (): void {
    $user = draftTriageUser('triage-draft-cleared');
    draftTriageUnknown($user->id, 'mystery-cleared-1', 'NL12RABO0000000401');
    // The queue reads updated_at then id descending, so the row inserted last
    // is the one the cursor starts on.
    $head = draftTriageUnknown($user->id, 'mystery-cleared-2', 'NL12RABO0000000402');

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->set('draftName', 'Corner Bakery')
        ->call('manualLabel');

    $component->assertSet('draftName', '')
        ->assertSet('drafts', []);

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->where('id', $head)->firstOrFail();
    expect($stored->display_name)->toBe('Corner Bakery');
});

// The button that records the decision did nothing at all on a blank name, and
// said nothing about it either.
it('says why nothing was saved when the name is blank', function (): void {
    $user = draftTriageUser('triage-draft-blank');
    $unknown = draftTriageUnknown($user->id, 'mystery-blank', 'NL12RABO0000000501');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->set('draftName', '   ')
        ->call('manualLabel')
        ->assertHasErrors('draftName');

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->where('id', $unknown)->firstOrFail();
    expect($stored->type)->toBe(CounterpartyType::Unknown->value);
});

// The collaborators moved to the front of manualLabel()'s signature so the view
// can call it with none. Laravel binds what it cannot resolve positionally, and
// four other test files still pass the name and the type that way.
it('still binds a positional name and type after the signature moved', function (): void {
    $user = draftTriageUser('triage-draft-positional');
    $unknown = draftTriageUnknown($user->id, 'mystery-positional', 'NL12RABO0000000601');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->call('manualLabel', 'Jane Doe', CounterpartyType::Personal->value);

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->where('id', $unknown)->firstOrFail();

    expect($stored->type)->toBe(CounterpartyType::Personal->value)
        ->and($stored->display_name)->toBe('Jane Doe')
        ->and($stored->merchant_name)->toBeNull();
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;
use Modules\Counterparties\Public\Enums\CounterpartyType;

// Measured in headless Chromium with a coarse pointer against the built
// stylesheet, at 375px and 411px in all 26 locales: five distinct left edges
// and seven right edges, the loudest control on the screen doing none of the
// work, and the primary drawn as bare grey text with no button chrome at all.
function primaryTriageUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function primaryTriageUnknown(int $userId, string $slug, ?string $iban = null): int
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

/** @return list<string> every <button> element inside the triage card, markup and all */
function primaryTriageButtons(string $html): array
{
    $found = PatternScan::all('~<button\b.*?</button>~s', $html);

    return $found[0];
}

// x-core::neutral-button is the only solid fill in this card. Its `bg-slate-900`
// carries a leading space; form-field's `dark:bg-slate-900` does not.
function primaryTriageSolid(string $html): array
{
    return array_values(array_filter(
        primaryTriageButtons($html),
        static fn (string $button): bool => str_contains($button, ' bg-slate-900 '),
    ));
}

it('draws exactly one solid button, and it is the one that records a decision', function (): void {
    $user = primaryTriageUser('triage-one-primary');
    primaryTriageUnknown($user->id, 'mystery-primary', 'NL12RABO0000000701');

    $html = (string) Livewire::actingAs($user)->test(CounterpartyTriage::class)->html();
    $solid = primaryTriageSolid($html);

    expect($solid)->toHaveCount(1)
        ->and($solid[0])->toContain('Save label')
        ->and($solid[0])->toContain('wire:click="manualLabel"');
});

// `Next ▸` was the solid one, and it wrote nothing. It is gone: skipForNow()
// and nextItem() were the same movement under two names and two weights.
it('offers no second forward control louder than the one that skips', function (): void {
    $user = primaryTriageUser('triage-no-next');
    primaryTriageUnknown($user->id, 'mystery-no-next', 'NL12RABO0000000801');

    $html = (string) Livewire::actingAs($user)->test(CounterpartyTriage::class)->html();

    expect($html)->not->toContain('wire:click="nextItem"')
        ->and($html)->not->toContain('counterparties::triage.next')
        ->and($html)->toContain('wire:click="skipForNow"');
});

it('puts every action on the card content edge, at full width', function (): void {
    $user = primaryTriageUser('triage-one-edge');
    primaryTriageUnknown($user->id, 'mystery-edge', 'NL12RABO0000000901');

    $html = (string) Livewire::actingAs($user)->test(CounterpartyTriage::class)->html();

    $buttons = primaryTriageButtons($html);
    expect($buttons)->not->toBe([]);

    foreach ($buttons as $button) {
        expect($button)->toContain('w-full');
    }

    // The hand-styled block that opted out of the grid: seven elements carried
    // an inline flex / padding / border of their own, and nothing shared an edge.
    $decide = PatternScan::first('~<fieldset class="triage-decide".*?</fieldset>~s', $html);
    expect($decide)->not->toBe([]);
    expect($decide[0])->not->toContain('style="flex')
        ->and($decide[0])->not->toContain('flex: 1 1 240px')
        ->and($decide[0])->not->toContain('pill-btn');
});

// Ignoring writes metadata.ignored and nothing else. The index card's "Label
// this counterparty" link carries ?queue_first=, which overrides the queue's
// own ignore filter — so it is one tap back, and rose said the opposite.
it('does not paint a reversible choice as a destructive one', function (): void {
    $user = primaryTriageUser('triage-not-destructive');
    primaryTriageUnknown($user->id, 'mystery-ignore', 'NL12RABO0000001001');

    $html = (string) Livewire::actingAs($user)->test(CounterpartyTriage::class)->html();

    $ignore = array_values(array_filter(
        primaryTriageButtons($html),
        static fn (string $button): bool => str_contains($button, 'wire:click="markIgnored"'),
    ));

    expect($ignore)->toHaveCount(1)
        ->and($ignore[0])->not->toContain('--color-rose')
        ->and($ignore[0])->not->toContain('rose-')
        ->and($html)->toContain('you can still label it later from the counterparties page');
});

it('names the branch the manual fields are an alternative to, or drops the "or"', function (): void {
    $user = primaryTriageUser('triage-legend');
    primaryTriageUnknown($user->id, 'mystery-legend', 'NL12RABO0000001101');

    $html = (string) Livewire::actingAs($user)->test(CounterpartyTriage::class)->html();

    expect($html)->toContain('What is this counterparty?')
        ->and($html)->not->toContain('Or label manually');
});

it('stacks the action area in one column rather than flowing it into a row', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect($css)->toContain(".triage-stack {\n        display: flex;\n        flex-direction: column;\n        gap: var(--space-2);\n        min-width: 0;\n    }")
        ->and($css)->toContain(".triage-decide {\n        display: block;\n        min-width: 0;\n        margin: 0;\n        padding: 0;\n        border: 0;\n    }")
        ->and($css)->not->toContain('.triage-actions {');
});

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Queries\CounterpartyTriageQueue;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Public\Events\EntityMutated;

// Triage is the app's second writer of `counterparties`. The resolver announces
// what it writes; triage wrote silently, kept a slug the display name no longer
// derives, and hid an ignored row for exactly as long as the session lasted.

function triageDecisionUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function triageDecisionAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.str_pad((string) random_int(1, 9999999999), 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);
}

function triageDecisionUnknown(User $user, string $slug, string $displayName): int
{
    $now = now()->toDateTimeString();

    return DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => CounterpartyType::Unknown->value,
        'slug' => $slug,
        'display_name' => $displayName,
        'iban' => null,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function triageDecisionAlias(User $user, string $pattern, string $generalized, string $friendly): void
{
    DB::table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => $pattern,
        'generalized_pattern' => $generalized,
        'friendly_name' => $friendly,
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function triageDecisionCanonical(User $user, Account $account, string $description): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: (int) $user->id,
        accountId: (int) $account->id,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-03-01'),
        bookedAt: CarbonImmutable::parse('2026-03-01 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-03-01'),
        amountMinor: -2500,
        currency: 'EUR',
        settledAmountMinor: -2500,
        settledCurrency: 'EUR',
        counterpartyName: null,
        counterpartyIban: null,
        counterpartyNormalized: 'triage-decision',
        normalizationVersion: 1,
        description: $description,
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: 1,
        sourceRowIndex: 1,
        sourceRef: 'triage-decision:'.uniqid(),
    );
}

/** @return list<EntityMutated> */
function triageDecisionCounterpartyOps(callable $write): array
{
    $captured = [];
    $listener = static function (EntityMutated $event) use (&$captured): void {
        if ($event->table === 'counterparties') {
            $captured[] = $event;
        }
    };

    app(Dispatcher::class)->listen(EntityMutated::class, $listener);
    $write();
    app(Dispatcher::class)->forget(EntityMutated::class);

    return $captured;
}

it('keeps an ignored row hidden on the next visit, not only for the session', function (): void {
    $user = triageDecisionUser('triage-ignore-persists');
    $ignoredId = triageDecisionUnknown($user, 'mystery-ignored', 'NL10BANK0000000601');
    triageDecisionUnknown($user, 'mystery-kept', 'NL10BANK0000000602');

    /** @var CounterpartyTriageQueue $queue */
    $queue = app(CounterpartyTriageQueue::class);
    expect($queue->forUser($user))->toHaveCount(2);

    Livewire::actingAs($user)->test(CounterpartyTriage::class, ['queue_first' => $ignoredId])
        ->call('markIgnored');

    $remaining = $queue->forUser($user);

    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]->slug)->toBe('mystery-kept')
        ->and($queue->unknownCountForUser($user))->toBe(1);
});

it('still opens an ignored row the reader asks for by id', function (): void {
    $user = triageDecisionUser('triage-ignore-reopened');
    $ignoredId = triageDecisionUnknown($user, 'mystery-reopened', 'NL10BANK0000000609');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)->call('markIgnored');

    /** @var CounterpartyTriageQueue $queue */
    $queue = app(CounterpartyTriageQueue::class);
    $requested = $queue->forUser($user, $ignoredId);

    // Both index cards and the unknown profile's label CTA link here with
    // ?queue_first={id}; without the override those links land nowhere.
    expect($requested)->toHaveCount(1)
        ->and($requested[0]->id)->toBe($ignoredId);
});

it('leaves the ignored row on file as an unknown rather than deleting or retyping it', function (): void {
    $user = triageDecisionUser('triage-ignore-shape');
    $ignoredId = triageDecisionUnknown($user, 'mystery-shape', 'NL10BANK0000000603');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)->call('markIgnored');

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->where('id', $ignoredId)->firstOrFail();

    expect($stored->type)->toBe(CounterpartyType::Unknown->value)
        ->and($stored->metadata)->toBe(['ignored' => true]);
});

it('tells the peer that a row was ignored', function (): void {
    $user = triageDecisionUser('triage-ignore-announced');
    $ignoredId = triageDecisionUnknown($user, 'mystery-announced', 'NL10BANK0000000604');

    $ops = triageDecisionCounterpartyOps(static function () use ($user): void {
        Livewire::actingAs($user)->test(CounterpartyTriage::class)->call('markIgnored');
    });

    expect($ops)->toHaveCount(1)
        ->and($ops[0]->mutationType)->toBe('edit')
        ->and($ops[0]->pk)->toBe($ignoredId)
        ->and($ops[0]->userId)->toBe((int) $user->id)
        ->and($ops[0]->dirtyFields['metadata'] ?? null)->toBe(['ignored' => true]);
});

it('labels an unknown by hand with the name and the type the reader chose', function (): void {
    $user = triageDecisionUser('triage-manual-merchant');
    $unknownId = triageDecisionUnknown($user, 'mystery-manual', 'NL10BANK0000000605');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->call('manualLabel', 'Albert Heijn', CounterpartyType::Merchant->value);

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->where('id', $unknownId)->firstOrFail();

    expect($stored->type)->toBe(CounterpartyType::Merchant->value)
        ->and($stored->display_name)->toBe('Albert Heijn')
        ->and($stored->merchant_name)->toBe('Albert Heijn');
});

it('anchors no alias name on a hand-labelled row that is not a merchant', function (): void {
    $user = triageDecisionUser('triage-manual-government');
    $unknownId = triageDecisionUnknown($user, 'mystery-gov', 'NL10BANK0000000606');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->call('manualLabel', 'Belastingdienst', CounterpartyType::Government->value);

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->where('id', $unknownId)->firstOrFail();

    expect($stored->type)->toBe(CounterpartyType::Government->value)
        ->and($stored->display_name)->toBe('Belastingdienst')
        ->and($stored->merchant_name)->toBeNull();
});

it('writes nothing for a blank name or a type outside the manual picker', function (): void {
    $user = triageDecisionUser('triage-manual-guard');
    $unknownId = triageDecisionUnknown($user, 'mystery-guarded', 'NL10BANK0000000607');

    $component = Livewire::actingAs($user)->test(CounterpartyTriage::class);
    $component->call('manualLabel', '   ', CounterpartyType::Merchant->value);
    $component->call('manualLabel', 'Own savings', CounterpartyType::SelfAccount->value);
    $component->call('manualLabel', 'Still nothing', 'not-a-type');

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->where('id', $unknownId)->firstOrFail();

    expect($stored->type)->toBe(CounterpartyType::Unknown->value)
        ->and($stored->slug)->toBe('mystery-guarded');
});

it('re-derives the slug from the name the reader typed', function (): void {
    $user = triageDecisionUser('triage-rename-slug');
    $unknownId = triageDecisionUnknown($user, 'bol', 'Bol');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->call('manualLabel', 'Albert Heijn', CounterpartyType::Merchant->value);

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->where('id', $unknownId)->firstOrFail();

    expect($stored->slug)->toBe('albert-heijn');
});

it('suffixes rather than collides when the typed name already has a slug', function (): void {
    $user = triageDecisionUser('triage-rename-collision');
    triageDecisionUnknown($user, 'albert-heijn', 'Albert Heijn');
    $renamedId = triageDecisionUnknown($user, 'bol', 'Bol');

    Livewire::actingAs($user)->test(CounterpartyTriage::class, ['queue_first' => $renamedId])
        ->call('manualLabel', 'Albert Heijn', CounterpartyType::Merchant->value);

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->where('id', $renamedId)->firstOrFail();

    expect($stored->slug)->toBe('albert-heijn-2');
});

it('does not fragment the counterparty when the next import meets the new name', function (): void {
    $user = triageDecisionUser('triage-rename-reimport');
    $account = triageDecisionAccount($user, 'triage-reimport-asn');
    $renamedId = triageDecisionUnknown($user, 'bol', 'Bol');
    triageDecisionAlias($user, 'ALBERT HEIJN', 'albert heijn', 'Albert Heijn');

    Livewire::actingAs($user)->test(CounterpartyTriage::class)
        ->call('manualLabel', 'Albert Heijn', CounterpartyType::Merchant->value);

    /** @var CounterpartyResolver $resolver */
    $resolver = app(CounterpartyResolver::class);
    $resolved = $resolver->resolve(
        triageDecisionCanonical($user, $account, 'BETAALAUTOMAAT ALBERT HEIJN 1042 AMSTERDAM'),
        $user,
    );

    expect($resolved?->counterpartyId)->toBe($renamedId)
        ->and(Counterparty::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('tells the peer about a hand-labelled row, in plaintext', function (): void {
    $user = triageDecisionUser('triage-manual-announced');
    $unknownId = triageDecisionUnknown($user, 'bol', 'Bol');

    $ops = triageDecisionCounterpartyOps(static function () use ($user): void {
        Livewire::actingAs($user)->test(CounterpartyTriage::class)
            ->call('manualLabel', 'Albert Heijn', CounterpartyType::Merchant->value);
    });

    expect($ops)->toHaveCount(1)
        ->and($ops[0]->mutationType)->toBe('edit')
        ->and($ops[0]->pk)->toBe($unknownId)
        ->and($ops[0]->dirtyFields)->toBe([
            'type' => CounterpartyType::Merchant->value,
            'slug' => 'albert-heijn',
            'display_name' => 'Albert Heijn',
            'merchant_name' => 'Albert Heijn',
        ]);
});

it('tells the peer about an accepted suggestion, and re-slugs it too', function (): void {
    $user = triageDecisionUser('triage-accept-announced');
    $account = triageDecisionAccount($user, 'triage-accept-asn');
    $unknownId = triageDecisionUnknown($user, 'mystery-spotify', 'NL10BANK0000000608');
    triageDecisionAlias($user, 'SPOTIFY', 'spotify', 'Spotify');

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn_csv',
        'raw_file_path' => 'fixture://triage-decision',
        'sha256' => str_pad((string) random_int(1, 1_000_000_000), 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => now()->toDateTimeString(),
        'confirmed_at' => now()->toDateTimeString(),
        'inserted_count' => 0,
        'duplicate_count' => 0,
        'error_count' => 0,
        'status' => 'confirmed',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => now()->toDateString(),
        'booked_at' => now()->toDateTimeString(),
        'value_date' => now()->toDateString(),
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'counterparty_name' => null,
        'counterparty_iban' => null,
        'counterparty_normalized' => 'spotify',
        'normalization_version' => 1,
        'description' => 'SPOTIFY P AMSTERDAM',
        'category_id' => null,
        'source_format' => 'asn_csv',
        'import_run_id' => $runId,
        'source_row_index' => 1,
        'source_ref' => 'triage-accept:1',
        'fingerprint' => str_pad((string) random_int(1, 1_000_000_000), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'counterparty_id' => $unknownId,
        'payment_type' => 'unknown',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $ops = triageDecisionCounterpartyOps(static function () use ($user): void {
        Livewire::actingAs($user)->test(CounterpartyTriage::class)->call('acceptSuggestion');
    });

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->where('id', $unknownId)->firstOrFail();

    expect($stored->slug)->toBe('spotify')
        ->and($ops)->toHaveCount(1)
        ->and($ops[0]->mutationType)->toBe('edit')
        ->and($ops[0]->dirtyFields['slug'] ?? null)->toBe('spotify')
        ->and($ops[0]->dirtyFields['display_name'] ?? null)->toBe('Spotify');
});

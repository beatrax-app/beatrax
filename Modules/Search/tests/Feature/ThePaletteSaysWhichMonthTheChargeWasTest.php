<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Support\Fmt;
use Livewire\Livewire;
use Modules\Search\Public\Http\Livewire\PaletteSearchEndpoint;
use Modules\Search\Public\Services\SearchQuery;

// Typing "vattenfall" into the palette on the Samsung answered five rows:
//
//   Vattenfall Energie 2   -€18.00   Vattenfall Energie 2 · Factuurnr XXXX…
//   Vattenfall Energie     -€58.17   Vattenfall Energie · KENMERK 582759 …
//   Vattenfall Energie 2   -€18.00   Vattenfall Energie 2 · Factuurnr XXXX…
//   Vattenfall Energie     -€58.17   Vattenfall Energie · KENMERK 582759 …
//   Vattenfall Energie 3   -€14.24   W9P97G XXXX bestelling via Bezorg…
//
// Seven results, five shown, and three pairs of them identical on screen.
// They are different transactions — a bill charged every month has the same
// counterparty, the same amount and the same reference each time, and the one
// thing that tells them apart is the date the row did not carry. The list on
// /transactions has shown it all along.

beforeEach(function (): void {
    $this->user = User::find($this->searchTestUser('palette-date-user'));
    $this->actingAs($this->user);
});

it('carries the date that tells one month\'s charge from the next', function (): void {
    foreach (['2026-02-02', '2026-03-02', '2026-04-01'] as $day) {
        $this->searchTestTransaction($this->user->id, [
            'posted_at' => $day,
            'booked_at' => $day.' 00:00:00',
            'value_date' => $day,
            'counterparty_name' => 'Vattenfall Energie',
            'counterparty_normalized' => 'vattenfall energie',
            'amount_minor' => -5817,
            'settled_amount_minor' => -5817,
            'description' => 'KENMERK 582759 Pakketpremie Hybride particulier',
        ]);
    }

    /** @var SearchQuery $search */
    $search = $this->app->make(SearchQuery::class);

    $hits = $search->palette($this->user, 'Vattenfall');

    expect($hits)->toHaveCount(3);

    $dates = array_map(static fn (array $hit): string => (string) ($hit['date'] ?? ''), $hits);

    // Three charges, three different days, and no two rows that read alike.
    expect(array_unique($dates))->toHaveCount(3)
        ->and($dates)->toContain(Fmt::shortDate('2026-04-01'));
});

it('writes that date the way the rest of the app writes dates', function (): void {
    $this->searchTestTransaction($this->user->id, [
        'posted_at' => '2026-04-01',
        'booked_at' => '2026-04-01 00:00:00',
        'value_date' => '2026-04-01',
        'counterparty_name' => 'Vattenfall Energie',
        'counterparty_normalized' => 'vattenfall energie',
        'description' => 'KENMERK 582759',
    ]);

    /** @var SearchQuery $search */
    $search = $this->app->make(SearchQuery::class);

    $hits = $search->palette($this->user, 'Vattenfall');

    expect($hits[0]['date'] ?? null)->toBe(Fmt::shortDate('2026-04-01'));
});

// The modal does not read SearchQuery. PaletteSearchEndpoint re-shapes every
// hit into its own array first, and a key the query adds but the endpoint does
// not copy reaches nothing — which is exactly what shipped the first time.
it('carries the date all the way to the component the modal renders', function (): void {
    $this->searchTestTransaction($this->user->id, [
        'posted_at' => '2026-04-01',
        'booked_at' => '2026-04-01 00:00:00',
        'value_date' => '2026-04-01',
        'counterparty_name' => 'Vattenfall Energie',
        'counterparty_normalized' => 'vattenfall energie',
        'description' => 'KENMERK 582759',
    ]);

    $hits = Livewire::test(PaletteSearchEndpoint::class)
        ->call('search', 'Vattenfall')
        ->get('transactionHits');

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]['date'] ?? null)->toBe(Fmt::shortDate('2026-04-01'));
});

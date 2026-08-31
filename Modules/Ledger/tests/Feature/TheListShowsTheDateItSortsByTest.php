<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Public\Support\Fmt;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;

// An ICS card statement books a card row the day after it posts, so posted_at
// and booked_at disagree on every card row while every PayPal and bank row has
// them equal. The list sorts on posted_at, so a screen that prints booked_at
// prints a sequence that steps back up wherever the two sources interleave --
// which is what a real statement did on hardware.

/**
 * Every rendered phone-card date, in DOM order, across every page loaded so far.
 *
 * Read from the markup rather than the row DTO so the assertion survives the
 * field being renamed and still fails when the wrong column reaches the screen.
 *
 * @return list<string>
 */
function renderedCardDates(string $html): array
{
    preg_match_all(
        '/data-testid="tx-card-\d+".*?<p class="secondary[^"]*">(.*?)<\/p>/s',
        $html,
        $matches,
    );

    return array_map(
        static fn (string $cell): string => trim(strip_tags($cell)),
        $matches[1],
    );
}

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * The six rows read off the device, in ascending id order. `booked` one day
 * past `posted` is the ICS card shape; the two equal is every other source.
 *
 * @return list<array{name: string, posted: string, booked: string}>
 */
function statementAsReadOffTheDevice(): array
{
    return [
        ['name' => 'AH TO GO AMSTERDAM', 'posted' => '2026-05-04', 'booked' => '2026-05-04'],
        ['name' => 'KLM ROYAL DUTCH AIR', 'posted' => '2026-05-05', 'booked' => '2026-05-06'],
        ['name' => 'Google Cloud EMEA', 'posted' => '2026-05-05', 'booked' => '2026-05-05'],
        ['name' => 'Bankstorting', 'posted' => '2026-05-05', 'booked' => '2026-05-05'],
        ['name' => 'ZEEMAN ALPHEN', 'posted' => '2026-05-07', 'booked' => '2026-05-08'],
        ['name' => 'Adobe Systems Software', 'posted' => '2026-05-07', 'booked' => '2026-05-08'],
    ];
}

it('prints the card statement in descending date order', function (): void {
    foreach (statementAsReadOffTheDevice() as $row) {
        $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
            'posted_at' => $row['posted'],
            'booked_at' => $row['booked'].' 12:00:00',
            'counterparty_name' => $row['name'],
        ]);
    }

    $html = (string) Livewire::test(TransactionsList::class)
        ->set('currency', 'original')
        ->html();

    // posted_at descending, id descending -- the order TransactionCursor sorts
    // and pages on, so the order the reader must be able to read off the page.
    $expected = array_map(
        static fn (string $day): string => Fmt::shortDate(CarbonImmutable::parse($day)),
        ['2026-05-07', '2026-05-07', '2026-05-05', '2026-05-05', '2026-05-05', '2026-05-04'],
    );

    expect(renderedCardDates($html))->toBe($expected);
});

it('never steps forward in time on the way down the list', function (): void {
    foreach (statementAsReadOffTheDevice() as $row) {
        $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
            'posted_at' => $row['posted'],
            'booked_at' => $row['booked'].' 12:00:00',
            'counterparty_name' => $row['name'],
        ]);
    }

    $html = (string) Livewire::test(TransactionsList::class)
        ->set('currency', 'original')
        ->html();

    // Every distinct day the fixture can render, mapped back off its own
    // formatting, so monotonicity is judged on days and not on locale strings.
    $dayOf = [];
    foreach (['2026-05-04', '2026-05-05', '2026-05-06', '2026-05-07', '2026-05-08'] as $day) {
        $dayOf[Fmt::shortDate(CarbonImmutable::parse($day))] = $day;
    }

    $days = array_map(
        static fn (string $rendered): string => $dayOf[$rendered] ?? $rendered,
        renderedCardDates($html),
    );

    $sorted = $days;
    rsort($sorted);

    expect($days)->toBe($sorted);
});

it('names the same day on the row and on the transaction it opens', function (): void {
    $klm = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-06 12:00:00',
        'counterparty_name' => 'KLM ROYAL DUTCH AIR',
    ]);

    $listHtml = (string) Livewire::test(TransactionsList::class)
        ->set('currency', 'original')
        ->html();

    $detailHtml = (string) Livewire::test(TransactionDetail::class, ['transactionId' => $klm->id])
        ->html();

    $posted = CarbonImmutable::parse('2026-05-05');
    $booked = CarbonImmutable::parse('2026-05-06');

    expect(renderedCardDates($listHtml))->toBe([Fmt::shortDate($posted)])
        ->and($listHtml)->not->toContain(Fmt::shortDate($booked))
        ->and($detailHtml)->toContain($posted->translatedFormat('j M Y'));
});

it('walks every page without skipping a row or handing one back twice', function (): void {
    // Three rows a day so a 50-row page boundary lands inside a posted_at tie,
    // which is the only place a row-value cursor can drop or repeat a row.
    $expectedIds = [];
    for ($i = 0; $i < 130; $i++) {
        $posted = CarbonImmutable::parse('2026-04-01')->addDays(intdiv($i, 3));
        $booked = $i % 2 === 0 ? $posted : $posted->addDay();
        $expectedIds[] = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
            'posted_at' => $posted->toDateString(),
            'booked_at' => $booked->toDateString().' 12:00:00',
        ])->id;
    }

    $component = Livewire::test(TransactionsList::class)->set('currency', 'original');
    $component->call('loadMore');
    $component->call('loadMore');

    /** @var list<array{id: int}> $accumulated */
    $accumulated = $component->get('accumulatedRows');
    $ids = array_column($accumulated, 'id');

    sort($expectedIds);
    $seen = $ids;
    sort($seen);

    expect($ids)->toHaveCount(count(array_unique($ids)))
        ->and($seen)->toBe($expectedIds);
});

it('keeps the date descending across the page boundaries', function (): void {
    $days = [];
    for ($i = 0; $i < 130; $i++) {
        $posted = CarbonImmutable::parse('2026-04-01')->addDays(intdiv($i, 3));
        $booked = $i % 2 === 0 ? $posted : $posted->addDay();
        $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
            'posted_at' => $posted->toDateString(),
            'booked_at' => $booked->toDateString().' 12:00:00',
        ]);
        $days[Fmt::shortDate($posted)] = $posted->toDateString();
        $days[Fmt::shortDate($booked)] = $booked->toDateString();
    }

    $component = Livewire::test(TransactionsList::class)->set('currency', 'original');
    $component->call('loadMore');
    $component->call('loadMore');

    $rendered = array_map(
        static fn (string $cell): string => $days[$cell] ?? $cell,
        renderedCardDates((string) $component->html()),
    );

    $sorted = $rendered;
    rsort($sorted);

    expect($rendered)->toHaveCount(130)->and($rendered)->toBe($sorted);
});

it('says when the card booked the row a day after it posted', function (): void {
    $klm = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-06 12:00:00',
        'counterparty_name' => 'KLM ROYAL DUTCH AIR',
    ]);

    $html = (string) Livewire::test(TransactionDetail::class, ['transactionId' => $klm->id])->html();

    expect($html)->toContain(CarbonImmutable::parse('2026-05-06')->translatedFormat('j M Y'))
        ->and($html)->toContain('tx-detail-booked-at');
});

it('stays silent about the booking day when it is the day it posted', function (): void {
    $paypal = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'counterparty_name' => 'Google Cloud EMEA',
    ]);

    $html = (string) Livewire::test(TransactionDetail::class, ['transactionId' => $paypal->id])->html();

    expect($html)->not->toContain('tx-detail-booked-at');
});

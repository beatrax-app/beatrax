<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;

// subMonth() off a 29th, 30th or 31st that the shorter month does not have
// rolls FORWARD into the month it started in, and the startOfMonth() after it
// cannot undo that. Seven days a year "Last month" set the range to THIS month
// — a button that reports success and narrows nothing.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return list<array{label: string, after: string, before: string}> every preset button on the page, in document order
 */
function datePresetButtons(string $today): array
{
    CarbonImmutable::setTestNow($today.' 12:00:00');

    $html = (string) Livewire::test(TransactionsList::class)->set('currency', 'eur_only')->html();

    $matches = PatternScan::sets(
        '/\$set\(\'filterAfter\', \'(?<after>[\d-]+)\'\).*?\$set\(\'filterBefore\', \'(?<before>[\d-]+)\'\).*?>(?<label>[^<]+)<\/button>/s',
        $html,
    );

    return array_map(
        static fn (array $m): array => ['label' => $m['label'], 'after' => $m['after'], 'before' => $m['before']],
        $matches,
    );
}

/**
 * @return array{string, string}
 */
function lastMonthPresetBounds(string $today): array
{
    foreach (datePresetButtons($today) as $button) {
        if ($button['label'] === 'Last month') {
            return [$button['after'], $button['before']];
        }
    }

    return ['no last-month preset was drawn', ''];
}

it('selects February when the reader opens the list on 31 March', function (): void {
    expect(lastMonthPresetBounds('2026-03-31'))->toBe(['2026-02-01', '2026-02-28']);
});

it('selects the month before, whatever day of the month the list is opened on', function (): void {
    $bounds = [];
    foreach (['2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31', '2026-05-31', '2026-07-31'] as $today) {
        $bounds[$today] = lastMonthPresetBounds($today);
    }

    expect($bounds)->toBe([
        '2026-03-28' => ['2026-02-01', '2026-02-28'],
        '2026-03-29' => ['2026-02-01', '2026-02-28'],
        '2026-03-30' => ['2026-02-01', '2026-02-28'],
        '2026-03-31' => ['2026-02-01', '2026-02-28'],
        '2026-05-31' => ['2026-04-01', '2026-04-30'],
        '2026-07-31' => ['2026-06-01', '2026-06-30'],
    ]);
});

it('never hands "Last month" and "This month" the same range', function (): void {
    foreach (['2026-03-29', '2026-03-30', '2026-03-31', '2026-08-31'] as $today) {
        $ranges = [];
        foreach (datePresetButtons($today) as $button) {
            $ranges[$button['label']][] = $button['after'].'..'.$button['before'];
        }

        expect($ranges['This month'][0])->not->toBe($ranges['Last month'][0], 'on '.$today);
    }
});

// Two surfaces draw the four presets — the desktop popover and the phone
// bottom sheet — and a rule spelled out twice is a rule that can disagree with
// itself on one surface only.
it('draws both surfaces of every preset on one range', function (): void {
    $buttons = datePresetButtons('2026-03-31');

    $ranges = [];
    foreach ($buttons as $button) {
        $ranges[$button['label']][] = $button['after'].'..'.$button['before'];
    }

    expect($buttons)->toHaveCount(8);
    foreach ($ranges as $label => $drawn) {
        expect($drawn)->toHaveCount(2, $label)
            ->and($drawn[0])->toBe($drawn[1], $label);
    }
});

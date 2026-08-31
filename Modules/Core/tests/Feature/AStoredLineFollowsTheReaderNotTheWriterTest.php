<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\StoredCopy;

// The defect this seam exists for is invisible to an English test: a reader in
// English sees the right sentence whether it was resolved at write time or at
// read time. So every assertion below renders the SAME stored value twice, once
// per locale, and requires the two to differ — a locale whose line is missing
// falls back to English on both sides and would otherwise pass.

function storedLineIn(string $locale, callable $render): string
{
    /** @var Translator $translator */
    $translator = app(Translator::class);
    $was = $translator->getLocale();
    $translator->setLocale($locale);

    try {
        return $render();
    } finally {
        $translator->setLocale($was);
    }
}

it('renders a row written in one language for a reader in another', function (): void {
    // Written the way a pot archive writes it: the process that stores the row
    // is running in English, and nothing about the write knows who will read it.
    $stored = storedLineIn('en', static fn (): string => StoredCopy::of(
        CopyLine::of('pots::messages.movement.released_on_archive'),
    ));

    $english = storedLineIn('en', static fn (): string => StoredCopy::read($stored));
    $dutch = storedLineIn('nl', static fn (): string => StoredCopy::read($stored));

    expect($english)->toBe('Released on archive');
    expect($dutch)->toBe(storedLineIn('nl', static fn (): string => Lang::get('pots::messages.movement.released_on_archive')));
    expect($dutch)->not->toBe($english, implode("\n", [
        'The stored memo rendered the same words for a Dutch reader as for an English one.',
        'Either the line was resolved at write time and froze, or nl has no line for this',
        'key and both sides fell back to English — which is the trap this assertion is',
        'shaped to catch, and why it compares the two renderings instead of naming one.',
    ]));
});

it('leaves the reader their own words untouched', function (): void {
    // The same column holds a memo the reader typed, and every row written
    // before the seam existed. Both have to come back exactly as found.
    foreach (['Weekly shop at Albert Heijn', 'Released on archive', ''] as $ownWords) {
        expect(storedLineIn('nl', static fn (): string => StoredCopy::read($ownWords)))->toBe($ownWords);
    }

    expect(StoredCopy::keyOf('Weekly shop at Albert Heijn'))->toBeNull();
});

it('names the line it stored without rendering it', function (): void {
    $stored = StoredCopy::of(CopyLine::of('pots::messages.movement.released_on_archive'));

    expect(StoredCopy::keyOf($stored))->toBe('pots::messages.movement.released_on_archive');
    expect(StoredCopy::names($stored, 'pots::messages.movement.released_on_archive'))->toBeTrue();
    expect(StoredCopy::names($stored, 'pots::messages.movement.unreadable'))->toBeFalse();
    expect(StoredCopy::names(null, 'pots::messages.movement.released_on_archive'))->toBeFalse();
});

it('lets the reader locale pick the plural arm, not the writer locale', function (): void {
    $stored = static fn (int $n): string => storedLineIn(
        'en',
        static fn (): string => StoredCopy::of(CopyLine::plural('community::settings.mappings', $n)),
    );

    $english = array_map(
        static fn (int $n): string => storedLineIn('en', static fn (): string => StoredCopy::read($stored($n))),
        [0, 1, 2],
    );

    expect($english)->toBe(['0 mappings', '1 mapping', '2 mappings']);

    // Polish selects between three forms where English selects between two, and
    // it picks the third from the FINAL digit rather than the magnitude. A form
    // chosen by the writer could never have reached the third arm at all.
    $polish = array_map(
        static fn (int $n): string => storedLineIn('pl', static fn (): string => StoredCopy::read($stored($n))),
        [1, 2, 5],
    );

    expect(array_unique($polish))->toHaveCount(3, implode("\n", [
        'Polish rendered fewer than three distinct forms for 1, 2 and 5.',
        'Either the arm is being chosen once at write time, or pl is short an arm.',
        'The rendered forms were: '.implode(' | ', $polish),
    ]));

    foreach ($polish as $index => $line) {
        expect($line)->not->toBe($english[$index === 0 ? 1 : 2]);
    }
});

it('re-renders a date and an amount for the reader too', function (): void {
    // A date and an amount are not the same string in every language, so
    // neither may be formatted on the way in. This is the label the migration
    // preview stores for a transaction it could not carry across.
    $stored = storedLineIn('en', static fn (): string => StoredCopy::of(
        CopyLine::of('migration::unmapped.label.transaction', [
            'name' => 'Albert Heijn',
            'date' => CopyParam::dateWithYear(CarbonImmutable::parse('2026-03-04')),
            'amount' => CopyParam::money(-3000, 'EUR'),
        ]),
    ));

    $english = storedLineIn('en', static fn (): string => StoredCopy::read($stored));
    $dutch = storedLineIn('nl', static fn (): string => StoredCopy::read($stored));

    expect($english)->toContain('Albert Heijn')->toContain('Mar')->toContain('30');
    expect($dutch)->toContain('Albert Heijn');
    expect($dutch)->not->toBe($english, implode("\n", [
        'The stored transaction label read identically in both languages.',
        'The month name, the amount and the label around them all follow the reader,',
        'so an identical rendering means one of the three was frozen on the way in.',
    ]));
});

it('resolves a spec riding beside the sentence rather than inside it', function (): void {
    // The shape a SYNCED column needs: the sentence stays where an older build
    // has always looked, and the spec rides in the JSON column next to it. A
    // peer that has never heard of this seam renders the sentence and is right.
    $line = CopyLine::of('pots::messages.movement.released_on_archive');
    $params = StoredCopy::inParams($line);
    $written = storedLineIn('en', static fn (): string => $line->sentence());

    expect($written)->toBe('Released on archive');

    $dutch = storedLineIn('nl', static fn (): string => StoredCopy::readFromParams($params, $written));

    expect($dutch)->not->toBe($written, implode("\n", [
        'The spec beside the sentence rendered the same words for a Dutch reader.',
        'Either readFromParams() fell through to the written column when it should',
        'have resolved, or nl has no line for this key and both sides fell back.',
    ]));
});

it('hands back the written sentence when no spec rides beside it', function (): void {
    foreach ([null, [], ['copy' => null], ['copy' => 'not-a-spec'], 'not-an-array'] as $params) {
        expect(StoredCopy::readFromParams($params, 'Released on archive'))->toBe('Released on archive');
    }
});

it('falls back to the sentence it was written with when the key is gone', function (): void {
    $stored = StoredCopy::of(CopyLine::of('pots::messages.movement.released_on_archive'));

    // A key renamed in a later release leaves rows behind that name it. The
    // reader is handed the stale sentence, never the raw key.
    $renamed = str_replace('released_on_archive', 'released_on_archive_v2', $stored);

    expect(StoredCopy::read($renamed))->toBe('Released on archive');
});

<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Support\LocaleCollator;

// Every picker and every budget list is sorted on the name the reader sees.
// strcasecmp compares bytes, so it files every accented name after Z: the
// numbers below are the ones the six call sites used to return.

it('files an accented name where the reader expects it, not after Z', function (): void {
    app()->make(Translator::class)->setLocale('nl');

    expect(LocaleCollator::compare('Éclair', 'Zeep'))->toBeLessThan(0)
        ->and(strcasecmp('Éclair', 'Zeep'))->toBeGreaterThan(0);
});

it('sorts a Polish name by the Polish alphabet', function (): void {
    app()->make(Translator::class)->setLocale('pl');

    expect(LocaleCollator::compare('Łąka', 'Zebra'))->toBeLessThan(0)
        ->and(strcasecmp('Łąka', 'Zebra'))->toBeGreaterThan(0);
});

// Ä is an A with a diaeresis to a German reader and a letter of its own, after
// Z, to a Swedish one. One memoised collator shared across locales would answer
// whichever reader asked first — and the phone, which has no collator at all,
// has to reach the same two answers through its own alphabet tables.
it('answers each reader in their own alphabet', function (): void {
    app()->make(Translator::class)->setLocale('de');
    $german = LocaleCollator::compare('Äpfel', 'Zebra');
    $germanPhone = LocaleCollator::compareWithoutIcu('Äpfel', 'Zebra');

    app()->make(Translator::class)->setLocale('sv');
    $swedish = LocaleCollator::compare('Äpfel', 'Zebra');
    $swedishPhone = LocaleCollator::compareWithoutIcu('Äpfel', 'Zebra');

    expect($german)->toBeLessThan(0)
        ->and($germanPhone)->toBeLessThan(0)
        ->and($swedish)->toBeGreaterThan(0)
        ->and($swedishPhone)->toBeGreaterThan(0);
});

// Every call site spells the tiebreak `compare(...) ?: $a->id <=> $b->id`, so
// equal names have to answer a falsy 0 for the id half to be reached at all.
it('answers zero for two equal names so the call site tiebreak still runs', function (): void {
    app()->make(Translator::class)->setLocale('nl');

    expect(LocaleCollator::compare('Groceries', 'Groceries'))->toBe(0)
        ->and(LocaleCollator::compare('Groceries', 'Groceries') ?: 7 <=> 3)->toBe(1);
});

it('orders case-insensitively, the way the byte comparators it replaces did', function (): void {
    app()->make(Translator::class)->setLocale('en');

    expect(LocaleCollator::compare('apple', 'Banana'))->toBeLessThan(0)
        ->and(LocaleCollator::compare('Banana', 'apple'))->toBeGreaterThan(0);
});

// Two of the five comparators this replaced used strnatcasecmp, so digits
// already read as numbers there. Collating without that would have quietly
// moved "Trip 10" above "Trip 2" on the budget list and the cash book.
it('reads a run of digits as a number, the way strnatcasecmp did', function (): void {
    app()->make(Translator::class)->setLocale('nl');

    expect(LocaleCollator::compare('Trip 2', 'Trip 10'))->toBeLessThan(0)
        ->and(LocaleCollator::compare('Trip 2', 'Trip 10'))->toBe(strnatcasecmp('Trip 2', 'Trip 10'));
});

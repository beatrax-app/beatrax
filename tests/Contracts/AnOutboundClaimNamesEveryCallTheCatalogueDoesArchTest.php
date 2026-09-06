<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Support\PatternScan;

/** @link ../../.docs/runbooks/store-submission.md */

// "Where is my data?" is the screen that tells a reader what leaves the device,
// and the README tells the same reader the same thing before they install. An
// enumeration is only worth reading if it is complete: this sentence named four
// of the catalogue's seven calls, and the one it left out was the only one that
// fires with every optional feature switched off.

/**
 * The reader-facing words that stand for each catalogue row. A row with no
 * entry is a call nobody has decided how to say, which is the state the
 * sentence must not ship in.
 *
 * @return array<string, list<string>>
 */
function outboundReaderWords(): array
{
    return [
        'Update check' => ['new version'],
        'Mail provider API' => ['mailbox'],
        'Exchange-rate fetch' => ['exchange-rate'],
        'Open-banking aggregator' => ['Enable Banking'],
        'Sync peers' => ['pair for sync'],
        'Sync relay' => ['relay'],
        'External-link opening' => ['link you click'],
    ];
}

/** @return list<string> every call named in the runbook's outbound-call catalogue, in table order */
function outboundCatalogueRows(): array
{
    $page = (string) file_get_contents(base_path('.docs/runbooks/store-submission.md'));

    $section = PatternScan::first('/^## The outbound-call catalogue$(.*?)^## /ms', $page);

    expect($section)->not->toBeEmpty('the outbound-call catalogue section is gone from the runbook');

    $rows = [];

    foreach (PatternScan::sets('/^\| ([^|]+) \| ([^|]+) \| ([^|]+) \| ([^|]+) \|$/m', $section[1]) as $set) {
        $call = trim($set[1]);

        if ($call !== 'Call') {
            $rows[] = $call;
        }
    }

    return $rows;
}

function outboundReaderSentence(): string
{
    /** @var Translator $translator */
    $translator = app(Translator::class);

    /** @var string $intro */
    $intro = $translator->get('core::help.intro', [], 'en', false);

    return $intro;
}

it('reads a catalogue with rows in it', function (): void {
    expect(outboundCatalogueRows())->toHaveCount(7);
});

it('has a reader word for every call the catalogue carries, and carries every call it has a word for', function (): void {
    expect(outboundCatalogueRows())->toBe(array_keys(outboundReaderWords()));
});

it('names every outbound call on the screen that tells a reader what leaves the device', function (): void {
    $sentence = outboundReaderSentence();

    $unnamed = [];

    foreach (outboundReaderWords() as $call => $words) {
        $named = false;

        foreach ($words as $word) {
            $named = $named || str_contains($sentence, $word);
        }

        if (! $named) {
            $unnamed[] = $call.' (looked for: '.implode(', ', $words).')';
        }
    }

    expect($unnamed)->toBe([], "core::help.intro does not name:\n  ".implode("\n  ", $unnamed));
});

it('tells a reader before they install what it tells them after', function (): void {
    $readme = PatternScan::replace('/\s+/', ' ', (string) file_get_contents(base_path('README.md')));

    $sentence = PatternScan::replace('/\s+/', ' ', outboundReaderSentence());

    // The screen opens by saying where the data lives, which the README has
    // already said in its own words. What both carry word for word is the
    // enumeration, and it starts at the one call that needs no invitation.
    $enumeration = PatternScan::first('/(One call goes out on its own.*)$/', $sentence);

    expect($enumeration)->not->toBeEmpty('core::help.intro no longer opens its enumeration where this guard reads it')
        ->and($readme)->toContain($enumeration[1]);
});

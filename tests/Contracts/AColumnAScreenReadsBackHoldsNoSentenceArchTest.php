<?php

declare(strict_types=1);

use Tests\Contracts\Support\BackendSourceFiles;
use Tests\Contracts\Support\StoredSentenceShape;

// A row is written once and read for as long as it is kept, and the reader is
// often not the person — or the language — that wrote it. English put into a
// column freezes there: the migration preview stored "Goal: Holiday" and
// "1 budget rows were not imported", a pot stored "Released on archive", and a
// savings prompt stored a whole sentence a queue worker had already resolved.
// Every one of them was invisible to the translation guards, which read lang
// files and call sites and cannot see a string that reaches the screen as data.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#english-written-into-a-column-a-screen-reads-back

// The write targets this rule is about: a column, or a constructor argument on
// its way to one, whose value a screen prints. A machine word — an enum value,
// a slug, a key — is not what is being looked for and is not matched; a
// sentence is. Widening this list is how the rule reaches a new column.
const STORED_SENTENCE_KEYS = [
    'display_label', 'displayLabel',
    'reason',
    'memo',
    'message',
    'body',
    'title',
    'label',
    'note', 'notes',
    'summary',
    'caption',
    'heading',
    'subtitle',
];

// Each entry names a file whose value under one of those keys never reaches a
// screen, and why. The `proves` pattern re-checks the reason on every run: when
// it stops matching, the exemption has outlived what earned it and this fails
// rather than waving it on.
const STORED_SENTENCE_PINS = [
    'Modules/Core/Internal/Console/BackupDatabaseCommand.php' => [
        'reason' => 'operator diagnostics inside the alert metadata array, keyed beside the phase that failed — read by whoever is debugging a backup, never rendered as the alert sentence',
        'proves' => "/'phase' => 'vacuum_into'/",
    ],
    'Modules/Import/Database/Seeders/DefaultKnownCounterpartyIbansSeeder.php' => [
        'reason' => 'the registered legal name of the institution behind the IBAN — a proper noun that reads the same in every language. The value becomes a counterparty display name, which the Counterparties seam already treats as the entity\'s own words, and CounterpartySlugResolver derives the identity slug from it: a name that changed with the reader would mint a second counterparty per language',
        'proves' => "/'notes' => 'International Card Services BV — ABN AMRO'/u",
    ],
];

/** @return array<string, array<int, string>> relative path => line => the offending write */
function storedSentenceWrites(): array
{
    static $writes = null;

    if ($writes !== null) {
        return $writes;
    }

    $writes = [];

    foreach (BackendSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $source = (string) file_get_contents($path);

        if (! StoredSentenceShape::isPayloadFile($relative) || ! StoredSentenceShape::writesToATable($source)) {
            continue;
        }

        $found = StoredSentenceShape::sentencesUnderKeys(BackendSourceFiles::codeTokens($path), STORED_SENTENCE_KEYS);
        storedSentenceKeysRead(StoredSentenceShape::$keysSeen);

        if ($found !== []) {
            $writes[$relative] = $found;
        }
    }

    ksort($writes);

    return $writes;
}

/** Adds to, and reads back, how many payload keys the whole walk has looked at. */
function storedSentenceKeysRead(?int $add = null): int
{
    static $total = 0;

    if ($add !== null) {
        $total += $add;
    }

    return $total;
}

it('never writes a sentence of its own into a column a screen reads back', function (): void {
    $offenders = [];

    foreach (storedSentenceWrites() as $relative => $lines) {
        if (array_key_exists($relative, STORED_SENTENCE_PINS)) {
            continue;
        }

        foreach ($lines as $line => $write) {
            $offenders[] = $relative.':'.$line.' — '.$write;
        }
    }

    // Dozens of payload keys stand under these names on this tree. A run that
    // reads none of them found nothing because it stopped, not because it is clean.
    expect(storedSentenceKeysRead())->toBeGreaterThan(40, 'No payload key was read, so this rule checked nothing.');

    expect($offenders)->toBe([], implode("\n  ", [
        'These write English of their own into a value a screen prints back:',
        ...$offenders,
        '',
        'The string freezes in the language of whoever ran the write. A migration',
        'preview staged on a machine whose queue ran in English read back English to',
        'a Dutch reader; a notification written by an hourly job read back the app',
        'default for the whole retention window. No lang-file test can see it,',
        'because there is no lang file — the sentence reaches the screen as data.',
        '',
        'Store the KEY and the values, not the sentence:',
        '  StoredCopy::of(CopyLine::of($key, [...]))     into the column',
        '  StoredCopy::read($stored)                     on the way out',
        'A count rides as CopyLine::plural($key, $n) so the reader locale picks the',
        'arm rather than the writer. A date, an amount, a nested line and a category',
        'name ride as CopyParam, because each of those renders differently per reader',
        'too. The user\'s own words — a merchant, a memo they typed — are the same',
        'text in every language and ride verbatim; StoredCopy::read() hands anything',
        'that is not a spec straight back, so a legacy row keeps rendering.',
        '',
        'If the value genuinely never reaches a screen, pin the file below with a',
        'reason and a `proves` pattern that re-checks it. A pin is a claim under',
        'review, not a waiver.',
    ]));
});

it('still holds each pinned exemption to the reason it was granted for', function (): void {
    foreach (STORED_SENTENCE_PINS as $relative => $pin) {
        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toMatch($pin['proves'], $relative.' no longer reads as "'.$pin['reason'].'"');
    }
});

// A pin nothing reaches any more is a claim about the tree that stopped being
// true, and it would otherwise sit here forever.
it('keeps no pin the walk no longer reaches', function (): void {
    $reached = array_values(array_intersect(array_keys(STORED_SENTENCE_PINS), array_keys(storedSentenceWrites())));

    expect($reached)->toBe(array_keys(STORED_SENTENCE_PINS), implode("\n  ", [
        'A pinned file that no longer writes a sentence under one of these keys has',
        'outlived its exemption. Delete the pin rather than leave a claim standing',
        'that the next reader will trust.',
    ]));
});

// The seam has to be reachable from every module, or a caller writes its own
// sentence because the alternative was an import it could not make.
it('keeps the stored-copy seam on the kernel every module already depends on', function (): void {
    expect(is_file(base_path('Modules/Core/Public/Support/StoredCopy.php')))->toBeTrue();
    expect(is_file(base_path('Modules/Core/Public/Support/CopyLine.php')))->toBeTrue();
    expect(is_file(base_path('Modules/Core/Public/Support/CopyParam.php')))->toBeTrue();

    $storedCopy = (string) file_get_contents(base_path('Modules/Core/Public/Support/StoredCopy.php'));

    expect($storedCopy)->toContain('CopyLine::fromArray');
    expect(str_contains($storedCopy, 'self::isSpec($stored)'))->toBeTrue(implode("\n  ", [
        'StoredCopy::read() has to hand a value that is not a spec straight back.',
        'Every column it guards also holds the user\'s own words — a memo they typed,',
        'a merchant they named — and every one of them holds rows written before this',
        'seam existed. Rendering those through the translator would print a key.',
    ]));
});

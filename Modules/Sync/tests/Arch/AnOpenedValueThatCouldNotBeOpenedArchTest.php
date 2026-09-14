<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Tests\Support\SensitiveColumnScan;

// The sibling of ADecryptedValueLandsOnlyWhereItMayArchTest. That one asks
// whether the destination is sealed or disclosed -- a question about protection
// class. This one asks what happens when the read itself came back empty.

// SensitiveColumnCodec answers '' -- not the stored bytes -- for a value shaped
// like ciphertext that no epoch here opened, so a screen renders nothing rather
// than base64. Written or announced, that '' reads as "the value is empty" and
// overwrites what it stood for. Four defects, one per line it was not checked.
const A_DECRYPT_WHOSE_VALUE_REACHES_A_SINK = [
    'Modules/CashBook/Internal/Http/Livewire/CashBookPage.php' => 'opened onto a paginated row for the page to render. The only announcement it makes is a delete, whose dirtyFields are empty.',
    'Modules/Categorization/Internal/Listeners/MerchantMemoryWriter.php' => 'guards on the `decrypted` flag before it heals a sealed name into merchants.name, and returns without writing when the value did not open.',
    'Modules/Categorization/Internal/Services/RuleApplier.php' => 'two of them. The tax note goes through UnopenedValue, because updateExisting() rewrites note, category and year together. The transaction note is read back out of the row this same pass just wrote under the current epoch, so it opens by construction.',
    'Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php' => 'the opened IBAN is an in-memory lookup key and never reaches a column; cardAccountNamedBy() refuses an empty one on its first line, so a blank finds no account and mints no link.',
    'Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php' => 'opened to parse the event row and to match an alias. What reaches chain_links.evidence is the funding ACCOUNT IBAN, and the link is refused unless accountIdForIban() resolves one.',
    'Modules/Counterparties/Internal/Actions/LabelCounterparty.php' => 'UnopenedValue: the name is announced only when it was read, because a peer that CAN read it would apply the blank over its own good copy.',
    'Modules/Counterparties/Internal/Actions/MergeCounterparties.php' => 'opened only to compare the survivor s name against the merged one. What is written is the merged name the caller supplied, never the opened value.',
    'Modules/Counterparties/Database/Seeders/Demo/DemoCounterpartiesSeeder.php' => 'reopens rows it sealed itself moments earlier, so it holds the key by construction and a blank cannot arise. Nothing it opens is written back either -- the opened values rebuild a CanonicalTransaction the resolver reads.',
    'Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php' => 'decryptRow, so a blank arrives named -- DecryptedRow::isUnreadable() carries it. refreshStored() is the only place an opened value is written back, and a blank cannot reach it: resolveUnique() compares the opened stored name first, a blank fails that comparison, the slug is suffixed and firstOrCreate() mints a rival row instead. Here the blank costs a duplicate counterparty rather than an overwrite, which CounterpartySlugResolver records at slugIsFreeFor().',
    'Modules/Ledger/Internal/Http/Livewire/Concerns/ManagesSplitEditor.php' => 'the loader. It puts the opened note in the editor, where a blank looks like an empty box; SaveTransactionSplit decides what survives the post back, so the bound is at the writer and holds for every caller.',
    'Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php' => 'opened for the page it renders. Its note field posts a reader-initiated `set`, which is a decision about what an empty box should mean rather than a correctness bound, and is recorded as an open question rather than answered here.',
    'Modules/Ledger/Internal/Services/StripAsnDescriptionDelimiters.php' => 'skips the row where a non-empty stored value opened to empty, so it never writes the unwrap of a blank back over a sealed description.',
    'Modules/Ledger/Public/Actions/SaveTransactionSplit.php' => 'UnopenedValue: where nothing was typed and the stored bytes did not open, the bytes stay and the dirty diff sees no change.',
    'Modules/Ledger/Public/Actions/SetTransactionNote.php' => 'UnopenedValue: an append whose stored note did not open targets what is already there, so the no-change return answers it.',
    'Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php' => 'the opened IBAN becomes a grouping key, not a stored value: CounterpartyKey answers its NONE sentinel for an empty one and the caller turns that into null.',
];

const AN_OPENING_CALL = '/->\s*(?:decryptValue|decryptRow)\s*\(/';

// A write, or an announcement a peer applies. Both are sinks: OpLogWriter seals
// a sensitive column on the way out, so an announced blank lands on the peer as
// surely as a written one lands here.
const A_SINK_FOR_AN_OPENED_VALUE = '/->\s*\w*(?i:insert|update|upsert|save|create|fill)\w*\s*\(|new\s+\w*Mutated\s*\(|dirtyFields\s*:/';

/**
 * @return list<string>
 */
function filesOpeningIntoASink(): array
{
    $found = [];

    foreach (SensitiveColumnScan::productionFiles(base_path().'/') as $absolute => $relative) {
        $source = (string) file_get_contents($absolute);

        if (PatternScan::matches(AN_OPENING_CALL, $source) && PatternScan::matches(A_SINK_FOR_AN_OPENED_VALUE, $source)) {
            $found[] = $relative;
        }
    }

    sort($found);

    return $found;
}

// Both halves have to find something, and the map has to be read, or every
// verdict below is about nothing.
it('has a denominator to read a verdict from', function (): void {
    $files = filesOpeningIntoASink();

    expect(count(SensitiveColumnScan::productionFiles(base_path().'/')))->toBeGreaterThan(500)
        ->and(count($files))->toBeGreaterThan(5, 'no file read as both opening a sealed value and reaching a sink, which is not true of this tree')
        ->and($files)->toContain('Modules/Ledger/Public/Actions/SetTransactionNote.php')
        ->and(count(A_DECRYPT_WHOSE_VALUE_REACHES_A_SINK))->toBeGreaterThan(5);
});

// Every file, including the ones that already call UnopenedValue. Naming the
// class is not the same as saying what happens to the value: RuleApplier opens
// two, routes one through it, and the other is safe for an unrelated reason.
it('says what becomes of every opened value that reaches a sink', function (): void {
    $unclassified = array_values(array_diff(filesOpeningIntoASink(), array_keys(A_DECRYPT_WHOSE_VALUE_REACHES_A_SINK)));

    expect($unclassified)->toBe([], implode("\n", [
        'These open a sealed value and reach a write or an announcement, and nothing',
        'here says what happens when the value comes back empty:',
        ...$unclassified,
        '',
        'The codec answers empty for ciphertext no epoch on this device opened, and that',
        'is not the same answer as "the value is empty". Decide which this is, guard it',
        'with UnopenedValue::wasBlanked() where it would overwrite, and write the verdict',
        'down here either way.',
    ]));
});

it('keeps no verdict for a file that no longer does both', function (): void {
    $files = filesOpeningIntoASink();

    $stale = array_values(array_filter(
        array_keys(A_DECRYPT_WHOSE_VALUE_REACHES_A_SINK),
        static fn (string $path): bool => ! in_array($path, $files, true),
    ));

    expect($stale)->toBe([], implode("\n", [
        'These carry a verdict about an opened value they no longer sink, or they are',
        'gone. A verdict nobody can check excuses nothing. Remove it:',
        ...$stale,
    ]));
});

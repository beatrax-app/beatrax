<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Tests\Support\SensitiveColumnScan;

// The other direction from SensitiveColumnPredicateGuardTest. That one reads
// plaintext landing IN a sealed column and is keyed on the sealed column's
// NAME, so a write to a column called `metadata` is invisible to it.

// Protection class is applied per column at the write: encryptAttrs() seals
// what it is handed and nothing else. A value opened out of a sealed column and
// written to a different one therefore changes protection silently, and
// PlaintextResidueSweep cannot see it either -- its own comment says so, since
// an unsealed destination is outside the projection it sweeps.

// A plaintext shadow of an encrypted column is allowed only where the registry
// discloses it. Every file below both opens a sealed value and writes to the
// database; each says where the opened value lands. A fold recorded an absorbed
// counterparty's display_name in counterparties.metadata, which is disclosed
// nowhere, and the row it was sealed on is deleted by the same fold.
const A_DECRYPT_THAT_ALSO_WRITES = [
    'Modules/Categorization/Internal/Listeners/MerchantMemoryWriter.php' => 'transactions.counterparty_name, opened and written to merchants.name -- which the registry discloses as plaintext, because it is joined to the keyed normalized_name and sealing it would leave the readable copy reachable through that join.',
    'Modules/Categorization/Internal/Services/RuleApplier.php' => 'transactions.note and tax_transaction_tags.note, opened to compare and to compose. The only write is hits_count.',
    'Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php' => 'transactions.counterparty_iban, opened as an in-memory lookup key for the card account. card_statement_credits carries statement ids, an amount and a reason, and no name.',
    'Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php' => 'transactions.raw_payload and transactions.counterparty_iban, opened to parse the event and to match an alias. What reaches chain_links.evidence is the funding ACCOUNT IBAN -- the link is refused unless accountIdForIban() resolves one -- and accounts.iban is disclosed plaintext, too narrow to hold a nonce and ciphertext. The counterparty IBAN is deliberately kept out, which the arm says at the write.',
    'Modules/Counterparties/Internal/Actions/LabelCounterparty.php' => 'counterparties.display_name, opened only to announce it under its own sealed column name, which OpLogWriter re-seals under the current epoch. The row write carries metadata.ignored alone.',
    'Modules/Counterparties/Internal/Actions/MergeCounterparties.php' => 'counterparties.display_name, opened to compare names. The fold records what it absorbed by slug, which the registry discloses and which is opaque for a name that spells an account number, and never by the name itself.',
    'Modules/Ledger/Internal/Http/Livewire/Concerns/ManagesSplitEditor.php' => 'transaction_splits.note, opened into the editor the reader types in. SaveTransactionSplit re-seals it on the way back.',
    'Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php' => 'transactions.note, description and counterparty_name, opened for the page the reader is reading. The writes carry pair_transaction_id and a note the codec sealed.',
    'Modules/Ledger/Internal/Services/StripAsnDescriptionDelimiters.php' => 'transactions.description, opened to unwrap the delimiters and written back to the same column through encryptValue.',
    'Modules/Ledger/Public/Actions/SaveTransactionSplit.php' => 'transaction_splits.note, opened to compare against what the reader typed, written back sealed beside a category_id.',
    'Modules/Ledger/Public/Actions/SetTransactionNote.php' => 'transactions.note, opened to compare against what the reader typed, written back through encryptValue.',
    'Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php' => 'transactions.counterparty_iban, opened before it becomes a grouping key. What reaches recurring_series is the blind index CounterpartyKey::forIban derives, beside detected_name, which the registry discloses.',
];

const A_DECRYPT_CALL = '/->\s*(?:decryptValue|decryptAttrs)\s*\(/';

// Word parts, not a closed list of spellings, for the reason SensitiveColumnScan
// gives: the next write helper to be named `saveChunked()` is not knowable.
const A_DATABASE_WRITE = '/->\s*\w*(?i:insert|update|upsert|save|create|fill)\w*\s*\(/';

/**
 * @return list<string>
 */
function decryptingWriters(): array
{
    $found = [];

    foreach (SensitiveColumnScan::productionFiles(base_path().'/') as $absolute => $relative) {
        $source = (string) file_get_contents($absolute);

        if (PatternScan::matches(A_DECRYPT_CALL, $source) && PatternScan::matches(A_DATABASE_WRITE, $source)) {
            $found[] = $relative;
        }
    }

    sort($found);

    return $found;
}

// Both halves have to find something, or every verdict below is about nothing:
// a walk that read no file, and a pattern pair that matched none of them, are
// the two ways this passes while checking nothing.
it('has a denominator to read a verdict from', function (): void {
    $files = SensitiveColumnScan::productionFiles(base_path().'/');
    $writers = decryptingWriters();

    expect(count($files))->toBeGreaterThan(500, 'the production walk found almost nothing, so the scan below is about nothing')
        ->and(count($writers))->toBeGreaterThan(5, 'no file read as both opening a sealed value and writing, which is not true of this tree')
        ->and($writers)->toContain('Modules/Counterparties/Internal/Actions/MergeCounterparties.php');
});

it('classifies every file that opens a sealed value and then writes', function (): void {
    $unclassified = array_values(array_diff(decryptingWriters(), array_keys(A_DECRYPT_THAT_ALSO_WRITES)));

    expect($unclassified)->toBe([], implode("\n", [
        'These files open a sealed value and write to the database, and nothing here',
        'says where the opened value lands:',
        ...$unclassified,
        '',
        'Read the write. If the opened value reaches a column, that column must be',
        'sealed, or disclosed in SensitiveFieldRegistry with the reason AEAD does not',
        'apply to it -- a plaintext shadow is allowed only where the registry discloses it.',
        'Then write the verdict down in A_DECRYPT_THAT_ALSO_WRITES.',
    ]));
});

it('keeps no verdict for a file that no longer does both', function (): void {
    $writers = decryptingWriters();

    $stale = array_values(array_filter(
        array_keys(A_DECRYPT_THAT_ALSO_WRITES),
        static fn (string $path): bool => ! in_array($path, $writers, true),
    ));

    expect($stale)->toBe([], implode("\n", [
        'These carry a verdict about a decrypt-then-write they no longer do, or they',
        'are gone. A verdict nobody can check excuses nothing. Remove it:',
        ...$stale,
    ]));
});

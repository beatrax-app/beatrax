<?php

declare(strict_types=1);

use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Tests\Support\SensitiveColumnScan;

/** @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#how-the-predicate-guard-decides-what-it-is-looking-at */

/** @return string the repository root, with a trailing slash, from either Composer root */
function sensitiveColumnGuardRoot(): string
{
    return dirname(__DIR__, 4).'/';
}

/** @return array<string, string> path::column::kind => reason */
function sensitiveColumnGuardAllowlist(): array
{
    /** @var array<string, string> $allowlist */
    $allowlist = require __DIR__.'/sensitive-column-guard-allowlist.php';

    return $allowlist;
}

/**
 * Every hit in the tree, allowlist NOT applied. Each caller decides what to do
 * with an exemption, which is what lets one test ask "what is still an
 * offender" and the next ask "what does each exemption still cover".
 *
 * @param  list<string>  $sealedPairs
 * @return list<array<string, mixed>>
 */
function sensitiveColumnGuardHits(array $sealedPairs = []): array
{
    /** @var array<string, array<string, string>> $modelTables */
    static $modelTables = [];

    $root = sensitiveColumnGuardRoot();
    $modelTables[$root] ??= SensitiveColumnScan::modelTables($root);

    $pairs = $sealedPairs === [] ? SensitiveFieldRegistry::columns() : $sealedPairs;
    $hits = [];

    foreach (SensitiveColumnScan::productionFiles($root) as $path => $relative) {
        foreach (SensitiveColumnScan::hits($relative, (string) file_get_contents($path), $modelTables[$root], $pairs) as $hit) {
            $hits[] = $hit;
        }
    }

    return $hits;
}

/** @return list<string> the sealed-column sites the guard would report today */
function sensitiveColumnGuardOffenders(): array
{
    $allowlist = sensitiveColumnGuardAllowlist();
    $offenders = [];

    foreach (SensitiveColumnScan::offenders(sensitiveColumnGuardHits()) as $hit) {
        if (array_key_exists(SensitiveColumnScan::signature($hit), $allowlist)) {
            continue;
        }
        $offenders[] = SensitiveColumnScan::describe($hit);
    }

    return $offenders;
}

/**
 * @param  list<string>  $sealedPairs
 * @return list<string> signatures of every hit the scanner reports on $source
 */
function sensitiveColumnGuardProbe(string $source, array $sealedPairs = []): array
{
    $hits = SensitiveColumnScan::hits(
        'Probe.php',
        $source,
        [],
        $sealedPairs === [] ? SensitiveFieldRegistry::columns() : $sealedPairs,
    );

    return array_map(SensitiveColumnScan::signature(...), SensitiveColumnScan::offenders($hits));
}

it('reads the whole production tree rather than a handful of files', function (): void {
    $files = SensitiveColumnScan::productionFiles(sensitiveColumnGuardRoot());

    expect(count($files))->toBeGreaterThan(1_500, 'the walk read almost no production files — it is broken, not the tree')
        ->and(SensitiveColumnScan::modelTables(sensitiveColumnGuardRoot()))->toHaveKey('Counterparty');
});

it('has zero uncoded sensitive-column predicate, read or write sites in production code', function (): void {
    expect(sensitiveColumnGuardOffenders())->toBe([], implode("\n", [
        'These calls name a column SensitiveFieldRegistry seals, in a place where the database',
        'has to read the stored bytes — and random-nonce ciphertext is different bytes every',
        'time it is written, so the predicate matches nothing and the write stores plaintext:',
        '',
        ...array_slice(sensitiveColumnGuardOffenders(), 0, 20),
        '',
        'Three answers are accepted, in this order. Name the table in the call, if the column is',
        'a different one that merely shares a bare name. Route the value through',
        'SensitiveColumnCodec, if it is a write. Or add the one call to',
        'sensitive-column-guard-allowlist.php with a reason naming the {table}.{column} it rests',
        'on — which has to be a column knowinglyPlaintext() records, so the claim is checkable.',
    ]));
});

it('scans a file for one column while it seals another', function (): void {
    $source = <<<'PHP'
        <?php

        final class ScratchProbe
        {
            public function sealsItsNote(int $userId): void
            {
                $attrs = $this->codec->encryptAttrs('transactions', ['note' => $this->note], $userId, $this->session);
                DB::table('transactions')->where('id', $this->id)->update($attrs);
            }

            public function leaksAName(string $name): void
            {
                DB::table('counterparties')->where('merchant_name', $name)->first();
            }
        }
        PHP;

    expect(sensitiveColumnGuardProbe($source))->toBe(['Probe.php::merchant_name::where']);
});

it('reads the table a call names instead of the bare column name', function (string $call, array $expected): void {
    expect(sensitiveColumnGuardProbe("<?php\n".$call))->toBe($expected);
})->with([
    'an own-account IBAN match' => ["DB::table('accounts')->where('iban', \$iban)->first();", []],
    'the sealed IBAN, same bare name' => ["DB::table('counterparties')->where('iban', \$iban)->first();", ['Probe.php::iban::where']],
    'an own-account IBAN write' => ["DB::table('accounts')->insert(['iban' => \$iban]);", []],
    'the sealed IBAN, written raw' => ["DB::table('counterparties')->insert(['iban' => \$iban]);", ['Probe.php::iban::write']],
    'a table the chain does not name' => ["\$query->where('counterparty_iban', \$iban)->first();", ['Probe.php::counterparty_iban::where']],
]);

it('clears a write by the codec call it makes, not by the file it sits in', function (string $call, array $expected): void {
    expect(sensitiveColumnGuardProbe("<?php\n".$call))->toBe($expected);
})->with([
    'a value sealed at the call' => [
        "DB::table('transactions')->update(['note' => \$codec->encryptValue('transactions', 'note', \$v, \$u, \$s)]);",
        [],
    ],
    'the same write with the codec call taken out' => [
        "DB::table('transactions')->update(['note' => \$v]);",
        ['Probe.php::note::write'],
    ],
    'an array handed to encryptAttrs' => [
        "DB::table('transactions')->update(\$codec->encryptAttrs('transactions', ['note' => \$v], \$u, \$s));",
        [],
    ],
    'encryptAttrs named for a different table' => [
        "DB::table('transactions')->update(\$codec->encryptAttrs('counterparties', ['note' => \$v], \$u, \$s));",
        ['Probe.php::note::write'],
    ],
]);

it('reads a comment as a comment, not as an open string literal', function (): void {
    $source = <<<'PHP'
        <?php

        final class ScratchProbe
        {
            public function ownAccount(string $iban): void
            {
                DB::table('accounts')->insert([
                    // the row's own iban, written where a person can read it
                    'iban' => $iban,
                ]);
            }

            public function sealedCounterparty(string $plain): void
            {
                DB::table('counterparties')->insert(['iban' => $plain]);
            }
        }
        PHP;

    expect(sensitiveColumnGuardProbe($source))->toBe(['Probe.php::iban::write']);
});

it('clears a spliced write only for the half that was sealed', function (): void {
    $call = "DB::table('transactions')->update(\$codec->encryptAttrs('transactions', ['note' => \$n], \$u, \$s) + ['description' => \$plain]);";

    expect(sensitiveColumnGuardProbe("<?php\n".$call))->toBe(['Probe.php::description::write']);
});

it('follows one hop to a helper that seals the value', function (): void {
    $sealed = <<<'PHP'
        <?php

        final class ScratchProbe
        {
            public function tag(): void
            {
                DB::table('tax_transaction_tags')->insert(['note' => $this->sealNote($note)]);
            }

            private function sealNote(?string $note): ?string
            {
                return $note === null ? null : $this->codec->encryptValue('tax_transaction_tags', 'note', $note, $this->userId, $this->session);
            }
        }
        PHP;

    $unsealed = str_replace('$this->codec->encryptValue(\'tax_transaction_tags\', \'note\', $note, $this->userId, $this->session)', 'trim($note)', $sealed);

    expect(sensitiveColumnGuardProbe($sealed))->toBe([])
        ->and(sensitiveColumnGuardProbe($unsealed))->toBe(['Probe.php::note::write']);
});

it('sees a write verb it has never been told the name of', function (string $call, array $expected): void {
    expect(sensitiveColumnGuardProbe("<?php\n".$call))->toBe($expected);
})->with([
    'insert, the one spelling the old reading knew' => ["\$q->insert(['note' => \$v]);", ['Probe.php::note::write']],
    'insertChunked' => ["\$q->insertChunked([['note' => \$v]]);", ['Probe.php::note::write']],
    'insertGetId' => ["\$q->insertGetId(['note' => \$v]);", ['Probe.php::note::write']],
    'upsert' => ["\$q->upsert([['note' => \$v]], ['id']);", ['Probe.php::note::write']],
    'updateOrInsert' => ["\$q->updateOrInsert(['id' => 1], ['note' => \$v]);", ['Probe.php::note::write']],
    'firstOrCreate' => ["\$q->firstOrCreate(['id' => 1], ['note' => \$v]);", ['Probe.php::note::write']],
    'findOrCreate' => ["\$q->findOrCreate(['id' => 1], ['note' => \$v]);", ['Probe.php::note::write']],
    'forceFill' => ["\$model->forceFill(['note' => \$v]);", ['Probe.php::note::write']],
]);

it('reads a write to its closing bracket rather than to a character budget', function (): void {
    $filler = '';
    for ($i = 0; $i < 40; $i++) {
        $filler .= "'padding_column_{$i}' => \$value{$i}, ";
    }

    $call = "<?php\nDB::table('transactions')->insert([{$filler}'note' => \$plaintext]);";

    expect(strlen($call))->toBeGreaterThan(600)
        ->and(sensitiveColumnGuardProbe($call))->toBe(['Probe.php::note::write']);
});

it('tells a sealed JSON column apart from a local that starts with its name', function (string $call, array $expected): void {
    expect(sensitiveColumnGuardProbe("<?php\n".$call))->toBe($expected);
})->with([
    'the column, read off a row' => ['$decoded = json_decode($row->params, true);', ['Probe.php::params::json_decode']],
    'the column, read out of an array' => ["\$decoded = json_decode(\$row['params'], true);", ['Probe.php::params::json_decode']],
    'a local holding the already-decrypted string' => ['$decoded = json_decode($paramsJson, true);', []],
]);

it('finds the predicate shapes an equality is written as when it is not written as one', function (string $call, array $expected): void {
    expect(sensitiveColumnGuardProbe("<?php\n".$call))->toBe($expected);
})->with([
    'orWhere' => ["\$q->orWhere('merchant_name', \$n);", ['Probe.php::merchant_name::where']],
    'whereIn' => ["\$q->whereIn('merchant_name', \$names);", ['Probe.php::merchant_name::where']],
    'whereLike' => ["\$q->whereLike('merchant_name', \$n);", ['Probe.php::merchant_name::where']],
    'orderBy' => ["\$q->orderBy('merchant_name');", ['Probe.php::merchant_name::orderBy']],
    'groupBy' => ["\$q->groupBy('merchant_name');", ['Probe.php::merchant_name::groupBy']],
    'whereRaw without a LIKE in it' => ["\$q->whereRaw('lower(merchant_name) = ?', [\$n]);", ['Probe.php::merchant_name::whereRaw']],
    'a join predicate' => ["\$q->join('counterparties', 'c.merchant_name', '=', 'm.name');", ['Probe.php::merchant_name::join']],
]);

it('grants an exemption only in a unit a reader can check', function (): void {
    $columns = SensitiveColumnScan::bareColumns();
    $kinds = SensitiveColumnScan::kinds();
    $root = sensitiveColumnGuardRoot();

    $malformed = [];

    foreach (array_keys(sensitiveColumnGuardAllowlist()) as $signature) {
        $parts = explode('::', $signature);

        if (count($parts) !== 3) {
            $malformed[] = "{$signature} is not path::column::kind";

            continue;
        }

        [$path, $column, $kind] = $parts;

        if (! is_file($root.$path)) {
            $malformed[] = "{$signature} names a file that is not in the tree";
        }
        if (! in_array($column, $columns, true)) {
            $malformed[] = "{$signature} names {$column}, which SensitiveFieldRegistry does not seal in any table";
        }
        if (! in_array($kind, $kinds, true)) {
            $malformed[] = "{$signature} names the kind {$kind}, which this scanner never reports";
        }
    }

    expect($malformed)->toBe([], implode("\n", [
        'An exemption is granted for one file, one column and one shape of use, so that a file',
        'cannot acquire silence for its predicates by encrypting something else correctly:',
        '',
        ...$malformed,
    ]));
});

it('keeps no exemption that has outlived the call it was granted for', function (): void {
    $live = [];

    foreach (SensitiveColumnScan::offenders(sensitiveColumnGuardHits()) as $hit) {
        $live[SensitiveColumnScan::signature($hit)] = true;
    }

    $stale = array_values(array_filter(
        array_keys(sensitiveColumnGuardAllowlist()),
        static fn (string $signature): bool => ! isset($live[$signature]),
    ));

    expect($stale)->toBe([], implode("\n", [
        'The scan was run with every exemption withheld, and these named nothing it reported.',
        'The call they were granted for has been rewritten, moved or deleted, so each is now an',
        'exclusion covering whatever is written there next. Delete the entry:',
        '',
        ...$stale,
    ]));
});

it('does not exempt any site whose reason says it is broken', function (): void {
    $banned = ['broken', 'known-broken', 'known broken', 'unfixed', 'TODO', 'FIXME'];

    $offenders = [];
    foreach (sensitiveColumnGuardAllowlist() as $signature => $reason) {
        foreach ($banned as $token) {
            if (stripos($reason, $token) !== false) {
                $offenders[] = "{$signature} => {$reason}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('rests an exemption only on a column whose plaintext status is a recorded decision', function (): void {
    $decided = array_keys(SensitiveFieldRegistry::knowinglyPlaintext());
    $sealed = SensitiveFieldRegistry::columns();

    $offenders = [];
    foreach (sensitiveColumnGuardAllowlist() as $signature => $reason) {
        foreach (SensitiveColumnScan::citedColumns($reason) as $cited) {
            if (in_array($cited, $sealed, true)) {
                $offenders[] = "{$signature} rests on {$cited} being plaintext, and SensitiveFieldRegistry now seals it";
            } elseif (! in_array($cited, $decided, true)) {
                $offenders[] = "{$signature} rests on {$cited}, which SensitiveFieldRegistry::knowinglyPlaintext() does not record";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the sealed list and the knowingly-plaintext list disjoint', function (): void {
    $overlap = array_intersect(
        SensitiveFieldRegistry::columns(),
        array_keys(SensitiveFieldRegistry::knowinglyPlaintext()),
    );

    expect(array_values($overlap))->toBe([]);
});

// The landmine, asked of the tree rather than of the reasons written about it.
// The old reading skipped an allowlisted FILE before scanning it, so promoting
// a knowingly-plaintext column left its exemptions silently covering the most
// dangerous predicates in the codebase. Nothing is skipped now, so promoting
// the column turns every call that rests on it red, and the answer is the list
// of them — eleven files were written down, and there are twenty call sites.
it('turns every accounts.iban call red the moment that column joins the registry', function (): void {
    $promoted = [...SensitiveFieldRegistry::columns(), 'accounts.iban'];

    $resting = [];
    foreach (SensitiveColumnScan::offenders(sensitiveColumnGuardHits($promoted)) as $hit) {
        if ($hit['column'] === 'iban') {
            $resting[] = SensitiveColumnScan::signature($hit);
        }
    }

    sort($resting);

    expect($resting)->toBe([
        'Modules/CashBook/Internal/Services/ManualEntryAnchors.php::iban::where',
        'Modules/CashBook/Internal/Services/ManualEntryAnchors.php::iban::write',
        'Modules/Chains/Internal/PaypalFundingSignatureKey.php::iban::where',
        'Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php::iban::where',
        'Modules/Import/Internal/Http/Livewire/PreviewWizard.php::iban::write',
        'Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php::iban::where',
        'Modules/Import/Internal/Services/OwnAccountPrompt.php::iban::where',
        'Modules/Import/Public/Actions/EnsureGooglePlayAccountAction.php::iban::where',
        'Modules/Import/Public/Actions/EnsureGooglePlayAccountAction.php::iban::write',
        'Modules/Import/Public/Actions/EnsurePaypalAccountAction.php::iban::where',
        'Modules/Import/Public/Actions/EnsurePaypalAccountAction.php::iban::write',
        'Modules/Import/Public/Services/AccountNamer.php::iban::where',
        'Modules/Import/Public/Services/EloquentAccountResolver.php::iban::where',
        'Modules/Migration/Internal/Pipeline/PromoteStagingToDomain.php::iban::write',
        'Modules/Onboarding/Internal/Http/Livewire/Steps/ConnectBankStep.php::iban::where',
        'Modules/Onboarding/Internal/Http/Livewire/Steps/ConnectBankStep.php::iban::write',
        'Modules/Onboarding/Internal/Http/Livewire/Steps/ConnectCardStep.php::iban::where',
        'Modules/Onboarding/Internal/Http/Livewire/Steps/ConnectCardStep.php::iban::write',
        'Modules/Receipts/Internal/ReceiptLedgerBridge.php::iban::where',
        'Modules/Transfers/Internal/Services/TransferPairer.php::iban::where',
    ]);
});

<?php

declare(strict_types=1);

use Tests\Contracts\Support\SonarSourceFiles;

/**
 * @link ../../.docs/architecture/an-ordering-that-picks.md
 */

// `transactions.id` and `counterparties.id` are per-device autoincrements and
// `recurring_series_occurrences.id` is device-minted, so an ORDER BY ending on
// one ranks a tie one way here and the other way there. Whether that matters
// is decided by what the query does next: an ordering that WALKS every row
// visits the same set whatever the sequence, while an ordering that PICKS —
// bounded by limit(), first(), value(), sole(), or ranked by a window function
// — makes the tie decide which rows the reader is shown at all.

const PICK_ORDER_SUBJECT_TABLES = ['transactions', 'counterparties', 'recurring_series_occurrences'];

// The Eloquent spelling of the same read, mapped rather than resolved so the
// scanner stays a lexer.
const PICK_ORDER_SUBJECT_MODELS = [
    'Transaction' => 'transactions',
    'Counterparty' => 'counterparties',
    'RecurringSeriesOccurrence' => 'recurring_series_occurrences',
];

// The calls that turn an ordering into a choice. `chunk`, `chunkById`,
// `cursor`, `each` and a bare `get()` are absent on purpose: those walk, and
// ordering by id is how several of them page safely.
const PICK_ORDER_BOUNDS = [
    'limit', 'take', 'first', 'firstOrFail', 'firstOr', 'value', 'sole', 'soleOrFail',
    'paginate', 'simplePaginate', 'cursorPaginate',
];

const PICK_ORDER_CALLS = ['orderBy', 'orderByDesc', 'orderByRaw', 'latest', 'oldest'];

// A `const NAME = 'value'` declaration reached through the token stream rather
// than a pattern, because the clause this rule is about is assembled from two
// of them: `ACROSS_ACCOUNTS` ends on `self::ACCOUNT.'.iban desc'`.
const PICK_ORDER_FILE_FLOOR = 1_000;

// Keyed `path::table`, carrying how many picks in that file end on a bare id
// and WHY each is admitted. The count is exact in both directions: a pick
// added to an allowed file pushes past the entry, and an entry that stops
// matching fails too, so the list cannot rot into a blanket exemption.
const PICK_ORDER_ALLOWED = [
    'Modules/Anomaly/Internal/Detectors/DuplicateChargeDetector.php::transactions' => [
        'picks' => 1,
        'why' => 'KNOWN DIVERGENT. The id is load-bearing in the WHERE too — `posted_at = anchor AND id < $thisId` is what makes a same-day pair backward-only — so reordering alone would be cosmetic: the candidate SET already differs between devices. Of three identical same-day charges, device A alerts on the second and third and device B on the first and third, and anomaly_alerts ids are DerivedRowId::for(user_id, transaction_id), so the two devices file different alert rows for one duplicate.',
    ],
    'Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php::transactions' => [
        'picks' => 2,
        'why' => 'KNOWN DIVERGENT. Both arms order by nearness in time FIRST and end on the id, and both cut at 20: the alias arm stops at the first two rows whose IBAN matches, the fuzzy arm keeps the first candidate at a tied score (`> $bestScore`), and ChainLinkInsertHelper derives the link id. Fixing needs a distance-primary clause with a device-stable tail, which NewestTransactionFirst does not spell.',
    ],
    'Modules/Ledger/Internal/Services/CounterpartyKeyProvenance.php::transactions' => [
        'picks' => 1,
        'why' => 'A probe, not a read for a screen: it asks whether ANY stored digest reproduces under a candidate key, so which rows the sample holds cannot change the answer. Ordering by id is how it takes a cheap, repeatable sample of this device\'s own file.',
    ],
    'Modules/Ledger/Public/Services/SplitSumHealthCheck.php::transactions' => [
        'picks' => 1,
        'why' => 'A diagnostic that reports at most N ids of rows whose legs disagree with their parent. The ids it prints ARE this device\'s ids — that is what the reader is being handed — so a peer agreeing on the order would say nothing.',
    ],
];

/**
 * Every `const NAME = <string expression>` the walked tree declares, keyed by
 * `ShortClassName::NAME`. Resolved to a fixpoint because a clause is built out
 * of its own class's other constants.
 *
 * @return array<string, string>
 */
function pickOrderConstantIndex(): array
{
    static $index = null;

    if ($index !== null) {
        return $index;
    }

    $index = [];
    $pending = [];

    foreach (SonarSourceFiles::all() as $path) {
        $source = (string) file_get_contents($path);

        if (! str_contains($source, 'const ')) {
            continue;
        }

        $tokens = SonarSourceFiles::tokens($source);
        $class = basename($path, '.php');

        foreach ($tokens as $at => $token) {
            if ($token[0] !== T_CONST) {
                continue;
            }

            $name = $at + 1;

            // `const string NAME` — the typed spelling puts the type where the
            // name would otherwise be.
            if (($tokens[$name][0] ?? null) === T_STRING && ($tokens[$name + 1][0] ?? null) === T_STRING) {
                $name++;
            }

            if (($tokens[$name][0] ?? null) === T_STRING && ($tokens[$name + 1][1] ?? null) === '=') {
                $pending[] = ['class' => $class, 'name' => $tokens[$name][1], 'tokens' => $tokens, 'at' => $name + 2];
            }
        }
    }

    // Four rounds: the deepest chain in this tree is a constant built from a
    // constant built from a literal, and a round that resolves nothing new
    // costs one pass over a list, not over the tree.
    for ($round = 0; $round < 4; $round++) {
        foreach ($pending as $declaration) {
            $key = $declaration['class'].'::'.$declaration['name'];

            if (isset($index[$key])) {
                continue;
            }

            $end = 0;
            $value = pickOrderStringExpression($declaration['tokens'], $declaration['at'], $end, $declaration['class'], $index);

            if ($value !== null) {
                $index[$key] = $value;
            }
        }
    }

    return $index;
}

// A run of string literals and class constants joined by `.`, which is how
// every ORDER BY clause in this tree is spelled. Anything else — a variable, a
// function call — returns null, and a clause whose last term is unreadable is
// not reported: the scanner says what it can see and no more.
/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<string, string>  $index
 */
function pickOrderStringExpression(array $tokens, int $at, ?int &$end, string $self, array $index): ?string
{
    $value = '';
    $count = count($tokens);
    $read = false;

    while ($at < $count) {
        $token = $tokens[$at];

        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value .= pickOrderLiteral($token[1]);
            $at++;
            $read = true;
        } elseif (pickOrderIsConstantFetch($tokens, $at)) {
            $named = $tokens[$at][1];
            $class = in_array(strtolower($named), ['self', 'static'], true)
                ? $self
                : (string) (array_slice(explode('\\', $named), -1)[0] ?? '');
            $key = $class.'::'.$tokens[$at + 2][1];

            if (! isset($index[$key])) {
                return null;
            }

            $value .= $index[$key];
            $at += 3;
            $read = true;
        } else {
            return null;
        }

        if (($tokens[$at][1] ?? null) === '.') {
            $at++;

            continue;
        }

        break;
    }

    $end = $at;

    return $read ? $value : null;
}

/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 */
function pickOrderIsConstantFetch(array $tokens, int $at): bool
{
    return in_array($tokens[$at][0] ?? null, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC], true)
        && ($tokens[$at + 1][0] ?? null) === T_DOUBLE_COLON
        && ($tokens[$at + 2][0] ?? null) === T_STRING;
}

function pickOrderLiteral(string $raw): string
{
    $inner = substr($raw, 1, -1);

    return $raw[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
}

// The subject table a chain opens on, spelled as a table name, as `from`, or
// as one of the three models. `table('transactions as tx')` names the same
// table the unaliased spelling does.
/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<string, string>  $index
 */
function pickOrderSubjectAt(array $tokens, int $at, string $self, array $index): ?string
{
    $token = $tokens[$at];

    if ($token[0] !== T_STRING) {
        return null;
    }

    if ($token[1] === 'query') {
        return pickOrderModelAt($tokens, $at);
    }

    if (! in_array($token[1], ['table', 'from'], true)) {
        return null;
    }

    if (($tokens[$at - 1][0] ?? null) !== T_OBJECT_OPERATOR || ($tokens[$at + 1][1] ?? null) !== '(') {
        return null;
    }

    $end = 0;
    $argument = pickOrderStringExpression($tokens, $at + 2, $end, $self, $index);

    if ($argument === null) {
        return null;
    }

    $named = explode(' ', trim($argument))[0];

    return in_array($named, PICK_ORDER_SUBJECT_TABLES, true) ? $named : null;
}

/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 */
function pickOrderModelAt(array $tokens, int $at): ?string
{
    if (($tokens[$at - 1][0] ?? null) !== T_DOUBLE_COLON) {
        return null;
    }

    $model = $tokens[$at - 2] ?? null;

    if ($model === null || ! in_array($model[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
        return null;
    }

    $short = (string) (array_slice(explode('\\', $model[1]), -1)[0] ?? '');

    return PICK_ORDER_SUBJECT_MODELS[$short] ?? null;
}

// Every `->method(...)` reached at depth zero after the opening call closes,
// with the string arguments it could resolve. A chain assembled across two
// variables is invisible here, which is this scanner's one honest blind spot.
/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 * @param  array<string, string>  $index
 * @return list<array{name:string,args:list<string>,line:int}>
 */
function pickOrderChainCalls(array $tokens, array $brackets, int $at, string $self, array $index): array
{
    $count = count($tokens);
    $open = $at + 1;

    while ($open < $count && ($tokens[$open][1] ?? null) !== '(') {
        $open++;
    }

    $calls = [];
    $depth = 0;

    for ($at = ($brackets[$open] ?? $open) + 1; $at < $count; $at++) {
        $token = $tokens[$at];

        if ($token[0] === null && in_array($token[1], ['(', '[', '{'], true)) {
            $depth++;

            continue;
        }

        if ($token[0] === null && in_array($token[1], [')', ']', '}'], true)) {
            $depth--;

            if ($depth < 0) {
                break;
            }

            continue;
        }

        if ($depth !== 0) {
            continue;
        }

        if ($token[0] === null && in_array($token[1], [';', ','], true)) {
            break;
        }

        if ($token[0] !== T_OBJECT_OPERATOR && $token[0] !== T_NULLSAFE_OBJECT_OPERATOR) {
            continue;
        }

        $name = $tokens[$at + 1] ?? null;

        if ($name === null || $name[0] !== T_STRING) {
            continue;
        }

        $calls[] = [
            'name' => $name[1],
            'args' => ($tokens[$at + 2][1] ?? null) === '('
                ? pickOrderArguments($tokens, $brackets, $at + 2, $self, $index)
                : [],
            'line' => $name[2],
        ];
    }

    return $calls;
}

/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 * @param  array<string, string>  $index
 * @return list<string>
 */
function pickOrderArguments(array $tokens, array $brackets, int $open, string $self, array $index): array
{
    $close = $brackets[$open] ?? $open;
    $arguments = [];

    for ($at = $open + 1; $at < $close;) {
        $end = 0;
        $value = pickOrderStringExpression($tokens, $at, $end, $self, $index);

        if ($value !== null) {
            $arguments[] = $value;
            $at = $end;
        }

        while ($at < $close && ($tokens[$at][1] ?? null) !== ',') {
            $at = ($tokens[$at][1] ?? null) === '(' ? ($brackets[$at] ?? $at) : $at;
            $at++;
        }

        $at++;
    }

    return $arguments;
}

// The ORDER BY clause inside a raw fragment, read from `order by` to the
// parenthesis that closes the window it sits in. Without this the window form
// is invisible, and that form is live: CounterpartyIndexQuery ranks the newest
// charge per counterparty with ROW_NUMBER() OVER (... ORDER BY ...).
/**
 * @return list<array{clause:string,window:bool,limited:bool}>
 */
function pickOrderRawClauses(string $sql): array
{
    $clauses = [];
    $offset = 0;

    while (($at = stripos($sql, 'order by', $offset)) !== false) {
        $head = substr($sql, 0, $at);
        $depth = 0;
        $end = strlen($sql);

        for ($i = $at + 8; $i < strlen($sql); $i++) {
            $depth += match ($sql[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth < 0) {
                $end = $i;

                break;
            }
        }

        $clauses[] = [
            'clause' => substr($sql, $at + 8, $end - $at - 8),
            'window' => preg_match('/\bover\s*\($/i', rtrim($head)) === 1 || stripos($head, 'over (') !== false,
            'limited' => stripos(substr($sql, $end), 'limit') !== false,
        ];
        $offset = $end;
    }

    return $clauses;
}

// The column an ORDER BY ends on, stripped of its direction and of whatever
// table it was reached through: `transactions.id desc` and `o.id` are the same
// answer to the same question.
function pickOrderLastTerm(string $clause): string
{
    $terms = array_map('trim', explode(',', $clause));
    $last = (string) end($terms);
    $last = trim((string) preg_replace('/\s+(asc|desc)\s*$/i', '', $last));
    $dot = strrpos($last, '.');

    return strtolower($dot === false ? $last : substr($last, $dot + 1));
}

/**
 * Every ordering in one file that PICKS against a subject table, with the
 * column it ends on.
 *
 * @return list<array{line:int,table:string,last:string,how:string}>
 */
function pickOrderScan(string $source, string $self = 'Probe'): array
{
    $index = pickOrderConstantIndex();
    $tokens = SonarSourceFiles::tokens($source);
    $brackets = SonarSourceFiles::brackets($tokens);
    $found = [];

    foreach ($tokens as $at => $token) {
        $table = pickOrderSubjectAt($tokens, $at, $self, $index);

        if ($table === null) {
            continue;
        }

        $calls = pickOrderChainCalls($tokens, $brackets, $at, $self, $index);
        $bounded = array_intersect(array_column($calls, 'name'), PICK_ORDER_BOUNDS) !== [];
        $terms = [];

        foreach ($calls as $call) {
            if (in_array($call['name'], ['latest', 'oldest'], true)) {
                $terms[] = $call['args'][0] ?? 'created_at';

                continue;
            }

            if (in_array($call['name'], PICK_ORDER_CALLS, true) && $call['args'] !== []) {
                $terms[] = $call['args'][0];
            }

            foreach ($call['args'] as $argument) {
                foreach (pickOrderRawClauses($argument) as $raw) {
                    if ($raw['window'] || $raw['limited'] || $bounded) {
                        $found[] = [
                            'line' => $call['line'],
                            'table' => $table,
                            'last' => pickOrderLastTerm($raw['clause']),
                            'how' => $raw['window'] ? 'a window rank' : 'a raw ORDER BY under a cut',
                        ];
                    }
                }
            }
        }

        if ($bounded && $terms !== []) {
            $found[] = [
                'line' => $token[2],
                'table' => $table,
                'last' => pickOrderLastTerm(implode(', ', $terms)),
                'how' => 'a chain bounded by '.implode('/', array_intersect(array_column($calls, 'name'), PICK_ORDER_BOUNDS)),
            ];
        }
    }

    return $found;
}

/**
 * Every pick against a subject table, keyed `path::table` and carrying the
 * column each one ends on, read off one walk of the tree.
 *
 * @return array<string, list<array{line:int,last:string,how:string}>>
 */
function pickOrderPicksByKey(): array
{
    static $memo = null;

    if (is_array($memo)) {
        return $memo;
    }

    $picks = [];

    foreach (SonarSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (pickOrderScan((string) file_get_contents($path), basename($path, '.php')) as $hit) {
            $picks[$relative.'::'.$hit['table']][] = [
                'line' => $hit['line'],
                'last' => $hit['last'],
                'how' => $hit['how'],
            ];
        }
    }

    ksort($picks);

    return $memo = $picks;
}

/**
 * The subset of those that end on a bare id.
 *
 * @return array<string, list<string>>
 */
function pickOrderOffendersByKey(): array
{
    $offenders = [];

    foreach (pickOrderPicksByKey() as $key => $picks) {
        foreach ($picks as $pick) {
            if ($pick['last'] === 'id') {
                $offenders[$key][] = 'line '.$pick['line'].', '.$pick['how'];
            }
        }
    }

    return $offenders;
}

/**
 * @return list<string>
 */
function pickOrderLastTermsAt(string $key): array
{
    return array_map(
        static fn (array $pick): string => $pick['last'],
        pickOrderPicksByKey()[$key] ?? [],
    );
}

// Every verdict below is read off one walk, and a walk that opened nothing
// answers "no divergent pick" in the same words a clean tree does. The floor
// is not the protection — the exact allow-list match below is — but a floor
// tells a scope that moved apart from a tree that stopped offending.
it('walks the tree it is about to read its verdicts off', function (): void {
    expect(count(SonarSourceFiles::all()))->toBeGreaterThan(
        PICK_ORDER_FILE_FLOOR,
        'The walk opened '.count(SonarSourceFiles::all()).' production files, which is what a reader that stopped reading looks like.',
    );

    expect(pickOrderConstantIndex()['NewestTransactionFirst::ACROSS_ACCOUNTS'] ?? null)->toBe(
        'posted_at desc, booked_at desc, amount_minor desc, currency desc, counterparty_normalized desc, occurrence_ordinal desc, charge_account.iban desc',
        'The constant reader could not assemble the one clause this rule exists to spread, so every '
        .'orderByRaw() behind a constant reads as an ordering with no terms and cannot offend.',
    );
});

// A read whose table and whose clause are both behind a class constant is the
// shape a scanner goes blind on while still passing: the allow-list reads
// "allows N, found 0", which is what a FIXED read looks like too. These three
// are named rather than counted, so blindness and a fix cannot be confused.
it('still reads a pick whose table and clause are both behind a constant', function (): void {
    expect(pickOrderConstantIndex()['SeriesTables::OCCURRENCES'] ?? null)->toBe('recurring_series_occurrences as o')
        ->and(pickOrderConstantIndex()['NewestOccurrenceFirst::SQL'] ?? null)->toContain('occurrence_ordinal');

    expect(pickOrderLastTermsAt('Modules/Recurring/Public/Services/RecurringOccurrenceQuery.php::recurring_series_occurrences'))
        ->toBe(['occurrence_ordinal', 'occurrence_ordinal'])
        ->and(pickOrderLastTermsAt('Modules/Recurring/Internal/Queries/SeriesAccountResolver.php::recurring_series_occurrences'))
        ->toBe(['occurrence_ordinal']);
});

it('reads a pick that ends on an id, and leaves a walk that ends on one alone', function (): void {
    $picked = <<<'PHP'
        <?php
        $rows = $db->connection()->table('transactions')
            ->where('user_id', 1)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
        PHP;

    $walked = <<<'PHP'
        <?php
        $db->connection()->table('transactions')->where('user_id', 1)->orderBy('id')->chunkById(500, $handler);
        PHP;

    $parted = <<<'PHP'
        <?php
        $rows = $db->connection()->table('transactions')
            ->orderByDesc('posted_at')
            ->orderByDesc('booked_at')
            ->limit(5)
            ->get();
        PHP;

    $elsewhere = <<<'PHP'
        <?php
        $rows = $db->connection()->table('accounts')->orderByDesc('id')->limit(5)->get();
        PHP;

    expect(pickOrderScan($picked))->toHaveCount(1)
        ->and(pickOrderScan($picked)[0]['last'])->toBe('id')
        ->and(pickOrderScan($picked)[0]['table'])->toBe('transactions')
        ->and(pickOrderScan($walked))->toBe([], 'a chunkById walk visits every row, so the tie decides nothing')
        ->and(pickOrderScan($parted)[0]['last'])->toBe('booked_at')
        ->and(pickOrderScan($elsewhere))->toBe([], 'accounts is not one of the three tables this rule is about');
});

// The window spelling carries no limit() and no first(): the rank IS the cut,
// and reading only fluent chains walks straight past it.
it('reads the window spelling of the same pick', function (): void {
    $ranked = <<<'PHP'
        <?php
        $ranked = $connection->table('transactions')
            ->join('accounts as charge_account', 'charge_account.id', '=', 'transactions.account_id')
            ->selectRaw('counterparty_id, ROW_NUMBER() OVER (PARTITION BY counterparty_id ORDER BY posted_at desc, id desc) as rn');
        PHP;

    $stable = <<<'PHP'
        <?php
        $ranked = $connection->table('transactions')
            ->selectRaw('counterparty_id, ROW_NUMBER() OVER (PARTITION BY counterparty_id ORDER BY posted_at desc, charge_account.iban desc) as rn');
        PHP;

    expect(pickOrderScan($ranked))->toHaveCount(1)
        ->and(pickOrderScan($ranked)[0]['last'])->toBe('id')
        ->and(pickOrderScan($ranked)[0]['how'])->toBe('a window rank')
        ->and(pickOrderScan($stable))->toHaveCount(1)
        ->and(pickOrderScan($stable)[0]['last'])->toBe(
            'iban',
            'the same window is still read; what changes is the column it ends on, which is what the rule turns on',
        );
});

it('admits no pick ending on a per-device id outside the allow-list', function (): void {
    $unlisted = [];

    foreach (pickOrderOffendersByKey() as $key => $lines) {
        $allowed = PICK_ORDER_ALLOWED[$key]['picks'] ?? 0;

        if (count($lines) > $allowed) {
            $unlisted[] = $key.' ('.count($lines).' found, '.$allowed.' allowed) — '.implode('; ', $lines);
        }
    }

    expect($unlisted)->toBe([], implode("\n  ", [
        'These end an ORDER BY on an id the device counts for itself, and then cut the result — so the',
        'tie decides WHICH rows the reader is shown, not merely their sequence, and the two devices show',
        'different ones. End on the device-stable key instead:',
        'Modules\Ledger\Public\Support\NewestTransactionFirst::ACROSS_ACCOUNTS, joined through ::ACCOUNT.',
        ...$unlisted,
    ]));
});

it('carries no allow-list entry that has stopped matching', function (): void {
    $offenders = pickOrderOffendersByKey();
    $stale = [];

    foreach (PICK_ORDER_ALLOWED as $key => $entry) {
        $found = count($offenders[$key] ?? []);

        if ($found !== $entry['picks']) {
            $stale[] = $key.' (allows '.$entry['picks'].', found '.$found.')';
        }
    }

    expect($stale)->toBe([], implode("\n  ", [
        'An entry admits a fixed number of picks, in both directions: one added to an allowed file pushes',
        'the count past its entry, and one that went away leaves the entry excusing something that is not',
        'there. Update the count, or delete the entry:',
        ...$stale,
    ]));
});

// The KNOWN DIVERGENT entries are a baseline, not a licence: each is a read
// whose two devices disagree today, named in the doc page with what fixing it
// needs. The count may fall. It may not rise.
it('does not let the known-divergent baseline grow', function (): void {
    $known = array_keys(array_filter(
        PICK_ORDER_ALLOWED,
        static fn (array $entry): bool => str_starts_with($entry['why'], 'KNOWN DIVERGENT'),
    ));

    expect(count($known))->toBe(
        2,
        'Two files on this tree pick a row out of a tie on an id the device counts for itself, each '
        .'costed in an-ordering-that-picks.md. The count may fall — delete a line here when one is '
        .'fixed. It may not rise: a third is one more screen that disagrees with the phone beside it.'
        ."\n  ".implode("\n  ", $known),
    );
});

it('gives every allow-list entry a reason somebody can act on', function (): void {
    $thin = [];

    foreach (PICK_ORDER_ALLOWED as $key => $entry) {
        if (strlen($entry['why']) < 80) {
            $thin[] = $key;
        }
    }

    expect($thin)->toBe([], implode("\n  ", [
        'An allow-list entry is a claim under review, and a claim nobody can act on is a waiver. Say what',
        'makes the id harmless here, or say KNOWN DIVERGENT and add it to the baseline. Too thin:',
        ...$thin,
    ]));
});

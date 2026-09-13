<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\SonarSourceFiles;

/**
 * @link ../../.docs/architecture/an-ordering-that-picks.md
 */

// `transactions.id` and `counterparties.id` are per-device autoincrements and
// `recurring_series_occurrences.id` is device-minted, so an ORDER BY ending on
// one ranks a tie one way here and the other way there. Whether that matters
// is decided by what the query does next: an ordering that WALKS every row
// visits the same set whatever the sequence, while an ordering that PICKS —
// bounded by limit(), first(), value(), sole(), a paginator, a ranking window,
// or a walk whose callback can stop it — makes the tie decide which rows the
// reader is shown at all.

const PICK_ORDER_SUBJECT_TABLES = ['transactions', 'counterparties', 'recurring_series_occurrences'];

// The Eloquent spelling of the same read, mapped rather than resolved so the
// scanner stays a lexer.
const PICK_ORDER_SUBJECT_MODELS = [
    'Transaction' => 'transactions',
    'Counterparty' => 'counterparties',
    'RecurringSeriesOccurrence' => 'recurring_series_occurrences',
];

// The calls that turn an ordering into a choice. A bare `get()` is absent on
// purpose: it walks.
const PICK_ORDER_BOUNDS = [
    'limit', 'take', 'first', 'firstOrFail', 'firstOr', 'value', 'sole', 'soleOrFail',
    'paginate', 'simplePaginate', 'cursorPaginate',
];

// `chunkById()` and its family walk — ordering by id is how several of them
// page safely — but only while the callback cannot stop them. One declaring a
// `bool` return can, and a walk stopped at the Nth row is a cut taken in an
// order each device numbers its own way.
const PICK_ORDER_WALK_CALLS = ['each', 'eachById', 'chunk', 'chunkById', 'lazy', 'lazyById'];

// The calls after which the value is no longer a builder. Without this a
// `$rows = $query->get()` followed by the Collection's own `take()` reads as a
// second cut on the query.
const PICK_ORDER_TERMINALS = [
    'get', 'pluck', 'cursor', 'each', 'eachById', 'chunk', 'chunkById', 'lazy', 'lazyById',
    'first', 'firstOrFail', 'firstOr', 'value', 'sole', 'soleOrFail',
    'paginate', 'simplePaginate', 'cursorPaginate',
    'count', 'exists', 'doesntExist', 'sum', 'avg', 'max', 'min',
    'insert', 'update', 'delete', 'implode',
];

const PICK_ORDER_CALLS = ['orderBy', 'orderByDesc', 'orderByRaw', 'latest', 'oldest'];

// A `const NAME = 'value'` declaration reached through the token stream rather
// than a pattern, because the clause this rule is about is assembled from two
// of them: `ACROSS_ACCOUNTS` ends on `self::ACCOUNT.'.iban desc'`.
const PICK_ORDER_FILE_FLOOR = 1_000;

// What this scanner cannot follow, recorded here so its silence is read as a
// boundary rather than as coverage. Each is a way a builder leaves the reach
// of a lexer that resolves names it can see spelled out.
/**
 * @link ../../.docs/architecture/an-ordering-that-picks.md#what-the-scanner-still-cannot-follow
 */
const PICK_ORDER_UNREACHED = [
    'a builder handed to a closure the scanner cannot resolve — `$applyFilters($query)`, `->tap($fn)`',
    'a builder stored on a property and ordered from another method through `$this->query`',
    'a builder passed into another object\'s constructor and ordered from inside it',
    'a table named by a variable — `->table($table)` — which no lexer can resolve to one name',
    'an ordering assembled from a variable rather than a literal or a class constant',
];

// Keyed `path::table`, carrying how many picks in that file end on a bare id
// and WHY each is admitted. The count is exact in both directions: a pick
// added to an allowed file pushes past the entry, and an entry that stops
// matching fails too, so the list cannot rot into a blanket exemption.
const PICK_ORDER_ALLOWED = [
    'Modules/Categorization/Public/Services/UncategorizedTriageQuery.php::transactions' => [
        'picks' => 1,
        'why' => 'A WALK across its pages. The `id` tail is required by the keyset comparison the batch pages against — TransactionCursor::apply() compares `(posted_at, id) < (?, ?)` — and ordering one way while comparing the other repeats or skips a row at every page boundary. The reader works through every uncategorized row; which page a row falls on can differ between devices, which rows exist cannot.',
    ],
    'Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php::transactions' => [
        'picks' => 2,
        'why' => 'KNOWN DIVERGENT, but NOT the shape DuplicateChargeDetector was: both candidate SETS already agree between devices — neither arm carries an id in its WHERE, and the fuzzy arm\'s `id <> $rowId` excludes the anchor itself, which each device names correctly. Only the sequence diverges, so this one IS an ORDER BY change: a distance term, then NewestTransactionFirst::ACROSS_ACCOUNTS joined through ::ACCOUNT, in both arms. Until then the cut at 20 and the two post-SQL picks past it (the alias arm stops at the first two IBAN matches, the fuzzy arm keeps the first candidate at a tied score, `> $bestScore`) answer differently here and there. ChainLinkInsertHelper MINTS the link id and chain_links_pair_uq is UNIQUE(user, from, to, kind), so two answers do not merge: the pair holds two links out of one PayPal expense, which is the one-transaction-in-two-chains the arms\' own `existing.id` exclusion exists to prevent.',
    ],
    'Modules/Ledger/Internal/Services/CounterpartyKeyProvenance.php::transactions' => [
        'picks' => 1,
        'why' => 'A probe, not a read for a screen: it asks whether ANY stored digest reproduces under a candidate key, so which rows the sample holds cannot change the answer. Ordering by id is how it takes a cheap, repeatable sample of this device\'s own file.',
    ],
    'Modules/Ledger/Public/Services/SplitSumHealthCheck.php::transactions' => [
        'picks' => 1,
        'why' => 'A diagnostic that reports at most N ids of rows whose legs disagree with their parent. The ids it prints ARE this device\'s ids — that is what the reader is being handed — so a peer agreeing on the order would say nothing.',
    ],
    'Modules/Ledger/Public/Services/TransactionListQuery.php::transactions' => [
        'picks' => 2,
        'why' => 'A WALK across its pages, in both reads. baseQuery() takes its ordering from TransactionCursor::orderNewestFirst() and each caller caps at $limit + 1, so the cap is a page rather than a cut, and the `id` tail is the other half of the row-value comparison `(posted_at, id) < (?, ?)` those pages are fetched with. Changing the clause without changing the cursor repeats or skips a row at every boundary.',
    ],
    'Modules/Search/Public/Services/SearchQuery.php::transactions' => [
        'picks' => 1,
        'why' => 'KNOWN DIVERGENT. search() itself walks its pages like the two above, but palette() calls it with no cursor and a limit of five and never pages: the command palette\'s five hits are page one read as the whole answer, so a tie on a DATE puts different hits in front of each device. Not a clause swap — the same builder feeds the paged list, whose cursor compares on the id — so fixing it means the keyset cursor carrying NewestTransactionFirst::KEY_ACROSS_ACCOUNTS rather than `(posted_at, id)`, across the three modules that page on it and the Livewire state that holds the pair.',
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

/**
 * The variables named inside one call's argument list, in order, so a builder
 * handed to a helper can be found again at the call site.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 * @return list<string>
 */
function pickOrderArgumentVariables(array $tokens, array $brackets, int $open): array
{
    $close = $brackets[$open] ?? $open;
    $variables = [];

    for ($at = $open + 1; $at < $close; $at++) {
        if ($tokens[$at][0] === T_VARIABLE) {
            $variables[] = $tokens[$at][1];
        }
    }

    return $variables;
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
            'window' => stripos($head, 'over (') !== false || stripos($head, 'over(') !== false,
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
    $last = trim(PatternScan::replace('/\s+(asc|desc)\s*$/i', '', $last));
    $dot = strrpos($last, '.');

    return strtolower($dot === false ? $last : substr($last, $dot + 1));
}

/**
 * @return array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}
 */
function pickOrderNothing(): array
{
    return ['table' => null, 'terms' => [], 'bounded' => [], 'fromParam' => null, 'done' => false];
}

/**
 * Ordering terms carried out of a helper answer for the line that called it,
 * not the line that spelled them: a clause reached through four call sites is
 * four findings, each naming the read it decides.
 *
 * @param  list<array{0:string,1:int}>  $terms
 * @return list<array{0:string,1:int}>
 */
function pickOrderRestamp(array $terms, int $line): array
{
    return array_map(static fn (array $term): array => [$term[0], $line], $terms);
}

/**
 * @param  array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}  $first
 * @param  array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}  $second
 * @return array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}
 */
function pickOrderMerge(array $first, array $second): array
{
    return [
        'table' => $first['table'] ?? $second['table'],
        'terms' => array_merge($first['terms'], $second['terms']),
        'bounded' => array_merge($first['bounded'], $second['bounded']),
        'fromParam' => $first['fromParam'] ?? $second['fromParam'],
        'done' => $first['done'] || $second['done'],
    ];
}

// A `): bool` on a callback inside a chunking call. Laravel stops the walk
// when one returns false, so the callback is where the cut is spelled and the
// chain carries no sign of it.
/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 */
function pickOrderStoppableCallback(array $tokens, array $brackets, int $open): bool
{
    $close = $brackets[$open] ?? $open;

    for ($at = $open + 1; $at < $close; $at++) {
        if (($tokens[$at][1] ?? null) === ':'
            && ($tokens[$at + 1][1] ?? null) === 'bool'
            && ($tokens[$at - 1][1] ?? null) === ')') {
            return true;
        }
    }

    return false;
}

/**
 * `private Foo $bar`, promoted or declared, so `$this->bar->order($query)`
 * names a class the helper index is keyed by.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @return array<string, string>
 */
function pickOrderPropertyTypes(array $tokens): array
{
    $types = [];
    $count = count($tokens);

    for ($at = 0; $at < $count; $at++) {
        if (! in_array($tokens[$at][0], [T_PRIVATE, T_PROTECTED, T_PUBLIC], true)) {
            continue;
        }

        $named = $at + 1;

        while ($named < $count && in_array($tokens[$named][0], [T_READONLY, T_STATIC], true)) {
            $named++;
        }

        if (($tokens[$named][1] ?? null) === '?') {
            $named++;
        }

        if (! in_array($tokens[$named][0] ?? null, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        if (($tokens[$named + 1][0] ?? null) !== T_VARIABLE) {
            continue;
        }

        $types[$tokens[$named + 1][1]] = (string) (array_slice(explode('\\', $tokens[$named][1]), -1)[0] ?? '');
    }

    return $types;
}

// A method name may be a reserved word — `for`, `list`, `print`, `match` —
// and its token is then the keyword's rather than T_STRING. Reading only
// T_STRING made UncategorizedTriageQuery::for() structurally invisible.
/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 * @return array<string, array{start:int,end:int,params:list<string>}>
 */
function pickOrderMethods(array $tokens, array $brackets): array
{
    $methods = [];
    $count = count($tokens);

    for ($at = 0; $at < $count; $at++) {
        if ($tokens[$at][0] !== T_FUNCTION) {
            continue;
        }

        $named = ($tokens[$at + 1][1] ?? null) === '&' ? $at + 2 : $at + 1;
        $text = $tokens[$named][1] ?? '';

        if (($tokens[$named][0] ?? null) === null || ($tokens[$named + 1][1] ?? null) !== '(') {
            continue;
        }

        if ($text === '' || ! (ctype_alpha($text[0]) || $text[0] === '_')) {
            continue;
        }

        $open = $named + 1;
        $close = $brackets[$open] ?? $open;
        $params = [];

        for ($inside = $open + 1; $inside < $close; $inside++) {
            if ($tokens[$inside][0] === T_VARIABLE) {
                $params[] = $tokens[$inside][1];
            }
        }

        $body = $close + 1;

        while ($body < $count && ($tokens[$body][1] ?? null) !== '{' && ($tokens[$body][1] ?? null) !== ';') {
            $body++;
        }

        if (($tokens[$body][1] ?? null) !== '{') {
            continue;
        }

        $methods[$text] = ['start' => $body + 1, 'end' => $brackets[$body] ?? $body, 'params' => $params];
    }

    return $methods;
}

/**
 * Statement boundaries inside a range, split at `;`, `{` and `}` reached at
 * parenthesis depth zero — so a closure's own statements stay inside the call
 * that carries it, and a `foreach` body does not.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @return list<array{0:int,1:int}>
 */
function pickOrderStatements(array $tokens, int $start, int $end): array
{
    $statements = [];
    $depth = 0;
    $from = $start;

    for ($at = $start; $at < $end; $at++) {
        $token = $tokens[$at];

        if ($token[0] === null && in_array($token[1], ['(', '['], true)) {
            $depth++;

            continue;
        }

        if ($token[0] === null && in_array($token[1], [')', ']'], true)) {
            $depth--;

            continue;
        }

        if ($depth === 0 && $token[0] === null && in_array($token[1], [';', '{', '}'], true)) {
            if ($at > $from) {
                $statements[] = [$from, $at];
            }

            $from = $at + 1;
        }
    }

    if ($end > $from) {
        $statements[] = [$from, $end];
    }

    return $statements;
}

/**
 * What a call to `$class::$method(...)` hands back, given the variables at the
 * call site: a builder it returns unchanged carries the caller's value on.
 *
 * @param  list<string>  $arguments
 * @param  array<string, array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}>  $values
 * @param  array{constants:array<string,string>,helpers:array<string,mixed>,returns:array<string,mixed>}  $index
 * @return array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}
 */
function pickOrderCalledValue(string $class, string $method, array $arguments, array $values, int $line, array $index): array
{
    /** @var array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}|null $known */
    $known = $index['returns'][$class.'::'.$method] ?? null;

    if ($known === null) {
        return pickOrderNothing();
    }

    $from = $known['fromParam'];
    $carried = $from !== null && isset($arguments[$from]) && isset($values[$arguments[$from]])
        ? $values[$arguments[$from]]
        : pickOrderNothing();

    return pickOrderMerge($carried, [
        'table' => $known['table'],
        'terms' => pickOrderRestamp($known['terms'], $line),
        'bounded' => $known['bounded'],
        'fromParam' => null,
        'done' => $known['done'],
    ]);
}

const PICK_ORDER_STATEMENT_HEADS = [
    T_RETURN, T_CLONE, T_YIELD, T_FOREACH, T_IF, T_ELSEIF, T_WHILE, T_SWITCH, T_ECHO, T_PRINT, T_MATCH, T_THROW,
];

/**
 * One expression's builder value: where it opens, what it orders by, and
 * whether anything cuts it. The root may be a variable, a local method, a
 * static call, or a call through a typed property — which is what makes a pick
 * spelled across two methods visible at all.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 * @param  array<string, array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}>  $values
 * @param  array<string, string>  $properties
 * @param  array{constants:array<string,string>,helpers:array<string,mixed>,returns:array<string,mixed>}  $index
 * @return array{0:array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool},1:string|null}
 */
function pickOrderEvaluate(array $tokens, array $brackets, int $at, int $stop, array $values, string $self, array $properties, array $index): array
{
    $count = min($stop, count($tokens));
    $value = pickOrderNothing();
    $baseVariable = null;

    while ($at < $count && (in_array($tokens[$at][0], PICK_ORDER_STATEMENT_HEADS, true)
        || ($tokens[$at][0] === null && in_array($tokens[$at][1], ['(', '!'], true)))) {
        $at++;
    }

    [$root, $after] = pickOrderRootCall($tokens, $brackets, $at, $count, $properties);

    if ($root !== null) {
        $class = $root[0] === '' ? $self : $root[0];
        $value = isset(PICK_ORDER_SUBJECT_MODELS[$class])
            ? pickOrderMerge(['table' => PICK_ORDER_SUBJECT_MODELS[$class], 'terms' => [], 'bounded' => [], 'fromParam' => null, 'done' => false], pickOrderNothing())
            : pickOrderCalledValue($class, $root[1], pickOrderArgumentVariables($tokens, $brackets, $root[2]), $values, $root[3], $index);
        $at = $after;
    } elseif ($at < $count && $tokens[$at][0] === T_VARIABLE && $tokens[$at][1] !== '$this') {
        $baseVariable = $tokens[$at][1];
        $value = $values[$baseVariable] ?? pickOrderNothing();
        $at++;
    }

    $depth = 0;

    for (; $at < $count; $at++) {
        $token = $tokens[$at];

        if ($token[0] === null && in_array($token[1], ['(', '[', '{'], true)) {
            $depth++;

            continue;
        }

        if ($token[0] === null && in_array($token[1], [')', ']', '}'], true)) {
            $depth--;

            // A `(clone $query)->…` opens its chain inside a parenthesis the
            // scanner did not enter, so the close is not the chain's end.
            if ($depth < 0 && in_array($tokens[$at + 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $depth = 0;

                continue;
            }

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

        if (! in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }

        $name = $tokens[$at + 1] ?? null;

        if ($name === null || $name[0] !== T_STRING || $value['done']) {
            continue;
        }

        $open = ($tokens[$at + 2][1] ?? null) === '(' ? $at + 2 : null;
        $value = pickOrderApplyCall($value, $tokens, $brackets, $name, $open, $self, $index);
    }

    return [$value, $baseVariable];
}

/**
 * The class, method, argument position and line of a chain opening on a call
 * rather than on a variable, or null when it opens on neither.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 * @param  array<string, string>  $properties
 * @return array{0:array{0:string,1:string,2:int,3:int}|null,1:int}
 */
function pickOrderRootCall(array $tokens, array $brackets, int $at, int $count, array $properties): array
{
    $isThis = $at < $count && $tokens[$at][0] === T_VARIABLE && $tokens[$at][1] === '$this'
        && ($tokens[$at + 1][0] ?? null) === T_OBJECT_OPERATOR && ($tokens[$at + 2][0] ?? null) === T_STRING;

    if ($isThis && ($tokens[$at + 3][0] ?? null) === T_OBJECT_OPERATOR && ($tokens[$at + 4][0] ?? null) === T_STRING
        && ($tokens[$at + 5][1] ?? null) === '(' && isset($properties['$'.$tokens[$at + 2][1]])) {
        return [[$properties['$'.$tokens[$at + 2][1]], $tokens[$at + 4][1], $at + 5, $tokens[$at + 4][2]], ($brackets[$at + 5] ?? $at + 5) + 1];
    }

    if ($isThis && ($tokens[$at + 3][1] ?? null) === '(') {
        return [['', $tokens[$at + 2][1], $at + 3, $tokens[$at + 2][2]], ($brackets[$at + 3] ?? $at + 3) + 1];
    }

    $isStatic = $at < $count && in_array($tokens[$at][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
        && ($tokens[$at + 1][0] ?? null) === T_DOUBLE_COLON && ($tokens[$at + 2][0] ?? null) === T_STRING
        && ($tokens[$at + 3][1] ?? null) === '(';

    if ($isStatic) {
        $class = (string) (array_slice(explode('\\', $tokens[$at][1]), -1)[0] ?? '');

        return [[$class, $tokens[$at + 2][1], $at + 3, $tokens[$at + 2][2]], ($brackets[$at + 3] ?? $at + 3) + 1];
    }

    return [null, $at];
}

/**
 * One `->call(...)` folded into the builder value it was reached on.
 *
 * @param  array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}  $value
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 * @param  array{0:int|null,1:string,2:int}  $name
 * @param  array{constants:array<string,string>,helpers:array<string,mixed>,returns:array<string,mixed>}  $index
 * @return array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}
 */
function pickOrderApplyCall(array $value, array $tokens, array $brackets, array $name, ?int $open, string $self, array $index): array
{
    $arguments = $open === null ? [] : pickOrderArguments($tokens, $brackets, $open, $self, $index['constants']);

    if (in_array($name[1], ['table', 'from'], true) && $arguments !== []) {
        $named = explode(' ', trim($arguments[0]))[0];

        if (in_array($named, PICK_ORDER_SUBJECT_TABLES, true)) {
            $value['table'] = $named;
        }
    }

    if ($name[1] === 'reorder') {
        $value['terms'] = [];
    }

    if (in_array($name[1], ['latest', 'oldest'], true)) {
        $value['terms'][] = [$arguments[0] ?? 'created_at', $name[2]];
    } elseif (in_array($name[1], PICK_ORDER_CALLS, true) && $arguments !== []) {
        $value['terms'][] = [$arguments[0], $name[2]];
    }

    foreach ($arguments as $argument) {
        foreach (pickOrderRawClauses($argument) as $raw) {
            $value['terms'][] = [$raw['clause'], $name[2]];

            if ($raw['window'] || $raw['limited']) {
                $value['bounded'][] = [$raw['window'] ? 'a window rank' : 'a raw LIMIT', $name[2]];
            }
        }
    }

    if (in_array($name[1], PICK_ORDER_BOUNDS, true)) {
        $value['bounded'][] = [$name[1], $name[2]];
    }

    if ($open !== null && in_array($name[1], PICK_ORDER_WALK_CALLS, true) && pickOrderStoppableCallback($tokens, $brackets, $open)) {
        $value['bounded'][] = ['a callback that stops '.$name[1].'()', $name[2]];
    }

    if (in_array($name[1], PICK_ORDER_TERMINALS, true)) {
        $value['done'] = true;
    }

    return $value;
}

/**
 * Each method's statements, plus the statements outside every method as a
 * scope of their own — a snippet that declares no class is still a read, and
 * a walk that only enters method bodies never sees one.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 * @return array<string, array{params:list<string>,statements:list<array{0:int,1:int}>}>
 */
function pickOrderScopes(array $tokens, array $brackets): array
{
    $methods = pickOrderMethods($tokens, $brackets);
    $scopes = [];

    foreach ($methods as $name => $method) {
        $scopes[$name] = [
            'params' => $method['params'],
            'statements' => pickOrderStatements($tokens, $method['start'], $method['end']),
        ];
    }

    $outside = [];

    foreach (pickOrderStatements($tokens, 0, count($tokens)) as $statement) {
        foreach ($methods as $method) {
            if ($statement[0] >= $method['start'] && $statement[0] < $method['end']) {
                continue 2;
            }
        }

        $outside[] = $statement;
    }

    if ($outside !== []) {
        $scopes['(file)'] = ['params' => [], 'statements' => $outside];
    }

    return $scopes;
}

/**
 * Every pick one source declares, plus what it learned about the methods in it
 * — which of them order a builder handed in, and which hand one back — folded
 * into the shared index so the next file can read a chain that crosses into
 * this one.
 *
 * @param  array{constants:array<string,string>,helpers:array<string,mixed>,returns:array<string,mixed>}  $index
 * @return list<array{line:int,table:string,last:string,how:string}>
 */
function pickOrderAnalyze(string $source, string $self, array &$index, bool $report): array
{
    $tokens = SonarSourceFiles::tokens($source);
    $brackets = SonarSourceFiles::brackets($tokens);
    $properties = pickOrderPropertyTypes($tokens);
    $scopes = pickOrderScopes($tokens, $brackets);
    $found = [];

    // Three rounds, because a method may be spelled below the one that calls
    // it and the caller's verdict is read off the callee's.
    for ($round = 0; $round < 3; $round++) {
        $last = $round === 2 && $report;

        foreach ($scopes as $name => $scope) {
            $values = [];

            foreach ($scope['params'] as $parameter) {
                $values[$parameter] = pickOrderNothing();
            }

            $returned = pickOrderNothing();

            foreach ($scope['statements'] as [$from, $to]) {
                $values = pickOrderApplyHelpers($tokens, $brackets, $from, $to, $values, $self, $properties, $index, $last, $found);

                $assignTo = null;
                $at = $from;

                if ($tokens[$from][0] === T_VARIABLE && ($tokens[$from + 1][1] ?? null) === '=') {
                    $assignTo = $tokens[$from][1];
                    $at = $from + 2;
                }

                [$value, $baseVariable] = pickOrderEvaluate($tokens, $brackets, $at, $to, $values, $self, $properties, $index);

                if ($assignTo !== null) {
                    $values[$assignTo] = $value;
                } elseif ($baseVariable !== null) {
                    $values[$baseVariable] = $value;
                }

                if (($tokens[$at][0] ?? null) === T_RETURN) {
                    $position = $baseVariable === null ? false : array_search($baseVariable, $scope['params'], true);
                    $returned = pickOrderMerge($returned, $value);
                    $returned['fromParam'] = $position === false ? $returned['fromParam'] : $position;
                }

                if ($last) {
                    pickOrderCollect($found, $value);
                }
            }

            foreach ($scope['params'] as $position => $parameter) {
                if ($values[$parameter]['terms'] !== [] || $values[$parameter]['bounded'] !== []) {
                    $index['helpers'][$self.'::'.$name.'#'.$position] = $values[$parameter];
                }
            }

            if ($returned !== pickOrderNothing()) {
                $index['returns'][$self.'::'.$name] = $returned;
            }

            if ($last) {
                foreach ($values as $value) {
                    pickOrderCollect($found, $value);
                }

                pickOrderCollect($found, $returned);
            }
        }
    }

    return array_values($found);
}

/**
 * A builder handed to a method that orders it comes back ordered, so the
 * caller's value carries what the callee left on it.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 * @param  array<string, array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}>  $values
 * @param  array<string, string>  $properties
 * @param  array{constants:array<string,string>,helpers:array<string,mixed>,returns:array<string,mixed>}  $index
 * @param  array<string, array{line:int,table:string,last:string,how:string}>  $found
 * @return array<string, array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}>
 */
function pickOrderApplyHelpers(array $tokens, array $brackets, int $from, int $to, array $values, string $self, array $properties, array $index, bool $report, array &$found): array
{
    for ($at = $from; $at < $to; $at++) {
        [$root] = pickOrderRootCall($tokens, $brackets, $at, $to, $properties);

        if ($root === null) {
            continue;
        }

        $class = $root[0] === '' ? $self : $root[0];

        foreach (pickOrderArgumentVariables($tokens, $brackets, $root[2]) as $position => $variable) {
            /** @var array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}|null $helper */
            $helper = $index['helpers'][$class.'::'.$root[1].'#'.$position] ?? null;

            if ($helper === null || ! isset($values[$variable])) {
                continue;
            }

            $values[$variable] = pickOrderMerge($values[$variable], [
                'table' => $helper['table'],
                'terms' => pickOrderRestamp($helper['terms'], $root[3]),
                'bounded' => $helper['bounded'],
                'fromParam' => null,
                'done' => false,
            ]);

            if ($report) {
                pickOrderCollect($found, $values[$variable]);
            }
        }
    }

    return $values;
}

/**
 * @param  array<string, array{line:int,table:string,last:string,how:string}>  $found
 * @param  array{table:string|null,terms:list<array{0:string,1:int}>,bounded:list<array{0:string,1:int}>,fromParam:int|null,done:bool}  $value
 */
function pickOrderCollect(array &$found, array $value): void
{
    if ($value['table'] === null || $value['terms'] === [] || $value['bounded'] === []) {
        return;
    }

    $last = pickOrderLastTerm(implode(', ', array_column($value['terms'], 0)));
    $line = $value['terms'][count($value['terms']) - 1][1];
    $key = $value['table'].'|'.$last.'|'.$line;
    $how = array_unique(array_merge(
        $key === '' ? [] : explode('; ', $found[$key]['how'] ?? ''),
        array_column($value['bounded'], 0),
    ));

    $found[$key] = [
        'line' => $line,
        'table' => $value['table'],
        'last' => $last,
        'how' => implode('; ', array_filter($how, static fn (string $one): bool => $one !== '')),
    ];
}

/**
 * The shared index, built by reading the whole tree twice: once so every
 * method's answer is on record, once more so a chain crossing two files reads
 * the second file's answer rather than an empty one.
 *
 * @return array{constants:array<string,string>,helpers:array<string,mixed>,returns:array<string,mixed>}
 */
function pickOrderIndex(): array
{
    static $index = null;

    if ($index !== null) {
        return $index;
    }

    $index = ['constants' => pickOrderConstantIndex(), 'helpers' => [], 'returns' => []];

    for ($pass = 0; $pass < 2; $pass++) {
        foreach (SonarSourceFiles::all() as $path) {
            pickOrderAnalyze((string) file_get_contents($path), basename($path, '.php'), $index, false);
        }
    }

    return $index;
}

/**
 * Every ordering in one source that PICKS against a subject table, with the
 * column it ends on. The tree's index is read but not written, so scanning a
 * snippet cannot teach the guard anything about the tree.
 *
 * @return list<array{line:int,table:string,last:string,how:string}>
 */
function pickOrderScan(string $source, string $self = 'Probe'): array
{
    $index = pickOrderIndex();

    return pickOrderAnalyze($source, $self, $index, true);
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

    $index = pickOrderIndex();
    $picks = [];

    foreach (SonarSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (pickOrderAnalyze((string) file_get_contents($path), basename($path, '.php'), $index, true) as $hit) {
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

// The shape this guard was blind to when it shipped: one method returns the
// builder, another paginates it, and neither token chain holds both halves.
// Reconstructed from CashBookPage as it stood before the ordering was fixed.
it('reads a pick spelled across two methods', function (): void {
    $parted = <<<'PHP'
        <?php
        final class CashBookProbe
        {
            public function render(Connection $connection, int $userId): void
            {
                $entries = $this->manualEntriesQuery($connection, $userId)->paginate(perPage: 25);
            }

            private function manualEntriesQuery(Connection $connection, int $userId): Builder
            {
                return $connection->table('transactions as t')
                    ->where('t.user_id', $userId)
                    ->orderByDesc('t.id');
            }
        }
        PHP;

    $joined = <<<'PHP'
        <?php
        final class CashBookProbe
        {
            public function render(Connection $connection, int $userId): void
            {
                $entries = $this->manualEntriesQuery($connection, $userId)->paginate(perPage: 25);
            }

            private function manualEntriesQuery(Connection $connection, int $userId): Builder
            {
                return $connection->table('transactions as t')
                    ->join('accounts as charge_account', 'charge_account.id', '=', 't.account_id')
                    ->where('t.user_id', $userId)
                    ->orderByRaw('posted_at desc, charge_account.iban desc');
            }
        }
        PHP;

    expect(pickOrderScan($parted, 'CashBookProbe'))->toHaveCount(1)
        ->and(pickOrderScan($parted, 'CashBookProbe')[0]['last'])->toBe('id')
        ->and(pickOrderScan($parted, 'CashBookProbe')[0]['how'])->toBe('paginate')
        ->and(pickOrderScan($joined, 'CashBookProbe')[0]['last'])->toBe(
            'iban',
            'the same two-method read is still seen; what changes is the column it ends on',
        );
});

// A walk is only a walk while nothing can stop it. `each()` given a callback
// that returns bool stops on false, and that cut is spelled in the callback
// rather than in the chain.
it('reads a walk whose callback can stop it as a cut', function (): void {
    $stoppable = <<<'PHP'
        <?php
        $db->connection()->table('transactions')
            ->orderBy('id')
            ->each(function (object $row) use (&$ids): bool {
                $ids[] = $row->id;

                return count($ids) < 10;
            });
        PHP;

    $unstoppable = <<<'PHP'
        <?php
        $db->connection()->table('transactions')
            ->orderBy('id')
            ->each(function (object $row) use (&$ids): void {
                $ids[] = $row->id;
            });
        PHP;

    expect(pickOrderScan($stoppable))->toHaveCount(1)
        ->and(pickOrderScan($stoppable)[0]['last'])->toBe('id')
        ->and(pickOrderScan($stoppable)[0]['how'])->toBe('a callback that stops each()')
        ->and(pickOrderScan($unstoppable))->toBe([], 'a void callback cannot stop the walk, so every row is visited');
});

// The cross-FILE reach, named rather than counted for the same reason the
// constant control is: an ordering supplied by another module's helper reads
// as "no ordering" when the index misses it, and that is what a fixed read
// reads as too.
it('still reads an ordering supplied by another file', function (): void {
    expect(pickOrderIndex()['helpers']['TransactionCursor::orderNewestFirst#0']['terms'] ?? null)
        ->not->toBe(null, 'TransactionCursor spells the ordering four reads in three modules rank on; losing it blinds all four.');

    expect(pickOrderLastTermsAt('Modules/Search/Public/Services/SearchQuery.php::transactions'))->toBe(['id'])
        ->and(pickOrderLastTermsAt('Modules/Ledger/Public/Services/TransactionListQuery.php::transactions'))->toBe(['id', 'id'])
        ->and(pickOrderLastTermsAt('Modules/Categorization/Public/Services/UncategorizedTriageQuery.php::transactions'))->toBe(['id']);

    expect(pickOrderLastTermsAt('Modules/CashBook/Internal/Http/Livewire/CashBookPage.php::transactions'))->toBe(
        ['iban', 'iban'],
        'The read this guard was blind to when it shipped, on the real tree rather than in a heredoc: '
        .'manualEntriesQuery() returns the builder and render() paginates it, twice.',
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
// needs. Named rather than counted, so widening the scanner's reach cannot
// slip a new one in under a number that moved for an honest reason.
it('does not let the known-divergent baseline grow', function (): void {
    $known = array_keys(array_filter(
        PICK_ORDER_ALLOWED,
        static fn (array $entry): bool => str_starts_with($entry['why'], 'KNOWN DIVERGENT'),
    ));

    expect($known)->toBe([
        'Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php::transactions',
        'Modules/Search/Public/Services/SearchQuery.php::transactions',
    ], implode("\n  ", [
        'Two files on this tree pick a row out of a tie on an id the device counts for itself, each',
        'costed in an-ordering-that-picks.md. The list may shrink — delete a line here when one is fixed.',
        'It may not grow: a third is one more screen that disagrees with the phone beside it.',
        'Names rather than a count, so a number that moves because the scanner reached further cannot',
        'carry a new one in beside the ones it was moved for.',
    ]));
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

// A scanner that cannot follow something and does not say so is read as one
// that found nothing there. These are the ways a builder leaves this lexer's
// reach; each is spelled in the doc page with what it would take to close it.
it('states what it cannot follow rather than letting silence stand for coverage', function (): void {
    expect(PICK_ORDER_UNREACHED)->not->toBe([])
        ->and(count(PICK_ORDER_UNREACHED))->toBe(5);

    foreach (PICK_ORDER_UNREACHED as $limitation) {
        expect(strlen($limitation))->toBeGreaterThan(
            40,
            'A limitation nobody can picture is not a limitation anybody can close: '.$limitation,
        );
    }
});

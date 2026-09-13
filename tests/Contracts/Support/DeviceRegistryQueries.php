<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Modules\Core\Public\Support\PatternScan;

// Every statement in the shipped tree that names the device_registry table, and
// whether it says anything about a retired row.
//
// The retirement stamp arrived with one reader taught about it, which is how the
// gap it was added to close stayed open: a row kept confirmed so a rebuild can
// verify what it signed was still, to fifty other queries, a device. Two answers
// are both right and they are opposite, so neither can be the default — a query
// that filters the stamp out of a history check makes a restored ledger
// unverifiable, and one that leaves it in a peer list hands a session to a
// machine that is gone.
//
// Read with the tokeniser rather than with a pattern: the table is named in
// prose all over this module, and half of that prose exists to say which map
// does NOT reach it.
final class DeviceRegistryQueries
{
    public const string TABLE = 'device_registry';

    // A statement that narrows to the self row cannot reach a retired one:
    // the retirement's whole mechanism is taking is_self off. That is a
    // decision the schema makes rather than one a reader has to restate.
    private const string NARROWED_TO_SELF = "/'is_self'\\s*(?:,|=>)\\s*(?:1|true)\\b/i";

    private const string RETIREMENT_MARKER = 'self_retired_at';

    private const string RETIREMENT_SEAM = 'stillADevice';

    // `table(...)` and `from(...)` are the two ways a query builder is opened
    // on a name. A model-backed read never spells the table at all, so it was
    // never collected here and nothing is lost by requiring one of these.
    private const string OPENED_ON_THE_TABLE = "/\\b(?:table|from)\\(\\s*['\"]device_registry['\"]/";

    /**
     * @return list<array{path: string, function: string, statement: string, decides: bool}>
     */
    public static function all(): array
    {
        $found = [];

        foreach (RepoTree::files(RepoTree::RUNTIME_DOMAIN_PHP) as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, self::TABLE)) {
                continue;
            }

            $relative = str_replace(RepoTree::root().'/', '', $path);

            foreach (self::in($relative, $source) as $statement) {
                $found[] = $statement;
            }
        }

        return $found;
    }

    /**
     * @return list<array{path: string, function: string, statement: string, decides: bool}>
     */
    public static function in(string $path, string $source): array
    {
        $tokens = BackendSourceFiles::tokensOf($path, $source);
        $text = array_map(static fn (array|string $token): string => is_array($token) ? $token[1] : $token, $tokens);

        $found = [];

        foreach ($text as $index => $literal) {
            if ($literal !== "'".self::TABLE."'" && $literal !== '"'.self::TABLE.'"') {
                continue;
            }

            $statement = QueryStatements::around($text, $index);

            if (! self::isAQuerySite($statement)) {
                continue;
            }

            $found[] = [
                'path' => $path,
                'function' => QueryStatements::functionAround($tokens, $text, $index),
                'statement' => $statement,
                'decides' => self::decides($statement),
            ];
        }

        return $found;
    }

    // The name has to be the argument a BUILDER was opened on, not merely a
    // string somewhere in the statement. A refusal, a log line or a schema
    // question names this table too, and reading those as undecided queries
    // makes asking the question the thing the rule refuses.
    private static function isAQuerySite(string $statement): bool
    {
        return PatternScan::matches(self::OPENED_ON_THE_TABLE, $statement);
    }

    // Three spellings of one decision. The marker itself is the direct answer;
    // the seam is the registry's name for it; and narrowing to the self row
    // answers structurally, because the stamp and is_self are written together.
    public static function decides(string $statement): bool
    {
        return str_contains($statement, self::RETIREMENT_MARKER)
            || str_contains($statement, self::RETIREMENT_SEAM)
            || PatternScan::matches(self::NARROWED_TO_SELF, $statement);
    }

    public static function keyFor(string $path, string $function): string
    {
        return $path.'::'.$function;
    }
}

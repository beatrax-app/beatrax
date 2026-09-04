<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Modules\Core\Public\Support\PatternScan;

// Reads a write payload the way the column will: what is the VALUE under this
// key, and is any part of it a sentence somebody typed in English rather than
// a machine word, a key or the user's own text.
final class StoredSentenceShape
{
    // How many payload keys the last call read. The rule above sums it and
    // refuses a clean answer from a walk that read nothing.
    public static int $keysSeen = 0;

    // The calls that put a payload into a table. A value that never reaches
    // one is a label held in memory for the length of a request, and no reader
    // opens it in a language other than the one that built it.
    private const array WRITE_CALLS = [
        'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing',
        'update', 'updateOrInsert', 'updateOrCreate',
        'upsert', 'create', 'forceCreate', 'firstOrCreate', 'fill',
    ];

    // A lang file and a blade are text by definition and neither is a payload.
    // A demo seeder writes the demo user's own DATA — a merchant, a note they
    // would have typed — which is the same text in every language, exactly
    // like the real rows beside it.
    public static function isPayloadFile(string $relative): bool
    {
        return ! str_contains($relative, '/Resources/lang/')
            && ! str_contains($relative, '/Database/Seeders/Demo/')
            && ! str_ends_with($relative, '.blade.php');
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $keys
     * @return array<int, string> line number => the offending literal
     */
    public static function sentencesUnderKeys(array $tokens, array $keys): array
    {
        self::$keysSeen = 0;
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }

            $valueAt = match (true) {
                $token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($token[1], "'\""), $keys, true) => self::afterArrow($tokens, $i),
                $token[0] === T_STRING && in_array($token[1], $keys, true) => self::afterNamedArgument($tokens, $i),
                default => null,
            };

            if ($valueAt === null) {
                continue;
            }

            self::$keysSeen++;

            $sentence = self::sentenceInValue($tokens, $valueAt);
            if ($sentence !== null) {
                $found[$token[2]] = trim($token[1], "'\"").' => '.$sentence;
            }
        }

        return $found;
    }

    // Does this file put a payload into a table at all? The gate is the file
    // rather than the statement because a writer that reaches the column
    // through a private recorder — `recordDriftAlert(message: ...)` — hands its
    // sentence over one hop before the insert, and that is the shape.
    public static function writesToATable(string $source): bool
    {
        foreach (self::WRITE_CALLS as $call) {
            if (PatternScan::matches('/(?:->|\?->|::)'.$call.'\s*\(/', $source)) {
                return true;
            }
        }

        return false;
    }

    /** @return string|null the first part of the literal that reads as authored prose */
    public static function readsAsASentence(string $literal): ?string
    {
        $text = trim(trim($literal), "'\"");
        if ($text === '' || str_contains($text, '\\')) {
            return null;
        }

        // A key, a slug, a column, an enum value, a route, a format string:
        // all lower case with no letters standing apart as words.
        if (PatternScan::matches('/^[a-z0-9_.:\/|%-]+$/', $text)) {
            return null;
        }

        // Trimmed with a Unicode class rather than trim()'s byte list: a
        // guillemet or an em dash is several bytes, and trim() cuts one in
        // half — which made every later match on the fragment answer false.
        $words = 0;
        foreach (preg_split('/[\s\x{00A0}]+/u', $text) ?: [] as $part) {
            $bare = (string) preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $part);
            if (PatternScan::matches('/^[A-Za-z][A-Za-z\'’-]+$/u', $bare)) {
                $words++;
            }
        }

        // Two words of letters is a phrase somebody wrote. One word plus the
        // punctuation a label leads into — "Goal: " — is the same thing with
        // its second half in a variable; one bare word is a brand, a code or a
        // heading the caller cannot be told apart from either.
        $punctuated = PatternScan::matches('/[:—–(]/u', $text);

        if ($words >= 2 || ($words === 1 && $punctuated && mb_strlen($text) >= 4)) {
            return '"'.$text.'"';
        }

        return null;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return int|null the index of the value expression, or null when this is not a keyed write
     */
    private static function afterArrow(array $tokens, int $index): ?int
    {
        $next = self::significant($tokens, $index + 1, 1);

        return $next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_ARROW
            ? $next + 1
            : null;
    }

    // `label: 'x'` in a call, told apart from a ternary's colon and a match
    // arm's by what stands in FRONT of the name: only an argument list opens
    // one with `(` or `,`.
    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     */
    private static function afterNamedArgument(array $tokens, int $index): ?int
    {
        $next = self::significant($tokens, $index + 1, 1);
        if ($next === null || $tokens[$next] !== ':') {
            return null;
        }

        $previous = self::significant($tokens, $index - 1, -1);
        if ($previous === null) {
            return null;
        }

        return in_array($tokens[$previous], ['(', ','], true) ? $next + 1 : null;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return string|null the first literal in this value expression that reads as prose
     */
    private static function sentenceInValue(array $tokens, int $start): ?string
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                if (in_array($token, ['(', '[', '{'], true)) {
                    $depth++;

                    continue;
                }
                if (in_array($token, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        return null;
                    }
                    $depth--;

                    continue;
                }
                if ($depth === 0 && in_array($token, [',', ';'], true)) {
                    return null;
                }

                continue;
            }

            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $sentence = self::readsAsASentence($token[1]);
                if ($sentence !== null) {
                    return $sentence;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     */
    private static function significant(array $tokens, int $from, int $step): ?int
    {
        for ($i = $from; $i >= 0 && $i < count($tokens); $i += $step) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if (trim($text) !== '') {
                return $i;
            }
        }

        return null;
    }
}

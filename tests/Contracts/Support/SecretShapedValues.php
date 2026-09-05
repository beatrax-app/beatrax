<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Modules\Core\Public\Support\PatternScan;

// gitleaks' own detection order, in the order it runs it: a keyword prefilter
// over the lowercased file, the rule's own path and file allowlists, the
// pattern, the capture group the secret is read from, the entropy floor, and
// then the two allowlist passes — global first, the rule's own second. The
// order is load-bearing rather than incidental: entropy is measured on the
// capture group and not on the match, and a stopword is tested against the
// secret even when a regex allowlist is aimed at the line.
/**
 * @link ../../../.docs/conventions/invariants-from-shipped-failures.md#a-test-fixture-shaped-like-a-secret
 */
final class SecretShapedValues
{
    /**
     * A delimiter no gitleaks pattern contains, so a pattern reaches PCRE with
     * nothing escaped. Escaping `/` would corrupt the many patterns that
     * already spell `\/` themselves.
     */
    private const string DELIMITER = "\x01";

    /**
     * @return list<array{rule: string, secret: string, line: int}>
     */
    public static function in(string $path, string $source): array
    {
        return self::findings($path, $source, GitleaksRuleset::globalAllowlists());
    }

    /**
     * The one pattern-to-PCRE conversion, exposed so the guard can assert every
     * vendored pattern compiles rather than discovering a dead rule the day it
     * was supposed to fire.
     */
    public static function pattern(string $regex): string
    {
        return self::DELIMITER.$regex.self::DELIMITER;
    }

    /**
     * The same scan with this repository's own exemptions withheld, so a rule
     * can ask whether an entry in `.gitleaks.toml` still describes a file that
     * would fail without it. An exemption nothing needs any more is an
     * exclusion nobody is auditing.
     *
     * @return list<array{rule: string, secret: string, line: int}>
     */
    public static function despiteRepositoryExemptions(string $path, string $source): array
    {
        return self::findings($path, $source, GitleaksRuleset::vendoredAllowlists());
    }

    /**
     * @param  list<array<string, mixed>>  $globalAllowlists
     * @return list<array{rule: string, secret: string, line: int}>
     */
    private static function findings(string $path, string $source, array $globalAllowlists): array
    {
        if (self::fileAllowed($path, $globalAllowlists)) {
            return [];
        }

        $lowercased = strtolower($source);
        $found = [];

        foreach (GitleaksRuleset::rules() as $rule) {
            if (! self::applies($rule, $path, $lowercased)) {
                continue;
            }

            $found = [...$found, ...self::matchesOf($rule, $path, $source, $globalAllowlists)];
        }

        return self::withoutSupersededGenerics($found);
    }

    /**
     * A named vendor rule beats the generic one on the same line: `sk_live_…`
     * is one finding rather than two. Reproduced rather than skipped, because
     * the guard names the rule that fired and a contributor sent to
     * `generic-api-key` for a Stripe key would go looking for the wrong shape.
     *
     * @param  list<array{rule: string, secret: string, line: int}>  $findings
     * @return list<array{rule: string, secret: string, line: int}>
     */
    private static function withoutSupersededGenerics(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static function (array $finding) use ($findings): bool {
                if (! str_contains(strtolower($finding['rule']), 'generic')) {
                    return true;
                }

                foreach ($findings as $other) {
                    if ($other['line'] === $finding['line']
                        && $other['rule'] !== $finding['rule']
                        && ! str_contains(strtolower($other['rule']), 'generic')
                        && str_contains($other['secret'], $finding['secret'])) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private static function applies(array $rule, string $path, string $lowercased): bool
    {
        /** @var list<string> $keywords */
        $keywords = $rule['keywords'];

        if ($keywords !== [] && ! self::anyKeyword($keywords, $lowercased)) {
            return false;
        }

        /** @var list<array<string, mixed>> $allowlists */
        $allowlists = $rule['allowlists'];

        if (self::fileAllowed($path, $allowlists)) {
            return false;
        }

        // A rule that names a path is answered by the path first: gitleaks
        // returns before it reads the file at all when the two disagree.
        return ! is_string($rule['path']) || self::anyMatch([$rule['path']], $path);
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<array<string, mixed>>  $globalAllowlists
     * @return list<array{rule: string, secret: string, line: int}>
     */
    private static function matchesOf(array $rule, string $path, string $source, array $globalAllowlists): array
    {
        /** @var string $id */
        $id = $rule['id'];

        // A path-only rule — `pkcs12-file` is the whole of that category — is
        // answered by `applies()` having let the path through, and has no
        // content to read a secret out of.
        if (! is_string($rule['regex'])) {
            return is_string($rule['path']) ? [['rule' => $id, 'secret' => $path, 'line' => 1]] : [];
        }

        $pattern = self::pattern($rule['regex']);
        $findings = [];

        foreach (PatternScan::setsWithOffsets($pattern, $source) as $set) {
            [$whole, $offset] = $set[0];
            $match = trim($whole, "\n");
            $secret = self::secretIn($pattern, $match, (int) $rule['secretGroup']);
            $line = self::lineAround($source, $offset, strlen($match));

            if (str_contains($line, 'gitleaks'.':allow')) {
                continue;
            }

            $entropy = (float) $rule['entropy'];

            if ($entropy !== 0.0 && self::entropyOf($secret) <= $entropy) {
                continue;
            }

            /** @var list<array<string, mixed>> $allowlists */
            $allowlists = $rule['allowlists'];

            if (self::findingAllowed($globalAllowlists, $path, $match, $secret, $line)
                || self::findingAllowed($allowlists, $path, $match, $secret, $line)) {
                continue;
            }

            $findings[] = ['rule' => $id, 'secret' => $secret, 'line' => substr_count(substr($source, 0, $offset), "\n") + 1];
        }

        return $findings;
    }

    private static function entropyOf(string $secret): float
    {
        $length = strlen($secret);

        if ($length === 0) {
            return 0.0;
        }

        $entropy = 0.0;

        foreach (count_chars($secret, 1) as $occurrences) {
            $frequency = $occurrences / $length;
            $entropy -= $frequency * log($frequency, 2);
        }

        return $entropy;
    }

    /**
     * gitleaks re-reads the groups off the trimmed match rather than off the
     * original one, and takes the first group that captured anything unless the
     * rule names one.
     */
    private static function secretIn(string $pattern, string $match, int $secretGroup): string
    {
        $groups = PatternScan::first($pattern, $match);

        if (count($groups) < 2) {
            return $match;
        }

        if ($secretGroup > 0) {
            return isset($groups[$secretGroup]) ? (string) $groups[$secretGroup] : $match;
        }

        foreach (array_slice($groups, 1) as $group) {
            if ((string) $group !== '') {
                return (string) $group;
            }
        }

        return $match;
    }

    private static function lineAround(string $source, int $offset, int $length): string
    {
        $start = strrpos(substr($source, 0, $offset), "\n");
        $end = strpos($source, "\n", $offset + $length);

        return substr(
            $source,
            $start === false ? 0 : $start + 1,
            ($end === false ? strlen($source) : $end) - ($start === false ? 0 : $start + 1),
        );
    }

    /**
     * @param  list<string>  $keywords
     */
    private static function anyKeyword(array $keywords, string $lowercased): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($lowercased, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function anyMatch(array $patterns, string $subject): bool
    {
        if ($subject === '') {
            return false;
        }

        foreach ($patterns as $candidate) {
            if (PatternScan::matches(self::pattern($candidate), $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $stopwords
     */
    private static function anyStopword(array $stopwords, string $secret): bool
    {
        $lowercased = strtolower($secret);

        foreach ($stopwords as $stopword) {
            if ($lowercased !== '' && str_contains($lowercased, strtolower($stopword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The file-level pass. An `AND` allowlist that also carries regexes or
     * stopwords is deferred here rather than decided, because those halves can
     * only be answered once there is a finding to answer them about.
     *
     * @param  list<array<string, mixed>>  $allowlists
     */
    private static function fileAllowed(string $path, array $allowlists): bool
    {
        foreach ($allowlists as $allowlist) {
            /** @var list<string> $paths */
            $paths = $allowlist['paths'];
            $matched = $paths !== [] && self::anyMatch($paths, $path);

            if ($allowlist['condition'] !== 'AND') {
                if ($matched) {
                    return true;
                }

                continue;
            }

            if ($allowlist['regexes'] === [] && $allowlist['stopwords'] === [] && $matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $allowlists
     */
    private static function findingAllowed(array $allowlists, string $path, string $match, string $secret, string $line): bool
    {
        foreach ($allowlists as $allowlist) {
            /** @var list<string> $regexes */
            $regexes = $allowlist['regexes'];
            /** @var list<string> $stopwords */
            $stopwords = $allowlist['stopwords'];
            /** @var list<string> $paths */
            $paths = $allowlist['paths'];

            $target = match ($allowlist['regexTarget']) {
                'match' => $match,
                'line' => $line,
                default => $secret,
            };

            $byRegex = $regexes !== [] && self::anyMatch($regexes, $target);
            $byStopword = $stopwords !== [] && self::anyStopword($stopwords, $secret);

            if ($allowlist['condition'] !== 'AND') {
                if ($byRegex || $byStopword) {
                    return true;
                }

                continue;
            }

            $checks = [];

            if ($paths !== []) {
                $checks[] = self::anyMatch($paths, $path);
            }

            if ($regexes !== []) {
                $checks[] = $byRegex;
            }

            if ($stopwords !== []) {
                $checks[] = $byStopword;
            }

            if (! in_array(false, $checks, true)) {
                return true;
            }
        }

        return false;
    }
}

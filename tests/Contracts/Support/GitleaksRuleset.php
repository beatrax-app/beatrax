<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use RuntimeException;

// The repository's own .gitleaks.toml declares `useDefault = true` and no rules
// of its own, so the ruleset the gate applies is the one compiled into the
// gitleaks binary the shared workflow downloads. That file is vendored here
// verbatim at the pinned version, because a guard cannot read a ruleset that
// exists only inside a Go binary CI fetches at run time.
/**
 * @link ../../../.docs/conventions/invariants-from-shipped-failures.md#a-test-fixture-shaped-like-a-secret
 */
final class GitleaksRuleset
{
    /**
     * The version `beatrax-app/spec/.github/workflows/security.yml` downloads.
     * The vendored file is `config/gitleaks.toml` at that tag, unmodified.
     */
    public const string VERSION = '8.30.1';

    /**
     * Kept under its upstream basename on purpose: `gitleaks\.toml` is a path
     * in gitleaks' own global allowlist, so a scan of this tree skips the
     * ruleset the same way it skips the configuration at the repository root.
     */
    public const string VENDORED = 'tests/Contracts/Fixtures/gitleaks/v8.30.1/gitleaks.toml';

    public const string REPOSITORY_CONFIG = '.gitleaks.toml';

    /** @var ?array<string, mixed> */
    private static ?array $defaults = null;

    /** @var ?list<array<string, mixed>> */
    private static ?array $globalAllowlists = null;

    /**
     * @return list<array<string, mixed>>
     */
    public static function rules(): array
    {
        /** @var list<array<string, mixed>> */
        return self::defaults()['rules'];
    }

    /**
     * Both halves of what the gate exempts: the default config's own global
     * allowlist — images, lock files, vendored dependency trees — and the paths
     * this repository adds on top of it.
     *
     * @return list<array<string, mixed>>
     */
    public static function globalAllowlists(): array
    {
        return self::$globalAllowlists ??= [...self::vendoredAllowlists(), ...self::repositoryAllowlists()];
    }

    /**
     * Upstream's own exemptions — images, fonts, lock files, vendored
     * dependency trees — and nothing this repository added.
     *
     * @return list<array<string, mixed>>
     */
    public static function vendoredAllowlists(): array
    {
        /** @var list<array<string, mixed>> */
        return self::defaults()['allowlist'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function repositoryAllowlists(): array
    {
        /** @var list<array<string, mixed>> */
        return self::read(self::REPOSITORY_CONFIG)['allowlist'];
    }

    /**
     * The one claim every rule below rests on. `useDefault = false`, or a
     * `[[rules]]` block in the repository config, would mean the gate is
     * applying a ruleset the vendored file does not describe.
     */
    public static function repositoryConfigExtendsTheDefaults(): bool
    {
        $config = self::read(self::REPOSITORY_CONFIG);

        /** @var array<string, string> $extend */
        $extend = $config['extend'];

        return ($extend['useDefault'] ?? '') === 'true' && $config['rules'] === [];
    }

    /**
     * @return array{rules: list<array<string, mixed>>, allowlist: list<array<string, mixed>>, extend: array<string, string>}
     */
    public static function read(string $relativePath): array
    {
        $path = base_path($relativePath);

        if (! is_file($path)) {
            throw new RuntimeException('the gitleaks configuration is missing: '.$relativePath);
        }

        return self::assemble(self::tables((string) file_get_contents($path)), $relativePath);
    }

    /**
     * @param  list<array{name: string, keys: array<string, string|list<string>>}>  $tables
     * @return array{rules: list<array<string, mixed>>, allowlist: list<array<string, mixed>>, extend: array<string, string>}
     */
    private static function assemble(array $tables, string $relativePath): array
    {
        $rules = [];
        $allowlist = [];
        $extend = [];

        foreach ($tables as $table) {
            match ($table['name']) {
                'rules' => $rules[] = self::rule($table['keys'], $relativePath),
                'rules.allowlists' => $rules[self::lastRuleIndex($rules, $relativePath)]['allowlists'][] = self::allowlist($table['keys']),
                'allowlist', 'allowlists' => $allowlist[] = self::allowlist($table['keys']),
                'extend' => $extend = array_filter($table['keys'], is_string(...)),
                default => null,
            };
        }

        return ['rules' => $rules, 'allowlist' => $allowlist, 'extend' => $extend];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private static function lastRuleIndex(array $rules, string $relativePath): int
    {
        if ($rules === []) {
            throw new RuntimeException('a [[rules.allowlists]] block precedes every rule in '.$relativePath.' — the reader is misreading the file');
        }

        return count($rules) - 1;
    }

    /**
     * @param  array<string, string|list<string>>  $keys
     * @return array<string, mixed>
     */
    private static function rule(array $keys, string $relativePath): array
    {
        $id = $keys['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('a [[rules]] block in '.$relativePath.' declares no id — the reader is misreading the file');
        }

        return [
            'id' => $id,
            'regex' => self::text($keys, 'regex'),
            'path' => self::text($keys, 'path'),
            'entropy' => (float) (self::text($keys, 'entropy') ?? '0'),
            'keywords' => self::items($keys, 'keywords'),
            'secretGroup' => (int) (self::text($keys, 'secretGroup') ?? '0'),
            'allowlists' => [],
        ];
    }

    /**
     * @param  array<string, string|list<string>>  $keys
     * @return array<string, mixed>
     */
    private static function allowlist(array $keys): array
    {
        return [
            'condition' => strtoupper(self::text($keys, 'condition') ?? 'OR'),
            'regexTarget' => self::text($keys, 'regexTarget') ?? '',
            'regexes' => self::items($keys, 'regexes'),
            'paths' => self::items($keys, 'paths'),
            'stopwords' => self::items($keys, 'stopwords'),
        ];
    }

    /**
     * @param  array<string, string|list<string>>  $keys
     */
    private static function text(array $keys, string $key): ?string
    {
        $value = $keys[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, string|list<string>>  $keys
     * @return list<string>
     */
    private static function items(array $keys, string $key): array
    {
        $value = $keys[$key] ?? [];

        return is_array($value) ? $value : [$value];
    }

    /**
     * The subset of TOML these two files are written in: table and
     * array-of-table headers, and keys whose value is a number, a bare `true`,
     * a quoted string, or a list of quoted strings written inline or one per
     * line. Nothing here reaches for a general parser, so the callers above
     * raise rather than shrug when a block comes out without the keys it must
     * have, and the guard asserts the shape of the whole parse before reading a
     * verdict off it.
     *
     * @return list<array{name: string, keys: array<string, string|list<string>>}>
     */
    private static function tables(string $source): array
    {
        $lines = explode("\n", $source);
        $tables = [];
        $open = null;
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line = trim($lines[$index]);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $header = self::headerIn($line);

            if ($header !== null) {
                if ($open !== null) {
                    $tables[] = $open;
                }

                $open = ['name' => $header, 'keys' => []];

                continue;
            }

            $separator = strpos($line, '=');

            if ($separator === false || $open === null) {
                continue;
            }

            $open['keys'][trim(substr($line, 0, $separator))] = self::value(substr($line, $separator + 1), $lines, $index);
        }

        if ($open !== null) {
            $tables[] = $open;
        }

        return $tables;
    }

    private static function headerIn(string $line): ?string
    {
        if (str_starts_with($line, '[[') && str_ends_with($line, ']]')) {
            return substr($line, 2, -2);
        }

        return str_starts_with($line, '[') && str_ends_with($line, ']') ? substr($line, 1, -1) : null;
    }

    /**
     * @param  list<string>  $lines
     * @return string|list<string>
     */
    private static function value(string $rest, array $lines, int &$index): string|array
    {
        $rest = trim($rest);

        if (! str_starts_with($rest, '[')) {
            $literals = self::literalsIn($rest);

            return $literals === [] ? $rest : $literals[0];
        }

        $collected = self::literalsIn($rest);

        while (str_starts_with($rest, '#') || ! str_ends_with($rest, ']')) {
            $index++;

            if (! isset($lines[$index])) {
                throw new RuntimeException('an unterminated list in the gitleaks configuration — the reader is misreading the file');
            }

            $rest = trim($lines[$index]);

            if (! str_starts_with($rest, '#')) {
                $collected = [...$collected, ...self::literalsIn($rest)];
            }
        }

        return $collected;
    }

    /**
     * Every quoted run in a line, in order. A literal is consumed whole, so the
     * quote characters inside a `'''…'''` pattern — and there are many — read as
     * the pattern rather than as a second literal.
     *
     * @return list<string>
     */
    private static function literalsIn(string $text): array
    {
        $literals = [];
        $length = strlen($text);
        $cursor = 0;

        while ($cursor < $length) {
            $character = $text[$cursor];

            if ($character === "'") {
                $delimiter = str_starts_with(substr($text, $cursor), "'''") ? "'''" : "'";
                $close = strpos($text, $delimiter, $cursor + strlen($delimiter));

                if ($close === false) {
                    break;
                }

                $literals[] = substr($text, $cursor + strlen($delimiter), $close - $cursor - strlen($delimiter));
                $cursor = $close + strlen($delimiter);

                continue;
            }

            if ($character !== '"') {
                $cursor++;

                continue;
            }

            [$literal, $cursor] = self::basicStringAt($text, $cursor);

            if ($literal === null) {
                break;
            }

            $literals[] = $literal;
        }

        return $literals;
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private static function basicStringAt(string $text, int $start): array
    {
        $escapes = ['\\' => '\\', '"' => '"', 'n' => "\n", 'r' => "\r", 't' => "\t"];
        $literal = '';
        $cursor = $start + 1;
        $length = strlen($text);

        while ($cursor < $length) {
            $character = $text[$cursor];

            if ($character === '"') {
                return [$literal, $cursor + 1];
            }

            if ($character === '\\' && $cursor + 1 < $length) {
                $literal .= $escapes[$text[$cursor + 1]] ?? $text[$cursor + 1];
                $cursor += 2;

                continue;
            }

            $literal .= $character;
            $cursor++;
        }

        return [null, $length];
    }

    /**
     * @return array{rules: list<array<string, mixed>>, allowlist: list<array<string, mixed>>, extend: array<string, string>}
     */
    private static function defaults(): array
    {
        return self::$defaults ??= self::read(self::VENDORED);
    }
}

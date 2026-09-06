<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/00-index.md
 */

// mobile-app/ is a second Composer root over this same checkout: Modules/,
// app/, resources/, routes/ and tests/ are symlinks pointing back here. They
// are pruned at the branch rather than resolved and de-duplicated, because
// scanning through one reports every shared file a second time under a second
// spelling — two offender lines for one comment. Pruned alongside them is
// everything under that root nobody writes by hand.
const COMMENT_POLICY_MOBILE_PRUNED_DIRECTORIES = [
    'vendor', 'node_modules', 'nativephp', 'ios', 'android',
    'build', 'build-secrets', 'credentials', 'storage', 'cache', '.phpunit.cache',
];

// Run from the mobile Composer root instead of this one, base_path('mobile-app')
// does not exist and this yields nothing — correctly, because that root reaches
// Modules/ and app/ through its own symlinks and they are already in scope.
/**
 * @param  list<string>  $prunedDirectories
 * @return list<string>
 */
function commentPolicyMobilePhpFiles(array $prunedDirectories): array
{
    $root = base_path('mobile-app');

    return is_dir($root)
        ? commentPolicyWalkPhp($root, array_merge(COMMENT_POLICY_MOBILE_PRUNED_DIRECTORIES, $prunedDirectories))
        : [];
}

/**
 * @param  list<string>  $prunedDirectories
 * @return list<string>
 */
function commentPolicyWalkPhp(string $directory, array $prunedDirectories): array
{
    $files = [];
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;
        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            if (! in_array($entry, $prunedDirectories, true)) {
                $files = array_merge($files, commentPolicyWalkPhp($path, $prunedDirectories));
            }

            continue;
        }

        if (str_ends_with($path, '.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

/** @return list<string> absolute paths to in-scope backend PHP files */
function commentPolicyBackendFiles(): array
{
    $roots = [base_path('Modules'), base_path('app')];
    $files = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    // config/ and scripts/ are style-exempt at the desktop root for the reason
    // below, so the mobile root's own copies are held to the same line. The
    // identifier walk picks them back up.
    foreach (commentPolicyMobilePhpFiles(['config', 'scripts']) as $path) {
        $files[] = $path;
    }
    sort($files);

    return $files;
}

// The identifier ban reaches further than the style rules: config/, routes/ and
// scripts/ carry no class the boundary rules care about, but they carry
// comments, and a requirement id was hiding in config/nativephp.php precisely
// because nothing looked there. The style rules stay off them deliberately — a
// build hook under scripts/ is a standalone file whose header block is the only
// documentation it has, and M3 and M4 would forbid that block outright.
/** @return list<string> absolute paths to every PHP file the identifier ban covers */
function commentPolicyIdentifierFiles(): array
{
    $files = commentPolicyBackendFiles();
    foreach ([base_path('config'), base_path('routes'), base_path('scripts')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && str_ends_with($path, '.php')) {
                $files[] = $path;
            }
        }
    }
    foreach (commentPolicyMobilePhpFiles([]) as $path) {
        $files[] = $path;
    }
    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

/** @return list<string> absolute paths to every in-scope Blade template */
function commentPolicyBladeFiles(): array
{
    $roots = [base_path('Modules'), base_path('resources')];
    $files = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.blade.php')) {
                continue;
            }
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

/** @return list<string> absolute paths to every repo-owned JS and CSS source */
function commentPolicyScriptFiles(): array
{
    $files = [];
    foreach ([base_path('resources/js'), base_path('resources/css'), base_path('build')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && preg_match('/\.(?:js|mjs|css)$/', $path) === 1) {
                $files[] = $path;
            }
        }
    }
    if (is_file(base_path('vite.config.js'))) {
        $files[] = base_path('vite.config.js');
    }
    sort($files);

    return $files;
}

/** @return list<string> absolute paths to every NEON, XML and YAML config the ban covers */
function commentPolicyConfigFiles(): array
{
    $files = [];
    foreach (['*.neon', '*.xml', '*.yml'] as $pattern) {
        foreach (glob(base_path($pattern)) ?: [] as $path) {
            $files[] = $path;
        }
    }
    if (is_file(base_path('mobile-app/phpunit.xml'))) {
        $files[] = base_path('mobile-app/phpunit.xml');
    }
    if (is_dir(base_path('.github'))) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('.github'), RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && preg_match('/\.ya?ml$/', $path) === 1) {
                $files[] = $path;
            }
        }
    }
    sort($files);

    return $files;
}

// A Blade comment is T_INLINE_HTML to the PHP tokeniser, so the token-based
// passes below cannot see one. It has to be lifted out of the raw source — and
// so does everything inside an @php, <?php or <script> island, whose `//` and
// `/* */` comments are inline HTML for exactly the same reason. Gating only the
// {{-- --}} form left three dozen identifiers sitting in the other half of the
// same files.
/** @return list<array{line: int, text: string}> every comment in a Blade file */
function commentPolicyBladeComments(string $path): array
{
    $source = (string) file_get_contents($path);
    $comments = [];

    $matches = PatternScan::allWithOffsets('/\{\{--.*?--\}\}/s', $source);

    /** @var array{0: string, 1: int} $match */
    foreach ($matches[0] as $match) {
        $comments[] = [
            'line' => substr_count(substr($source, 0, $match[1]), "\n") + 1,
            'text' => $match[0],
        ];
    }

    $islands = '/@php\b(?<body>.*?)@endphp|<\?php(?<php>.*?)\?>|<script\b[^>]*>(?<js>.*?)<\/script>/si';
    $matches = PatternScan::setsWithOffsets($islands, $source);

    foreach ($matches as $match) {
        foreach (['body', 'php', 'js'] as $group) {
            /** @var array{0: string, 1: int}|null $island */
            $island = $match[$group] ?? null;
            if ($island === null || $island[1] < 0) {
                continue;
            }
            $before = substr_count(substr($source, 0, $island[1]), "\n");
            foreach (commentPolicyScanComments($island[0]) as $comment) {
                $comments[] = [
                    'line' => $before + $comment['line'],
                    'text' => $comment['text'],
                ];
            }
        }
    }

    usort($comments, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

    return $comments;
}

// A slash opens a regex literal only where a value can start. After a name, a
// number or a closing bracket it is division — `(a + b) / c` is not a regex.
const COMMENT_POLICY_REGEX_KEYWORDS = [
    'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void',
    'throw', 'case', 'do', 'else', 'yield', 'await',
];

// A `'` or `"` run that reaches the end of its line is a misread rather than a
// string, so the caller re-reads the quote as an ordinary character. NEON and
// YAML escape a quote by doubling it and take no backslash in a single-quoted
// run, which is why the escaping rule is a parameter.
/** @return int the offset just past the closing quote, or -1 if the run never closed */
function commentPolicyQuotedEnd(string $source, int $start, bool $backslashEscapes = true): int
{
    $quote = $source[$start];
    $length = strlen($source);
    for ($i = $start + 1; $i < $length; $i++) {
        $char = $source[$i];
        if ($char === '\\' && ($backslashEscapes || $quote === '"')) {
            $i++;

            continue;
        }
        if ($char === $quote) {
            if (! $backslashEscapes && $quote === "'" && ($source[$i + 1] ?? '') === "'") {
                $i++;

                continue;
            }

            return $i + 1;
        }
        if ($char === "\n" && $quote !== '`') {
            return -1;
        }
    }

    return -1;
}

// A regex literal never spans a line, and an unescaped `/` inside `[...]` does
// not close one — `/[^/]+/` is a whole literal, not two.
/** @return int the offset just past the closing slash, or -1 if the literal never closed */
function commentPolicyRegexEnd(string $source, int $start): int
{
    $length = strlen($source);
    $inClass = false;
    for ($i = $start + 1; $i < $length; $i++) {
        $char = $source[$i];
        if ($char === '\\') {
            $i++;

            continue;
        }
        if ($char === "\n") {
            return -1;
        }
        if ($char === '[') {
            $inClass = true;
        } elseif ($char === ']') {
            $inClass = false;
        } elseif ($char === '/' && ! $inClass) {
            return $i + 1;
        }
    }

    return -1;
}

// Neither JS nor CSS goes through token_get_all, and the naive `//` match this
// pocket was left ungated for trips on `https://` inside a string and on a
// regex literal like `/\//g`, whose middle two characters are a `//`. This
// walks the source instead — skipping strings, template literals and regex
// bodies — so a slash only opens a comment where one can actually open. CSS has
// neither a `//` comment nor a regex literal, so neither is recognised there.
/** @return list<array{line: int, text: string}> every comment in a JS or CSS file */
function commentPolicyScriptComments(string $path): array
{
    return commentPolicyScanComments((string) file_get_contents($path), str_ends_with($path, '.css'));
}

/** @return list<array{line: int, text: string}> every comment in JS, CSS or PHP source text */
function commentPolicyScanComments(string $source, bool $isCss = false): array
{
    $length = strlen($source);
    $comments = [];
    $line = 1;
    $offset = 0;
    $previous = '';
    $previousWord = '';

    while ($offset < $length) {
        $char = $source[$offset];

        if ($char === "\n") {
            $line++;
            $offset++;

            continue;
        }
        if ($char === ' ' || $char === "\t" || $char === "\r") {
            $offset++;

            continue;
        }

        $next = $source[$offset + 1] ?? '';

        if ($char === '/' && $next === '*') {
            $end = strpos($source, '*/', $offset + 2);
            $end = $end === false ? $length : $end + 2;
            $text = substr($source, $offset, $end - $offset);
            $comments[] = ['line' => $line, 'text' => $text];
            $line += substr_count($text, "\n");
            $offset = $end;

            continue;
        }

        if ($char === '/' && $next === '/' && ! $isCss) {
            $end = strpos($source, "\n", $offset);
            $end = $end === false ? $length : $end;
            $comments[] = ['line' => $line, 'text' => substr($source, $offset, $end - $offset)];
            $offset = $end;

            continue;
        }

        if ($char === "'" || $char === '"' || ($char === '`' && ! $isCss)) {
            $end = commentPolicyQuotedEnd($source, $offset);
            if ($end !== -1) {
                $line += substr_count(substr($source, $offset, $end - $offset), "\n");
                $offset = $end;
                $previous = $char;
                $previousWord = '';

                continue;
            }
        }

        if ($char === '/' && ! $isCss && ($previous === ''
            || str_contains('(,=:[!&|?{};+-*/%^~<>', $previous)
            || in_array($previousWord, COMMENT_POLICY_REGEX_KEYWORDS, true))) {
            $end = commentPolicyRegexEnd($source, $offset);
            if ($end !== -1) {
                $offset = $end;
                $previous = '/';
                $previousWord = '';

                continue;
            }
        }

        if (preg_match('/\G[A-Za-z_$][A-Za-z0-9_$]*/', $source, $match, 0, $offset) === 1) {
            $previousWord = $match[0];
            $previous = substr($match[0], -1);
            $offset += strlen($match[0]);

            continue;
        }

        $previous = $char;
        $previousWord = '';
        $offset++;
    }

    return $comments;
}

// `#` opens a comment in NEON and YAML only outside a quoted run and only after
// a line start or whitespace. Without the first half every `'#…#'` regex
// delimiter in phpstan.neon reads as a comment; without the second, so does the
// `#` in a URL fragment.
/** @return list<array{line: int, text: string}> every # comment in a NEON or YAML source */
function commentPolicyHashComments(string $source): array
{
    $length = strlen($source);
    $comments = [];
    $line = 1;
    $offset = 0;
    $opensComment = true;

    while ($offset < $length) {
        $char = $source[$offset];

        if ($char === "\n") {
            $line++;
            $offset++;
            $opensComment = true;

            continue;
        }

        if ($char === '#' && $opensComment) {
            $end = strpos($source, "\n", $offset);
            $end = $end === false ? $length : $end;
            $comments[] = ['line' => $line, 'text' => substr($source, $offset, $end - $offset)];
            $offset = $end;

            continue;
        }

        if ($char === "'" || $char === '"') {
            $end = commentPolicyQuotedEnd($source, $offset, backslashEscapes: false);
            if ($end !== -1) {
                $offset = $end;
                $opensComment = false;

                continue;
            }
        }

        $opensComment = $char === ' ' || $char === "\t";
        $offset++;
    }

    return $comments;
}

/** @return list<array{line: int, text: string}> every comment in a NEON, XML or YAML config */
function commentPolicyConfigComments(string $path): array
{
    $source = (string) file_get_contents($path);

    if (! str_ends_with($path, '.xml')) {
        return commentPolicyHashComments($source);
    }

    $matches = PatternScan::allWithOffsets('/<!--.*?-->/s', $source);

    $comments = [];
    /** @var array{0: string, 1: int} $match */
    foreach ($matches[0] as $match) {
        $comments[] = [
            'line' => substr_count(substr($source, 0, $match[1]), "\n") + 1,
            'text' => $match[0],
        ];
    }

    return $comments;
}

// M1 and M2 are a floor and a ceiling on the same measurement, so they read it
// through one function. Two copies of the grouping would be two chances for the
// floor and the ceiling to disagree about where a block begins.
/** @return list<list<int>> the line numbers of each contiguous // comment block */
function commentPolicyLineCommentBlocks(string $path): array
{
    $lines = [];
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && $token[0] === T_COMMENT && str_starts_with(ltrim($token[1]), '//')
            && ! commentPolicyIsDirective($token[1])) {
            $lines[] = $token[2];
        }
    }
    sort($lines);

    $blocks = [];
    $block = [];
    foreach ($lines as $line) {
        if ($block !== [] && $line !== end($block) + 1) {
            $blocks[] = $block;
            $block = [];
        }
        $block[] = $line;
    }
    if ($block !== []) {
        $blocks[] = $block;
    }

    return $blocks;
}

// A directive is recognised by its punctuation, not by its first word. Matching
// the bare tool name exempted five ordinary sentences that merely opened with
// "PHPStan" — and an exemption here is silent, so those lines were invisible to
// M1 through M4 rather than reported. Every real directive is `@var`,
// `@codeCoverageIgnore`, or a tool name followed by `-` or `:`.
/** @return bool whether the comment text is a machine directive the style rules exempt */
function commentPolicyIsDirective(string $text): bool
{
    return preg_match(
        '#^\s*(?://|/\*\*?)\s*(?:@(?:var\b|codeCoverageIgnore)|@?(?:phpstan|psalm|phpcs)[-:])#i',
        $text,
    ) === 1;
}

/** @return list<string> absolute paths to every Pest test file in the repo */
function commentPolicyTestFiles(): array
{
    $roots = [base_path('Modules'), base_path('tests')];
    $files = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, 'Test.php')) {
                continue;
            }
            if (str_starts_with($path, base_path('Modules')) && ! str_contains($path, '/tests/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

// Standards names share the LETTERS-DIGITS shape a requirement id has, and a
// test that proves something about SHA-256 has to be allowed to say so. R-7 is
// the quantile definition, not a requirement, and API-28 is an Android platform
// level rather than a row in a spec. Every name here is one the walks below
// actually meet; the case at the end of this file holds the list to that, so a
// name nothing writes any more cannot sit here reading as considered.
const COMMENT_POLICY_STANDARDS_NAMES = [
    'AES-256', 'ISO-4217', 'SHA-256', 'SHA-512', 'UTF-8',
    'BCP-47', 'PSR-3', 'PSR-4', 'R-7', 'API-28',
];

// Not a name at all: a regex character class whose middle reads like an
// identifier. The exemption is the exact literal rather than a hole in the
// pattern, so a real identifier standing next to one still trips.
const COMMENT_POLICY_LITERAL_EXEMPTIONS = [
    '[A-NP-Z2-9]',
];

/** @return string the text with every standards name and exempt literal blanked out */
function commentPolicyWithoutStandards(string $text): string
{
    return str_replace(
        array_merge(COMMENT_POLICY_STANDARDS_NAMES, COMMENT_POLICY_LITERAL_EXEMPTIONS),
        '',
        $text,
    );
}

/** @return list<string> the requirement identifiers a test name carries, if any */
function commentPolicyRequirementIds(string $name): array
{
    $matches = PatternScan::all(
        COMMENT_POLICY_BANNED_TOKENS,
        commentPolicyWithoutStandards($name),
    );

    /** @var list<string> $ids */
    $ids = $matches[0];

    return $ids;
}

/** @return list<array{file: string, line: int, name: string}> */
function commentPolicyTestNames(string $path): array
{
    $tokens = token_get_all((string) file_get_contents($path));
    $names = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        if (! in_array($token[1], ['it', 'test', 'describe'], true)) {
            continue;
        }

        // `$this->it(...)` and `Foo::test(...)` are not the Pest functions.
        $prev = $tokens[$i - 1] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            continue;
        }

        if (($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }

        // Whitespace is skipped, because a long name is written on the line
        // below the `it(` and reading only the very next token made four of
        // them invisible -- one of which carried the identifier this bans.
        $at = $i + 2;

        while (is_array($tokens[$at] ?? null) && in_array($tokens[$at][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $at++;
        }

        $literal = $tokens[$at] ?? null;

        if (! is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $names[] = [
            'file' => $path,
            'line' => $literal[2],
            'name' => trim($literal[1], "'\""),
        ];
    }

    return $names;
}

// One body serves every rule below — a test name and a comment ban the same
// thing, and the two have now drifted apart twice: once on the pattern, once
// on which files they read. The shapes it must and must not catch are listed
// as data in the test under it, where they are checked rather than described.
const COMMENT_POLICY_IDENTIFIER_BODY = '\b(?:[A-Z]{2,6}\d{0,2}|[A-Z]\d{1,2})-[A-Z]{0,2}\d{1,3}\b'
    .'|\b[A-Z]-(?:[A-Z]{1,2}\d{1,3}|\d{2,3})\b';

const COMMENT_POLICY_IDENTIFIER_TOKENS = '/'.COMMENT_POLICY_IDENTIFIER_BODY.'/';

const COMMENT_POLICY_BANNED_TOKENS = '/\b(TODO|FIXME|HACK|XXX|@todo)\b'
    .'|'.COMMENT_POLICY_IDENTIFIER_BODY
    .'|(?i:\b(?:Phase|Wave|Plan|Pitfall|Req|Issue|UAT)\s+#?\d)/';

it('reads every identifier shape this repository mints and leaves the prose forms alone', function (): void {
    $identifiers = ['D-06', 'T-05-12', 'WR-11', 'GOV-R12', 'F3-R36'];
    $prose = ['N-1', 'R-7', 'SHA-256', 'BCP-47', 'API-28'];

    expect($identifiers)->not->toBe([], 'The minted-identifier probes were emptied, so this control proves nothing about the pattern.')
        ->and($prose)->not->toBe([], 'The prose probes were emptied, so nothing here proves the pattern leaves a standards name alone.');

    foreach ($identifiers as $identifier) {
        expect(PatternScan::matches(COMMENT_POLICY_IDENTIFIER_TOKENS, $identifier))
            ->toBeTrue($identifier.' is a shape this repository mints and the pattern must read it as one');
    }

    foreach ($prose as $word) {
        expect(PatternScan::matches(COMMENT_POLICY_IDENTIFIER_TOKENS, commentPolicyWithoutStandards($word)))
            ->toBeFalse($word.' is prose or a standards name and the pattern must leave it alone');
    }
});

it('has no banned deferral or provenance tokens in comments (M5)', function (): void {
    $files = commentPolicyIdentifierFiles();

    // The floor sits far under the 6,500 files the identifier ban opens.
    expect(count($files))->toBeGreaterThan(
        1000,
        'The identifier walk opened almost nothing, so no comment was read at all.'
    );

    $hits = [];
    foreach ($files as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (preg_match(COMMENT_POLICY_BANNED_TOKENS, commentPolicyWithoutStandards($token[1])) === 1) {
                $hits[] = $path.':'.$token[2];
            }
        }
    }
    expect($hits)->toBe([], "Comments must carry no TODO/ticket/phase provenance. Offenders:\n  ".implode("\n  ", $hits));
});

// A Blade file ends in .php and so was always in scope, but its comments are
// invisible to the tokeniser — which is how 250-odd of them accumulated.
it('has no banned deferral or provenance tokens in Blade comments (M5)', function (): void {
    $files = commentPolicyBladeFiles();

    // The floor sits well under the 279 templates this tree ships.
    expect(count($files))->toBeGreaterThan(
        100,
        'The Blade walk opened almost nothing, so no template comment was read at all.'
    );

    $hits = [];
    foreach ($files as $path) {
        foreach (commentPolicyBladeComments($path) as $comment) {
            if (preg_match(COMMENT_POLICY_BANNED_TOKENS, commentPolicyWithoutStandards($comment['text'])) === 1) {
                $hits[] = $path.':'.$comment['line'];
            }
        }
    }
    expect($hits)->toBe([], "A Blade comment says what the markup is for, never which requirement or phase it traces to — that covers the {{-- --}} form and the // and /* */ comments inside an @php or <script> island alike. Identifiers belong in the commit trailer and the PR body. A UI-SPEC §-section reference is a pointer into a living document and stays. Offenders:\n  ".implode("\n  ", $hits));
});

// The stylesheet and the scripts are where a UI decision gets written down, so
// they collected identifiers exactly the way Blade did and for the same reason:
// nothing looked.
it('has no banned deferral or provenance tokens in JS and CSS comments (M5)', function (): void {
    $files = commentPolicyScriptFiles();

    // Twelve scripts and stylesheets are repo-owned today.
    expect(count($files))->toBeGreaterThan(
        4,
        'The script walk opened almost nothing, so no stylesheet or script comment was read at all.'
    );

    $hits = [];
    foreach ($files as $path) {
        foreach (commentPolicyScriptComments($path) as $comment) {
            if (preg_match(COMMENT_POLICY_BANNED_TOKENS, commentPolicyWithoutStandards($comment['text'])) === 1) {
                $hits[] = $path.':'.$comment['line'];
            }
        }
    }
    expect($hits)->toBe([], "A stylesheet or script comment says what the rule or the branch is for, never which requirement or phase it traces to — identifiers belong in the commit trailer and the PR body. Offenders:\n  ".implode("\n  ", $hits));
});

// A PHPStan carve-out and a workflow job are both explained in a comment, and
// both explanations were citing requirement rows.
it('has no banned deferral or provenance tokens in config comments (M5)', function (): void {
    $files = commentPolicyConfigFiles();

    // Fifteen workflows plus the root NEON, XML and YAML configs.
    expect(count($files))->toBeGreaterThan(
        8,
        'The config walk opened almost nothing, so no carve-out comment was read at all.'
    );

    $hits = [];
    foreach ($files as $path) {
        foreach (commentPolicyConfigComments($path) as $comment) {
            if (preg_match(COMMENT_POLICY_BANNED_TOKENS, commentPolicyWithoutStandards($comment['text'])) === 1) {
                $hits[] = $path.':'.$comment['line'];
            }
        }
    }
    expect($hits)->toBe([], "A NEON, XML or YAML comment says why the carve-out or the job exists, never which requirement it traces to — identifiers belong in the commit trailer and the PR body. Offenders:\n  ".implode("\n  ", $hits));
});

// Both scanners above are hand-written rather than borrowed from a tokeniser,
// so the cases that would make either of them lie are pinned here: a scanner
// that reads too much invents offenders, and one that reads too little cannot
// fail at all.
it('reads JS and CSS comments without tripping on URLs, regex literals or templates', function (): void {
    $source = <<<'JS'
        const url = 'https://example.test/a//b';
        const alphabet = /[^A-NP-Z2-7]/g;
        const escaped = name.replace(/\//g, '_');
        const template = `a // b /* c */ d`;
        const divided = (a + b) / c / d;
        const matched = /* inline */ text.match(/[/]+/);
        // a real line comment
        /* a real
           block comment */
        JS;

    $path = tempnam(sys_get_temp_dir(), 'comment-policy').'.js';
    file_put_contents($path, $source);
    $comments = commentPolicyScriptComments($path);
    unlink($path);

    expect(array_column($comments, 'text'))->toBe([
        '/* inline */',
        '// a real line comment',
        "/* a real\n   block comment */",
    ]);
    expect(array_column($comments, 'line'))->toBe([6, 7, 8]);
});

it('reads Blade comments in the {{-- --}} form and inside @php and <script> islands', function (): void {
    $source = <<<'BLADE'
        {{-- a real Blade comment --}}
        <div class="a // b">{{ $notAComment }}</div>
        @php
            // a real PHP comment
            $href = 'https://example.test/a//b';
        @endphp
        <script>
            // a real script comment
            const re = /[^A-Z2-7]/g;
        </script>
        BLADE;

    $path = tempnam(sys_get_temp_dir(), 'comment-policy').'.blade.php';
    file_put_contents($path, $source);
    $comments = commentPolicyBladeComments($path);
    unlink($path);

    expect(array_column($comments, 'text'))->toBe([
        '{{-- a real Blade comment --}}',
        '// a real PHP comment',
        '// a real script comment',
    ]);
    expect(array_column($comments, 'line'))->toBe([1, 4, 8]);
});

it('reads NEON and YAML comments without tripping on quoted hashes or URL fragments', function (): void {
    $source = <<<'NEON'
        # a real comment
        message: '#Some\Regex facade should not be used\.#'
        url: https://example.test/page#fragment
        quoted: "text # not a comment"
        apostrophe: it's fine  # a trailing comment
        NEON;

    $comments = commentPolicyHashComments($source);

    expect(array_column($comments, 'text'))->toBe([
        '# a real comment',
        '# a trailing comment',
    ]);
    expect(array_column($comments, 'line'))->toBe([1, 5]);
});

// A test name is read at a failure, where the useful thing to know is what
// broke — not which requirement row the test traces back to.
it('has no requirement identifiers in test names', function (): void {
    $files = commentPolicyTestFiles();

    // The floor sits far under the 2,500 test files this suite ships.
    expect(count($files))->toBeGreaterThan(
        500,
        'The test walk opened almost nothing, so no test name was read at all.'
    );

    $hits = [];
    foreach ($files as $path) {
        foreach (commentPolicyTestNames($path) as $entry) {
            $ids = commentPolicyRequirementIds($entry['name']);
            if ($ids !== []) {
                $hits[] = $entry['file'].':'.$entry['line'].' → '.implode(', ', $ids);
            }
        }
    }
    expect($hits)->toBe([], "A test name says what the test proves, never which requirement it traces to — identifiers belong in the commit trailer and the PR body. Offenders:\n  ".implode("\n  ", $hits));
});

// The same ban reached test NAMES and not test COMMENTS, because the walk it
// used is built on the style-rule walk, which drops tests so an explanatory
// line in one stays free. Only the identifier half crosses: a comment in a test
// may still name the deferral words it is describing.
it('has no requirement identifiers in test comments', function (): void {
    $files = commentPolicyTestFiles();

    expect(count($files))->toBeGreaterThan(
        500,
        'The test walk opened almost nothing, so no test comment was read at all.'
    );

    $hits = [];

    foreach ($files as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $matches = PatternScan::all(
                COMMENT_POLICY_IDENTIFIER_TOKENS,
                commentPolicyWithoutStandards($token[1]),
            );

            /** @var list<string> $ids */
            $ids = $matches[0];

            if ($ids !== []) {
                $hits[] = str_replace(base_path().'/', '', $path).':'.$token[2].' → '.implode(', ', $ids);
            }
        }
    }

    expect($hits)->toBe([], "A comment in a test says what the test proves, never which requirement it traces to — identifiers belong in the commit trailer and the PR body. Offenders:\n  ".implode("\n  ", $hits));
});

it('has no informative /* */ block comments (M3)', function (): void {
    $files = commentPolicyBackendFiles();

    expect(count($files))->toBeGreaterThan(
        1000,
        'The backend walk opened almost nothing, so no comment was read at all.'
    );

    $hits = [];
    foreach ($files as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_COMMENT) {
                continue;
            }
            if (str_starts_with(ltrim($token[1]), '/*')) {
                $hits[] = $path.':'.$token[2];
            }
        }
    }
    expect($hits)->toBe([], "Use /** */ PHPDoc, never informative /* */ blocks. Offenders:\n  ".implode("\n  ", $hits));
});

// M1 is deleted, and its number stays vacant on purpose: this file names its
// cases by those numbers, so a vacated number that acquires new text would
// resolve silently to a rule it was never written against (ADR-0023).

it('has no // block over 4 lines (M2)', function (): void {
    $files = commentPolicyBackendFiles();

    expect(count($files))->toBeGreaterThan(
        1000,
        'The backend walk opened almost nothing, so no comment block was measured at all.'
    );

    $hits = [];
    foreach ($files as $path) {
        foreach (commentPolicyLineCommentBlocks($path) as $block) {
            $n = count($block);
            if ($n > 4) {
                $hits[] = $path.':'.$block[0]." ({$n}-line // block > 4)";
            }
        }
    }
    expect($hits)->toBe([], "An inline // block is at most 4 lines. Anything needing more prose belongs in .docs, linked from a tag-only docblock. Offenders:\n  ".implode("\n  ", $hits));
});

it('has @-tag-only docblocks with no descriptive prose (M4)', function (): void {
    $files = commentPolicyBackendFiles();

    expect(count($files))->toBeGreaterThan(
        1000,
        'The backend walk opened almost nothing, so no docblock was read at all.'
    );

    $hits = [];
    foreach ($files as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            $seenTag = false;
            $hasContent = false;
            foreach (explode("\n", $token[1]) as $raw) {
                $line = trim(ltrim(trim($raw), '/*'));
                if ($line === '') {
                    continue;
                }
                $hasContent = true;
                if (str_starts_with($line, '@')) {
                    $seenTag = true;
                } elseif (! $seenTag) {
                    $hits[] = $path.':'.$token[2];
                    break;
                }
            }
            if ($hasContent && ! $seenTag && ! in_array($path.':'.$token[2], $hits, true)) {
                $hits[] = $path.':'.$token[2].' (docblock with no @-tags)';
            }
        }
    }
    expect($hits)->toBe([], "Docblocks must be @-tag only. Offenders:\n  ".implode("\n  ", $hits));
});

/** @return list<string> absolute paths to every page under .docs */
function commentPolicyDocsPages(): array
{
    $root = base_path('.docs');
    if (! is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getPathname(), '.md')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

// Fenced blocks and inline code are stripped first: a page teaching a link
// syntax, or quoting a path that does not exist yet, is illustrating rather
// than pointing. A bare `#anchor` and anything carrying a scheme are somebody
// else's to resolve.
/** @return list<string> the repo-relative link targets a .docs page points at */
function commentPolicyDocsLinkTargets(string $path): array
{
    $source = (string) file_get_contents($path);
    $source = preg_replace('/^```.*?^```/ms', '', $source) ?? $source;
    $source = preg_replace('/`[^`\n]*`/', '', $source) ?? $source;

    $inline = PatternScan::all('/!?\[[^\]]*\]\(([^)\s]+)\)/', $source);
    $reference = PatternScan::all('/^\[[^\]]+\]:\s*(\S+)/m', $source);

    $targets = [];
    /** @var string $target */
    foreach (array_merge($inline[1], $reference[1]) as $target) {
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|\#)~i', $target) === 1) {
            continue;
        }
        $targets[] = $target;
    }

    return $targets;
}

it('has every @link .md target resolving to a real .docs file (M6)', function (): void {
    $files = commentPolicyBackendFiles();

    expect(count($files))->toBeGreaterThan(
        1000,
        'The backend walk opened almost nothing, so no @link was read at all.'
    );

    $hits = [];
    foreach ($files as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            $m = PatternScan::all('/@link\s+(\S+\.md)/', $token[1]);

            foreach ($m[1] as $target) {
                $resolved = realpath(dirname($path).'/'.$target);
                if ($resolved === false || ! is_file($resolved)) {
                    $hits[] = $path.':'.$token[2].' → '.$target;
                }
            }
        }
    }
    expect($hits)->toBe([], "@link .md targets must exist under .docs. Broken links:\n  ".implode("\n  ", $hits));
});

// M6's sibling, on the other side of the same seam. The rule above proves a
// link OUT of the code reaches a page; nothing proved a link between two pages
// reaches anything, and a .docs page naming a class or a test that was never
// written reads exactly like one describing shipped code.
it('has every relative link in a .docs page resolving to a real file (M6)', function (): void {
    $pages = commentPolicyDocsPages();

    // The floor sits well under the 210 pages under .docs today.
    expect(count($pages))->toBeGreaterThan(
        50,
        'The .docs walk opened almost no page, so no link was read at all.'
    );

    $hits = [];
    foreach ($pages as $path) {
        foreach (commentPolicyDocsLinkTargets($path) as $target) {
            $file = explode('#', $target)[0];
            if ($file === '') {
                continue;
            }
            $resolved = realpath(dirname($path).'/'.rawurldecode($file));
            if ($resolved === false) {
                $hits[] = str_replace(base_path().'/', '', $path).' -> '.$target;
            }
        }
    }
    expect($hits)->toBe([], implode("\n", [
        'These .docs links point at nothing:',
        ...$hits,
        '',
        'A page that names a class, a test or a sibling page which does not exist',
        'is not stale documentation — it is documentation of something that was',
        'never built, and it reads identically to a description of shipped code.',
        'Either write what exists, or delete the claim. A directory target is',
        'fine; only a path resolving to nothing at all fails here.',
    ]));
});

/**
 * @return list<string>
 */
function commentPolicyHeadingSlugs(string $page): array
{
    $slugs = [];
    foreach (file($page) ?: [] as $line) {
        if (preg_match('/^#{1,6} (.+)$/', rtrim($line), $m) !== 1) {
            continue;
        }
        // GitHub's rule, and the two halves that trip people up: a character
        // it drops leaves its surrounding spaces behind, so an em dash yields
        // a DOUBLE hyphen, and runs of spaces are never collapsed.
        $text = strtolower(str_replace('`', '', $m[1]));
        $slugs[] = str_replace(' ', '-', PatternScan::replace('/[^a-z0-9 _\-]/', '', $text));
    }

    return $slugs;
}

// M6's third face. The two rules above prove a link reaches a *file*; both
// strip the fragment before looking. A link to a section that no longer exists
// lands the reader at the top of a page with no sign anything was missed,
// which is the failure a renamed heading causes and nothing else catches.
it('has every #fragment in a doc link naming a heading that exists (M6)', function (): void {
    $hits = [];
    $cited = 0;

    // Every PHP file, and both comment kinds. The walk was the backend files
    // and T_DOC_COMMENT: thirty citations are written on a `//` line, the whole
    // of tests/ was outside it, and two guards there cited a heading nobody had
    // written — which is the failure this rule is named after, sitting in the
    // half of the tree it could not see.
    foreach (RepoTree::files(RepoTree::EVERY_PHP_FILE) as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $m = PatternScan::all('/@link\s+(\S+\.md#\S+)/', $token[1]);

            foreach ($m[1] as $target) {
                $cited++;
                [$file, $anchor] = explode('#', $target, 2);
                $resolved = realpath(dirname($path).'/'.$file);
                if ($resolved !== false && ! in_array($anchor, commentPolicyHeadingSlugs($resolved), true)) {
                    $hits[] = str_replace(base_path().'/', '', $path).':'.$token[2].' → '.$target;
                }
            }
        }
    }

    // Read before the verdict: 533 citations carry a fragment today, and a walk
    // that found none reports the same clean answer as a tree whose every link
    // lands where it says.
    expect($cited)->toBeGreaterThan(
        300,
        'The walk found '.$cited.' doc citations carrying a fragment, which is too few to have read the tree.'
    );

    // The reader itself, over a heading that exists and one that does not, so
    // an empty offender list is never a slug reader that answers nothing.
    $slugs = commentPolicyHeadingSlugs(base_path('.docs/conventions/arch-invariants.md'));

    expect($slugs)->toContain('a-scanner-accounts-for-the-whole-tree')
        ->and($slugs)->not->toContain('a-heading-this-page-has-never-carried');

    foreach (commentPolicyDocsPages() as $path) {
        foreach (commentPolicyDocsLinkTargets($path) as $target) {
            if (! str_contains($target, '#')) {
                continue;
            }
            [$file, $anchor] = explode('#', $target, 2);
            $resolved = $file === '' ? $path : realpath(dirname($path).'/'.rawurldecode($file));
            if ($resolved !== false && is_file($resolved) && ! in_array($anchor, commentPolicyHeadingSlugs($resolved), true)) {
                $hits[] = str_replace(base_path().'/', '', $path).' -> '.$target;
            }
        }
    }

    expect($hits)->toBe([], implode("\n", [
        'These links name a heading that does not exist:',
        ...$hits,
        '',
        'The file resolves, so the two rules above pass and the reader still',
        'lands nowhere. Rename the fragment to match the heading, or restore',
        'the heading the link was written against.',
    ]));
});

/** @return list<string> every file any of this file's bans opens, deduplicated */
function commentPolicyPinnedNameFiles(): array
{
    return array_values(array_unique([
        ...commentPolicyIdentifierFiles(),
        ...commentPolicyBladeFiles(),
        ...commentPolicyScriptFiles(),
        ...commentPolicyConfigFiles(),
        ...commentPolicyTestFiles(),
    ]));
}

// Both lists blank text out before the ban reads it, which is the widest thing
// a rule here can do to itself: a name nothing writes any more still hides
// every future occurrence of it, and the entry reads as a decision somebody
// weighed. Seventeen of the twenty-seven standards names had gone that way,
// matching nothing anywhere in the tree, and were deleted with this case.
it('keeps no blanked name the bans no longer meet', function (): void {
    $wanted = array_merge(COMMENT_POLICY_STANDARDS_NAMES, COMMENT_POLICY_LITERAL_EXEMPTIONS);
    $files = commentPolicyPinnedNameFiles();

    expect(count($files))->toBeGreaterThan(
        1000,
        'The union of the bans opened almost nothing, so every pinned name would read as stale.'
    );

    $missing = array_flip($wanted);
    $self = realpath(__FILE__);

    foreach ($files as $path) {
        if ($missing === [] || realpath($path) === $self) {
            continue;
        }

        $source = (string) file_get_contents($path);

        foreach (array_keys($missing) as $name) {
            if (str_contains($source, (string) $name)) {
                unset($missing[$name]);
            }
        }
    }

    expect(array_map(strval(...), array_keys($missing)))->toBe([], implode("\n  ", [
        'These names are blanked out of every comment before the ban reads it, and no',
        'file the ban opens writes them any more:',
        ...array_map(strval(...), array_keys($missing)),
        '',
        'A blanking entry that matches nothing is not harmless. It stays in force for',
        'whatever is written next, and it reads as an exemption somebody weighed rather',
        'than one nobody has revisited. Delete it; add it back the day a comment needs it.',
    ]));
});

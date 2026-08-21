<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/00-index.md
 */

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

    if (preg_match_all('/\{\{--.*?--\}\}/s', $source, $matches, PREG_OFFSET_CAPTURE) !== 0) {
        /** @var array{0: string, 1: int} $match */
        foreach ($matches[0] as $match) {
            $comments[] = [
                'line' => substr_count(substr($source, 0, $match[1]), "\n") + 1,
                'text' => $match[0],
            ];
        }
    }

    $islands = '/@php\b(?<body>.*?)@endphp|<\?php(?<php>.*?)\?>|<script\b[^>]*>(?<js>.*?)<\/script>/si';
    if (preg_match_all($islands, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) !== 0) {
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

    if (preg_match_all('/<!--.*?-->/s', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
        return [];
    }

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

/** @return bool whether the comment text is a machine directive exempt from M1-M4 */
function commentPolicyIsDirective(string $text): bool
{
    return preg_match(
        '#^\s*(?://|/\*\*?)\s*@?(phpstan|psalm|phpcs|codeCoverage|var)\b#i',
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
// level rather than a row in a spec.
const COMMENT_POLICY_STANDARDS_NAMES = [
    'AES-128', 'AES-256', 'BIP-39', 'ISO-8601', 'ISO-4217', 'RFC-822', 'RFC-2822',
    'SHA-1', 'SHA-256', 'SHA-384', 'SHA-512', 'UTF-8', 'UTF-16', 'UTF-32',
    'X-25519', 'ED-25519', 'HTTP-2', 'CAMT-053', 'MT-940', 'PBKDF2-1', 'BASE-64',
    'BCP-47', 'PSR-3', 'PSR-4', 'PSR-12', 'R-7', 'API-28',
];

// Not a name at all: a regex character class whose middle reads as NP-Z2. The
// exemption is the exact literal rather than a hole in the pattern, so a real
// identifier standing next to one still trips.
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
    preg_match_all(
        COMMENT_POLICY_BANNED_TOKENS,
        commentPolicyWithoutStandards($name),
        $matches,
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

        $literal = $tokens[$i + 2] ?? null;
        if (($tokens[$i + 1] ?? null) !== '(' || ! is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING) {
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

// Every identifier shape this repo actually mints: D-06 and T-05-12 (one
// letter), WR-11 and GOV-R12 (several), F3-R36 (letter then digit). The
// one-letter form demands two digits so N-1 and R-7 stay prose. Phase, Wave
// and friends are provenance whatever number follows them. One pattern serves
// every rule below — a test name and a comment ban the same thing, and the two
// drifted apart once already.
const COMMENT_POLICY_BANNED_TOKENS = '/\b(TODO|FIXME|HACK|XXX|@todo)\b'
    .'|\b(?:[A-Z]{2,6}\d{0,2}|[A-Z]\d{1,2})-[A-Z]{0,2}\d{1,3}\b'
    .'|\b[A-Z]-(?:[A-Z]{1,2}\d{1,3}|\d{2,3})\b'
    .'|(?i:\b(?:Phase|Wave|Plan|Pitfall|Req|Issue|UAT)\s+#?\d)/';

it('has no banned deferral or provenance tokens in comments (M5)', function (): void {
    $hits = [];
    foreach (commentPolicyIdentifierFiles() as $path) {
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
    expect(commentPolicyBladeFiles())->not->toBe([]);

    $hits = [];
    foreach (commentPolicyBladeFiles() as $path) {
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
    expect($files)->not->toBe([]);

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
    expect($files)->not->toBe([]);

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
    $hits = [];
    foreach (commentPolicyTestFiles() as $path) {
        foreach (commentPolicyTestNames($path) as $entry) {
            $ids = commentPolicyRequirementIds($entry['name']);
            if ($ids !== []) {
                $hits[] = $entry['file'].':'.$entry['line'].' → '.implode(', ', $ids);
            }
        }
    }
    expect($hits)->toBe([], "A test name says what the test proves, never which requirement it traces to — identifiers belong in the commit trailer and the PR body. Offenders:\n  ".implode("\n  ", $hits));
});

it('has no informative /* */ block comments (M3)', function (): void {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
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

it('has no // block over 4 lines (M2)', function (): void {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        $lines = [];
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && $token[0] === T_COMMENT && str_starts_with(ltrim($token[1]), '//')
                && ! commentPolicyIsDirective($token[1])) {
                $lines[] = $token[2];
            }
        }
        sort($lines);
        $block = [];
        $flush = function () use (&$block, &$hits, $path): void {
            $n = count($block);
            if ($n > 4) {
                $hits[] = $path.':'.$block[0]." ({$n}-line // block > 4)";
            }
            $block = [];
        };
        foreach ($lines as $line) {
            if ($block !== [] && $line !== end($block) + 1) {
                $flush();
            }
            $block[] = $line;
        }
        $flush();
    }
    expect($hits)->toBe([], "An inline // block is at most 4 lines. A comment worth only one line should BE one line — padding it to reach a floor is how this file used to manufacture the noise it exists to prevent. Offenders:\n  ".implode("\n  ", $hits));
});

it('has @-tag-only docblocks with no descriptive prose (M4)', function (): void {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
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

it('has every @link .md target resolving to a real .docs file (M6)', function (): void {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            if (preg_match_all('/@link\s+(\S+\.md)/', $token[1], $m) === 0) {
                continue;
            }
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

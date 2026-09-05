<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;
use Tests\Contracts\Support\BackendSourceFiles;

// Every walk in this tree that says `.php` already holds the 276 Blade
// templates -- `.blade.php` ends in `.php` -- and `token_get_all` reads all 276
// as one T_INLINE_HTML, because PHP mode opens at a literal `<?php` and a Blade
// island is not one. A guard built that way lists a template, reads none of the
// code in it, and reports it clean.
//
// Measured on the day this was written: 0 significant tokens across those 276
// files raw, 62,982 through BladePhpSource. Four bare `preg_replace`/`preg_split`
// calls had been sitting in three modules under a guard that walks them.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-scanner-blind-inside-a-php-island

// A walk that cannot reach a template needs no seam, and saying so is cheaper
// than routing one through it. Each entry names why, and `proves` re-checks that
// against the file, so an exemption that stops being true fails here.
const BLADE_SCANNER_WALKS_NO_TEMPLATE = [
    'Modules/Auth/tests/Feature/CrossUserIsolationTest.php' => [
        'reason' => 'tokenises its own source and nothing else',
        'proves' => '#file_get_contents\(__FILE__\)#',
    ],
    'Modules/Search/tests/Unit/TheSearchModuleNamesWhatItUsesTest.php' => [
        'reason' => 'walks one module and steps over its Resources/ directory, which is where every template in it lives',
        'proves' => '#/Resources/#',
    ],
    'Modules/Sync/tests/Feature/RelayZeroKnowledgeTest.php' => [
        'reason' => 'one directory of relay transport classes, named by a glob that reaches no template',
        'proves' => '#Internal/Transport/Relay#',
    ],
    'tests/Contracts/AGuardThatReadsMarkupParsesItArchTest.php' => [
        'reason' => 'reads the guard tree, which holds no template',
        'proves' => '#/Contracts/\*\.php#',
    ],
    'tests/Contracts/AScannerAccountsForTheWholeTreeArchTest.php' => [
        'reason' => 'reads the scanner-support classes to find the root names they write out; that directory holds no template, and the Blade roots it asks RepoTree about are names rather than files it opens',
        'proves' => '#/Contracts/Support/\*\.php#',
    ],
    'tests/Contracts/AReaderFacingRowNamesTheDayTheLedgerStoresArchTest.php' => [
        'reason' => 'reads a template on a branch of its own and reaches the tokeniser only for a PHP file',
        'proves' => '#if \(str_ends_with\(\$path, .\.blade\.php.\)\) \{#',
    ],
    'tests/Contracts/AStoppedScanIsNeverReadAsAnEmptyOneArchTest.php' => [
        'reason' => 'reads the guard tree, which holds no template',
        'proves' => '#base_path\(.tests/Contracts.\)#',
    ],
    'tests/Contracts/ATestHelperNameIsOwnedByOneFileArchTest.php' => [
        'reason' => 'reads files whose name ends Test.php',
        'proves' => '#str_ends_with\(\$path, .Test\.php.\)#',
    ],
    'tests/Contracts/BaseCurrencyIsTheOnlyEuroArchTest.php' => [
        'reason' => 'reads a template as text on purpose: a currency code written into markup is the same defect, and no island holds that one. The two readings agree on every template today; the text one also covers the markup. Its tokeniser is the PHP branch',
        'proves' => '#function euroLiteralBladeLines#',
    ],
    'tests/Contracts/CommentPolicyArchTest.php' => [
        'reason' => 'reads a template for the comment forms only a template has -- {{-- --}}, and the // inside a <script> body -- neither of which is PHP and both of which an island reading drops',
        'proves' => '#/script#',
    ],
    'tests/Contracts/ComposerRootsAgreeArchTest.php' => [
        'reason' => 'reads the two bootstrap files it names',
        'proves' => '#bootstrap/app\.php#',
    ],
    'tests/Contracts/EmptyBodyExplainsItselfWhereSonarLooksArchTest.php' => [
        'reason' => 'stands in for a hosted analyser that is blind here too: SonarCloud indexes a .blade.php file as language php and reports ncloc 0 for it, so reading the islands would fail the build on findings the dashboard will never raise',
        'proves' => '#sonarEmptyBodyExcluded#',
    ],
    'tests/Contracts/EveryKeyACallSiteNamesResolvesToALineArchTest.php' => [
        'reason' => 'compiles the template with Blade before tokenising, which additionally reaches the :attribute expressions on a component tag that an island reading leaves as markup',
        'proves' => '#Blade::compileString#',
    ],
    'tests/Contracts/NoNonCompoundImportInATestFileArchTest.php' => [
        'reason' => 'reads files named Test.php and Pest.php',
        'proves' => '#/Pest#',
    ],
    'tests/Contracts/Support/PcreCallSites.php' => [
        'reason' => 'is handed a source rather than choosing a file, and both walks that use it read theirs through the seam',
        'proves' => '#function significantTokens\(string \$source\)#',
    ],
    'tests/Contracts/Support/SonarSourceFiles.php' => [
        'reason' => 'is sonar.sources and nothing else, mirroring an analyser that reads a template as PHP and finds no code in it',
        'proves' => '#sonar\.sources#',
    ],
];

// Each line holds the same call in a different Blade construct, so a reading
// that handles one and not the next is a reading that reports part of a file.
const BLADE_SCANNER_PLANTED_TEMPLATE = <<<'BLADE'
    {{-- preg_quote('in a comment') --}}
    @verbatim {{ preg_quote('in verbatim') }} @endverbatim
    @{{ preg_quote('escaped echo') }}
    @@if (preg_quote('escaped directive'))
    @php
        $island = preg_quote('in an @php block');
    @endphp
    <span>{{ preg_quote('in an echo') }}</span>
    <span>{!! preg_quote('in a raw echo') !!}</span>
    @if (preg_quote('in a directive argument'))<b>x</b>@endif
    @php($inline = preg_quote('in an @php expression'))
    <?php $raw = preg_quote('in a raw tag'); ?>
    BLADE;

/** @return list<string> every guard file in the tree, whichever root it sits under */
function bladeScannerGuardFiles(): array
{
    $files = [];
    $roots = [base_path('tests/Contracts'), ...glob(base_path('Modules/*/tests')) ?: []];

    foreach ($roots as $root) {
        if (! is_dir((string) $root)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator((string) $root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

// Read with the tokeniser rather than with a pattern: `token_get_all` written
// inside a string or a comment is not a call, and a guard that mistook one for
// the other would be the same mistake one level up.
function bladeScannerTokenisesDirectly(string $source): bool
{
    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $before = $tokens[$index - 1] ?? null;
        $owner = is_array($before) && $before[0] === T_DOUBLE_COLON
            ? (is_array($tokens[$index - 2] ?? null) ? $tokens[$index - 2][1] : '')
            : null;

        if (($tokens[$index + 1] ?? '') !== '(') {
            continue;
        }

        if (($token[1] === 'token_get_all' && $owner === null) || ($token[1] === 'tokenize' && $owner === 'PhpToken')) {
            return true;
        }
    }

    return false;
}

/** @return list<int> the lines of $source on which a `preg_quote(` call stands */
function bladeScannerCallLines(string $source): array
{
    $lines = [];
    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'preg_quote' && ($tokens[$index + 1] ?? '') === '(') {
            $lines[] = $token[2];
        }
    }

    return $lines;
}

it('leaves no guard tokenising a walk that holds a template without reading its islands', function (): void {
    $files = bladeScannerGuardFiles();

    // A walk that read nothing would report every guard clean, which is the
    // answer this whole file exists to make impossible.
    expect(count($files))->toBeGreaterThan(1500, 'The guard-file walk found '.count($files).' files, so its verdict covers almost nothing.');

    $tokenising = [];
    $offenders = [];
    $exempt = [];

    foreach ($files as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        if ($relative === str_replace(base_path().'/', '', __FILE__)) {
            continue;
        }

        $source = (string) file_get_contents($path);

        if (! bladeScannerTokenisesDirectly($source)) {
            continue;
        }

        $tokenising[] = $relative;

        if (array_key_exists($relative, BLADE_SCANNER_WALKS_NO_TEMPLATE)) {
            $exempt[] = $relative;

            continue;
        }

        if (! str_contains($source, 'BladePhpSource')) {
            $offenders[] = $relative;
        }
    }

    expect(count($tokenising))->toBeGreaterThan(15, 'The reader recognised almost no tokenising guard, which is what a broken tokeniser looks like.');

    expect($offenders)->toBe([], implode("\n  ", [
        'These hand a file straight to the tokeniser, and the walk that chose it holds',
        'Blade templates — `.blade.php` ends in `.php`, so every `.php` walk does.',
        'token_get_all reads a template as one T_INLINE_HTML, so every one of them is',
        'reported clean without being read. Take the source through',
        'Modules\Core\Public\Support\BladePhpSource::forPath(), which answers a template',
        'with its islands on the lines the Blade wrote them and a PHP file with itself,',
        'or add the file to BLADE_SCANNER_WALKS_NO_TEMPLATE with why its walk cannot',
        'reach one. Offenders:',
        ...$offenders,
    ]));

    // An exemption nobody reaches any more is a claim about the tree that
    // stopped being true, and it would otherwise sit here forever.
    sort($exempt);
    $declared = array_keys(BLADE_SCANNER_WALKS_NO_TEMPLATE);
    sort($declared);
    expect($exempt)->toBe($declared);
});

it('still holds each exempted walk to the reason it was granted for', function (): void {
    foreach (BLADE_SCANNER_WALKS_NO_TEMPLATE as $relative => $exemption) {
        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toMatch($exemption['proves'], $relative.' no longer reads as "'.$exemption['reason'].'"');
    }
});

// The reader half of the enumeration, checked against planted sources rather
// than against the tree: a guard that stopped recognising a call would report
// no tokenising guard at all, and read as a tree with nothing to fix.
it('tells a call to the tokeniser from its name merely written down', function (string $body, bool $tokenises): void {
    expect(bladeScannerTokenisesDirectly('<?php '.$body))->toBe($tokenises);
})->with([
    'a direct call' => ['$t = token_get_all($source);', true],
    'the static one' => ['$t = PhpToken::tokenize($source);', true],
    'the name inside a string' => ['$hint = "token_get_all is what this used to do";', false],
    'the name inside a comment' => ['// token_get_all($source) was here', false],
    'a method that shares the name' => ['$t = $this->tokenize($source);', false],
    'a lexer of another language' => ['$t = Mt940Lexer::tokenize($line);', false],
]);

// The planted control. A guard asserting "nothing found" is unreadable on its
// own, so the same template is read twice and the two answers are asserted
// against each other: the raw tokeniser has to find none of these, and the seam
// has to find every one, on the line it was written on.
it('finds in a template the calls the raw tokeniser cannot see', function (): void {
    $template = BLADE_SCANNER_PLANTED_TEMPLATE;

    // The raw reading finds line 12 and nothing else: that line opens PHP mode
    // with a literal `<?php`, and it is the only construct in the template that
    // does. Six calls stand above it and the tokeniser reaches none of them.
    expect(bladeScannerCallLines($template))->toBe([12]);

    // The seam adds lines 6, 8, 9, 10 and 11: an @php body, an echo, a raw echo,
    // a directive argument and an @php expression. The comment, the @verbatim
    // body and the two escapes above them are text Blade prints rather than code
    // it compiles, so none of those appears in either reading.
    expect(bladeScannerCallLines(BladePhpSource::of($template)))->toBe([6, 8, 9, 10, 11, 12]);

    expect(substr_count(BladePhpSource::of($template), "\n"))->toBe(substr_count($template, "\n"));
});

// The reading above is only worth having if it is the one the guards actually
// use, so the shared walk is asked the same question about a real file — planted
// under the temp directory, because a template written into a scanned root
// races every guard enumerating that root in a parallel worker.
it('reads a planted template through the seam every guard shares', function (): void {
    $planted = sys_get_temp_dir().'/blade-scanner-'.bin2hex(random_bytes(6)).'.blade.php';
    file_put_contents($planted, BLADE_SCANNER_PLANTED_TEMPLATE);

    try {
        $names = [];

        foreach (BackendSourceFiles::codeTokens($planted) as $token) {
            if (is_array($token) && $token[0] === T_STRING) {
                $names[] = $token[1];
            }
        }

        expect(array_values(array_unique($names)))->toBe(['preg_quote']);
    } finally {
        unlink($planted);
    }
});

<?php

declare(strict_types=1);

use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingAccessScope;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;

/*
 * Req 11 falsifiable AIS-only guard (19-13 Task 1). Enable Banking's API
 * exposes payment-initiation (PIS) via a wholly separate `POST /payments`
 * endpoint family [CITED: RESEARCH.md Pattern 5] — this module must never
 * reference it, and the typed `EnableBankingAccessScope` DTO the `/auth`
 * request body is built from must be structurally incapable of carrying a
 * `payments` key.
 *
 * Three falsifiable invariants:
 *   (a) a content grep over Modules/OpenBanking (excluding tests/ and
 *       comment lines) finds zero `/payments` or PIS/payment-initiation
 *       references;
 *   (b) `EnableBankingAccessScope::toArray()` can only ever emit a key set
 *       that is a strict subset of {balances, transactions, accounts} —
 *       proven both by reflection (no other property exists on the DTO)
 *       and by exhaustive boolean-combination enumeration;
 *   (c) the `/auth` request body construction path in
 *       `EnableBankingHttpClient::initiateAuth()` populates its `access`
 *       object ONLY from `$scope->toArray()` — never a free-form array
 *       literal that could smuggle in an extra key.
 *
 * Function names here are deliberately prefixed `aisOnlyGuard*` (distinct
 * from `OpenBankingSecretsFileGuardTest`'s `openBankingGuard*` helpers) —
 * both files load as global functions in the same `OpenBankingContracts`
 * PHP process, and a shared name would fatal with "cannot redeclare
 * function".
 */

function aisOnlyGuardStripComments(string $contents): string
{
    return preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
}

/**
 * @return list<string>
 */
function aisOnlyGuardPhpFiles(string $relativeDir): array
{
    $absolute = base_path($relativeDir);
    if (! is_dir($absolute)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $files[] = $path;
    }

    return $files;
}

/**
 * The single pattern applied both to the production tree AND to the
 * in-test falsifiability sample below — a `payments` endpoint path
 * literal (with or without a leading slash, since every real call site
 * in this module passes bare relative paths like `'aspsps'`, not
 * `'/aspsps'`), a bare `PIS` token, or a `payment-initiation`/
 * `payment_initiation` phrase (case-insensitive, word-boundaried so it
 * doesn't accidentally flag unrelated words like "payments_processed").
 *
 * The `u` flag is load-bearing, not decoration: without it `\b` matches on
 * BYTE boundaries, so the multibyte `ý` in the Czech and Slovak word "výpis"
 * (bank statement) reads as a word break and `\bPIS\b` fires on ordinary
 * translated copy. It cost two false offenders the day those locales landed.
 * Verified it still catches a real bare `PIS`, a quoted `/payments` path and
 * `payment-initiation`.
 */
const AIS_ONLY_FORBIDDEN_PATTERN = '#[\'"]/?payments[\'"]|\bPIS\b|payment[-_]initiation#iu';

it('never references a PIS/payments endpoint or scope anywhere in Modules/OpenBanking outside tests/comments', function (): void {
    $hits = [];
    foreach (aisOnlyGuardPhpFiles('Modules/OpenBanking') as $path) {
        $stripped = aisOnlyGuardStripComments((string) file_get_contents($path));
        if (preg_match(AIS_ONLY_FORBIDDEN_PATTERN, $stripped) === 1) {
            $hits[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($hits)->toBe(
        [],
        'AIS-only (Req 11): no OpenBanking source file may reference a /payments endpoint, '
        ."PIS, or payment-initiation. Offenders:\n  ".implode("\n  ", $hits),
    );

    // Falsifiability proof: the SAME pattern applied to a literal sample
    // representing exactly the violation this guard exists to catch —
    // proves the regex is not vacuously true just because no such
    // reference exists in the tree today.
    $violatingSample = <<<'PHP'
        // A hypothetical future call site:
        $this->postJson('payments', $body);
        PHP;
    expect(preg_match(AIS_ONLY_FORBIDDEN_PATTERN, $violatingSample))->toBe(1);

    $violatingPisSample = 'Extend this client for PIS support once the user opts in.';
    expect(preg_match(AIS_ONLY_FORBIDDEN_PATTERN, $violatingPisSample))->toBe(1);

    $violatingInitiationSample = 'Add payment-initiation support behind a future flag.';
    expect(preg_match(AIS_ONLY_FORBIDDEN_PATTERN, $violatingInitiationSample))->toBe(1);

    // Negative control: a benign string must NOT trip the pattern.
    $safeSample = 'GET /accounts/{uid}/transactions and /accounts/{uid}/balances only.';
    expect(preg_match(AIS_ONLY_FORBIDDEN_PATTERN, $safeSample))->toBe(0);
});

it('emits only a strict subset of {balances, transactions, accounts} from EnableBankingAccessScope::toArray()', function (): void {
    $allowedKeys = ['balances', 'transactions', 'accounts'];

    // Reflection proof: no property named `payments` (or anything else)
    // exists on the DTO at all — a `payments` key is structurally
    // unreachable, not merely absent by convention.
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(EnableBankingAccessScope::class))->getProperties(),
    );
    expect($properties)->toEqual($allowedKeys);

    // Exhaustive boolean-combination enumeration: every one of the 8
    // possible constructions emits a key set that is a subset of the
    // allow-list — never anything extra.
    foreach ([false, true] as $balances) {
        foreach ([false, true] as $transactions) {
            foreach ([false, true] as $accounts) {
                $scope = new EnableBankingAccessScope(
                    balances: $balances,
                    transactions: $transactions,
                    accounts: $accounts,
                );
                $keys = array_keys($scope->toArray());

                expect(array_diff($keys, $allowedKeys))->toBe([]);
                expect($keys)->not->toContain('payments');
            }
        }
    }
});

it('builds the /auth access body only from EnableBankingAccessScope::toArray(), never a free-form array', function (): void {
    $source = (string) file_get_contents(
        (new ReflectionClass(EnableBankingHttpClient::class))->getFileName(),
    );

    // Isolate initiateAuth()'s body so a match elsewhere in the file
    // (e.g. a docblock mentioning "access") can't produce a false pass.
    expect(preg_match(
        '/function\s+initiateAuth\s*\([^)]*\)\s*:\s*array\s*\{(.*?)\n    \}/s',
        $source,
        $matches,
    ))->toBe(1, 'Could not locate EnableBankingHttpClient::initiateAuth() to inspect its body.');

    $body = $matches[1];

    expect($body)->toContain("'access' => array_merge(\$scope->toArray()");

    // Falsifiability proof: a free-form array literal containing a
    // hardcoded scope key set (the exact anti-pattern this guard exists
    // to reject) must NOT satisfy the assertion above.
    $violatingBody = "'access' => ['balances' => true, 'payments' => true],";
    expect($violatingBody)->not->toContain("'access' => array_merge(\$scope->toArray()");
});

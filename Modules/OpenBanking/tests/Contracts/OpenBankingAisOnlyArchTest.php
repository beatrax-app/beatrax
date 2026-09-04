<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingAccessScope;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;

// Prefixed `aisOnlyGuard*` because OpenBankingSecretsFileGuardTest's helpers
// load as globals in the same process and a shared name fatals on redeclare.

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

// The `u` flag is load-bearing: without it `\b` matches on byte boundaries, so
// the `ý` in the Czech/Slovak "výpis" reads as a word break and `\bPIS\b` fires
// on ordinary copy. That produced two false offenders when those locales landed.
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

    // Fire the same pattern at samples of the exact violation, so a clean grep
    // over the tree is never mistaken for a vacuously-true one.
    $violatingSample = <<<'PHP'
        // A hypothetical future call site:
        $this->postJson('payments', $body);
        PHP;
    expect(PatternScan::matches(AIS_ONLY_FORBIDDEN_PATTERN, $violatingSample))->toBeTrue();

    $violatingPisSample = 'Extend this client for PIS support once the user opts in.';
    expect(PatternScan::matches(AIS_ONLY_FORBIDDEN_PATTERN, $violatingPisSample))->toBeTrue();

    $violatingInitiationSample = 'Add payment-initiation support behind a future flag.';
    expect(PatternScan::matches(AIS_ONLY_FORBIDDEN_PATTERN, $violatingInitiationSample))->toBeTrue();

    $safeSample = 'GET /accounts/{uid}/transactions and /accounts/{uid}/balances only.';
    expect(PatternScan::matches(AIS_ONLY_FORBIDDEN_PATTERN, $safeSample))->toBeFalse();
});

it('emits only a strict subset of {balances, transactions, accounts} from EnableBankingAccessScope::toArray()', function (): void {
    $allowedKeys = ['balances', 'transactions', 'accounts'];

    // The DTO has no `payments` property, so the key is structurally
    // unreachable rather than merely absent by convention.
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(EnableBankingAccessScope::class))->getProperties(),
    );
    expect($properties)->toEqual($allowedKeys);

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
    $matches = PatternScan::first(
        '/function\s+initiateAuth\s*\([^)]*\)\s*:\s*array\s*\{(.*?)\n    \}/s',
        $source,
    );

    expect($matches)->not->toBe([], 'Could not locate EnableBankingHttpClient::initiateAuth() to inspect its body.');

    $body = $matches[1];

    expect($body)->toContain("'access' => array_merge(\$scope->toArray()");

    // The free-form array literal this rejects must fail the assertion above.
    $violatingBody = "'access' => ['balances' => true, 'payments' => true],";
    expect($violatingBody)->not->toContain("'access' => array_merge(\$scope->toArray()");
});

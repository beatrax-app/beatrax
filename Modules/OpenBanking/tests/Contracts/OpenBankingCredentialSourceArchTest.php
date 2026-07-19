<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

/*
 * Req 10 falsifiable credential-source guard (19-13 Task 2): "the test
 * fails if ... a credential is read from anywhere but the secrets file."
 *
 * Deliberately COMPLEMENTARY to, not a duplicate of, the existing D-07
 * guard (`OpenBankingSecretsFileGuardTest`, 19-03) — that test is a
 * STATIC content-grep over source text (file-path string literal +
 * DatabaseManager/credential-field-name co-occurrence, plus a
 * migration-content grep). This test adds two DIFFERENT signal sources
 * a pure text grep cannot see, while enforcing the SAME never-in-DB /
 * secrets-file-only invariant D-07 already pins — a violation here
 * would never contradict that guard, only fail alongside it:
 *
 *   (a) LIVE DATABASE SCHEMA introspection (`Schema::getColumns()`) —
 *       catches a credential column that landed via a dynamically-built
 *       column name or any construct a source-text grep could miss,
 *       because this inspects what actually materialized in SQLite,
 *       not what the migration source literally says;
 *   (b) a REFLECTION-level check that `OpenBankingSecretsRepository::
 *       load()` is the ONLY method, across every autoloaded class the
 *       module actually declares, whose return type is
 *       `OpenBankingCredentials`/`?OpenBankingCredentials` — enforcing
 *       "credentials are fabricated only by the repository" at the PHP
 *       type level, not merely by the absence of a string pattern.
 *
 * Classes are discovered by scanning the module's PHP source tree
 * (excluding tests/migrations/routes) and resolving each declared
 * class/interface/trait/enum's FQCN via `class_exists()` (which
 * triggers Composer's PSR-4 autoloader) — NOT via `get_declared_classes()`
 * alone, which would only see classes some earlier test happened to
 * already autoload and could silently under-report.
 */

const OB_CREDENTIAL_SOURCE_FORBIDDEN_COLUMN_PATTERN = '/private_key|application_id|session_id|refresh_token|access_token/i';

/**
 * @return list<string>
 */
function credentialSourceGuardOpenBankingTables(): array
{
    return ['open_banking_connections'];
}

/**
 * @return list<class-string>
 */
function credentialSourceGuardDiscoverClasses(string $relativeDir): array
{
    $absolute = base_path($relativeDir);
    if (! is_dir($absolute)) {
        return [];
    }

    $classes = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/tests/')
            || str_contains($path, '/Database/Migrations/')
            || str_contains($path, '/Database/Seeders/')
            || str_contains($path, '/Routes/')
        ) {
            continue;
        }

        $contents = (string) file_get_contents($path);
        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatch) !== 1) {
            continue;
        }
        if (preg_match('/\b(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/', $contents, $classMatch) !== 1) {
            continue;
        }

        $fqcn = trim($nsMatch[1]).'\\'.$classMatch[1];
        if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn)) {
            $classes[] = $fqcn;
        }
    }

    return $classes;
}

/**
 * Reflects over the given candidate class list and returns the
 * `Class::method` identifiers of every method whose return type is
 * exactly `$returnType` (or `?$returnType`), excluding any class in
 * `$allowList`. Only methods DECLARED on the candidate class itself are
 * considered (inherited methods are attributed to their declaring
 * class, never double-counted).
 *
 * @param  list<class-string>  $candidates
 * @param  list<class-string>  $allowList
 * @return list<string>
 */
function credentialSourceGuardMethodsReturning(array $candidates, string $returnType, array $allowList): array
{
    $offenders = [];
    foreach (array_unique($candidates) as $class) {
        if (in_array($class, $allowList, strict: true)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $type = $method->getReturnType();
            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            if (ltrim($type->getName(), '?') === $returnType) {
                $offenders[] = $class.'::'.$method->getName();
            }
        }
    }

    return $offenders;
}

it('never lands a credential-shaped column in the live open_banking_connections schema', function (): void {
    foreach (credentialSourceGuardOpenBankingTables() as $table) {
        if (! Schema::hasTable($table)) {
            // Table lands in a later wave than this guard; trivially
            // satisfied until it exists (mirrors OpenBankingSecretsFileGuardTest's
            // own not-yet-created-directory tolerance).
            continue;
        }

        $columns = array_map(
            static fn (array $column): string => (string) $column['name'],
            Schema::getColumns($table),
        );

        $hits = array_values(array_filter(
            $columns,
            static fn (string $column): bool => preg_match(OB_CREDENTIAL_SOURCE_FORBIDDEN_COLUMN_PATTERN, $column) === 1,
        ));

        expect($hits)->toBe(
            [],
            "Req 10: table `{$table}` has a credential-shaped column in its LIVE schema "
            .'(not just the migration source) — credentials must live only in the chmod-600 '
            ."secrets file. Offending columns:\n  ".implode("\n  ", $hits),
        );
    }

    // Falsifiability proof: the SAME pattern applied to a literal sample
    // column-name list containing exactly the violation this guard
    // exists to catch, alongside genuinely-safe metadata columns that
    // must NOT trip it.
    $violatingColumns = ['id', 'institution_id', 'application_id'];
    $violatingHits = array_values(array_filter(
        $violatingColumns,
        static fn (string $column): bool => preg_match(OB_CREDENTIAL_SOURCE_FORBIDDEN_COLUMN_PATTERN, $column) === 1,
    ));
    expect($violatingHits)->toBe(['application_id']);

    $safeColumns = ['id', 'institution_id', 'account_uid', 'enabled'];
    $safeHits = array_values(array_filter(
        $safeColumns,
        static fn (string $column): bool => preg_match(OB_CREDENTIAL_SOURCE_FORBIDDEN_COLUMN_PATTERN, $column) === 1,
    ));
    expect($safeHits)->toBe([]);
});

it('makes OpenBankingSecretsRepository::load() the ONLY method in the module returning OpenBankingCredentials', function (): void {
    $candidates = credentialSourceGuardDiscoverClasses('Modules/OpenBanking');
    expect($candidates)->not->toBeEmpty('Sanity check: class discovery must find at least the repository itself.');

    $offenders = credentialSourceGuardMethodsReturning(
        $candidates,
        OpenBankingCredentials::class,
        allowList: [OpenBankingSecretsRepository::class],
    );

    expect($offenders)->toBe(
        [],
        'Req 10: no class other than OpenBankingSecretsRepository::load() may declare a method '
        .'returning OpenBankingCredentials — credentials are read ONLY via the repository. '
        .'Offenders: '.implode(', ', $offenders),
    );

    // Falsifiability proof: run the SAME reflection-based check against a
    // fixture class that commits exactly the violation this guard exists
    // to catch (a second class independently fabricating credentials,
    // e.g. from a raw DB row) — proves the detector is not vacuously
    // true just because no such class exists in the tree today.
    $violatingClass = new class
    {
        public function readFromDatabaseRow(): OpenBankingCredentials
        {
            return new OpenBankingCredentials(
                applicationId: 'leaked-from-db',
                privateKeyPem: 'leaked-pem',
                sessionId: null,
                consentExpiresAt: null,
                bankScaHost: null,
                institutionId: null,
            );
        }
    };

    $violatingOffenders = credentialSourceGuardMethodsReturning(
        [$violatingClass::class],
        OpenBankingCredentials::class,
        allowList: [OpenBankingSecretsRepository::class],
    );
    expect($violatingOffenders)->not->toBe([]);
});

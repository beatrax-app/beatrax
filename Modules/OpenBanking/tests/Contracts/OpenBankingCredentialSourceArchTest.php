<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;

// Adds two signals a source grep cannot see: the live SQLite schema, and every
// method's declared return type. Candidates come from scanning source and
// calling class_exists(), because get_declared_classes() under-reports.

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
        // A missing table used to skip the case, which read as a clean live
        // schema. The table is created by this module's own migration, so its
        // absence means the schema was never built and the rule never ran.
        expect(Schema::hasTable($table))->toBeTrue(
            "Req 10 is asserted against the LIVE schema, and `{$table}` is not in it. The rule below would "
            .'have passed over a table nobody created.'
        );

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

    // Fire the same pattern at a known-bad and a known-good column list, so a
    // clean live schema is never mistaken for a vacuously-true pattern.
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
    expect($safeHits)->toBe([], 'The credential-column pattern now fires on ordinary columns too, so the '
        .'rule above reports every table it reads and nobody can read its offender list.');
});

it('makes OpenBankingSecretsRepository::load() the ONLY method in the module returning OpenBankingCredentials', function (): void {
    $candidates = credentialSourceGuardDiscoverClasses('Modules/OpenBanking');

    // Counted, not merely non-empty: discovery resolves a class only when it
    // autoloads, so a broken autoloader answers with a handful of classes and
    // an empty offender list that reads exactly like a clean module.
    expect(count($candidates))->toBeGreaterThan(
        30,
        'Class discovery over Modules/OpenBanking resolved '.count($candidates).' classes, which is too few '
        .'to be the module. The offender list below would be read off classes nobody loaded.'
    );

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

    // Run the same reflection check over a class that fabricates credentials
    // itself, so an empty offender list is never mistaken for a broken check.
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
    expect($violatingOffenders)->not->toBe(
        [],
        'The reflection reader no longer sees a method declaring OpenBankingCredentials as its return type, '
        .'so the rule above would report a clean module however many classes fabricated credentials.'
    );
});

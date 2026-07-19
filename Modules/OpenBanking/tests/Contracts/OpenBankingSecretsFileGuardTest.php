<?php

declare(strict_types=1);

/*
 * D-07 arch/content guard (19-03 Task 2). OpenBanking credentials
 * (application_id, RSA private-key PEM, session/consent tokens) live
 * ONLY in the chmod-600 file `OpenBankingSecretsRepository` writes at
 * storage/app/secrets/open-banking.json — never a DB column, because
 * any secret in SQLite would leak into DB backups (T-19-03-02).
 *
 * Two falsifiable invariants:
 *   (a) no OpenBanking class other than OpenBankingSecretsRepository
 *       references the filesystem secrets path, and no other class
 *       reads a credential field via a DatabaseManager/Eloquent
 *       surface;
 *   (b) no migration under Modules/OpenBanking/Database/Migrations
 *       adds a column matching the forbidden credential-field regex
 *       (the metadata migration built in 19-05 must stay secret-free).
 *
 * Comments are stripped BEFORE matching: this file's own explanatory
 * prose (and the repository's own docblocks naming the forbidden
 * column names) would otherwise trip the rule it describes.
 */

function openBankingGuardStripComments(string $contents): string
{
    return preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
}

/**
 * @return list<string>
 */
function openBankingGuardPhpFiles(string $relativeDir, bool $excludeTests = true): array
{
    $absolute = base_path($relativeDir);
    if (! is_dir($absolute)) {
        // Directory lands in a later wave (e.g. Database/Migrations
        // before 19-05); until it does the rule is trivially satisfied.
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
        if ($excludeTests && str_contains($path, '/tests/')) {
            continue;
        }
        $files[] = $path;
    }

    return $files;
}

it('confines the on-disk secrets path reference to OpenBankingSecretsRepository alone', function (): void {
    $hits = [];
    foreach (openBankingGuardPhpFiles('Modules/OpenBanking') as $path) {
        if (str_ends_with($path, '/Public/Services/OpenBankingSecretsRepository.php')) {
            continue;
        }
        $stripped = openBankingGuardStripComments((string) file_get_contents($path));
        if (str_contains($stripped, 'open-banking.json')) {
            $hits[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($hits)->toBe(
        [],
        'Only OpenBankingSecretsRepository may reference the filesystem secrets path '
        .'(storage/app/secrets/open-banking.json) — every other class must go through its '
        ."save()/load()/clear()/hasApplication() Public API. Offenders:\n  "
        .implode("\n  ", $hits),
    );
});

it('never reads a credential field via a DatabaseManager/Eloquent surface outside OpenBankingSecretsRepository', function (): void {
    $credentialFieldPattern = '/private_key|application_id|session_id|refresh_token|access_token/i';
    $dbIndicatorPattern = '/DatabaseManager|Illuminate\\\\Database\\\\Eloquent|::query\(\)|extends Model\b/';

    $hits = [];
    foreach (openBankingGuardPhpFiles('Modules/OpenBanking') as $path) {
        if (str_ends_with($path, '/Public/Services/OpenBankingSecretsRepository.php')) {
            continue;
        }
        $stripped = openBankingGuardStripComments((string) file_get_contents($path));
        if (preg_match($dbIndicatorPattern, $stripped) === 1 && preg_match($credentialFieldPattern, $stripped) === 1) {
            $hits[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($hits)->toBe(
        [],
        'No OpenBanking class may read a credential field via a DatabaseManager/Eloquent surface — '
        ."credentials come ONLY from OpenBankingSecretsRepository::load(). Offenders:\n  "
        .implode("\n  ", $hits),
    );

    // Falsifiability proof: apply the SAME two patterns to a literal
    // fixture sample representing exactly the violation this guard
    // exists to catch (a hypothetical Eloquent accessor reading
    // application_id straight off a DB row) — proves the regex pair
    // is not vacuously true just because no such class exists in the
    // tree yet.
    $violatingSample = <<<'PHP'
        class FixtureCredentialModel extends Model
        {
            public function readApplicationId(DatabaseManager $db): string
            {
                return $db->connection()->table('fixture')->value('application_id');
            }
        }
        PHP;
    expect(preg_match($dbIndicatorPattern, $violatingSample))->toBe(1);
    expect(preg_match($credentialFieldPattern, $violatingSample))->toBe(1);
});

it('forbids any OpenBanking migration from adding a secret column', function (): void {
    $forbiddenPattern = '/private_key|application_id|session_id|refresh_token|access_token/i';

    $hits = [];
    foreach (openBankingGuardPhpFiles('Modules/OpenBanking/Database/Migrations', excludeTests: false) as $path) {
        $stripped = openBankingGuardStripComments((string) file_get_contents($path));
        if (preg_match($forbiddenPattern, $stripped) === 1) {
            $hits[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($hits)->toBe(
        [],
        'No OpenBanking migration may add a column matching '
        .'/private_key|application_id|session_id|refresh_token|access_token/i — credentials live '
        ."only in the chmod-600 secrets file (D-07). Offenders:\n  "
        .implode("\n  ", $hits),
    );

    // Falsifiability proof: the exact forbidden-column fixture the
    // 19-05 metadata migration must never introduce, alongside a
    // genuinely-safe metadata column (institution_id) that must NOT
    // trip the same pattern.
    $violatingMigrationSample = "\$table->string('application_id')->nullable();";
    expect(preg_match($forbiddenPattern, $violatingMigrationSample))->toBe(1);

    $safeMigrationSample = "\$table->string('institution_id')->nullable();";
    expect(preg_match($forbiddenPattern, $safeMigrationSample))->toBe(0);
});

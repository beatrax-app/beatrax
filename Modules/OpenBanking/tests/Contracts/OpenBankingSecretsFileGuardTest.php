<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Comments are stripped before matching, or this file's own prose and the
// repository's docblocks would trip the rule they describe.

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
        if (str_ends_with($path, '/Internal/Services/OpenBankingSecretsRepository.php')) {
            continue;
        }
        $stripped = openBankingGuardStripComments((string) file_get_contents($path));
        if (str_contains($stripped, 'secrets/open-banking')) {
            $hits[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($hits)->toBe(
        [],
        'Only OpenBankingSecretsRepository may build a path under storage/app/secrets/open-banking — '
        .'every other class must go through its reader-keyed load()/saveApplication()/'
        ."rememberSession()/clear() API. Offenders:\n  "
        .implode("\n  ", $hits),
    );

    // Fire the needle at a path a caller might build by hand, so an empty
    // offender list is never mistaken for a needle that matches nothing.
    expect(str_contains("storage_path('app/secrets/open-banking/1.json')", 'secrets/open-banking'))->toBeTrue();
});

it('never reads a credential field via a DatabaseManager/Eloquent surface outside OpenBankingSecretsRepository', function (): void {
    $credentialFieldPattern = '/private_key|application_id|session_id|refresh_token|access_token/i';
    $dbIndicatorPattern = '/DatabaseManager|Illuminate\\\\Database\\\\Eloquent|::query\(\)|extends Model\b/';

    $hits = [];
    foreach (openBankingGuardPhpFiles('Modules/OpenBanking') as $path) {
        if (str_ends_with($path, '/Internal/Services/OpenBankingSecretsRepository.php')) {
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

    // Fire the same patterns at a real violation, so an empty offender list is
    // never mistaken for a vacuously-true check.
    $violatingSample = <<<'PHP'
        class FixtureCredentialModel extends Model
        {
            public function readApplicationId(DatabaseManager $db): string
            {
                return $db->connection()->table('fixture')->value('application_id');
            }
        }
        PHP;
    expect(PatternScan::matches($dbIndicatorPattern, $violatingSample))->toBeTrue();
    expect(PatternScan::matches($credentialFieldPattern, $violatingSample))->toBeTrue();
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

    $violatingMigrationSample = "\$table->string('application_id')->nullable();";
    expect(PatternScan::matches($forbiddenPattern, $violatingMigrationSample))->toBeTrue();

    $safeMigrationSample = "\$table->string('institution_id')->nullable();";
    expect(PatternScan::matches($forbiddenPattern, $safeMigrationSample))->toBeFalse();
});

// A connector secret arriving on a paired device would be a session that device
// can spend. Nothing in the replication path may reach this store: the rows are
// declared device-local in Sync's own coverage test, and the file itself lives
// under storage/app, which no bundle and no database backup carries.
it('keeps the connector secrets store out of the replication path', function (): void {
    $needles = ['secrets/open-banking', 'OpenBankingSecretsRepository', 'OpenBankingSecretsFile'];

    $hits = [];
    foreach (openBankingGuardPhpFiles('Modules/Sync', excludeTests: false) as $path) {
        $stripped = openBankingGuardStripComments((string) file_get_contents($path));
        foreach ($needles as $needle) {
            if (str_contains($stripped, $needle)) {
                $hits[] = str_replace(base_path().'/', '', $path).' -> '.$needle;
            }
        }
    }

    expect($hits)->toBe(
        [],
        'Nothing in Modules/Sync may reach the open-banking secrets store — a connector session '
        ."that travels to a peer is a session that peer can spend. Offenders:\n  "
        .implode("\n  ", $hits),
    );

    // The same needles against a file that would violate it, so an empty
    // offender list is never mistaken for needles that match nothing.
    $violatingSample = 'use Modules\\OpenBanking\\Internal\\Services\\OpenBankingSecretsRepository;';
    expect(array_filter($needles, static fn (string $needle): bool => str_contains($violatingSample, $needle)))
        ->not->toBe([]);
});

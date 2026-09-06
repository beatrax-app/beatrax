<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Comments are stripped before matching, or this file's own prose and the
// repository's docblocks would trip the rule they describe.

const OPEN_BANKING_SECRETS_SEAM = 'Modules/OpenBanking/Internal/Services/OpenBankingSecretsRepository.php';

// The seam is exempted whole because the whole file is the store: it builds the
// path, it opens the file, and every other class reaches it through load() and
// its siblings. The last case re-runs this needle against it, so an exemption
// that outlives the store it was granted for fails rather than standing.
const OPEN_BANKING_SECRETS_SEAM_PROVES = 'secrets/open-banking';

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
    $files = openBankingGuardPhpFiles('Modules/OpenBanking');

    // Counted first: a walk that reached nothing reports the same empty
    // offender list a module with one path-builder reports.
    expect(count($files))->toBeGreaterThan(
        50,
        'The walk over Modules/OpenBanking reached '.count($files).' files, which is too few to be the module. '
        .'Every verdict below would be read off a tree nobody opened.'
    );

    $hits = [];
    foreach ($files as $path) {
        if (str_ends_with($path, '/'.OPEN_BANKING_SECRETS_SEAM)) {
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

    $files = openBankingGuardPhpFiles('Modules/OpenBanking');

    expect(count($files))->toBeGreaterThan(
        50,
        'The walk over Modules/OpenBanking reached '.count($files).' files, which is too few to be the module.'
    );

    $hits = [];
    foreach ($files as $path) {
        if (str_ends_with($path, '/'.OPEN_BANKING_SECRETS_SEAM)) {
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

    $migrations = openBankingGuardPhpFiles('Modules/OpenBanking/Database/Migrations', excludeTests: false);

    expect(count($migrations))->toBeGreaterThan(
        0,
        'The walk over Modules/OpenBanking/Database/Migrations reached no file. A module whose migrations '
        .'cannot be read reports the same clean result as one whose migrations add no secret column.'
    );

    $hits = [];
    foreach ($migrations as $path) {
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

    $syncFiles = openBankingGuardPhpFiles('Modules/Sync', excludeTests: false);

    expect(count($syncFiles))->toBeGreaterThan(
        300,
        'The walk over Modules/Sync reached '.count($syncFiles).' files, which is too few to be the '
        .'replication module. An unread Sync reports the same clean result as a Sync that never names the store.'
    );

    $hits = [];
    foreach ($syncFiles as $path) {
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
        ->not->toBe([], 'None of the needles matches an import of the secrets store any more, so the rule above '
            .'would report Modules/Sync clean whatever it reached the store through.');
});

it('still holds the secrets store to the reason its two exemptions were granted for', function (): void {
    $seam = base_path(OPEN_BANKING_SECRETS_SEAM);

    expect(is_file($seam))->toBeTrue(
        OPEN_BANKING_SECRETS_SEAM.' is exempted from both rules above and no longer exists. The exemptions '
        .'excuse nothing, and the store has moved somewhere those rules now have to be told about.'
    );

    expect(str_contains((string) file_get_contents($seam), OPEN_BANKING_SECRETS_SEAM_PROVES))->toBeTrue(
        OPEN_BANKING_SECRETS_SEAM.' was exempted because it is the one class that builds a path under '
        .OPEN_BANKING_SECRETS_SEAM_PROVES.', and it no longer names that path. Either the store moved to a '
        .'file those rules now report, or the exemption has outlived what earned it — delete it.'
    );

    expect(in_array($seam, openBankingGuardPhpFiles('Modules/OpenBanking'), strict: true))->toBeTrue(
        OPEN_BANKING_SECRETS_SEAM.' is exempted from a walk that no longer reaches it, so the exemption '
        .'excuses nothing and the walk is narrower than the module it claims.'
    );
});

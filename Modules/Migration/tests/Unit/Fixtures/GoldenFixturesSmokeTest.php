<?php

declare(strict_types=1);

use Modules\Migration\Tests\Support\ActualFixtureBuilder;
use Pdo\Sqlite;

function migrationFixturesRoot(): string
{
    return __DIR__.'/../../Fixtures';
}

it('Fixture: the ynab4 v1/v2 golden fixtures ship both Register.csv and Budget.csv', function (): void {
    foreach (['v1', 'v2'] as $version) {
        $dir = migrationFixturesRoot()."/ynab4/{$version}";
        $files = glob("{$dir}/*.csv");

        expect($files)->not->toBeFalse();
        /** @var list<string> $files */
        $registerFiles = array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, 'Register.csv')));
        $budgetFiles = array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, 'Budget.csv')));

        expect($registerFiles)->toHaveCount(1);
        expect($budgetFiles)->toHaveCount(1);

        $header = fgetcsv(fopen($registerFiles[0], 'r'));
        expect($header)->toContain('Account');
        expect($header)->toContain('Category Group/Category');
        expect($header)->toContain('Master Category');
        expect($header)->toContain('Sub Category');
        expect($header)->toContain('Cleared');
    }
});

it('Fixture: the ynab4 v1 Register.csv carries a plain expense, income, split pair, and transfer pair', function (): void {
    $dir = migrationFixturesRoot().'/ynab4/v1';
    $files = glob("{$dir}/*Register.csv");
    expect($files)->not->toBeFalse();
    /** @var list<string> $files */
    $rows = array_map('str_getcsv', file($files[0]));
    array_shift($rows);

    expect($rows)->toHaveCount(7);

    $memos = array_column($rows, 8);
    expect(in_array('Split (1/2)', $memos, true))->toBeTrue();
    expect(in_array('Split (2/2)', $memos, true))->toBeTrue();

    $payees = array_column($rows, 4);
    expect(in_array('Transfer : Savings', $payees, true))->toBeTrue();
    expect(in_array('Transfer : Checking', $payees, true))->toBeTrue();

    $clearedFlags = array_column($rows, 11);
    expect(in_array('N', $clearedFlags, true))->toBeTrue();
    expect(in_array('C', $clearedFlags, true))->toBeTrue();
});

it('Fixture: the ynab4 Budget.csv v2 changes exactly the Jan Groceries + Jan Household budgeted amounts', function (): void {
    $v1 = array_map('str_getcsv', file(glob(migrationFixturesRoot().'/ynab4/v1/*Budget.csv')[0]));
    $v2 = array_map('str_getcsv', file(glob(migrationFixturesRoot().'/ynab4/v2/*Budget.csv')[0]));

    array_shift($v1);
    array_shift($v2);

    expect($v1)->toHaveCount(4);
    expect($v2)->toHaveCount(4);

    // Column indices: Month(0), Category Group(1), Category(2), Budgeted(3).
    $v1Budgeted = [];
    foreach ($v1 as $row) {
        $v1Budgeted["{$row[0]}|{$row[2]}"] = $row[3];
    }
    $v2Budgeted = [];
    foreach ($v2 as $row) {
        $v2Budgeted["{$row[0]}|{$row[2]}"] = $row[3];
    }

    expect($v1Budgeted['2026-01|Groceries'])->toBe('200.00');
    expect($v2Budgeted['2026-01|Groceries'])->toBe('250.00');
    expect($v1Budgeted['2026-01|Household'])->toBe('100.00');
    expect($v2Budgeted['2026-01|Household'])->toBe('120.00');
    expect($v1Budgeted['2026-02|Groceries'])->toBe($v2Budgeted['2026-02|Groceries']);
    expect($v1Budgeted['2026-02|Household'])->toBe($v2Budgeted['2026-02|Household']);
});

it('Fixture: the nynab v1/v2 exports are real ZIPs containing exactly the two CSV entries with the combined category column', function (): void {
    foreach (['v1/nynab-export.zip', 'v2/nynab-export-v2.zip'] as $relative) {
        $zipPath = migrationFixturesRoot()."/nynab/{$relative}";
        expect(is_file($zipPath))->toBeTrue();

        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);
        expect($opened)->toBe(true);
        expect($zip->numFiles)->toBe(2);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $registerEntries = array_values(array_filter($names, static fn (string $n): bool => str_ends_with($n, 'Register.csv')));
        $budgetEntries = array_values(array_filter($names, static fn (string $n): bool => str_ends_with($n, 'Budget.csv')));
        expect($registerEntries)->toHaveCount(1);
        expect($budgetEntries)->toHaveCount(1);
    }
});

it('Fixture: the nynab Register.csv uses the single combined Category Group/Category column (not separate Master/Sub Category)', function (): void {
    $zip = new ZipArchive;
    $zip->open(migrationFixturesRoot().'/nynab/v1/nynab-export.zip');
    $registerIndex = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_ends_with((string) $zip->getNameIndex($i), 'Register.csv')) {
            $registerIndex = $i;
        }
    }
    expect($registerIndex)->not->toBeNull();

    $contents = $zip->getFromIndex($registerIndex);
    $zip->close();
    expect($contents)->not->toBeFalse();

    $lines = explode("\n", (string) $contents);
    $header = str_getcsv($lines[0]);

    expect($header)->toContain('Category Group/Category');
    expect($header)->not->toContain('Master Category');
    expect($header)->not->toContain('Sub Category');
});

it('Fixture: the corrupt fixture is a valid ZIP that does not match any known export shape', function (): void {
    $zipPath = migrationFixturesRoot().'/corrupt/not-a-real-export.zip';
    expect(is_file($zipPath))->toBeTrue();

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBe(true);

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    expect($names)->not->toContain('db.sqlite');
    expect($names)->not->toContain('metadata.json');
    foreach ($names as $name) {
        expect(str_ends_with((string) $name, 'Register.csv'))->toBeFalse();
        expect(str_ends_with((string) $name, 'Budget.csv'))->toBeFalse();
    }
});

it('Fixture: ActualFixtureBuilder produces a ZIP containing exactly db.sqlite + metadata.json', function (): void {
    $zipPath = sys_get_temp_dir().'/actual-fixture-smoke-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($zipPath);

    try {
        $zip = new ZipArchive;
        expect($zip->open($zipPath))->toBe(true);
        expect($zip->numFiles)->toBe(2);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        expect($names)->toContain('db.sqlite');
        expect($names)->toContain('metadata.json');
    } finally {
        @unlink($zipPath);
    }
});

it('Fixture: ActualFixtureBuilder output opens read-only and v_transactions returns only the non-tombstoned rows', function (): void {
    $zipPath = sys_get_temp_dir().'/actual-fixture-smoke-'.uniqid('', true).'.zip';
    $extractDir = sys_get_temp_dir().'/actual-fixture-extract-'.uniqid('', true);

    ActualFixtureBuilder::build($zipPath);
    mkdir($extractDir, 0755, true);

    try {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $zip->extractTo($extractDir);
        $zip->close();

        $dbPath = "{$extractDir}/db.sqlite";
        expect(is_file($dbPath))->toBeTrue();

        $readOnly = new Sqlite(
            'sqlite:'.$dbPath,
            null,
            null,
            [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY],
        );

        $rows = $readOnly->query('SELECT id FROM v_transactions')->fetchAll(PDO::FETCH_COLUMN);
        expect($rows)->toHaveCount(7);
        expect(in_array('tx-6-tombstoned', $rows, true))->toBeFalse();

        $threwOnWrite = false;
        try {
            $readOnly->exec("INSERT INTO accounts (id, name) VALUES ('should-fail', 'x')");
        } catch (Throwable) {
            $threwOnWrite = true;
        }
        expect($threwOnWrite)->toBeTrue('A read-only Pdo\Sqlite connection must reject a write attempt');

        $metadata = json_decode((string) file_get_contents("{$extractDir}/metadata.json"), true, flags: JSON_THROW_ON_ERROR);
        expect($metadata)->toHaveKey('budgetName');
    } finally {
        @unlink($zipPath);
        @unlink("{$extractDir}/db.sqlite");
        @unlink("{$extractDir}/metadata.json");
        @rmdir($extractDir);
    }
});

it('Fixture: ActualFixtureBuilder carries exactly one FLAT goal_def, one NON-FLAT goal_def, one saved-report row, and >=1 schedule (Req 8)', function (): void {
    $zipPath = sys_get_temp_dir().'/actual-fixture-smoke-'.uniqid('', true).'.zip';
    $extractDir = sys_get_temp_dir().'/actual-fixture-extract-'.uniqid('', true);

    ActualFixtureBuilder::build($zipPath);
    mkdir($extractDir, 0755, true);

    try {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $zip->extractTo($extractDir);
        $zip->close();

        $readOnly = new Sqlite(
            'sqlite:'.$extractDir.'/db.sqlite',
            null,
            null,
            [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY],
        );

        $goalDefs = $readOnly->query('SELECT goal_def FROM v_categories WHERE goal_def IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        expect($goalDefs)->toHaveCount(2);

        $decoded = array_map(static fn (string $g): array => json_decode($g, true, flags: JSON_THROW_ON_ERROR), $goalDefs);
        $flat = array_values(array_filter($decoded, static fn (array $g): bool => $g['type'] === 'simple'));
        $nonFlat = array_values(array_filter($decoded, static fn (array $g): bool => $g['type'] !== 'simple'));
        expect($flat)->toHaveCount(1);
        expect($nonFlat)->toHaveCount(1);

        $reportCount = $readOnly->query('SELECT COUNT(*) FROM custom_reports')->fetchColumn();
        expect((int) $reportCount)->toBe(1);

        $scheduleCount = $readOnly->query('SELECT COUNT(*) FROM schedules')->fetchColumn();
        expect((int) $scheduleCount)->toBeGreaterThanOrEqual(1);

        $currency = $readOnly->query("SELECT value FROM preferences WHERE id = 'currencyCode'")->fetchColumn();
        expect($currency)->toBe(ActualFixtureBuilder::BUDGET_FILE_CURRENCY);
        expect($currency)->not->toBe('EUR');
    } finally {
        @unlink($zipPath);
        @unlink("{$extractDir}/db.sqlite");
        @unlink("{$extractDir}/metadata.json");
        @rmdir($extractDir);
    }
});

<?php

declare(strict_types=1);

use Tests\Helpers\RealSqliteFixture;

// The backup/restore tests need a real on-disk file: `:memory:` cannot back
// VACUUM INTO or PRAGMA integrity_check against a path.

it('create() returns a path to a file that exists on disk', function (): void {
    $path = RealSqliteFixture::create('fixture-a');

    try {
        expect(is_file($path))->toBeTrue();
        expect(str_ends_with($path, '.sqlite'))->toBeTrue();
        expect(filesize($path))->toBeGreaterThan(0);
    } finally {
        RealSqliteFixture::cleanup($path);
    }
});

it('cleanup() removes both the .sqlite file and its temp directory', function (): void {
    $path = RealSqliteFixture::create('fixture-cleanup');
    $dir = dirname($path);

    expect(is_file($path))->toBeTrue();
    expect(is_dir($dir))->toBeTrue();

    RealSqliteFixture::cleanup($path);

    expect(is_file($path))->toBeFalse();
    expect(is_dir($dir))->toBeFalse();
});

it('applies DEFAULT_SCHEMAS so transactions + system_alerts tables are present', function (): void {
    $path = RealSqliteFixture::create('fixture-schema');

    try {
        $pdo = new PDO('sqlite:'.$path);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
            ?->fetchAll(PDO::FETCH_COLUMN);
        expect($tables)->toContain('transactions');
        expect($tables)->toContain('system_alerts');
    } finally {
        RealSqliteFixture::cleanup($path);
    }
});

it('enables WAL journal mode so the fixture matches the production substrate posture', function (): void {
    $path = RealSqliteFixture::create('fixture-wal');

    try {
        $pdo = new PDO('sqlite:'.$path);
        $mode = $pdo->query('PRAGMA journal_mode')?->fetchColumn();
        expect(strtolower((string) $mode))->toBe('wal');
    } finally {
        RealSqliteFixture::cleanup($path);
    }
});

it('accepts a custom $schemas array as an extension hook on top of DEFAULT_SCHEMAS', function (): void {
    $path = RealSqliteFixture::create(
        'fixture-custom',
        [
            ...RealSqliteFixture::DEFAULT_SCHEMAS,
            'CREATE TABLE custom_table (id INTEGER PRIMARY KEY, label TEXT)',
        ],
    );

    try {
        $pdo = new PDO('sqlite:'.$path);
        $columns = $pdo->query("PRAGMA table_info('custom_table')")
            ?->fetchAll(PDO::FETCH_ASSOC) ?? [];
        $columnNames = array_map(static fn (array $row): string => (string) $row['name'], $columns);

        expect($columnNames)->toContain('id');
        expect($columnNames)->toContain('label');

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
            ?->fetchAll(PDO::FETCH_COLUMN);
        expect($tables)->toContain('transactions');
        expect($tables)->toContain('system_alerts');
    } finally {
        RealSqliteFixture::cleanup($path);
    }
});

it('each create() call returns a unique path so concurrent tests do not collide', function (): void {
    $pathA = RealSqliteFixture::create('fixture-uniq');
    $pathB = RealSqliteFixture::create('fixture-uniq');

    try {
        expect($pathA)->not->toBe($pathB);
        expect(is_file($pathA))->toBeTrue();
        expect(is_file($pathB))->toBeTrue();
    } finally {
        RealSqliteFixture::cleanup($pathA);
        RealSqliteFixture::cleanup($pathB);
    }
});

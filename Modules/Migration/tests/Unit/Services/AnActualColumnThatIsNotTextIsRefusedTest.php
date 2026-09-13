<?php

declare(strict_types=1);

use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Services\ActualSqliteReader;

// An Actual export is a whole SQLite database written by something other than
// this product, so its TEXT columns are bytes until they are checked. A name
// that is not UTF-8 travelled on to a slug resolver and threw there, halfway
// through a promotion nothing could take back.
function notTextActualDatabase(string $payeeName): string
{
    $dir = sys_get_temp_dir().'/actual-not-text-'.uniqid('', true);
    mkdir($dir, 0755, true);
    $path = $dir.'/db.sqlite';

    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE payees (id TEXT PRIMARY KEY, name TEXT NOT NULL, transfer_acct TEXT, tombstone INTEGER NOT NULL DEFAULT 0)');
    $statement = $pdo->prepare('INSERT INTO payees (id, name) VALUES (:id, :name)');
    $statement->execute(['id' => 'payee-1', 'name' => $payeeName]);

    return $path;
}

it('refuses a column whose bytes are not text, naming the column it read', function (): void {
    $reader = new ActualSqliteReader(notTextActualDatabase("Albert\xC3\x28 Heijn"));

    try {
        $reader->payees();
        $this->fail('the column was accepted');
    } catch (UnrecognizedMigrationFileException $e) {
        expect($e->refusedCell()?->file)->toBe('db.sqlite')
            ->and($e->refusedCell()?->column)->toBe('name');
    }
});

it('reads a name that is text', function (): void {
    $reader = new ActualSqliteReader(notTextActualDatabase('Albert Heijn'));

    expect(array_column($reader->payees(), 'name'))->toBe(['Albert Heijn']);
});

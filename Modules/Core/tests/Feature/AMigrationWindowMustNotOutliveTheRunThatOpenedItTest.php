<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;
use Modules\Core\Internal\Support\MigrationWindow;

// Migrator::runPending() fires MigrationsStarted before its loop and
// MigrationsEnded after it, with no finally between them. A migration that
// throws therefore opens the window and never closes it, and the listener the
// window gates stops invalidating the sidebar badges for the rest of that
// request. Three callers drive the migrator: the backup forward-run closes the
// window itself, and the two first-launch bootstraps did not.
function aMigrationThatThrows(): string
{
    $directory = sys_get_temp_dir().'/throwing-migration-'.bin2hex(random_bytes(5));
    mkdir($directory, 0755, true);

    file_put_contents(
        $directory.'/2026_01_01_000000_a_migration_that_throws.php',
        implode("\n", [
            '<?php',
            'use Illuminate\Database\Migrations\Migration;',
            'return new class extends Migration {',
            '    public function up(): void { throw new RuntimeException("this migration fails"); }',
            '};',
            '',
        ])
    );

    return $directory;
}

it('is left open by a migration that throws, because the end event never fires', function (): void {
    $window = app(MigrationWindow::class);
    $window->close();

    /** @var Migrator $migrator */
    $migrator = app('migrator');

    expect(fn () => $migrator->run([aMigrationThatThrows()]))->toThrow(RuntimeException::class);

    // Pins the framework behaviour this fix exists for rather than assuming
    // it: if a later Laravel moves the end event into a finally, this turns
    // red and the terminating callback below becomes redundant.
    expect($window->isOpen())->toBeTrue('the run threw, so MigrationsEnded never fired');
});

it('is closed by the end of the lifetime that opened it', function (): void {
    $window = app(MigrationWindow::class);
    $window->close();

    /** @var Migrator $migrator */
    $migrator = app('migrator');

    expect(fn () => $migrator->run([aMigrationThatThrows()]))->toThrow(RuntimeException::class);
    expect($window->isOpen())->toBeTrue();

    app()->terminate();

    expect($window->isOpen())->toBeFalse(
        'a shell that catches the throw and goes on serving must not inherit an open window'
    );
});

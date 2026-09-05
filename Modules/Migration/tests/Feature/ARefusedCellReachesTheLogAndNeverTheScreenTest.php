<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Http\Livewire\NewMigration;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Tests\Helpers\UploadIsolation;

uses(RefreshDatabase::class);

// One export, one unreadable Outflow cell, and the two endings the product owes
// the reader: a fixed line on screen that quotes nothing of their file, and a
// local log entry naming the file, the column and the cell so there is
// something to go and look at.
const REFUSED_OUTFLOW_CELL = 'twelve euros fifty';

final class RefusedCellRecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param  array<mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }

    /**
     * @return array<mixed>
     */
    public function parseFailureContext(): array
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], 'parse/stage failed')) {
                return $record['context'];
            }
        }

        throw new RuntimeException('nothing logged a parse/stage failure');
    }
}

function refusedCellExportUpload(): UploadedFile
{
    $register = implode("\n", [
        'Account,Flag,"Check Number",Date,Payee,"Category Group/Category","Master Category","Sub Category",Memo,Outflow,Inflow,Cleared,"Running Balance"',
        'Checking,,,01/15/2026,"Albert Heijn","Frequent: Groceries",Frequent,Groceries,,'.REFUSED_OUTFLOW_CELL.',0.00,C,955.00',
    ]);
    $budget = implode("\n", [
        'Month,"Category Group",Category,Budgeted,Outflows,"Category Balance"',
        '2026-01,Frequent,Groceries,200.00,45.00,155.00',
    ]);

    $path = sys_get_temp_dir().'/migration-refused-cell-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('Beatrax Refusal Budget as of 2026-01-20 - Register.csv', $register);
    $zip->addFromString('Beatrax Refusal Budget as of 2026-01-20 - Budget.csv', $budget);
    $zip->close();

    $upload = UploadedFile::fake()->createWithContent('ynab4-export.zip', (string) file_get_contents($path));
    @unlink($path);

    return $upload;
}

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::create([
        'username' => 'migration-refused-cell-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $this->logger = new RefusedCellRecordingLogger;
    app()->instance(LoggerInterface::class, $this->logger);

    $this->component = Livewire::actingAs($this->user)
        ->test(NewMigration::class)
        ->set('sourceProduct', 'ynab4')
        ->set('file', refusedCellExportUpload())
        ->call('submit');
});

it('records the file, the column and the cell it could not read in the local log', function (): void {
    $context = $this->logger->parseFailureContext();

    expect($context['refused_file'])->toBe('Register.csv')
        ->and($context['refused_column'])->toBe('Outflow')
        ->and($context['refused_value'])->toBe(REFUSED_OUTFLOW_CELL)
        ->and($context['refused_value_bytes'])->toBe(strlen(REFUSED_OUTFLOW_CELL));
});

// The log is where a diagnostic belongs and the screen is not, so the entry
// still carries no raw message: the class, the SQLSTATE and the cell are the
// whole of it, and the strip that made this a gap stays in place for every
// other exception the same catch receives.
it('carries the cell beside the strip, never through it', function (): void {
    $context = $this->logger->parseFailureContext();

    expect($context['reason'])->toBe(UnrecognizedMigrationFileException::class)
        ->and($context)->not->toHaveKey('exception_message')
        ->and($context['filename'])->toBe('ynab4-export.zip');
});

// The refusal fires part-way through staging, so the run has children by the
// time it is unwound. Nothing clears them for the database any more, and the
// key it refused the delete on arrived at the log in place of the refusal.
it('leaves neither the run nor a staged row of the file it refused', function (): void {
    $db = app(DatabaseManager::class)->connection();

    expect($db->table('migration_runs')->count())->toBe(0)
        ->and($db->table('migration_staging_categories')->count())->toBe(0)
        ->and($db->table('migration_staging_accounts')->count())->toBe(0)
        ->and($db->table('migration_staging_budget_assignments')->count())->toBe(0)
        ->and($db->table('migration_staging_transactions')->count())->toBe(0);
});

it('shows the reader one fixed line and none of the cell it refused', function (): void {
    $this->component
        ->assertSet('uploadError', Lang::get('migration::new.errors.unrecognised'))
        ->assertDontSee(REFUSED_OUTFLOW_CELL)
        ->assertDontSee('Register.csv')
        ->assertDontSee('Outflow')
        ->assertDontSee('could not parse');
});

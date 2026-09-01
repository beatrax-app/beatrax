<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Public\Enums\SyntheticSourceFormat;

uses(RefreshDatabase::class);

// /imports/87 on a paired iPhone read "Import complete — Imported 0
// transactions" while transactions.import_run_id = 87 held 95 rows. The run is
// a demo seed: SyntheticSourceFormat exists precisely for rows no parser
// produces, so the run is a container the rows hang off, not an import that
// ran. Nothing links there, but the page still described an event that never
// happened. The real import path is unaffected and reports its own count.

function importSummaryUser(): User
{
    return User::query()->create([
        'username' => 'import-summary-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function importSummaryRun(DatabaseManager $db, int $userId, string $format, int $inserted): int
{
    return (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => $format,
        'raw_file_path' => '/tmp/import-summary-'.$format.'.csv',
        'sha256' => hash('sha256', 'import-summary-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-01 00:00:00',
        'confirmed_at' => '2026-01-01 00:00:00',
        'status' => 'confirmed',
        'inserted_count' => $inserted,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->user = importSummaryUser();
});

it('shows the summary for a run that really was an import', function (): void {
    $id = importSummaryRun($this->db, (int) $this->user->id, 'asn-csv', 229);

    $this->actingAs($this->user)->get('/imports/'.$id)->assertOk();
});

it('refuses the summary for a run no parser produced', function (): void {
    foreach (SyntheticSourceFormat::cases() as $format) {
        $id = importSummaryRun($this->db, (int) $this->user->id, $format->value, 0);

        $this->actingAs($this->user)->get('/imports/'.$id)->assertNotFound();
    }
});

it('refuses a demo run the same way, which is the one a phone could reach', function (): void {
    $id = importSummaryRun($this->db, (int) $this->user->id, 'demo', 0);

    $this->actingAs($this->user)->get('/imports/'.$id)->assertNotFound();
});

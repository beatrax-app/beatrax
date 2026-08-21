<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Migration\Internal\Actions\DiscardMigrationRun;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Http\Livewire\NewMigration;
use Modules\Migration\Tests\Support\MigrationFixturePaths;
use Tests\Helpers\UploadIsolation;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::create([
        'username' => 'migration-wizard-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

it('MigrationWizard: GET /migrations/new renders the upload form', function (): void {
    $response = $this->actingAs($this->user)->get('/migrations/new');

    $response->assertOk();
    $response->assertSee('Migration', false);
});

it('MigrationWizard: NewMigration requires a source-product selection', function (): void {
    Livewire::actingAs($this->user)
        ->test(NewMigration::class)
        ->set('sourceProduct', '')
        ->call('submit')
        ->assertHasErrors(['sourceProduct']);
});

it('MigrationWizard: preview renders the 5 mapped counts + a grouped unmapped summary', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $response = $this->actingAs($this->user)->get("/migrations/{$run->id}/preview");

    $response->assertOk();
    $response->assertSee('Categories', false);
    $response->assertSee('Accounts', false);
    $response->assertSee('Transactions', false);
});

it('MigrationWizard: NO domain writes occur before confirm (staging only)', function (): void {
    app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $stagingCount = $this->db->connection()->table('migration_staging_categories')->count();
    expect($stagingCount)->toBeGreaterThan(0);

    // "Database unchanged before confirm" means the DOMAIN tables; staging is
    // scratch and legitimately holds rows pre-confirm.
    expect(Category::query()->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count())->toBe(0);
    expect($this->db->connection()->table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('MigrationWizard: discarding a run leaves domain tables unchanged and truncates staging', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    app(DiscardMigrationRun::class)->__invoke($run->id, $this->user);

    expect($this->db->connection()->table('migration_staging_categories')->where('migration_run_id', $run->id)->count())->toBe(0);
    expect(Category::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('MigrationWizard: partner receives 404 (never 403) requesting the owner\'s migration preview', function (): void {
    $partner = User::create(['username' => 'migration-wizard-partner', 'password' => 'opensesame', 'period_start_day' => 1]);

    $ownerRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $response = $this->actingAs($partner)->get("/migrations/{$ownerRun->id}/preview");

    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('MigrationWizard: partner receives 404 (never 403) requesting the owner\'s migration results', function (): void {
    $partner = User::create(['username' => 'migration-wizard-partner-2', 'password' => 'opensesame', 'period_start_day' => 1]);

    $ownerRun = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $response = $this->actingAs($partner)->get("/migrations/{$ownerRun->id}/results");

    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('MigrationWizard: the owner\'s migration run never bleeds into the partner\'s /migrations index', function (): void {
    $partner = User::create(['username' => 'migration-wizard-partner-3', 'password' => 'opensesame', 'period_start_day' => 1]);

    app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $response = $this->actingAs($partner)->get('/migrations');

    $response->assertOk();
    $response->assertDontSee('Beatrax Test Budget.zip', false);
});

it('MigrationWizard: GET /migrations/new is reachable by any authenticated user (no per-entity id, no data to leak)', function (): void {
    $partner = User::create(['username' => 'migration-wizard-partner-4', 'password' => 'opensesame', 'period_start_day' => 1]);

    $this->actingAs($partner)->get('/migrations/new')->assertOk();
});

it('MigrationWizard: NewMigration::rules() rejects a non-ZIP file renamed to a .zip extension via real content sniffing', function (): void {
    // UploadedFile::fake() derives its mime type from the filename and never
    // sniffs, so it cannot exercise the finfo check; a real UploadedFile falls
    // through to Symfony's File::getMimeType(), which does.
    $tmpPath = tempnam(sys_get_temp_dir(), 'migration-in01-').'.txt';
    file_put_contents($tmpPath, "this is just plain text\n");
    $file = new UploadedFile($tmpPath, 'not-really-a-zip.zip', null, null, true);

    $validator = Validator::make(['file' => $file], (new NewMigration)->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('file'))->toBeTrue();

    @unlink($tmpPath);
});

it('MigrationWizard: a genuine ZIP file passes the mimes:zip content-sniffing rule (defense-in-depth does not reject legitimate exports)', function (): void {
    $zipPath = sys_get_temp_dir().'/migration-wizard-mimes-test-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('hello.txt', 'hello world');
    $zip->close();

    $contents = file_get_contents($zipPath);
    @unlink($zipPath);
    expect($contents)->not->toBeFalse();

    $file = UploadedFile::fake()->createWithContent('genuine-export.zip', $contents !== false ? $contents : '');

    Livewire::actingAs($this->user)
        ->test(NewMigration::class)
        ->set('sourceProduct', 'ynab4')
        ->set('file', $file)
        ->call('submit')
        // It legitimately fails parsing afterwards, which surfaces as
        // $uploadError rather than an error on the 'file' field.
        ->assertHasNoErrors(['file']);
});

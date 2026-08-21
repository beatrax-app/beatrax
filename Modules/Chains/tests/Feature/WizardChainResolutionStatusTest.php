<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;

function wcrUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = wcrUser('wizard-chain-status');
    $this->otherUser = wcrUser('wizard-chain-status-other');

    // Seed an import_run for the user — the wizard's polling action
    // needs an import_run id to auto-navigate on complete.
    $this->importRunId = (int) $this->db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/wcr.csv',
        'sha256' => str_repeat('w', 64),
        'uploaded_at' => CarbonImmutable::now()->toDateTimeString(),
        'status' => 'confirmed',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
});

it('reads chain_resolution_runs by exact user_id match — running status surfaces', function (): void {
    $now = CarbonImmutable::now()->toDateTimeString();
    $this->db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'running',
        'linked_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::actingAs($this->user)
        ->test(PreviewWizard::class, ['id' => $this->importRunId])
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', 'running');
});

it('surfaces pending status when chain_resolution_runs.status=pending', function (): void {
    $now = CarbonImmutable::now()->toDateTimeString();
    $this->db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'pending',
        'linked_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::actingAs($this->user)
        ->test(PreviewWizard::class, ['id' => $this->importRunId])
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', 'pending');
});

it('auto-navigates to imports.results on chain_resolution_runs.status=complete', function (): void {
    $now = CarbonImmutable::now()->toDateTimeString();
    $this->db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'complete',
        'linked_count' => 7,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::actingAs($this->user)
        ->test(PreviewWizard::class, ['id' => $this->importRunId])
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', 'complete')
        ->assertSet('chainResolutionLinkedCount', 7)
        ->assertRedirect(route('imports.results', ['id' => $this->importRunId]));
});

// This pinned the truncated last_error onto a PUBLIC Livewire property, which
// put it in the wire:snapshot whether or not a view printed it. last_error is
// written as "<JobClass>: <first line of the message>", and the crypto layer's
// version of that names an internal class and the reader's own user id. What
// the wizard owes the reader is the failed state and a door to the job log, so
// that is what this pins now.
it('surfaces failed status without carrying the job error to the browser', function (): void {
    $longError = 'ResolveChainLinksJob: BlindIndexCodec: encryption is enabled for user 1';
    $now = CarbonImmutable::now()->toDateTimeString();
    $this->db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'failed',
        'last_error' => $longError,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $html = Livewire::actingAs($this->user)
        ->test(PreviewWizard::class, ['id' => $this->importRunId])
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', 'failed')
        ->assertSee('Chain resolution failed')
        ->assertSee('the details are in the job log')
        ->html();

    expect($html)->not->toContain('ResolveChainLinksJob')
        ->and($html)->not->toContain('BlindIndexCodec');
});

it('cross-user isolation — user A does NOT observe user B\'s chain_resolution_runs row', function (): void {
    $now = CarbonImmutable::now()->toDateTimeString();
    $this->db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->otherUser->id,
        'status' => 'running',
        'linked_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::actingAs($this->user)
        ->test(PreviewWizard::class, ['id' => $this->importRunId])
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', null);
});

it('substring-attack guard — user_id matching is exact, not LIKE', function (): void {
    // A `payload LIKE '%userId:N%'` query would falsely match users whose ids
    // share a digit prefix; the exact-match query must return null.
    $now = CarbonImmutable::now()->toDateTimeString();
    $this->db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->otherUser->id,
        'status' => 'failed',
        'last_error' => 'OtherUserError',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::actingAs($this->user)
        ->test(PreviewWizard::class, ['id' => $this->importRunId])
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', null);

    // User B needs their own import_run: the wizard requires one.
    $otherImportRunId = (int) $this->db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->otherUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/wcr-other.csv',
        'sha256' => str_repeat('o', 64),
        'uploaded_at' => CarbonImmutable::now()->toDateTimeString(),
        'status' => 'confirmed',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    Livewire::actingAs($this->otherUser)
        ->test(PreviewWizard::class, ['id' => $otherImportRunId])
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', 'failed');
});

it('PreviewWizard does NOT contain a substring LIKE payload pattern', function (): void {
    $wizardPath = base_path('Modules/Import/Internal/Http/Livewire/PreviewWizard.php');
    expect(file_exists($wizardPath))->toBeTrue();
    $contents = (string) file_get_contents($wizardPath);
    // Strip comments so legitimate PHPDoc references stay legal.
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
    expect($stripped)->not->toMatch('/payload.*?like.*?userId/i');
    expect($stripped)->not->toMatch("/'%userId:/");
    expect($contents)->toContain('chain_resolution_runs');
});

it('returns null when no chain_resolution_runs row exists at all', function (): void {
    Livewire::actingAs($this->user)
        ->test(PreviewWizard::class, ['id' => $this->importRunId])
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', null);
});

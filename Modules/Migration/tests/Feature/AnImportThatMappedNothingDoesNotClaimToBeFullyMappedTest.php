<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Http\Livewire\PreviewMigration;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// The line is decided by counting unmapped rows, which is zero for a run that
// staged nothing at all — the one case it most obviously must not claim.
// The assertion that was meant to catch that named a key nobody defines, so
// Lang::get returned the key path and the page could never contain it.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'fully-mapped-badge',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->run = MigrationRun::query()->create([
        'user_id' => $this->user->id,
        'source_product' => 'ynab4',
        'status' => MigrationRunStatus::Parsed->value,
        'original_filename' => 'nothing-mapped.zip',
    ]);
});

it('does not tell an import that mapped nothing that it is fully mapped', function (): void {
    Livewire::test(PreviewMigration::class, ['id' => $this->run->id])
        ->assertOk()
        ->assertSee(Lang::get('migration::preview.heading'))
        ->assertDontSee(Lang::get('migration::preview.all_clean'));
});

it('says the export held nothing rather than leaving the reader an empty page', function (): void {
    Livewire::test(PreviewMigration::class, ['id' => $this->run->id])
        ->assertOk()
        ->assertSee(Lang::get('migration::preview.nothing_staged'));
});

it('still says everything mapped cleanly for a run that actually staged rows', function (): void {
    $run = app(StartMigrationRun::class)->__invoke(
        $this->user,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    Livewire::test(PreviewMigration::class, ['id' => $run->id])
        ->assertOk()
        ->assertSee(Lang::get('migration::preview.all_clean'))
        ->assertDontSee(Lang::get('migration::preview.nothing_staged'));
});

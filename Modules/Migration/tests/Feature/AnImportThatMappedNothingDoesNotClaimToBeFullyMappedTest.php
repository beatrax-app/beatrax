<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Http\Livewire\PreviewMigration;
use Modules\Migration\Models\MigrationRun;

uses(RefreshDatabase::class);

// The badge was decided by counting unmapped rows of type 'category' and
// 'payee'. No production path writes either word, so both counts were always 0
// and the micro-label printed on every preview -- including one that staged
// nothing at all, which is the case it most obviously must not claim.

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
        ->assertDontSee(Lang::get('migration::preview.fully_mapped'));
});

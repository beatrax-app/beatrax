<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Enums\ConflictResolution;
use Modules\Migration\Internal\Enums\MigrationEntityType;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use Modules\Migration\Internal\Http\Livewire\PreviewMigration;
use Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder;
use Modules\Migration\Models\MigrationRun;

uses(RefreshDatabase::class);

// migration_staging_unmapped_items.item_type has no CHECK trigger, so nothing
// rejects a spelling the writers and the readers no longer share — the conflict
// toggle simply stops saving.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'unmapped-vocabulary',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->run = MigrationRun::query()->create([
        'user_id' => $this->user->id,
        'source_product' => 'ynab4',
        'status' => MigrationRunStatus::Parsed->value,
        'original_filename' => 'vocabulary.zip',
    ]);
});

function umvItem(object $context, UnmappedItemType $type, string $label): int
{
    return (int) DB::table('migration_staging_unmapped_items')->insertGetId([
        'user_id' => $context->user->id,
        'migration_run_id' => $context->run->id,
        'item_type' => $type->value,
        'source_external_id' => 'ext-'.$type->value,
        'entity_type' => MigrationEntityType::Category->value,
        'field_name' => 'name',
        'local_value' => 'local',
        'source_value' => 'source',
        'baseline_value' => 'baseline',
        'display_label' => $label,
        'reason' => 'fixture',
    ]);
}

it('groups the staged rows under every case the enum names', function (): void {
    foreach (UnmappedItemType::cases() as $type) {
        umvItem($this, $type, 'Row '.$type->value);
    }

    $summary = app(PreviewSummaryBuilder::class)->forRun($this->run->id, $this->user);

    foreach (UnmappedItemType::cases() as $type) {
        expect($summary->unmapped[$type->value]['count'])->toBe(1);
    }
});

// resolveConflict() narrows to the conflict rows before it writes, so a
// spelling that drifted would leave the toggle looking saved and change
// nothing in the database.
it('saves a resolution onto a row stored under the conflict case', function (): void {
    $conflictId = umvItem($this, UnmappedItemType::Conflict, 'A real conflict');

    Livewire::test(PreviewMigration::class, ['id' => $this->run->id])
        ->call('resolveConflict', $conflictId, ConflictResolution::TakeSource->value);

    expect(DB::table('migration_staging_unmapped_items')->where('id', $conflictId)->value('resolution'))
        ->toBe(ConflictResolution::TakeSource->value);
});

it('refuses to resolve a row stored under any other case', function (): void {
    $extraId = umvItem($this, UnmappedItemType::Extra, 'Not a conflict');

    Livewire::test(PreviewMigration::class, ['id' => $this->run->id])
        ->call('resolveConflict', $extraId, ConflictResolution::TakeSource->value);

    expect(DB::table('migration_staging_unmapped_items')->where('id', $extraId)->value('resolution'))
        ->toBeNull();
});

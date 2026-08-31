<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Tax\Public\Http\Livewire\TaxSettingsSection;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'tax-category-capture',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

// A category added on the settings screen and then tagged onto a transaction
// reached the peer as a tax_transaction_tags row pointing at a
// deduction_category_id the peer had never been told about.
it('captures a deduction category added from the settings screen', function (): void {
    Event::fake([EntityMutated::class]);

    Livewire::test(TaxSettingsSection::class)
        ->set('newCategoryName', 'Vakliteratuur')
        ->call('addCategory');

    Event::assertDispatched(
        EntityMutated::class,
        static fn (EntityMutated $event): bool => $event->table === 'tax_deduction_categories'
            && $event->mutationType === 'create',
    );
});

it('captures a rename and an archive from the settings screen', function (): void {
    // Faked before the first resolution: the writer is a singleton and keeps
    // the dispatcher it was built with.
    Event::fake([EntityMutated::class]);

    Livewire::test(TaxSettingsSection::class)
        ->set('newCategoryName', 'Vakliteratuur')
        ->call('addCategory');

    $categoryId = (int) DB::table('tax_deduction_categories')
        ->where('user_id', $this->user->id)
        ->value('id');

    Livewire::test(TaxSettingsSection::class)
        ->call('renameCategory', $categoryId, 'Vakboeken')
        ->call('archiveCategory', $categoryId);

    Event::assertDispatchedTimes(EntityMutated::class, 3);
});

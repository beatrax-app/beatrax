<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Public\Enums\PreviewSectionStatus;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectPaypalStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

// The reported symptom this reproduces: the section comes back 'ready' with
// totalRows = 0, meaning the cache holds rows but none have status 'new'.

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-paypal-consolidated',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    $this->paypalFixturePath = base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv');
});

it('produces a non-zero totalRows in the consolidated preview for the stashed paypal run id', function (): void {
    $contents = file_get_contents($this->paypalFixturePath);
    expect($contents)->toBeString();

    $csv = UploadedFile::fake()->createWithContent('activity.csv', $contents);

    Livewire::test(ConnectPaypalStep::class)
        ->set('activityCsv', $csv)
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    $row = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-paypal')
        ->first();
    $stashed = $row->data['paypal_import_run_id'] ?? null;
    expect($stashed)->toBeInt()->toBeGreaterThan(0);

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);
    $batch = $query->build([$stashed], $this->user);

    expect($batch->sections)->toHaveCount(1);
    $section = $batch->sections[0];

    expect($section->sourceFormat)->toBe('paypal-csv');
    expect($section->status)->toBe(PreviewSectionStatus::Ready);
    expect($section->totalRows)->toBeGreaterThan(0);
});

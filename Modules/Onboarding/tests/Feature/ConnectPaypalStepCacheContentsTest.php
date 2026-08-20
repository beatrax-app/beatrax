<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectPaypalStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

// The cached rows must be status 'new', not 'error'. buildSection() counts
// only 'new'/'enriched' towards totalRows but still reports 'ready' on a
// cache hit, so an all-error cache renders as "0 ROWS · READY".

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-paypal-cache-contents',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    $this->paypalFixturePath = base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv');
});

it('caches `new`-status rows for the stashed paypal_import_run_id on first submit', function (): void {
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
    expect($row)->not->toBeNull();
    $stashed = $row->data['paypal_import_run_id'] ?? null;
    expect($stashed)->toBeInt()->toBeGreaterThan(0);

    /** @var PreviewCache $cache */
    $cache = $this->app->make(PreviewCache::class);
    $preview = $cache->getPreview($stashed);
    expect($preview)->not->toBeNull();
    expect($preview->rows)->not->toBeEmpty();

    $newRowCount = 0;
    $errorRowCount = 0;
    foreach ($preview->rows as $previewRow) {
        if ($previewRow->status === 'new') {
            $newRowCount++;
        }
        if ($previewRow->status === 'error') {
            $errorRowCount++;
        }
    }

    expect($errorRowCount)->toBe(0);
    expect($newRowCount)->toBe(count($preview->rows));
});

<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Import\Public\Enums\PreviewSectionStatus;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectPaypalStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

// The returning-user case: an "only re-preview when the action INSERTed"
// guard skips the re-preview when the PayPal account already exists, leaving
// the all-error pre-account cache as the source of truth.

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-paypal-reuse',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    $this->paypalFixturePath = base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv');

    // EnsurePaypalAccountAction now returns false on submit, as it would for
    // a user whose account row came from an earlier import.
    /** @var EnsurePaypalAccountAction $ensure */
    $ensure = $this->app->make(EnsurePaypalAccountAction::class);
    ($ensure)($this->user);

    $existing = Account::query()
        ->where('user_id', $this->user->id)
        ->where('iban', EnsurePaypalAccountAction::PAYPAL_OWN_IBAN)
        ->first();
    expect($existing)->not->toBeNull();
});

it('caches `new`-status rows even when the PayPal account already exists at submit', function (): void {
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

    /** @var PreviewCache $cache */
    $cache = $this->app->make(PreviewCache::class);
    $preview = $cache->getPreview($stashed);
    expect($preview)->not->toBeNull();
    expect($preview->rows)->not->toBeEmpty();

    $newRowCount = 0;
    $errorRowCount = 0;
    foreach ($preview->rows as $previewRow) {
        if ($previewRow->status === PreviewRowStatus::NewRow) {
            $newRowCount++;
        }
        if ($previewRow->status === PreviewRowStatus::Error) {
            $errorRowCount++;
        }
    }

    expect($errorRowCount)->toBe(0);
    expect($newRowCount)->toBe(count($preview->rows));

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);
    $batch = $query->build([$stashed], $this->user);

    expect($batch->sections)->toHaveCount(1);
    expect($batch->sections[0]->status)->toBe(PreviewSectionStatus::Ready);
    expect($batch->sections[0]->totalRows)->toBeGreaterThan(0);
});

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

/*
 * Locks the cache-content invariant for the PayPal connector step: after a
 * successful ConnectPaypalStep submit, the preview cache keyed on the
 * stashed `paypal_import_run_id` MUST contain rows whose status is `'new'`
 * (the rows the FirstImportStep consolidated preview counts towards
 * `totalRows`).
 *
 * The pre-fix behaviour silently leaves the cache in its pre-account-
 * creation state — every row is `'error'` because the AccountResolver
 * returned UnknownAccount for IBAN 'PAYPAL'. That manifests as the
 * consolidated preview rendering "FROM PAYPAL · 0 ROWS · READY" because
 * `BuildConsolidatedPreviewQuery::buildSection()` counts only `'new'` and
 * `'enriched'` rows towards `totalRows`, while still flagging the section
 * as `'ready'` because the cache lookup did not miss.
 */

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

    // The whole point of the connector step: every row must resolve
    // against the just-created synthetic PayPal account, so every row is
    // a fresh insert candidate (status='new') and nothing falls through
    // as a still-unresolved account error.
    expect($errorRowCount)->toBe(0);
    expect($newRowCount)->toBe(count($preview->rows));
});

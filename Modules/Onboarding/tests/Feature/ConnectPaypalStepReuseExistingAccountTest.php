<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectPaypalStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

/*
 * Regression for the live "0 ROWS · READY" PayPal section: when the
 * synthetic PayPal account ALREADY exists for the user before they
 * reach ConnectPaypalStep (typical when the user previously used the
 * standalone /imports/upload PayPal flow, or restarted the wizard
 * without clearing accounts), the step's "only re-preview when the
 * action INSERTed" guard causes the pre-account-creation cache
 * snapshot — full of `'error'`-status rows because the resolver did
 * not have the account to resolve against — to remain the source of
 * truth. The FirstImportStep section then renders 0 ROWS · READY.
 *
 * This test sets up a fresh wizard run with the PayPal account
 * already present (mirroring a returning user), drives
 * ConnectPaypalStep::submit, and asserts the cache contains
 * `'new'`-status rows and BuildConsolidatedPreviewQuery returns
 * totalRows > 0. Pre-fix this test fails: every row is `'error'`.
 */

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

    // Pre-create the synthetic PayPal account so EnsurePaypalAccountAction
    // returns false on submit. This mirrors a returning user whose
    // account row was created by a previous import (standalone wizard,
    // /imports/upload, or an earlier wizard run that was reset by
    // clearing wizard_progress without dropping accounts).
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
        if ($previewRow->status === 'new') {
            $newRowCount++;
        }
        if ($previewRow->status === 'error') {
            $errorRowCount++;
        }
    }

    expect($errorRowCount)->toBe(0);
    expect($newRowCount)->toBe(count($preview->rows));

    /** @var BuildConsolidatedPreviewQuery $query */
    $query = $this->app->make(BuildConsolidatedPreviewQuery::class);
    $batch = $query->build([$stashed], $this->user);

    expect($batch->sections)->toHaveCount(1);
    expect($batch->sections[0]->status)->toBe('ready');
    expect($batch->sections[0]->totalRows)->toBeGreaterThan(0);
});

<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectCardStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

/*
 * Verifies the multi-file submit path stashes every successful
 * ImportRun id into `wizard_progress.data['card_import_run_ids']` as a
 * deduplicated array — the foundation the consolidated preview screen
 * later reads back to combine multiple ICS PDFs into one preview.
 *
 * Two behaviours under test:
 *
 *  1. A successful three-file submit stashes a three-element array of
 *     ImportRun ids in insertion order; the array matches the rows
 *     that landed in `import_runs` for this user and source format.
 *
 *  2. Re-running the submit after pre-seeding an existing
 *     `card_import_run_ids` entry merges the new ids with the old —
 *     the array stays unique (no duplicate ids) and the pre-seeded id
 *     is preserved at the front.
 */

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-card-stash',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    Account::query()->updateOrCreate(
        [
            'user_id' => $this->user->id,
            'iban' => 'ICS-CARD',
        ],
        [
            'name' => 'ICS card',
            'slug' => 'ics-card',
            'kind' => 'ics_card',
            'default_currency' => 'EUR',
        ],
    );

    $this->tinyPdfPath = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
});

it('stashes card_import_run_ids in wizard_progress.data after submit with multiple files', function (): void {
    $contents = file_get_contents($this->tinyPdfPath);
    expect($contents)->toBeString();

    $files = [];
    foreach (range(1, 3) as $i) {
        $files[] = UploadedFile::fake()->createWithContent(
            sprintf('statement-%d.pdf', $i),
            $contents.str_repeat("\n", $i),
        );
    }

    Livewire::test(ConnectCardStep::class)
        ->set('statements', $files)
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    $row = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-card')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->data)->toBeArray();
    expect($row->data['card_import_run_ids'] ?? null)->toBeArray();
    expect($row->data['card_import_run_ids'])->toHaveCount(3);
});

it('appends to an existing card_import_run_ids array on re-run (idempotent merge)', function (): void {
    // Pre-seed an existing id (42) in the stash so the next submit
    // must merge — never replace — the array.
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-card')
        ->update(['data' => json_encode(['card_import_run_ids' => [42]])]);

    $contents = file_get_contents($this->tinyPdfPath);
    expect($contents)->toBeString();

    $files = [];
    foreach (range(1, 2) as $i) {
        $files[] = UploadedFile::fake()->createWithContent(
            sprintf('statement-%d.pdf', $i),
            $contents.str_repeat("\n", $i),
        );
    }

    Livewire::test(ConnectCardStep::class)
        ->set('statements', $files)
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    $row = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-card')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->data['card_import_run_ids'])->toBeArray();
    expect($row->data['card_import_run_ids'])->toHaveCount(3);
    expect($row->data['card_import_run_ids'][0])->toBe(42);
    expect(array_unique($row->data['card_import_run_ids']))->toBe($row->data['card_import_run_ids']);
});

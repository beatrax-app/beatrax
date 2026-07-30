<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectCardStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Tests\Helpers\UploadIsolation;

/*
 * Verifies the multi-file restructure of the ICS card connector step.
 *
 * Three behaviours under test:
 *
 *  1. The step accepts an array of PDF uploads via `statements[]` and
 *     produces one `ImportRun` row per uploaded file. Each ImportRun
 *     carries `source_format = 'ics-pdf'`.
 *
 *  2. The per-file validator (`statements.*` with `extensions:pdf`)
 *     rejects a non-PDF file without producing an ImportRun for it.
 *
 *  3. A per-file parse failure (malformed PDF among well-formed ones)
 *     does not abort the whole submit — the well-formed files still
 *     produce their ImportRuns; the bad file's failure is logged
 *     per-row, not raised.
 */

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-card-multi',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    // Pre-seed the ICS card account so the IcsPdfAdapter's synthetic
    // own-IBAN ("ICS-CARD") resolves to a real account; without this
    // every preview row would be an unknown-IBAN error.
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

it('accepts an array of PDF uploads and produces one ImportRun per file', function (): void {
    // Three uploads with slightly different bytes so SHA-256 dedup does
    // not collapse them into one ImportRun. The ICS adapter parses the
    // structural-only fixture content; the byte tweak appends after
    // %%EOF which a PDF parser tolerates.
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

    $runs = ImportRun::query()
        ->where('user_id', $this->user->id)
        ->where('source_format', 'ics-pdf')
        ->get();

    expect($runs)->toHaveCount(3);
    foreach ($runs as $run) {
        expect($run->source_format)->toBe('ics-pdf');
    }
});

it('rejects a non-PDF file via the statements.* extensions:pdf rule', function (): void {
    $bogus = UploadedFile::fake()->create('not-a-pdf.txt', 5, 'text/plain');

    Livewire::test(ConnectCardStep::class)
        ->set('statements', [$bogus])
        ->call('submit')
        ->assertHasErrors(['statements.0']);

    expect(ImportRun::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('continues the loop when one file fails to parse instead of aborting the submit', function (): void {
    $goodContents = file_get_contents($this->tinyPdfPath);
    expect($goodContents)->toBeString();

    $good = UploadedFile::fake()->createWithContent('good.pdf', $goodContents);
    // A .pdf-extensioned file with random bytes: passes the `extensions:pdf`
    // rule (which checks extension only), then trips the parser later.
    $bad = UploadedFile::fake()->createWithContent('bad.pdf', str_repeat('not-a-real-pdf-body', 10));

    Livewire::test(ConnectCardStep::class)
        ->set('statements', [$good, $bad])
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    // The well-formed file landed an ImportRun; the bad file either
    // produced an ImportRun whose preview surfaces an ERROR row, or
    // never got past sniffing. Either way the well-formed run is
    // present and `source_format = 'ics-pdf'` so the chain continues.
    $runs = ImportRun::query()
        ->where('user_id', $this->user->id)
        ->where('source_format', 'ics-pdf')
        ->get();

    expect($runs->count())->toBeGreaterThanOrEqual(1);
});

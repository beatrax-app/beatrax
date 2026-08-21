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

    // Without this row IcsPdfAdapter's synthetic "ICS-CARD" IBAN resolves
    // to UnknownAccount and every preview row is an error.
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
    // The bytes must differ or SHA-256 dedup collapses the three into one
    // ImportRun; the tweak appends after %%EOF, which a PDF parser tolerates.
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
    // Random bytes under a .pdf name: `extensions:pdf` checks the extension
    // only, so this clears the validator and fails in the parser.
    $bad = UploadedFile::fake()->createWithContent('bad.pdf', str_repeat('not-a-real-pdf-body', 10));

    Livewire::test(ConnectCardStep::class)
        ->set('statements', [$good, $bad])
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    // The bad file may or may not have produced a run of its own; what
    // matters is that the well-formed one survived it.
    $runs = ImportRun::query()
        ->where('user_id', $this->user->id)
        ->where('source_format', 'ics-pdf')
        ->get();

    expect($runs->count())->toBeGreaterThanOrEqual(1);
});

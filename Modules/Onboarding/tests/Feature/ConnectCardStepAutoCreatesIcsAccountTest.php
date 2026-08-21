<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectCardStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Models\WizardProgress;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::query()->create([
        'username' => 'connect-card-autocreate',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    $this->tinyPdfPath = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
});

it('creates the ICS card account and stashes run ids when none exists yet', function (): void {
    expect(Account::query()->where('user_id', $this->user->id)->where('iban', 'ICS-CARD')->exists())->toBeFalse();

    $contents = file_get_contents($this->tinyPdfPath);
    expect($contents)->toBeString();

    $file = UploadedFile::fake()->createWithContent('statement.pdf', $contents);

    Livewire::test(ConnectCardStep::class)
        ->set('statements', [$file])
        ->call('submit')
        ->assertDispatched('wizard.step.completed');

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('iban', 'ICS-CARD')
        ->first();

    expect($account)->not->toBeNull();
    expect($account->kind)->toBe('ics_card');

    $progress = WizardProgress::query()
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-card')
        ->first();

    expect($progress)->not->toBeNull();
    $stashed = $progress->data['card_import_run_ids'] ?? [];
    expect($stashed)->toBeArray()->not->toBeEmpty();
});

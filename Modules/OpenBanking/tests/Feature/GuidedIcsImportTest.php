<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\ImportRun;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Tests\Helpers\UploadIsolation;

uses(RefreshDatabase::class);

function guiUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function guiSecretsPath(): string
{
    return storage_path('app/secrets/open-banking.json');
}

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->tinyPdfPath = base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf');
});

afterEach(function (): void {
    $path = guiSecretsPath();
    if (is_file($path)) {
        @unlink($path);
    }
    if (is_file($path.'.tmp')) {
        @unlink($path.'.tmp');
    }
});

it('renders the always-visible ICS card under the no-credentials-stored label with the #ics-import anchor', function (): void {
    $user = guiUser('gui-renders-card');
    $this->actingAs($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->assertSeeHtml('id="ics-import"')
        ->assertSee('File import — no credentials stored')
        ->assertSee('ICS credit card statement')
        ->assertSeeHtml('data-testid="ob-ics-drop-zone"')
        ->assertDontSeeHtml('data-testid="ob-ics-import-button"');
});

it('dropping a PDF statement reveals the Import statement CTA without auto-submitting', function (): void {
    $user = guiUser('gui-no-auto-submit');
    $this->actingAs($user);

    $contents = file_get_contents($this->tinyPdfPath);
    expect($contents)->toBeString();

    Livewire::test(OpenBankingSettingsPage::class)
        ->set('icsStatement', UploadedFile::fake()->createWithContent('statement.pdf', $contents))
        ->assertSeeHtml('data-testid="ob-ics-import-button"');

    expect(ImportRun::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('clicking Import statement routes the dropped file through the EXISTING ics-pdf adapter into the existing preview, storing zero OB credentials', function (): void {
    $user = guiUser('gui-routes-existing-adapter');
    $this->actingAs($user);

    expect(is_file(guiSecretsPath()))->toBeFalse();

    $contents = file_get_contents($this->tinyPdfPath);
    expect($contents)->toBeString();

    Livewire::test(OpenBankingSettingsPage::class)
        ->set('icsStatement', UploadedFile::fake()->createWithContent('statement.pdf', $contents))
        ->call('importIcsStatement')
        ->assertRedirect();

    $run = ImportRun::query()->where('user_id', $user->id)->first();
    expect($run)->not->toBeNull();
    expect($run->source_format)->toBe('ics-pdf');

    expect(is_file(guiSecretsPath()))->toBeFalse();

    expect(DB::table('open_banking_connections')->where('user_id', $user->id)->count())->toBe(0);

    $preview = $this->get(route('imports.preview', ['id' => $run->id]));
    $preview->assertOk();
});

it('a non-PDF drop is rejected with an inline validation error and never reaches the importer', function (): void {
    $user = guiUser('gui-rejects-non-pdf');
    $this->actingAs($user);

    Livewire::test(OpenBankingSettingsPage::class)
        ->set('icsStatement', UploadedFile::fake()->create('statement.txt', 10))
        ->call('importIcsStatement')
        ->assertHasErrors(['icsStatement']);

    expect(ImportRun::query()->where('user_id', $user->id)->count())->toBe(0);
    expect(is_file(guiSecretsPath()))->toBeFalse();
});

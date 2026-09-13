<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Import\Internal\Services\AliasYamlImporter;
use Modules\Import\Models\MerchantAlias;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::create([
        'username' => 'twice-named-alias-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

// Two exports concatenated, or one file hand-edited: the same pattern under
// two names. merchant_aliases is unique on (user_id, pattern), so the repeat
// hit the constraint inside apply()'s transaction and rolled the whole import
// back — after a diff that had called both of them new.
function twiceNamedAliasYaml(): string
{
    return <<<'YAML'
entries:
  - pattern: SHELL STATION 0042
    name: Shell
  - pattern: SPOTIFY AB
    name: Spotify
  - pattern: SHELL STATION 0042
    name: Shell Express
YAML;
}

it('classifies the second mention of a pattern as a conflict with the first', function (): void {
    /** @var AliasYamlImporter $importer */
    $importer = app(AliasYamlImporter::class);
    $diff = $importer->diff($this->user, $importer->parse(twiceNamedAliasYaml()));

    // Before: new = 3, conflicts = 0.
    expect($diff['new'])->toHaveCount(2);
    expect($diff['conflicts'])->toHaveCount(1);
    expect($diff['conflicts'][0]['entry']->name)->toBe('Shell Express');
    expect($diff['conflicts'][0]['existing_name'])->toBe('Shell');
});

it('writes the first mention and leaves the repeat to the conflict answer', function (): void {
    /** @var AliasYamlImporter $importer */
    $importer = app(AliasYamlImporter::class);
    $entries = $importer->parse(twiceNamedAliasYaml());

    // Before: UniqueConstraintViolationException, and zero rows written.
    $changed = $importer->apply($this->user, $entries, []);

    expect($changed)->toBe(2);
    expect(DB::table('merchant_aliases')->where('user_id', $this->user->id)->pluck('friendly_name')->all())
        ->toBe(['Shell', 'Spotify']);
});

it('lets the reader answer the repeat with replace', function (): void {
    /** @var AliasYamlImporter $importer */
    $importer = app(AliasYamlImporter::class);
    $entries = $importer->parse(twiceNamedAliasYaml());

    $importer->apply($this->user, $entries, ['SHELL STATION 0042' => 'replace']);

    $row = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->where('pattern', 'SHELL STATION 0042')
        ->first(['friendly_name']);

    expect($row?->friendly_name)->toBe('Shell Express');
    expect(DB::table('merchant_aliases')->where('user_id', $this->user->id)->count())->toBe(2);
});

it('carries the file through the settings page without a crash', function (): void {
    Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', UploadedFile::fake()->createWithContent('aliases.yaml', twiceNamedAliasYaml()))
        ->call('parseUpload')
        ->assertSet('importError', '')
        ->call('confirmImport')
        ->assertSet('importDiff', []);

    expect(DB::table('merchant_aliases')->where('user_id', $this->user->id)->count())->toBe(2);
});

// The stored row already answers the first mention, so the repeat is weighed
// against the row rather than against a mention that changed nothing.
it('weighs the repeat against the row already stored when the first mention changed nothing', function (): void {
    MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'SHELL STATION 0042',
        'generalized_pattern' => 'shell station',
        'friendly_name' => 'Shell',
    ]);

    /** @var AliasYamlImporter $importer */
    $importer = app(AliasYamlImporter::class);
    $diff = $importer->diff($this->user, $importer->parse(twiceNamedAliasYaml()));

    expect($diff['new'])->toHaveCount(1);
    expect($diff['unchanged'])->toHaveCount(1);
    expect($diff['conflicts'])->toHaveCount(1);
    expect($diff['conflicts'][0]['entry']->name)->toBe('Shell Express');
});

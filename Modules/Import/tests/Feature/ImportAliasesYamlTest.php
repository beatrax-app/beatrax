<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Import\Models\MerchantAlias;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::create([
        'username' => 'yaml-import-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    // Two rows: one the YAML will match exactly, one it will conflict with on
    // the name. Each generalized_pattern is what PatternGeneralizer produces
    // for its pattern, or the diff misclassifies the row.
    MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'SHELL-PATTERN',
        'generalized_pattern' => 'shell-pattern',
        'friendly_name' => 'Shell',
    ]);
    MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'AH-PATTERN',
        'generalized_pattern' => 'ah-pattern',
        'friendly_name' => 'Albert Heijn original',
    ]);
});

// SPOTIFY is new, SHELL-PATTERN matches the seeded row exactly, AH-PATTERN
// collides with it on the name.
function aliasYamlFixture(): string
{
    return <<<'YAML'
entries:
  - pattern: SPOTIFY-PATTERN
    name: Spotify
    category: null
    region: null
    contributor: user
  - pattern: SHELL-PATTERN
    name: Shell
    category: null
    region: null
    contributor: user
  - pattern: AH-PATTERN
    name: 'Albert Heijn'
    category: null
    region: null
    contributor: user
YAML;
}

it('parses an upload + classifies entries as new / unchanged / conflict', function (): void {
    $file = UploadedFile::fake()->createWithContent('aliases.yaml', aliasYamlFixture());

    $component = Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', $file)
        ->call('parseUpload');

    /** @var AliasesSettingsPage $instance */
    $instance = $component->instance();

    expect($instance->importError)->toBe('');
    expect($instance->importDiff['new'])->toHaveCount(1);
    expect($instance->importDiff['unchanged'])->toHaveCount(1);
    expect($instance->importDiff['conflicts'])->toHaveCount(1);

    expect($instance->conflictResolutions)->toHaveKey('AH-PATTERN');
    expect($instance->conflictResolutions['AH-PATTERN'])->toBe('keep');
});

it('confirms an import with default keep resolutions and inserts only the new entry', function (): void {
    $file = UploadedFile::fake()->createWithContent('aliases.yaml', aliasYamlFixture());

    Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', $file)
        ->call('parseUpload')
        ->call('confirmImport');

    $rows = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->orderBy('pattern')
        ->get();
    expect($rows)->toHaveCount(3);

    $ah = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->where('pattern', 'AH-PATTERN')
        ->first();
    expect($ah->friendly_name)->toBe('Albert Heijn original');

    $spotify = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->where('pattern', 'SPOTIFY-PATTERN')
        ->first();
    expect($spotify)->not->toBeNull();
    expect($spotify->friendly_name)->toBe('Spotify');
});

it('replaces the conflict friendly name when the user picks the replace resolution', function (): void {
    $file = UploadedFile::fake()->createWithContent('aliases.yaml', aliasYamlFixture());

    Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', $file)
        ->call('parseUpload')
        ->set('conflictResolutions.AH-PATTERN', 'replace')
        ->call('confirmImport');

    $ah = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->where('pattern', 'AH-PATTERN')
        ->first();
    expect($ah->friendly_name)->toBe('Albert Heijn');
});

it('surfaces an inline error on malformed YAML without writing anything', function (): void {
    $rowsBefore = DB::table('merchant_aliases')->count();

    // The .yaml extension passes file validation; the body is what fails.
    $file = UploadedFile::fake()->createWithContent('aliases.yaml', "this is not: valid:\n  - yaml: [\n");

    $component = Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', $file)
        ->call('parseUpload');

    /** @var AliasesSettingsPage $instance */
    $instance = $component->instance();

    expect($instance->importError)->not->toBe('');
    expect($instance->importDiff)->toBe([]);
    expect(DB::table('merchant_aliases')->count())->toBe($rowsBefore);
});

it('rejects a .txt upload at the file-validation layer before parse runs', function (): void {
    $file = UploadedFile::fake()->createWithContent('aliases.txt', "entries:\n  - pattern: x\n    name: x\n");

    Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', $file)
        ->call('parseUpload')
        ->assertHasErrors(['importFile']);
});

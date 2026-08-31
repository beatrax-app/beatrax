<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::create([
        'username' => 'alias-yaml-locale-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

function aliasImportErrorIn(User $user, string $locale, string $body): string
{
    App::setLocale($locale);

    $component = Livewire::actingAs($user)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', UploadedFile::fake()->createWithContent('aliases.yaml', $body))
        ->call('parseUpload');

    /** @var AliasesSettingsPage $instance */
    $instance = $component->instance();

    return $instance->importError;
}

const ALIAS_YAML_NOT_YAML = "this is not: valid:\n  - yaml: [\n";

const ALIAS_YAML_NO_ENTRIES = "aliases:\n  - pattern: X\n";

const ALIAS_YAML_ENTRY_NOT_A_MAPPING = "entries:\n  - just-a-string\n  - just-another\n";

const ALIAS_YAML_ENTRY_MISSING_FIELDS = "entries:\n  - pattern: X\n    name: X\n  - pattern: Y\n";

it('says a file is not YAML in the language the reader is reading', function (): void {
    $dutch = aliasImportErrorIn($this->user, 'nl', ALIAS_YAML_NOT_YAML);

    App::setLocale('nl');
    expect($dutch)->toBe(Lang::get('import::aliases.errors.file_not_yaml'));

    $english = aliasImportErrorIn($this->user, 'en', ALIAS_YAML_NOT_YAML);

    expect($english)->not->toBe($dutch)
        ->and($dutch)->not->toContain('import::aliases')
        ->and($dutch)->not->toContain('YAML document');
});

it('says a file carries no entries list in the language the reader is reading', function (): void {
    $dutch = aliasImportErrorIn($this->user, 'nl', ALIAS_YAML_NO_ENTRIES);

    App::setLocale('nl');
    expect($dutch)->toBe(Lang::get('import::aliases.errors.file_has_no_entries_list'));

    expect(aliasImportErrorIn($this->user, 'en', ALIAS_YAML_NO_ENTRIES))->not->toBe($dutch)
        ->and($dutch)->not->toContain("top-level 'entries'");
});

it('numbers the entry it refused and still says it in the reader language', function (): void {
    $dutch = aliasImportErrorIn($this->user, 'nl', ALIAS_YAML_ENTRY_NOT_A_MAPPING);

    App::setLocale('nl');
    expect($dutch)->toBe(Lang::get('import::aliases.errors.entry_is_not_a_mapping', ['entry' => '1']))
        ->and($dutch)->toContain('1');

    expect(aliasImportErrorIn($this->user, 'en', ALIAS_YAML_ENTRY_NOT_A_MAPPING))->not->toBe($dutch)
        ->and($dutch)->not->toContain('is not a mapping');
});

it('names the entry that is missing a pattern or a name in the reader language', function (): void {
    $dutch = aliasImportErrorIn($this->user, 'nl', ALIAS_YAML_ENTRY_MISSING_FIELDS);

    App::setLocale('nl');
    expect($dutch)->toBe(Lang::get('import::aliases.errors.entry_is_missing_a_field', ['entry' => '2']))
        ->and($dutch)->toContain('2');

    expect(aliasImportErrorIn($this->user, 'en', ALIAS_YAML_ENTRY_MISSING_FIELDS))->not->toBe($dutch);
});

it('resolves every refusal in all twenty-six shipped locales', function (): void {
    $wrong = [];

    $bodies = [
        'file_not_yaml' => ALIAS_YAML_NOT_YAML,
        'file_has_no_entries_list' => ALIAS_YAML_NO_ENTRIES,
        'entry_is_not_a_mapping' => ALIAS_YAML_ENTRY_NOT_A_MAPPING,
        'entry_is_missing_a_field' => ALIAS_YAML_ENTRY_MISSING_FIELDS,
    ];
    $entries = [
        'file_not_yaml' => '0',
        'file_has_no_entries_list' => '0',
        'entry_is_not_a_mapping' => '1',
        'entry_is_missing_a_field' => '2',
    ];

    foreach (glob(base_path('Modules/Import/Resources/lang/*/aliases.php')) ?: [] as $file) {
        $locale = basename(dirname($file));

        foreach ($bodies as $key => $body) {
            $shown = aliasImportErrorIn($this->user, $locale, $body);

            App::setLocale($locale);
            $expected = Lang::get('import::aliases.errors.'.$key, ['entry' => $entries[$key]]);

            if ($shown !== $expected) {
                $wrong[] = $locale.'/'.$key.': '.$shown;
            }
        }
    }

    expect($wrong)->toBe([]);
});

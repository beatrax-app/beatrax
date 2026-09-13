<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Import\Internal\Services\AliasYamlImporter;
use Modules\Import\Models\MerchantAlias;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->reader = User::create([
        'username' => 'hostile-alias-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->stranger = User::create([
        'username' => 'hostile-alias-stranger',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

// `pattern` is the raw description a row arrived with, and the resolver's
// exact tier is keyed on that string, so two casings are two descriptions and
// deliberately two aliases. Pinned because the unique index would otherwise
// look like it meant to catch them.
it('keeps an alias spelled in another case as an alias of its own', function (): void {
    MerchantAlias::create([
        'user_id' => $this->reader->id,
        'pattern' => 'SHELL 42',
        'generalized_pattern' => 'shell 42',
        'friendly_name' => 'Shell',
    ]);

    /** @var AliasYamlImporter $importer */
    $importer = app(AliasYamlImporter::class);
    $entries = $importer->parse("entries:\n  - pattern: 'shell 42'\n    name: Shell Lower\n");

    expect($importer->apply($this->reader, $entries, []))->toBe(1);
    expect(DB::table('merchant_aliases')->where('user_id', $this->reader->id)->pluck('pattern')->all())
        ->toBe(['SHELL 42', 'shell 42']);
});

// The same raw description with an editor's stray spaces around it is the same
// description, so it collapses onto the stored row rather than doubling it.
it('collapses an alias that differs from the stored one only by surrounding space', function (): void {
    MerchantAlias::create([
        'user_id' => $this->reader->id,
        'pattern' => 'SHELL 42',
        'generalized_pattern' => 'shell 42',
        'friendly_name' => 'Shell',
    ]);

    /** @var AliasYamlImporter $importer */
    $importer = app(AliasYamlImporter::class);
    $diff = $importer->diff($this->reader, $importer->parse("entries:\n  - pattern: '  SHELL 42  '\n    name: Shell Spaced\n"));

    expect($diff['new'])->toHaveCount(0);
    expect($diff['conflicts'])->toHaveCount(1);
});

// A checkbox sends its value as a string, and no test had ever handed the
// component one: the filter that drops the deleted id from the selection is
// typed int, so the wire's own shape is the shape it had never seen.
it('drops the deleted id from a selection the wire sent as strings', function (): void {
    $kept = MerchantAlias::create([
        'user_id' => $this->reader->id,
        'pattern' => 'KEPT 01',
        'generalized_pattern' => 'kept 01',
        'friendly_name' => 'Kept',
    ]);
    $doomed = MerchantAlias::create([
        'user_id' => $this->reader->id,
        'pattern' => 'DOOMED 01',
        'generalized_pattern' => 'doomed 01',
        'friendly_name' => 'Doomed',
    ]);

    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [(string) $kept->id, (string) $doomed->id])
        ->call('deleteAlias', (string) $doomed->id)
        ->assertSet('selectedIds', [(string) $kept->id]);

    expect(DB::table('merchant_aliases')->where('id', $doomed->id)->exists())->toBeFalse();
});

// A UserScope sits on the model, so a cross-user read through it answers false
// whether or not the row survived. The table is read directly instead.
it('leaves a stranger alias named in the selection untouched by a merge', function (): void {
    $mine = MerchantAlias::create([
        'user_id' => $this->reader->id,
        'pattern' => 'MINE 01',
        'generalized_pattern' => 'mine 01',
        'friendly_name' => 'Mine',
    ]);
    $theirs = MerchantAlias::create([
        'user_id' => $this->stranger->id,
        'pattern' => 'THEIRS 01',
        'generalized_pattern' => 'theirs 01',
        'friendly_name' => 'Theirs',
    ]);

    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$mine->id, $theirs->id])
        ->call('openMergeModal')
        ->assertSet('showMergeModal', false);

    $row = DB::table('merchant_aliases')->where('id', $theirs->id)->first(['user_id', 'friendly_name']);
    expect($row?->user_id)->toBe($this->stranger->id);
    expect($row?->friendly_name)->toBe('Theirs');
});

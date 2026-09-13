<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Contracts\MergesCounterparties;
use Modules\Counterparties\Public\Dto\CounterpartyMergeDto;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Import\Models\MerchantAlias;
use Modules\Import\Public\Services\AliasMatchPreviewQuery;
use Tests\Helpers\UploadIsolation;

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->reader = User::create([
        'username' => 'alias-refusals-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->first = MerchantAlias::create([
        'user_id' => $this->reader->id,
        'pattern' => 'SHELL 0001',
        'generalized_pattern' => 'shell 0001',
        'friendly_name' => 'Shell',
    ]);
    $this->second = MerchantAlias::create([
        'user_id' => $this->reader->id,
        'pattern' => 'SHELL 0002',
        'generalized_pattern' => 'shell 0002',
        'friendly_name' => 'Shell Two',
    ]);
});

it('says a row is gone rather than opening an editor over nothing', function (): void {
    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->call('startEdit', $this->first->id + 9_000)
        ->assertSet('flashMessage', 'Alias not found (it may have been deleted in another tab).')
        ->assertSet('editingId', 0);
});

it('drops the editor and the preview together when the edit is cancelled', function (): void {
    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->call('startEdit', $this->first->id)
        ->set('editingPattern', 'shell')
        ->call('cancelEdit')
        ->assertSet('editingId', 0)
        ->assertSet('editingPattern', '')
        ->assertSet('previewResult', []);
});

it('refuses to save an emptied pattern and leaves the stored one standing', function (): void {
    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->call('startEdit', $this->first->id)
        ->set('editingPattern', '   ')
        ->call('saveAlias', $this->first->id)
        ->assertSet('flashMessage', 'Generalized pattern cannot be empty.');

    expect(DB::table('merchant_aliases')->where('id', $this->first->id)->value('generalized_pattern'))
        ->toBe('shell 0001');
});

it('keeps the merge dialog open when a required field was emptied', function (): void {
    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$this->first->id, $this->second->id])
        ->call('openMergeModal')
        ->set('mergeFriendlyName', '  ')
        ->call('confirmMerge')
        ->assertSet('flashMessage', 'Friendly name and generalized pattern are both required.')
        ->assertSet('showMergeModal', true);
});

// Each refusal leaves the screen somewhere different, which is the whole
// reason merged() answers a bool rather than a message. The floor is
// AliasMatchPreviewQuery::MIN_PATTERN_LENGTH, which 'abc' sits exactly on.
it('keeps the dialog and the selection when the pattern is under the floor', function (): void {
    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$this->first->id, $this->second->id])
        ->call('openMergeModal')
        ->set('mergeGeneralizedPattern', str_repeat('a', AliasMatchPreviewQuery::MIN_PATTERN_LENGTH - 1))
        ->set('mergeFriendlyName', 'Shell')
        ->call('confirmMerge')
        ->assertSet('flashMessage', 'Pattern is too short to test.')
        ->assertSet('showMergeModal', true)
        ->assertSet('selectedIds', [$this->first->id, $this->second->id]);

    expect(DB::table('merchant_aliases')->where('user_id', $this->reader->id)->count())->toBe(2);
});

it('clears the dialog and the selection when a selected row has gone', function (): void {
    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$this->first->id + 9_000, $this->second->id + 9_000])
        ->set('showMergeModal', true)
        ->set('mergeGeneralizedPattern', 'shell')
        ->set('mergeFriendlyName', 'Shell')
        ->call('confirmMerge')
        ->assertSet('flashMessage', 'One or more aliases were not found (they may have been deleted in another tab).')
        ->assertSet('showMergeModal', false)
        ->assertSet('selectedIds', []);
});

// An unknown failure closes the dialog and keeps the selection, so the reader
// can try again without re-ticking every row.
it('names the failure class and keeps the selection when the merge breaks', function (): void {
    app()->bind(MergesCounterparties::class, fn (): MergesCounterparties => new class implements MergesCounterparties
    {
        /**
         * @param  list<string>  $formerNames
         */
        public function fold(User $user, array $formerNames, string $survivingName): CounterpartyMergeDto
        {
            throw new LogicException('the counterparty fold is unavailable');
        }
    });

    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$this->first->id, $this->second->id])
        ->call('openMergeModal')
        ->set('mergeGeneralizedPattern', 'shell')
        ->set('mergeFriendlyName', 'Shell')
        ->call('confirmMerge')
        ->assertSet('flashMessage', 'Merge failed (LogicException).')
        ->assertSet('showMergeModal', false)
        ->assertSet('selectedIds', [$this->first->id, $this->second->id]);

    expect(DB::table('merchant_aliases')->where('user_id', $this->reader->id)->count())->toBe(2);
});

it('empties the merge dialog when it is dismissed', function (): void {
    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$this->first->id, $this->second->id])
        ->call('openMergeModal')
        ->call('cancelMerge')
        ->assertSet('showMergeModal', false)
        ->assertSet('mergeGeneralizedPattern', '')
        ->assertSet('mergeFriendlyName', '');
});

it('says no file was uploaded rather than reading one that is not there', function (): void {
    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->call('parseUpload')
        ->assertSet('importError', 'No file uploaded.')
        ->assertSet('importDiff', []);
});

it('names a pattern under the floor as the reason the file was refused', function (): void {
    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', UploadedFile::fake()->createWithContent(
            'aliases.yaml',
            "entries:\n  - pattern: 'ab'\n    name: Ab\n",
        ))
        ->call('parseUpload')
        ->assertSet('importError', 'Pattern is too short to test.')
        ->assertSet('importDiff', []);
});

// Every entry unchanged, which needs the stored generalized_pattern to be the
// one the generalizer derives from the pattern -- 'SHELL 0001' generalizes to
// 'shell', so the seeded rows above would each read as a conflict instead.
it('says an import changed nothing rather than reporting a count of none', function (): void {
    MerchantAlias::create([
        'user_id' => $this->reader->id,
        'pattern' => 'SHELL-PATTERN',
        'generalized_pattern' => 'shell-pattern',
        'friendly_name' => 'Shell',
    ]);

    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', UploadedFile::fake()->createWithContent(
            'aliases.yaml',
            "entries:\n  - pattern: 'SHELL-PATTERN'\n    name: Shell\n",
        ))
        ->call('parseUpload')
        ->call('confirmImport')
        ->assertSet('flashMessage', 'Nothing to import.')
        ->assertSet('importDiff', [])
        ->assertSet('importFile', null);

    expect(DB::table('merchant_aliases')->where('user_id', $this->reader->id)->count())->toBe(3);
});

it('drops a parsed diff the reader walked away from', function (): void {
    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', UploadedFile::fake()->createWithContent(
            'aliases.yaml',
            "entries:\n  - pattern: 'SPOTIFY AB'\n    name: Spotify\n",
        ))
        ->call('parseUpload')
        ->call('cancelImport')
        ->assertSet('importDiff', [])
        ->assertSet('importError', '')
        ->call('clearFlash')
        ->assertSet('flashMessage', '');

    expect(DB::table('merchant_aliases')->where('user_id', $this->reader->id)->count())->toBe(2);
});

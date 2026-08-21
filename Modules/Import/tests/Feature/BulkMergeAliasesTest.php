<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Import\Models\MerchantAlias;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'bulk-merge-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->foreigner = User::create([
        'username' => 'bulk-merge-foreigner',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->shellA = MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'BCK*SHELL PIETER NIEUW *0001',
        'generalized_pattern' => 'shell pieter nieuw a',
        'friendly_name' => 'Shell Pieter A',
    ]);
    $this->shellB = MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'BCK*SHELL PIETER NIEUW *0002',
        'generalized_pattern' => 'shell pieter nieuw b',
        'friendly_name' => 'Shell Pieter B',
    ]);
    $this->shellC = MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'BCK*SHELL PIETER NIEUW *0003',
        'generalized_pattern' => 'shell pieter nieuw c',
        'friendly_name' => 'Shell Pieter C',
    ]);
});

it('opens the merge dialog with the LCP prefilled and the first selected friendly name', function (): void {
    Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$this->shellA->id, $this->shellB->id, $this->shellC->id])
        ->call('openMergeModal')
        ->assertSet('showMergeModal', true)
        ->assertSet('mergeGeneralizedPattern', 'shell pieter nieuw')
        ->assertSet('mergeFriendlyName', 'Shell Pieter A');
});

it('confirms the merge and preserves merged_from provenance with 2 absorbed entries', function (): void {
    Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$this->shellA->id, $this->shellB->id, $this->shellC->id])
        ->call('openMergeModal')
        ->set('mergeFriendlyName', 'Shell Pieter')
        ->set('mergeGeneralizedPattern', 'shell pieter nieuw')
        ->call('confirmMerge')
        ->assertSet('showMergeModal', false)
        ->assertSet('flashMessage', 'Aliases merged.');

    $remaining = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->get();
    expect($remaining)->toHaveCount(1);

    /** @var stdClass $survivor */
    $survivor = $remaining->first();
    expect($survivor->friendly_name)->toBe('Shell Pieter');
    expect($survivor->generalized_pattern)->toBe('shell pieter nieuw');

    $mergedFrom = is_string($survivor->merged_from) ? json_decode($survivor->merged_from, true) : [];
    expect($mergedFrom)->toHaveCount(2);
});

it('returns an empty mergeGeneralizedPattern when the selected aliases share no 4-char prefix', function (): void {
    $shell = MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'SHELL-RAW',
        'generalized_pattern' => 'shell',
        'friendly_name' => 'Shell',
    ]);
    $spotify = MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'SPOTIFY-RAW',
        'generalized_pattern' => 'spotify',
        'friendly_name' => 'Spotify',
    ]);

    Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$shell->id, $spotify->id])
        ->call('openMergeModal')
        ->assertSet('showMergeModal', true)
        ->assertSet('mergeGeneralizedPattern', '');
});

it('refuses to merge an alias belonging to a different user via the calm-flash 404-not-403 surface', function (): void {
    $foreignAlias = MerchantAlias::create([
        'user_id' => $this->foreigner->id,
        'pattern' => 'FOREIGNER-RAW',
        'generalized_pattern' => 'foreigner',
        'friendly_name' => 'Foreigner',
    ]);

    Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$this->shellA->id, $foreignAlias->id])
        ->call('openMergeModal')
        ->assertSet('showMergeModal', false)
        ->assertSet('flashMessage', 'One or more selected aliases were not found.');

    expect(DB::table('merchant_aliases')->where('id', $foreignAlias->id)->exists())->toBeTrue();
});

it('refuses to open the merge modal when fewer than two aliases are selected', function (): void {
    Livewire::actingAs($this->user)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$this->shellA->id])
        ->call('openMergeModal')
        ->assertSet('showMergeModal', false)
        ->assertSet('flashMessage', 'Select at least two aliases to merge.');
});

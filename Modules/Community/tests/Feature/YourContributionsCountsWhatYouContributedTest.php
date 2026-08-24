<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\MysteryMerchantsPage;
use Modules\Community\Internal\Http\Livewire\SuggestMappingModal;
use Native\Desktop\Contracts\Shell as ShellContract;
use Native\Desktop\Fakes\ShellFake;

// /community/mystery-merchants heads its four tiles with "Your contributions"
// and rendered a literal 0 — the value was hard-coded, so it read 0 on a fresh
// install and 0 again straight after submitting a name. The tile beside it
// carries the rule this one broke: a percentage over nothing is null, "not 0
// and not 100", because it is a number the page would have invented.
//
// The mappings table has carried a user_id and a (user_id, pattern) unique
// index all along, and every read path filters whereNull('user_id') — so a
// row of the reader's own records the suggestion without joining the shared
// list or changing a single match.

beforeEach(function (): void {
    $this->user = makeCommunityTestUser('contributions-user');
    $this->actingAs($this->user);

    $this->shell = new ShellFake;
    $this->app->instance(ShellContract::class, $this->shell);
});

it('records the reader\'s own suggestion without joining the shared list', function (): void {
    Livewire::test(SuggestMappingModal::class)
        ->dispatch('suggest-mapping:open', rawDescription: 'SHELL*PIETER*')
        ->set('name', 'Shell Pieter')
        ->call('submit')
        ->assertDispatched('modal-close');

    $mine = DB::table('community_merchant_mappings')->where('user_id', $this->user->id)->get();

    expect($mine)->toHaveCount(1)
        ->and($mine[0]->pattern)->toBe('SHELL*PIETER*')
        ->and($mine[0]->name)->toBe('Shell Pieter');

    // The shared list is what every resolver reads, and it is not the reader's
    // to write: a suggestion is a pull request until somebody merges it.
    expect(DB::table('community_merchant_mappings')->whereNull('user_id')->where('pattern', 'SHELL*PIETER*')->count())
        ->toBe(0);
});

it('counts what the reader contributed rather than printing a fixed zero', function (): void {
    $page = Livewire::test(MysteryMerchantsPage::class);
    expect($page->viewData('stats')['contributorCount'])->toBe(0);

    Livewire::test(SuggestMappingModal::class)
        ->dispatch('suggest-mapping:open', rawDescription: 'SHELL*PIETER*')
        ->set('name', 'Shell Pieter')
        ->call('submit');

    Livewire::test(SuggestMappingModal::class)
        ->dispatch('suggest-mapping:open', rawDescription: 'ALBERT*HEIJN*')
        ->set('name', 'Albert Heijn')
        ->call('submit');

    expect(Livewire::test(MysteryMerchantsPage::class)->viewData('stats')['contributorCount'])->toBe(2);
});

it('does not count the same pattern twice when it is suggested again', function (): void {
    foreach (['Shell Pieter', 'Shell Pieter BV'] as $name) {
        Livewire::test(SuggestMappingModal::class)
            ->dispatch('suggest-mapping:open', rawDescription: 'SHELL*PIETER*')
            ->set('name', $name)
            ->call('submit');
    }

    expect(Livewire::test(MysteryMerchantsPage::class)->viewData('stats')['contributorCount'])->toBe(1)
        ->and(DB::table('community_merchant_mappings')->where('user_id', $this->user->id)->value('name'))
        ->toBe('Shell Pieter BV');
});

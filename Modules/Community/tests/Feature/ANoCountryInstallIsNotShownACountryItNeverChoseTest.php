<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\SuggestMappingModal;

// No country chosen is the default install, and the region this modal files a
// suggestion under is empty there. The option that means "no country" was
// written last in the list and marked nothing, so a browser drew whichever
// country sorted first, while the preview underneath — and the suggestion the
// reader actually filed — carried no region at all.

beforeEach(function (): void {
    $this->reader = makeCommunityTestUser('suggest-region-default');
    $this->actingAs($this->reader);
});

it('shows no country on an install that has chosen none', function (): void {
    $html = Livewire::test(SuggestMappingModal::class)
        ->assertSet('region', '')
        ->html();

    expect($html)->toContain('<option value="" selected>')
        ->and($html)->not->toContain('<option value="NL" selected>');
});

it('marks the chosen country once there is one', function (): void {
    DB::table('users')->where('id', $this->reader->id)->update(['country_code' => 'nl']);

    $html = Livewire::test(SuggestMappingModal::class)
        ->assertSet('region', 'NL')
        ->html();

    expect($html)->toContain('<option value="NL" selected>')
        ->and($html)->not->toContain('<option value="" selected>');
});

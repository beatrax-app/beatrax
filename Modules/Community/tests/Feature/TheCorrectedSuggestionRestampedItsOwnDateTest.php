<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\SuggestMappingModal;
use Modules\Community\Tests\Support\RecordingUrlOpener;
use Modules\Core\Public\Contracts\ExternalUrlOpener;

// Re-suggesting a name for a pattern is "one contribution corrected, not two
// made" — but created_at rode in the update half of the write, so the correction
// moved the original contribution's date to today. The seeder gets the same case
// right for the shared tier: created_at is written on the insert path only.

beforeEach(function (): void {
    $this->user = makeCommunityTestUser('contribution-date-user');
    $this->actingAs($this->user);
    $this->app->instance(ExternalUrlOpener::class, new RecordingUrlOpener);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function suggestMapping(string $pattern, string $name): void
{
    Livewire::test(SuggestMappingModal::class)
        ->dispatch('suggest-mapping:open', rawDescription: $pattern)
        ->set('name', $name)
        ->call('submit');
}

it('keeps the date a contribution was made when its name is corrected', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-04 09:15:00'));
    suggestMapping('SHELL*PIETER*', 'Shell Pieter');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-19 21:40:00'));
    suggestMapping('SHELL*PIETER*', 'Shell Pieter BV');

    $row = DB::table('community_merchant_mappings')->where('user_id', $this->user->id)->sole();

    expect($row->created_at)->toStartWith('2026-03-04 09:15:00')
        ->and($row->updated_at)->toStartWith('2026-08-19 21:40:00')
        ->and($row->name)->toBe('Shell Pieter BV');
});

it('stamps a first contribution with the moment it was made', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-04 09:15:00'));
    suggestMapping('ALBERT*HEIJN*', 'Albert Heijn');

    $row = DB::table('community_merchant_mappings')->where('user_id', $this->user->id)->sole();

    expect($row->created_at)->toStartWith('2026-03-04 09:15:00')
        ->and($row->updated_at)->toStartWith('2026-03-04 09:15:00');
});

it('leaves another pattern of the reader\'s own untouched', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-04 09:15:00'));
    suggestMapping('SHELL*PIETER*', 'Shell Pieter');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-19 21:40:00'));
    suggestMapping('ALBERT*HEIJN*', 'Albert Heijn');

    $rows = DB::table('community_merchant_mappings')
        ->where('user_id', $this->user->id)
        ->orderBy('pattern')
        ->pluck('created_at', 'pattern');

    expect($rows['SHELL*PIETER*'])->toStartWith('2026-03-04 09:15:00')
        ->and($rows['ALBERT*HEIJN*'])->toStartWith('2026-08-19 21:40:00');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Import\Models\MerchantAlias;

beforeEach(function (): void {
    $this->userA = User::create([
        'username' => 'alias-settings-a',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->userB = User::create([
        'username' => 'alias-settings-b',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    // 30 rows for user A so pagination caps the first page at 25.
    for ($i = 1; $i <= 30; $i++) {
        MerchantAlias::create([
            'user_id' => $this->userA->id,
            'pattern' => sprintf('USERA-RAW-%02d', $i),
            'generalized_pattern' => sprintf('usera %02d', $i),
            'friendly_name' => sprintf('User A #%02d', $i),
        ]);
    }
    // 5 rows for user B so the cross-user filter has data to exclude.
    for ($i = 1; $i <= 5; $i++) {
        MerchantAlias::create([
            'user_id' => $this->userB->id,
            'pattern' => sprintf('USERB-RAW-%02d', $i),
            'generalized_pattern' => sprintf('userb %02d', $i),
            'friendly_name' => sprintf('User B #%02d', $i),
        ]);
    }
});

it('renders /settings/aliases for an authenticated user with the first 25 rows', function (): void {
    $this->actingAs($this->userA)
        ->get('/settings/aliases')
        ->assertOk();
});

it('paginates user A at 25 rows and excludes user B rows entirely', function (): void {
    $component = Livewire::actingAs($this->userA)->test(AliasesSettingsPage::class);

    // Count distinct row indices: the pattern string appears twice per row
    // (read-only column + wire:confirm payload), so raw matches double-count.
    $html = (string) $component->html();
    $matchesA = PatternScan::all('/USERA-RAW-(\d{2})/', $html);
    $matchesB = PatternScan::all('/USERB-RAW-(\d{2})/', $html);

    $distinctA = array_unique($matchesA[1]);
    $distinctB = array_unique($matchesB[1]);

    expect(count($distinctA))->toBe(25);
    expect(count($distinctB))->toBe(0);
});

it('persists the generalized_pattern when the user saves an inline edit', function (): void {
    /** @var MerchantAlias $alias */
    $alias = MerchantAlias::query()
        ->where('user_id', $this->userA->id)
        ->where('pattern', 'USERA-RAW-01')
        ->first();

    Livewire::actingAs($this->userA)
        ->test(AliasesSettingsPage::class)
        ->call('startEdit', $alias->id)
        ->set('editingPattern', 'usera-edited-01')
        ->call('saveAlias', $alias->id)
        ->assertSet('flashMessage', 'Alias updated.');

    $updated = DB::table('merchant_aliases')->where('id', $alias->id)->first();
    expect($updated)->not->toBeNull();
    expect($updated->generalized_pattern)->toBe('usera-edited-01');
});

it('deletes the row and clears the selectedIds entry', function (): void {
    /** @var MerchantAlias $alias */
    $alias = MerchantAlias::query()
        ->where('user_id', $this->userA->id)
        ->where('pattern', 'USERA-RAW-02')
        ->first();

    Livewire::actingAs($this->userA)
        ->test(AliasesSettingsPage::class)
        ->set('selectedIds', [$alias->id])
        ->call('deleteAlias', $alias->id)
        ->assertSet('flashMessage', 'Alias deleted.');

    $stillThere = DB::table('merchant_aliases')->where('id', $alias->id)->exists();
    expect($stillThere)->toBeFalse();
});

it('refuses a cross-user delete with a calm flash and the foreign row stays in the DB (404-not-403 surface)', function (): void {
    /** @var MerchantAlias $userBAlias */
    $userBAlias = MerchantAlias::query()
        ->where('user_id', $this->userB->id)
        ->first();

    Livewire::actingAs($this->userA)
        ->test(AliasesSettingsPage::class)
        ->call('deleteAlias', $userBAlias->id)
        ->assertSet('flashMessage', 'Alias not found (it may have been deleted in another tab).');

    expect(DB::table('merchant_aliases')->where('id', $userBAlias->id)->exists())->toBeTrue();
});

it('refuses a cross-user save with a calm flash and the foreign row stays unchanged', function (): void {
    /** @var MerchantAlias $userBAlias */
    $userBAlias = MerchantAlias::query()
        ->where('user_id', $this->userB->id)
        ->first();

    $originalGeneralized = $userBAlias->generalized_pattern;

    Livewire::actingAs($this->userA)
        ->test(AliasesSettingsPage::class)
        ->set('editingId', $userBAlias->id)
        ->set('editingPattern', 'usera-tampered')
        ->call('saveAlias', $userBAlias->id)
        ->assertSet('flashMessage', 'Alias not found (it may have been deleted in another tab).');

    $reloaded = DB::table('merchant_aliases')->where('id', $userBAlias->id)->first();
    expect($reloaded)->not->toBeNull();
    expect($reloaded->generalized_pattern)->toBe($originalGeneralized);
});

it('redirects an unauthenticated visitor to login', function (): void {
    $this->get('/settings/aliases')->assertRedirect('/login');
});

it('streams aliases.yaml via Livewire assertFileDownloaded', function (): void {
    Livewire::actingAs($this->userA)
        ->test(AliasesSettingsPage::class)
        ->call('exportYaml')
        ->assertFileDownloaded('aliases.yaml');
});

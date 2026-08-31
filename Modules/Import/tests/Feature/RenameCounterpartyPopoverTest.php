<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Exceptions\MerchantAliasPatternTooShortException;
use Modules\Import\Internal\Http\Livewire\RenameCounterpartyPopover;
use Modules\Import\Public\Actions\CreateMerchantAlias;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\PreviewRowStatus;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'rename-popover',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('opens with the raw description prefilled and remember=true by default', function (): void {
    Livewire::test(RenameCounterpartyPopover::class)
        ->dispatch('rename-counterparty:open', raw: 'SHELL PIETER NIEUW', rowIndex: 0)
        ->assertSet('raw', 'SHELL PIETER NIEUW')
        ->assertSet('remember', true);
});

it('persists a merchant_aliases row and dispatches rename-counterparty:saved when save succeeds', function (): void {
    Livewire::test(RenameCounterpartyPopover::class)
        ->dispatch('rename-counterparty:open', raw: 'SHELL PIETER NIEUW', rowIndex: 7)
        ->set('friendly', 'Shell Pieter')
        ->call('save')
        ->assertDispatched('rename-counterparty:saved');

    $row = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->where('pattern', 'SHELL PIETER NIEUW')
        ->first();
    expect($row)->not->toBeNull();
    expect($row->friendly_name)->toBe('Shell Pieter');
});

it('rejects an empty friendly name and writes no merchant_aliases row', function (): void {
    Livewire::test(RenameCounterpartyPopover::class)
        ->dispatch('rename-counterparty:open', raw: 'EMPTY-FRIENDLY', rowIndex: 1)
        ->set('friendly', '')
        ->call('save')
        ->assertHasErrors('friendly');

    $exists = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->where('pattern', 'EMPTY-FRIENDLY')
        ->exists();
    expect($exists)->toBeFalse();
});

// A generalized pattern matches as a whole token against every description the
// reader owns, so "ah" renames every line with the word in it. The floor was on
// the settings page only, and the generalizer reaches sub-floor values by itself.
it('refuses a pattern too short to have been previewed, and writes nothing at all', function (): void {
    Livewire::test(RenameCounterpartyPopover::class)
        ->dispatch('rename-counterparty:open', raw: 'AH 1234', rowIndex: 3, currentCategoryId: 1)
        ->assertSet('generalized', 'ah')
        ->set('friendly', 'Albert Heijn')
        ->call('save')
        ->assertHasErrors('generalized')
        ->assertNotDispatched('rename-counterparty:saved');

    expect(DB::table('merchant_aliases')->where('user_id', $this->user->id)->count())->toBe(0);
    expect(DB::table('categorization_rules')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('refuses a short pattern typed straight into the generalized field', function (): void {
    Livewire::test(RenameCounterpartyPopover::class)
        ->dispatch('rename-counterparty:open', raw: 'BOL.COM ORDER 12345', rowIndex: 4)
        ->set('friendly', 'Bol')
        ->set('generalized', 'bo')
        ->call('save')
        ->assertHasErrors('generalized');

    expect(DB::table('merchant_aliases')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('refuses the same pattern at the action, so no other caller can reach the table with one', function (): void {
    expect(fn (): mixed => app(CreateMerchantAlias::class)($this->user, 'AH 1234', 'ah', 'Albert Heijn'))
        ->toThrow(MerchantAliasPatternTooShortException::class);

    expect(DB::table('merchant_aliases')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('renders the italic desc-fallback span when aliasFriendlyName is null and the plain name when it is set', function (): void {
    $rows = [
        new PreviewRowDto(
            rowIndex: 0,
            status: PreviewRowStatus::NewRow,
            accountId: 1,
            postedAt: '01-05-2026',
            counterpartyName: null,
            counterpartyIban: null,
            description: 'BCK*SHELL PIETER NIEUW *0123',
            amountMinor: -4250,
            currency: 'EUR',
            error: null,
            aliasFriendlyName: null,
        ),
        new PreviewRowDto(
            rowIndex: 1,
            status: PreviewRowStatus::NewRow,
            accountId: 1,
            postedAt: '02-05-2026',
            counterpartyName: null,
            counterpartyIban: null,
            description: 'BOL.COM ORDER 12345',
            amountMinor: -2500,
            currency: 'EUR',
            error: null,
            aliasFriendlyName: 'Bol.com',
        ),
    ];

    $html = view('import::livewire.preview-wizard-rows', ['rows' => $rows])->render();

    expect($html)->toContain('desc-fallback');
    expect($html)->toContain('BCK*SHELL PIETER NIEUW *0123');
    expect($html)->toContain('Bol.com');
    expect($html)->toContain('rename-counterparty:open');
});

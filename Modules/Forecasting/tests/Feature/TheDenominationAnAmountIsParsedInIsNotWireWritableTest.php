<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\AccountBufferEditor;
use Modules\Forecasting\Public\Http\Livewire\ModelWhatIfDropdown;
use Modules\Forecasting\Public\Http\Livewire\OpeningBalanceEditor;

uses(RefreshDatabase::class);

// Three editors read the figure the reader typed at a currency's own scale, and
// carried that currency on an unlocked public property that only the server ever
// wrote. Over real HTTP, replaying a page's own snapshot with `currency: JPY`
// made "150" on a EUR row persist as 150 minor instead of 15000 — a hundredth
// of the amount, answered 200, on every one of them.

function denomUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function denomAccount(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Main',
        'slug' => 'denom-'.$userId,
        'kind' => 'bank',
        'iban' => 'NL00DENOM'.$userId,
        'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function denomSeries(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Spotify',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -999,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -999,
        'variance_tolerance_percent' => 5,
        'next_expected_at' => '2026-05-25',
        'cluster_key' => 'cluster-denom-'.$userId,
        'cluster_counterparty_key' => 'Spotify',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

// The component's own rendered snapshot, so the round trip below needs no
// forgery: the checksum is the server's, and only `updates` is the client's.
function denomSnapshot(string $pageHtml, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $pageHtml, $matches);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"'.$component.'"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No wire:snapshot for [{$component}] on the rendered page.");
}

/**
 * @param  array<string, mixed>  $updates
 */
function denomTamper(string $pageHtml, string $component, array $updates, string $method): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => denomSnapshot($pageHtml, $component),
            'updates' => $updates,
            'calls' => [['path' => '', 'method' => $method, 'params' => []]],
        ]],
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('refuses a browser that renames the currency a modelled what-if is priced in', function (): void {
    $user = denomUser('denom-whatif');
    $this->actingAs($user);
    $seriesId = denomSeries($this->db, (int) $user->id);

    $page = $this->get('/recurring/series/'.$seriesId)->assertOk();

    denomTamper(
        $page->getContent(),
        'forecasting.model-what-if-dropdown',
        ['currency' => 'JPY', 'newAmountInput' => '150'],
        'saveAmountChange',
    )->assertForbidden();

    expect($this->db->connection()->table('forecast_scenario_mutations')->count())->toBe(0);
});

it('refuses a browser that renames the currency an opening balance is typed in', function (): void {
    $user = denomUser('denom-opening');
    $this->actingAs($user);
    $accountId = denomAccount($this->db, (int) $user->id);

    $page = $this->get('/settings')->assertOk();

    denomTamper(
        $page->getContent(),
        'forecasting.opening-balance-editor',
        ['currency' => 'JPY', 'openingInput' => '150', 'asOfInput' => '2026-05-01'],
        'save',
    )->assertForbidden();

    expect($this->db->connection()->table('accounts')->where('id', $accountId)->value('opening_balance_minor'))
        ->toBeNull();
});

it('refuses a browser that renames the currency a forecast buffer is typed in', function (): void {
    $user = denomUser('denom-buffer');
    $this->actingAs($user);
    $accountId = denomAccount($this->db, (int) $user->id);

    $page = $this->get('/forecast?account='.$accountId)->assertOk();

    denomTamper(
        $page->getContent(),
        'forecasting.account-buffer-editor',
        ['currency' => 'JPY', 'bufferInput' => '150'],
        'save',
    )->assertForbidden();

    expect($this->db->connection()->table('accounts')->where('id', $accountId)->value('forecast_min_buffer_minor'))
        ->toBeNull();
});

it('refuses a browser that moves the what-if onto another series', function (): void {
    $user = denomUser('denom-series-id');
    $this->actingAs($user);
    $seriesId = denomSeries($this->db, (int) $user->id);

    Livewire::test(ModelWhatIfDropdown::class, ['seriesId' => $seriesId])
        ->set('seriesId', $seriesId + 1);
})->throws(CannotUpdateLockedPropertyException::class);

it('refuses a browser that moves the opening balance onto another account', function (): void {
    $user = denomUser('denom-opening-id');
    $this->actingAs($user);
    $accountId = denomAccount($this->db, (int) $user->id);

    Livewire::test(OpeningBalanceEditor::class, [
        'accountId' => $accountId,
        'currentOpeningMinor' => null,
        'currentAsOfDate' => null,
        'currency' => 'EUR',
        'accountName' => 'Main',
        'accountKind' => 'bank',
    ])->set('accountId', $accountId + 1);
})->throws(CannotUpdateLockedPropertyException::class);

it('refuses a browser that moves the forecast buffer onto another account', function (): void {
    $user = denomUser('denom-buffer-id');
    $this->actingAs($user);
    $accountId = denomAccount($this->db, (int) $user->id);

    Livewire::test(AccountBufferEditor::class, [
        'accountId' => $accountId,
        'currentBufferMinor' => null,
        'currency' => 'EUR',
        'accountName' => 'Main',
    ])->set('accountId', $accountId + 1);
})->throws(CannotUpdateLockedPropertyException::class);

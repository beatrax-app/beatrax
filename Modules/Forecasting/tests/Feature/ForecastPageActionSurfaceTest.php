<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Public\Enums\ForecastHorizon;

uses(RefreshDatabase::class);

function fpaUser(string $username = 'fpa'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function fpaAccount(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Account '.$suffix,
        'slug' => 'fpa-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00FPA'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-05-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function fpaScenario(User $user, string $name): int
{
    /** @var ForecastScenario $scenario */
    $scenario = ForecastScenario::query()->create(['user_id' => $user->id, 'name' => $name]);

    return (int) $scenario->id;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-19 00:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = fpaUser();
    fpaAccount($this->db, $this->user->id);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// deleteScenario() takes its id from the browser. A foreign id and an id nobody
// holds have to come back the same way, or the id space is probeable. Defended
// twice -- the component's own pre-check and the action's 404 -- and measured:
// losing either one alone changes nothing observable here, losing both deletes.
it('answers a scenario id owned by somebody else exactly as it answers one owned by nobody', function (): void {
    $stranger = fpaUser('stranger');
    $theirs = fpaScenario($stranger, 'Not yours');
    $nobodys = $theirs + 10_000;

    $observed = [];
    foreach (['foreign' => $theirs, 'missing' => $nobodys] as $label => $id) {
        $component = Livewire::test(ForecastPage::class);
        $component->call('confirmDeleteScenario', $id);
        $component->call('deleteScenario', $id);

        $observed[$label] = [
            'confirming' => $component->get('confirmingDeleteForScenarioId'),
            'scenarioId' => $component->get('scenarioId'),
        ];
        $component->assertNotDispatched('toast');
        $component->assertNotDispatched('forecast-updated');
    }

    // Read off the table, never the model: ForecastScenario carries a UserScope,
    // so a row belonging to the stranger is invisible to a scoped query whether
    // or not it survived, and the assertion would have read as a deletion.
    $survived = $this->db->connection()->table('forecast_scenarios')->where('id', $theirs)->exists();

    expect($observed['foreign'])->toEqual($observed['missing'])
        ->and($survived)->toBeTrue();
});

it('deletes the reader own scenario and lets go of it where it was the selected one', function (): void {
    $mine = fpaScenario($this->user, 'Mine');

    $component = Livewire::test(ForecastPage::class);
    $component->call('setScenario', $mine);
    expect($component->get('scenarioId'))->toBe($mine);

    $component->call('deleteScenario', $mine);

    expect($component->get('scenarioId'))->toBeNull()
        ->and($component->get('confirmingDeleteForScenarioId'))->toBeNull()
        ->and(ForecastScenario::query()->whereKey($mine)->exists())->toBeFalse();
    $component->assertDispatched('toast');
});

it('keeps the selection where the deleted scenario was a different one', function (): void {
    $selected = fpaScenario($this->user, 'Selected');
    $other = fpaScenario($this->user, 'Other');

    $component = Livewire::test(ForecastPage::class);
    $component->call('setScenario', $selected);
    $component->call('deleteScenario', $other);

    expect($component->get('scenarioId'))->toBe($selected)
        ->and(ForecastScenario::query()->whereKey($selected)->exists())->toBeTrue()
        ->and(ForecastScenario::query()->whereKey($other)->exists())->toBeFalse();
});

// The rail offers a fixed set of horizons. An unlisted one leaves the reader on
// a projection no chip is lit for, so it is refused rather than stored.
it('refuses a horizon the rail does not offer and stays where it was', function (): void {
    $component = Livewire::test(ForecastPage::class);
    $before = $component->get('horizon');

    $component->call('setHorizon', 999);

    expect($component->get('horizon'))->toBe($before);
    $component->assertNotDispatched('forecast-updated');
});

it('takes every horizon the rail does offer', function (): void {
    $component = Livewire::test(ForecastPage::class);

    foreach (ForecastHorizon::days() as $days) {
        $component->call('setHorizon', $days);
        expect($component->get('horizon'))->toBe($days);
        $component->assertDispatched('forecast-updated');
    }
});

it('refuses an empty scenario name and creates nothing', function (): void {
    $component = Livewire::test(ForecastPage::class);
    $component->call('startCreateScenario');
    $component->set('newScenarioName', '   ');
    $component->call('saveNewScenario');

    expect($component->get('createScenarioError'))->not->toBeNull()
        ->and($component->get('creatingScenario'))->toBeTrue()
        ->and(ForecastScenario::query()->count())->toBe(0);
});

// Scenario names are unique per user, and CreateScenario raises rather than
// returning. The page has to show that message instead of letting it escape.
it('shows the refusal where the name is already taken and creates nothing', function (): void {
    fpaScenario($this->user, 'Taken');

    $component = Livewire::test(ForecastPage::class);
    $component->call('startCreateScenario');
    $component->set('newScenarioName', 'Taken');
    $component->call('saveNewScenario');

    expect($component->get('createScenarioError'))->toBeString()
        ->and(ForecastScenario::query()->count())->toBe(1);
});

it('creates a scenario under a free name and selects it', function (): void {
    $component = Livewire::test(ForecastPage::class);
    $component->call('startCreateScenario');
    $component->set('newScenarioName', 'Fresh');
    $component->call('saveNewScenario');

    $created = ForecastScenario::query()->where('name', 'Fresh')->firstOrFail();

    expect($component->get('createScenarioError'))->toBeNull()
        ->and($component->get('creatingScenario'))->toBeFalse()
        ->and($component->get('newScenarioName'))->toBe('')
        ->and($component->get('scenarioId'))->toBe((int) $created->id);
    $component->assertDispatched('forecast-updated');
});

it('puts the create and delete prompts back the way it found them', function (): void {
    $mine = fpaScenario($this->user, 'Mine');

    $component = Livewire::test(ForecastPage::class);
    $component->call('startCreateScenario');
    $component->set('newScenarioName', 'half typed');
    $component->call('cancelCreateScenario');

    expect($component->get('creatingScenario'))->toBeFalse()
        ->and($component->get('newScenarioName'))->toBe('')
        ->and($component->get('createScenarioError'))->toBeNull();

    $component->call('confirmDeleteScenario', $mine);
    expect($component->get('confirmingDeleteForScenarioId'))->toBe($mine);

    $component->call('cancelDeleteScenario');
    expect($component->get('confirmingDeleteForScenarioId'))->toBeNull()
        ->and(ForecastScenario::query()->whereKey($mine)->exists())->toBeTrue();
});

// The sidebar is a sibling component: its own re-render leaves this page's
// chips and chart payload untouched, so each of these has to announce.
it('redraws on every event a sibling component raises at it', function (): void {
    $mine = fpaScenario($this->user, 'Mine');

    foreach (['onBufferSaved', 'onScenarioMutated', 'refreshProjectionStatus'] as $handler) {
        $component = Livewire::test(ForecastPage::class);
        $component->call($handler);
        $component->assertDispatched('forecast-updated');
    }

    $component = Livewire::test(ForecastPage::class);
    $component->call('setScenario', $mine);
    $component->call('onScenarioDeleted');

    expect($component->get('scenarioId'))->toBeNull();
    $component->assertDispatched('forecast-updated');
});

// setAccount takes an account id straight from the browser. render() is what
// refuses it, and it refuses a foreign one and an absent one the same way.
it('falls back to the aggregate tab where the account named is not the reader own', function (): void {
    $stranger = fpaUser('stranger');
    $theirs = fpaAccount($this->db, $stranger->id);

    $component = Livewire::test(ForecastPage::class);
    $component->call('setAccount', (string) $theirs);

    expect($component->get('account'))->toBe(ForecastPage::ALL_ACCOUNTS)
        ->and($component->get('selectedAccountId') ?? null)->toBeNull();
});

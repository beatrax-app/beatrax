<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;

// Demo content is the first thing a fresh install shows, and it was English on
// a Dutch phone. Bank and person names are deliberately left alone: ASN Bank is
// called ASN Bank in Dutch too, and a demo that renamed a person would be lying
// about what the resolver produces.

beforeEach(function (): void {
    App::setLocale('nl');
    $this->artisan('demo:seed')->assertSuccessful();
    $this->demoUser = User::query()->where('username', 'demo-1')->firstOrFail();
});

it('names the demo goals in the interface language', function (): void {
    $names = DB::table('goals')->where('user_id', $this->demoUser->id)->pluck('name')->all();

    expect($names)->toContain('Noodfonds')
        ->and($names)->toContain('Winterbanden')
        ->and($names)->not->toContain('Emergency fund')
        ->and($names)->not->toContain('Winter tyres');
});

it('names the demo pots in the interface language, and still links them to their goal', function (): void {
    $pots = DB::table('pots')->where('user_id', $this->demoUser->id)->get(['name', 'goal_id']);
    $names = $pots->pluck('name')->all();

    expect($names)->toContain('Noodfonds')
        ->and($names)->toContain('Jaarlijkse verzekering')
        ->and($names)->not->toContain('New laptop');

    // The pot resolves its goal by name, so a half-translated pair would
    // silently drop the link rather than fail. Spelling out which pot reached
    // which goal, rather than counting them, also catches the other way that
    // resolution can go wrong: a name that matched the wrong goal.
    $goals = DB::table('goals')->where('user_id', $this->demoUser->id)->pluck('name', 'id')->all();
    $linked = $pots->filter(static fn (object $pot): bool => $pot->goal_id !== null)
        ->mapWithKeys(static fn (object $pot): array => [$pot->name => $goals[$pot->goal_id] ?? null])
        ->all();
    ksort($linked);

    expect($linked)->toBe([
        'Aanbetaling ryokan' => 'Verblijf in een ryokan',
        'Nieuwe laptop' => 'Laptop vervangen',
        'Noodfonds' => 'Noodfonds',
        'Reis naar Japan' => 'Reis naar Japan',
    ]);
});

it('names the demo saved reports in the interface language', function (): void {
    $names = DB::table('saved_reports')->where('user_id', $this->demoUser->id)->pluck('name')->all();

    expect($names)->toContain('Waar het geld heen ging')
        ->and($names)->not->toContain('Where the money went');
});

it('names the demo forecast scenarios, and describes them, in the interface language', function (): void {
    $rows = DB::table('forecast_scenarios')
        ->where('user_id', $this->demoUser->id)
        ->pluck('description', 'name')
        ->all();

    expect(array_keys($rows))->toContain('Basisscenario')
        ->and(array_keys($rows))->toContain('Wat-als: zomervakantie')
        ->and(array_keys($rows))->not->toContain('Base Case')
        ->and(array_keys($rows))->not->toContain('What-If: Summer holiday');

    // The description renders under the name in the scenario editor sidebar, so
    // a translated name over an English sentence is the half-fixed case.
    expect($rows['Basisscenario'] ?? '')->toStartWith('Basislijnprognose over 60 dagen')
        ->and($rows['Wat-als: zomervakantie'] ?? '')->toStartWith('Hypothetische prognose');
});

it('writes the demo what-if note in the interface language', function (): void {
    $payload = ForecastScenarioMutation::query()
        ->where('user_id', $this->demoUser->id)
        ->firstOrFail()
        ->payload;

    expect($payload)->toBeInstanceOf(AddOneOffPayload::class);

    /** @var AddOneOffPayload $payload */
    expect($payload->note)->toBe('Hypothetische afschrijving voor de zomervakantie');
});

it('does not seed a second set of forecast scenarios when the language changes', function (): void {
    App::setLocale('de');
    $this->artisan('demo:seed')->assertSuccessful();

    $names = DB::table('forecast_scenarios')
        ->where('user_id', $this->demoUser->id)
        ->pluck('name')
        ->all();

    expect($names)->toHaveCount(2)
        ->and($names)->toContain('Basisscenario')
        ->and($names)->not->toContain('Basisszenario');
});

it('translates the user own accounts but leaves the banks and people alone', function (): void {
    $rows = DB::table('counterparties')
        ->where('user_id', $this->demoUser->id)
        ->pluck('display_name', 'slug')
        ->all();

    expect($rows['self-asn-checking'] ?? null)->toBe('Mijn ASN-betaalrekening')
        ->and($rows['self-paypal-wallet'] ?? null)->toBe('Mijn PayPal-portemonnee')
        ->and($rows['asn-bank'] ?? null)->toBe('ASN Bank')
        ->and($rows['maria-van-buren'] ?? null)->toBe('Maria van Buren');
});

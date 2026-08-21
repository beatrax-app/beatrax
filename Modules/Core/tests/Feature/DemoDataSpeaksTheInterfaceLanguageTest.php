<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;

// Demo content is the first thing a fresh install shows, and it was English on
// a Dutch phone. Bank and person names are deliberately left alone: ASN Bank is
// called ASN Bank in Dutch too, and a demo that renamed a person would be lying
// about what the resolver produces.

beforeEach(function (): void {
    App::setLocale('nl');
    $this->artisan('demo:seed')->assertSuccessful();
    $this->demoUser = User::query()->where('username', 'demo-1@beatrax.local')->firstOrFail();
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
    // silently drop the link rather than fail.
    $linked = $pots->filter(static fn (object $pot): bool => $pot->goal_id !== null);
    expect($linked)->toHaveCount(3);
});

it('names the demo saved reports in the interface language', function (): void {
    $names = DB::table('saved_reports')->where('user_id', $this->demoUser->id)->pluck('name')->all();

    expect($names)->toContain('Waar het geld heen ging')
        ->and($names)->not->toContain('Where the money went');
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

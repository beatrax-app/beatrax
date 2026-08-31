<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// Every lookup and the headline count read the shared tier — user_id IS NULL —
// and the demo wrote its three patterns under the primary user only, on an
// install that never loaded the bundled corpus either.

it('resolves the demo patterns through the shared tier every lookup reads', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $corpus = app(CommunityCorpusQuery::class);

    expect($corpus->lookupExact('STG TUINBOUW NL', 'NL'))->toBe('Stichting Tuinbouw NL')
        ->and($corpus->lookupExact('PYPL *EZPORT BV', 'NL'))->toBe('EZ-Port BV (PayPal)')
        ->and($corpus->lookupExact('ICS PURCHASE 1234', 'NL'))->toBe('ICS Generic Purchase');
});

it('still counts three contributions of the readers own', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1')->firstOrFail();

    expect(app(CommunityCorpusQuery::class)->contributionsCount($user->id))->toBe(3);
});

// The demo command dispatches no UserInstalled, so the listener that loads the
// bundled corpus never fired and /community headlined a shared list of nought.
it('loads the bundled corpus a real install would have', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $corpus = app(CommunityCorpusQuery::class);

    expect($corpus->lookupExact('ACTION', 'NL'))->toBe('Action')
        ->and($corpus->mappingsCount())->toBeGreaterThan(100)
        ->and($corpus->contributorsCount())->toBeGreaterThanOrEqual(1);
});

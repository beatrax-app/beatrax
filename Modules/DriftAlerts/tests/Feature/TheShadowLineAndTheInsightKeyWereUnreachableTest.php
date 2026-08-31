<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DriftAlerts\Internal\Enums\SavingsInsightKind;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;
use Modules\DriftAlerts\Tests\Support\DriftAlertFixture;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// No production call site ever passed the equivalent, so the row's shadow line
// and its meta_eur_equiv key in 26 locales could not be reached at all.
it('gives a non-base-currency alert its reporting-currency shadow amount', function (): void {
    $user = DriftAlertFixture::user('tsli');
    DriftAlertFixture::alert($user, ['annualized_impact_minor' => -3600], Currency::Usd->value);

    $rows = app(DriftAlertQuery::class)->openForUser($user);

    // The rate itself belongs to FX and ships bundled, so what is pinned here
    // is that a converted figure arrives at all and carries the reader's code.
    expect($rows)->toHaveCount(1);
    expect($rows[0]->eurEquivalent?->currency())->toBe(Currency::Eur->value);
    expect($rows[0]->eurEquivalent?->toMinor())->not->toBe(-3600);
    expect($rows[0]->eurEquivalent?->toMinor())->toBeLessThan(0);
});

it('withholds the shadow amount when the alert is already in the reporting currency', function (): void {
    $user = DriftAlertFixture::user('tsli');
    DriftAlertFixture::alert($user, ['annualized_impact_minor' => -3600]);

    $rows = app(DriftAlertQuery::class)->openForUser($user);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->eurEquivalent)->toBeNull();
});

// insight_key is a string(64) column and the value arrives from the card, so a
// tampered payload persisted a 500-character key.
it('refuses to persist a dismissal key the module could not have produced', function (): void {
    $user = DriftAlertFixture::user('tsli');

    app(SavingsInsightsQuery::class)->dismiss($user, str_repeat('a', 500));
    app(SavingsInsightsQuery::class)->dismiss($user, 'cancel:not-a-number');
    app(SavingsInsightsQuery::class)->dismiss($user, 'invented-kind:7');

    expect($this->db->connection()->table('savings_insight_dismissals')->count())->toBe(0);
});

it('persists a dismissal key the insight builder produces', function (): void {
    $user = DriftAlertFixture::user('tsli');

    app(SavingsInsightsQuery::class)->dismiss($user, SavingsInsightKind::Cancel->keyFor(42));

    expect($this->db->connection()->table('savings_insight_dismissals')->count())->toBe(1);
});

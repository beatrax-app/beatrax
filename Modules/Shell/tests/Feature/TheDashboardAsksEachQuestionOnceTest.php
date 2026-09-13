<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Position\Public\Dto\PositionTilesDto;
use Modules\Position\Public\Services\PositionQuery;

uses(RefreshDatabase::class);

// The dashboard route composed the whole period summary to read `isFirstRun`
// off it, and the Dashboard component then composed it again. The summary is
// four queries and a rate lookup before the first tile is drawn.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 09:00:00'));
    DB::table('currencies')->updateOrInsert(['code' => 'EUR'], ['minor_unit' => 2]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function asksOnceReader(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'asks-once-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

function asksOnceRow(User $user): void
{
    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'asks-once-'.$user->id],
        ['name' => 'ASN', 'kind' => 'bank', 'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))), 'default_currency' => 'EUR'],
    );

    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/asks-once.xml',
        'sha256' => hash('sha256', 'asks-once-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-08-04',
        'booked_at' => '2026-08-04 12:00:00',
        'value_date' => '2026-08-04',
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('asks-once-'.bin2hex(random_bytes(8)), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

/** @return callable(): int the number of matching statements since this was called */
function asksOnceCounting(string $needle): callable
{
    $seen = 0;

    app(DatabaseManager::class)->connection()->listen(
        function (QueryExecuted $query) use (&$seen, $needle): void {
            if (str_contains($query->sql, $needle)) {
                $seen++;
            }
        },
    );

    // A closure, not an arrow function: `fn` captures by value, so the reader
    // would answer with the count as it stood the moment it was built.
    return static function () use (&$seen): int {
        return $seen;
    };
}

// `COALESCE(SUM(CASE WHEN` is ThisPeriodAtAGlanceQuery::SUM_HEAD, and
// bucketsByCurrency() is the only thing that emits it. In eur_only mode the
// composed summary runs it exactly once, so counting it counts the summary.
it('composes the period summary once for a dashboard request, not twice', function (): void {
    $user = asksOnceReader();
    asksOnceRow($user);
    test()->actingAs($user);

    $summaries = asksOnceCounting('COALESCE(SUM(CASE WHEN');

    test()->get('/')->assertOk();

    expect($summaries())->toBe(
        1,
        'The period summary was composed '.$summaries().' times for one dashboard request. The route asks '
        .'ThisPeriodAtAGlanceQuery::isFirstRun() for the redirect it needs and nothing else; only the '
        .'Dashboard component composes the summary.',
    );
});

// A fresh install has no transactions, so nothing else on the page ever runs.
it('decides the first-run redirect without composing anything', function (): void {
    $user = asksOnceReader();
    test()->actingAs($user);

    $summaries = asksOnceCounting('COALESCE(SUM(CASE WHEN');

    test()->get('/')->assertRedirect();

    expect($summaries())->toBe(0);
});

// The dashboard reads three of the position's seven members. The other four are
// each a child component's own question — a recurring window under its own
// `fp-filter`, an envelope fold, a forecast tile and a net-worth roll-up — and
// the child asks for it again, with client state the parent cannot carry.
it('builds none of the four members a dashboard render throws away', function (): void {
    $user = asksOnceReader();
    asksOnceRow($user);
    test()->actingAs($user);

    $recurringReads = asksOnceCounting('"recurring_series"');

    $tiles = app(PositionQuery::class)->tilesForUser($user, app(PeriodQuery::class)->current());

    expect($tiles)->toBeInstanceOf(PositionTilesDto::class)
        ->and($recurringReads())->toBe(
            0,
            'tilesForUser() read recurring_series '.$recurringReads().' times. Only the discarded `upcoming` '
            .'member reads it, and the fixed-payments card asks its own question of that table anyway.',
        );
});

// envelope_moves and envelope_settings are read by EnvelopeProgressQuery and by
// nothing else the page draws: the budgets glance card asks CarryoverQuery a
// different question. On a whole dashboard request they went 1 to 0, and the
// request went from 102 statements to 82.
it('runs none of the envelope-progress reads no tile on the page draws', function (): void {
    $user = asksOnceReader();
    asksOnceRow($user);
    test()->actingAs($user);

    $envelopeReads = asksOnceCounting('"envelope_moves"');

    test()->get('/')->assertOk();

    expect($envelopeReads())->toBe(
        0,
        'A dashboard request read envelope_moves '.$envelopeReads().' times. Only the position\'s `budgets` '
        .'member reads it, and the dashboard draws no figure from that member — the budgets glance card '
        .'below the tiles asks CarryoverQuery its own question.',
    );
});

it('still composes all seven members for the digest that reads them', function (): void {
    $user = asksOnceReader();
    asksOnceRow($user);
    test()->actingAs($user);

    $recurringReads = asksOnceCounting('"recurring_series"');

    $position = app(PositionQuery::class)->forUser($user, app(PeriodQuery::class)->current());

    expect($recurringReads())->toBeGreaterThan(0)
        // The two seams answer with the same tiles because one is composed from
        // the other, not written beside it.
        ->and($position->summary)->toEqual(
            app(PositionQuery::class)->tilesForUser($user, app(PeriodQuery::class)->current())->summary,
        );
});

it('reads whether this is a first run without composing the summary', function (): void {
    $user = asksOnceReader();
    test()->actingAs($user);

    expect(app(ThisPeriodAtAGlanceQuery::class)->isFirstRun($user))->toBeTrue();

    asksOnceRow($user);

    expect(app(ThisPeriodAtAGlanceQuery::class)->isFirstRun($user))->toBeFalse();
});

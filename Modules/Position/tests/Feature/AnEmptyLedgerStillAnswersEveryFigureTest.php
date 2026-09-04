<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Services\PositionQuery;

uses(RefreshDatabase::class);

// A brand-new install is the position most likely to be composed and least
// likely to be looked at while it is written. Zero is an answer the digest
// can say out loud; null is the absence of one, and it arrives at the reader
// as a blank tile or as a fatal in whatever tried to format it.

// The two the summary is allowed to answer with nothing, because a card with
// nothing to show draws nothing rather than an empty state.
const EMPTY_LEDGER_NOTHING_BY_DESIGN = [
    'tilesByCurrency' => 'the per-currency tiles, drawn only for a reader who chose the original-currency view',
    'emailScanHealth' => 'the mail-scan card, absent on an install that scans no mail',
];

function emptyLedgerUser(string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('answers a ledger with nothing in it with a zero of its own currency, never a blank', function (): void {
    $user = emptyLedgerUser('el-zeroes');
    $this->actingAs($user);

    $currency = app(BaseCurrency::class)->forUser($user);
    $position = app(PositionQuery::class)->forUser($user, app(PeriodQuery::class)->current());
    $summary = $position->summary;

    // The currency is asserted beside every figure: a zero denominated in
    // nothing is as unreadable as a null, and it formats without complaint.
    expect($summary->inflow->toMinor())->toBe(0);
    expect($summary->inflow->currency())->toBe($currency);
    expect($summary->outflow->toMinor())->toBe(0);
    expect($summary->outflow->currency())->toBe($currency);
    expect($summary->net->toMinor())->toBe(0);
    expect($summary->net->currency())->toBe($currency);

    expect($summary->uncategorizedCount)->toBe(0);
    expect($summary->recentTransactions)->toBe([]);
    expect($summary->topCategories->rows)->toBe([]);
    expect($summary->topCategories->refunded->toMinor())->toBe(0);
    expect($summary->topCategories->refundedCategoryCount)->toBe(0);
    expect($summary->unconvertedCurrencies)->toBe([]);

    expect($position->upcoming)->toBe([]);
    expect($position->budgets)->toBe([]);
    expect($position->shortfallAhead)->toBeFalse();
});

it('leaves nothing unanswered on an empty ledger beyond the cards that draw nothing', function (): void {
    $user = emptyLedgerUser('el-no-nulls');
    $this->actingAs($user);

    $position = app(PositionQuery::class)->forUser($user, app(PeriodQuery::class)->current());

    $fields = array_keys(get_object_vars($position));

    // Counted first: reflecting nothing would report every field answered.
    expect(count($fields))->toBeGreaterThanOrEqual(6);

    $unanswered = [];

    foreach ($fields as $field) {
        if (array_key_exists($field, EMPTY_LEDGER_NOTHING_BY_DESIGN)) {
            continue;
        }

        if ($position->{$field} === null) {
            $unanswered[] = $field;
        }
    }

    expect($unanswered)->toBe([], implode("\n", [
        'These answered a user with no data with nothing at all:',
        ...$unanswered,
        '',
        'A position with no data is still a position. Every figure it carries has',
        'a zero, an empty list or a false to answer with; the reader is told they',
        'have nothing, rather than shown a gap where the figure goes.',
    ]));
});

it('adds no field that can answer with nothing without saying why it may', function (): void {
    $constructor = (new ReflectionClass(PositionSummaryDto::class))->getConstructor();

    expect($constructor)->not->toBeNull();

    /** @var ReflectionMethod $constructor */
    $parameters = $constructor->getParameters();

    expect(count($parameters))->toBeGreaterThanOrEqual(6);

    $nullable = [];

    foreach ($parameters as $parameter) {
        $type = $parameter->getType();

        if ($type !== null && $type->allowsNull()) {
            $nullable[] = $parameter->getName();
        }
    }

    sort($nullable);

    $declared = array_keys(EMPTY_LEDGER_NOTHING_BY_DESIGN);
    sort($declared);

    expect($nullable)->toBe($declared, implode("\n", [
        'The summary fields that may answer with nothing are '.implode(', ', $nullable).',',
        'and the ones this test knows a reason for are '.implode(', ', $declared).'.',
        '',
        'A field added here that may be null is a figure the digest can be asked',
        'for and fail to produce. Give it a zero instead, or name it above with',
        'the reason a card drawing nothing is the right answer for it.',
    ]));
});

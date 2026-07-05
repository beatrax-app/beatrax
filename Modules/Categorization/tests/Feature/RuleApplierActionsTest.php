<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Categorization\Internal\Services\MatchedRule;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Models\RuleAction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Psr\Log\LoggerInterface;

/*
 * Plan 13.4-05 — RuleApplier dual-mode action executor (D-06, Req 2/3).
 *
 * Task 1: import-mode folding — category/counterparty/note withers,
 * tax_tag import-deferred (Pitfall 4), last-writer-wins across
 * same-field actions, zero side effects (no DB write, no event
 * dispatch).
 *
 * Task 2 (below): re-apply mode — provenance guard (manual-preserving),
 * write-only-on-change idempotency, TransactionMutated sync dispatch,
 * fail-open on a dangling payload id.
 */

function baseCanonicalForRuleApplier(): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: 1,
        accountId: 1,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-07-01'),
        bookedAt: CarbonImmutable::parse('2026-07-01 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-07-01'),
        amountMinor: -1000,
        currency: 'EUR',
        settledAmountMinor: -1000,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: 'Spotify AB',
        counterpartyIban: null,
        counterpartyNormalized: 'spotify ab',
        normalizationVersion: 1,
        description: 'Music subscription',
        categoryId: null,
        sourceFormat: 'camt053',
        importRunId: 1,
        sourceRowIndex: 1,
        sourceRef: null,
    );
}

/**
 * Builds an unpersisted `RuleAction` model — import-mode folding never
 * touches the DB, so a `MatchedRule`'s `actions` list can be built
 * directly against the fillable attributes without an `insert()`.
 *
 * @param  array<string, mixed>  $payload
 */
function ruleActionFor(string $type, array $payload, int $position = 0): RuleAction
{
    return new RuleAction([
        'position' => $position,
        'type' => $type,
        'payload' => $payload,
    ]);
}

function ruleApplierForTest(): RuleApplier
{
    return new RuleApplier(app(LoggerInterface::class));
}

// --- Task 1: import mode ---

it('folds category + counterparty + note onto the DTO at import; tax_tag is ignored', function (): void {
    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('category', ['category_id' => 42], 0),
            ruleActionFor('counterparty', ['counterparty_id' => 7], 1),
            ruleActionFor('note', ['text' => 'Rule note', 'mode' => 'set'], 2),
            ruleActionFor('tax_tag', ['deduction_category_id' => 5], 3),
        ]),
    ];

    $result = ruleApplierForTest()->applyAtImport($matched, baseCanonicalForRuleApplier());

    expect($result->categoryId)->toBe(42);
    expect($result->counterpartyId)->toBe(7);
    expect($result->note)->toBe('Rule note');
});

it('resolves last-writer-wins on the DTO when two matching rules both set category', function (): void {
    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('category', ['category_id' => 1], 0),
        ]),
        new MatchedRule(ruleId: 2, priority: 1, actions: [
            ruleActionFor('category', ['category_id' => 2], 0),
        ]),
    ];

    $result = ruleApplierForTest()->applyAtImport($matched, baseCanonicalForRuleApplier());

    expect($result->categoryId)->toBe(2);
});

it('skips a malformed category action (missing category_id) without throwing', function (): void {
    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('category', [], 0),
        ]),
    ];

    $result = ruleApplierForTest()->applyAtImport($matched, baseCanonicalForRuleApplier());

    expect($result->categoryId)->toBeNull();
});

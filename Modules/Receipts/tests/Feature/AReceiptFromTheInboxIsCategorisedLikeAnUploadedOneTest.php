<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Receipts\Internal\ReceiptLedgerBridge;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    $now = CarbonImmutable::now()->toDateTimeString();

    $this->groceriesId = (int) DB::table('categories')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Groceries',
        'slug' => 'groceries-inbox',
        'kind' => 'expense',
        'display_order' => 100,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $ruleId = (int) DB::table('categorization_rules')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'priority' => 0,
        'combinator' => 'all',
        'hits_count' => 0,
        'active' => true,
        'notes' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('rule_conditions')->insert([
        'rule_id' => $ruleId,
        'field' => 'merchant',
        'op' => 'equals',
        'value_type' => 'string',
        'value' => 'Albert Heijn',
        'value2' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('rule_actions')->insert([
        'rule_id' => $ruleId,
        'position' => 0,
        'type' => 'category',
        'payload' => json_encode(['category_id' => $this->groceriesId], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->receipt = new ParsedReceiptDto(
        merchantName: 'Albert Heijn',
        amountMinor: -2450,
        currency: 'EUR',
        settledAmountMinor: null,
        settledCurrency: null,
        referenceId: 'inbox-receipt-1',
        bookedAt: CarbonImmutable::parse('2026-05-15 10:00:00'),
        ownIban: 'PAYPAL',
        description: 'Albert Heijn receipt',
        rawPayload: [],
    );
});

it('categorises a bridged receipt the way the upload path would', function (): void {
    app(ReceiptLedgerBridge::class)->bridge($this->receipt, $this->fixtureUser, null, SourceFormat::Eml);

    $row = DB::table('transactions')->where('user_id', $this->fixtureUser->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->category_id)->toBe($this->groceriesId);
});

it('resolves a counterparty for a bridged receipt the way the upload path would', function (): void {
    app(ReceiptLedgerBridge::class)->bridge($this->receipt, $this->fixtureUser, null, SourceFormat::Eml);

    $row = DB::table('transactions')->where('user_id', $this->fixtureUser->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->counterparty_id)->not->toBeNull();
});

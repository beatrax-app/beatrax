<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

uses(RefreshDatabase::class);

// The two stages in the order ImportPipeline runs them: auto-category folds a
// firing rule's actions onto the DTO, and counterparty resolution follows.
function runTheTwoStagesOverruleFixture(CanonicalTransaction $tx, User $user): CanonicalTransaction
{
    $tx = app(AppliesAutoCategory::class)->apply($tx, $user)->canonical;

    return app(ResolvesCounterparties::class)->run($tx, $user);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'overrule-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'overrule-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $now = CarbonImmutable::now()->toDateTimeString();

    $this->flatmateId = (int) DB::table('counterparties')->insertGetId([
        'user_id' => $this->user->id,
        'type' => 'personal',
        'slug' => 'sanne-flatmate',
        'display_name' => 'Sanne (flatmate)',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $ruleId = (int) DB::table('categorization_rules')->insertGetId([
        'user_id' => $this->user->id,
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
        'field' => 'description',
        'op' => 'contains',
        'value_type' => 'string',
        'value' => 'TIKKIE',
        'value2' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('rule_actions')->insert([
        'rule_id' => $ruleId,
        'position' => 0,
        'type' => 'counterparty',
        'payload' => json_encode(['counterparty_id' => $this->flatmateId], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->row = new CanonicalTransaction(
        userId: $this->user->id,
        accountId: $this->account->id,
        type: 'income',
        postedAt: CarbonImmutable::parse('2026-05-15'),
        bookedAt: CarbonImmutable::parse('2026-05-15 00:00:00'),
        valueDate: CarbonImmutable::parse('2026-05-15'),
        amountMinor: 1250,
        currency: 'EUR',
        settledAmountMinor: 1250,
        settledCurrency: 'EUR',
        counterpartyName: 'ABN AMRO Tikkie',
        counterpartyIban: 'NL68BANK0000000001',
        counterpartyNormalized: 'abn amro tikkie',
        normalizationVersion: 4,
        description: 'TIKKIE BETAALVERZOEK huur',
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: 1,
        sourceRowIndex: 0,
        sourceRef: 'REF-1',
        rawPayload: null,
    );
});

it('keeps the party a rule named rather than the one the row text resolves to', function (): void {
    $result = runTheTwoStagesOverruleFixture($this->row, $this->user);

    expect($result->counterpartyId)->toBe($this->flatmateId);
});

it('still resolves a party for a row no rule named one on', function (): void {
    DB::table('categorization_rules')->where('user_id', $this->user->id)->update(['active' => false]);

    $result = runTheTwoStagesOverruleFixture($this->row, $this->user);

    expect($result->counterpartyId)->not->toBeNull()
        ->and($result->counterpartyId)->not->toBe($this->flatmateId);
});

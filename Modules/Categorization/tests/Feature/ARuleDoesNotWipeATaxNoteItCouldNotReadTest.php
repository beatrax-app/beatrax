<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Categorization\Internal\Services\MatchedRule;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Models\RuleAction;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// writeTaxTag() re-reads and decrypts the existing tax note before handing it
// back to TagTransaction, because updateExisting() rewrites note, category and
// year together and a literal null would wipe a note the reader wrote. The ''
// the codec answers for ciphertext no epoch here opened wipes it just as surely.

function rtnSession(): Session
{
    /** @var Session $session */
    $session = app(Session::class);

    return $session;
}

function rtnUser(): User
{
    $user = User::query()->create([
        'username' => 'rule-tax-note',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    AppLockTestHarness::unlock(rtnSession(), str_repeat("\x2a", 32));
    app(GdkKeyringService::class)->generateAndPersist((int) $user->id, rtnSession());

    return $user;
}

function rtnDeductionCategory(User $user, string $name): int
{
    return (int) DB::table('tax_deduction_categories')->insertGetId([
        'user_id' => $user->id,
        'name' => $name,
        'short_name' => substr($name, 0, 3),
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
}

function rtnTransaction(User $user): int
{
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $user->id, 'name' => 'ASN', 'slug' => 'rtn-asn', 'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $user->id, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/rtn.csv',
        'sha256' => hash('sha256', 'rtn'), 'uploaded_at' => '2026-09-01 00:00:00', 'status' => 'imported',
        'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
    ]);

    return (int) DB::table('transactions')->insertGetId([
        'user_id' => $user->id, 'account_id' => $accountId, 'type' => 'expense',
        'posted_at' => '2026-09-01', 'booked_at' => '2026-09-01 00:00:00', 'value_date' => '2026-09-01',
        'amount_minor' => -1299, 'currency' => 'EUR',
        'settled_amount_minor' => -1299, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'rtnfixture', 'normalization_version' => 1,
        'occurrence_ordinal' => 0, 'fingerprint' => 'rtn-fixture', 'fingerprint_version' => 1,
        'source_format' => 'asn_csv', 'import_run_id' => $runId, 'source_row_index' => 1,
        'status' => 'booked', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('opens a foreign-epoch tax note as nothing, which is the state this is about', function (): void {
    $user = rtnUser();
    $foreign = base64_encode(random_bytes(48));

    $opened = app(SensitiveColumnCodec::class)
        ->decryptValue('tax_transaction_tags', 'note', $foreign, (int) $user->id, rtnSession());

    expect($opened['decrypted'])->toBeFalse()
        ->and($opened['value'])->toBe('', 'the codec did not blank it, so the case below cannot arise');
});

it('leaves a tax note it could not read where it is', function (): void {
    $user = rtnUser();
    $transactionId = rtnTransaction($user);
    $foreign = base64_encode(random_bytes(48));

    DB::table('tax_transaction_tags')->insert([
        'user_id' => $user->id,
        'transaction_id' => $transactionId,
        'deduction_category_id' => rtnDeductionCategory($user, 'Subscriptions'),
        'note' => $foreign,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(RuleApplier::class)->applyAtReapply([
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            new RuleAction([
                'position' => 0,
                'type' => 'tax_tag',
                'payload' => ['deduction_category_id' => rtnDeductionCategory($user, 'Office costs')],
            ]),
        ]),
    ], $transactionId, (int) $user->id);

    expect(DB::table('tax_transaction_tags')->where('transaction_id', $transactionId)->value('note'))
        ->toBe($foreign, 'the rule rewrote the tag and took the unreadable note with it');
});

// The other half. A refusal that fired every time would satisfy the case above
// while stopping the tax_tag action from ever working.
it('still retags, and keeps the note, when it could read it', function (): void {
    $user = rtnUser();
    $transactionId = rtnTransaction($user);
    $codec = app(SensitiveColumnCodec::class);

    DB::table('tax_transaction_tags')->insert([
        'user_id' => $user->id,
        'transaction_id' => $transactionId,
        'deduction_category_id' => rtnDeductionCategory($user, 'Subscriptions'),
        'note' => $codec->encryptValue('tax_transaction_tags', 'note', 'half of this is private use', (int) $user->id, rtnSession()),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $office = rtnDeductionCategory($user, 'Office costs');

    app(RuleApplier::class)->applyAtReapply([
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            new RuleAction(['position' => 0, 'type' => 'tax_tag', 'payload' => ['deduction_category_id' => $office]]),
        ]),
    ], $transactionId, (int) $user->id);

    $row = DB::table('tax_transaction_tags')->where('transaction_id', $transactionId)->first();

    expect((int) $row->deduction_category_id)->toBe($office)
        ->and($codec->decryptValue('tax_transaction_tags', 'note', (string) $row->note, (int) $user->id, rtnSession())['value'])
        ->toBe('half of this is private use');
});

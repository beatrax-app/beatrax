<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;

// The registered PayPal aliases are a property of the reader, not of the row
// being resolved, and the ASN-direct arm asked for them once per unlinked
// PayPal payment on the way past.

beforeEach(function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();

    /** @var User $user */
    $user = User::query()->create([
        'username' => 'paypal-alias-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->user = $user;

    $this->paypalAccountId = (int) $this->conn->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'PayPal',
        'slug' => 'pas-paypal',
        'kind' => AccountKind::Paypal->value,
        'iban' => 'NL00PAYPALPAS0001',
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->bankAccountId = (int) $this->conn->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'pas-asn',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00ASNBPAS00001',
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->runId = (int) $this->conn->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pas.csv',
        'sha256' => hash('sha256', 'pas'),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->conn->table('known_counterparty_ibans')->insert([
        'user_id' => $user->id,
        'real_iban' => 'NL00ALIASPAS0001',
        'target_account_kind' => AccountKind::Paypal->value,
        'notes' => 'PayPal Europe',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->pasTransaction = function (int $accountId, string $type, int $minor, string $day, ?string $iban = null): int {
        return (int) $this->conn->table('transactions')->insertGetId([
            'user_id' => $this->user->id,
            'account_id' => $accountId,
            'import_run_id' => $this->runId,
            'fingerprint' => hash('sha256', 'pas-'.$accountId.'-'.$type.'-'.$minor.'-'.$day),
            'posted_at' => $day,
            'booked_at' => $day.' 00:00:00',
            'value_date' => $day,
            'amount_minor' => $minor,
            'currency' => 'EUR',
            'settled_amount_minor' => $minor,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => 'pas-merchant',
            'counterparty_name' => 'PAS Merchant',
            'counterparty_iban' => $iban,
            'normalization_version' => 1,
            'description' => 'pas row '.$minor,
            'type' => $type,
            'source_format' => 'asn-csv',
            'source_row_index' => 1,
            'fingerprint_version' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };
});

$pasAliasReads = static function (callable $work): int {
    $reads = 0;
    DB::listen(static function (QueryExecuted $query) use (&$reads): void {
        if (str_contains($query->sql, 'from "known_counterparty_ibans"')) {
            $reads++;
        }
    });

    $work();

    return $reads;
};

it('reads the registered aliases once for the run, not once per payment', function () use ($pasAliasReads): void {
    for ($i = 0; $i < 40; $i++) {
        ($this->pasTransaction)($this->paypalAccountId, 'expense', -100 - $i, '2026-03-01');
    }

    $resolver = app(PaypalFundingResolver::class);
    $reads = $pasAliasReads(function () use ($resolver): void {
        $resolver->resolveForUser($this->user);
    });

    expect($reads)->toBe(1);
});

it('still links a payment to the bank leg its alias iban identifies', function (): void {
    $paypalTx = ($this->pasTransaction)($this->paypalAccountId, 'expense', -2500, '2026-03-04');
    $bankTx = ($this->pasTransaction)($this->bankAccountId, 'transfer_out', -2500, '2026-03-03', 'NL00ALIASPAS0001');

    app(PaypalFundingResolver::class)->resolveForUser($this->user);

    $link = $this->conn->table('chain_links')
        ->where('user_id', $this->user->id)
        ->where('from_transaction_id', $paypalTx)
        ->first();

    expect($link)->not->toBeNull()
        ->and((int) $link->to_transaction_id)->toBe($bankTx)
        ->and($link->kind)->toBe(ChainLinkKind::PaypalFunding->value)
        ->and($link->state)->toBe(ChainLinkState::Confirmed->value);
});

it('links nothing when the reader has registered no alias at all', function (): void {
    $this->conn->table('known_counterparty_ibans')->where('user_id', $this->user->id)->delete();
    $paypalTx = ($this->pasTransaction)($this->paypalAccountId, 'expense', -2500, '2026-03-04');
    ($this->pasTransaction)($this->bankAccountId, 'transfer_out', -2500, '2026-03-03', 'NL00ALIASPAS0001');

    app(PaypalFundingResolver::class)->resolveForUser($this->user);

    expect($this->conn->table('chain_links')->where('from_transaction_id', $paypalTx)->count())->toBe(0);
});

it('marks a payment ambiguous when two alias legs sit in its window', function (): void {
    $paypalTx = ($this->pasTransaction)($this->paypalAccountId, 'expense', -2500, '2026-03-04');
    ($this->pasTransaction)($this->bankAccountId, 'transfer_out', -2500, '2026-03-03', 'NL00ALIASPAS0001');
    ($this->pasTransaction)($this->bankAccountId, 'transfer_out', -2500, '2026-03-05', 'NL00ALIASPAS0001');

    app(PaypalFundingResolver::class)->resolveForUser($this->user);

    $link = $this->conn->table('chain_links')
        ->where('user_id', $this->user->id)
        ->where('from_transaction_id', $paypalTx)
        ->first();

    expect($link)->not->toBeNull()
        ->and($link->state)->toBe(ChainLinkState::Candidate->value);
});

it('leaves a leg outside the date window alone', function (): void {
    $paypalTx = ($this->pasTransaction)($this->paypalAccountId, 'expense', -2500, '2026-03-04');
    ($this->pasTransaction)($this->bankAccountId, 'transfer_out', -2500, '2026-02-01', 'NL00ALIASPAS0001');

    app(PaypalFundingResolver::class)->resolveForUser($this->user);

    expect($this->conn->table('chain_links')->where('from_transaction_id', $paypalTx)->count())->toBe(0);
});

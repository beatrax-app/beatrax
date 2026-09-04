<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// field_provenance is what stops a rule from undoing the reader's own work:
// RuleApplier skips a field the map calls 'manual'. An unreadable map answers
// [], which says no field is protected, so an unattended re-apply overwrites
// the category, counterparty and note the reader set by hand. Degrading that
// way beats taking the whole batch down over one row — but degrading in
// silence leaves nothing anywhere to connect the two.
function unreadableProvenanceLogger(): object
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        /**
         * @param  mixed  $level
         * @param  Stringable|string  $message
         * @param  array<mixed>  $context
         */
        public function log($level, $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }

        public function said(string $needle): bool
        {
            foreach ($this->messages as $message) {
                if (str_contains($message, $needle)) {
                    return true;
                }
            }

            return false;
        }
    };
}

/**
 * @return array{0: int, 1: int}
 */
function transactionHoldingProvenance(string $stored): array
{
    $user = User::query()->create([
        'username' => 'provenance-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id, 'name' => 'ASN', 'slug' => 'prov-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL57ASNB'.random_int(1000000000, 9999999999), 'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/prov.csv',
        'sha256' => hash('sha256', 'prov-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(), 'status' => 'previewed',
    ]);

    $transaction = Transaction::query()->create([
        'user_id' => $user->id, 'account_id' => $account->id, 'import_run_id' => $run->id,
        'type' => 'expense', 'posted_at' => '2026-07-05', 'booked_at' => '2026-07-05 12:00:00',
        'value_date' => '2026-07-05', 'amount_minor' => -1299, 'currency' => 'EUR',
        'settled_amount_minor' => -1299, 'settled_currency' => 'EUR',
        'counterparty_name' => 'Test Vendor', 'counterparty_normalized' => 'test vendor',
        'normalization_version' => 1, 'source_format' => 'asn-csv', 'source_row_index' => 0,
        'fingerprint' => hash('sha256', 'prov-tx-'.bin2hex(random_bytes(8))), 'fingerprint_version' => 1,
    ]);

    DB::table('transactions')->where('id', $transaction->id)->update(['field_provenance' => $stored]);

    return [(int) $user->id, (int) $transaction->id];
}

it('says a provenance map did not read rather than answering that nothing is protected', function (): void {
    [$userId, $transactionId] = transactionHoldingProvenance('{"category":"manual её');

    $logger = unreadableProvenanceLogger();
    $this->app->instance(LoggerInterface::class, $logger);

    /** @var FieldProvenanceWriter $writer */
    $writer = $this->app->make(FieldProvenanceWriter::class);

    expect($writer->provenanceFor($userId, $transactionId))->toBe([])
        ->and($logger->said('field_provenance did not read'))->toBeTrue();
});

// The other half: a map that reads must stay quiet, or the line means nothing.
it('says nothing about a provenance map that reads', function (): void {
    [$userId, $transactionId] = transactionHoldingProvenance('{"category":"manual"}');

    $logger = unreadableProvenanceLogger();
    $this->app->instance(LoggerInterface::class, $logger);

    /** @var FieldProvenanceWriter $writer */
    $writer = $this->app->make(FieldProvenanceWriter::class);

    expect($writer->provenanceFor($userId, $transactionId))->toBe(['category' => 'manual'])
        ->and($logger->said('field_provenance did not read'))->toBeFalse();
});

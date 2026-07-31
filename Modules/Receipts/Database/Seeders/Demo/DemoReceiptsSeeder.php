<?php

declare(strict_types=1);

namespace Modules\Receipts\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// Materialises 2 file_imports rows (a PayPal + an ICS mock drop) and 1
// pending_enrichment_conflicts row so both the receipts surface and the
// conflict-resolution toast render with data on a fresh demo install.
// Idempotent via the same UNIQUE keys the production writers use.
final class DemoReceiptsSeeder
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary === null) {
            return 0;
        }

        $importRun = ImportRun::query()
            ->where('user_id', $primary->id)
            ->where('source_format', 'demo')
            ->orderBy('id')
            ->first();

        if ($importRun === null) {
            return 0;
        }

        $this->upsertFileImport($primary, new DemoFileImportSpec(
            providerMessageId: 'demo-paypal-receipt-001',
            sourceFilename: 'demo-paypal-receipt.eml',
            senderEmail: 'service@paypal.com',
            senderName: 'PayPal',
            subject: 'Receipt for your payment',
            matcherKey: 'paypal-receipt',
            ageHours: 48,
        ));
        $this->upsertFileImport($primary, new DemoFileImportSpec(
            providerMessageId: 'demo-ics-statement-001',
            sourceFilename: 'demo-ics-statement.eml',
            senderEmail: 'noreply@ics.nl',
            senderName: 'ICS Cards',
            subject: 'Uw maandafschrift is beschikbaar',
            matcherKey: 'ics-receipt',
            ageHours: 72,
        ));

        $bolPaypalTransaction = Transaction::query()
            ->where('user_id', $primary->id)
            ->where('source_format', 'demo')
            ->where('description', 'Bol.com via PayPal')
            ->orderBy('posted_at', 'desc')
            ->first();

        if ($bolPaypalTransaction !== null) {
            $this->upsertPendingConflict(
                $primary,
                $bolPaypalTransaction,
                $importRun,
                fieldName: 'description',
                storedValue: 'Bol.com via PayPal',
                incomingValue: 'Bol.com - Order #DEMO-1234 (PayPal)',
            );
        }

        return (int) $this->db->connection()
            ->table('file_imports')
            ->where('user_id', $primary->id)
            ->count();
    }

    private function upsertFileImport(User $user, DemoFileImportSpec $spec): void
    {
        $connection = $this->db->connection();

        $existing = $connection->table('file_imports')
            ->where('user_id', $user->id)
            ->where('provider_message_id', $spec->providerMessageId)
            ->exists();

        if ($existing) {
            return;
        }

        $now = CarbonImmutable::now();
        $internalDate = $now->subHours($spec->ageHours);

        $connection->table('file_imports')->insert([
            'user_id' => $user->id,
            'source_kind' => $spec->sourceKind(),
            'source_filename' => $spec->sourceFilename,
            'provider_message_id' => $spec->providerMessageId,
            'internal_date' => $internalDate,
            'sender_email' => $spec->senderEmail,
            'sender_name' => $spec->senderName,
            'subject' => $spec->subject,
            'eml_path' => 'demo://receipts/'.$spec->providerMessageId.'.eml',
            'status' => 'parsed',
            'matcher_key' => $spec->matcherKey,
            'fetched_at' => $internalDate->addMinutes(5),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertPendingConflict(
        User $user,
        Transaction $tx,
        ImportRun $importRun,
        string $fieldName,
        string $storedValue,
        string $incomingValue,
    ): void {
        $connection = $this->db->connection();

        $existing = $connection->table('pending_enrichment_conflicts')
            ->where('user_id', $user->id)
            ->where('transaction_id', $tx->id)
            ->where('field_name', $fieldName)
            ->exists();

        if ($existing) {
            return;
        }

        $now = CarbonImmutable::now();

        $connection->table('pending_enrichment_conflicts')->insert([
            'user_id' => $user->id,
            'transaction_id' => $tx->id,
            'field_name' => $fieldName,
            // Matches the production producer (ApplyEnrichments): both
            // columns hold a JSON-encoded scalar, decoded by the
            // resolution action.
            'stored_value' => json_encode($storedValue, JSON_THROW_ON_ERROR),
            'incoming_value' => json_encode($incomingValue, JSON_THROW_ON_ERROR),
            'incoming_source_format' => 'eml-receipt',
            'import_run_id' => $importRun->id,
            'created_at' => $now->subHours(6),
            'updated_at' => $now->subHours(6),
        ]);
    }
}

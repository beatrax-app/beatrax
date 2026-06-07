<?php

declare(strict_types=1);

namespace Modules\Receipts\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

/**
 * Materialises a representative receipts demo dataset for the
 * primary demo user so both the receipts surface and the
 * conflict-resolution path render with data on a fresh demo install:
 *
 *   - 2 `file_imports` rows modelling dropped .eml receipt files (one
 *     PayPal receipt, one ICS card statement) with mock matcher_key
 *     attribution and `status = 'parsed'` lifecycle stamps
 *   - 1 `pending_enrichment_conflicts` row attached to an existing
 *     demo transaction so the conflict-resolution toast renders
 *
 * Receipts module has no Eloquent Models/ surface (the table is
 * accessed via Public/Services/FileImportQuery and FileImportDto), so
 * the seeder writes via the query builder through the injected
 * `DatabaseManager`. The pending_enrichment_conflicts table is owned
 * by the Categorization module; same pattern applies.
 *
 * Idempotency: file_imports uses `(user_id, provider_message_id)`
 * UNIQUE for keying; pending_enrichment_conflicts uses
 * `(user_id, transaction_id, field_name)` UNIQUE. Both upserts skip
 * existing rows via a SELECT-then-INSERT shape because neither table
 * has a Laravel-side `updateOrInsert` invariant the seeder needs to
 * preserve, and the demo set never mutates an existing row's payload.
 */
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

        $this->upsertFileImport(
            $primary,
            providerMessageId: 'demo-paypal-receipt-001',
            sourceFilename: 'demo-paypal-receipt.eml',
            sourceKind: 'eml',
            senderEmail: 'service@paypal.com',
            senderName: 'PayPal',
            subject: 'Receipt for your payment',
            matcherKey: 'paypal-receipt',
            ageHours: 48,
        );
        $this->upsertFileImport(
            $primary,
            providerMessageId: 'demo-ics-statement-001',
            sourceFilename: 'demo-ics-statement.eml',
            sourceKind: 'eml',
            senderEmail: 'noreply@ics.nl',
            senderName: 'ICS Cards',
            subject: 'Uw maandafschrift is beschikbaar',
            matcherKey: 'ics-receipt',
            ageHours: 72,
        );

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

    private function upsertFileImport(
        User $user,
        string $providerMessageId,
        string $sourceFilename,
        string $sourceKind,
        string $senderEmail,
        string $senderName,
        string $subject,
        string $matcherKey,
        int $ageHours,
    ): void {
        $connection = $this->db->connection();

        $existing = $connection->table('file_imports')
            ->where('user_id', $user->id)
            ->where('provider_message_id', $providerMessageId)
            ->exists();

        if ($existing) {
            return;
        }

        $now = CarbonImmutable::now();
        $internalDate = $now->subHours($ageHours);

        $connection->table('file_imports')->insert([
            'user_id' => $user->id,
            'source_kind' => $sourceKind,
            'source_filename' => $sourceFilename,
            'provider_message_id' => $providerMessageId,
            'internal_date' => $internalDate,
            'sender_email' => $senderEmail,
            'sender_name' => $senderName,
            'subject' => $subject,
            'eml_path' => 'demo://receipts/'.$providerMessageId.'.eml',
            'status' => 'parsed',
            'matcher_key' => $matcherKey,
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
            // Match the production producer (ApplyEnrichments): both columns
            // hold a JSON-encoded scalar, which the resolution action decodes.
            'stored_value' => json_encode($storedValue, JSON_THROW_ON_ERROR),
            'incoming_value' => json_encode($incomingValue, JSON_THROW_ON_ERROR),
            'incoming_source_format' => 'eml-receipt',
            'import_run_id' => $importRun->id,
            'created_at' => $now->subHours(6),
            'updated_at' => $now->subHours(6),
        ]);
    }
}

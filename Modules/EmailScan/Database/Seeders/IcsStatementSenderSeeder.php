<?php

declare(strict_types=1);

namespace Modules\EmailScan\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Seeder;

/**
 * Seeds the system `known_senders` row(s) that let `IncrementalScanJob`
 * actually FETCH the ICS "statement ready" email in the first place (Req
 * 14, D-14/D-15).
 *
 * `known_senders` already carries a system row for `@ics.nl` — seeded by
 * that table's own creation migration for the ICS *receipts* sender — but
 * NOT for `icscards.nl`, the second domain
 * `Modules\Receipts\Internal\Matchers\IcsReceiptMatcher::ICS_DOMAINS`
 * already claims for receipt parsing. Without a `known_senders` row for
 * whichever domain the statement-ready email actually arrives from, the
 * message never lands in `inbox_messages` at all and
 * `DetectIcsStatementReadyJob` has nothing to detect.
 *
 * Reads the sender allow-list from the SAME tunable config block the
 * detector job reads (`email-scan.ics_statement_ready.sender_domains`) so
 * a config-only correction (Open Question 1) also updates which sender
 * the primary fetch filter watches for — one source of truth, not two
 * that can drift.
 *
 * Idempotent via an existence check (not an `upsert()`): `known_senders`'s
 * `UNIQUE(user_id, email_pattern)` index does not fire for NULL `user_id`
 * on SQLite (NULL != NULL in unique-index matching), so a naive upsert on
 * that index would insert a duplicate system row on every re-run instead
 * of updating the existing one.
 */
final class IcsStatementSenderSeeder extends Seeder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ConfigRepository $config,
    ) {}

    public function run(): void
    {
        $domainsRaw = $this->config->get('email-scan.ics_statement_ready.sender_domains', []);
        if (! is_array($domainsRaw)) {
            return;
        }

        $connection = $this->db->connection();
        $now = CarbonImmutable::now()->toDateTimeString();

        foreach ($domainsRaw as $domain) {
            if (! is_string($domain) || $domain === '') {
                continue;
            }

            $pattern = '@'.$domain;

            $exists = $connection->table('known_senders')
                ->whereNull('user_id')
                ->where('email_pattern', $pattern)
                ->exists();

            if ($exists) {
                continue;
            }

            $connection->table('known_senders')->insert([
                'user_id' => null,
                'email_pattern' => $pattern,
                'label' => 'ICS Cards (statements)',
                'source' => 'system',
                'added_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\EmailScan\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Seeder;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
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

            // Existence check, not upsert(): known_senders'
            // UNIQUE(user_id, email_pattern) index does not fire for a
            // NULL user_id on SQLite, so a naive upsert on that index
            // would insert a duplicate system row on every re-run.
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

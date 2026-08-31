<?php

declare(strict_types=1);

namespace Modules\EmailScan\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Seeder;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;

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

            // SQLite's UNIQUE(user_id, email_pattern) does not fire for a
            // NULL user_id, so upsert() would insert a duplicate system row
            // on every re-run.
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
                // The issuer's own name is the same in every language, so the
                // whole label is one line rather than a brand glued to a
                // translated fragment; KnownSenderQuery resolves it per reader.
                'label' => StoredCopy::of(CopyLine::of('email-scan::inboxes.known_sender.ics_statements')),
                'source' => 'system',
                'added_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

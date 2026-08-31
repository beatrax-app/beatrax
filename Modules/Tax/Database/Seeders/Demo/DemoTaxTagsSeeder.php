<?php

declare(strict_types=1);

namespace Modules\Tax\Database\Seeders\Demo;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Tax\Internal\Actions\TaxCategoryStore;
use Modules\Tax\Public\Actions\TagTransaction;

// Tags go through the public action rather than raw inserts, so provenance and
// the search index stay correct.
final class DemoTaxTagsSeeder
{
    private const COUNTRY = 'nl';

    // Matched against transactions.description with a LIKE, so one entry tags
    // every occurrence of a recurring charge. yearOffset is added to the
    // current calendar year and written as tax_year_override.
    /** @var list<array{match: string, corpusKey: string, note: ?string, yearOffset: ?int}> */
    private const TAGS = [
        [
            'match' => 'Zilveren Kruis%',
            'corpusKey' => 'nl_zorgkosten',
            'note' => 'Zorgverzekering premie',
            'yearOffset' => null,
        ],
        [
            'match' => 'MEDIAMARKT%',
            'corpusKey' => 'nl_ondernemerskosten',
            'note' => 'Monitor voor de werkplek',
            'yearOffset' => null,
        ],
        [
            'match' => 'KPN Mobile%',
            'corpusKey' => 'nl_ondernemerskosten',
            'note' => 'Internet, zakelijk deel',
            'yearOffset' => null,
        ],
        [
            'match' => 'BOL.COM%',
            'corpusKey' => 'nl_ondernemerskosten',
            'note' => 'Vakliteratuur',
            'yearOffset' => null,
        ],
        // The whole 90-day window sits inside one calendar year, and January
        // through April /tax opens on the year before it. Without a row filed
        // back a year, four months of the year land on an empty page.
        [
            'match' => 'COOLBLUE ROTTERDAM',
            'corpusKey' => 'nl_ondernemerskosten',
            'note' => 'Laptop, geboekt op het vorige belastingjaar',
            'yearOffset' => -1,
        ],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TaxCategoryStore $categories,
        private readonly TagTransaction $tagTransaction,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1'] ?? null;
        if ($primary !== null) {
            $this->categories->seedFromCorpus($primary, self::COUNTRY);
            $currentYear = $this->clock->now()->year;

            foreach (self::TAGS as $row) {
                $this->tagMatching($primary, $row, $currentYear);
            }
        }

        return $this->db->connection()
            ->table('tax_transaction_tags')
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{match: string, corpusKey: string, note: ?string, yearOffset: ?int}  $row
     */
    private function tagMatching(User $user, array $row, int $currentYear): void
    {
        $categoryId = $this->db->connection()
            ->table('tax_deduction_categories')
            ->where('user_id', $user->id)
            ->where('corpus_key', $row['corpusKey'])
            ->value('id');

        if (! is_numeric($categoryId)) {
            return;
        }

        $transactionIds = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('description', 'like', $row['match'])
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->all();

        foreach ($transactionIds as $transactionId) {
            $id = is_numeric($transactionId) ? (int) $transactionId : 0;
            if ($id === 0 || $this->alreadyTagged($user, $id)) {
                continue;
            }

            $this->tagTransaction->execute(
                $user->id,
                $id,
                (int) $categoryId,
                $row['note'],
                $row['yearOffset'] === null
                    ? null
                    : $currentYear + $row['yearOffset'],
            );
        }
    }

    private function alreadyTagged(User $user, int $transactionId): bool
    {
        return $this->db->connection()
            ->table('tax_transaction_tags')
            ->where('user_id', $user->id)
            ->where('transaction_id', $transactionId)
            ->whereNull('transaction_split_id')
            ->exists();
    }
}

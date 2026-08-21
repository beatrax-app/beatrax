<?php

declare(strict_types=1);

namespace Modules\Tax\Database\Seeders\Demo;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;
use Modules\Tax\Public\Actions\TagTransaction;

// Tags go through the public action rather than raw inserts, so provenance and
// the search index stay correct.
final class DemoTaxTagsSeeder
{
    private const COUNTRY = 'nl';

    // Matched against transactions.description with a LIKE, so one entry tags
    // every occurrence of a recurring charge.
    /** @var list<array{match: string, corpusKey: string, note: ?string}> */
    private const TAGS = [
        [
            'match' => 'Zilveren Kruis%',
            'corpusKey' => 'nl_zorgkosten',
            'note' => 'Zorgverzekering premie',
        ],
        [
            'match' => 'MEDIAMARKT%',
            'corpusKey' => 'nl_ondernemerskosten',
            'note' => 'Monitor voor de werkplek',
        ],
        [
            'match' => 'KPN Mobile%',
            'corpusKey' => 'nl_ondernemerskosten',
            'note' => 'Internet, zakelijk deel',
        ],
        [
            'match' => 'BOL.COM%',
            'corpusKey' => 'nl_ondernemerskosten',
            'note' => 'Vakliteratuur',
        ],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TaxCategoryWriter $categories,
        private readonly TagTransaction $tagTransaction,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            $this->categories->seedFromCorpus($primary, self::COUNTRY);

            foreach (self::TAGS as $row) {
                $this->tagMatching($primary, $row);
            }
        }

        return $this->db->connection()
            ->table('tax_transaction_tags')
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{match: string, corpusKey: string, note: ?string}  $row
     */
    private function tagMatching(User $user, array $row): void
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
            ->orderByDesc('booked_at')
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
                null,
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

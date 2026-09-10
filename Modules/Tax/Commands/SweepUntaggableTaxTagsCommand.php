<?php

declare(strict_types=1);

namespace Modules\Tax\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Tax\Internal\Support\TaxableMovement;
use Modules\Tax\Public\Actions\UntagTransaction;
use stdClass;

/**
 * @link ../../../.docs/features/tax/tag-write-contract.md#which-rows-may-carry-a-tag
 */
final class SweepUntaggableTaxTagsCommand extends Command
{
    use CoercesScalars;

    protected $signature = 'tax:sweep-untaggable
        {--user= : Restrict the sweep to one account; defaults to every account}
        {--apply : Remove the tags. Without it the command only reports them.}';

    protected $description = 'Report, and optionally remove, tax tags on rows whose type can carry no deduction.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly UntagTransaction $untag,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = $this->untaggable();

        if ($rows === []) {
            $this->info('No tax tag sits on a row that cannot carry one.');

            return self::SUCCESS;
        }

        $this->table(
            ['user', 'transaction', 'leg', 'type', 'payment_type', 'settled_minor'],
            array_map(static fn (array $row): array => array_values($row), $rows),
        );

        if (! $this->option('apply')) {
            $this->warn(count($rows).' tag(s) would be removed. Re-run with --apply.');

            return self::SUCCESS;
        }

        // Through the sanctioned writer, never a bulk DELETE: the removal has
        // to reach the op log or a paired device replays the tag back.
        foreach ($rows as $row) {
            $this->untag->execute($row['user_id'], $row['transaction_id'], $row['transaction_split_id']);
        }

        $this->info('Removed '.count($rows).' tag(s).');

        return self::SUCCESS;
    }

    /**
     * @return list<array{user_id: int, transaction_id: int, transaction_split_id: ?int, type: string, payment_type: string, settled_amount_minor: int}>
     */
    private function untaggable(): array
    {
        $user = $this->option('user');

        $rows = $this->db->connection()
            ->table('tax_transaction_tags as tag')
            ->join('transactions as t', 't.id', '=', 'tag.transaction_id')
            ->when(is_string($user) && $user !== '', static fn (Builder $q): Builder => $q->where('tag.user_id', (int) $user))
            ->orderBy('tag.user_id')
            ->orderBy('tag.transaction_id')
            ->orderBy('tag.id')
            ->get(['tag.user_id', 'tag.transaction_id', 'tag.transaction_split_id', 't.type', 't.payment_type', 't.settled_amount_minor']);

        $untaggable = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            if (TaxableMovement::canCarryATag($row->type ?? null, $row->payment_type ?? null)) {
                continue;
            }

            $untaggable[] = [
                'user_id' => self::toInt($row->user_id),
                'transaction_id' => self::toInt($row->transaction_id),
                'transaction_split_id' => $row->transaction_split_id === null ? null : self::toInt($row->transaction_split_id),
                'type' => self::toString($row->type),
                'payment_type' => self::toString($row->payment_type),
                'settled_amount_minor' => self::toInt($row->settled_amount_minor),
            ];
        }

        return $untaggable;
    }
}

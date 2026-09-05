<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use stdClass;

// The three lookups every promoted row resolves an id through. They are built
// once per run and then travel together through eight signatures, which is what
// made those signatures long enough to hide a parameter.
final readonly class PromotionMaps
{
    use CoercesScalars;

    /**
     * @param  array<string, int>  $categoryIds
     * @param  array<string, int>  $accountIds
     * @param  array<string, string>  $payeeNames
     */
    public function __construct(
        public array $categoryIds,
        public array $accountIds,
        public array $payeeNames,
    ) {}

    // The payee half is the only one read from the staging table rather than
    // handed in: categories and accounts are promoted first, and this is the
    // run's own record of what its payees are called.
    /**
     * @param  array<string, int>  $categoryIds
     * @param  array<string, int>  $accountIds
     */
    public static function forRun(DatabaseManager $db, int $runId, User $user, array $categoryIds, array $accountIds): self
    {
        $rows = $db->connection()->table('migration_staging_payees')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->get(['source_external_id', 'normalized_name']);

        /** @var array<string, string> $payeeNames */
        $payeeNames = [];

        /** @var stdClass $row */
        foreach ($rows as $row) {
            if (is_string($row->source_external_id) && $row->source_external_id !== '') {
                $payeeNames[$row->source_external_id] = self::toString($row->normalized_name);
            }
        }

        return new self($categoryIds, $accountIds, $payeeNames);
    }

    public function payeeName(mixed $externalId): ?string
    {
        return is_string($externalId) ? ($this->payeeNames[$externalId] ?? null) : null;
    }
}

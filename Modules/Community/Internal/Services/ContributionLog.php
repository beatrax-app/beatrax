<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Dto\SuggestMappingDto;
use Modules\Core\Public\Contracts\Clock;

// A suggestion leaves as a pull request, so the shared list cannot have it
// until somebody merges it. The reader's own row is what remembers they made
// it — and it is inert everywhere else, because every corpus read path filters
// on `user_id is null`.
final readonly class ContributionLog
{
    private const string TABLE = 'community_merchant_mappings';

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    // Upsert on the (user_id, pattern) unique index the table already carries:
    // re-suggesting a name is one contribution corrected, not two made. The
    // correction must not restamp when it was made, so `created_at` stays out of
    // the update list, as SeedCommunityCorpus keeps it out of the global tier's.
    public function record(int $userId, string $contributor, SuggestMappingDto $dto): void
    {
        $now = $this->clock->now()->toDateTimeString();

        $this->db->connection()->table(self::TABLE)->upsert(
            [[
                'user_id' => $userId,
                'pattern' => $dto->pattern,
                'name' => $dto->name,
                'category' => $dto->category,
                'region' => $dto->region,
                'contributor' => $contributor,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            uniqueBy: ['user_id', 'pattern'],
            update: [
                'name',
                'category',
                'region',
                'contributor',
                'updated_at',
            ],
        );
    }
}

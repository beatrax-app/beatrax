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
final class ContributionLog
{
    private const TABLE = 'community_merchant_mappings';

    public function __construct(private readonly DatabaseManager $db, private readonly Clock $clock) {}

    // Upsert on the (user_id, pattern) unique index the table already carries:
    // suggesting a better name for a description already suggested is one
    // contribution corrected, not two made.
    public function record(int $userId, string $contributor, SuggestMappingDto $dto): void
    {
        $now = $this->clock->now()->toDateTimeString();

        $this->db->connection()->table(self::TABLE)->updateOrInsert(
            ['user_id' => $userId, 'pattern' => $dto->pattern],
            [
                'name' => $dto->name,
                'category' => $dto->category,
                'region' => $dto->region,
                'contributor' => $contributor,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }
}

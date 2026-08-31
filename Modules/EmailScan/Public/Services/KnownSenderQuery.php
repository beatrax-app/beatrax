<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\StoredCopy;
use Modules\EmailScan\Public\Dto\KnownSenderDto;
use stdClass;

final readonly class KnownSenderQuery
{
    use CoercesScalars;

    public function __construct(private DatabaseManager $db) {}

    /**
     * @return list<KnownSenderDto>
     */
    public function all(User $user): array
    {
        $rows = $this->db->connection()
            ->table('known_senders')
            ->where(function ($q) use ($user): void {
                /** @var Builder $q */
                $q->where('user_id', $user->id)
                    ->orWhereNull('user_id');
            })
            ->orderBy('source', 'desc')
            ->select(['id', 'user_id', 'email_pattern', 'label', 'source'])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $userId = $row->user_id;
            $out[] = new KnownSenderDto(
                id: self::toInt($row->id),
                userId: is_numeric($userId) ? (int) $userId : null,
                emailPattern: self::toString($row->email_pattern),
                // A label the reader promoted is their own words and comes back
                // as it was typed; a seeded one is a stored line and comes back
                // in the reader's language. The source-then-name order moves out
                // of SQL with it: a stored line sorts by its envelope there.
                label: StoredCopy::read(self::toString($row->label)),
                source: self::toString($row->source),
            );
        }

        usort($out, static fn (KnownSenderDto $a, KnownSenderDto $b): int => [$b->source, $a->label] <=> [$a->source, $b->label]);

        return $out;
    }
}

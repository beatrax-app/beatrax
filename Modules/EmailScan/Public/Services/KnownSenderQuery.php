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

    // The column that says who wrote the label, and the only thing that may
    // decide whether it is read as a copy spec. The value cannot decide: the
    // envelope is unsigned, public, and a From display name can carry it.
    private const string APP_AUTHORED = 'system';

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
            $source = self::toString($row->source);
            $label = self::toString($row->label);
            $out[] = new KnownSenderDto(
                id: self::toInt($row->id),
                userId: is_numeric($userId) ? (int) $userId : null,
                emailPattern: self::toString($row->email_pattern),
                // A seeded label is a stored line and comes back in the
                // reader's language; a promoted one is the From display name
                // and comes back as it was sent. The source-then-name order
                // moves out of SQL with it: a spec sorts by its envelope there.
                label: $source === self::APP_AUTHORED ? StoredCopy::read($label) : $label,
                source: $source,
            );
        }

        usort($out, static fn (KnownSenderDto $a, KnownSenderDto $b): int => [$b->source, $a->label] <=> [$a->source, $b->label]);

        return $out;
    }
}

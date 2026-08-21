<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\EmailScan\Public\Dto\KnownSenderDto;
use stdClass;

final class KnownSenderQuery
{
    use CoercesScalars;

    public function __construct(private readonly DatabaseManager $db) {}

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
            ->orderBy('label', 'asc')
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
                label: self::toString($row->label),
                source: self::toString($row->source),
            );
        }

        return $out;
    }
}

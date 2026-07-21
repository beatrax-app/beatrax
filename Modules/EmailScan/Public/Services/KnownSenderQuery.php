<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\EmailScan\Public\Dto\KnownSenderDto;
use stdClass;

// Public read API over known_senders: returns the user's curated
// sender list, both system-shipped seeds (user_id IS NULL) and
// per-user additions, with system rows surfaced first so the calling
// matcher registry sees seeded patterns before any user overrides.
final class KnownSenderQuery
{
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
            // System rows first ('system' < 'user' lexicographically;
            // sort DESC to put 'user' last and 'system' first).
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}

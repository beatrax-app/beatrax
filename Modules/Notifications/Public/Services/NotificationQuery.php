<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use JsonException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Notifications\Public\Dto\NotificationDto;
use Modules\Notifications\Public\NotificationCopy;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final readonly class NotificationQuery
{
    use CoercesScalars;

    // 25 + 1 lookahead, matching the DriftAlertQuery
    // precedent.
    private const PAGE_LIMIT = 26;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    /**
     * @return list<NotificationDto>
     */
    public function unreadForUser(User $user, ?string $cursor = null): array
    {
        $query = $this->db->connection()->table('notifications')
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('dismissed_at');

        return $this->paginate($query, $user, $cursor);
    }

    /**
     * @return list<NotificationDto>
     */
    public function allForUser(User $user, ?string $cursor = null): array
    {
        $query = $this->db->connection()->table('notifications')
            ->where('user_id', $user->id)
            ->whereNull('dismissed_at');

        return $this->paginate($query, $user, $cursor);
    }

    /**
     * @return list<NotificationDto>
     */
    public function dismissedForUser(User $user, ?string $cursor = null): array
    {
        $query = $this->db->connection()->table('notifications')
            ->where('user_id', $user->id)
            ->whereNotNull('dismissed_at');

        return $this->paginate($query, $user, $cursor);
    }

    // The nav-badge unread count. Deliberately runs with no encryption
    // key: it counts rows on the plaintext read_at/dismissed_at columns
    // and never touches title/body/params/trigger_type.
    public function unreadCountForUser(User $user): int
    {
        return $this->db->connection()->table('notifications')
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->count();
    }

    // Opaque cursor encoding the (created_at, id) pair of the last row
    // on the current page. The page renderer passes this back verbatim
    // as the next page's $cursor argument.
    public static function encodeCursor(string $createdAt, string $id): string
    {
        return base64_encode(json_encode(['created_at' => $createdAt, 'id' => $id], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<NotificationDto>
     */
    private function paginate(Builder $query, User $user, ?string $cursor): array
    {
        $decoded = self::decodeCursor($cursor);

        if ($decoded !== null) {
            $query->where(function (Builder $q) use ($decoded): void {
                $q->where('created_at', '<', $decoded['createdAt'])
                    ->orWhere(function (Builder $q2) use ($decoded): void {
                        $q2->where('created_at', '=', $decoded['createdAt'])
                            ->where('id', '<', $decoded['id']);
                    });
            });
        }

        $rows = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::PAGE_LIMIT)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = $this->hydrate($row, $user);
        }

        return $result;
    }

    // A missing, malformed, or non-conforming cursor is treated as null
    // (first page) rather than thrown - a tampered #[Url] param must not
    // 500 the inbox.
    /**
     * @return array{createdAt: string, id: string}|null
     */
    private static function decodeCursor(?string $cursor): ?array
    {
        $decoded = ($cursor === null || $cursor === '') ? false : base64_decode($cursor, true);
        if ($decoded === false) {
            return null;
        }

        try {
            $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }

        if (
            ! is_array($data)
            || ! isset($data['created_at'], $data['id'])
            || ! is_string($data['created_at'])
            || ! is_string($data['id'])
        ) {
            return null;
        }

        return ['createdAt' => $data['created_at'], 'id' => $data['id']];
    }

    private function hydrate(stdClass $row, User $user): NotificationDto
    {
        $decrypted = $this->codec->decryptRow('notifications', [
            'title' => self::toString($row->title ?? null),
            'body' => self::toString($row->body ?? null),
            'trigger_type' => self::toString($row->trigger_type ?? null),
        ], $user->id, $this->session);

        $title = self::toString($decrypted['title'] ?? null);
        $body = self::toString($decrypted['body'] ?? null);
        $triggerType = self::toString($decrypted['trigger_type'] ?? null);

        $chip = NotificationCopy::typeChip($triggerType);

        return new NotificationDto(
            id: self::toString($row->id ?? null),
            triggerType: $triggerType,
            title: $title,
            body: $body,
            readAt: self::toCarbonOrNull($row->read_at ?? null),
            dismissedAt: self::toCarbonOrNull($row->dismissed_at ?? null),
            state: self::toString($row->state ?? null),
            createdAt: self::toCarbon($row->created_at ?? null),
            deepLinkUrl: null,
            deepLinkDisabled: false,
            targetKind: null,
            glyph: $chip['glyph'],
            typeWord: $chip['word'],
        );
    }

    private static function toCarbon(mixed $value): CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value) : CarbonImmutable::now();
    }

    private static function toCarbonOrNull(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}

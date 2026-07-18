<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use JsonException;
use Modules\Core\Models\User;
use Modules\Notifications\Public\Dto\NotificationDto;
use Modules\Notifications\Public\NotificationCopy;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * Public read API over `notifications` — the inbox read model (18-12) and
 * the nav-badge unread count (D-03). Every method carries an explicit
 * `->where('user_id', $user->id)` because `BelongsToUser`'s `UserScope`
 * global scope does not fire in queue/console context, and the sha256 PK is
 * NOT an authorization boundary (T-18-16).
 *
 * Clones `DriftAlertQuery`'s shape (limit 26 = 25 + 1 lookahead) with ONE
 * mandatory deviation: `drift_alerts.id` is an autoincrement surrogate, so
 * `ORDER BY id DESC` + `WHERE id < cursor` stays monotone with insertion
 * order. `notifications.id` is a sha256 hex digest and is NOT
 * insertion-ordered, so this query sorts on `created_at DESC, id DESC` and
 * pages on a COMPOUND cursor — `(created_at < ?) OR (created_at = ? AND
 * id < ?)` — backed by the `(user_id, created_at, id)` index from 18-01.
 * A malformed cursor is treated as null (first page) rather than thrown.
 *
 * `unreadCountForUser` is the ONE method in this class that never touches
 * `SensitiveColumnCodec`/`Session` — it counts on the plaintext
 * `read_at`/`dismissed_at` columns only, so the nav badge works on a locked,
 * KEK-less device. Every other method decrypts `title`/`body`/`trigger_type`
 * via the codec (a pass-through no-op when encryption is not enabled).
 */
final readonly class NotificationQuery
{
    /** 25 + 1 lookahead, matching the DriftAlertQuery / 18-UI-SPEC.md precedent. */
    private const PAGE_LIMIT = 26;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    /**
     * `read_at IS NULL AND dismissed_at IS NULL`.
     *
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
     * `dismissed_at IS NULL` (read or unread, just not dismissed).
     *
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
     * `dismissed_at IS NOT NULL`.
     *
     * @return list<NotificationDto>
     */
    public function dismissedForUser(User $user, ?string $cursor = null): array
    {
        $query = $this->db->connection()->table('notifications')
            ->where('user_id', $user->id)
            ->whereNotNull('dismissed_at');

        return $this->paginate($query, $user, $cursor);
    }

    /**
     * The D-03 nav-badge unread count. Deliberately runs with NO KEK: it
     * counts rows on the plaintext `read_at`/`dismissed_at` columns and never
     * touches `title`/`body`/`params`/`trigger_type`.
     */
    public function unreadCountForUser(User $user): int
    {
        return $this->db->connection()->table('notifications')
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->count();
    }

    /**
     * Opaque cursor encoding the `(created_at, id)` pair of the last row on
     * the current page. The page renderer passes this back verbatim as the
     * next page's `$cursor` argument.
     */
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

    /**
     * Decodes an opaque cursor into its `(created_at, id)` pair. A missing,
     * malformed, or non-conforming cursor is treated as null (first page)
     * rather than thrown — a tampered `#[Url]` param must not 500 the inbox.
     *
     * @return array{createdAt: string, id: string}|null
     */
    private static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            return null;
        }

        try {
            $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
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

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
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

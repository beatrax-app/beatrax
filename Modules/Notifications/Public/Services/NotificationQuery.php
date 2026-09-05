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
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Public\Dto\NotificationDto;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\NotificationCopy;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;
use stdClass;

// A page carries the cursor that follows it, because whether there IS another
// page is the query's answer and not one the caller can derive from the rows.
// Deriving it is what put the page size in the Blade as a literal, where it
// drifted from the query's own.
/**
 * @phpstan-type NotificationPage array{rows: list<NotificationDto>, nextCursor: ?string}
 */
final readonly class NotificationQuery
{
    use CoercesScalars;

    // The page as the reader sees it. One row past this is read and then
    // withheld — that lookahead is what tells the page there is another,
    // without paying for a second COUNT.
    public const int PAGE_SIZE = 25;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private Session $session,
        private LoggerInterface $log,
    ) {}

    /**
     * @return NotificationPage
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
     * @return NotificationPage
     */
    public function allForUser(User $user, ?string $cursor = null): array
    {
        $query = $this->db->connection()->table('notifications')
            ->where('user_id', $user->id)
            ->whereNull('dismissed_at');

        return $this->paginate($query, $user, $cursor);
    }

    /**
     * @return NotificationPage
     */
    public function dismissedForUser(User $user, ?string $cursor = null): array
    {
        $query = $this->db->connection()->table('notifications')
            ->where('user_id', $user->id)
            ->whereNotNull('dismissed_at');

        return $this->paginate($query, $user, $cursor);
    }

    // Deliberately key-less: counts on the plaintext read_at/dismissed_at
    // columns and never touches title/body/params/trigger_type.
    public function unreadCountForUser(User $user): int
    {
        return $this->db->connection()->table('notifications')
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->count();
    }

    public static function encodeCursor(string $createdAt, string $id): string
    {
        return base64_encode(json_encode(['created_at' => $createdAt, 'id' => $id], JSON_THROW_ON_ERROR));
    }

    /**
     * @return NotificationPage
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
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = $this->hydrate($row, $user);
        }

        // The lookahead row is read and dropped. Rendering it put PAGE_SIZE + 1
        // rows on the page and then took the cursor from the last of them, so
        // an inbox holding an exact multiple of that many paged straight into
        // the empty state with no way back.
        $hasMore = count($result) > self::PAGE_SIZE;
        $page = $hasMore ? array_slice($result, 0, self::PAGE_SIZE) : $result;

        $this->reportUnnamed($page);

        $last = $page === [] ? null : $page[count($page) - 1];

        return [
            'rows' => $page,
            'nextCursor' => $hasMore && $last !== null
                ? self::encodeCursor($last->createdAt->toDateTimeString(), $last->id)
                : null,
        ];
    }

    // The reader gets a neutral chip and a sentence; nothing about that reaches
    // an operator, and a page quietly rendering thirteen unreadable rows is the
    // shape this defect had. One line per page, ids only — the values are
    // exactly the ones that must not be logged.
    /**
     * @param  list<NotificationDto>  $rows
     */
    private function reportUnnamed(array $rows): void
    {
        $unreadable = [];
        $unknown = [];

        foreach ($rows as $row) {
            if ($row->unreadable) {
                $unreadable[] = $row->id;
            } elseif (NotificationTrigger::tryFrom($row->triggerType) === null) {
                $unknown[$row->id] = $row->triggerType;
            }
        }

        if ($unreadable !== []) {
            $this->log->warning(
                'NotificationQuery: notification content did not decrypt; rendering the unreadable row.',
                ['notification_ids' => $unreadable],
            );
        }

        if ($unknown !== []) {
            $this->log->warning(
                'NotificationQuery: trigger type this build does not name; rendering the neutral chip.',
                ['trigger_types' => $unknown],
            );
        }
    }

    // A tampered #[Url] param must not 500 the inbox, so a malformed cursor
    // reads as the first page rather than throwing.
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
            'params' => self::toString($row->params ?? null),
            'trigger_type' => self::toString($row->trigger_type ?? null),
        ], $user->id, $this->session);

        $triggerType = self::toString($decrypted['trigger_type']);
        $unreadable = $decrypted->hasUnreadable();
        $copy = self::copySpec($decrypted['params']);

        // The stored sentence catches two rows: one written before the copy
        // spec existed, and one whose key a later release removed. Both are
        // stuck in the language they were written in, which still reads as a
        // sentence where a raw translation key does not.
        $title = $copy?->title() ?? self::toString($decrypted['title']);
        $body = $copy?->body() ?? self::toString($decrypted['body']);

        $chip = NotificationCopy::typeChip(NotificationTrigger::tryFrom($triggerType));

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
            unreadable: $unreadable,
        );
    }

    private static function copySpec(mixed $paramsJson): ?NotificationCopySpec
    {
        if (! is_string($paramsJson) || $paramsJson === '') {
            return null;
        }

        try {
            $params = json_decode($paramsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($params)
            ? NotificationCopySpec::fromArray($params[NotificationCopySpec::PARAMS_KEY] ?? null)
            : null;
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

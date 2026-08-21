<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use DateTimeImmutable;
use Generator;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Enums\InboxMessageStatus;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use stdClass;

// The status is validated rather than passed through, so a typo at a call site
// fails loud instead of silently yielding zero rows.
readonly class InboxMessageQuery
{
    use CoercesScalars;

    public function __construct(private DatabaseManager $db) {}

    /**
     * @return Generator<int, InboxMessageDto>
     */
    public function forStatus(string $status): Generator
    {
        if (InboxMessageStatus::tryFrom($status) === null) {
            throw new InvalidArgumentException(
                'InboxMessageQuery::forStatus expected one of: '.implode(', ', array_column(InboxMessageStatus::cases(), 'value')).", got '{$status}'."
            );
        }

        $rows = $this->db->connection()
            ->table('inbox_messages')
            ->where('status', $status)
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            /** @var stdClass $row */
            yield new InboxMessageDto(
                id: self::toInt($row->id),
                userId: self::toInt($row->user_id),
                inboxId: self::toInt($row->inbox_id),
                providerMessageId: self::toString($row->provider_message_id),
                internalDate: self::toDateTime($row->internal_date),
                senderEmail: self::toString($row->sender_email),
                senderName: self::toStringOrNull($row->sender_name),
                subject: self::toStringOrNull($row->subject),
                status: self::toString($row->status),
                fetchedAt: self::toDateTime($row->fetched_at),
            );
        }
    }

    private static function toDateTime(mixed $value): DateTimeImmutable
    {
        $raw = self::toString($value);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        if ($dt === false) {
            // SQLite stores 'Y-m-d H:i:s', but fixtures seed ISO 8601 and
            // tz-bearing strings that createFromFormat rejects.
            return new DateTimeImmutable($raw);
        }

        return $dt;
    }
}

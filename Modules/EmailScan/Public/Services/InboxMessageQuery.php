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

// Public read-side query over inbox_messages. Streams rows in id
// order via cursor() so downstream parsers iterate large fetch
// backlogs without materialising the full set; the status value is
// validated so a typo call site fails loud, not silently zero rows.
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
                senderName: self::toNullableString($row->sender_name),
                subject: self::toNullableString($row->subject),
                status: self::toString($row->status),
                fetchedAt: self::toDateTime($row->fetched_at),
            );
        }
    }

    private static function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::toString($value);
    }

    private static function toDateTime(mixed $value): DateTimeImmutable
    {
        $raw = self::toString($value);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        if ($dt === false) {
            // Fall back to the constructor so ISO 8601 + tz-bearing
            // strings round-trip; SQLite stores 'Y-m-d H:i:s' but
            // tests may seed with createFromFormat-rejected variants.
            return new DateTimeImmutable($raw);
        }

        return $dt;
    }
}

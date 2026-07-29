<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Services;

use DateTimeImmutable;
use Generator;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Receipts\Public\Dto\FileImportDto;
use stdClass;

// Public read-side query over file_imports. latestForStatus() streams
// per-user via cursor() so a large drop history never materialises
// fully in memory, mirroring InboxMessageQuery::forStatus().
final readonly class FileImportQuery
{
    use CoercesScalars;

    private const ALLOWED_STATUSES = ['fetched', 'parsed', 'skipped', 'unmatched'];

    public function __construct(private DatabaseManager $db) {}

    /**
     * @return list<FileImportDto>
     */
    public function forUser(User $user): array
    {
        $rows = $this->db->connection()
            ->table('file_imports')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $out[] = $this->mapRow($row);
        }

        return $out;
    }

    /**
     * @return Generator<int, FileImportDto>
     */
    public function latestForStatus(User $user, string $status): Generator
    {
        if (! in_array($status, self::ALLOWED_STATUSES, strict: true)) {
            throw new InvalidArgumentException(
                "FileImportQuery::latestForStatus expected one of ['fetched','parsed','skipped','unmatched'], got '{$status}'."
            );
        }

        $rows = $this->db->connection()
            ->table('file_imports')
            ->where('user_id', $user->id)
            ->where('status', $status)
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            /** @var stdClass $row */
            yield $this->mapRow($row);
        }
    }

    private function mapRow(stdClass $row): FileImportDto
    {
        return new FileImportDto(
            id: self::toInt($row->id),
            userId: self::toInt($row->user_id),
            sourceKind: self::toString($row->source_kind),
            sourceFilename: self::toString($row->source_filename),
            providerMessageId: self::toString($row->provider_message_id),
            internalDate: self::toDateTime($row->internal_date),
            senderEmail: self::toString($row->sender_email),
            senderName: self::toNullableString($row->sender_name),
            subject: self::toNullableString($row->subject),
            emlPath: self::toString($row->eml_path),
            status: self::toString($row->status),
            matcherKey: self::toNullableString($row->matcher_key ?? null),
            fetchedAt: self::toDateTime($row->fetched_at),
        );
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
            return new DateTimeImmutable($raw);
        }

        return $dt;
    }
}

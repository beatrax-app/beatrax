<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Public\Support\LockStore;
use Modules\EmailScan\Public\Events\IcsStatementReady;
use stdClass;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class DetectIcsStatementReadyJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(DatabaseManager $db, Dispatcher $events, ConfigRepository $config): void
    {
        $domains = self::stringList($config->get('email-scan.ics_statement_ready.sender_domains', []));
        $patternRaw = $config->get('email-scan.ics_statement_ready.subject_pattern');
        $pattern = is_string($patternRaw) ? $patternRaw : '';

        if ($domains === [] || $pattern === '') {
            return;
        }

        $rows = $db->connection()
            ->table('inbox_messages')
            ->where('user_id', $this->userId)
            ->select(['id', 'user_id', 'sender_email', 'subject', 'internal_date'])
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $rowUserId = is_numeric($row->user_id) ? (int) $row->user_id : null;
            if ($rowUserId !== $this->userId) {
                // Defence-in-depth: mirrors ProcessFetchedInboxMessagesJob's
                // own cross-user re-check even though the query above is
                // already user_id-scoped.
                continue;
            }

            $senderEmail = is_string($row->sender_email) ? $row->sender_email : '';
            if (! self::senderMatchesDomain($senderEmail, $domains)) {
                continue;
            }

            $subject = is_string($row->subject) ? $row->subject : '';
            if ($subject === '' || preg_match($pattern, $subject) !== 1) {
                continue;
            }

            $internalDateRaw = is_string($row->internal_date) ? $row->internal_date : '';
            if ($internalDateRaw === '') {
                continue;
            }

            $messageId = is_numeric($row->id) ? (int) $row->id : null;
            if ($messageId === null) {
                continue;
            }

            // One malformed internal_date must not abort the whole
            // per-user sweep; skip the row on an unparseable date,
            // matching the is_numeric/is_string guard style above.
            try {
                $internalDate = CarbonImmutable::parse($internalDateRaw);
            } catch (InvalidFormatException) {
                continue;
            }

            $events->dispatch(new IcsStatementReady(
                userId: $this->userId,
                messageId: $messageId,
                internalDate: $internalDate,
            ));
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $domains
     */
    private static function senderMatchesDomain(string $senderEmail, array $domains): bool
    {
        if ($senderEmail === '') {
            return false;
        }

        $atTail = strrchr($senderEmail, '@');
        if ($atTail === false) {
            return false;
        }

        $domain = strtolower(substr($atTail, 1));

        return in_array($domain, $domains, true);
    }
}

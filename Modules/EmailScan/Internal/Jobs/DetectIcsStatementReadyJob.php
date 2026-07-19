<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use Carbon\CarbonImmutable;
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
 * Per-user metadata-only detector for the ICS "statement ready" nudge
 * (Req 14, D-14/D-15).
 *
 * Mirrors `Modules\Receipts\Internal\Jobs\ProcessFetchedInboxMessagesJob`'s
 * per-user query shape, but its ENTIRE input surface is
 * `inbox_messages.sender_email`/`.subject` — it never resolves an `.eml`
 * path via `EmlBlobStore`, never reads message bytes, and structurally
 * cannot parse transaction data (Req 14's "never parses the email body"
 * is enforced simply by this class never importing anything body-shaped).
 *
 * Deliberately status-agnostic (no `WHERE status = ...` clause):
 * `Modules\Receipts\Internal\Matchers\IcsReceiptMatcher::canHandle()`
 * already claims every `ics.nl`/`icscards.nl` sender for its own
 * independent, hourly receipt-parsing pass, and will likely have flipped
 * a co-matched row's `status` to `'unmatched'` by the time this job runs
 * (19-RESEARCH.md Architecture Patterns §7's "critical finding"). Gating
 * on status would silently miss the exact rows this detector cares about
 * — the two jobs are independent hourly-cadence consumers of the same
 * table with no ordering guarantee between them, and that co-claim is
 * expected and harmless.
 *
 * Sender-domain match is EXACT-equality on the domain part (never
 * substring), mirroring `IcsReceiptMatcher::canHandle()`'s spoofing
 * defence (T-19-16-02) — `ics.nl.attacker.example` must not match.
 *
 * No new "already nudged" bookkeeping lives on the EmailScan side: a
 * matching row dispatches `IcsStatementReady` on every tick this job
 * runs, and `Modules\Notifications\Internal\Support\NotificationWriter`'s
 * `insertOrIgnore` on the deterministic `(userId, triggerType, subjectKey,
 * occurrence='Y-m')` key absorbs the repeats into a single persisted
 * notification per statement-month (D-15) — the SAME idempotency seam
 * every other trigger listener in this codebase already relies on, so no
 * second dedup mechanism is introduced here.
 *
 * Sender allow-list + subject pattern are read from the tunable
 * `email-scan.ics_statement_ready` config block (no real ICS
 * statement-ready email sample exists — Open Question 1) — correctable
 * without a redeploy once a real sample surfaces during UAT.
 */
final class DetectIcsStatementReadyJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Retry attempts before final failure. */
    public int $tries = 3;

    /**
     * Exponential backoff in seconds.
     *
     * @var array<int, int>
     */
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
            // Tunable pattern not configured — nothing to detect against.
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

            $events->dispatch(new IcsStatementReady(
                userId: $this->userId,
                messageId: $messageId,
                internalDate: CarbonImmutable::parse($internalDateRaw),
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

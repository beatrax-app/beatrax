<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use DateTimeImmutable;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;

final class GraphDeltaWalk
{
    // Bounds one tick's work against a provider that keeps answering
    // nextLink. Stopping here defers pages rather than dropping them: the
    // nextLink returned as the cursor is itself a resumable delta URL.
    private const int PAGE_CAP = 25;

    /**
     * @return array{messages: list<array<string, mixed>>, cursor: ?string}
     */
    public function collect(
        GraphApiClientContract $graph,
        int $inboxId,
        ?string $deltaLink,
        ?DateTimeImmutable $sinceOverride = null,
    ): array {
        $messages = [];
        $link = $deltaLink;

        for ($page = 0; $page < self::PAGE_CAP; $page++) {
            $body = $graph->deltaPage($inboxId, $link, $sinceOverride);
            foreach ($body['messages'] as $message) {
                $messages[] = $message;
            }

            // Only the final page of a delta carries @odata.deltaLink; every
            // page before it carries @odata.nextLink instead.
            $delta = $body['deltaLink'];
            if ($delta !== null && $delta !== '') {
                return ['messages' => $messages, 'cursor' => $delta];
            }

            $link = $body['nextLink'];
            if ($link === null || $link === '') {
                return ['messages' => $messages, 'cursor' => null];
            }
        }

        return ['messages' => $messages, 'cursor' => $link];
    }
}

<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Status;

use Carbon\CarbonImmutable;
use Modules\Sync\Internal\Enums\SyncSessionStatus;
use stdClass;

// The four independent answers the session rows hold, folded once. They are
// not exclusive — a peer that finished an exchange yesterday and cannot be
// reached now is both — so which of them wins stays with the caller that ranks.
final readonly class PeerSessionTally
{
    public function __construct(
        public bool $error,
        public bool $syncing,
        public bool $unreachable,
        public bool $finished,
    ) {}

    /**
     * @param  array<int, stdClass>  $rows
     * @param  CarbonImmutable  $now  Dates the live claim an active row makes.
     */
    public static function over(array $rows, CarbonImmutable $now): self
    {
        $tally = new self(false, false, false, false);

        foreach ($rows as $row) {
            $tally = $tally->with($row, $now);
        }

        return $tally;
    }

    // A closed row, or a failed one seen at least once, means an exchange did
    // complete — which is what "up to date" is a claim about. Whether that peer
    // is reachable NOW is the separate answer beside it.
    private function with(stdClass $row, CarbonImmutable $now): self
    {
        $vars = get_object_vars($row);
        $status = SyncSessionStatus::tryFrom(is_string($vars['status'] ?? null) ? $vars['status'] : '');
        $message = is_string($vars['error_message'] ?? null) ? $vars['error_message'] : null;
        $seen = is_string($vars['last_seen_at'] ?? null) ? $vars['last_seen_at'] : '';

        $failed = $status === SyncSessionStatus::Failed;
        $unverified = $failed && PeerFailure::kind($message) === PeerFailureKind::Verification;

        // Only the reader can end a strand. close() is what writes the state an
        // active row leaves to, and the process that would have run it is the
        // one that died — so nothing repairs the row, and declining to believe
        // a stamp this old is the whole of the recovery available.
        $inFlight = $status !== null
            && $status->isLiveClaim()
            && SessionLiveness::isStampRecent($seen, $now);

        return new self(
            $this->error || $unverified,
            $this->syncing || $inFlight,
            $this->unreachable || ($failed && ! $unverified),
            $this->finished || $status === SyncSessionStatus::Closed || ($failed && $seen !== ''),
        );
    }
}

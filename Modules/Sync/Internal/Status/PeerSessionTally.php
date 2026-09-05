<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Status;

use stdClass;

// The four independent answers the session rows hold, folded once. They are
// not exclusive — a peer that finished an exchange yesterday and cannot be
// reached now is both — so which of them wins stays with the caller that ranks.
final readonly class PeerSessionTally
{
    private const array IN_FLIGHT = ['connecting', 'handshaking', 'active'];

    public function __construct(
        public bool $error,
        public bool $syncing,
        public bool $unreachable,
        public bool $finished,
    ) {}

    /**
     * @param  array<int, stdClass>  $rows
     */
    public static function over(array $rows): self
    {
        $tally = new self(false, false, false, false);

        foreach ($rows as $row) {
            $tally = $tally->with($row);
        }

        return $tally;
    }

    // A closed row, or a failed one seen at least once, means an exchange did
    // complete — which is what "up to date" is a claim about. Whether that peer
    // is reachable NOW is the separate answer beside it.
    private function with(stdClass $row): self
    {
        $vars = get_object_vars($row);
        $status = is_string($vars['status'] ?? null) ? $vars['status'] : '';
        $message = is_string($vars['error_message'] ?? null) ? $vars['error_message'] : null;
        $seen = is_string($vars['last_seen_at'] ?? null) ? $vars['last_seen_at'] : '';

        $failed = $status === 'failed';
        $unverified = $failed && PeerFailure::kind($message) === PeerFailureKind::Verification;

        return new self(
            $this->error || $unverified,
            $this->syncing || in_array($status, self::IN_FLIGHT, true),
            $this->unreachable || ($failed && ! $unverified),
            $this->finished || $status === 'closed' || ($failed && $seen !== ''),
        );
    }
}

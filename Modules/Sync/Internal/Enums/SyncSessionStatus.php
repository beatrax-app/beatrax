<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Enums;

// Every state a sync_sessions row can hold, which is every state a writer in
// this module writes. The schema comment listed five and the code wrote three;
// the two with no writer were branched on by both readers, so a machine with
// five states shipped with three and no surface could tell the difference.
enum SyncSessionStatus: string
{
    case Active = 'active';

    case Closed = 'closed';

    case Failed = 'failed';

    // The one state whose truth expires. Closed and failed record how a session
    // ENDED and stay true forever; active is a claim about right now, and only
    // the process holding the connection can keep it current — so a reader must
    // date it rather than believe it.
    public function isLiveClaim(): bool
    {
        return $this === self::Active;
    }
}

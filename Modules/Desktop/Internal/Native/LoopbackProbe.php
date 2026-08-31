<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

// How long a liveness dial at 127.0.0.1 waits before calling the port unbound.
// One second, because a loopback socket either answers immediately or is not
// there. Both listener processes asked the same question with their own copy
// of the answer.
final class LoopbackProbe
{
    public const int TIMEOUT_SECONDS = 1;
}

<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The counterpart to SafeExceptionContext: this exception's message names the
// SHAPE of what failed — a format, a missing column, an unregistered adapter —
// never a value read out of the file. A broad catch may log the message for
// these, and must drop it for everything else it might have caught.
interface MessageNamesNoUserData {}

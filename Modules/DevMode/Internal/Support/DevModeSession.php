<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Support;

// A typo in a reader fails closed, but one in the forget() that clears
// ADVANCED_KEY fails open and leaves Advanced armed across logins.
final class DevModeSession
{
    public const ADVANCED_KEY = 'dev_mode.advanced';

    public const ADVANCED_SEEN_KEY = 'dev_mode.advanced_session_seen';
}

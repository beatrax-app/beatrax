<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use RuntimeException;

// A bundled fixture the fake provider clients read is missing or malformed.
// These clients back demo mode, so reaching this means the shipped fixture set
// is wrong rather than anything about a user's mailbox — no live provider is
// involved and no retry can help.
/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class FixtureUnusableException extends RuntimeException {}

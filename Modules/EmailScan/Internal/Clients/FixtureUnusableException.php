<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use RuntimeException;

// A bundled fixture is missing or malformed. The fake clients back demo mode,
// so this is a packaging fault no retry can help.
final class FixtureUnusableException extends RuntimeException {}

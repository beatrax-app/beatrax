<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

use RuntimeException;

// The read-only child process ended without a result: a non-zero exit, or
// output the parent could not read. Named apart from a timeout because that
// one is the statement's own fault and this one is the process's.
final class IsolatedSelectFailedException extends RuntimeException {}

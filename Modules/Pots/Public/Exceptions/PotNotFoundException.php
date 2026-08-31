<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Exceptions;

use InvalidArgumentException;

// One type for "no such pot" and "not yours" on purpose, and the page keeps
// them one sentence: told apart, the refusal answers whether a given id exists
// for somebody else, which is an enumeration oracle over another user's pots.
class PotNotFoundException extends InvalidArgumentException {}

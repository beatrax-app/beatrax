<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Exceptions;

// Narrows its parent so a move can say which of its two pots went missing: the
// source is the card the reader opened, the target is the one they picked, and
// the corrective action is a different one for each.
final class TargetPotNotFoundException extends PotNotFoundException {}

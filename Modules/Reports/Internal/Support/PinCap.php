<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

// Enforced twice — once where a pin is written, once where the pinned strip is
// read — so a stray fourth row can never reach a fourth mini card. Two
// enforcement points is the defence; two numbers would be the bug it is
// defending against, and the sentence that tells the reader is a third.
final class PinCap
{
    public const int MAX_PINS = 3;
}

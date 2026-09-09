<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Mobile\Internal\Notifications\PlatformNotificationSwitch;

// The platform's own answer, which off a device comes from a bridge function
// that does not exist. null is the third answer and the one that matters most:
// a shell that cannot be asked is not a shell that said no.
final class AnsweringPlatformNotificationSwitch implements PlatformNotificationSwitch
{
    public int $reads = 0;

    public function __construct(private readonly ?bool $answer) {}

    public function enabled(): ?bool
    {
        $this->reads++;

        return $this->answer;
    }
}

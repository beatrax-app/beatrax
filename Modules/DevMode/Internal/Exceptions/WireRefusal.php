<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Exceptions;

// A refusal the runner's fetch() reads as JSON. Both spawn endpoints answer
// every refusal the same way, so the wire shape belongs on the refusal rather
// than in a match at each endpoint.
interface WireRefusal
{
    /**
     * @return array<string, string>
     */
    public function wirePayload(): array;

    public function wireStatus(): int;
}

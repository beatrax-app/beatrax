<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Exceptions;

use Modules\Core\Public\Support\Lang;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

// The runtime will not start a child process at all, so no command reaches the
// point where it could fail. Separate from SpawnProcessException because that
// one describes a launch that went wrong, and the console has to answer these
// two differently: one is worth retrying, the other never will be.
final class ProcessSpawningUnavailableException extends RuntimeException implements WireRefusal
{
    public const string WIRE_ERROR = 'spawning_unavailable';

    // The runner page toasts it, both spawn endpoints put it on the wire, and
    // the Doctor panel shows what came back, so the sentence lives here rather
    // than three times over.
    public function readerMessage(): string
    {
        return Lang::get('dev::runner.spawning_unavailable');
    }

    /**
     * @return array<string, string>
     */
    public function wirePayload(): array
    {
        return ['error' => self::WIRE_ERROR, 'message' => $this->readerMessage()];
    }

    public function wireStatus(): int
    {
        return Response::HTTP_NOT_IMPLEMENTED;
    }
}

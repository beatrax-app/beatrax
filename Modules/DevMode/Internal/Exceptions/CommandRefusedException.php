<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

// Every way a spawn can be turned down, carrying the JSON the runner's fetch()
// reads. The command name rides along only where one was resolved: the two 403s
// answer a name the caller already knows, and echoing an unparsed payload back
// would put attacker-chosen text on the wire.
final class CommandRefusedException extends RuntimeException
{
    /**
     * @param  array<string, string>  $payload
     */
    private function __construct(private readonly array $payload, private readonly int $status)
    {
        parent::__construct($payload['error']);
    }

    public static function invalidCommand(): self
    {
        return new self(['error' => 'invalid_command'], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function destructiveRequiresTripleGate(): self
    {
        return new self(['error' => 'destructive_requires_triple_gate'], Response::HTTP_FORBIDDEN);
    }

    public static function unknownCommand(string $command): self
    {
        return new self(['error' => 'unknown_command', 'command' => $command], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function notDestructive(string $command): self
    {
        return new self(['error' => 'not_destructive', 'command' => $command], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // A platform with no interpreter is a refusal like any other here, so the
    // endpoints answer one type. The reader's sentence still comes from the
    // exception that knows the reason, and the Livewire callers keep catching
    // that one directly.
    public static function spawningUnavailable(ProcessSpawningUnavailableException $cause): self
    {
        return new self(
            ['error' => ProcessSpawningUnavailableException::WIRE_ERROR, 'message' => $cause->readerMessage()],
            Response::HTTP_NOT_IMPLEMENTED,
        );
    }

    /**
     * @return array<string, string>
     */
    public function wirePayload(): array
    {
        return $this->payload;
    }

    public function wireStatus(): int
    {
        return $this->status;
    }
}

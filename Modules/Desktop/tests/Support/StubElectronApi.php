<?php

declare(strict_types=1);

namespace Modules\Desktop\Tests\Support;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Native\Desktop\Client\Client;

// Answers the shell's HTTP API from a canned body, through the real Response
// so json() decoding is exercised rather than stubbed: a 404 from a bundle
// built before a route existed decodes to null exactly as it would on disk.
final class StubElectronApi extends Client
{
    /** @var list<string> */
    public array $endpoints = [];

    public function __construct(
        private readonly string $body = '{}',
        private readonly int $status = 200,
        private readonly bool $refusesToConnect = false,
    ) {}

    public function get(string $endpoint, array|string|null $query = null): Response
    {
        $this->endpoints[] = $endpoint;

        if ($this->refusesToConnect) {
            throw new ConnectionException('the shell is not listening');
        }

        return new Response(
            new PsrResponse($this->status, ['Content-Type' => 'application/json'], $this->body),
        );
    }
}

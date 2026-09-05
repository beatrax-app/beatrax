<?php

declare(strict_types=1);

namespace Modules\Desktop\Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Client\Client;

// Answers the shell's HTTP API from a canned body, through a real Response so
// json() decoding is exercised rather than stubbed: a 404 from a bundle built
// before a route existed decodes to null exactly as it would on disk. Built
// through Http::fake() rather than a PSR-7 response of its own, because
// GuzzleHttp is contained to the two modules that speak to a bank.
final class StubElectronApi extends Client
{
    private const string UNREACHABLE_HOST = 'https://electron.invalid/api/';

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

        Http::fake(['*' => Http::response($this->body, $this->status)]);

        return Http::get(self::UNREACHABLE_HOST.$endpoint);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use Amp\Http\Server\Driver\Client as AmpClient;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Socket\InternetAddress;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use League\Uri\Http as HttpUri;
use Mockery;
use Modules\Sync\Commands\RelayServeCommand;
use ReflectionMethod;

use function Amp\ByteStream\buffer;

// Routes a RelayClient's outbound HTTP through the REAL RelayServeCommand
// route handlers instead of a socket, so a test exercises the production
// deliver/drain/confirm code path end to end without booting a server.
final class RelayHandlerHarness
{
    public static function httpFactory(RelayServeCommand $command): HttpFactory
    {
        $factory = new HttpFactory;
        $route = new ReflectionMethod($command, 'route');

        $factory->fake(function (ClientRequest $request) use ($command, $route) {
            /** @var AmpResponse $ampResponse */
            $ampResponse = $route->invoke($command, self::toAmpRequest($request));

            return HttpFactory::response(
                buffer($ampResponse->getBody()),
                $ampResponse->getStatus(),
                ['Content-Type' => 'application/json'],
            );
        });

        return $factory;
    }

    private static function toAmpRequest(ClientRequest $request): AmpRequest
    {
        $client = Mockery::mock(AmpClient::class);
        // Deliver reads the source IP for its rate-limit bucket.
        $client->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress('127.0.0.1', 12345));

        $headers = [];
        if ($request->hasHeader('Authorization')) {
            $headers['authorization'] = $request->header('Authorization')[0];
        }

        return new AmpRequest(
            $client,
            $request->method(),
            HttpUri::new($request->url()),
            $headers,
            $request->body(),
        );
    }
}

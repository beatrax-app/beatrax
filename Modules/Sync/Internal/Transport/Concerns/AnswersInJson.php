<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Concerns;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;

// Both amphp listeners in this module answer in the same shape, and two of
// their refusals have to read identically wherever they come from: whoever
// probes any of these routes must learn "no" and nothing else. One body each,
// written once, is what keeps that true as routes are added.
trait AnswersInJson
{
    private const string JSON_CONTENT_TYPE = 'application/json';

    // One body for every refusal. An unknown token, an expired one, a
    // malformed frame, one belonging to another user and one whose ceremony
    // already finished are indistinguishable on purpose: a prober must not
    // learn which pairings are in flight, or which mailbox rows exist.
    private const string NOT_FOUND_BODY = '{"error":"not_found"}';

    private const string RATE_LIMITED_BODY = '{"error":"rate_limited"}';

    private function json(int $status, string $body): Response
    {
        return new Response($status, ['content-type' => self::JSON_CONTENT_TYPE], $body);
    }

    // The two refusals are methods, not bodies a caller pairs with a status:
    // the point of one body is that every route spells the refusal the same
    // way, and a constant left that to whoever wrote the next route.
    private function notFound(): Response
    {
        return $this->json(HttpStatus::NOT_FOUND, self::NOT_FOUND_BODY);
    }

    private function rateLimited(): Response
    {
        return $this->json(HttpStatus::TOO_MANY_REQUESTS, self::RATE_LIMITED_BODY);
    }

    // Bucketed per host, not per connection: dropping the ephemeral port makes
    // every attempt from one machine share one budget.
    private function clientKey(Request $request): string
    {
        $address = $request->getClient()->getRemoteAddress();

        return $address instanceof InternetAddress ? $address->getAddress() : $address->toString();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function queryParams(Request $request): array
    {
        $params = [];
        parse_str($request->getUri()->getQuery(), $params);

        return $params;
    }

    // parse_str() builds nested arrays from `device[]=x`, so anything that is
    // not a plain string degrades to '' and is answered exactly the way a
    // missing parameter is.
    /**
     * @param  array<array-key, mixed>  $params
     */
    private static function stringParam(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}

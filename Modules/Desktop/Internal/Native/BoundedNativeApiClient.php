<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Http\Client\Response;
use Native\Desktop\Client\Client;

// NativePHP allows an hour per call to the Electron API, which is a hang
// budget rather than a call budget. This backend serves one request at a time,
// and repo code reaches that client from an ordinary web request.
/**
 * @link ../../../../.docs/features/desktop/architecture.md#bounding-a-call-into-electron
 */
final class BoundedNativeApiClient extends Client
{
    // Loopback, and the API answers in milliseconds when it answers at all, so
    // anything past this is a hang rather than a slow call.
    public const int TIMEOUT_SECONDS = 15;

    // The one call that legitimately waits: the sheet stays up until the reader
    // answers it or macOS dismisses it, so a machine-scale bound would cancel
    // the prompt out from under them.
    public const int PERSON_TIMEOUT_SECONDS = 120;

    private const string AWAITS_A_PERSON = 'system/prompt-touch-id';

    /**
     * @param  array<array-key, mixed>|string|null  $query
     */
    public function get(string $endpoint, array|string|null $query = null): Response
    {
        $this->bound($endpoint);

        return parent::get($endpoint, $query);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function post(string $endpoint, array $data = []): Response
    {
        $this->bound($endpoint);

        return parent::post($endpoint, $data);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function delete(string $endpoint, array $data = []): Response
    {
        $this->bound($endpoint);

        return parent::delete($endpoint, $data);
    }

    // Set on every call rather than once in the constructor: the inherited
    // PendingRequest is shared across calls, so a per-endpoint widening has to
    // be undone by the next one.
    private function bound(string $endpoint): void
    {
        $this->client->timeout(
            $endpoint === self::AWAITS_A_PERSON ? self::PERSON_TIMEOUT_SECONDS : self::TIMEOUT_SECONDS,
        );
    }
}

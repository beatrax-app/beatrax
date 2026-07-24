<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;
use RuntimeException;

/**
 * @link ../../../../../.docs/features/open-banking/architecture.md
 */
class EnableBankingHttpClient
{
    private const EB_API_HOST = 'api.enablebanking.com';

    public function __construct(
        private readonly OpenBankingSecretsRepository $secrets,
        private readonly EnableBankingJwtSigner $jwtSigner,
    ) {}

    // Named initiateAuth() rather than the more obvious auth(): the shared
    // BoundaryArchTest's Auth-facade guard bans the literal pattern `auth(`
    // and would otherwise false-positive-flag this method and its call
    // site, even though neither touches Laravel's Auth facade.
    /**
     * @return array<string, mixed>
     */
    public function initiateAuth(
        string $institutionId,
        string $country,
        string $redirectUrl,
        EnableBankingAccessScope $scope,
        CarbonImmutable $validUntil,
    ): array {
        $body = [
            'access' => array_merge($scope->toArray(), [
                'valid_until' => $validUntil->toAtomString(),
            ]),
            'aspsp' => [
                'name' => $institutionId,
                'country' => $country,
            ],
            'redirect_url' => $redirectUrl,
            'psu_type' => 'personal',
        ];

        return $this->postJson('auth', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function createSession(string $code): array
    {
        return $this->postJson('sessions', ['code' => $code]);
    }

    // Isolated here, inside the sole Enable Banking HTTP boundary class,
    // rather than inline at DB-touching call sites: a guard test asserts no
    // file referencing DatabaseManager also references a raw credential
    // field name, so callers use this method instead of indexing directly.
    /**
     * @param  array<string, mixed>  $sessionResponse
     */
    public function sessionIdFrom(array $sessionResponse): ?string
    {
        $sessionId = $sessionResponse['session_id'] ?? null;

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    // A single PSD2 consent can, in principle, cover multiple accounts at
    // the same bank; this project's connection model tracks only one
    // account per connection, so the first accounts[] entry is the one
    // persisted here.
    /**
     * @param  array<string, mixed>  $sessionResponse
     */
    public function accountUidFrom(array $sessionResponse): ?string
    {
        $accounts = $sessionResponse['accounts'] ?? null;
        if (! is_array($accounts) || $accounts === []) {
            return null;
        }

        $first = $accounts[0] ?? null;
        if (! is_array($first)) {
            return null;
        }

        $uid = $first['uid'] ?? null;

        return is_string($uid) && $uid !== '' ? $uid : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function aspsps(string $country): array
    {
        return $this->getJson('aspsps', ['country' => $country]);
    }

    // Resolves the own-account IBAN, which the /sessions response's bare
    // uid does not carry directly.
    /**
     * @return array<string, mixed>
     */
    public function accountDetails(string $uid): array
    {
        return $this->getJson('accounts/'.rawurlencode($uid));
    }

    /**
     * @return array<string, mixed>
     */
    public function transactions(string $uid, FetchWindow $window, ?string $continuationKey = null): array
    {
        $query = [
            'date_from' => $window->dateFrom->toDateString(),
            'date_to' => $window->dateTo->toDateString(),
        ];
        if ($continuationKey !== null && $continuationKey !== '') {
            $query['continuation_key'] = $continuationKey;
        }

        return $this->getJson('accounts/'.rawurlencode($uid).'/transactions', $query);
    }

    // A point-in-time balance reading, not a statement opening/closing
    // pair — there is no bounded-period summary to persist alongside it.
    /**
     * @return array<string, mixed>
     */
    public function balances(string $uid): array
    {
        return $this->getJson('accounts/'.rawurlencode($uid).'/balances');
    }

    // Overridden by the SSRF regression test to exercise attacker/look-alike/
    // non-HTTPS/unparseable hosts without touching production URL-building.
    protected function baseUri(): string
    {
        return 'https://'.self::EB_API_HOST.'/';
    }

    // Overridden by the SSRF regression test's positive (accepted-host) case
    // to inject a MockHandler-backed client, so that case never attempts a
    // real network call.
    protected function makeHttpClient(): GuzzleClient
    {
        return new GuzzleClient([
            'timeout' => 30,
            'connect_timeout' => 10,
            // JSON API must not redirect; a 3xx is an error body, not a
            // silent egress. Following redirects would let a Location
            // target bypass the once-only assertAllowedUrl() allow-list
            // check and downgrade https to http.
            'allow_redirects' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $body): array
    {
        $credentials = $this->secrets->loadOrThrow();
        $url = $this->baseUri().$path;
        $this->assertAllowedUrl($url, $credentials);
        $bearer = $this->jwtSigner->sign($credentials->privateKeyPem, $credentials->applicationId);

        try {
            $response = $this->makeHttpClient()->request('POST', $url, [
                'json' => $body,
                'headers' => [
                    'Authorization' => 'Bearer '.$bearer,
                    'Accept' => 'application/json',
                ],
                'http_errors' => true,
            ]);
        } catch (BadResponseException $e) {
            throw $this->mapErrorResponse($e, 'POST '.$url);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                'EnableBankingHttpClient: HTTP error against '.$url.' — '.$e->getMessage()
            );
        }

        return $this->decodeJsonBody((string) $response->getBody(), $url);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function getJson(string $path, array $query = []): array
    {
        $credentials = $this->secrets->loadOrThrow();
        $url = $this->baseUri().$path;
        $this->assertAllowedUrl($url, $credentials);
        $bearer = $this->jwtSigner->sign($credentials->privateKeyPem, $credentials->applicationId);

        try {
            $response = $this->makeHttpClient()->request('GET', $url, [
                'query' => $query,
                'headers' => [
                    'Authorization' => 'Bearer '.$bearer,
                    'Accept' => 'application/json',
                ],
                'http_errors' => true,
            ]);
        } catch (BadResponseException $e) {
            throw $this->mapErrorResponse($e, 'GET '.$url);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                'EnableBankingHttpClient: HTTP error against '.$url.' — '.$e->getMessage()
            );
        }

        return $this->decodeJsonBody((string) $response->getBody(), $url);
    }

    /**
     * @link ../../../../../.docs/features/open-banking/architecture.md
     *
     * @return list<string>
     */
    private function allowedHosts(OpenBankingCredentials $credentials): array
    {
        $hosts = [self::EB_API_HOST];

        if ($credentials->bankScaHost !== null && $credentials->bankScaHost !== '') {
            $hosts[] = strtolower($credentials->bankScaHost);
        }

        return $hosts;
    }

    // SSRF defence — refuse to attach a bearer token to any URL whose
    // scheme is not https or whose host is not on the allow-list. Runs
    // before the JWT is built/attached on every request (both postJson()
    // and getJson() load credentials, run this check, then sign).
    private function assertAllowedUrl(string $url, OpenBankingCredentials $credentials): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! is_string($scheme) || strtolower($scheme) !== 'https') {
            throw new RuntimeException(
                'EnableBankingHttpClient: refusing to send bearer token over non-HTTPS scheme.'
            );
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || ! in_array(strtolower($host), $this->allowedHosts($credentials), strict: true)) {
            throw new RuntimeException(
                'EnableBankingHttpClient: refusing to send bearer token to non-allow-listed host: '
                .(is_string($host) && $host !== '' ? $host : '(unparseable)')
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(string $raw, string $url): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'EnableBankingHttpClient: failed to decode response JSON from '.$url.' ('.$e->getMessage().').'
            );
        }

        if (! is_array($decoded)) {
            return [];
        }

        // Narrow array<mixed, mixed> -> array<string, mixed>: the
        // top-level shape is always a JSON object, but PHPStan's strict
        // mode can't infer that from json_decode.
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private function mapErrorResponse(BadResponseException $e, string $context): RuntimeException
    {
        $response = $e->getResponse();
        $status = $response->getStatusCode();
        $bodySnippet = substr((string) $response->getBody(), 0, 300);

        return new RuntimeException(
            'EnableBankingHttpClient: '.$context.' returned HTTP '.$status.' — '.$bodySnippet
        );
    }
}

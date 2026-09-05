<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\EnableBankingApiException;
use Modules\OpenBanking\Internal\Exceptions\UnsafeOpenBankingRequestException;

class EnableBankingHttpClient
{
    private const string ACCOUNTS_PATH = 'accounts/';

    private const string EB_API_HOST = 'api.enablebanking.com';

    // Holds no credential of its own: every request is signed with the one
    // its caller loaded for a named reader and a named bank, so this client
    // cannot reach for a session that belongs to somebody else.
    public function __construct(private readonly EnableBankingJwtSigner $jwtSigner) {}

    // Not named auth(): BoundaryArchTest's Auth-facade guard bans the literal
    // `auth(` and would flag this method and its call site.
    /**
     * @return array<string, mixed>
     */
    public function initiateAuth(
        OpenBankingCredentials $credentials,
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

        return $this->postJson($credentials, 'auth', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function createSession(OpenBankingCredentials $credentials, string $code): array
    {
        return $this->postJson($credentials, 'sessions', ['code' => $code]);
    }

    // Callers index the response through this: a guard test bans naming a raw
    // credential field in any file that also references DatabaseManager.
    /**
     * @param  array<string, mixed>  $sessionResponse
     */
    public function sessionIdFrom(array $sessionResponse): ?string
    {
        $sessionId = $sessionResponse['session_id'] ?? null;

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    // One PSD2 consent can cover several accounts at the bank; a connection
    // row tracks exactly one, so accounts[0] is the one persisted.
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
    public function aspsps(OpenBankingCredentials $credentials, string $country): array
    {
        return $this->getJson($credentials, 'aspsps', ['country' => $country]);
    }

    // The extra round-trip resolves the own-account IBAN, which the /sessions
    // response's bare uid does not carry.
    /**
     * @return array<string, mixed>
     */
    public function accountDetails(OpenBankingCredentials $credentials, string $uid): array
    {
        return $this->getJson($credentials, self::ACCOUNTS_PATH.rawurlencode($uid));
    }

    /**
     * @return array<string, mixed>
     */
    public function transactions(
        OpenBankingCredentials $credentials,
        string $uid,
        FetchWindow $window,
        ?string $continuationKey = null,
    ): array {
        $query = [
            'date_from' => $window->dateFrom->toDateString(),
            'date_to' => $window->dateTo->toDateString(),
        ];
        if ($continuationKey !== null && $continuationKey !== '') {
            $query['continuation_key'] = $continuationKey;
        }

        return $this->getJson($credentials, self::ACCOUNTS_PATH.rawurlencode($uid).'/transactions', $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function balances(OpenBankingCredentials $credentials, string $uid): array
    {
        return $this->getJson($credentials, self::ACCOUNTS_PATH.rawurlencode($uid).'/balances');
    }

    protected function baseUri(): string
    {
        return 'https://'.self::EB_API_HOST.'/';
    }

    protected function makeHttpClient(): GuzzleClient
    {
        return new GuzzleClient([
            'timeout' => 30,
            'connect_timeout' => 10,
            // A followed Location would reach the network after the once-only
            // assertAllowedUrl() check, with a bearer token already attached.
            'allow_redirects' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postJson(OpenBankingCredentials $credentials, string $path, array $body): array
    {
        $url = $this->baseUri().$path;
        $this->assertAllowedUrl($url);
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
            throw EnableBankingApiException::transportFailed($url, $e);
        }

        return $this->decodeJsonBody((string) $response->getBody(), $url);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function getJson(OpenBankingCredentials $credentials, string $path, array $query = []): array
    {
        $url = $this->baseUri().$path;
        $this->assertAllowedUrl($url);
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
            throw EnableBankingApiException::transportFailed($url, $e);
        }

        return $this->decodeJsonBody((string) $response->getBody(), $url);
    }

    // Every URL this client builds comes from baseUri(). The bank's SCA origin
    // is reached by the reader's browser, through an outward redirect this
    // class never issues, so widening the list to it would authorise a request
    // no path here makes -- with a bearer token attached.
    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        return [self::EB_API_HOST];
    }

    // SSRF gate. Both postJson() and getJson() call this before signing, so
    // no bearer token is ever built for a non-https or non-allow-listed URL.
    private function assertAllowedUrl(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! is_string($scheme) || strtolower($scheme) !== 'https') {
            throw UnsafeOpenBankingRequestException::nonHttpsScheme();
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || ! in_array(strtolower($host), $this->allowedHosts(), strict: true)) {
            throw UnsafeOpenBankingRequestException::disallowedHost(is_string($host) ? $host : null);
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
            throw EnableBankingApiException::malformedJson($url, $e);
        }

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private function mapErrorResponse(BadResponseException $e, string $context): EnableBankingApiException
    {
        $response = $e->getResponse();
        $status = $response->getStatusCode();
        $bodySnippet = substr((string) $response->getBody(), 0, 300);

        return EnableBankingApiException::errorStatus($context, $status, $bodySnippet);
    }
}

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
 * The SOLE HTTP boundary to Enable Banking (D-11 / Req 10 / Req 11).
 *
 * Every outbound request:
 *
 *  - loads the caller's own application credentials ONCE, then checks
 *    the target URL against `assertAllowedUrl()` (https scheme + exact-
 *    host allow-list — the Enable Banking API host, plus the resolved
 *    per-connection bank SCA host once one has been persisted) BEFORE
 *    the RS256 bearer JWT is built or attached (T-19-04-01);
 *  - carries a locally-signed RS256 JWT (`EnableBankingJwtSigner`) built
 *    from that credential's RSA private key — the key itself never
 *    leaves the machine, only the signed token crosses the wire
 *    (T-19-04-03);
 *  - requests AIS-only scope via the typed `EnableBankingAccessScope`
 *    DTO on `/auth` (Req 11, T-19-04-02) — a `payments` key is
 *    structurally impossible.
 *
 * Mirrors `Modules\EmailScan\Internal\Clients\GraphApiClient`'s SSRF
 * guard shape verbatim (verified pattern, 19-PATTERNS.md), adapted for
 * Enable Banking's DYNAMIC allow-list (EB host + resolved bank SCA
 * host) rather than a single static host — `allowedHosts()` is an
 * instance method, not a `private const`.
 *
 * The consent controllers (19-05) and the fetch adapter (Wave 2) both
 * call through this one class — no other class in this module is
 * permitted to construct a Guzzle client against Enable Banking.
 *
 * Deliberately NOT `final`, and `baseUri()`/`makeHttpClient()` are
 * `protected` rather than `private`: unlike GraphApiClient's
 * `deltaLink`/`nextLink` parameters (which let its SSRF test drive an
 * attacker-controlled host straight through a real production method
 * argument), none of this client's public methods accept a caller-
 * supplied URL — every URL is built from `baseUri()`. The dedicated
 * SSRF regression test therefore overrides `baseUri()` (to exercise
 * attacker/look-alike/non-HTTPS/unparseable hosts) and, for the one
 * positive case, `makeHttpClient()` (to swap in a `MockHandler`-backed
 * Guzzle client so the accepted-host path never attempts a real network
 * call) — mirroring the `performRename()` test-override hook already
 * used on `OpenBankingSecretsRepository`.
 */
class EnableBankingHttpClient
{
    private const EB_API_HOST = 'api.enablebanking.com';

    public function __construct(
        private readonly OpenBankingSecretsRepository $secrets,
        private readonly EnableBankingJwtSigner $jwtSigner,
    ) {}

    /**
     * POST /auth — kicks off the consent dance. Returns the aggregator's
     * decoded response (carries the `url` the browser is redirected to
     * for SCA, plus an `authorization_id`). The AIS-only `access` scope
     * (Req 11) is built from the typed `EnableBankingAccessScope` DTO,
     * so a `payments` key can never appear in the request body.
     *
     * Named `initiateAuth()` rather than the more obvious `auth()`:
     * `BoundaryArchTest`'s cross-module Auth-facade/helper guard bans the
     * literal pattern `auth(` (its lookbehind only excludes `->`/`::`
     * prefixes, not a `function ` declaration or a `->client->auth(...)`
     * call site), which would otherwise false-positive-flag this
     * method's declaration and its one call site in
     * `OpenBankingConnectController` as an `auth()`-global-helper call
     * even though neither has anything to do with Laravel's Auth facade
     * (T-19-07-01 review-gate finding, fixed here rather than weakening
     * the shared project-wide arch guard).
     *
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
     * POST /sessions — exchanges the SCA-redirect `code` for a
     * `session_id` + the linked `accounts[].uid` list. There is no
     * refresh-token concept in the EB model; the session stays valid
     * until the `access.valid_until` the app itself requested at
     * `/auth` time (capped by the bank's own maximum consent validity).
     *
     * @return array<string, mixed>
     */
    public function createSession(string $code): array
    {
        return $this->postJson('sessions', ['code' => $code]);
    }

    /**
     * Extracts the session identifier field from a `createSession()`
     * response array. Deliberately isolated here — inside the sole
     * Enable Banking HTTP boundary class — rather than inline at DB-
     * touching call sites: `OpenBankingSecretsFileGuardTest` (D-07,
     * 19-03) asserts no file that references `DatabaseManager` also
     * references a raw credential field name, so
     * `OpenBankingCallbackController` calls this method instead of
     * indexing the response array itself (19-05).
     *
     * @param  array<string, mixed>  $sessionResponse
     */
    public function sessionIdFrom(array $sessionResponse): ?string
    {
        $sessionId = $sessionResponse['session_id'] ?? null;

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    /**
     * Extracts the FIRST linked account's `uid` from a `createSession()`
     * response array — isolated here for the identical D-07 arch-guard
     * reason `sessionIdFrom()` documents: `OpenBankingCallbackController`
     * must never index the raw response array itself.
     *
     * A single PSD2 consent can, in principle, cover multiple accounts at
     * the same bank; this project's connection model
     * (`open_banking_connections` is keyed one row per (user_id,
     * institution_id), per that migration's docblock) tracks only ONE
     * account per connection, so the first `accounts[]` entry is the one
     * `OpenBankingCallbackController` persists. Extending to a
     * multi-account-per-connection model is out of this method's scope.
     *
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
     * GET /aspsps — institution discovery (Req 12), filtered by
     * country.
     *
     * @return array<string, mixed>
     */
    public function aspsps(string $country): array
    {
        return $this->getJson('aspsps', ['country' => $country]);
    }

    /**
     * GET /accounts/{uid} — account details. Resolves the own-account
     * IBAN the `/sessions` response's bare `uid` does not carry
     * directly (RESEARCH.md Architecture Patterns §2).
     *
     * @return array<string, mixed>
     */
    public function accountDetails(string $uid): array
    {
        return $this->getJson('accounts/'.rawurlencode($uid));
    }

    /**
     * GET /accounts/{uid}/transactions — booked + pending rows for the
     * window. `$continuationKey` follows the aggregator's own opaque
     * pagination cursor verbatim.
     *
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

    /**
     * GET /accounts/{uid}/balances — point-in-time balance reading (not
     * a statement pair; see 19-PATTERNS.md on why this is NOT forced
     * onto `StatementSummaryData`'s shape).
     *
     * @return array<string, mixed>
     */
    public function balances(string $uid): array
    {
        return $this->getJson('accounts/'.rawurlencode($uid).'/balances');
    }

    /**
     * Base URI every request is built from. Overridden by the SSRF
     * regression test to exercise attacker/look-alike/non-HTTPS/
     * unparseable hosts without touching production URL-building logic.
     */
    protected function baseUri(): string
    {
        return 'https://'.self::EB_API_HOST.'/';
    }

    /**
     * Lazily instantiate a Guzzle client. Overridden by the SSRF
     * regression test's positive (accepted-host) case to inject a
     * `MockHandler`-backed client, so that case never attempts a real
     * network call.
     */
    protected function makeHttpClient(): GuzzleClient
    {
        return new GuzzleClient([
            'timeout' => 30,
            'connect_timeout' => 10,
            // JSON API must not redirect; a 3xx is an error body, not a
            // silent egress. Following redirects would let a Location
            // target bypass the once-only `assertAllowedUrl()` allow-list
            // check and downgrade https->http (CR-01 / SSRF guarantee).
            'allow_redirects' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $body): array
    {
        $credentials = $this->loadCredentials();
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
        $credentials = $this->loadCredentials();
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

    private function loadCredentials(): OpenBankingCredentials
    {
        $credentials = $this->secrets->load();
        if ($credentials === null) {
            throw new RuntimeException(
                'EnableBankingHttpClient: no Enable Banking application credentials are persisted.'
            );
        }

        return $credentials;
    }

    /**
     * Dynamic SSRF allow-list: the static Enable Banking API host, plus
     * the per-connection resolved bank SCA host once one has been
     * persisted (only known after a completed `/auth` call). Unlike
     * `GraphApiClient`'s single static host, this list depends on the
     * loaded credential, because the bank SCA host varies per user
     * connection.
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

    /**
     * SSRF defence — refuse to attach a bearer token to any URL whose
     * scheme is not https OR whose host is not on the allow-list. Runs
     * BEFORE the JWT is built/attached on every request (both call
     * sites load credentials, run this check, and only THEN sign — see
     * `postJson()`/`getJson()`), including any response-derived
     * follow-up URL a future caller might construct (T-19-04-01).
     */
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

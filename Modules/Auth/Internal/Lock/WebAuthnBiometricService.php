<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Exceptions\BiometricChallengeException;
use Modules\Auth\Internal\Exceptions\BiometricEnrollmentException;
use Modules\Auth\Internal\Exceptions\WebAuthnSerializerException;
use Modules\Core\Public\Contracts\SecretShield;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

// rpId is the host portion of APP_URL, and the full origin is validated
// separately: both must be, or a same-rpId attacker page passes.
final class WebAuthnBiometricService
{
    private const LOCALHOST_ORIGIN = 'http://localhost';

    public const CREATION_CHALLENGE_SESSION = 'beatrax_webauthn_creation_challenge';

    public const REQUEST_CHALLENGE_SESSION = 'beatrax_webauthn_request_challenge';

    public function __construct(
        private readonly BiometricDeviceStore $store,
        private readonly AppLockKeyWrap $keyWrap,
        private readonly LockStateManager $lockState,
        private readonly ConfigRepository $config,
        private readonly SecretShield $shield,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function creationOptions(int $userId, string $username, Session $session): array
    {
        $rpId = $this->rpId();
        $challenge = random_bytes(32);

        // excludeCredentials makes the browser reject a re-enrol of the same
        // authenticator rather than creating a duplicate row.
        /** @var list<PublicKeyCredentialDescriptor> $excludeCredentials */
        $excludeCredentials = [];
        foreach ($this->store->findForUser($userId) as $cred) {
            $credId = $cred->credential_id;
            if (! is_string($credId)) {
                continue;
            }

            $rawId = base64_decode($credId, strict: true);
            if ($rawId === false) {
                continue;
            }

            $excludeCredentials[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $rawId,
            );
        }

        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create('Beatrax', $rpId),
            user: PublicKeyCredentialUserEntity::create(
                $username,
                (string) $userId,
                $username,
            ),
            challenge: $challenge,
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(-7),   // ES256
                PublicKeyCredentialParameters::createPk(-257), // RS256
            ],
            // A null residentKey serialises as an explicit null the browser
            // rejects ("Ignoring unknown publicKey.authenticatorSelection
            // .residentKey value"); the account is always known here anyway.
            authenticatorSelection: new AuthenticatorSelectionCriteria(
                authenticatorAttachment: 'platform',
                userVerification: 'required',
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_DISCOURAGED,
            ),
            excludeCredentials: $excludeCredentials,
        );

        $session->put(self::CREATION_CHALLENGE_SESSION, base64_encode($challenge));

        /** @var array<string, mixed> */
        return $this->buildSerializer()->normalize($options, 'json');
    }

    /**
     * @param  string  $username  Must match the username issued in
     *                            creationOptions() -- the rebuilt options must mirror the issued
     *                            user entity, not substitute the userId.
     * @param  array<string, mixed>  $credentialResponse  Browser attestation JSON.
     * @param  string  $dataKey  The caller's live data-key bytes (from session).
     * @param  string  $deviceLabel  Human-readable device label.
     * @param  string  $platform  'webauthn' or 'nativephp_macos'.
     */
    public function completeEnrollment(
        int $userId,
        string $username,
        array $credentialResponse,
        string $dataKey,
        string $deviceLabel,
        string $platform,
        Session $session,
    ): void {
        $challenge = $this->consumeCreationChallenge($session);

        /** @var PublicKeyCredential $credential */
        $credential = $this->buildSerializer()->deserialize(
            json_encode($credentialResponse, JSON_THROW_ON_ERROR),
            PublicKeyCredential::class,
            'json',
        );

        $attestationResponse = $credential->response;
        if (! $attestationResponse instanceof AuthenticatorAttestationResponse) {
            throw BiometricEnrollmentException::unexpectedAttestationResponse();
        }

        $creationOptions = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create('Beatrax', $this->rpId()),
            user: PublicKeyCredentialUserEntity::create(
                $username,
                (string) $userId,
                $username,
            ),
            challenge: $challenge,
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(-7),
                PublicKeyCredentialParameters::createPk(-257),
            ],
        );

        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins([$this->origin()]);

        $validator = AuthenticatorAttestationResponseValidator::create(
            $factory->creationCeremony()
        );

        $credentialRecord = $validator->check(
            $attestationResponse,
            $creationOptions,
            $this->rpId(),
        );

        $secret = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $wrappedKey = $this->keyWrap->wrap($dataKey, $secret);

        $credentialIdRaw = $credentialRecord->publicKeyCredentialId;
        $credentialId = base64_encode($credentialIdRaw);
        $publicKeyCbor = $credentialRecord->credentialPublicKey;

        $wrappedKeyBytes = base64_decode($wrappedKey, strict: true);
        if ($wrappedKeyBytes === false) {
            sodium_memzero($secret);
            throw BiometricEnrollmentException::keyWrapEncodingFailed();
        }

        $storedBlob = $secret.$wrappedKeyBytes;
        sodium_memzero($secret);

        // Shielded in the OS keychain on desktop (identity elsewhere) so the
        // persisted bytes are machine-bound, not readable from the DB row.
        $shieldedBlob = $this->shield->protect($storedBlob);

        $this->store->store(
            $userId,
            $credentialId,
            $deviceLabel,
            $shieldedBlob,
            $publicKeyCbor,
            $platform,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function requestOptions(int $userId, Session $session): array
    {
        $rpId = $this->rpId();
        $challenge = random_bytes(32);

        $credentials = $this->store->findForUser($userId);

        /** @var list<PublicKeyCredentialDescriptor> $allowCredentials */
        $allowCredentials = [];
        foreach ($credentials as $cred) {
            if ($this->store->isArmed($cred)) {
                $credId = $cred->credential_id;
                if (! is_string($credId)) {
                    continue;
                }

                $rawId = base64_decode($credId, strict: true);
                if ($rawId === false) {
                    continue;
                }

                $allowCredentials[] = PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    $rawId,
                );
            }
        }

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            allowCredentials: $allowCredentials,
            userVerification: 'required',
        );

        $session->put(self::REQUEST_CHALLENGE_SESSION, base64_encode($challenge));

        /** @var array<string, mixed> */
        return $this->buildSerializer()->normalize($options, 'json');
    }

    /**
     * @param  array<string, mixed>  $assertion  Browser assertion JSON.
     */
    public function verifyAndRelease(int $userId, array $assertion, Session $session): bool
    {
        $challenge = $this->consumeRequestChallenge($session);
        if ($challenge === null) {
            return false;
        }

        // lock.js sends rawId as unpadded base64url and the store keeps
        // standard base64, so without normalising here the catch below never
        // finds the row and the failure throttle never engages.
        $fallbackCredentialId = $this->normaliseAssertionRawId($assertion['rawId'] ?? null);

        try {
            return $this->assertAndRelease($userId, $assertion, $challenge, $session);
        } catch (\Throwable) {
            $credRow = $this->store->findByCredentialId($userId, $fallbackCredentialId);
            if ($credRow !== null) {
                $this->incrementFailureIfPresent($credRow);
            }

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $assertion
     */
    private function assertAndRelease(int $userId, array $assertion, string $challenge, Session $session): bool
    {
        $resolved = $this->resolveArmedCredential($userId, $assertion);
        if ($resolved === null) {
            return false;
        }
        [$credRow, $credential, $assertionResponse] = $resolved;

        $credentialRecord = $this->buildCredentialRecord($credRow, $credential, $userId);
        if ($credentialRecord === null) {
            return false;
        }

        $updatedRecord = $this->runAssertionCeremony($credentialRecord, $assertionResponse, $challenge, $userId);

        return $this->releaseDataKey($credRow, $updatedRecord, $session);
    }

    /**
     * @param  array<string, mixed>  $assertion
     * @return array{0: \stdClass, 1: PublicKeyCredential, 2: AuthenticatorAssertionResponse}|null
     */
    private function resolveArmedCredential(int $userId, array $assertion): ?array
    {
        /** @var PublicKeyCredential $credential */
        $credential = $this->buildSerializer()->deserialize(
            json_encode($assertion, JSON_THROW_ON_ERROR),
            PublicKeyCredential::class,
            'json',
        );

        $assertionResponse = $credential->response;
        if (! $assertionResponse instanceof AuthenticatorAssertionResponse) {
            return null;
        }

        $credRow = $this->store->findByCredentialId($userId, base64_encode($credential->rawId));
        if ($credRow === null || ! $this->store->isArmed($credRow)) {
            return null;
        }

        return [$credRow, $credential, $assertionResponse];
    }

    // A malformed public key or counter counts as a failed attempt, not a
    // silent no-op, mirroring the wrong-credential path.
    private function buildCredentialRecord(\stdClass $credRow, PublicKeyCredential $credential, int $userId): ?CredentialRecord
    {
        $publicKeyCbor = $credRow->public_key_cbor;
        $counterValue = $credRow->counter;
        if (! is_string($publicKeyCbor) || (! is_int($counterValue) && ! is_string($counterValue))) {
            $this->incrementFailureIfPresent($credRow);

            return null;
        }

        return CredentialRecord::create(
            publicKeyCredentialId: $credential->rawId,
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: [],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: $publicKeyCbor,
            userHandle: (string) $userId,
            counter: (int) $counterValue,
        );
    }

    private function runAssertionCeremony(
        CredentialRecord $credentialRecord,
        AuthenticatorAssertionResponse $assertionResponse,
        string $challenge,
        int $userId,
    ): CredentialRecord {
        $requestOptions = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $this->rpId(),
            userVerification: 'required',
        );

        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins([$this->origin()]);

        $validator = AuthenticatorAssertionResponseValidator::create(
            $factory->requestCeremony()
        );

        return $validator->check(
            $credentialRecord,
            $assertionResponse,
            $requestOptions,
            $this->rpId(),
            (string) $userId,
        );
    }

    private function releaseDataKey(\stdClass $credRow, CredentialRecord $updatedRecord, Session $session): bool
    {
        $credRowId = $credRow->id;
        if (! is_int($credRowId) && ! is_string($credRowId)) {
            return false;
        }

        // Counter bump is replay protection.
        $this->store->updateCounter((int) $credRowId, $updatedRecord->counter);
        $this->store->resetFailureCount((int) $credRowId);

        $dataKey = $this->unwrapStoredDataKey($credRow);
        if ($dataKey === null) {
            return false;
        }

        // Best-effort: the session shares this buffer and its copy persists
        // by design until lock().
        $this->lockState->unlock($session, $dataKey);
        sodium_memzero($dataKey);

        return true;
    }

    // The keychain reveal is a no-op on web/mobile and on desktop rows
    // written before shielding existed.
    private function unwrapStoredDataKey(\stdClass $credRow): ?string
    {
        $storedBlob = $credRow->biometric_wrap_secret;
        if (! is_string($storedBlob)) {
            return null;
        }

        return $this->extractDataKey($this->shield->reveal($storedBlob));
    }

    private function normaliseAssertionRawId(mixed $rawIdValue): string
    {
        if (! is_string($rawIdValue) || $rawIdValue === '') {
            return '';
        }

        foreach ([SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, SODIUM_BASE64_VARIANT_ORIGINAL] as $variant) {
            try {
                return base64_encode(sodium_base642bin($rawIdValue, $variant, ''));
            } catch (\SodiumException) {
                // Try the next variant; sodium_base642bin() throws when
                // $rawIdValue doesn't decode under this variant.
            }
        }

        return '';
    }

    private function rpId(): string
    {
        $url = $this->config->get('app.url', self::LOCALHOST_ORIGIN);
        if (! is_string($url)) {
            return 'localhost';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'localhost';
    }

    private function origin(): string
    {
        $url = $this->config->get('app.url', self::LOCALHOST_ORIGIN);

        return is_string($url) ? $url : self::LOCALHOST_ORIGIN;
    }

    private function consumeCreationChallenge(Session $session): string
    {
        $encoded = $session->pull(self::CREATION_CHALLENGE_SESSION);
        if (! is_string($encoded)) {
            throw BiometricChallengeException::missing();
        }

        $bytes = base64_decode($encoded, strict: true);
        if ($bytes === false) {
            throw BiometricChallengeException::malformedEncoding();
        }

        return $bytes;
    }

    private function consumeRequestChallenge(Session $session): ?string
    {
        $encoded = $session->pull(self::REQUEST_CHALLENGE_SESSION);
        if (! is_string($encoded)) {
            return null;
        }

        $bytes = base64_decode($encoded, strict: true);

        return $bytes === false ? null : $bytes;
    }

    // Stored blob format: secret (32 bytes) || wrapped_key_bytes (remainder).
    // The secret is the raw secretbox key used to unwrap the data key.
    private function extractDataKey(string $storedBlob): ?string
    {
        if (strlen($storedBlob) <= SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        $secret = substr($storedBlob, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $wrappedKeyBytes = substr($storedBlob, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        $wrappedKey = base64_encode($wrappedKeyBytes);

        $dataKey = $this->keyWrap->unwrap($wrappedKey, $secret);
        sodium_memzero($secret);

        return $dataKey === false ? null : $dataKey;
    }

    private function incrementFailureIfPresent(\stdClass $credRow): void
    {
        $id = $credRow->id;
        if (is_int($id) || is_string($id)) {
            $this->store->incrementFailureCount((int) $id);
        }
    }

    // The Symfony Serializer returned by WebauthnSerializerFactory::create()
    // implements both SerializerInterface and NormalizerInterface at
    // runtime; declaring the return type as the intersection lets PHPStan
    // allow both ->normalize() and ->deserialize() without @var casts.
    private function buildSerializer(): Serializer
    {
        $serializer = (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([
                new NoneAttestationStatementSupport,
            ])
        ))->create();

        if (! $serializer instanceof Serializer) {
            throw WebAuthnSerializerException::unexpectedType();
        }

        return $serializer;
    }
}

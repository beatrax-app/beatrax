<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Session\Session;
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

// rpId is 'localhost' in dev (the host portion of APP_URL). The full
// origin (http://localhost:8000) is validated separately -- rpId vs.
// origin must BOTH be validated, or a same-rpId, different-origin
// attacker page could pass.
/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
final class WebAuthnBiometricService
{
    public const CREATION_CHALLENGE_SESSION = 'beatrax_webauthn_creation_challenge';

    public const REQUEST_CHALLENGE_SESSION = 'beatrax_webauthn_request_challenge';

    public function __construct(
        private readonly BiometricDeviceStore $store,
        private readonly AppLockKeyWrap $keyWrap,
        private readonly LockStateManager $lockState,
        private readonly ConfigRepository $config,
        private readonly SecretShield $shield,
    ) {}

    // -------------------------------------------------------------------------
    // Enrollment — navigator.credentials.create()
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function creationOptions(int $userId, string $username, Session $session): array
    {
        $rpId = $this->rpId();
        $challenge = random_bytes(32);

        // Lists already-enrolled credential IDs as excludeCredentials so
        // re-enrolling the same authenticator is rejected by the browser
        // instead of creating a duplicate row.
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
            rp: PublicKeyCredentialRpEntity::create('beatrax', $rpId),
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
            authenticatorSelection: new AuthenticatorSelectionCriteria(
                authenticatorAttachment: 'platform',
                userVerification: 'required',
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
            throw new \RuntimeException('Expected attestation response.');
        }

        $creationOptions = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create('beatrax', $this->rpId()),
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
            throw new \RuntimeException('Wrap produced invalid base64.');
        }

        $storedBlob = $secret.$wrappedKeyBytes;
        sodium_memzero($secret);

        // Shield the blob in the OS keychain on the desktop bundle (identity
        // on web / mobile) so the persisted secret||wrapped-key bytes are
        // machine-bound ciphertext, not recoverable from the raw DB row.
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

    // -------------------------------------------------------------------------
    // Assertion — navigator.credentials.get()
    // -------------------------------------------------------------------------

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

        // Pre-identify the credential for the failure counter below. lock.js
        // serialises rawId as base64url without padding, while the store
        // keeps credential_id in standard base64 -- normalise before lookup,
        // or the failure throttle silently never engages on a wrong match.
        $rawIdValue = $assertion['rawId'] ?? null;
        $credentialId = '';
        if (is_string($rawIdValue) && $rawIdValue !== '') {
            foreach ([SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, SODIUM_BASE64_VARIANT_ORIGINAL] as $variant) {
                try {
                    $credentialId = base64_encode(sodium_base642bin($rawIdValue, $variant, ''));
                    break;
                } catch (\SodiumException) {
                    // Try the next variant; sodium_base642bin() throws when
                    // $rawIdValue doesn't decode under this variant.
                }
            }
        }

        try {
            /** @var PublicKeyCredential $credential */
            $credential = $this->buildSerializer()->deserialize(
                json_encode($assertion, JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
                'json',
            );

            $assertionResponse = $credential->response;
            if (! $assertionResponse instanceof AuthenticatorAssertionResponse) {
                return false;
            }

            $credRow = $this->store->findByCredentialId($userId, base64_encode($credential->rawId));

            if ($credRow === null) {
                return false;
            }

            if (! $this->store->isArmed($credRow)) {
                return false;
            }

            $publicKeyCbor = $credRow->public_key_cbor;
            if (! is_string($publicKeyCbor)) {
                $this->incrementFailureIfPresent($credRow);

                return false;
            }

            $counterValue = $credRow->counter;
            if (! is_int($counterValue) && ! is_string($counterValue)) {
                $this->incrementFailureIfPresent($credRow);

                return false;
            }

            $credentialRecord = CredentialRecord::create(
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

            $updatedRecord = $validator->check(
                $credentialRecord,
                $assertionResponse,
                $requestOptions,
                $this->rpId(),
                (string) $userId,
            );

            $credRowId = $credRow->id;
            if (! is_int($credRowId) && ! is_string($credRowId)) {
                return false;
            }

            // Update the counter (replay protection) and reset the failure
            // count now that the assertion has verified successfully.
            $this->store->updateCounter((int) $credRowId, $updatedRecord->counter);
            $this->store->resetFailureCount((int) $credRowId);

            // Reveal the stored per-device blob from the OS keychain first --
            // a no-op on web/mobile, or on desktop rows written before
            // shielding was introduced -- then unwrap the data key from it.
            $storedBlob = $credRow->biometric_wrap_secret;
            if (! is_string($storedBlob)) {
                return false;
            }

            $dataKey = $this->extractDataKey($this->shield->reveal($storedBlob));
            if ($dataKey === null) {
                return false;
            }

            // The memzero is best-effort: the session now shares the buffer,
            // so only the local reference is affected -- the session copy
            // persists by design until lock().
            $this->lockState->unlock($session, $dataKey);
            sodium_memzero($dataKey);

            return true;

        } catch (\Throwable) {
            $credRow2 = $this->store->findByCredentialId($userId, $credentialId);
            if ($credRow2 !== null) {
                $this->incrementFailureIfPresent($credRow2);
            }

            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function rpId(): string
    {
        $url = $this->config->get('app.url', 'http://localhost');
        if (! is_string($url)) {
            return 'localhost';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'localhost';
    }

    private function origin(): string
    {
        $url = $this->config->get('app.url', 'http://localhost');

        return is_string($url) ? $url : 'http://localhost';
    }

    private function consumeCreationChallenge(Session $session): string
    {
        $encoded = $session->pull(self::CREATION_CHALLENGE_SESSION);
        if (! is_string($encoded)) {
            throw new \RuntimeException('No pending creation challenge in session.');
        }

        $bytes = base64_decode($encoded, strict: true);
        if ($bytes === false) {
            throw new \RuntimeException('Invalid creation challenge encoding.');
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
            throw new \RuntimeException('Unexpected serializer type.');
        }

        return $serializer;
    }
}

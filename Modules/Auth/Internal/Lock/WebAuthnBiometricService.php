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

/**
 * Server-side WebAuthn enrollment (attestation) and assertion (unlock) logic.
 *
 * Design decisions (D-12, D-13, D-14, D-16):
 *   D-12: web-auth/webauthn-lib 5.3.4 used directly (NOT laravel/passkeys).
 *   D-13: Enrollment only available from settings when a PIN exists.
 *   D-14: The data key is wrapped under a random per-device biometric secret;
 *         the PIN wrap remains the cryptographic root.
 *   D-16: N failures disable the credential until the next PIN unlock re-arms.
 *
 * rpId is 'localhost' in dev (the host portion of APP_URL).
 * The full origin (http://localhost:8000) is validated separately to avoid
 * Pitfall 3 from 05-RESEARCH: rpId vs. origin must BOTH be validated.
 *
 * Sensitive intermediate values are zeroed with sodium_memzero() after use
 * where possible. IN-08 caveat: PHP's sodium_memzero only zeroes refcount-1
 * buffers — once the data key is stored in the session the buffer is shared
 * and the bytes intentionally live on there (see LockStateManager custody
 * note); the memzero calls are best-effort hygiene for local references.
 *
 * Session keys for pending challenge options:
 *   - CREATION_CHALLENGE_SESSION: pending creation challenge (base64).
 *   - REQUEST_CHALLENGE_SESSION:  pending assertion challenge (base64).
 *
 * Stored blob format in biometric_wrap_secret column:
 *   [ 32 bytes raw secret ] || [ nonce||ciphertext bytes (from secretbox wrap) ]
 * The secret IS the secretbox key; the wrapped data key is the nonce||ciphertext.
 */
final class WebAuthnBiometricService
{
    /** Session key holding the pending creation challenge (for enrollment). */
    public const CREATION_CHALLENGE_SESSION = 'beatrax_webauthn_creation_challenge';

    /** Session key holding the pending assertion challenge (for unlock). */
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
     * Build PublicKeyCredentialCreationOptions for navigator.credentials.create.
     *
     * Sets a fresh random challenge and stores it in the session so
     * completeEnrollment() can validate the response.
     *
     * @return array<string, mixed> JSON-serializable options for the browser.
     */
    public function creationOptions(int $userId, string $username, Session $session): array
    {
        $rpId = $this->rpId();
        $challenge = random_bytes(32);

        // IN-02: list already-enrolled credential IDs as excludeCredentials so
        // re-enrolling the same authenticator is rejected by the browser
        // instead of creating a duplicate row (lock.js already decodes the
        // field when present).
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

        // Persist challenge for later validation.
        $session->put(self::CREATION_CHALLENGE_SESSION, base64_encode($challenge));

        /** @var array<string, mixed> */
        return $this->buildSerializer()->normalize($options, 'json');
    }

    /**
     * Verify the attestation response and persist the credential.
     *
     * Generates a random 32-byte biometric wrap secret, wraps $dataKey under
     * it using AppLockKeyWrap (sodium_crypto_secretbox), and stores the blob
     * (secret || wrapped_key_bytes) in biometric_wrap_secret column.
     *
     * @param  string  $username  The username used in creationOptions() (IN-03:
     *                            the rebuilt options must mirror the issued
     *                            user entity, not substitute the userId).
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

        // Build per-device biometric secret (32 random bytes).
        $secret = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        // Wrap the data key under the secret.
        $wrappedKey = $this->keyWrap->wrap($dataKey, $secret);

        $credentialIdRaw = $credentialRecord->publicKeyCredentialId;
        $credentialId = base64_encode($credentialIdRaw);
        $publicKeyCbor = $credentialRecord->credentialPublicKey;

        // Decode the wrapped key back to raw bytes for concatenation.
        $wrappedKeyBytes = base64_decode($wrappedKey, strict: true);
        if ($wrappedKeyBytes === false) {
            sodium_memzero($secret);
            throw new \RuntimeException('Wrap produced invalid base64.');
        }

        // Store format: secret (32 bytes) || wrapped_key_bytes.
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
     * Build PublicKeyCredentialRequestOptions for navigator.credentials.get.
     *
     * Lists only armed credentials for this user. Stores the challenge in the session.
     *
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
     * Verify the WebAuthn assertion and, on success, unlock the session.
     *
     * On success:
     *   - Updates the signature counter (replay protection, T-05-19).
     *   - Resets biometric_failed_count (re-arms for the next use).
     *   - Unwraps the data key from the stored per-device secret.
     *   - Calls LockStateManager->unlock() to store the key in the session.
     *   - Returns true.
     *
     * On failure (signature, counter, or armed check):
     *   - Increments biometric_failed_count (D-16).
     *   - Returns false.
     *
     * Pitfall 3 (05-RESEARCH): validates the FULL origin (http://localhost:8000)
     * as well as rpId='localhost'. Both MUST match.
     *
     * @param  array<string, mixed>  $assertion  Browser assertion JSON.
     */
    public function verifyAndRelease(int $userId, array $assertion, Session $session): bool
    {
        $challenge = $this->consumeRequestChallenge($session);
        if ($challenge === null) {
            return false;
        }

        // Pre-identify the credential so we can increment failure count on error.
        // lock.js serialises rawId as base64url WITHOUT padding, while the store
        // keeps credential_id in STANDARD base64 (base64_encode at enrollment).
        // Normalise to the stored encoding before lookup — otherwise the catch
        // path below never matches a row and the D-16 failure throttle silently
        // never engages (CR-04). Standard base64 is accepted as a fallback so
        // non-browser callers remain compatible.
        $rawIdValue = $assertion['rawId'] ?? null;
        $credentialId = '';
        if (is_string($rawIdValue) && $rawIdValue !== '') {
            foreach ([SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, SODIUM_BASE64_VARIANT_ORIGINAL] as $variant) {
                try {
                    $credentialId = base64_encode(sodium_base642bin($rawIdValue, $variant, ''));
                    break;
                } catch (\SodiumException) {
                    // Not this encoding — try the next variant.
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

            // Identify the credential in the DB by the rawId from the browser.
            $credRow = $this->store->findByCredentialId($userId, base64_encode($credential->rawId));

            if ($credRow === null) {
                return false;
            }

            // D-16: reject if the credential is disarmed.
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

            // Build the request options with the same challenge for validation.
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

            // T-05-19: update the counter to prevent replay.
            $this->store->updateCounter((int) $credRowId, $updatedRecord->counter);

            // D-16: reset the failure count on success.
            $this->store->resetFailureCount((int) $credRowId);

            // Unwrap the data key from the stored per-device blob. Reveal it
            // from the OS keychain first (identity on web / mobile, and on
            // desktop for legacy rows written before shielding — reveal()
            // returns the input unchanged when it is not safeStorage
            // ciphertext).
            $storedBlob = $credRow->biometric_wrap_secret;
            if (! is_string($storedBlob)) {
                return false;
            }

            $dataKey = $this->extractDataKey($this->shield->reveal($storedBlob));
            if ($dataKey === null) {
                return false;
            }

            // Unlock the session with the recovered data key. The memzero is
            // best-effort (IN-08): the session now shares the buffer, so only
            // the local reference is affected — the session copy persists by
            // design until lock().
            $this->lockState->unlock($session, $dataKey);
            sodium_memzero($dataKey);

            return true;

        } catch (\Throwable) {
            // Any assertion verification failure increments the failure count.
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

    /** Return the RP ID (host portion of APP_URL). */
    private function rpId(): string
    {
        $url = $this->config->get('app.url', 'http://localhost');
        if (! is_string($url)) {
            return 'localhost';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'localhost';
    }

    /** Return the full origin (e.g. http://localhost:8000). Per Pitfall 3. */
    private function origin(): string
    {
        $url = $this->config->get('app.url', 'http://localhost');

        return is_string($url) ? $url : 'http://localhost';
    }

    /** Consume and decode the stored creation challenge from the session. */
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

    /** Consume and decode the stored assertion challenge from the session. */
    private function consumeRequestChallenge(Session $session): ?string
    {
        $encoded = $session->pull(self::REQUEST_CHALLENGE_SESSION);
        if (! is_string($encoded)) {
            return null;
        }

        $bytes = base64_decode($encoded, strict: true);

        return $bytes === false ? null : $bytes;
    }

    /**
     * Extract the data key from the stored blob.
     *
     * Stored blob format: secret (32 bytes) || wrapped_key_bytes (remainder).
     * The secret is the raw secretbox key used to unwrap the data key.
     */
    private function extractDataKey(string $storedBlob): ?string
    {
        if (strlen($storedBlob) <= SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        $secret = substr($storedBlob, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $wrappedKeyBytes = substr($storedBlob, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        // Re-encode as base64 to pass to AppLockKeyWrap->unwrap.
        $wrappedKey = base64_encode($wrappedKeyBytes);

        $dataKey = $this->keyWrap->unwrap($wrappedKey, $secret);
        sodium_memzero($secret);

        return $dataKey === false ? null : $dataKey;
    }

    /**
     * Increment the failure count if the credential row has a valid int id.
     */
    private function incrementFailureIfPresent(\stdClass $credRow): void
    {
        $id = $credRow->id;
        if (is_int($id) || is_string($id)) {
            $this->store->incrementFailureCount((int) $id);
        }
    }

    /**
     * Build and return the WebAuthn serializer.
     *
     * The Symfony Serializer returned by WebauthnSerializerFactory::create()
     * implements both SerializerInterface and NormalizerInterface at runtime.
     * This helper declares the return type as the intersection so PHPStan
     * allows both ->normalize() and ->deserialize() calls without @var casts.
     */
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

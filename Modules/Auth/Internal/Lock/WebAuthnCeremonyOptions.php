<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

// The validator reads its requirements off the copy it is handed, never off
// the one the browser was issued, so a rebuilt copy that drops a field drops
// the check with it: a creation copy without authenticatorSelection made
// CheckUserVerification return early and accept an unverified attestation.
/**
 * @link ../../../../.docs/features/auth/architecture.md#biometric-enrollment--assertion-webauthnbiometricservice
 */
final readonly class WebAuthnCeremonyOptions
{
    private const string LOCALHOST_ORIGIN = 'http://localhost';

    public function __construct(
        private ConfigRepository $config,
    ) {}

    /**
     * @param  list<PublicKeyCredentialDescriptor>  $excludeCredentials
     */
    public function creation(
        int $userId,
        string $username,
        string $challenge,
        array $excludeCredentials = [],
    ): PublicKeyCredentialCreationOptions {
        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create('Beatrax', $this->rpId()),
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
    }

    /**
     * @param  list<PublicKeyCredentialDescriptor>  $allowCredentials
     */
    public function request(string $challenge, array $allowCredentials = []): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $this->rpId(),
            allowCredentials: $allowCredentials,
            userVerification: 'required',
        );
    }

    // rpId is the host portion of APP_URL, and the full origin is validated
    // separately: both must be, or a same-rpId attacker page passes.
    public function rpId(): string
    {
        $url = $this->config->get('app.url', self::LOCALHOST_ORIGIN);
        if (! is_string($url)) {
            return 'localhost';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'localhost';
    }

    public function origin(): string
    {
        $url = $this->config->get('app.url', self::LOCALHOST_ORIGIN);

        return is_string($url) ? $url : self::LOCALHOST_ORIGIN;
    }
}

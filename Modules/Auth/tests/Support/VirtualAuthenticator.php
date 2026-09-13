<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

// A WebAuthn platform authenticator, in PHP. Every server-side branch past
// CheckSignature was unreachable without one: the ceremony needs a signature
// that verifies against a COSE key the server stored, and only a real ES256
// key pair can produce that pair. Everything here is the wire format the
// browser would have produced, built by hand so a test can vary one field of
// it at a time.
final class VirtualAuthenticator
{
    // UP (0x01) | UV (0x04): present and verified, the flags a platform
    // authenticator sets after a fingerprint.
    public const int FLAG_USER_PRESENT = 0x01;

    public const int FLAG_USER_VERIFIED = 0x04;

    public const int FLAG_ATTESTED_CREDENTIAL_DATA = 0x40;

    public readonly string $credentialId;

    private readonly OpenSSLAsymmetricKey $key;

    private readonly string $coseKey;

    public function __construct(?string $credentialId = null)
    {
        $this->credentialId = $credentialId ?? random_bytes(16);

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($key === false) {
            throw new RuntimeException('openssl_pkey_new() could not mint a P-256 key pair.');
        }

        $this->key = $key;

        $details = openssl_pkey_get_details($key);
        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('openssl_pkey_get_details() returned no EC coordinates.');
        }

        /** @var string $x */
        $x = $details['ec']['x'];
        /** @var string $y */
        $y = $details['ec']['y'];

        $this->coseKey = self::coseEc2Key($x, $y);
    }

    // The COSE public key exactly as the store column holds it, so a test can
    // seed a credential row without running an attestation ceremony first.
    public function publicKeyCbor(): string
    {
        return $this->coseKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function assertion(
        string $rpId,
        string $origin,
        string $challenge,
        int $signCount = 1,
        ?int $flags = null,
        ?string $userHandle = null,
    ): array {
        $flags ??= self::FLAG_USER_PRESENT | self::FLAG_USER_VERIFIED;

        $authenticatorData = hash('sha256', $rpId, true).chr($flags).pack('N', $signCount);
        $clientDataJson = self::clientData('webauthn.get', $challenge, $origin);

        return [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'authenticatorData' => self::b64url($authenticatorData),
                'clientDataJSON' => self::b64url($clientDataJson),
                'signature' => self::b64url($this->sign($authenticatorData.hash('sha256', $clientDataJson, true))),
                'userHandle' => $userHandle === null ? null : self::b64url($userHandle),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attestation(
        string $rpId,
        string $origin,
        string $challenge,
        ?int $flags = null,
    ): array {
        $flags ??= self::FLAG_USER_PRESENT | self::FLAG_USER_VERIFIED | self::FLAG_ATTESTED_CREDENTIAL_DATA;

        $attestedCredentialData = str_repeat("\x00", 16)
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->coseKey;

        $authenticatorData = hash('sha256', $rpId, true)
            .chr($flags)
            .pack('N', 0)
            .$attestedCredentialData;

        $attestationObject = "\xa3"
            .self::cborText('fmt').self::cborText('none')
            .self::cborText('attStmt')."\xa0"
            .self::cborText('authData').self::cborBytes($authenticatorData);

        return [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64url(self::clientData('webauthn.create', $challenge, $origin)),
                'attestationObject' => self::b64url($attestationObject),
            ],
        ];
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function sign(string $data): string
    {
        // DER here, not the raw r||s pair: CoseSignatureFixer converts
        // anything that is not 64 bytes, which is what a browser sends too.
        if (openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256) === false) {
            throw new RuntimeException('openssl_sign() refused the P-256 key.');
        }

        return $signature;
    }

    private static function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => self::b64url($challenge),
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
    }

    // COSE_Key for ES256 over P-256: kty(1)=EC2(2), alg(3)=-7, crv(-1)=1,
    // x(-2) and y(-3) as 32-byte strings, left-padded because openssl drops a
    // leading zero byte and the curve field is fixed-width.
    private static function coseEc2Key(string $x, string $y): string
    {
        return "\xa5"
            ."\x01\x02"
            ."\x03\x26"
            ."\x20\x01"
            ."\x21".self::cborBytes(str_pad($x, 32, "\x00", STR_PAD_LEFT))
            ."\x22".self::cborBytes(str_pad($y, 32, "\x00", STR_PAD_LEFT));
    }

    private static function cborBytes(string $value): string
    {
        return self::cborHeader(0x40, strlen($value)).$value;
    }

    private static function cborText(string $value): string
    {
        return self::cborHeader(0x60, strlen($value)).$value;
    }

    private static function cborHeader(int $majorType, int $length): string
    {
        return match (true) {
            $length < 24 => chr($majorType | $length),
            $length < 256 => chr($majorType | 24).chr($length),
            default => chr($majorType | 25).pack('n', $length),
        };
    }
}

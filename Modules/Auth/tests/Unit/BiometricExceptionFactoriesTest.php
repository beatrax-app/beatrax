<?php

declare(strict_types=1);

// A couple of these factories sit behind branches only a live authenticator can
// reach, so their type and message are asserted directly instead.

use Modules\Auth\Internal\Exceptions\BiometricChallengeException;
use Modules\Auth\Internal\Exceptions\BiometricEnrollmentException;
use Modules\Auth\Internal\Exceptions\WebAuthnSerializerException;

it('BiometricChallengeException factories carry the expected message and type', function (): void {
    expect(BiometricChallengeException::missing())
        ->toBeInstanceOf(BiometricChallengeException::class)
        ->and(BiometricChallengeException::missing()->getMessage())
        ->toBe('No pending creation challenge in session.');

    expect(BiometricChallengeException::malformedEncoding())
        ->toBeInstanceOf(BiometricChallengeException::class)
        ->and(BiometricChallengeException::malformedEncoding()->getMessage())
        ->toBe('Invalid creation challenge encoding.');
});

it('BiometricEnrollmentException factories carry the expected message and type', function (): void {
    expect(BiometricEnrollmentException::unexpectedAttestationResponse())
        ->toBeInstanceOf(BiometricEnrollmentException::class)
        ->and(BiometricEnrollmentException::unexpectedAttestationResponse()->getMessage())
        ->toBe('Expected attestation response.');

    expect(BiometricEnrollmentException::keyWrapEncodingFailed())
        ->toBeInstanceOf(BiometricEnrollmentException::class)
        ->and(BiometricEnrollmentException::keyWrapEncodingFailed()->getMessage())
        ->toBe('Wrap produced invalid base64.');
});

it('WebAuthnSerializerException::unexpectedType carries the expected message and type', function (): void {
    expect(WebAuthnSerializerException::unexpectedType())
        ->toBeInstanceOf(WebAuthnSerializerException::class)
        ->and(WebAuthnSerializerException::unexpectedType()->getMessage())
        ->toBe('Unexpected serializer type.');
});

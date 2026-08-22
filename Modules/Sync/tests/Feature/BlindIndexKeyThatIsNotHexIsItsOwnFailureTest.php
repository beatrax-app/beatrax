<?php

declare(strict_types=1);

use Modules\Sync\Internal\Exceptions\BlindIndexKeyMalformedException;
use Modules\Sync\Public\Exceptions\BlindIndexKeyUnavailableException;
use Modules\Sync\Public\Services\BlindIndexCodec;

// A keyring holding a key that is not hex used to leave here as a bare
// RuntimeException, which a caller could only catch by catching everything
// else with it. The import pipeline already branches on the sibling type to
// tell the reader the app is locked, and this is the case it must not claim.

it('throws its own type when the held blind-index key is not valid hex', function (): void {
    /** @var BlindIndexCodec $codec */
    $codec = $this->app->make(BlindIndexCodec::class);

    expect(fn (): string => $codec->deriveWithKey(
        BlindIndexCodec::DOMAIN_COUNTERPARTY_NORMALIZED,
        'ALBERT HEIJN 1042',
        7,
        'not-hex-at-all',
    ))->toThrow(BlindIndexKeyMalformedException::class);
});

// Unlocking produces a missing key and can never produce a valid one from
// invalid hex, so a caller that retried on both would retry forever on this.
it('is not the not-held failure, and names the user and domain without the plaintext', function (): void {
    /** @var BlindIndexCodec $codec */
    $codec = $this->app->make(BlindIndexCodec::class);

    try {
        $codec->deriveWithKey(BlindIndexCodec::DOMAIN_COUNTERPARTY_IBAN, 'NL91ABNA0417164300', 7, 'zz');
    } catch (BlindIndexKeyMalformedException $e) {
        expect($e)->not->toBeInstanceOf(BlindIndexKeyUnavailableException::class)
            ->and($e->getMessage())->toContain('user 7')
            ->and($e->getMessage())->toContain(BlindIndexCodec::DOMAIN_COUNTERPARTY_IBAN)
            ->and($e->getMessage())->not->toContain('NL91ABNA0417164300')
            ->and($e->getPrevious())->toBeInstanceOf(SodiumException::class);

        return;
    }

    $this->fail('deriveWithKey accepted a key that is not valid hex');
});

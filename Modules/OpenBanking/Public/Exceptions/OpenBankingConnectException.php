<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Exceptions;

use RuntimeException;

// A consent handoff refused before the browser is ever redirected: the wizard
// is unfinished, no bank was chosen, or Enable Banking answered with a missing,
// unparseable, non-public, or open-redirect consent URL. Each carries the
// reason the connect controller flashes, so one catch replaces eight returns.
/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class OpenBankingConnectException extends RuntimeException
{
    public static function wizardIncomplete(): self
    {
        return new self('Finish the Open Banking setup wizard first.');
    }

    public static function noBankChosen(): self
    {
        return new self('Choose a bank before connecting.');
    }

    public static function noConsentUrl(): self
    {
        return new self('Enable Banking did not return a consent URL.');
    }

    public static function unparseableConsentUrl(): self
    {
        return new self('Enable Banking returned an unparseable consent URL.');
    }

    public static function nonPublicConsentHost(): self
    {
        return new self('Enable Banking returned a non-public consent host.');
    }

    public static function unsafeConsentUrl(): self
    {
        return new self('Enable Banking returned an unsafe consent URL.');
    }
}

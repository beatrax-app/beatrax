<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Exceptions;

use Modules\Core\Public\Support\Lang;
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
        return new self(Lang::get('openbanking::messages.errors.wizard_incomplete'));
    }

    public static function noBankChosen(): self
    {
        return new self(Lang::get('openbanking::messages.errors.no_bank_chosen'));
    }

    public static function noConsentUrl(): self
    {
        return new self(Lang::get('openbanking::messages.errors.no_consent_url'));
    }

    public static function unparseableConsentUrl(): self
    {
        return new self(Lang::get('openbanking::messages.errors.unparseable_consent_url'));
    }

    public static function nonPublicConsentHost(): self
    {
        return new self(Lang::get('openbanking::messages.errors.non_public_consent_host'));
    }

    public static function unsafeConsentUrl(): self
    {
        return new self(Lang::get('openbanking::messages.errors.unsafe_consent_url'));
    }
}

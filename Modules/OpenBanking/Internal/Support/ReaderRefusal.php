<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Support;

use Modules\Core\Public\Support\Lang;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCallbackException;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingConnectException;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCredentialsException;
use Modules\OpenBanking\Internal\OAuth\InvalidStateException;
use Modules\OpenBanking\Internal\Services\SecretsWriteFailed;
use RuntimeException;

// What the two consent controllers may flash. Three of this module's refusals
// ARE Lang lines and speak for themselves; the rest carry an aggregator
// response body, an absolute path or a row id, and the controllers flashed
// those verbatim and in English.
/**
 * @link ../../../../.docs/features/open-banking/architecture.md#what-a-refusal-may-say
 */
final readonly class ReaderRefusal
{
    private function __construct(
        public string $message,
        // True where the message above replaces one no reader could act on, so
        // the caller records what actually failed instead of dropping it.
        public bool $hidesDetail,
    ) {}

    // Listed by what each exception's message IS, never by what produced it: a
    // default that falls through to getMessage() is how a class added later
    // reaches the screen without anyone deciding that it should.
    public static function for(RuntimeException $e): self
    {
        return match (true) {
            $e instanceof OpenBankingConnectException,
            $e instanceof OpenBankingCallbackException,
            $e instanceof InvalidStateException => new self($e->getMessage(), false),
            $e instanceof OpenBankingCredentialsException => new self($e->readerMessage(), true),
            $e instanceof SecretsWriteFailed => new self(
                Lang::get('openbanking::messages.errors.connection_not_saved'),
                true,
            ),
            default => new self(Lang::get('openbanking::messages.sync.unavailable'), true),
        };
    }
}

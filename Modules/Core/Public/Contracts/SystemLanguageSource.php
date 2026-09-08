<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

// The language the OS is set to, as a BCP-47 tag ("nl-NL"), for the shells
// that do not put it in a request header. Null means "no better answer than
// the header already carried", so an implementation never guesses.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
interface SystemLanguageSource
{
    public function tag(): ?string;
}

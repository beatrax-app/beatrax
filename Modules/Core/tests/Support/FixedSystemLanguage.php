<?php

declare(strict_types=1);

namespace Modules\Core\tests\Support;

use Modules\Core\Public\Contracts\SystemLanguageSource;

// Stands in for a phone, whose answer comes over the native bridge. The real
// adapter lives in Mobile and may not be reached from here.
final readonly class FixedSystemLanguage implements SystemLanguageSource
{
    public function __construct(private ?string $tag) {}

    public function tag(): ?string
    {
        return $this->tag;
    }
}

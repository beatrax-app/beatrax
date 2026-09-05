<?php

declare(strict_types=1);

namespace Modules\DevMode\Tests\Support;

use Modules\DevMode\Internal\Services\OAuthScrubSet;

// A named class rather than an anonymous one: pest runs each `it()` body
// inside a Closure, and an anonymous class declared there is redeclared on
// repeat iterations. Overriding all three public methods sidesteps the
// parent's private cache without touching the DB.
class FixedOAuthScrubSetStub extends OAuthScrubSet
{
    /**
     * @param  list<string>  $preloaded
     */
    public function __construct(private array $preloaded) {}

    public function all(): array
    {
        return $this->preloaded;
    }

    public function compiledPattern(): ?string
    {
        if ($this->preloaded === []) {
            return null;
        }
        $alt = implode('|', array_map(
            static fn (string $s): string => preg_quote($s, '/'),
            $this->preloaded,
        ));

        return '/('.$alt.')/';
    }

    public function bust(): void {}
}

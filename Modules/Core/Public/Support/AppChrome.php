<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The per-request chrome the page shells render into their <html> tag and
// head: whether to paint dark server-side, whether the pre-paint script
// must run, and the active UI locale for the lang attribute. Built by
// AppChromeResolver so every shell derives these the same way.
final readonly class AppChrome
{
    public function __construct(
        public bool $isDark,
        public bool $needsPrePaintScript,
        public string $locale,
        // The reader's stored choice, not the paint it resolved to. Flux keeps
        // its own copy in localStorage and treats an absent key as "follow the
        // system", so seeding it needs the choice rather than the outcome.
        public string $theme = 'system',
    ) {}

    // Three answers, because a boolean cannot say "not yet known". Writing
    // `light` for the unknown case is what every `html:not(.light)` guard
    // reads as "the reader chose light", which disarms the pre-paint script
    // and style that exist to answer precisely that case.
    public function rootThemeClass(): string
    {
        if ($this->isDark) {
            return 'dark';
        }

        return $this->needsPrePaintScript ? '' : 'light';
    }
}

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
    ) {}
}

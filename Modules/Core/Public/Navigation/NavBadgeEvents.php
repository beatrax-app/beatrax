<?php

declare(strict_types=1);

namespace Modules\Core\Public\Navigation;

// The rail lives in the layout, which a component update never re-renders, so
// a module that writes a counted row has to say so. Dispatcher and #[On]
// listener sit in different modules; this is the shared name.
final class NavBadgeEvents
{
    public const string REFRESH = 'nav-badges:refresh';
}

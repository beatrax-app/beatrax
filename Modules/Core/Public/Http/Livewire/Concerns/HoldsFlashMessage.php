<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Livewire\Concerns;

use Livewire\Component;

// The page-level flash line every Livewire page renders with
// `@if ($flashMessage !== '')`. Empty string, never null, so the blade
// guard is the same everywhere. Field-scoped errors are a separate thing
// and stay on their own component.
/**
 * @phpstan-require-extends Component
 */
trait HoldsFlashMessage
{
    public string $flashMessage = '';
}

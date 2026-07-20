<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Navigation;

use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Dto\AppAction;

// Mirrors NavigationRegistryImpl's shape — a final readonly class whose
// roster is fixed at construction time, populated inside
// DevModeServiceProvider::register() from the app-menu intent surface.
final readonly class AppActionRegistryImpl implements AppActionRegistry
{
    /** @var list<AppAction> */
    private array $actions;

    /**
     * @param  list<AppAction>  $actions
     */
    public function __construct(array $actions)
    {
        $this->actions = $actions;
    }

    /**
     * @return list<AppAction>
     */
    public function all(): array
    {
        return $this->actions;
    }
}

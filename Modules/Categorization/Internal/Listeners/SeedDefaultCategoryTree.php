<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Listeners;

use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Core\Public\Events\UserInstalled;

/**
 * Listens for `UserInstalled` (dispatched by the Core install command) and
 * runs the default category tree seeder. Keeps `InstallCommand` decoupled
 * from any Categorization symbol — the install command emits the event and
 * never imports a `Modules\Categorization\…` class.
 */
final class SeedDefaultCategoryTree
{
    public function __construct(private readonly DefaultCategoryTreeSeeder $seeder) {}

    public function handle(UserInstalled $event): void
    {
        $this->seeder->run();
    }
}

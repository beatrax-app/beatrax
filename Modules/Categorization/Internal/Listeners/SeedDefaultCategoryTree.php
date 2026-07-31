<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Listeners;

use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;

// Keeps InstallCommand decoupled from any Categorization symbol — the
// install command emits the event and never imports a
// Modules\Categorization\* class.
final class SeedDefaultCategoryTree
{
    public function __construct(private readonly DefaultCategoryTreeSeeder $seeder) {}

    // The event carries a userId the sibling rule-seeder needs, but the
    // default category tree is identical for every user, so this listener
    // seeds it without reading the payload.
    public function handle(): void
    {
        $this->seeder->run();
    }
}

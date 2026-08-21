<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Listeners;

use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;

final class SeedDefaultCategoryTree
{
    public function __construct(private readonly DefaultCategoryTreeSeeder $seeder) {}

    public function handle(): void
    {
        $this->seeder->run();
    }
}

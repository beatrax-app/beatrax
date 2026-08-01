<?php

declare(strict_types=1);

namespace Modules\Core\Database\Support;

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder;

// Base for every module migration. Owns the container-resolved schema builder —
// the no-facade DB access the project requires (migrations run outside the
// request lifecycle, so there is no injection seam) — so the ~120 migrations no
// longer each re-declare the identical resolvedDb/db()/schema() block.
abstract class ModuleMigration extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    protected function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    protected function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
}

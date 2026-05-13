<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        /** @var Kernel $kernel */
        $kernel = Container::getInstance()->make(Kernel::class);
        $exitCode = $kernel->call('diederik:rederive-fingerprints', ['--confirm' => true]);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Fingerprint re-derive migration failed. See the command output above. '
                .'Existing rows have been left on the previous normalization_version.'
            );
        }
    }

    public function down(): void
    {
        // Re-deriving the current normalization_version back to its
        // predecessor is destructive: the current algorithm may have merged
        // rows that the previous one distinguished, and reversing the hash
        // would re-fragment those merged rows incorrectly. Restore from a
        // SQLite backup if a rollback is required.
    }
};
